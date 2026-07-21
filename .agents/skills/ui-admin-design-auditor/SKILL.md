---
name: wp-admin-ui-auditor
description: Audits all WordPress admin React plugin pages, tabs, detail views, and inspector panels for visual consistency, typography, color contrast, card/table layouts, action bars, and component patterns using BrowserMCP.
---

# WordPress Admin UI Consistency Auditor Skill

When invoked, the agent acts as a **Lead UI Systems Engineer** and **Design Auditor**. It uses Antigravity's built-in browser tools (`BrowserMCP`) to log into local WordPress (e.g., `https://localhost`), interactively navigate across **all pages, tabs, detail screens, and inspector panels** of the Expedition Management System, capture visual states, and audit the application for strict visual and structural consistency.

---

## 1. Setup & Session Authentication

Before starting the visual audit loop, the agent MUST establish an active admin session:

1. **Target Identification:** Identify local WordPress login URL (default `https://localhost/wp-admin` or read from prompt/environment).
2. **Session Verification:** Navigate to the admin dashboard using `BrowserMCP`. If unauthenticated, pause or utilize saved session state/cookies to establish an active session.
3. **Comprehensive Scope Mapping:** Plan browser traversal across every route, tab, panel, and sub-view:
   * **Core Pages:** `Expeditions`, `Explorers`, `Participant Places`, `Expedition Signups`, `Volunteers`, `OSM Sync`, `Settings`.
   * **Sub-Surfaces:** All internal page tabs, sliding Inspector Panels/Sidebars, top/bottom Action Bars, and Detail/Edit pages.

---

## 2. Granular UI Component & Token Baseline

Evaluations must adhere to a strict, high-density dashboard standard (Shadcn/Tailwind or custom design system defined in `DESIGN_RULES.md`). Avoid generic "AI defaults" (e.g., oversized rounded corners, excessive drop shadows, or floating cards).

### A. Spacing Grid & Layout Density
* **Base Grid ($8\text{px}$ Scale):** Margins, paddings, gaps, and component heights must strictly adhere to the $8\text{px}$ grid (`4px`, `8px`, `16px`, `24px`, `32px`). Flag any arbitrary inline values (e.g., `margin-top: 13px`).
* **Container White Space:** Cards, table cells, inspector panels, and modal containers must maintain balanced whitespace without cramped text or excessive dead space.

### B. Typography & Font Sizes
* **Modular Type Scale:**
  * **H1 (Page Headers):** `22px` / Bold / Line Height `1.2`
  * **H2 (Section/Panel Headers):** `16px` / Semi-bold / Line Height `1.3`
  * **H3 (Card & Table Headers):** `14px` / Medium / Line Height `1.4`
  * **Body & Table Text:** `13px` / Regular / Line Height `1.5`
  * **Captions & Meta Badges:** `12px` / Regular / Line Height `1.4`
* **Hierarchy Consistency:** Headers, body copy, and metadata must use identical font weights and size scales across all views and detail pages.

### C. Color & Accessibility Contrast (WCAG AA)
* **Contrast Ratios:** Text-to-background contrast must be $\ge 4.5:1$ for normal body text and $\ge 3:1$ for large headings and interactive badges.
* **Semantic Colors:** Primary actions, borders (`#E5E7EB`), neutral backgrounds (`#FAFAFA`), and status badges (*Pending*, *Approved*, *Blocked*) must be uniform. Color must never be the sole status indicator; badges must include explicit textual labels.

### D. Buttons & Action Bars
* **Button Hierarchy:** Variants (`default`, `secondary`, `outline`, `destructive`) must be used consistently for identical intent. Standardize desktop button heights (`h-8` compact or `h-9` standard).
* **Action Bars:** Page action bars, batch table toolbars, and inspector panel action footers must align buttons predictably (Primary CTA top-right or bottom-right; Secondary/Cancel actions left-aligned).

### E. Cards & Containers
* **Visual Geometry:** Cards must use flat, crisp borders (`border border-border`) with subtle or zero shadows (`shadow-sm` / `shadow-none`).
* **Border Radii:** Enforce `--radius: 0.375rem` (`6px`) uniformly across all cards, modals, and inspector panels. Do not mix rounded corners (e.g., combining `2px`, `8px`, and `16px`).

### F. Dropdowns & Select Controls
* **Consistency:** Select dropdowns, comboboxes, and filter menus must share identical styling, focus rings, hover states, and compact heights (`h-8` / `h-9`) across all filter toolbars and form panels.

