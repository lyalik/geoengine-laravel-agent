<?php

namespace GeoEngine\Agent;

use GeoEngine\Agent\Models\Setting;

/**
 * self_update — download the package zip from the platform and overwrite
 * this package's src/ tree in place, with per-file backup + rollback.
 *
 * Package layout in the zip: geoengine-agent/{src,config,routes,...}.
 * We locate our own package root by walking up from this file until we
 * find composer.json with name geoengine/agent (or config/geoengine.php).
 */
class SelfUpdater
{
    public static function packageRoot(): string
    {
        $override = config('geoengine.package_path');
        if ($override && is_dir($override)) {
            return rtrim($override, '/');
        }
        // src/SelfUpdater.php → package root is two levels up.
        $dir = dirname(__DIR__);
        // Walk up until composer.json declares geoengine/agent.
        while ($dir !== '/' && $dir !== '') {
            $composer = $dir . '/composer.json';
            if (is_file($composer)) {
                $json = json_decode((string) file_get_contents($composer), true);
                if (($json['name'] ?? '') === 'geoengine/agent') {
                    return $dir;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return dirname(__DIR__); // fallback: package src/ parent
    }

    private static function versionOf(string $root): string
    {
        $f = $root . '/src/Version.php';
        if (is_file($f) && preg_match('/VERSION\s*=\s*\'([^\']+)\'/', (string) file_get_contents($f), $m)) {
            return $m[1];
        }
        return '0';
    }

    private static function verTuple(string $v): array
    {
        return array_map('intval', array_map(
            fn ($p) => preg_match('/\d+/', (string) $p, $m) ? $m[0] : '0',
            preg_split('/[.\-+]/', $v) ?: ['0']
        ));
    }

    /**
     * Apply update. Returns result array for the task result.
     */
    public static function apply(array $payload, AgentClient $client): array
    {
        $target = (string) ($payload['target_version'] ?? '');
        $expectedSha = strtolower((string) ($payload['sha256'] ?? ''));
        $current = Version::VERSION;

        if ($target !== '' && version_compare($current, $target, '>=')) {
            return ['ok' => true, 'already_up_to_date' => true, 'version' => $current];
        }

        // downloadPackage() returns an associative array; accept a numeric
        // list too so older/newer clients keep working.
        $pkg = $client->downloadPackage();
        $tmpZip = (string) ($pkg['path'] ?? $pkg[0] ?? '');
        $servedSha = (string) ($pkg['sha256'] ?? $pkg[1] ?? '');
        $servedVer = (string) ($pkg['version'] ?? $pkg[2] ?? '');
        try {
            // Prefer the sha of the archive actually served.
            $check = $servedSha !== '' ? strtolower($servedSha) : $expectedSha;
            if ($check !== '' && hash_file('sha256', $tmpZip) !== $check) {
                return ['ok' => false, 'error' => 'Package checksum mismatch (download corrupt)'];
            }

            $extract = sys_get_temp_dir() . '/geoengine-update-' . uniqid();
            mkdir($extract, 0755, true);
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                return ['ok' => false, 'error' => 'Invalid package archive'];
            }
            $zip->extractTo($extract);
            $zip->close();

            // Locate package root inside the archive: geoengine-agent/…
            $candidates = glob($extract . '/*', GLOB_ONLYDIR) ?: [];
            $srcRoot = null;
            foreach ($candidates as $c) {
                if (basename($c) === 'geoengine-agent' && is_dir($c . '/src')) {
                    $srcRoot = $c;
                    break;
                }
            }
            if (!$srcRoot && is_dir($extract . '/src')) {
                $srcRoot = $extract; // flat layout fallback
            }
            if (!$srcRoot) {
                self::rrmdir($extract);
                return ['ok' => false, 'error' => 'Package layout mismatch (geoengine-agent/ missing)'];
            }

            $root = self::packageRoot();
            [$written, $error] = self::copyTree($srcRoot, $root);
            self::rrmdir($extract);
            if ($error !== null) {
                return ['ok' => false, 'error' => 'Update rolled back: ' . $error];
            }

            $newVersion = $servedVer !== '' ? $servedVer : self::versionOf($root);
            Setting::put('agent_version', $newVersion);

            return [
                'ok' => true,
                'version' => $newVersion,
                'files_written' => $written,
                'requires_restart' => true,
                'note' => 'PHP-FPM/Octane reload or next request picks up new code; run php artisan optimize:clear if needed',
            ];
        } finally {
            @unlink($tmpZip);
        }
    }

    /**
     * Copy src/** over dst/** with per-file backup; rollback on failure.
     * Returns [files_written, error|null].
     */
    private static function copyTree(string $src, string $dst): array
    {
        $backup = sys_get_temp_dir() . '/geoengine-backup-' . uniqid();
        mkdir($backup, 0755, true);
        $backups = [];
        $created = [];
        $written = 0;
        $error = null;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        try {
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                $rel = substr($file->getPathname(), strlen($src) + 1);
                $s = $file->getPathname();
                $d = $dst . '/' . $rel;
                if (is_file($d)) {
                    $bak = $backup . '/' . $rel;
                    @mkdir(dirname($bak), 0755, true);
                    if (!@copy($d, $bak)) {
                        throw new \RuntimeException('backup failed: ' . $rel);
                    }
                    $backups[$d] = $bak;
                } else {
                    $created[] = $d;
                }
                @mkdir(dirname($d), 0755, true);
                if (!@copy($s, $d)) {
                    throw new \RuntimeException('copy failed: ' . $rel);
                }
                $written++;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            foreach ($backups as $d => $bak) {
                @copy($bak, $d);
            }
            foreach ($created as $f) {
                @unlink($f);
            }
        }
        self::rrmdir($backup);
        return [$written, $error];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
