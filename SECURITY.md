# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in this addon, please report it privately rather than opening a public issue. A public issue on a privacy-focused addon can expose users before a fix is available.

**Preferred method:** [GitHub Security Advisories](https://github.com/oliweb-ch/statamic-privacy-analytics/security/advisories/new) — this creates a private discussion visible only to the maintainer until a fix is ready.

**Alternative:** email the maintainer directly (see [oliweb.ch](https://oliweb.ch) for contact details).

Please include:

- A description of the vulnerability and its potential impact
- Steps to reproduce (a minimal reproduction case is ideal)
- The version of the addon, PHP, Statamic, and database engine you're using
- Any relevant configuration (geolocation provider, queue mode, retention settings) if it affects reproduction

## What's in scope

- The addon's own code (`src/`, `resources/`, `database/migrations/`)
- Configuration defaults that could lead to unintended data exposure
- Authorization/permission bypasses on the Control Panel routes
- Data retention or anonymisation logic that doesn't behave as documented

## What's out of scope

- Vulnerabilities in third-party dependencies (`statamic/cms`, `matomo/device-detector`, `geoip2/geoip2`, etc.) — please report these upstream, though a note here is still welcome so users can be advised
- Vulnerabilities requiring an already-compromised server or filesystem access (at that point, the addon's own protections are not the relevant boundary)
- Issues specific to a self-managed queue driver, database engine, or hosting environment misconfiguration outside the addon's control

## Response

This is a single-maintainer open-source project. There is no guaranteed SLA, but security reports are prioritised over regular feature work. Expect an initial acknowledgement within a few days. A fix, once confirmed, is typically released as a patch version with a corresponding entry in the [GitHub Releases](https://github.com/oliweb-ch/statamic-privacy-analytics/releases) notes describing the issue and impact — without details that would help exploit unpatched installations.

## Supported versions

Only the latest published major version receives security fixes. Given the pace of development on this project, upgrading to the latest tag is the simplest way to stay covered.
