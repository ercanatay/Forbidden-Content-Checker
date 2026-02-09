# Sentinel's Journal

## 2026-02-08 - SSRF via Open Redirect
**Vulnerability:** SSRF via Open Redirect (CURLOPT_FOLLOWLOCATION)
**Learning:** Using `CURLOPT_FOLLOWLOCATION => true` in cURL allows the library to automatically follow redirects. If the initial URL is validated against an SSRF policy (e.g., blocking private IPs), a malicious server can redirect the request to a blocked IP (e.g., 127.0.0.1), and cURL will follow it without re-validating, bypassing the protection.
**Prevention:** Disable `CURLOPT_FOLLOWLOCATION`. Implement a manual redirect loop that inspects the `Location` header and validates the new URL against the SSRF policy before following it.

## 2024-05-23 - User Enumeration via Timing Attack
**Vulnerability:** Found a ~300ms timing difference between "User Not Found" and "Invalid Password" login responses, allowing attackers to enumerate valid email addresses.
**Learning:** `password_verify` (Argon2id) is computationally expensive. Skipping it when a user is not found reveals the absence of the user.
**Prevention:** Implement a dummy hash verification path for non-existent users, ensuring the same cryptographic work is performed regardless of user existence.
