# EMS Automated E2E Testing & Reviewer Guide Generation Plan

This document outlines the strategy for implementing automated end-to-end (E2E) testing for the Expedition Management System (EMS) and generating an illustrated Reviewer's Guide for human testers.

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

## 3. Automated Reviewer Guide Asset Generation

To generate an illustrated Reviewer's Guide without manually taking screenshots:

1.  **Dedicated Playwright Script**: Implement a script (`tests/e2e/generate-screenshots.ts`) that runs sequentially through all EMS workflows using stable mock data.
2.  **Screenshot Output Directory**: Save high-resolution PNG images to `docs/assets/screenshots/` (e.g., `settings-tab.png`, `expedition-board.png`, `parent-portal-mobile.png`).
3.  **Illustrated Reviewer's Guide**: Write `docs/reviewer-guide.md` which references these automatically generated assets to guide human testers step-by-step.

---

## 4. Implementation Steps

1.  **Setup Playwright**:
    *   Install `@playwright/test` and `playwright-bdd`.
    *   Create `playwright.config.ts` targeting local Docker environment (`http://localhost:8080`).
2.  **Develop Test Suite**:
    *   Write spec `.feature` files in `tests/features/e2e/` focusing on edge cases and odd data.
    *   Write TypeScript step definition files in `tests/e2e/steps/`.
3.  **Write the Reviewer's Guide**:
    *   Create `docs/reviewer-guide.md` linking to generated images.
