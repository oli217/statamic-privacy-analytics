<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure how analytics data is temporarily stored before processing.
    | The file driver is used by default for simplicity and reliability.
    |
    */
    'cache' => [
        'driver' => env('STATAMIC_ANALYTICS_CACHE_DRIVER', 'file'),

        // File driver specific settings
        'file' => [
            'path' => storage_path('app/statamic-analytics'),
            'permissions' => [
                'file' => 0644,
                'directory' => 0755
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Geolocation Settings
    |--------------------------------------------------------------------------
    |
    | provider : 'disabled' | 'ip-api' | 'maxmind'
    |
    | ip-api   : Appels HTTP vers ip-api.com (gratuit, 45 req/min).
    | maxmind  : Base locale GeoLite2, aucun appel externe. Nécessite
    |            un compte gratuit MaxMind et la commande :
    |            php artisan analytics:update-geoip
    |
    | Rétrocompat : si 'provider' est absent et que 'enabled' est défini,
    |   enabled=true → 'ip-api', enabled=false → 'disabled'.
    |
    */
    'geolocation' => [
        'provider'       => env('ANALYTICS_GEO_PROVIDER', 'maxmind'),
        'cache_duration' => 60 * 24, // minutes, soit 24 h

        'ip_api' => [
            'rate_limit' => 45, // Requêtes par minute (tier gratuit ip-api.com)
        ],

        'maxmind' => [
            'database_path' => storage_path('app/geoip/GeoLite2-City.mmdb'),
            'account_id'    => env('MAXMIND_ACCOUNT_ID'),
            'license_key'   => env('MAXMIND_LICENSE_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Settings
    |--------------------------------------------------------------------------
    |
    | Configure how often analytics data is processed and how many records
    | are processed at once.
    |
    */
    'processing' => [
        'frequency' => 15, // minutes
        'chunk_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking Settings
    |--------------------------------------------------------------------------
    |
    | Configure which requests should be tracked.
    |
    */
    'tracking' => [
        'exclude_ips' => [
            '127.0.0.1',
        ],
        'exclude_paths' => [
            'cp/*',
            'api/*',
        ],
        'exclude_bots' => true,
        'track_authenticated_users' => true,
        'consent' => [
            'enabled' => false,
            'banner' => [
                'title' => 'Privacy Notice',
                'description' => 'We use analytics to understand how you use our website and improve your experience.',
                'accept_button' => 'Accept',
                'decline_button' => 'Decline',
                'settings_button' => 'Customize',
                'position' => 'bottom', // options: bottom, top, center
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Settings
    |--------------------------------------------------------------------------
    |
    | Configure the analytics dashboard behavior.
    |
    */
    'dashboard' => [
        'default_date_range' => '7days',
        'refresh_interval' => 300, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Misc Settings
    |--------------------------------------------------------------------------
    |
    |
    */
    'enable_debugging' => false
];
