# EMS Automated E2E Testing & Reviewer Guide Generation Plan

This document outlines the strategy for implementing automated end-to-end (E2E) testing for the Expedition Management System (EMS) and generating an illustrated Reviewer's Guide for human testers.

---

## 1. Tool Selection: Playwright
We will use **Playwright** as the automated browser testing framework. Playwright provides:
*   **Multi-Browser Coverage**: Native execution in Chromium (Chrome/Edge), Firefox, and WebKit (Safari).
*   **Mobile Emulation**: Mobile browser viewport and user-agent emulation (e.g., iPhone Safari, Pixel Chrome).
*   **Rich Error Diagnostics**: Auto-generated HTML reports, screen capture video recordings, and full tracing logs on failure.
*   **Visual Asset Generation**: Ability to programmatically capture high-resolution screenshots during test runs to use directly in documentation.

---

## 2. Testing Scope & Scenarios

The E2E test suite will reside in `tests/e2e/` and cover:

### 2.1 WordPress Admin Console
*   **Authentication & Access Control**: Verify admin login redirects to dashboard, and non-admins are blocked from settings and the sync page.
*   **Settings Management**: Verify saving API Modes, Connection endpoints, Managed Sections, and Form Mappings.
*   **Portability & Backups**: Trigger the backup export JSON download and test uploading a restore file.
*   **Expedition Planning Board**: Validate drag-and-drop actions, loading state indicators, and previewing Online Scout Manager push-back differences.

### 2.2 Public Parent/Explorer Portal (`[ems-portal]`)
*   **OIDC Mock Login**: Simulate parent/explorer login via OIDC handler redirection.
*   **Desktop Portal View**: Verify checklist status rendering, read-only maps loading, and event detail expanders.
*   **Mobile Viewport Emulation**: Validate responsive CSS navigation tabs and mobile column styling under iPhone/Android screen dimensions.

---

## 3. Automated Reviewer Guide Asset Generation

To generate an illustrated Reviewer's Guide without manually taking screenshots:

1.  **Dedicated Playwright Script**: Implement a script (`tests/e2e/generate-screenshots.ts`) that runs sequentially through all EMS workflows using stable mock data.
2.  **Screenshot Output Directory**: Save high-resolution PNG images to `docs/assets/screenshots/` (e.g., `settings-tab.png`, `expedition-board.png`, `parent-portal-mobile.png`).
3.  **Illustrated Reviewer's Guide**: Write `docs/reviewer-guide.md` which references these automatically generated assets to guide human testers step-by-step.

---

## 4. Implementation Steps

1.  **Setup Playwright**:
    *   Install `@playwright/test` and dependency packages.
    *   Create `playwright.config.ts` targeting local Docker environment (`http://localhost:8080`).
2.  **Develop Test Suite & Seeder**:
    *   Write spec files under `tests/e2e/`.
    *   Write the screenshot generator script.
3.  **Write the Reviewer's Guide**:
    *   Create `docs/reviewer-guide.md` linking to generated images.
