# Forbidden Content Checker v3

A modular, secure, multilingual forbidden-content scanning platform for WordPress-first and generic website checks.

- Runtime: PHP 8.2+
- UI + REST API: `/public/index.php` + `/api/v1/*`
- Storage: SQLite (`storage/checker.sqlite` by default)
- Auth: session login + API tokens + role-based permissions
- Languages: 10 locales including Turkish and Arabic (RTL)

## Feature Matrix (36 Professional Features)

| # | Feature | Status |
|---|---|---|
| 1 | Role-based access control (`admin`, `analyst`, `viewer`) | Implemented |
| 2 | Secure login/logout with Argon2id hashing | Implemented |
| 3 | Optional TOTP MFA for admin users | Implemented |
| 4 | CSRF protection for state-changing requests | Implemented |
| 5 | Secure session cookie policy (`HttpOnly`, `Secure`, `SameSite=Lax`) | Implemented |
| 6 | API token management with scopes | Implemented |
| 7 | Full audit trail for auth and config actions | Implemented |
| 8 | Scan history with immutable run records | Implemented |
| 9 | Domain allowlist and denylist governance | Implemented |
| 10 | SSRF hardening with private/reserved IP block and DNS pinning | Implemented |
| 11 | Global and per-user/IP rate limiting | Implemented |
| 12 | Retry policy with exponential backoff and jitter | Implemented |
| 13 | Per-domain circuit breaker | Implemented |
| 14 | SQLite-backed queue for background scans | Implemented |
| 15 | Worker process with configurable concurrency behavior | Implemented |
| 16 | Resumable scans after restart (stale job recovery) | Implemented |
| 17 | Scan profiles | Implemented |
| 18 | Keyword sets with include/exclude groups | Implemented |
| 19 | Exact-match and regex keyword modes | Implemented |
| 20 | WordPress-first detection (`/?s=`, `wp-json/wp/v2/search`) | Implemented |
| 21 | Generic HTML fallback scanning | Implemented |
| 22 | Pagination traversal (depth-capped) | Implemented |
| 23 | Canonical URL normalization and dedupe | Implemented |
| 24 | Content-type validation before parsing | Implemented |
| 25 | Severity scoring per finding | Implemented |
| 26 | False-positive suppression rules | Implemented |
| 27 | Baseline comparison and diff reports | Implemented |
| 28 | Trend analytics by day/week/month | Implemented |
| 29 | CSV export with UTF-8 BOM | Implemented |
| 30 | JSON export with metadata | Implemented |
| 31 | XLSX export | Implemented |
| 32 | Signed PDF summary report (HMAC signature) | Implemented |
| 33 | Webhook notifications | Implemented |
| 34 | Email completion digest notifications | Implemented |
| 35 | Health, readiness, and metrics endpoints | Implemented |
| 36 | Full i18n for UI + API errors in 10 languages | Implemented |

## Repository Layout

```text
public/
  index.php
  forbidden_checker.php         # deprecated compatibility shim
  assets/
    app.css
    app.js
src/
  App.php
  Config.php
  bootstrap.php
  Http/
    Router.php
    Request.php
    Response.php
    Controllers/
  Domain/
    Auth/
    I18n/
    Scan/
    Analytics/
  Infrastructure/
    Db/
    Queue/
    Security/
    Export/
    Notification/
    Observability/
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

- PHP 8.2+ with extensions:
  - `curl`
  - `dom`
  - `mbstring`
  - `openssl`
  - `pdo_sqlite`
  - `zip` (recommended for native XLSX generation)
- Web server (Apache/Nginx) or PHP built-in server

## Quick Start

### Option A: Local PHP Built-in Server

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

### Option B: Docker

```bash
docker compose up --build
```

Open `http://127.0.0.1:8080`.

### Option C: Shared Hosting

1. Upload project files.
2. Point document root to `public/`.
3. Set writable permissions for `storage/`.
4. Ensure Apache rewrite is enabled (`public/.htaccess` is included).
5. Set environment variables in hosting panel.

## Default Admin Account

Initial seed values (change immediately in production):

- Email: `admin@example.com`
- Password: `admin123!ChangeNow`

