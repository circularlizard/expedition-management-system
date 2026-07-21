---
name: ui-auditor
description: Audits WordPress admin and public portal interfaces for visual UI design and structural UX workflow efficiency by dynamically reading workflow specs from project documentation.
---

# UI & UX Design Auditor Skill

When invoked, the agent acts as an expert **UX Architect** and **UI Designer**. It executes browser automation on local target URLs (e.g., `http://localhost:8080`), dynamically reads workflow specifications from project documentation, captures visual state screenshots, and evaluates the interface across two distinct layers: **Macro Structural UX** and **Micro Visual UI**.

---

## 1. Flow & Spec Discovery

Before executing browser interaction or evaluations, the agent MUST establish ground truth for the target user journey:

1. **Locate Workflow Specs:** Read the user flow definition from the specified path provided in the user prompt, or scan `docs/workflows/*.md` (e.g., `team-formation.md`, `readiness-tracking.md`, `parent-portal.md`).
2. **Extract Expected Journey:** Identify the target user role (e.g., Admin, Volunteer Lead, Parent), key user goal, expected inputs, and sequence of steps outlined in the workflow document.
3. **Map Browser Traversal:** Plan the browser interactions needed to navigate through each step in the documented workflow.

---

## 2. Macro Structural UX Rules (Workflow Efficiency)

Evaluate the executed browser sequence against the discovered workflow spec and these usability heuristics:

* **Context & Information Availability:** All decision-making data required at a given step (e.g., participant availability, requested teammates, capacity) must be visible *on screen* without forcing multi-tab navigation or context switching.
* **Low Click-Friction:** Tasks must be completable with minimal clicks/transitions (e.g., inline controls, modal selectors, or drag-and-drop). Flag flows that require navigating into separate sub-pages for individual items when batch actions are appropriate.
* **Immediate Validation:** Performing an action with a constraint violation (e.g., assigning an explorer to an unavailable weekend) must trigger immediate, non-blocking visual feedback (e.g., a warning badge).
* **Management by Exception:** Overview/dashboard views must prioritize incomplete requirements, pending route submissions, or missing consent over fully satisfied items.
* **Role Scoping:** Non-admin portals (e.g., Parent/Explorer views) must present a clean, read-only projection of admin data without exposing WP-Admin navigation or administrative controls.

---

## 3. Micro Visual UI Rules (Tactical & Aesthetics)

Evaluate rendered DOM elements and CSS properties against these explicit visual criteria:

### Typography & Hierarchy
* **Scale:** Heading tags (`H1`, `H2`, `H3`) must follow a strict, linear font scale.
* **Readability:** Body text must maintain a line-height between $1.4$ and $1.6$.

### Color & Accessibility (WCAG AA)
* **Contrast:** Text-to-background contrast ratio must satisfy $\ge 4.5:1$ for standard text and $\ge 3:1$ for large headings.
* **Status Indicators:** Color must *never* be the sole indicator of state. Badges/alerts must include distinct textual labels alongside color cues.

### Spacing & Layout
* **Design System Tokens:** Padding and margins must adhere to $8\text{px}$ grid increments ($8\text{px} / 16\text{px} / 24\text{px} / 32\text{px}$).
* **Container Whitespace:** Tables, cards, and admin meta-boxes must maintain balanced internal whitespace without cramped text.

### Interactive Elements & Responsiveness
* **Touch Targets:** Buttons, form inputs, and clickable icons must measure at least $44 \times 44\text{px}$.
* **Viewport Adaptability:** Layouts must wrap cleanly at mobile sizes ($375\text{px}$) with zero horizontal page scrolling or clipped text.

---

## 4. Execution & Reporting Requirements

When executing an audit run, the agent must:

1. **Capture Visual Artifacts:** Save screenshots of each step in the workflow across both Desktop and Mobile viewports into `tests/ui-audit/screenshots/`.
2. **Quantify Workflow Friction:** Calculate the total clicks, screen transitions, and cognitive friction points required to complete the workflow.
3. **Generate Audit Report:** Write a structured Markdown report to `docs/ui-design-audit-report.md` containing:
   * **Target Workflow:** Link/reference to the specific `docs/workflows/*.md` document evaluated.
   * **Executive Summary:** Overall assessment of workflow usability and design compliance.
   * **Structural UX Findings:** Analysis of click depth, context availability, and friction points encountered during the journey.
   * **Visual UI Audit Table:** Itemized pass/fail status for typography, contrast, spacing, touch targets, and mobile responsiveness.
   * **Actionable Recommendations:** Ranked list of recommended layout, navigation, or visual adjustments.