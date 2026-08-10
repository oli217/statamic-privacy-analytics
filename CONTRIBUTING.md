# Contributing

Thanks for considering a contribution. This project welcomes bug reports, focused pull requests, and documentation improvements.

## Local setup

```bash
git clone https://github.com/oliweb-ch/statamic-privacy-analytics.git
cd statamic-privacy-analytics
composer install --prefer-dist
```

The addon uses [Orchestra Testbench](https://github.com/orchestral/testbench) to run tests against a minimal Statamic/Laravel application — no separate Statamic install is required to work on this repo.

## Running tests

```bash
vendor/bin/phpunit
```

Tests run against an in-memory SQLite database by default (`phpunit.xml`). The CI pipeline additionally runs the full suite against MySQL, MariaDB, and Redis (as the cache driver) on every push and pull request — see `.github/workflows/tests.yml` for the exact matrix. You don't need these locally to contribute; the CI will catch driver-specific issues you can't reproduce with SQLite alone.

If you're adding a new command, middleware behaviour, or anything touching data retention/anonymisation, please add or update a test alongside the change. This project has been through several rounds of security and privacy audits, and most of the fixes that came out of them are protected by a specific regression test — new changes should follow the same pattern rather than relying on manual verification alone.

## Before opening a pull request

- Run the full test suite locally and make sure it's green.
- If your change affects `README.md` behaviour claims (defaults, retention periods, provider behaviour), update the README in the same PR — a change that's correct in code but undocumented is incomplete.
- If your change is a breaking change (a new default, a change in what gets stored or how long, a new required permission), add an entry to `UPGRADE.md` describing what's affected and how to adapt. Look at the existing entries for the expected level of detail.
- Keep pull requests focused. A PR that fixes one thing is easier to review, verify, and revert if needed than one that bundles several unrelated changes.

## Commit messages

Existing history mostly follows a `type(scope): short description` convention (`fix(geo): ...`, `feat(privacy): ...`, `docs: ...`). Feel free to follow it, but it's not a strict requirement — a clear message in your own words is more useful than a rigid format applied inconsistently.

## Code style

Follow the conventions already present in the surrounding file rather than introducing a new style. No formal linter is currently enforced in CI — this may change as the project grows; check `.github/workflows/` for the current state before assuming otherwise.

## Reporting bugs

Open a GitHub issue with the addon version, PHP version, database engine, and a minimal way to reproduce the problem. For anything that looks like a security or privacy issue rather than a regular bug, please see [SECURITY.md](SECURITY.md) instead of opening a public issue.

## Reporting security issues

Do not open a public issue for security vulnerabilities. See [SECURITY.md](SECURITY.md) for the private reporting process.
