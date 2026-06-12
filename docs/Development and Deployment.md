# Development and Deployment Strategy: EMS

This document outlines the environment, testing, and incremental rollout plan for the Expedition Management System.

## 1. Development Environment
### 1.1 Local Development (Docker)
To ensure parity with the test server, we will use a Docker-based local environment:
- **Image**: `wordpress:php8.2-apache` (or similar SiteGround-aligned image).
- **Database**: `mariadb:latest`.
- **Tools**: WP-CLI, Composer, Node/NPM.

### 1.2 Testing Infrastructure
In alignment with **[ADR 007 (TDD Mandate)](./Technical Architecture.md#adr-007-test-driven-development-tdd-mandate)**:
- **PHP Testing**: `phpunit/phpunit` and `weaseur/brain-monkey` installed via Composer.
- **JS Testing**: `vitest` and `@testing-library/react` installed via NPM.
- **E2E Testing**: `playwright` for cross-browser validation on the staging environment.

### 1.3 Test Server
- **Environment**: A staging/test subdomain on SiteGround.
- **CI/CD**: See §3 for the full pipeline specification.

## 2. OSM Integration Strategy
### 2.1 Authentication (OIDC) & Hydration
- **Base Plugin**: [login-with-google](https://github.com/circularlizard/login-with-google) (configured for OSM OIDC).
- **Identity Step**: Standard OIDC handshake performed by the base plugin to match/create the WP User.
- **Context Step (EMS Hook)**: EMS hooks into `rtcamp.google_user_logged_in`.
    - Captured Access Token is used to perform a secondary `getDataPayload` (Startup API) fetch.
    - Resulting context (Scout IDs, child mapping, `access_type`) is persisted to WP User Meta.
    - The user's `access_token` is used solely for this hydration step and is **discarded immediately after**. No per-user token is stored server-side.
- **Shell Account Merge**: EMS also hooks into `rtcamp.google_user_logged_in` to detect if a shell account exists for the newly logged-in child (matched by `ems_scout_id`). If found, EMS performs a merge of User Meta before the session is established. See [PRD §4.6](./Expedition Management System.md#46-parent-child-relationship).
- **Service Account**: All subsequent EMS-to-OSM write operations (flexi-records, event status) use the dedicated EMS service account tokens stored encrypted in WP Options. See [ADR 010](./Technical Architecture.md#adr-010-osm-service-account-for-push-back-operations).

### 2.2 OSM Push-back Failure Handling
- **Problem**: Push-back operations (flexi-record updates, event status changes) may fail if OSM is temporarily unavailable.
- **Solution**: On failure, the job is persisted to a WP Option (`ems_failed_pushback_queue`) as a serialized entry and an **admin notice** is surfaced in the EMS dashboard identifying the failed operation with a **"Retry" button**. No external queue library is required.
- **Rationale**: Push-backs are low-volume, admin-triggered operations. A manual retry with clear visibility is appropriate for this scale and avoids introducing a WooCommerce-namespaced dependency (WP Action Scheduler) for a lightweight problem.
- **Escalation path**: If retry frequency becomes a real operational problem in production, the queue can be upgraded to use native WP Cron (`wp_schedule_single_event()`) with the same WP Option store — no architectural change required.
- **TDD Task**: Write tests for failure persistence (job written to option on HTTP error), notice rendering, and retry dispatch.

### 2.3 Rate Limiting & Performance
OSM has strict rate limits. Our integration must include:
- **Throttling**: A central `OSM_API_Client` class that implements a "Token Bucket" or simple delay logic to ensure we never exceed the allowed requests per minute. **This is implemented in Phase 1** — OSM aggressively blocks accounts and IP addresses that breach rate limits, so throttling must be in place before any `Live_Driver` request is ever made.
- **Caching**: Aggressive use of WordPress Transients to cache OSM data (e.g., Section lists, Event details) for 1–12 hours.
- **Batching**: Where the API allows, fetch data in batches rather than individual requests per user.

### 2.4 Mock Data Layer (Test Mode)
- **Implementation**: The `OSM_API_Client` will use a "Driver" pattern.
- **Drivers**:
    - `Live_Driver`: Makes real HTTP requests to OSM.
    - `Mock_Driver`: Returns static JSON payloads (stored in `tests/mocks/`) for all data requests.
- **Switching**: Controlled via a WP Option or `EMS_TEST_MODE` constant in `wp-config.php`.

## 3. CI/CD Pipeline

- **Local Development**: Docker Compose environment (see §1.1). All development and initial testing occurs locally.
- **Automated Checks (GitHub Actions)**: A GitHub Actions workflow runs on every push to `main` and all pull requests:
    1. PHP lint (`php -l`)
    2. PHPUnit test suite
    3. Vitest test suite
- **Deployment to Staging**: Manual. Once all automated checks pass on a branch, the developer deploys to the SiteGround staging subdomain via SSH/SFTP (or SiteGround's deployment tools). E2E Playwright tests are run against the staging environment.
- **Deployment to Production**: Manual promotion from staging to production, after staging sign-off.
- **Note**: Automated deployment to SiteGround may be added in a future phase if SSH key access is confirmed.

## 4. Incremental Implementation Plan

### Phase 0: Local Dev Setup & Training Completion Report — ✅ COMPLETE
- **Full spec**: [docs/Phase 0 - Training Report.md](./Phase%200%20-%20Training%20Report.md)
- **Goal**: Working local environment, CI pipeline, plugin packaging, and the first shippable feature — a Tutor LMS training completion report in the WP admin dashboard with CSV export.
- **Completed**:
    - ✅ Docker images pinned (`wordpress:php8.2-apache`, `mariadb:10.11`); WP-CLI service added.
    - ✅ Seed script `bin/seed-test-data.sh` written.
    - ✅ `.github/workflows/ci.yml` (PHP lint + PHPUnit on push/PR).
    - ✅ `bin/package.sh` produces `dist/ems-plugin-{VERSION}.zip`.
    - ✅ `TutorLMS_Client` tests written and passing (`TutorLMS_ClientTest`).
    - ✅ `TutorLMS_Client` implemented wrapping Tutor LMS PHP functions.
    - ✅ `Training_Report_Page` tests written and passing (`Training_Report_PageTest`).
    - ✅ `Training_Report_Page` implemented (admin menu, table render, CSV export).

### Phase 1: Infrastructure & Test Setup — ✅ COMPLETE
- **Goal**: Establish the "Test-First" environment and verify OSM API connectivity.
- **Completed**:
    - ✅ Docker environment configured.
    - ✅ PHPUnit and Vitest test runners set up.
    - ✅ `OSM_API_Client` parsing tests written and passing (`OSM_ParserTest`, `OSM_API_ClientTest`).
    - ✅ `Mock_Driver` implemented with payloads in `tests/mocks/`.
    - ✅ `Rate_Limiter` (token-bucket) implemented; rate limiting tests pass.
    - ✅ Section Participant Pull prototyped (`get_section_participants`).
    - ✅ `Auth_Provider` interface, `LoginWithGoogle_Auth_Provider` adapter, and `Mock_Auth_Provider` implemented with passing unit tests.
    - ✅ `OSM_Auth_Integration` does not store `access_token` in `$_SESSION` — confirmed by regression test.

### Phase 2: OSM Auth Foundation & Admin Settings *(Current Phase)*
- **Goal**: OSM authentication working end-to-end on local dev with admin visibility; mock/live API driver switchable from WP Admin; graceful handling of non-OSM users.
- **Completed**:
    - ✅ `expedition` and `team` CPTs registered (`CPT_Registry`); meta field validation (`Meta_Validator`); tests passing.
    - ✅ `Gravity_Forms_Client` and `Mock_Gravity_Forms_Client` implemented.
    - ✅ `Reconciliation_Controller` matching logic (OSM members vs GF entries); `Reconciliation_ControllerTest` passing.
    - ✅ `OSM_Auth_IntegrationTest` passing (login hook wires to `getDataPayload` and persists User Meta).
    - ✅ Basic `Admin_Page` with EMS menu and Reconciliation sub-page.
- **Remaining tasks**:
    - **TDD Task**: Write tests for `Admin\Settings_Page` (mock/live toggle stored in WP Option `ems_api_mode`; OSM base URL validation and persistence).
    - Implement `Admin\Settings_Page` sub-page (fields: API Mode toggle `mock`/`live`, OSM API base URL; saved to WP Options).
    - Wire `OSM_API_Client` driver selection through `ems_api_mode` WP Option in `Plugin` bootstrap: `'mock'` → `Mock_Driver`, `'live'` → `Live_Driver`.
    - **TDD Task**: Write regression test for non-OSM user path in `OSM_Auth_Integration` (login data missing `access_token` → `ems_access_type` set to `'local'`, no hydration attempted, no exceptions thrown).
    - Update `OSM_Auth_Integration::handle_osm_login` to handle missing `access_token` gracefully.
    - **TDD Task**: Write tests for admin diagnostic panel (user with OSM meta → shows hydration summary; user without → "No OSM account linked" notice).
    - Implement admin diagnostic panel on EMS dashboard showing current user's stored `ems_access_type`, `ems_section_ids`, `ems_scout_ids`.
    - Verify `getDataPayload` end-to-end with `Mock_Driver` in local dev: trigger login → hydration stored in User Meta → visible in diagnostic panel.
    - **TDD Task**: Define React component tests for the Reconciliation view.
    - Build the React-based "Reconciliation Dashboard" using mock data (Vitest component tests).
- **Phase Complete When**:
    - EMS → Settings sub-page saves mock/live toggle and OSM API base URL to WP Options.
    - Login as a local-only WP user: no errors, `ems_access_type = 'local'` set in User Meta.
    - Login via OSM OIDC in `mock` mode: `getDataPayload` hydration stored in User Meta and visible in admin diagnostic panel.
    - `vendor/bin/phpunit` passes all Phase 2 PHP tests.
    - Reconciliation Dashboard renders correctly against mock data — Vitest component tests pass.

### Phase 3: Volunteer & Team Building
- **Goal**: Enable staffing and participant grouping.
- **Tasks**:
    - **TDD Task**: Write tests for team code auto-generation from expedition code.
    - Build the React "Team Builder" (Drag-and-drop); write component tests for participant assignment and team reordering.
    - **TDD Task**: Write tests for Volunteer availability submission and the confirmation state machine.
    - Implement Volunteer signup and "Confirmation" workflow.
    - **TDD Task**: Write tests for push-back failure handling (job persisted to WP Option, admin notice rendered, retry re-dispatches the correct payload).
- **Phase Complete When**:
    - Team code auto-generation tests pass (e.g. `SP1` → `SP1-1`, `SP1-2`).
    - Team Builder drag-and-drop component tests pass for participant assignment and reordering.
    - Volunteer confirmation state machine tests pass.
    - Push-back failure handling tests pass (persistence, notice rendering, retry dispatch).

### Phase 4: Frontend Portals
- **Goal**: Launch Explorer and Parent views.
- **Tasks**:
    - **TDD Task**: Write component tests for the Explorer Portal (expedition view, team display, route status).
    - Create the React "Explorer Portal" shortcode.
    - **TDD Task**: Write tests for the shell account merge flow (matching by `ems_scout_id`, meta transfer, shell deletion).
    - Implement Parent-Child relationship parsing, selection UI, and shell account merge.
    - **TDD Task**: Write tests for secure route upload (file type validation, naming convention, versioning).
    - Setup secure Route Planning uploads.
    - Confirm SiteGround SMTP availability and implement email notification triggers (see [PRD §4.2a](./Expedition Management System.md#42a-email-notifications-gap--to-be-resolved)).
- **Phase Complete When**:
    - Explorer Portal component tests pass (expedition view, team display, route status).
    - Shell account merge flow tests pass (match by `ems_scout_id`, meta transfer, shell deletion).
    - Secure route upload validation tests pass (file type, naming convention, versioning).
    - SiteGround SMTP confirmed; email notification triggers implemented and covered by integration tests.

### Phase 5: Production Sync & Launch
- **Goal**: Full integration and live testing.
- **Tasks**:
    - Switch to `Live_Driver` for OSM.
    - **TDD Task**: Write tests for service account token refresh (expired access token triggers refresh token exchange, new tokens persisted).
    - Configure and test EMS service account authorisation flow.
    - Investigate and implement `.htaccess` protection for `/wp-content/uploads/ems-secure/` (confirm SiteGround access).
    - Perform load testing on the SiteGround staging server.
    - Final UI polish and user training.
- **Phase Complete When**:
    - All PHPUnit and Vitest tests pass with `Live_Driver` against the staging OSM environment.
    - Service account token refresh tests pass (expired token → refresh exchange → tokens re-persisted).
    - `.htaccess` protection for `/wp-content/uploads/ems-secure/` confirmed and tested on SiteGround.
    - Playwright E2E suite passes on staging for all critical paths.
    - Production deployment checklist signed off.

## 5. Source Directory Map

Canonical class-to-file mapping for agent scaffolding. All classes use the `EMS\` namespace root via PSR-4 Composer autoload.

| File | Class / Interface |
|---|---|
| `src/Plugin.php` | `EMS\Plugin` |
| `src/Core/CPT_Registry.php` | `EMS\Core\CPT_Registry` |
| `src/Core/Table_Installer.php` | `EMS\Core\Table_Installer` |
| `src/Integrations/OSM_API_Client.php` | `EMS\Integrations\OSM_API_Client` |
| `src/Integrations/OSM_Auth_Integration.php` | `EMS\Integrations\OSM_Auth_Integration` |
| `src/Integrations/OSM_Parser.php` | `EMS\Integrations\OSM_Parser` |
| `src/Integrations/TutorLMS_Client.php` | `EMS\Integrations\TutorLMS_Client` *(Phase 0)* |
| `src/Integrations/Drivers/Driver_Interface.php` | `EMS\Integrations\Drivers\Driver_Interface` *(interface)* |
| `src/Integrations/Drivers/Live_Driver.php` | `EMS\Integrations\Drivers\Live_Driver` |
| `src/Integrations/Drivers/Mock_Driver.php` | `EMS\Integrations\Drivers\Mock_Driver` |
| `src/Auth/Auth_Provider.php` | `EMS\Auth\Auth_Provider` *(interface)* |
| `src/Auth/LoginWithGoogle_Auth_Provider.php` | `EMS\Auth\LoginWithGoogle_Auth_Provider` |
| `src/Data/Expedition_Repository.php` | `EMS\Data\Expedition_Repository` |
| `src/Data/Team_Repository.php` | `EMS\Data\Team_Repository` |
| `src/Data/Volunteer_Repository.php` | `EMS\Data\Volunteer_Repository` |
| `src/Data/Route_Submission_Repository.php` | `EMS\Data\Route_Submission_Repository` |
| `src/Admin/Training_Report_Page.php` | `EMS\Admin\Training_Report_Page` *(Phase 0)* |
| `src/Admin/Admin_Page.php` | `EMS\Admin\Admin_Page` |
| `src/Admin/Reconciliation_Controller.php` | `EMS\Admin\Reconciliation_Controller` |
| `src/Admin/Team_Builder_Controller.php` | `EMS\Admin\Team_Builder_Controller` |
| `src/REST/Expedition_REST_Controller.php` | `EMS\REST\Expedition_REST_Controller` |
| `src/REST/Route_REST_Controller.php` | `EMS\REST\Route_REST_Controller` |
| `src/REST/Volunteer_REST_Controller.php` | `EMS\REST\Volunteer_REST_Controller` |

Test files mirror the `src/` structure under `tests/Unit/` (e.g. `src/Integrations/OSM_API_Client.php` → `tests/Unit/Integrations/OSM_API_ClientTest.php`). Mock JSON payloads live in `tests/mocks/`:
- `tests/mocks/osm-get-data-payload.json` — `getDataPayload` (Startup API) response
- `tests/mocks/osm-events.json` — OSM Events list
- `tests/mocks/osm-flexi-records.json` — OSM Flexi-record structure
