# Forbidden Content Checker

Forbidden Content Checker is a secure, multilingual, WordPress-first scanning platform for detecting forbidden keywords across websites at scale.

Current release: **v3.0.0** (February 8, 2026)

## Table of Contents

1. Overview
2. What Is New in v3
3. Core Capabilities
4. Architecture
5. Requirements
6. Quick Start
7. Configuration
8. Security Model
9. API Reference
10. Queue and Worker
11. Internationalization (10 languages)
12. Reporting and Exports
13. Monitoring and Operations
14. Compatibility and Migration
15. Testing and CI
16. Troubleshooting
17. Changelog and Release Policy
18. License

## Overview

v3 upgrades the project from a single-file checker to a modular application with:

- Web UI + REST API (`/api/v1/*`)
- SQLite persistence
- Role-based access control (`admin`, `analyst`, `viewer`)
- Secure scanning pipeline with SSRF protection and retry/circuit-breaker logic
- Queue + worker model for large batches
- Localization in 10 languages (including Turkish and Arabic/RTL)
- Multi-format reporting (`csv`, `json`, `xlsx`, `pdf`)

## What Is New in v3

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
