# Forbidden Content Checker

Forbidden Content Checker is a secure, multilingual, WordPress-first scanning platform for detecting forbidden keywords across websites at scale.

Current release: **v3.2.0** (February 10, 2026)

Latest release highlights:
- Sitemap.xml auto-discovery for automatic target URL detection.
- Scheduled/recurring scans (daily, weekly, monthly, hourly).
- Tag system for organizing and filtering scans.
- Bulk URL import from CSV/TXT files.
- Dashboard with aggregated statistics and top keywords.

## Table of Contents

1. Overview
2. What Is New in v3.2
3. Core Capabilities
4. Architecture
5. Requirements
6. Quick Start
7. Configuration
8. Security Model
9. API Reference
10. Queue and Worker
11. Scheduled Scans
12. Internationalization (10 languages)
13. Tags
14. Reporting and Exports
14. Monitoring and Operations
15. Automatic Updater
16. Compatibility and Migration
17. Testing and CI
18. Troubleshooting
19. Changelog and Release Policy
20. License

## Overview

v3 upgrades the project from a single-file checker to a modular application with:

- Web UI + REST API (`/api/v1/*`)
- SQLite persistence
- Role-based access control (`admin`, `analyst`, `viewer`)
- Secure scanning pipeline with SSRF protection and retry/circuit-breaker logic
- Queue + worker model for large batches
- Localization in 10 languages (including Turkish and Arabic/RTL)
- Multi-format reporting (`csv`, `json`, `xlsx`, `pdf`)

## What Is New in v3.2

- **Sitemap.xml Auto-Discovery**: auto-detect URLs from target site's sitemap
- **Scheduled Scans**: recurring scans on daily/weekly/monthly/hourly basis
- **Tag System**: organize and filter scans with color-coded tags
- **Bulk URL Import**: import from CSV/TXT with deduplication
- **Dashboard**: aggregated statistics, top keywords, daily activity
- Modular architecture under `src/`, `public/`, `database/`, `locales/`, `tests/`
- New API envelope and stable error model
- Auth hardening (Argon2id, CSRF, secure sessions, API tokens)
- Deterministic scan queue behavior and resumable worker flow
- Baseline diff and trend analytics
- Full release documentation + changelog-driven versioning

## Core Capabilities

1. Role-based access control (`admin`, `analyst`, `viewer`)
2. Session login + API token authentication
3. Optional TOTP MFA support
4. Domain allowlist/denylist policy enforcement
5. WordPress-first scan strategy with generic HTML fallback
6. Include/exclude keyword model and regex mode
7. Retry with backoff + jitter and per-domain circuit breaker
8. False-positive suppression rules
9. Historical scan records and immutable run outputs
10. CSV/JSON/XLSX/Signed-PDF exports
11. Webhook and email notifications
12. Health/readiness/metrics endpoints
13. Full i18n support for UI and API messages
14. Sitemap.xml auto-discovery for target URL detection
15. Scheduled/recurring scans with cron-like scheduling
16. Tag/label system for scan organization
17. Bulk URL import from CSV/TXT files
18. Dashboard with aggregated statistics

## Architecture

```text
public/
  index.php
  forbidden_checker.php            # deprecated compatibility shim
  .htaccess
  assets/
    app.css
    app.js
src/
  App.php
  Config.php
  bootstrap.php
  Http/
  Domain/
  Infrastructure/
database/
  schema.sql
locales/
  en-US.json tr-TR.json es-ES.json fr-FR.json de-DE.json
  it-IT.json pt-BR.json nl-NL.json ar-SA.json ru-RU.json
bin/
  worker.php
  updater.php
  scheduler.php
tests/
  run.php
```

## Requirements

- PHP 8.2+
- PHP extensions:
  - `curl`
  - `dom`
  - `mbstring`
  - `openssl`
  - `pdo_sqlite`
  - `zip` (recommended)
- Apache/Nginx or PHP built-in server

## Quick Start

### Local (PHP built-in)

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Open: `http://127.0.0.1:8080`

### Docker

```bash
docker compose up --build
```

Open: `http://127.0.0.1:8080`

### Shared Hosting

1. Upload repository files.
2. Point document root to `public/`.
3. Ensure `storage/` is writable.
4. Ensure Apache rewrite is enabled (`public/.htaccess` included).
5. Define environment variables in hosting panel.

## Configuration

Primary settings are loaded from environment variables.
See: `/Users/ercanatay/Documents/GitHub/Forbidden-Content-Checker/.env.example`

Most important variables:

