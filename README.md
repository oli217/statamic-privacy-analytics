# Privacy Analytics for Statamic
![PHP Require](https://img.shields.io/badge/PHP-^8.3-blue?link=https://php.net)   ![Statamic 6](https://img.shields.io/badge/Statamic-6.0+-FF269E?link=https://statamic.com)  [![Tests](https://github.com/oliweb-ch/statamic-privacy-analytics/actions/workflows/tests.yml/badge.svg)](https://github.com/oliweb-ch/statamic-privacy-analytics/actions/workflows/tests.yml)


A self-hosted, privacy-first analytics addon for Statamic. No Google. No third-party scripts. No analytics-specific cookies by default (the addon uses the host application's Laravel session, which may itself rely on a session cookie). Your data stays on your server.

>Originally forked from [mohammedshuaau/enhanced-analytics](https://github.com/mohammedshuaau/enhanced-analytics). Since then, the project has diverged substantially: a privacy-first geolocation architecture (MaxMind local by default), a full data retention and anonymisation system, granular CP permissions, optional asynchronous tracking, and an extensive automated test suite — none of which existed in the original.

## Why this addon?

- **Zero external tracking dependencies** — no Google Analytics, no Matomo cloud, no Plausible cloud
- **Direct DB writes** — every page view is recorded instantly, no processing queue needed for real-time data
- **Privacy-conscious by design** — configurable data retention, anonymisation, purge, consent banner (optional), and disableable geolocation; provides the mechanisms for GDPR/nLPD-conscious deployments without constituting a legal guarantee in itself
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
> A non-technical summary of what data is collected, retained, and who can access it is available in [`docs/data-processing-overview.md`](docs/data-processing-overview.md) ([French version](docs/data-processing-overview.fr.md)) — useful to share directly with a client or their legal counsel.
- Consent banner (disabled by default) with granular controls
- Bot filtering
- Configurable excluded paths and IPs
- Optional authenticated user tracking — when enabled (default), the authenticated user ID is temporarily associated with analytics events and automatically set to NULL during the anonymisation process (`privacy.ip_retention_days`); this is not a permanent association
- Geolocation optional per-visitor via consent settings
- Three providers : `ip-api` (external HTTP), `maxmind` (local database, no external calls), `disabled`
- **Automatic IP retention** — `ip_address`, `user_agent`, and `user_id` are anonymised (set to NULL) after 90 days by default; configurable via `ANALYTICS_IP_RETENTION_DAYS`
- **HTML-only tracking** — only responses with a `200 OK` status **and** a `text/html` Content-Type are recorded. Automated scans (404s), static assets served by third-party addons (`.js`, `.wasm`, etc.), and API JSON responses are silently ignored without requiring a manual exclusion list.
- **Session-safe** — the tracking middleware never invalidates or regenerates the session. It only adds its own keys (`analytics_session_started`, `visitor_id`, `visited_pages`, `last_visit_date`, `last_visit_hour`); all pre-existing session data (cart, auth, form state, etc.) is left intact.

### CP Permissions

Two granular permissions gate access to the analytics control panel routes. They are assignable to any Statamic role via **CP → Users → Roles**.

| Permission | Slug | What it covers |
|---|---|---|
| View analytics dashboard | `analytics.view` | Dashboard, aggregated data, geolocation stats, real-time visitors |
| Export and manage analytics data | `analytics.manage` | CSV export (contains plain-text IPs), geolocation stats reset |

**Super administrators** bypass all permission checks automatically — they always have full access regardless of role configuration.

Users who hold `analytics.view` but not `analytics.manage` will see the dashboard and all statistics. The Export CSV button and the geolocation stats reset button are hidden in the UI (client-side UX only — the routes themselves are protected server-side independently).

---

## Requirements

- PHP ≥ 8.3
- Statamic ≥ 6.0
- Any database engine supported by your Statamic/Laravel installation — the addon uses Laravel's database abstraction exclusively and does not require a specific engine. Tested in CI against: SQLite, MySQL 8.0, MariaDB 11.

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

### Configuration notes

**`privacy.ip_retention_days`** — Number of days `ip_address`, `user_agent`, and `user_id` are kept before anonymisation. `null` disables automatic anonymisation — not recommended, and unlimited retention may be difficult to justify depending on the requirements applicable to your deployment. See [IP retention](#ip-retention).

**`enable_debugging`** — Enables detailed logging in the tracking middleware. Leave this `false` outside of development.

**`geolocation.provider` backward compatibility** — If the `provider` key is absent from the published config (generated before this version), the addon falls back to the legacy boolean key `enabled`: `enabled=true` → `ip-api`, `enabled=false` → `disabled`. Migrate explicitly to `provider` on your next `vendor:publish --force`.

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

> **`ip-api` is an optional external provider, not the default.** When active, visitor IP addresses are transmitted to ip-api.com with each uncached geolocation request. The 45 req/min rate limit is a constraint of the ip-api.com free tier, not a configuration choice of this addon. For full privacy with no external IP transmission, use `maxmind` (local database) or `disabled`.


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

> **Important:** `analytics:update-geoip` is **not** scheduled automatically by the addon — this is a deliberate choice. Triggering an automatic download to an external service is an infrastructure decision that belongs to each project, not to an addon. Add it yourself in `routes/console.php` at the frequency you prefer:

```php
// routes/console.php
Schedule::command('analytics:update-geoip')->weekly()->tuesdays();
```

MaxMind updates GeoLite2 every Tuesday — a weekly schedule on Tuesday is recommended.

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

If the scheduler was interrupted for several days, use `--days` to backfill the missing aggregates:

```bash
php artisan analytics:process --days=7
```

The default (`--days=2`, today + yesterday) is unchanged for automatic scheduling.

---

## Asynchronous tracking (opt-in)

By default, page view recording (geolocation lookup + database write) happens synchronously during the HTTP request. For sites with very high traffic where the DB write or geolocation resolution becomes a **measured** bottleneck, you can offload this work to a Laravel queue worker.

> **For the vast majority of sites (low to medium traffic), this mode brings no measurable benefit.** Only enable it when you have concrete evidence that synchronous recording is a bottleneck — not as a precaution.

### What moves to the queue vs. what stays synchronous

| Stays synchronous (HTTP request) | Deferred to queue worker |
|---|---|
| Session mutations (`visitor_id`, `visited_pages`, flags) | Geolocation lookup (IP → country/city) |
| User-agent parsing, device/browser detection | Database INSERT |
| Referrer sanitization | — |

Session data **must** be manipulated during the HTTP cycle. It cannot be safely accessed from a queue job running outside the request context.

### Configuration

```
# .env
ANALYTICS_QUEUE_CONNECTION=redis   # any Laravel queue connection
ANALYTICS_QUEUE_NAME=analytics     # optional, defaults to "analytics"
```

```php
// config/statamic-analytics.php
'tracking' => [
    'queue_connection' => env('ANALYTICS_QUEUE_CONNECTION', null), // null = synchronous (default)
    'queue_name'       => env('ANALYTICS_QUEUE_NAME', 'analytics'),
    // ... existing keys unchanged
],
```

`queue_connection = null` (the default) preserves the existing synchronous behaviour exactly. No worker is needed.

### Prerequisites

**A queue worker must be running**, otherwise jobs accumulate in the queue storage without ever executing:

```bash
php artisan queue:work --queue=analytics
```

In production, supervise it with Supervisor or a systemd unit so it restarts automatically.

**Multi-container / split worker deployments** — if your web server and queue worker run in separate containers or machines, the MaxMind `GeoLite2-City.mmdb` file must be accessible on a shared volume mounted at the same path on both sides. Without it, geolocation silently falls back to `emptyResult()` (all geo fields remain `null`) — the visit is still recorded, but without country/city data.

### Retry behaviour

Jobs use 3 attempts with an exponential backoff of 10 / 30 / 60 seconds. Definitive failures appear in Laravel's standard `failed_jobs` table.

### Payload encryption

`TrackPageViewJob` implements `ShouldBeEncrypted`. The job payload — which may contain IP addresses, user-agents, and user IDs — is **encrypted with `APP_KEY`** before being pushed to the queue backend. This applies equally to `failed_jobs` entries written on definitive failure: no PII reaches the queue storage or the failed-jobs table in plaintext.

The `analytics:purge-failed-jobs` command automatically removes stale `TrackPageViewJob` entries from `failed_jobs` using the same `ip_retention_days` threshold, so encrypted payloads do not accumulate indefinitely.

### Safety net

If queue dispatch fails (misconfigured connection, driver unavailable), the middleware catches the exception, logs an error, and falls back to synchronous recording. This does not cover post-dispatch failures (worker crash, job exhausting its retries) — those appear in `failed_jobs` and are handled by `analytics:purge-failed-jobs`:

```
StatamicAnalytics: queue dispatch failed, falling back to synchronous recording
```

---

## IP retention

By default, `ip_address`, `user_agent`, and `user_id` are automatically set to NULL after **90 days**. This keeps historical visit counts, page view aggregates, and visitor/session identifiers intact while removing personally identifiable data.

`track_authenticated_users` is enabled by default, so authenticated user IDs are recorded at visit time. However, the `user_id` link is automatically broken at the same retention threshold as the IP — it is therefore not a permanent association.

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
- Already-resolved geolocation data (`country_code`, `country_name`, `city`) — only `ip_address`, `user_agent`, and `user_id` columns are set to NULL.

---

## Raw event retention

By default, raw page view rows are permanently deleted after **180 days** via the `analytics:purge-raw-events` command (runs daily, auto-registered by the addon).

This is distinct from IP anonymisation (`ip_retention_days`): anonymisation sets PII columns to NULL but keeps the row; purge removes the row entirely.

### Configuration

```
# .env
ANALYTICS_RAW_RETENTION_DAYS=180   # default
ANALYTICS_RAW_RETENTION_DAYS=365   # longer window
ANALYTICS_RAW_RETENTION_DAYS=null  # unlimited (not recommended) — literal "null" required
```

### Manual run

```bash
# Preview affected rows without deleting
php artisan analytics:purge-raw-events --dry-run

# Run purge immediately
php artisan analytics:purge-raw-events
```

### What survives indefinitely (aggregate table)

The `_overview` dimension (written by `analytics:process` on each run) preserves per-day totals regardless of purge:

- Total visits
- Unique visitors
- Unique page views
- Returning visitors

Country, device, browser, and platform breakdowns are also preserved in the aggregate table.

### What does NOT survive beyond the raw retention window

Widgets that query `statamic_analytics_page_views` directly will show empty data for dates older than the retention window:

- Individual page performance (top pages, avg. time, exit rate)
- Traffic sources and referrers
- Hourly activity heatmap
- Session depth
- User flow (entry/exit pages)
- Avg. session duration

---

## Architecture

```
HTTP request
    └─ TrackPageVisit middleware
           ├─ $next($request) → executes the full request stack
           ├─ (skip if status ≠ 200 or Content-Type ≠ text/html)
           └─ INSERT into statamic_analytics_page_views   ← direct, real-time

Scheduler (every N minutes)
    └─ analytics:process
           └─ DELETE + INSERT into statamic_analytics_aggregates
              (recalculated from page_views for today + yesterday)

Scheduler (daily)
    └─ analytics:anonymize-ips
           └─ UPDATE statamic_analytics_page_views
              SET ip_address = NULL, user_agent = NULL, user_id = NULL
              WHERE visited_at < now() - ip_retention_days

    └─ analytics:purge-raw-events
           └─ DELETE FROM statamic_analytics_page_views
              WHERE visited_at < now() - raw_retention_days

    └─ analytics:purge-failed-jobs
           └─ DELETE FROM failed_jobs
              WHERE failed_at < now() - ip_retention_days
              AND payload LIKE '%TrackPageViewJob%'
```

Geolocation (IP → country/city) is resolved by the configured provider — ip-api.com (HTTP, 45 req/min free tier) or MaxMind GeoLite2 (local `.mmdb` file, no external calls). Results are cached for 24 hours. With `provider: disabled`, country/city fields stay `null` and no IP leaves the server.

---

## License

MIT — see [LICENSE.md](LICENSE.md).

Original work © 2024 Mohammed Shuaau.
Modifications © 2026 Olivier Petrucciani (OliWeb - oliweb.ch).
