<?php

namespace GeoEngine\Agent\Console\Commands;

use GeoEngine\Agent\AgentClient;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'geoengine:install
        {--key= : Project API key (ge_…) — overrides GEO_ENGINE_PROJECT_KEY}
        {--site= : Public site URL — overrides GEO_ENGINE_SITE_URL/APP_URL}
        {--no-register : Skip platform registration (config only)}
        {--no-migrations : Skip geoengine_* table migrations (create them manually)}';

    protected $description = 'Install/activate the GEO Engine Laravel agent';

    public function handle(): int
    {
        // 1. Publish config if the app hasn't customized it yet.
        if (!config_path('geoengine.php') || !file_exists(config_path('geoengine.php'))) {
            $this->call('vendor:publish', ['--tag' => 'geoengine-config', '--force' => false]);
        }

        // 2. Migrations — ONLY the package's own geoengine_* migrations.
        //    --realpath targets the package dir; pointing --path at the app's
        //    database/migrations would run every pending app migration —
        //    forbidden or destructive on many production setups.
        if ($this->option('no-migrations')) {
            $this->line('Skipped migrations. Create the geoengine_* tables manually (see database/migrations).');
        } else {
            $this->info('Running geoengine migrations…');
            $status = $this->call('migrate', [
                '--path' => dirname(__DIR__, 3) . '/database/migrations',
                '--realpath' => true,
                '--force' => true,
            ]);
            if ($status !== self::SUCCESS) {
                $this->warn('Package migrations failed — if the geoengine_* tables already exist (e.g. created manually), rerun with --no-migrations.');
            }
        }

        // 3. Persist .env values when provided.
        $key = $this->option('key') ?: env('GEO_ENGINE_PROJECT_KEY');
        $site = $this->option('site') ?: env('GEO_ENGINE_SITE_URL') ?: env('APP_URL');
        if ($key || $site) {
            $this->writeEnv([
                'GEO_ENGINE_PROJECT_KEY' => $key,
                'GEO_ENGINE_SITE_URL' => $site,
            ]);
        }

        if ($this->option('no-register')) {
            $this->info('Config written. Set GEO_ENGINE_PROJECT_KEY + GEO_ENGINE_SITE_URL, then run: php artisan geoengine:install');
            return self::SUCCESS;
        }

        // 4. Register with the platform.
        $this->info('Registering agent with GEO Engine…');
        try {
            $client = new AgentClient();
            $res = $client->register();
            $this->writeEnv([
                'GEO_ENGINE_AGENT_ID' => (string) $res['agent_id'],
                'GEO_ENGINE_AGENT_TOKEN' => $res['agent_token'],
            ]);
            $this->info("Registered! agent_id={$res['agent_id']}");
            $this->line('Add to app/Console/Kernel.php schedule():');
            $this->line('    $schedule->command(\'geoengine:poll\')->everyFiveMinutes()->withoutOverlapping();');
            $this->newLine();
            $this->info('Done. The agent now polls tasks and receives webhook pushes.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Registration failed: ' . $e->getMessage());
            $this->line('Check GEO_ENGINE_PROJECT_KEY (Settings → API Keys in GEO Engine) and that a project exists for ' . ($site ?: 'this domain') . '.');
            return self::FAILURE;
        }
    }

    private function writeEnv(array $pairs): void
    {
        $envPath = base_path('.env');
        if (!is_file($envPath)) {
            return;
        }
        $content = (string) file_get_contents($envPath);
        foreach ($pairs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (preg_match('/^' . preg_quote($k, '/') . '=/m', $content)) {
                $content = preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', $k . '=' . $v, $content);
            } else {
                $content = rtrim($content) . "\n{$k}={$v}\n";
            }
        }
        file_put_contents($envPath, $content);
    }
}