- `FCC_APP_SECRET`: required; rotate in production
- `FCC_DB_PATH`: SQLite path (default `storage/checker.sqlite`)
- `FCC_LOG_FILE`: log output path
- `FCC_DEFAULT_LOCALE`: default locale (recommended `en-US`)
- `FCC_ALLOW_PRIVATE_NETWORK`: keep `0` unless fully trusted internal targets
- `FCC_WEBHOOK_URL`: optional global webhook endpoint
- `FCC_EMAIL_ENABLED`: set to `1` to enable email digest notifications
- `FCC_UPDATE_ENABLED`: set to `1` to enable update checking/apply flow
- `FCC_UPDATE_REPO`: GitHub repository slug (default `ercanatay/Forbidden-Content-Checker`)
- `FCC_UPDATE_CHECK_INTERVAL_SEC`: periodic check interval (default `21600`, 6 hours)
- `FCC_UPDATE_REQUIRE_APPROVAL`: require admin approval before apply (default `1`)
- `FCC_UPDATE_ALLOW_ZIP_FALLBACK`: fallback to release zip when git update fails (default `1`)
- `FCC_GITHUB_TOKEN`: optional GitHub token to reduce API rate-limit issues
- `FCC_ADMIN_EMAIL` / `FCC_ADMIN_PASSWORD`: bootstrap admin account

Default bootstrap credentials (change immediately):

- Email: `admin@example.com`
- Password: `admin123!ChangeNow`

## Security Model

### Authentication and Authorization

- Session authentication for UI
- Bearer token authentication for automation/API integrations
- RBAC permissions:
  - `admin`: full administration
  - `analyst`: scans, profiles, rules, reports
  - `viewer`: read-only access to scans/reports/metrics

### Security Controls

- CSRF required for state-changing session-auth requests
- Rate limiting (global + per user/IP)
- SSRF protection:
  - HTTP(S)-only enforcement
  - private/reserved IP blocking
  - DNS pinning via cURL resolve
- Domain policy controls (allow/deny)
- Circuit breaker for unstable or failing domains
- Audit logging for sensitive actions

## API Reference

Base path: `/api/v1`

### Envelope

Success:

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {}
}
```

Error:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_error",
    "message": "Validation failed.",
    "locale": "en-US",
    "traceId": "...",
    "details": {}
  },
  "meta": {}
}
```

### Core Endpoints

- `POST /auth/login`
- `POST /auth/logout`
- `POST /auth/tokens`
- `GET /me`
- `GET /locales`
- `POST /scans`
- `GET /scans/{id}`
- `GET /scans/{id}/results`
- `GET /scans/{id}/diff/{baselineId}`
- `POST /scans/{id}/cancel`
- `GET /analytics/trends?period=day|week|month`
- `GET /reports/{id}.{format}` where `format=csv|json|xlsx|pdf`
- `GET /domain-policies`, `POST /domain-policies`
- `GET /suppression-rules`, `POST /suppression-rules`
- `GET /scan-profiles`, `POST /scan-profiles`
- `GET /keyword-sets`, `POST /keyword-sets`
- `GET /healthz`, `GET /readyz`, `GET /metrics`
- `GET /updates/status`
- `POST /updates/check`
- `POST /updates/approve`
- `POST /updates/revoke-approval`
- `POST /sitemap/discover`
- `POST /bulk-import`
- `GET /tags`, `POST /tags`, `DELETE /tags/{id}`
- `POST /scans/{id}/tags`, `GET /scans/{id}/tags`
- `GET /schedules`, `POST /schedules`, `POST /schedules/{id}/toggle`, `DELETE /schedules/{id}`
- `GET /dashboard`

### Example: Create Scan

```bash
curl -X POST http://127.0.0.1:8080/api/v1/scans \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "targets": ["example.com", "https://news.example.org"],
    "keywords": ["casino", "betting"],
    "excludeKeywords": ["demo"],
    "keywordMode": "exact",
    "exactMatch": false,
    "sync": false
  }'
```

## Queue and Worker

Queued jobs are stored in SQLite (`scan_jobs`).

Run one cycle:

```bash
php bin/worker.php --once
```

Run continuously:

```bash
php bin/worker.php
```

Stale running jobs are recovered on worker startup.

## Scheduled Scans

Set up recurring scans that run automatically on a schedule.

### Creating a Schedule

```bash
curl -X POST http://127.0.0.1:8080/api/v1/schedules \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Weekly Production Scan",
    "targets": ["example.com", "blog.example.com"],
    "keywords": ["casino", "betting"],
    "cron": "weekly"
  }'
```

Supported schedule intervals: `hourly`, `daily`, `weekly`, `monthly`, or a 5-part cron expression.

### Running Due Schedules

```bash
php bin/scheduler.php --once
```

Recommended crontab entry:

