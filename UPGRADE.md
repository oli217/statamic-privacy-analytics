# Upgrade Guide

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