Override with:

- `FCC_ADMIN_EMAIL`
- `FCC_ADMIN_PASSWORD`

## Environment Variables

See `.env.example` for full list.

Key variables:

- `FCC_APP_SECRET`: required for secure token hashing and report signatures
- `FCC_DB_PATH`: SQLite database location
- `FCC_LOG_FILE`: app log output
- `FCC_DEFAULT_LOCALE`: default locale (`en-US`)
- `FCC_ALLOW_PRIVATE_NETWORK`: set `1` only for trusted internal scans
- `FCC_WEBHOOK_URL`: optional global webhook
- `FCC_EMAIL_ENABLED`: set `1` to send email notifications

## Security Model

### Authentication and Authorization

- Session-based login for web UI
- Bearer API token auth for automation
- RBAC:
  - `admin`: full access
  - `analyst`: scan + config + reports
  - `viewer`: read-only scan/report access

### Controls

- CSRF token required on state-changing requests (session-auth paths)
- Request rate limiting (global and per user/IP)
- SSRF protection with scheme restriction + IP range blocking + DNS pinning
- Domain allow/deny policy enforcement
- Circuit breaker to reduce repeated failures to unstable hosts

## API Reference (v1)

Base path: `/api/v1`

### Response Envelope

```json
{
  "success": true,
  "data": {},
  "error": null,
  "meta": {}
}
```

Error envelope fields:

- `code`
- `message`
- `locale`
- `traceId`
- `details`

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
- `GET /reports/{id}.{format}` (`csv|json|xlsx|pdf`)
- `GET /domain-policies`, `POST /domain-policies`
- `GET /suppression-rules`, `POST /suppression-rules`
- `GET /scan-profiles`, `POST /scan-profiles`
- `GET /keyword-sets`, `POST /keyword-sets`
- `GET /healthz`, `GET /readyz`, `GET /metrics`

### Example: Create a Scan Job

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

### Example: Download Report

```bash
curl -L "http://127.0.0.1:8080/api/v1/reports/12.csv" \
  -H "Authorization: Bearer <TOKEN>" \
  -o scan-12.csv
```

## i18n Guide

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

1. Explicit query (`?lang=`)
2. User profile locale
3. `Accept-Language`
4. `en-US`

All locale files share an identical key catalog. Validation is enforced in tests.

## Queue and Worker

Queue jobs are stored in SQLite (`scan_jobs`).

Process one job and exit:

```bash
php bin/worker.php --once
```

Run continuously:

```bash
php bin/worker.php
```

Stale running jobs are recovered automatically at worker startup.

## Compatibility and Migration

- Legacy endpoint is kept at:
  - `/public/forbidden_checker.php`
  - root `forbidden_checker.php` wrapper
- Legacy AJAX payloads still receive a compatibility response with deprecation headers.
- New development should target `/api/v1/*`.

## Testing and Quality Gates

Run tests:

```bash
php tests/run.php
```

Coverage includes:

- URL normalization
- Locale key completeness
- Severity scoring
- TOTP verification
- Schema and seed validation

Recommended CI gates:

1. `php -l` on all PHP files
2. `php tests/run.php`
3. locale key parity check (included)
4. API smoke checks

## Troubleshooting

### 1) `Route not found`

- Confirm web root points to `public/`.

### 2) `Authentication required`

- Login via UI or send `Authorization: Bearer <token>`.

### 3) CSRF errors on POST

- For session-auth requests, include header `X-CSRF-Token`.

### 4) Scans fail with SSRF block

- Target resolves to private/reserved IP.
- Use `FCC_ALLOW_PRIVATE_NETWORK=1` only in trusted environments.

### 5) No XLSX output

- Ensure `zip` extension is installed.

### 6) Email notifications not sending

- Set `FCC_EMAIL_ENABLED=1` and confirm mail transport availability.

## Operations Notes

- Logs: `storage/logs/app.log`
- Reports: `storage/reports/`
- DB: `storage/checker.sqlite`
- Metrics: `/api/v1/metrics` (Prometheus format)

## License

This project is licensed under the MIT License. See `LICENSE`.