### G. Data Tables & Table Sorting
* **Cell Density:** Data tables across all pages must enforce uniform, compact padding (`py-2 px-3`).
* **Interactive Sorting Indicators:** Sortable column headers MUST display clear visual sort indicators (e.g., active/inactive sort arrows). Active sorted columns must highlight visually without breaking text alignment.
* **Row Hover & Actions:** Hover states on rows must be subtle, with end-aligned action columns (e.g., quick edit, view details, delete) positioned consistently.
* **Empty States:** Tables with no data must display a standardized card template with an explicit call-to-action button rather than a blank table frame.

---

## 3. Systematic Traversal Checklist

The agent MUST use `BrowserMCP` to systematically navigate and evaluate **every surface** listed below:

- [ ] **1. Expeditions Page** (Main Table, Sort Controls, Tabs, Filter Bars, Detail/Edit Page, Inspector Panel)
- [ ] **2. Explorers Page** (Main Table, Sort Controls, Tabs, Search Controls, Explorer Detail View, Inspector Panel)
- [ ] **3. Participant Places Page** (Capacity Grids, Placement Tables, Filter Bars, Detail View)
- [ ] **4. Expedition Signups Page** (Signup Queue, Status Badges, Batch Action Toolbar, Detail View)
- [ ] **5. Volunteers Page** (Volunteer Roster, Role Badges, Inspector Panel, Detail/Edit View)
- [ ] **6. OSM Sync Page** (Sync Status Cards, Logs Table, Manual Sync Action Bar, Settings Tab)
- [ ] **7. Settings Pages & All Sub-Tabs** (General, Integration, Email Templates, User Permissions, Save Action Bars)

---

## 4. Browser MCP Audit Loop Procedure

For **EACH** page, tab, detail view, and inspector panel identified above, the agent MUST execute the following loop using `BrowserMCP`:

1. **Navigate & Open Surface:** Direct `BrowserMCP` to the target route or trigger clicks to open tabs, detail pages, and inspector panels.
2. **Capture Desktop Snapshot:** Take a full-page screenshot at $1280 \times 800\text{px}$ desktop resolution and save to `tests/ui-audit/screenshots/{surface-name}-desktop.png`.
3. **Inspect DOM & CSS Attributes:** Evaluate spacing grid ($8\text{px}$ scale), computed font sizes, contrast ratios, table sort icon states, card radii, and button alignment.
4. **Capture Mobile/Compact Snapshot:** Resize viewport to $375 \times 812\text{px}$ to inspect table horizontal scrolling, responsive inspector panels, and action bar wrapping.
5. **Log Inconsistencies:** Document any broken alignment, non-standard drop-downs, improper table sorting cues, or arbitrary styling.

---

## 5. Execution Output & Audit Report

Upon completing the full traversal, generate a structured Markdown audit report saved to `docs/ui-consistency-audit-report.md` containing:

1. **Executive Summary:** Overall assessment of visual consistency, pattern reuse, WCAG contrast compliance, and data density across the entire plugin.
2. **Granular Surface Matrix:**
   | Page / Surface / Tab | Spacing & Grid | Typography & Contrast | Cards & Tables | Buttons & Dropdowns | Table Sorting | Status |
   | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
   | `Expeditions Table` | Pass | Pass | Fail (Mixed Padding) | Pass | Pass | Needs Refactor |
   | `Explorer Detail Panel` | Fail (Non-8px) | Pass | Pass | Fail (Mixed Heights) | N/A | Needs Refactor |
   | `OSM Sync Settings` | Pass | Fail (Low Contrast) | Pass | Pass | N/A | Needs Refactor |
3. **Itemized Component Findings:**
   * **Spacing & Font Discrepancies:** Specific lines/components where margins, font sizes, or line heights diverge.
   * **Color Contrast Violations:** Exact text/badge elements failing WCAG AA $\ge 4.5:1$ contrast ratios.
   * **Card, Button & Dropdown Anti-Patterns:** Mixed border radii, inconsistent button heights, or non-uniform select boxes.
   * **Table & Sorting Blemishes:** Missing sort indicators, inconsistent table cell paddings, or misaligned action columns.
4. **Actionable Refactoring Plan:** Prioritized, step-by-step React component refactoring tasks formatted directly for `agy` execution in subsequent goals.