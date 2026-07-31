<?php

namespace Oliweb\StatamicAnalytics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        Commands\ProcessAnalytics::class,
        Commands\UpdateGeoIpDatabase::class,
    ];

    protected $routes = [
        'cp' => __DIR__ . '/../routes/cp.php',
        'web' => __DIR__ . '/../routes/web.php',
    ];

    protected $middleware = [
        Middleware\TrackPageVisit::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            Middleware\TrackPageVisit::class,
        ],
    ];

    protected $middlewarePriority = [
        \Illuminate\Session\Middleware\StartSession::class,
        Middleware\TrackPageVisit::class,
    ];

    protected $tags = [
        Tags\ConsentBanner::class,
    ];

    protected $widgets = [
        Widgets\AnalyticsOverviewWidget::class,
    ];

    protected $vite = [
        'input' => [
            'resources/js/consent-banner.js',
            'resources/js/statamic-analytics.js',
            'resources/css/statamic-analytics.css'
        ],
        'publicDirectory' => 'resources/dist',
    ];

    public function bootAddon()
    {
        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'statamic-analytics');

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/statamic-analytics.php' => config_path('statamic-analytics.php'),
        ], 'statamic-analytics-config');

        // Merge configuration early so we can use it
        $this->mergeConfigFrom(
            __DIR__ . '/../config/statamic-analytics.php', 'statamic-analytics'
        );

        // Publish views/components
        $this->publishes([
            __DIR__ . '/../resources/views/components/consent-banner.antlers.html' => resource_path('views/vendor/statamic-analytics/components/consent-banner.antlers.html'),
        ], 'statamic-analytics-views');

        // Ensure storage directory exists with proper permissions (if using file driver)
        $this->ensureStorageDirectoryExists();

        // Auto-inject the analytics overview widget into the CP dashboard if not already configured
        $widgets = config('statamic.cp.widgets', []);
        if (!collect($widgets)->contains('type', 'analytics_overview')) {
            config(['statamic.cp.widgets' => array_merge($widgets, [
                ['type' => 'analytics_overview', 'width' => 50],
            ])]);
        }

        // Register the nav item
        Nav::extend(function ($nav) {
            $nav->create(__('statamic-analytics::messages.nav_item'))
                ->section('Tools')
                ->route('statamic-analytics.index')
                ->icon('chart-monitoring-indicator');
        });

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register scheduled tasks
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $frequency = config('statamic-analytics.processing.frequency', 15);

            // Generate the cron expression for custom minutes
            $cronExpression = "*/{$frequency} * * * *";

            $schedule->command('analytics:process')
                ->withoutOverlapping()
                ->appendOutputTo(storage_path('logs/analytics-scheduler.log'))
                ->cron($cronExpression);
        });
    }

    protected function ensureStorageDirectoryExists()
    {
        try {
            // Only create directory if using file driver
            if (config('statamic-analytics.cache.driver') === 'file') {
                $path = config('statamic-analytics.cache.file.path', storage_path('app/statamic-analytics'));
                $permissions = config('statamic-analytics.cache.file.permissions.directory', 0755);

                if (!File::exists($path)) {
                    File::makeDirectory($path, $permissions, true);
                }
            }

            // Créer le dossier geoip si le provider MaxMind est actif
            if (config('statamic-analytics.geolocation.provider') === 'maxmind') {
                $geoipDir = dirname(config(
                    'statamic-analytics.geolocation.maxmind.database_path',
                    storage_path('app/geoip/GeoLite2-City.mmdb')
                ));

                if (!File::exists($geoipDir)) {
                    File::makeDirectory($geoipDir, 0775, true);
                }
            }
        } catch (\Exception $e) {
            report($e);
        }
    }
}
