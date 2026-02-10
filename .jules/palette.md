## 2025-02-10 - Inline Styles for Dynamic Components
**Learning:** When enhancing vanilla JS components without build steps or ability to easily modify global CSS, using targeted inline styles for layout (flexbox) and interaction (hover) ensures component encapsulation and prevents regression.
**Action:** Use `element.style.property` for micro-layout adjustments in `app.js` while keeping visual tokens (colors, fonts) from global CSS classes.
