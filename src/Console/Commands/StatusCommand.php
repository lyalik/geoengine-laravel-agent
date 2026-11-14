<?php

namespace GeoEngine\Agent\Console\Commands;

use GeoEngine\Agent\AgentClient;
use GeoEngine\Agent\Models\Page;
use GeoEngine\Agent\Models\Product;
use GeoEngine\Agent\Models\Redirect;
use GeoEngine\Agent\Models\SeoMeta;
use GeoEngine\Agent\Models\Setting;
use GeoEngine\Agent\Version;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'geoengine:status';

    protected $description = 'Show GEO Engine agent status and managed-state counts';

    public function handle(): int
    {
        $this->info('GEO Engine Laravel Agent v' . Version::VERSION);
        $this->line('  api_url:      ' . config('geoengine.api_url'));
        $this->line('  site_url:     ' . config('geoengine.site_url'));
        $this->line('  configured:   ' . (AgentClient::configured() ? 'yes' : 'no (run geoengine:install)'));

        try {
            $agentId = AgentClient::agentId();
            $this->line('  agent_id:     ' . ($agentId ?? '—'));
        } catch (\Throwable) {
            $this->line('  agent_id:     (db not ready)');
        }

        try {
            $this->line('  managed seo rows:   ' . SeoMeta::count());
            $this->line('  products:           ' . Product::count());
            $this->line('  redirects:          ' . Redirect::count());
            $this->line('  content pages:      ' . Page::count());
            $this->line('  native_seo_off:    ' . var_export((bool) Setting::get('native_seo_off'), true));
        } catch (\Throwable $e) {
            $this->warn('  (geoengine tables missing — run php artisan migrate)');
        }

        return self::SUCCESS;
    }
}
