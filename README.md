# Privacy Analytics for Statamic

A self-hosted, privacy-first analytics addon for Statamic. No Google. No third-party scripts. No cookies by default. Your data stays on your server.

> Fork of [mohammedshuaau/enhanced-analytics](https://github.com/mohammedshuaau/enhanced-analytics) — significantly extended and refactored.

## Why this addon?

- **Zero external tracking dependencies** — no Google Analytics, no Matomo cloud, no Plausible cloud
- **Direct DB writes** — every page view is recorded instantly, no processing queue needed for real-time data
- **GDPR-ready** — built-in consent banner with granular controls (optional)
- **Geolocation** — IP → country/city via [ip-api.com](https://ip-api.com) (HTTP, free tier) or locally with [MaxMind GeoLite2](https://www.maxmind.com/en/geolite2/signup) (no external calls)

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
- Three providers : `ip-api` (external HTTP), `maxmind` (local database, no external calls), `disabled`
- **Automatic IP retention** — `ip_address` and `user_agent` are anonymised (set to NULL) after 90 days by default; configurable via `ANALYTICS_IP_RETENTION_DAYS`

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
    'cache' => [
        'driver' => env('STATAMIC_ANALYTICS_CACHE_DRIVER', 'file'),
        'file' => [
            'path' => storage_path('app/statamic-analytics'),
            'permissions' => [
                'file'      => 0644,
                'directory' => 0755,
            ],
        ],
    ],

    'geolocation' => [
        'provider'       => env('ANALYTICS_GEO_PROVIDER', 'maxmind'), // 'disabled' | 'ip-api' | 'maxmind'
        'cache_duration' => 1440, // minutes (24h)

        'ip_api' => [
            'rate_limit' => 45, // requests per minute (ip-api.com free tier)
        ],

        'maxmind' => [
            'database_path' => storage_path('app/geoip/GeoLite2-City.mmdb'),
            'account_id'    => env('MAXMIND_ACCOUNT_ID'),
            'license_key'   => env('MAXMIND_LICENSE_KEY'),
        ],
    ],

    'processing' => [
        'frequency'  => 15,   // minutes — aggregate recalculation frequency
        'chunk_size' => 1000,
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

    'privacy' => [
        'ip_retention_days' => env('ANALYTICS_IP_RETENTION_DAYS', 90), // null = unlimited (not recommended)
    ],

    'enable_debugging' => false,
];
```

### Notes de configuration

**`privacy.ip_retention_days`** — Nombre de jours pendant lesquels `ip_address` et `user_agent` sont conservés avant anonymisation. `null` désactive l'anonymisation automatique (non conforme RGPD/nLPD par défaut). Voir [IP retention](#ip-retention).

**`enable_debugging`** — Active les logs détaillés du middleware de tracking. À ne laisser à `true` qu'en développement.

**Rétrocompatibilité `geolocation.provider`** — Si la clé `provider` est absente du fichier publié (config générée avant cette version), l'addon se rabat sur l'ancienne clé booléenne `enabled` : `enabled=true` → `ip-api`, `enabled=false` → `disabled`. Migrer explicitement vers `provider` lors du prochain `vendor:publish --force`.

---

## Geolocation

### Providers

| Provider | External call | Data | Requirements |
|---|---|---|---|
| `ip-api` | Yes (ip-api.com) | Country + city | None |
| `maxmind` *(default since 2.0)* | No | Country + city | Free MaxMind account + credentials |
| `disabled` | No | — | — |

Set via `.env`:
```
ANALYTICS_GEO_PROVIDER=ip-api      # external HTTP, no credentials needed
# ANALYTICS_GEO_PROVIDER=maxmind   # default — local database, no external calls
# ANALYTICS_GEO_PROVIDER=disabled  # disables geolocation entirely
```

> **If `maxmind` is active but credentials are not configured**, a warning banner appears in the Control Panel dashboard and geolocation will silently return empty results. Geographic widgets (Top countries, Top cities) are hidden entirely when provider is `disabled`.


### MaxMind GeoLite2 (recommended for full privacy)

1. Create a free account at [maxmind.com/en/geolite2/signup](https://www.maxmind.com/en/geolite2/signup)
2. Generate a license key in **My Account → Manage License Keys**
3. Add to `.env`:
   ```
   ANALYTICS_GEO_PROVIDER=maxmind
   MAXMIND_ACCOUNT_ID=<your_account_id>
   MAXMIND_LICENSE_KEY=<your_license_key>
   ```
4. Download the database:
   ```bash
   php artisan analytics:update-geoip
   ```

> **Important :** `analytics:update-geoip` n'est **pas** planifié automatiquement par l'addon (contrairement à `analytics:process`). Vous devez l'ajouter vous-même dans `routes/console.php` :

```php
// routes/console.php
Schedule::command('analytics:update-geoip')->weekly()->tuesdays();
```

MaxMind met à jour GeoLite2 chaque mardi — un déclenchement hebdomadaire le mardi est recommandé.

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

## IP retention

By default, `ip_address` and `user_agent` are automatically set to NULL after **90 days**. This keeps historical visit counts, page view aggregates, and visitor/session identifiers intact while removing personally identifiable data.

The `analytics:anonymize-ips` command runs daily via the Laravel Scheduler (auto-registered by the addon — no manual cron entry needed).

### Configuration

```
# .env
ANALYTICS_IP_RETENTION_DAYS=90   # default
ANALYTICS_IP_RETENTION_DAYS=30   # shorter retention
ANALYTICS_IP_RETENTION_DAYS=null   # unlimited (not recommended) — the literal word "null" is required; a blank value is read as an empty string, not null, and would NOT disable anonymization
```

### Manual run

```bash
# Preview affected rows without modifying anything
php artisan analytics:anonymize-ips --dry-run

# Run anonymisation immediately
php artisan analytics:anonymize-ips
```

### What is NOT affected

- Visit counts, unique visitor counts, and aggregate statistics — they rely on `visitor_id`/`session_id`, not on `ip_address`.
- Already-resolved geolocation data (`country_code`, `country_name`, `city`) — only `ip_address` and `user_agent` columns are set to NULL.

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

Scheduler (daily)
    └─ analytics:anonymize-ips
           └─ UPDATE statamic_analytics_page_views
              SET ip_address = NULL, user_agent = NULL
              WHERE visited_at < now() - ip_retention_days
```

Geolocation (IP → country/city) is resolved by the configured provider — ip-api.com (HTTP, 45 req/min free tier) or MaxMind GeoLite2 (local `.mmdb` file, no external calls). Results are cached for 24 hours. With `provider: disabled`, country/city fields stay `null` and no IP leaves the server.

---

## License

MIT — see [LICENSE.md](LICENSE.md).

Original work © 2024 Mohammed Shuaau.
Modifications © 2026 Olivier Petrucciani (OliWeb - oliweb.ch).
