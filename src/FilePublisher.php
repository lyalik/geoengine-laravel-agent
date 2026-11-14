<?php

namespace GeoEngine\Agent;

/**
 * Managed public-file publisher — upload_file / publish_file tasks and
 * generate_llms write llms.txt, robots.txt, sitemap.xml, .well-known/*
 * into the public web root so the web server serves them directly.
 */
class FilePublisher
{
    private const ALLOWED = '/^(?:\.well-known\/[A-Za-z0-9._-]+|[A-Za-z0-9][A-Za-z0-9._\-]{0,120})$/';

    public static function publicPath(): string
    {
        $override = config('geoengine.public_path');
        return $override ? rtrim($override, '/') : public_path();
    }

    public static function safeName(string $name): ?string
    {
        $name = ltrim(trim($name), '/');
        if ($name === '' || str_contains($name, '..') || !preg_match(self::ALLOWED, $name)) {
            return null;
        }
        return $name;
    }

    /**
     * Write a managed file. Returns absolute path written.
     * encoding: 'base64' or 'utf-8' (default).
     */
    public static function publish(string $filename, string $content, string $encoding = 'utf-8'): string
    {
        $name = self::safeName($filename);
        if ($name === null) {
            throw new \InvalidArgumentException('Disallowed filename: ' . $filename);
        }
        $target = self::publicPath() . '/' . $name;
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Invalid base64 content');
            }
            file_put_contents($target, $decoded);
        } else {
            file_put_contents($target, $content);
        }
        return $target;
    }

    /** Read a previously published managed file (null when absent). */
    public static function read(string $filename): ?string
    {
        $name = self::safeName($filename);
        if ($name === null) {
            return null;
        }
        $path = self::publicPath() . '/' . $name;
        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
