# Sentinel's Journal

## 2026-02-08 - SSRF via Open Redirect
**Vulnerability:** SSRF via Open Redirect (CURLOPT_FOLLOWLOCATION)
**Learning:** Using `CURLOPT_FOLLOWLOCATION => true` in cURL allows the library to automatically follow redirects. If the initial URL is validated against an SSRF policy (e.g., blocking private IPs), a malicious server can redirect the request to a blocked IP (e.g., 127.0.0.1), and cURL will follow it without re-validating, bypassing the protection.
**Prevention:** Disable `CURLOPT_FOLLOWLOCATION`. Implement a manual redirect loop that inspects the `Location` header and validates the new URL against the SSRF policy before following it.

## 2026-05-24 - Timing Attack in Authentication
**Vulnerability:** User enumeration via timing attack in `AuthService::login`. The system returned early when a user was not found, skipping the computationally expensive `password_verify` (Argon2id) call.
**Learning:** Returning generic error messages is insufficient if the time taken to generate the error varies significantly. Attackers can measure the response time to determine if an email exists in the database.
**Prevention:** Ensure constant-time execution for authentication logic. Perform a dummy password verification against a pre-calculated hash when the user is not found, so the response time is indistinguishable from a failed password attempt for a valid user.
