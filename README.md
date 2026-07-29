# Privacy Analytics for Statamic

A self-hosted, privacy-first analytics addon for Statamic. No Google. No third-party scripts. No cookies by default. Your data stays on your server.

> Fork of [mohammedshuaau/enhanced-analytics](https://github.com/mohammedshuaau/enhanced-analytics) — significantly extended and refactored.

## Why this addon?

- **Zero external tracking dependencies** — no Google Analytics, no Matomo cloud, no Plausible cloud
- **Direct DB writes** — every page view is recorded instantly, no processing queue needed for real-time data
- **GDPR-ready** — built-in consent banner with granular controls (optional)
- **Self-hosted geolocation** — IP → country/city via [ip-api.com](https://ip-api.com) with local caching, no Google Maps

---

## Features

### Dashboard
- Date ranges : 24h, 7 days, 30 days, custom
- Comparison with previous period (visits, unique visitors, bounce rate)
- CSV export
- Auto-refresh (configurable interval)
- Dark mode support

### Widgets
| Widget | Description |
|---|---|
| Overview | Total visits, unique visitors, avg. time on site, bounce rate |
| Visit frequency | New vs returning, pages/session, avg. session duration |
| Page views over time | Line chart, total + unique views per day |
| Top countries | Bar chart + table with % of total |
| Device types | Doughnut chart (desktop / mobile / tablet) |
| Browser usage | Doughnut chart |
| **Traffic sources** | Direct / Search / Social / Referral + top referring domains |
| **Platforms / OS** | Horizontal bar chart |
| **Top cities** | Table with progress bars |
| **Activity heatmap** | 7-day × 24-hour CSS grid, intensity-based coloring |
| **Real-time visitors** | Active sessions/visitors in the last 5 / 15 / 30 min, auto-refresh every 30s |
| **New vs returning trend** | Stacked area chart over the selected period |
| **Session depth** | Page distribution per session (1 / 2-3 / 4-5 / 6-10 / 10+) |
| Page performance | Top 10 pages: views, unique views, avg. time, bounce rate, exit rate |
| User flow | Entry pages, most engaged pages, exit pages |

### CP Dashboard Widget

A compact widget is automatically injected into the Statamic Control Panel dashboard, displaying at-a-glance stats for the last 7 days:

- Visits today
- Total page views (7 days)
- Unique visitors (7 days)

The widget links directly to the analytics dashboard. It is auto-injected if no `analytics_overview` entry is already present in `config/statamic/cp.php`. To control its position or width, add it explicitly:

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'analytics_overview', 'width' => 50],
    // ...
],
```

---

### Privacy & tracking
- Consent banner (disabled by default) with granular controls
- Bot filtering
- Configurable excluded paths and IPs
- Optional authenticated user tracking
- Geolocation optional per-visitor via consent settings

---

## Requirements

- PHP ≥ 8.3
- Statamic ≥ 6.0
- MariaDB / MySQL

---

## Installation

```bash
composer require oliweb/statamic-privacy-analytics
```

Publish the configuration:
```bash
php artisan vendor:publish --tag=statamic-analytics-config
```

Run the migrations:
```bash
php artisan migrate
```

The addon starts tracking immediately. Access the dashboard via **Control Panel → Tools → Analytics**.

---

## Configuration

`config/statamic-analytics.php` :

```php
return [
    'geolocation' => [
        'cache_duration' => 1440, // minutes (24h)
        'rate_limit'     => 45,   // requests per minute (ip-api.com free tier)
    ],

    'processing' => [
        'frequency'    => 15, // minutes — aggregate recalculation frequency
        'lock_timeout' => 60,
    ],

    'dashboard' => [
        'refresh_interval' => 300, // seconds
    ],

    'tracking' => [
        'exclude_paths' => ['cp/*', 'api/*'],
        'exclude_ips'   => [],
        'exclude_bots'  => true,
        'track_authenticated_users' => true,
        'consent' => [
            'enabled' => false, // set to true to require visitor consent
            'banner'  => [
                'title'          => 'Privacy Notice',
                'description'    => 'We use analytics to understand how visitors use our site.',
                'accept_button'  => 'Accept',
                'decline_button' => 'Decline',
                'settings_button'=> 'Customize',
                'position'       => 'bottom', // bottom | top | center
            ],
        ],
    ],
];
```

---

## Consent banner

When `tracking.consent.enabled` is `true`, tracking only starts after visitor consent.

Add to your Antlers layout:

```antlers
{{ statamic_analytics:consent_banner }}
<meta name="csrf-token" content="{{ csrf_token }}">
```

Publish and customize the template:
```bash
php artisan vendor:publish --tag=statamic-analytics-views
```

Template location after publishing:
```
resources/views/vendor/statamic-analytics/components/consent-banner.antlers.html
```

---

## Aggregate recalculation

The addon writes page views directly to the database on every request. A scheduled command recalculates aggregates (by country, device, browser, platform) for today and yesterday:

```bash
php artisan analytics:process
```

This runs automatically via Laravel Scheduler at the frequency defined in config. Make sure the scheduler is running:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## Architecture

```
HTTP request
    └─ TrackPageVisit middleware
           └─ INSERT into statamic_analytics_page_views   ← direct, real-time

Scheduler (every N minutes)
    └─ analytics:process
           └─ DELETE + INSERT into statamic_analytics_aggregates
              (recalculated from page_views for today + yesterday)
```

Geolocation (IP → country/city) is resolved via ip-api.com and cached locally. No data is sent to Google or any tracking platform.

---

## License

MIT — see [LICENSE.md](LICENSE.md).

Original work © 2024 Mohammed Shuaau.
Modifications © 2026 Olivier Petrucciani (OliWeb - oliweb.ch).
