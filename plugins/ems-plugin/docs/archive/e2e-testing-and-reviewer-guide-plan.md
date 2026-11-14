# EMS Automated E2E Testing & Reviewer Guide Generation Plan

This document outlines the strategy for implementing automated end-to-end (E2E) testing for the Expedition Management System (EMS), executing an automated UI design audit, and generating an illustrated Reviewer's Guide for human testers.

---

## 1. Tool Selection: Playwright + `playwright-bdd`
To align with the codebase's strict Gherkin requirement, we will use **Playwright** paired with **`playwright-bdd`**.
*   **Direct Execution**: Playwright will compile and run `.feature` files directly via TypeScript step definitions.
*   **Multi-Browser Coverage**: Native execution in Chromium (Chrome/Edge), Firefox, and WebKit (Safari).
*   **Mobile Emulation**: Mobile browser viewport and user-agent emulation (e.g., iPhone Safari, Pixel Chrome).
*   **Visual Asset Generation**: Capture high-resolution screenshots programmatically during test execution to build documentation.

---

## 2. Testing Scope: Edge Cases & Odd Data

Rather than focusing on happy paths or low-probability backup failures, the E2E Gherkin suite will focus heavily on access control, UI sync state boundaries, and how the system behaves under odd or malformed data constraints.

### 2.1 Access Boundaries (Auth Edge Cases)
*   **Unauthorized Admin Access**: Attempting to view the settings page or sync preview boards as a parent (`ems_parent` role) or explorer (`ems_explorer` role). Verify they are rejected or redirected.
*   **Expired OSM OAuth Token**: Simulating a cached OSM token that has expired or lacks write scopes (`section:event:write`, `section:flexirecord:write`). Verify the admin is prompted to re-authorize.

### 2.2 Odd Data & Formatting Robustness
*   **Special Characters in Names**: Syncing explorers whose names contain apostrophes, hyphens, non-ASCII letters (e.g., `O'Connor`, `François-Marie`), or leading/trailing whitespace. Verify the preview table renders them correctly and API write payloads escape them safely.
*   **Malformed or Missing Event Codes**: Syncing events where `ems_event_code` is missing, blank, or formatted with unusual characters. Verify the accepted column value formatter handles them gracefully without failing.
*   **Weird or Missing Event Dates**: Verifying the sync formatter `"{Event Code} {Date}"` handles null, empty, or invalid date values gracefully (e.g. avoiding rendering as `"H-SP1 undefined"`).
*   **Partial or Unexpected OSM Payloads**: Simulating responses from Online Scout Manager where expected fields like `email` or `dob` are missing or null. Verify the preview page doesn't crash and displays appropriate placeholders (e.g., `—`).

### 2.3 Sync Pushback & Conflict Edge Cases
*   **OSM Rate Limit Lockout**: Accessing the Push-back Sync preview while `ems_rate_limit_status` is active. Verify the dashboard displays the lockout notice and disables sync triggers.
*   **OSM API Blocked state**: Simulating the block option state. Verify correct status notice shows.
*   **Overwrite Alerts**: Loading the sync preview when a proposed EMS update conflicts with an existing, non-empty value in Online Scout Manager. Verify the "Overwrite" danger warning badge displays for that row.
*   **Zero proposed updates**: Loading a preview when EMS and OSM are completely in sync. Verify the sync action button is deactivated and displays `(0 changes)`.

### 2.4 Public Portal Responsive Layouts
*   **Viewport constraints**: Emulating small viewports (e.g. mobile Safari). Verify tabs wrap cleanly and no parent theme CSS breaks font scale or layout columns.

---

## 3. Mock API Layer Completeness

To achieve deterministic test runs and completely avoid external rate limits, API cooldown blocks, or unintended edits to production Online Scout Manager data, **all E2E tests will run in `mock` API mode**.

