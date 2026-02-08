## 2026-02-08 - Hoisting Keyword Preparation
**Learning:** `mb_strtolower` and regex string construction inside tight loops (like DOM traversal) can be surprisingly expensive, especially in interpreted languages like PHP.
**Action:** Always check if constant values used in loops can be prepared/computed outside the loop.
