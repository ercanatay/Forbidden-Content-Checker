# Bolt's Journal

## 2026-02-08 - Repeated DB queries in hot scan paths
**Learning:** `SuppressionService.isSuppressed()` and `ScanService.isDomainAllowed()` both hit the DB on every invocation despite returning stable data within a scan. These are called in tight loops (per-match and per-target respectively), making them classic "repeated query" bottlenecks.
**Action:** Look for service methods called in loops that query static-during-operation data. Cache at instance level with a `clearCache()` escape hatch.