### 3.1 Verifying the Mock API Driver
*   We must audit the `Mock_Driver` class (used when `ems_api_mode === 'mock'`) to verify it implements all API write-back responses.
*   Ensure mock endpoints (e.g., writing flexi-record updates, dispatching event invitations) simulate realistic API success payloads or raise realistic exception types (e.g. Rate Limit timeouts) when requested by test parameters.

### 3.2 Injecting Odd Data into Mock Payloads
*   We will modify the mock data fixtures (e.g. inside `tests/mocks/` or dynamically generated mock states) to include:
    *   Explorer rows containing name edge cases (`François-Marie`, `O'Connor`).
    *   Event payloads with blank dates, missing event codes, or empty location coordinates.
    *   Null fields for contacts to verify frontend layout stability.

---

## 4. Automated UI Design Audit (Custom AI Auditor)

To ensure all screens (WordPress admin dashboard and the public website frontend portal) meet premium design aesthetics, we will implement an automated UI audit using a custom **Multimodal UI Design Auditor Subagent**.

### 4.1 Stage 1: Define Design Audit Rules
Before starting the audit, we must document the exact rules the agent should follow. These will be stored in `.agents/rules/ui-design-rules.md`:
*   **Typography Hierarchy**: Font family choices, readable scaling, and line heights.
*   **Color Palette Limits**: Verification that elements only use approved EMS branding colors (no generic browser-default reds or blues).
*   **Spacing and Grid alignment**: Elements must align cleanly on a standard grid (e.g. consistent paddings, uniform margins, centered flex containers).
*   **Interactive Contrast**: Buttons, hover states, and focus outlines must satisfy readability and accessibility guidelines.
*   **Responsive Adaptation**: Mobile layouts must wrap text and stack grid columns without causing horizontal page overflow or clipping button texts.

### 4.2 Stage 2: Capture Screenshots
Playwright will execute the test suites and capture screenshots of all settings, dashboard views, portal layouts, and mobile viewport views, outputting them to a structured directory: `tests/ui-audit/screenshots/`.

### 4.3 Stage 3: Agent Auditing & Reporting
We will define and invoke a custom multimodal subagent (`UI_Design_Auditor`) that reads the screenshots, evaluates them against the defined UI rules, and generates a structured audit report (`docs/ui-design-audit-report.md`) detailing alignment issues, responsive layout breaks, or styling suggestions.

---

## 5. Automated Reviewer Guide Asset Generation

To generate an illustrated Reviewer's Guide without manually taking screenshots:

1.  **Dedicated Playwright Script**: Implement a script (`tests/e2e/generate-screenshots.ts`) that runs sequentially through all EMS workflows using stable mock data.
2.  **Screenshot Output Directory**: Save high-resolution PNG images to `docs/assets/screenshots/` (e.g., `settings-tab.png`, `expedition-board.png`, `parent-portal-mobile.png`).
3.  **Illustrated Reviewer's Guide**: Write `docs/reviewer-guide.md` which references these automatically generated assets to guide human testers step-by-step.

---

## 6. Implementation Steps

1.  **Setup Playwright**:
    *   Install `@playwright/test` and `playwright-bdd`.
    *   Create `playwright.config.ts` targeting local Docker environment (`http://localhost:8080`).
2.  **Align the Mock API Layer**:
    *   Extend `Mock_Driver` to ensure complete coverage for all new write operations.
    *   Seed the static mock JSON files with odd name and format cases.
3.  **Define UI Design Audit Rules**:
    *   Write the design checklist rules to `.agents/rules/ui-design-rules.md`.
4.  **Develop Test Suite & Capturing**:
    *   Write spec `.feature` files in `tests/features/e2e/` focusing on edge cases and odd data.
    *   Write TypeScript step definition files in `tests/e2e/steps/` capturing E2E screenshots.
5.  **Run UI Audit Subagent**:
    *   Invoke the custom `UI_Design_Auditor` subagent on the captured image directory to output the audit report.
6.  **Write the Reviewer's Guide**:
    *   Create `docs/reviewer-guide.md` linking to generated images.