```bash
* * * * * php /path/to/bin/scheduler.php --once >> /path/to/storage/scheduler.log 2>&1
```

List all schedules: `php bin/scheduler.php --list`

## Tags

Organize scans with color-coded tags. Tags can be created, attached to scan jobs, and used for filtering.

```bash
# Create a tag
curl -X POST http://127.0.0.1:8080/api/v1/tags \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"name": "production", "color": "#ef4444"}'

# Attach tags to a scan
curl -X POST http://127.0.0.1:8080/api/v1/scans/42/tags \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"tagIds": [1, 2]}'
```

## Internationalization (10 Languages)

Supported locales:

- `en-US`
- `tr-TR`
- `es-ES`
- `fr-FR`
- `de-DE`
- `it-IT`
- `pt-BR`
- `nl-NL`
- `ar-SA` (RTL)
- `ru-RU`

Fallback order:

1. explicit query (`?lang=`)
2. user profile locale
3. `Accept-Language`
4. `en-US`

All locale files must keep the same key set.

## Reporting and Exports

Available report outputs:

- `csv` (UTF-8 BOM)
- `json` (full structured metadata)
- `xlsx`
- `pdf` (signed summary with HMAC)

Report endpoint:

- `GET /api/v1/reports/{scanId}.{format}`

## Monitoring and Operations

- Health: `GET /api/v1/healthz`
- Readiness: `GET /api/v1/readyz`
- Metrics (Prometheus): `GET /api/v1/metrics`
- Logs: `storage/logs/app.log`
- Reports: `storage/reports/`
- Database: `storage/checker.sqlite`

## Automatic Updater

Updater mode is designed for VPS/dedicated deployments where shell + write access exists.

- Release channel: stable semantic tags only (`vX.Y.Z`)
- Check policy: periodic (`FCC_UPDATE_CHECK_INTERVAL_SEC`) or manual API/CLI check
- Apply policy: admin approval required by default
- Apply execution: CLI/cron only (no in-request web apply)
- Safety: DB backup + code snapshot + rollback on failed post-apply validation

Updater API:

- `GET /api/v1/updates/status`
- `POST /api/v1/updates/check` (optional body: `{"force": true}`)
- `POST /api/v1/updates/approve` (body: `{"version":"X.Y.Z"}`)
- `POST /api/v1/updates/revoke-approval` (body: `{"version":"X.Y.Z"}`)

Updater CLI:

```bash
php bin/updater.php --status
php bin/updater.php --check
php bin/updater.php --check --force
php bin/updater.php --apply-approved
```

Recommended cron:

```bash
0 */6 * * * php /Applications/XAMPP/xamppfiles/htdocs/backlink.ercanatay.com/Forbidden-Content-Checker/bin/updater.php --check
*/5 * * * * php /Applications/XAMPP/xamppfiles/htdocs/backlink.ercanatay.com/Forbidden-Content-Checker/bin/updater.php --apply-approved
```

## Compatibility and Migration

Legacy endpoint remains available for one release cycle:

- `/public/forbidden_checker.php`
- root `forbidden_checker.php` wrapper

Legacy AJAX contract is still supported and marked deprecated.
Use `/api/v1/*` for all new integrations.

## Testing and CI

Run tests:

```bash
php tests/run.php
```

Checks currently included:

- URL normalization
- locale key completeness
- severity scoring
- TOTP validation
- schema + seed verification
- semantic version comparator and updater state persistence
- updater check/approval flow
- updater apply fallback and rollback flow
- tag service CRUD operations
- bulk import URL parsing and deduplication
- scheduled scan lifecycle
- dashboard statistics aggregation

GitHub Actions CI pipeline:

- PHP lint for all `.php` files
- test run via `php tests/run.php`

## Troubleshooting

### Route not found

- Confirm web root points to `public/`.
- Confirm rewrite is enabled for Apache.

### Authentication required

- Use UI login or Bearer token.

### CSRF errors

- Send `X-CSRF-Token` for session-auth POST/PUT/PATCH/DELETE calls.

### SSRF block errors

- Target resolves to blocked private/reserved address.
- Set `FCC_ALLOW_PRIVATE_NETWORK=1` only in trusted environments.

### XLSX export issues

- Ensure `zip` extension is installed.

## Changelog and Release Policy

- Changelog file: `/Users/ercanatay/Documents/GitHub/Forbidden-Content-Checker/CHANGELOG.md`
- Version file: `/Users/ercanatay/Documents/GitHub/Forbidden-Content-Checker/VERSION`
- Versioning model: Semantic Versioning (`MAJOR.MINOR.PATCH`)

## License

MIT License. See `/Users/ercanatay/Documents/GitHub/Forbidden-Content-Checker/LICENSE`.
