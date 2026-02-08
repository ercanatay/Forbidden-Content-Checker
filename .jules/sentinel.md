# Sentinel Security Journal

## 2026-02-08 - Error Message Information Leakage in API Controllers
**Vulnerability:** Multiple API controllers (ScanController, ReportController) exposed raw `$e->getMessage()` in JSON error responses. Internal exceptions from database operations, file I/O, or business logic could leak file paths, SQL structure, and PHP internals to API consumers.
**Learning:** The codebase had good security patterns (SSRF guard, CSRF, rate limiting, parameterized queries) but overlooked error message sanitization at the controller layer. Exception messages from deep in the stack (PDO, filesystem) can contain sensitive paths and query fragments.
**Prevention:** Always use the `safeErrorMessage()` helper in `ApiController` when returning error details from catch blocks. Only expose raw messages when `app_debug` is enabled. Log full details server-side for debugging.
