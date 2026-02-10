# Sentinel's Journal

## 2026-02-08 - SSRF via Open Redirect
**Vulnerability:** SSRF via Open Redirect (CURLOPT_FOLLOWLOCATION)
**Learning:** Using `CURLOPT_FOLLOWLOCATION => true` in cURL allows the library to automatically follow redirects. If the initial URL is validated against an SSRF policy (e.g., blocking private IPs), a malicious server can redirect the request to a blocked IP (e.g., 127.0.0.1), and cURL will follow it without re-validating, bypassing the protection.
**Prevention:** Disable `CURLOPT_FOLLOWLOCATION`. Implement a manual redirect loop that inspects the `Location` header and validates the new URL against the SSRF policy before following it.

## 2026-02-08 - Error Message Information Leakage in API Controllers
**Vulnerability:** Multiple API controllers (ScanController, ReportController) exposed raw `$e->getMessage()` in JSON error responses. Internal exceptions from database operations, file I/O, or business logic could leak file paths, SQL structure, and PHP internals to API consumers.
**Learning:** The codebase had good security patterns (SSRF guard, CSRF, rate limiting, parameterized queries) but overlooked error message sanitization at the controller layer. Exception messages from deep in the stack (PDO, filesystem) can contain sensitive paths and query fragments.
**Prevention:** Always use the `safeErrorMessage()` helper in `ApiController` when returning error details from catch blocks. Only expose raw messages when `app_debug` is enabled. Log full details server-side for debugging.

## 2026-05-24 - Timing Attack in Authentication
**Vulnerability:** User enumeration via timing attack in `AuthService::login`. The system returned early when a user was not found, skipping the computationally expensive `password_verify` (Argon2id) call.
**Learning:** Returning generic error messages is insufficient if the time taken to generate the error varies significantly. Attackers can measure the response time to determine if an email exists in the database.
**Prevention:** Ensure constant-time execution for authentication logic. Perform a dummy password verification against a representative hash when the user is not found, so the response time is indistinguishable from a failed password attempt for a valid user.

## 2026-05-25 - Unauthenticated Legacy Endpoint Bypass
**Vulnerability:** Unauthenticated Legacy Endpoint (Public Function)
**Learning:** Legacy endpoints like `fcc_legacy_scan_response` in `src/bootstrap.php` often bypass standard routing and middleware security checks. This specific function allowed unauthenticated users to trigger scan jobs on behalf of the administrator (first user in DB) by spoofing an AJAX header.
**Prevention:** Audit all entry points, especially standalone PHP files or helper functions in bootstrap, to ensure they enforce authentication before performing sensitive actions. Use centralized routing with middleware whenever possible.
