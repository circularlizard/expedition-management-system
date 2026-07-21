---
name: ui-implementation-auditor
description: Audits the Expedition Management Utility (EMU) codebase for front-end implementation consistency, CSS architecture, inline style violations, component reusability, and asset enqueuing standards.
---

# UI Implementation Consistency Auditor Skill

When invoked, the agent acts as a **Lead Front-End Architect**. It scans the project repository source files (`includes/`, `assets/`, `templates/`, `src/`) to ensure the front-end code adheres to clean code standards, WordPress enqueuing best practices, and standard CSS architecture.

---

## 1. CSS & Style Architecture Rules

* **Zero Inline Styles:** Flag any hardcoded `style="..."` attributes inside PHP templates, JS/JSX components, or HTML outputs (unless dynamically calculated coordinates/transforms for canvas/drag-and-drop elements).
* **Centralized Stylesheet:** Verify all custom styling is organized in dedicated CSS/SASS/LESS files within `assets/css/` or `src/styles/`.
* **Design Token Usage:** Flag hardcoded hex colors (e.g. `#005A9C`), fixed pixel margins, or arbitrary font sizes in template files. Check that elements utilize CSS variables / utility classes defined in the primary stylesheet.
* **WordPress CSS Scoping:** Ensure classes are prefixed (e.g., `.emu-card`, `.emu-team-grid`) to avoid styling leaks into standard WP-Admin pages or parent themes.

---

## 2. Component Reusability & DRY Principles

* **Duplicate UI Logic:** Identify copy-pasted HTML blocks (e.g., repeated explorer status badges or modal structures across different template files) that should be refactored into reusable template components or UI helper functions.
* **Standard Controls:** Flag raw `<button>` or `<select>` tags that do not use standard WordPress UI classes (e.g., `button-primary`, `button-secondary`) or custom EMU component primitives.
* **Component Encapsulation:** Verify JS components (e.g., React, Vue, or vanilla JS modules) keep markup, logic, and state clean without embedding raw HTML strings directly into AJAX handlers.

---

## 3. WordPress Asset Enqueuing & Performance

* **Proper Script Enqueuing:** Ensure styles and scripts are loaded using `wp_enqueue_script()` and `wp_enqueue_style()` hooked to proper actions (`admin_enqueue_scripts`, `wp_enqueue_scripts`), rather than hardcoded `<link>` or `<script>` tags in templates.
* **Asset Optimization:** Flag unminified vendor scripts or unused CSS libraries bundled in production builds.

---

## 4. Execution & Reporting Requirements

When performing an audit run, the agent must:

1. **Scan Source Code:** Search through PHP templates, JS files, and CSS assets using grep/file pattern matching.
2. **Locate Violations:** Record exact file paths and line numbers for inline styles, hardcoded values, and non-standard markup.
3. **Generate Implementation Report:** Write a structured Markdown report to `docs/ui-implementation-audit-report.md` containing:
   * **Summary Score:** Overall health score for implementation consistency.
   * **Inline Style Violations:** Table listing file paths, line numbers, and proposed CSS class refactors.
   * **Refactoring Opportunities:** Recommended UI components to consolidate duplicated markup across templates.