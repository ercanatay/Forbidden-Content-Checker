## 2026-02-08 - Accessible Label Creation Pattern
**Learning:** The custom `create` DOM helper was missing attribute support, causing labels to be unassociated.
**Action:** Use updated `create(tag, class, text, attrs)` helper with `{ for: 'id' }` for all future form labels.
