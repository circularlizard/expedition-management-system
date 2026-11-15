---
name: ui-auditor
description: Audits WordPress admin and public portal interfaces for visual UI design and structural UX workflow efficiency. Dynamically reads user workflow files, uses Antigravity's built-in browser to traverse the plugin, and evaluates screens against visual taste profiles and objective heuristics.
---

# UI & UX Design Auditor Skill

When invoked, the agent acts as a **Principal UX Architect** and **UI Systems Designer**. It dynamically reads specified workflow files, uses Antigravity's built-in browser to log into local WordPress and navigate the plugin interface (e.g., `http://localhost:8888`), captures visual states, and evaluates the interface across two distinct layers: **Macro Structural UX** and **Micro Visual UI**.

---

## 1. Dynamic Workflow Ingestion & Setup

Before launching the browser navigation sequence, the agent MUST ingest the required workflow context:

1. **Read Workflow Input File:** Locate and read the target workflow file provided in the user prompt (e.g., `docs/workflows/team-formation.md`) or scan the `docs/ui-review/` directory.
2. **Extract Key Journey Steps:** Parse the document to extract:
   * **Target User Role:** (e.g., Admin, Volunteer Lead, Parent/Explorer)
   * **Step-by-Step Sequence:** The exact sequence of screens, clicks, inputs, and form submissions required to complete the task.
   * **Success Criteria:** The expected final state or confirmation message.
3. **Identify Target URLs:** Map the workflow steps to specific WP-Admin sub-pages (e.g., `wp-admin/admin.php?page=expeditions`) or shortcode pages.

---

## 2. Taste Profile & Visual Direction (Interactive Challenge)

> **CRITICAL RULE:** The agent MUST NOT assume the user's preferred visual aesthetic, color palette, or visual density. Design critiques must never depend on memory or vague "vibes."

If no `DESIGN_RULES.md` or visual reference folder (`docs/ui-review/`) is supplied in the prompt context, **stop and challenge the user to select or define a Taste Profile** before executing browser audits.

### Presets Available (User Choice Required):
* **Preset A: High-Density Utility (Linear / Vercel style)**
  * Compact padding ($6\text{px}\text{--}10\text{px}$ table cells), dark/light muted borders (`#E5E7EB`), high contrast, subtle focus rings, strict mono/sans typography hierarchy. Minimal card elevation.
* **Preset B: Modern B2B SaaS (Stripe / Tailwind UI style)**
  * Spacious padding ($16\text{px}\text{--}24\text{px}$ containers), soft card drop-shadows, prominent primary CTA buttons, rounded corners (`8px` radius), warm background neutrals (`#F9FAFB`).
* **Preset C: WordPress Native Blend (`@wordpress/components`)**
  * Seamless integration with core WP-Admin CSS variables (`--wp-admin-theme-color`), standard WP buttons, classic meta-box layout grids, flat borders.

*Or ask the user to provide 2–3 reference images in `docs/ui-review/` to act as visual ground truth.*

---

## 3. Built-In Browser Execution Strategy

The agent MUST use Antigravity's built-in browser tools (`BrowserMCP`) to dynamically interact with the live WordPress site:

1. **Session & Auth Check:** 
   * Navigate to the local WP-Admin login or dashboard URL.
   * If unauthenticated, pause or use saved session cookies to establish an active admin session.
2. **Step-by-Step Workflow Traversal:**
   * Execute the step-by-step journey defined in the ingested workflow file using native click, type, and navigate browser tools.
   * At *each* step in the workflow, capture full-page screenshots (Desktop at $1280\text{px}$, Mobile at $375\text{px}$) and inspect the rendered DOM state.
3. **Data Mutation Safety:**
   * Perform actions on local development data only. If destructive actions are required, warn the user before proceeding.

---

## 4. Macro Structural UX Evaluation (Workflow Efficiency)

While traversing the workflow via the browser, evaluate the interface against these structural heuristics:

* **Context & Information Availability:** Is all decision-making data required at a given step (e.g., participant availability, requested teammates) visible *on screen* without forcing multi-tab navigation or context switching?
* **Low Click-Friction:** Can tasks be completed with minimal transitions (e.g., inline controls, modals, or drag-and-drop)? Flag flows that force navigating into separate sub-pages for individual items when batch operations are appropriate.
* **Immediate Validation:** Does performing an action with a constraint violation (e.g., date conflicts) trigger immediate visual feedback (e.g., a warning badge)?
* **Management by Exception:** Do dashboard and overview screens prioritize incomplete requirements, pending route approvals, or missing consent over fully satisfied items?
* **Role Scoping:** Does the embedded shortcode/front-end portal present a clean, read-only projection without exposing WP-Admin navigation or controls?

---

## 5. Micro Visual UI Evaluation (Tactical & Aesthetics)

Evaluate rendered DOM elements, captured screenshots, and computed CSS properties against the chosen **Taste Profile** and these visual checks:

### Objective Visual Anti-Patterns (Fail Immediately)
* **Visual Noise:** Mixed border-radius values (e.g., combining `2px`, `8px`, and `16px` on the same screen).
* **Typography Disconnect:** More than 2 distinct font families or arbitrary font sizes outside a modular scale.
* **Orphan Elements:** Floating action buttons or misaligned form labels that break the layout grid.
* **Unscoped Styles:** Embedded shortcode components leaking CSS into host WordPress themes or inheriting unwanted theme styles.

### Systemic Quality Checks
* **Typography & Hierarchy:** Heading tags (`H1`, `H2`, `H3`) follow a strict font scale. Body text line-height is $1.4\text{--}1.6$.
* **Color & Accessibility (WCAG AA):** Text contrast satisfies $\ge 4.5:1$ (or $\ge 3:1$ for large headings). Color is never the sole state indicator; badges must include explicit textual labels.
* **Spacing & Layout Grid:** Padding and margins strictly adhere to $8\text{px}$ base grid increments ($8\text{px} / 16\text{px} / 24\text{px} / 32\text{px}$). Tables and cards maintain balanced whitespace.
* **Interactive Elements & Responsiveness:** Buttons and form inputs maintain minimum target dimensions ($\ge 44 \times 44\text{px}$ touch, $\ge 32\times 32\text{px}$ desktop). Layouts wrap cleanly at $375\text{px}$ width with zero horizontal scrolling.

---

## 6. Execution Output & Reporting Requirements

After completing the browser traversal, the agent must generate the following artifacts:

1. **Save Visual Artifacts:** Save captured screenshots into `tests/ui-audit/screenshots/` (labeled by step name and viewport size).
2. **Visual Comparison:** If reference images exist in `docs/ui-references/`, compare current screenshots against references and note layout discrepancies.
3. **Quantify Workflow Metrics:** Record total clicks, screen transitions, and cognitive friction points encountered during the workflow run.
4. **Generate Audit Report:** Write a structured Markdown report to `docs/ui-design-audit-report.md` containing:
   * **Evaluated Workflow & Taste Profile:** Reference to the input workflow file used and the active visual profile baseline.
   * **Executive Summary:** Overall usability score and visual design consistency score.
   * **Structural UX Findings:** Step-by-step breakdown of click depth, context availability, and friction points.
   * **Visual UI Audit Table:** Pass/fail breakdown for typography, contrast, spacing tokens, touch targets, and mobile responsiveness.
   * **Actionable Refactoring Plan:** Step-by-step component editing instructions ready for direct execution in `agy`.