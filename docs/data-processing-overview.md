# Statamic Privacy Analytics — Data Processing Overview

*This document is intended for a client, project owner, or legal counsel. It explains how the analytics tool installed on your site works, without going into technical detail.*

## In one sentence

Your visitor data stays on your own server. None of it is sent to Google, Matomo, Plausible, or any other third-party service, unless you explicitly enable a specific option described below.

## What data is collected

On every page visit, the tool records:

- the page viewed (without any query string parameters, e.g. a search term or session identifier)
- the visitor's IP address, temporarily
- device type, browser, and operating system
- country and city, derived from the IP address
- the referring page (where the visitor came from), simplified to the scheme, domain, and path — query string parameters and fragments are discarded
- a session identifier internal to the tool, which does not identify a specific person

If a visitor is logged into your site (customer account, back office), their account identifier may be temporarily associated with their visit. This is enabled by default but can be turned off on request.

## How long this data is kept

Two mechanisms apply automatically, with no action required on your part:

**After 90 days** — the IP address, detailed technical information (specific browser version), and the account identifier are erased. Overall statistics (visit counts, countries, device types) remain available, but can no longer be traced back to a specific IP address or person.

**After 180 days** — the detailed row for each individual visit is permanently deleted. Only aggregated daily totals (visits, visitors, countries, devices) remain available indefinitely, in a form that no longer allows reconstructing an individual visitor's journey.

Both durations are configurable to fit your needs.

## Where this data is hosted

On the same server that already hosts your site. No additional infrastructure is required, no external account is created.

**Geographic location** — to detect a visitor's country and city, two options exist:

- **Default**: a database downloaded once to your server (MaxMind GeoLite2). No IP address ever leaves your server for this step.
- **Alternative option** (must be enabled deliberately): an external service (ip-api.com), which then receives the visitor's IP address to translate it into a country/city. This option is not active by default on your installation unless you've requested otherwise.

## Who on your team can see this data

Access to the dashboard is protected by two levels of permission, assignable individually to each team account:

- **View** — access to the dashboard and statistics.
- **Export and manage** — in addition to viewing, the ability to export raw data (including not-yet-anonymised IP addresses) to a file, and to reset certain internal counters.

An account with neither permission simply does not see the dashboard in their admin interface.

## Cookies and browser storage

No cookie specific to this analytics tool is set on the visitor's browser.

In the standard configuration, tracking relies on the session mechanism your site already uses for normal operation (cart, login, etc.), which may itself rely on a standard technical cookie, independent of this tool.

**If your site uses full static caching** (`STATAMIC_STATIC_CACHING_STRATEGY=full`): the tool stores a persistent first-party analytics identifier in the browser's `localStorage` (not a cookie). This identifier (`_anl_vid`) has no expiry date and is used solely to distinguish new visitors from returning ones. It does not leave the browser except as part of the analytics beacon sent to your own server. A session-scoped identifier (`_anl_sid`, erased when the tab is closed) is also stored in `sessionStorage`.

An optional consent banner can be enabled if you want to make statistical tracking conditional on the visitor's explicit agreement. When enabled, no identifier is created and no beacon is sent until the visitor consents.

## What this means for you in practice

- No data processor to declare in your processing register for the analytics function, unless you deliberately enable the external geolocation option.
- No data transfer outside Switzerland (or your site's hosting country) **in the default configuration** (MaxMind local database). If the optional ip-api provider is enabled, visitor IP addresses are transmitted to ip-api.com (an external service) for geolocation resolution.
- No subscription or traffic-based billing.
- Retention periods can be adjusted, or data deleted, on request.

*For further technical documentation, the full README is available at [github.com/oliweb-ch/statamic-privacy-analytics](https://github.com/oliweb-ch/statamic-privacy-analytics).*
