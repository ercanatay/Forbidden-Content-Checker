# Sentinel's Journal

## 2026-02-08 - SSRF via Open Redirect
**Vulnerability:** SSRF via Open Redirect (CURLOPT_FOLLOWLOCATION)
**Learning:** Using `CURLOPT_FOLLOWLOCATION => true` in cURL allows the library to automatically follow redirects. If the initial URL is validated against an SSRF policy (e.g., blocking private IPs), a malicious server can redirect the request to a blocked IP (e.g., 127.0.0.1), and cURL will follow it without re-validating, bypassing the protection.
**Prevention:** Disable `CURLOPT_FOLLOWLOCATION`. Implement a manual redirect loop that inspects the `Location` header and validates the new URL against the SSRF policy before following it.
