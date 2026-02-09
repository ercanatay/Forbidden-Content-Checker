# Sentinel's Journal

## 2026-02-08 - SSRF via Open Redirect
**Vulnerability:** SSRF via Open Redirect (CURLOPT_FOLLOWLOCATION)
**Learning:** Using `CURLOPT_FOLLOWLOCATION => true` in cURL allows the library to automatically follow redirects. If the initial URL is validated against an SSRF policy (e.g., blocking private IPs), a malicious server can redirect the request to a blocked IP (e.g., 127.0.0.1), and cURL will follow it without re-validating, bypassing the protection.
**Prevention:** Disable `CURLOPT_FOLLOWLOCATION`. Implement a manual redirect loop that inspects the `Location` header and validates the new URL against the SSRF policy before following it.

## 2026-02-08 - Error Message Information Leakage in API Controllers
**Vulnerability:** Multiple API controllers (ScanController, ReportController) exposed raw `$e->getMessage()` in JSON error responses. Internal exceptions from database operations, file I/O, or business logic could leak file paths, SQL structure, and PHP internals to API consumers.
**Learning:** The codebase had good security patterns (SSRF guard, CSRF, rate limiting, parameterized queries) but overlooked error message sanitization at the controller layer. Exception messages from deep in the stack (PDO, filesystem) can contain sensitive paths and query fragments.
**Prevention:** Always use the `safeErrorMessage()` helper in `ApiController` when returning error details from catch blocks. Only expose raw messages when `app_debug` is enabled. Log full details server-side for debugging.
