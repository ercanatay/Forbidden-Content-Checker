# Changelog

All notable changes to this project are documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

- No unreleased changes yet.

## [3.1.4] - 2026-02-10

### Changed

- Added `JSON_HEX_TAG` escaping to inline UI bootstrap state rendering.
- Added missing Prometheus `# HELP`/`# TYPE` annotations for `fcc_scan_jobs_status`.
- Replaced rate-limit cleanup string concatenation with parameterized SQL execution.

### Fixed

- Corrected updater path normalization to replace single backslashes.
- Ensured keyword-set creation transaction rolls back on insert failures.
- Fixed strict-type handling in `Request::uriPath()` when `parse_url()` returns non-string values.
- Fixed minimal PDF stream delimiters to emit actual newline characters.

### Security

- Sanitized download filenames before setting `Content-Disposition` headers.
- Replaced raw exception messages with safe API error output in update endpoints.

## [3.1.3] - 2026-02-10

### Changed

- Enhanced frontend `notify()` rendering with icon-backed, accessible notices and inline close controls.
- Added keyboard-visible focus behavior for notification close button interaction parity with hover states.

### Fixed

- Set notification close button `type="button"` to prevent accidental form submission when rendered inside forms.
- Corrected palette journal entry date for chronological consistency with 2026 entries.

## [3.1.2] - 2026-02-09

### Changed

- Refreshed domain-policy and suppression-rule caches per scan job in long-lived worker flows.
- Sanitized API controller error responses to prevent leaking internal exception details by default.
- Hardened authentication timing mitigation by using representative password-hash verification for unknown users.
- Improved auth test isolation with per-test log files, explicit session lifecycle handling, and SQLite sidecar cleanup.

### Fixed

- Fixed updater git-root detection to avoid applying git operations against parent repositories in nested-path scenarios.

### Security

- Mitigated user-enumeration timing side channels in authentication.
- Mitigated information leakage through raw API exception messages.

## [3.1.1] - 2026-02-09

### Changed

- Completed updater UI translations (`update.*` keys) across all non-English locales:
  - tr-TR
  - es-ES
  - fr-FR
  - de-DE
  - it-IT
  - pt-BR
  - nl-NL
  - ru-RU
  - ar-SA
- Removed English fallback text from updater labels/messages in localized language packs.

## [3.1.0] - 2026-02-09

### Added

- Automatic updater domain with GitHub stable tag checks (`vX.Y.Z`) and semantic version comparison.
- Admin update management API endpoints:
  - `GET /api/v1/updates/status`
  - `POST /api/v1/updates/check`
  - `POST /api/v1/updates/approve`
  - `POST /api/v1/updates/revoke-approval`
- CLI updater entrypoint (`bin/updater.php`) with:
  - `--status`
  - `--check` and `--check --force`
  - `--apply-approved`
- Safe apply pipeline with updater lock, DB backup, code snapshot backup, post-apply validation, and rollback.
- Git-first apply transport with optional zip fallback and rollback telemetry in updater state.
- New updater UI panel for admin users in web frontend.
- Updater configuration variables in `.env.example` and runtime config loading.
- Test coverage for version comparator, updater state storage, updater service checks, and apply fallback/rollback scenarios.

## [3.0.0] - 2026-02-08

### Added

- Modular architecture with dedicated `public`, `src`, `database`, `locales`, and `tests` directories.
- REST API v1 with standardized success/error envelope.
- Role-based access control (`admin`, `analyst`, `viewer`).
- Session auth, API token auth, and optional TOTP MFA support.
- CSRF protection and secure session cookie settings.
- Rate limiting for global and per-user/IP controls.
- SQLite persistence schema for users, scans, profiles, keyword sets, suppression rules, notifications, and audit data.
- Queue-backed scan execution and worker process (`bin/worker.php`) with stale job recovery.
- WordPress-first scan strategy (`/?s=` and WordPress REST search) with generic HTML fallback mode.
- Retry with exponential backoff and jitter.
- Domain circuit breaker and allowlist/denylist governance.
- Severity scoring and false-positive suppression rules.
- Baseline diff endpoint and trend analytics endpoint.
- Export engine for CSV, JSON, XLSX, and signed PDF reports.
- Webhook notification channel and optional email digest channel.
- Health (`/healthz`), readiness (`/readyz`), and Prometheus metrics (`/metrics`) endpoints.
- Full i18n runtime support with 10 locales:
  - en-US
  - tr-TR
  - es-ES
  - fr-FR
  - de-DE
  - it-IT
  - pt-BR
  - nl-NL
  - ar-SA (RTL)
  - ru-RU
- Shared-hosting rewrite configuration (`public/.htaccess`).
- Docker support (`Dockerfile`, `docker-compose.yml`).
- CI workflow for linting and tests.
- MIT license file.

### Changed

- Replaced legacy single-file runtime path with modular bootstrap flow.
- Updated user interface to consume REST API and render results using safe DOM APIs.
- Upgraded project documentation to full US-English v3 release documentation.

### Fixed

- Fixed legacy frontend concurrency race by introducing deterministic worker-pool execution logic.
- Removed unsafe rendering patterns that could enable reflected/stored XSS from remote content.
- Implemented robust XPath literal handling for keywords including quotes.
- Improved URL normalization and relative URL resolution behavior.
- Introduced clearer scan status model: `completed`, `partial`, `failed`, `cancelled`.

### Security

- Added SSRF safeguards with host/IP validation and blocked private/reserved ranges.
- Added RBAC enforcement across protected API endpoints.
- Added CSRF validation for session-based state-changing requests.
- Added audit trail records for sensitive auth/config operations.
