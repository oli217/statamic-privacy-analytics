<?php

namespace Oliweb\StatamicAnalytics\Http\Controllers;

use Illuminate\Http\Request;
use Oliweb\StatamicAnalytics\Services\GeolocationService;

class AnalyticsSettingsController
{
    public function index()
    {
        return view('statamic-analytics::settings', [
            'stats' => GeolocationService::getStats(),
            'config' => [
                'cache_driver'               => config('statamic-analytics.cache.driver'),
                'geolocation_provider'       => config('statamic-analytics.geolocation.provider', 'ip-api'),
                'geolocation_cache_duration' => config('statamic-analytics.geolocation.cache_duration'),
                'geolocation_rate_limit'     => config('statamic-analytics.geolocation.ip_api.rate_limit', config('statamic-analytics.geolocation.rate_limit', 45)),
                'processing_frequency'       => config('statamic-analytics.processing.frequency'),
                'processing_chunk_size'      => config('statamic-analytics.processing.chunk_size'),
                'excluded_ips'               => config('statamic-analytics.tracking.exclude_ips'),
                'excluded_paths'             => config('statamic-analytics.tracking.exclude_paths'),
                'exclude_bots'               => config('statamic-analytics.tracking.exclude_bots'),
                'track_authenticated_users'  => config('statamic-analytics.tracking.track_authenticated_users'),
            ]
        ]);
    }

    public function resetStats()
    {
        try {
            GeolocationService::resetStats();
            return response()->json([
                'success' => true,
                'message' => 'Geolocation statistics reset. Individual IP lookup cache entries expire automatically within the configured cache_duration and cannot be force-cleared across all cache drivers.',
                'stats'   => GeolocationService::getStats(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getStats()
    {
        return response()->json([
            'success' => true,
            'stats' => GeolocationService::getStats()
        ]);
    }
} 