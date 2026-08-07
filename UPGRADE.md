# Upgrade Guide

## Upgrading to 4.4

### Breaking change : CP permissions now required for non-super-admin users

Before v4.4, all authenticated CP users could access the analytics routes. From v4.4 onward, access is gated by two explicit permissions:

| Permission slug | Routes covered |
|---|---|
| `analytics.view` | Dashboard, data, geo-stats, real-time |
| `analytics.manage` | CSV export, geolocation stats reset |

**Super administrators are not affected** — they bypass permission checks automatically.

#### Who is affected?

Any CP user who is **not** a super administrator and who previously accessed the analytics dashboard. After updating, those users will receive a `403 Forbidden` response until one of the permissions above is assigned to their role.

#### How to grant access

1. Go to **CP → Users → Roles** and open (or create) the role assigned to your analytics users.
2. Under the **Analytics** permission group, tick **View analytics dashboard** and/or **Export and manage analytics data**.
3. Save the role.

Refer to [Statamic's documentation on roles and permissions](https://statamic.dev/users#permissions) for details on creating and assigning roles.

#### Minimal read-only access

If you only want users to see the dashboard without being able to export data or reset stats, assign `analytics.view` only. The export button and reset button will be hidden in the UI, and the corresponding routes return `403` server-side.

---

## Upgrading to 2.0

### Breaking change : default geolocation provider changed from `ip-api` to `maxmind`

Prior to 2.0, the addon used `ip-api` (external HTTP calls) as the default geolocation
provider. Starting with 2.0, the default is `maxmind` (local GeoLite2 database, no
external calls).

#### Who is affected?

You are affected if **you never set `ANALYTICS_GEO_PROVIDER` in your `.env`** and
relied on the implicit `ip-api` default. After upgrading, the addon will switch to
`maxmind` automatically.

#### Symptoms if MaxMind is not configured

- A warning banner will appear in the Control Panel analytics dashboard.
- Geolocation will silently return empty results (no country/city data recorded).
- Top countries and Top cities widgets will remain empty (but still visible).

#### Options

**Option A — Keep using `ip-api` (no credentials required):**
```
# .env
ANALYTICS_GEO_PROVIDER=ip-api
```

**Option B — Set up MaxMind GeoLite2 (recommended, full privacy):**
```
# .env
ANALYTICS_GEO_PROVIDER=maxmind
MAXMIND_ACCOUNT_ID=<your_account_id>
MAXMIND_LICENSE_KEY=<your_license_key>
```
Then download the database:
```bash
php artisan analytics:update-geoip
```
See the [README](README.md#maxmind-geolite2-recommended-for-full-privacy) for the
full setup procedure.

**Option C — Disable geolocation entirely:**
```
# .env
ANALYTICS_GEO_PROVIDER=disabled
```
Geographic widgets (Top countries, Top cities) will be hidden from the dashboard.
No IP data is sent to any external service.

---

### What does NOT change

- The `ip-api` and `disabled` providers continue to work exactly as before when
  explicitly configured via `ANALYTICS_GEO_PROVIDER`.
- Retrocompat: if your published config still uses the old boolean key `enabled`
  instead of `provider`, it continues to be honoured (`enabled=true` → `ip-api`,
  `enabled=false` → `disabled`). Migrate to `provider` at your next
  `vendor:publish --force`.
- All other configuration keys, dashboard widgets (non-geographic), and scheduled
  commands are unchanged.

---

### Composer constraint

Users installing via `^1.x` will **not** receive this update automatically — Composer
semver guarantees that. To upgrade explicitly:
```bash
composer require oliweb/statamic-privacy-analytics:^2.0
```

---

## Upgrading to 3.0

**Breaking change (destructive)**: an IP retention policy is now active by default. IP addresses and user-agents for page views older than 90 days will be automatically and irreversibly anonymised (set to NULL) by a daily scheduled command.

#### Who is affected?

All existing installations, from the moment they update to 3.0 and the scheduler runs for the first time after the upgrade.

#### What does NOT change

- Visit statistics (visits, page views, unique visitors) remain intact: they rely on `visitor_id`/`session_id`, not on `ip_address`.
- Already-resolved geolocation data (`country_code`, `city`) on existing page views are **not** affected — only the `ip_address` and `user_agent` columns are set to NULL.

#### Database migration

The `ip_address` column becomes nullable. Run migrations after updating the package:

```bash
php artisan migrate
```

#### Options

**Option A — Keep the default behaviour (recommended):**
Nothing to do. The 90-day retention applies automatically.

**Option B — Adjust the retention window:**
```
# .env
ANALYTICS_IP_RETENTION_DAYS=30
```
Or explicitly in `config/statamic-analytics.php` (after `vendor:publish`):
```php
'privacy' => [
    'ip_retention_days' => 30,
],
```

**Option C — Disable automatic anonymisation (not recommended):**
```
# .env
ANALYTICS_IP_RETENTION_DAYS=null
```
> ⚠️ The word `null` must be written literally, without quotes. A blank value
> (`ANALYTICS_IP_RETENTION_DAYS=`) is interpreted by Laravel as an empty string,
> not as `null`, and would NOT disable anonymisation — it would instead trigger
> immediate anonymisation of virtually all existing data on the next scheduler run.

Or explicitly in the config:
```php
'privacy' => [
    'ip_retention_days' => null,
],
```
> ⚠️ Unlimited IP retention is not recommended and may be difficult to justify depending on the requirements applicable to your deployment. Only enable this option knowingly.

#### Manual anonymisation

To trigger immediate anonymisation (without waiting for the scheduler):
```bash
# Preview affected rows
php artisan analytics:anonymize-ips --dry-run

# Run anonymisation
php artisan analytics:anonymize-ips
```

---

### Composer constraint

Users installing via `^2.x` will **not** receive this update automatically — Composer
semver guarantees that. To upgrade explicitly:
```bash
composer require oliweb/statamic-privacy-analytics:^3.0
```

---

## Upgrading to 4.0

### Breaking change: permanent raw event purge enabled by default

Starting with v4.0, a new daily scheduled command (`analytics:purge-raw-events`) **permanently and irreversibly** deletes rows from `statamic_analytics_page_views` older than 180 days. This is a row deletion, not anonymisation: the data is not recoverable.

#### Who is affected?

All existing installations with data older than 180 days, from the first scheduler run after the update.

#### What is preserved indefinitely

Daily aggregates in `statamic_analytics_aggregates` are **not affected** by the purge. The `_overview` dimension (new in v4.0) preserves per-day totals:
- Total visits, unique visitors, unique page views, returning visitors

Existing dimensions (country, device, browser, platform) are also preserved.

#### What does NOT survive beyond the raw retention window

The following widgets will show empty data for dates older than the retention window:
individual pages, traffic sources, referrers, hourly activity heatmap, session depth, avg. time on page, user flow.

#### Options

**Option A — Keep the default behaviour (recommended):**
Nothing to do. The 180-day raw retention applies automatically.

**Option B — Adjust the retention window:**
```
# .env
ANALYTICS_RAW_RETENTION_DAYS=365
```

**Option C — Disable automatic purge (not recommended):**
```
# .env
ANALYTICS_RAW_RETENTION_DAYS=null
```
> ⚠️ The word `null` must be written literally. A blank value (`ANALYTICS_RAW_RETENTION_DAYS=`) is read by Laravel as an empty string rather than `null` — the defensive guard treats this as unlimited retention, which is the intended fallback, but the intent remains ambiguous when reading the `.env` file.

#### Before updating

Run a `--dry-run` to assess the impact on your existing data:
```bash
php artisan analytics:purge-raw-events --dry-run
```

### Other changes in v4.0

- **CP route renamed**: `statamic-analytics.clear-cache` → `statamic-analytics.reset-stats` (since v3.2.0, noted here to flag the API change).
- **`AnalyticsSettingsController` removed**: controller with no route or view, leftover from the fork.
- **ip-api**: `file_get_contents` replaced by the `Http` facade (timeout 2 s / connect 1 s).
- **`visited_pages` session capped** at 20 entries maximum.
- **`_overview` in aggregates**: `analytics:process` now writes a daily summary without grouping (dimension `_overview`, dimension_value `_all`).

### Composer constraint

```bash
composer require oliweb/statamic-privacy-analytics:^4.0
```
