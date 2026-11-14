<?php

namespace GeoEngine\Agent;

use GeoEngine\Agent\Console\Commands\InstallCommand;
use GeoEngine\Agent\Console\Commands\PollCommand;
use GeoEngine\Agent\Console\Commands\StatusCommand;
use GeoEngine\Agent\Http\Middleware\InjectManagedMeta;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GeoEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/geoengine.php', 'geoengine');
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('')->group(__DIR__ . '/../routes/api.php');

        // Migrations.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Config publish.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/geoengine.php' => config_path('geoengine.php'),
            ], 'geoengine-config');
        }

        // Commands.
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PollCommand::class,
                StatusCommand::class,
            ]);
        }

        // Auto-inject managed meta/redirects into HTML responses.
        if (config('geoengine.inject_middleware', true) && !$this->app->runningInConsole()) {
            $this->app['router']->pushMiddlewareToGroup('web', InjectManagedMeta::class);
        }
    }
}
