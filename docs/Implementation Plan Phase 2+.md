# Development and Deployment Strategy: EMS

This document outlines the environment, testing, and incremental rollout plan for the Expedition Management System.

> **Note**: The original phased plan (Phases 0–5) has been archived to `docs/archive/Development and Deployment - v1-original.md`. The early phase outline notes are archived to `docs/archive/Phases.md`. This document is the authoritative phased plan from Phase 2 onwards.

> **Agent coding conventions**: Namespace/file patterns, PHP test anatomy (`EMSTestCase`, Brain Monkey, Mockery), authoritative WP User Meta keys, `OSM_API_Client` surface, mock file registry, and `Table_Installer` status are all documented in `docs/Implementation Plan Phase 1.md §7`. Read that section before implementing any Phase 2–6 class.

## TDD Workflow — Gherkin First

All stages in all phases follow this sequence:

1. **Write Gherkin scenarios** (`tests/features/*.feature`) covering happy path, edge cases, validation, and guard conditions.
2. **Review scenarios** with the user before any code is written.
3. **Write failing tests** (PHPUnit / Vitest step definitions) — confirm red.
4. **Implement production code** until all tests pass — green.
5. **Refactor** keeping tests green.

Gherkin covers **observable behaviour**: business logic, REST API shape/auth, and UI behaviour. CPT registration, meta field wiring, and table schema are tested directly in PHPUnit (Brain Monkey stubs) — not via Gherkin. Feature files are named by stage (e.g. `tests/features/2.1-explorer-portal.feature`).

---

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
- **All OSM Operations**: All EMS-to-OSM operations — data imports, membership pulls, and push-backs — are performed via an admin-triggered personal OAuth2 authorization code flow. No tokens are stored at any point. OSM has no machine/service account concept. See [ADR 010](./Technical Architecture.md#adr-010-revised-admin-triggered-osm-sync-oauth).

### 2.2 OSM Push-back Failure Handling
- **Problem**: Push-back operations (flexi-record updates, event status changes) may fail if OSM is temporarily unavailable.
- **Solution**: On failure, the job is persisted to a WP Option (`ems_failed_pushback_queue`) as a serialized entry and an **admin notice** is surfaced in the EMS dashboard identifying the failed operation with a **"Retry" button**. No external queue library is required.
- **Rationale**: Push-backs are low-volume, admin-triggered operations. A manual retry with clear visibility is appropriate for this scale and avoids introducing a WooCommerce-namespaced dependency (WP Action Scheduler) for a lightweight problem.
- **Escalation path**: If retry frequency becomes a real operational problem in production, the queue can be upgraded to use native WP Cron (`wp_schedule_single_event()`) with the same WP Option store — no architectural change required.
- **TDD Task**: Write tests for failure persistence (job written to option on HTTP error), notice rendering, and retry dispatch.

### 2.3 Rate Limiting & Performance
OSM has strict rate limits. Our integration must include:
- **Throttling**: A central `OSM_API_Client` class that implements a "Token Bucket" or simple delay logic to ensure we never exceed the allowed requests per minute. Throttling is already in place — OSM aggressively blocks accounts and IP addresses that breach rate limits.
- **Caching**: Aggressive use of WordPress Transients to cache OSM data (e.g., Section lists, Event details) for 1–12 hours.
- **Batching**: Where the API allows, fetch data in batches rather than individual requests per user.

### 2.4 Mock Data Layer (Test Mode)
- **Implementation**: The `OSM_API_Client` uses a "Driver" pattern.
- **Drivers**:
    - `Live_Driver`: Makes real HTTP requests to OSM.
    - `Mock_Driver`: Returns static JSON payloads (stored in `tests/mocks/`) for all data requests.
- **Switching**: Controlled via a WP Option (`ems_api_mode`) or `EMS_TEST_MODE` constant in `wp-config.php`.

## 3. CI/CD Pipeline

- **Local Development**: Docker Compose environment (see §1.1). All development and initial testing occurs locally.
- **Automated Checks (GitHub Actions)**: A GitHub Actions workflow runs on every push to `main` and all pull requests:
    1. PHP lint (`php -l`)
    2. PHPUnit test suite
    3. Vitest test suite
- **Deployment to Staging**: Manual. Once all automated checks pass on a branch, the developer deploys to the SiteGround staging subdomain via SSH/SFTP (or SiteGround's deployment tools). E2E Playwright tests are run against the staging environment.
- **Deployment to Production**: Manual promotion from staging to production, after staging sign-off.


### Phase 2: Explorer & Parent Views *(No live OSM data — cached WP data only)*
- **Goal**: Explorers and parents can log in and see their personal expedition status, training record, and team information. All data is served from WordPress (populated by Phase 1 admin actions) — no live OSM API calls are made during portal render.
- **Key decisions to resolve**:
    - OSM Parent/Explorer linkage: define the three login cases (explorer self-login, parent-as-proxy, unknown OSM user).
    - Additional support needs: handling explorers who cannot complete standard online courses (e.g., dyslexia, autism) — define the admin override mechanism.
    - What actions parents can take on behalf of their child vs. explorer-only actions.

#### Stage 2.1 — Explorer Portal Core
- **TDD Task**: Write tests for `Explorer_Portal_Controller` — given a logged-in `user_id` with `ems_access_type='member'`, returns expedition assignment, team members, route info, LiC details, WhatsApp link, and training status. Test: assigned explorer, unassigned explorer, `access_type='local'` (no OSM link) edge case.
- Implement `Explorer_Portal_Controller` and `GET /wp-json/ems/v1/portal/explorer` REST endpoint.
- Create React `ExplorerPortal` SPA and register `[ems-explorer-portal]` shortcode.
- Write Vitest component tests: expedition card, team roster, training status display, "not yet assigned" empty state.
- **Stage Complete When**: Controller tests pass; component tests pass for all explorer view states.

#### Stage 2.2 — Training Record Display
- **TDD Task**: Write tests for training record fetch in portal context — returns course name, completion status, completion date; applies fallback logic from Stage 1.6 for parent-trained records; returns `requires_override: true` flag if admin has marked an exemption.
- Add admin mechanism (meta box or settings toggle) to mark a training record as "exempted" for accessibility reasons.
- Write tests for exemption flag persistence and retrieval.
- Implement training record section in `ExplorerPortal` component.
- **Stage Complete When**: Training record tests (including fallback and exemption) pass; component renders all training states correctly.

#### Stage 2.3 — Parent Portal & Child Selection
- **TDD Task**: Write tests for `Parent_Portal_Controller` — given `ems_access_type='parent'` and a list of `ems_scout_ids`, returns child selection list. For each selected child, returns their expedition assignment from WP (same data shape as explorer portal). Test: single child, multi-child, unknown child (no WP record).
- Implement `Parent_Portal_Controller` and `GET /wp-json/ems/v1/portal/parent` REST endpoint.
- Create React `ParentPortal` SPA with child selector and `[ems-parent-portal]` shortcode.
- Write Vitest component tests: child selector, expedition view per child, "child not yet in system" state.
- **Stage Complete When**: Controller tests pass for all child-count scenarios; component tests pass.

#### Stage 2.4 — Shell Account Merge
- **TDD Task**: Write tests for the shell account merge flow: when a child logs in via OIDC and a shell account exists with a matching `ems_scout_id` in User Meta, all EMS User Meta is transferred to the OIDC account and the shell is deleted. Test: merge succeeds, no shell found (no-op), duplicate `ems_scout_id` guard.
- Implement merge logic in `OSM_Auth_Integration::handle_osm_login`.
- **Stage Complete When**: Shell account merge tests pass for all three cases (merge, no-op, duplicate guard).

- **Phase 2 Complete When**:
    - All Stage 2.1–2.4 tests pass.
    - Explorer can log in and see their expedition assignment, team, training record, and contact details.
    - Parent can log in, select a child, and see that child's portal view.
    - Shell account merge fires correctly on first explorer OIDC login.

---

### Phase 3: Signup *(Gravity Forms based)*
- **Goal**: Participants can sign up for expeditions via Gravity Forms. The signup process captures first aid declarations, teammate preferences, and DofE level. EMS processes submissions, enforces access-type rules, and surfaces results in the reconciliation view.

#### Stage 3.1 — Gravity Forms Signup Form
- Define GF form fields: DofE level, preferred expedition dates, first aid status, teammate preferences (free text or GF user field), Explorer email, any additional support needs.
- **TDD Task**: Write tests for `GF_Signup_Processor` — processes a new GF submission, stores first aid status and preferences in WP User Meta (keyed by `ems_scout_id` if present, otherwise by email), fires `ems_signup_received` action hook. Test: new signup, duplicate signup guard, missing Explorer email validation.
- Implement `GF_Signup_Processor` and wire to the GF `gform_after_submission` hook.
- **Stage Complete When**: Processor tests pass for all three cases; `ems_signup_received` hook fires with correct payload.

#### Stage 3.2 — Access Control Enforcement
- **TDD Task**: Write tests for access-type enforcement: `access_type='parent'` can initiate a new DofE level signup; `access_type='member'` cannot. Test Explorer email validation — if no email in OSM, return `requires_email: true` error.
- Implement access-type checks in `GF_Signup_Processor`.
- **Stage Complete When**: Access control and email validation tests pass.

#### Stage 3.3 — Reconciliation View Enhancements
- The core `Reconciliation_Controller` (GF vs OSM matching) is already built. This stage extends it with first aid and preferences data.
- **TDD Task**: Write tests for enriched reconciliation payload — each matched record includes first aid status and teammate preferences from GF submission alongside OSM member data.
- Extend `Reconciliation_Controller::get_enriched_matches()` to include first aid and preferences.
- Update `ReconciliationDashboard` React component to display first aid status and preferences columns.
- Write Vitest component tests for the enriched columns.
- **Stage Complete When**: Enriched reconciliation tests pass; component renders first aid and preferences data correctly.

- **Phase 3 Complete When**:
    - All Stage 3.1–3.3 tests pass.
    - GF signup form captures required fields and EMS processes submissions correctly.
    - Access control prevents invalid signup paths.
    - Reconciliation view shows first aid and preferences alongside OSM/GF match status.

---

### Phase 4: Volunteer Management
- **Goal**: Volunteers can view expeditions and sign up with availability details. Admins can confirm, reject, and review coverage across the season.

#### Stage 4.1 — Volunteer Availability Data Layer
- **TDD Task**: Write tests for `Volunteer_Repository` — submit availability (whole expedition or per-day/overnight), retrieve availability by expedition, retrieve by user (seasonal view). Confirm `ems_volunteer_availability` custom table is created on activation.
- Implement `Volunteer_Repository` using `ems_volunteer_availability` table (see [ADR 011](./Technical Architecture.md#adr-011-custom-database-tables)).
- Add `POST /wp-json/ems/v1/volunteer/availability` and `GET /wp-json/ems/v1/volunteer/availability/{expedition_id}` REST endpoints.
- **Stage Complete When**: Repository tests pass; activation hook creates table correctly.

#### Stage 4.2 — Volunteer Portal (Frontend)
- **TDD Task**: Write tests for `Volunteer_Portal_Controller` — returns expedition list with current signup status for the logged-in user; returns per-expedition availability detail.
- Create React `VolunteerDashboard` SPA and register `[ems-volunteer-dashboard]` shortcode.
- Write Vitest component tests: expedition list, availability form (whole expedition toggle, day/overnight selectors), submission confirmation state.
- **Stage Complete When**: Controller tests pass; component tests pass for all availability form states.

#### Stage 4.3 — Admin Confirmation & Coverage Views
- **TDD Task**: Write tests for `Volunteer_Admin_Controller` — confirm/reject availability, return overview calendar data (all expeditions × volunteers), return expedition-specific coverage, return person view (single volunteer across season).
- Implement `Volunteer_Admin_Controller` and wire to admin REST endpoints.
- Build React admin views: overview calendar grid, expedition detail view, person view.
- Write Vitest component tests for each admin view.
- **Stage Complete When**: Controller tests pass; all three admin view component tests pass.

#### Stage 4.4 — Volunteer Confirmation State Machine & Notifications
- **TDD Task**: Write tests for confirmation state machine: `pending` → `confirmed` (by admin), `pending` → `rejected` (by admin), `confirmed` → `withdrawn` (by volunteer). Each transition fires the correct `ems_volunteer_*` action hook.
- Implement state machine transitions in `Volunteer_Repository`.
- Confirm SiteGround SMTP availability. Implement email notification triggers (see [PRD §4.2a](./Expedition Management System.md#42a-email-notifications-gap--to-be-resolved)) on `ems_volunteer_confirmed` and `ems_volunteer_rejected` hooks.
- **Stage Complete When**: State machine tests pass for all valid and invalid transitions; email notification hooks fire in tests.

- **Phase 4 Complete When**:
    - All Stage 4.1–4.4 tests pass.
    - Volunteers can submit availability; admins can confirm/reject and view coverage.
    - State machine transitions correctly; notification hooks fire.

---

### Phase 5: Route Submission & Review
- **Goal**: Teams submit GPX and route cards before a deadline. LiCs review, approve, or provide feedback. Explorers and parents see current status and submission history.

#### Stage 5.1 — Secure File Storage
- **TDD Task**: Write tests for `Route_Submission_Repository` — store submission (file type validation: PDF/GPX only, naming convention: `[Team_Code]_[File_Type]_v[X].[ext]`, version auto-increment), retrieve history for a team, retrieve latest version.
- Implement `Route_Submission_Repository` using `ems_route_submissions` table.
- Investigate and implement `.htaccess` protection for `/wp-content/uploads/ems-secure/` on SiteGround.
- Implement `GET /wp-json/ems/v1/download/{submission_id}` REST endpoint with Nonce/capability check before serving file via `readfile()`.
- **Stage Complete When**: Repository tests pass (file type, naming, versioning); download endpoint requires auth in tests.

#### Stage 5.2 — Route Submission Portal
- **TDD Task**: Write tests for `Route_Submit_Controller` — processes upload (validates type, generates versioned filename, stores to WP Media Library, writes to `ems_route_submissions`). Test: valid GPX, valid PDF, invalid type rejected, duplicate version guard.
- Implement `Route_Submit_Controller` and `POST /wp-json/ems/v1/route/submit` endpoint.
- Create React `RouteSubmit` SPA and register `[ems-route-submit]` shortcode.
- Write Vitest component tests: upload form, file type error state, versioning display.
- **Stage Complete When**: Controller tests pass for all file scenarios; component tests pass.

#### Stage 5.3 — LiC Review & Feedback
- **TDD Task**: Write tests for `Route_Review_Controller` — LiC can approve a submission or add feedback text; approval changes submission `status` to `approved`; feedback changes to `changes_requested`; explorer/parent endpoint returns latest submission with feedback.
- Implement `Route_Review_Controller` and admin review REST endpoints.
- Build React `RouteStatus` component and register `[ems-route-status]` shortcode (shows current status and LiC feedback to explorers/parents).
- Write Vitest component tests for status display and feedback rendering.
- **Stage Complete When**: Review controller tests pass; `RouteStatus` component tests pass.

- **Phase 5 Complete When**:
    - All Stage 5.1–5.3 tests pass.
    - Teams can submit and re-submit routes; LiCs can review and give feedback; explorers/parents see current status.
    - Files stored securely and served only via authenticated REST endpoint.

---

### Phase 6: OSM Push-back & Production Launch
- **Goal**: Write operations from EMS back to OSM are fully operational via admin-triggered OAuth. All systems pass production readiness checks.

#### Stage 6.1 — Write-Scope OAuth & Push-back Authorisation
- The `OSM_Sync_Auth_Handler` (Stage 1.2) is extended to request write scopes when push-back operations are triggered. The same admin-triggered personal OAuth2 flow is used — no token persistence is introduced.
- **TDD Task**: Write tests for `OSM_Sync_Auth_Handler` with write scopes — `initiate()` builds an authorization URL containing `section:member:write section:flexirecord:write` in addition to the read scopes when invoked from a push-back action. Test: correct scope string present; read-only initiation does not include write scopes.
- **TDD Task**: Write tests confirming that when `OSM_API_Client` receives a 401 during a push-back, the failure is written to `ems_failed_pushback_queue` (per §2.2) and an admin notice is surfaced. No token refresh is attempted — the token was already discarded.
- Extend `OSM_Sync_Auth_Handler` to accept a `$scopes` parameter; wire push-back actions in `Admin_Page` to use the write-scope variant.
- **Stage Complete When**: Write-scope auth handler tests pass; 401 failure queue test passes; no token is persisted at any point.

#### Stage 6.2 — OSM Push-back Operations
- **TDD Task**: Write tests for `OSM_Pushback_Service` — pushes team assignment to flexi-record, pushes first aid status to flexi-record, updates OSM event status to "Show in Parent Portal". On HTTP error, job is written to `ems_failed_pushback_queue` WP Option.
- **TDD Task**: Write tests for admin retry UI — notice renders job details and "Retry" button; retry re-dispatches the correct payload; on success the job is removed from the queue.
- Implement `OSM_Pushback_Service` and wire to admin team assignment actions.
- Build Retry UI in the EMS admin dashboard.
- **Stage Complete When**: Push-back tests pass (success path, failure → queue, retry → success/failure); retry UI component tests pass.

#### Stage 6.3 — Production Hardening & Launch
- Switch `ems_api_mode` to `live` on staging; confirm all `Live_Driver` calls succeed against OSM sandbox.
- Run Playwright E2E suite on staging for all critical paths: OSM login, explorer portal view, parent child selection, volunteer signup, route submission, admin import and team assignment.
- Confirm `.htaccess` protection for `/wp-content/uploads/ems-secure/` is active on SiteGround.
- Perform load testing on the SiteGround staging server.
- Final UI polish and user training documentation.
- **Stage Complete When**: All PHPUnit, Vitest, and Playwright tests pass on staging with `Live_Driver`. Production deployment checklist signed off.

- **Phase 6 Complete When**:
    - All Stage 6.1–6.3 checks pass.
    - Service account authorised; token refresh tested; push-back operations verified against OSM.
    - Retry mechanism operational for failed push-backs.
    - Playwright E2E suite passes on staging.
    - Production deployment signed off.

---

## 6. Source Directory Map

Canonical class-to-file mapping for agent scaffolding. All classes use the `EMS\` namespace root via PSR-4 Composer autoload.

### Existing (Foundations)

| File | Class / Interface | Status |
|---|---|---|
| `src/Plugin.php` | `EMS\Plugin` | ✅ |
| `src/Core/CPT_Registry.php` | `EMS\Core\CPT_Registry` | ✅ |
| `src/Core/Table_Installer.php` | `EMS\Core\Table_Installer` | ✅ |
| `src/Integrations/OSM_API_Client.php` | `EMS\Integrations\OSM_API_Client` | ✅ |
| `src/Integrations/OSM_Auth_Integration.php` | `EMS\Integrations\OSM_Auth_Integration` | ✅ |
| `src/Integrations/OSM_Parser.php` | `EMS\Integrations\OSM_Parser` | ✅ |
| `src/Integrations/TutorLMS_Client.php` | `EMS\Integrations\TutorLMS_Client` | ✅ |
| `src/Integrations/Drivers/Driver_Interface.php` | `EMS\Integrations\Drivers\Driver_Interface` | ✅ |
| `src/Integrations/Drivers/Live_Driver.php` | `EMS\Integrations\Drivers\Live_Driver` | ✅ |
| `src/Integrations/Drivers/Mock_Driver.php` | `EMS\Integrations\Drivers\Mock_Driver` | ✅ |
| `src/Auth/Auth_Provider.php` | `EMS\Auth\Auth_Provider` *(interface)* | ✅ |
| `src/Auth/LoginWithGoogle_Auth_Provider.php` | `EMS\Auth\LoginWithGoogle_Auth_Provider` | ✅ |
| `src/Admin/Training_Report_Page.php` | `EMS\Admin\Training_Report_Page` | ✅ |
| `src/Admin/Admin_Page.php` | `EMS\Admin\Admin_Page` | ✅ |
| `src/Admin/Settings_Page.php` | `EMS\Admin\Settings_Page` | ✅ |
| `src/Admin/Diagnostic_Panel.php` | `EMS\Admin\Diagnostic_Panel` | ✅ |
| `src/Admin/Reconciliation_Controller.php` | `EMS\Admin\Reconciliation_Controller` | ✅ |
| `src/Integrations/Gravity_Forms_Client.php` | `EMS\Integrations\Gravity_Forms_Client` | ✅ |

### To Be Built (Phases 1–6)

| File | Class / Interface | Phase |
|---|---|---|
| `src/Data/Expedition_Repository.php` | `EMS\Data\Expedition_Repository` | 1.1 |
| `src/Data/Team_Repository.php` | `EMS\Data\Team_Repository` | 1.1 |
| `src/Data/Team_Member_Repository.php` | `EMS\Data\Team_Member_Repository` | 1.1 |
| `src/Admin/OSM_Sync_Auth_Handler.php` | `EMS\Admin\OSM_Sync_Auth_Handler` | 1.2 |
| `src/Integrations/OSM_Section_Importer.php` | `EMS\Integrations\OSM_Section_Importer` | 1.3 |
| `src/Integrations/Flexi_Structure_Parser.php` | `EMS\Integrations\Flexi_Structure_Parser` | 1.4 |
| `src/Integrations/Flexi_Column_Map.php` | `EMS\Integrations\Flexi_Column_Map` | 1.4 |
| `src/Integrations/Flexi_Record_Importer.php` | `EMS\Integrations\Flexi_Record_Importer` | 1.5 |
| `src/Admin/Admin_View_Controller.php` | `EMS\Admin\Admin_View_Controller` | 1.6 |
| `src/REST/Expedition_REST_Controller.php` | `EMS\REST\Expedition_REST_Controller` | 1.6 |
| `src/Admin/Expedition_Admin_Controller.php` | `EMS\Admin\Expedition_Admin_Controller` | 1.7 |
| `src/Integrations/GF_Signup_Processor.php` | `EMS\Integrations\GF_Signup_Processor` | 3.1 |
| `src/Data/Volunteer_Repository.php` | `EMS\Data\Volunteer_Repository` | 4.1 |
| `src/Frontend/Volunteer_Portal_Controller.php` | `EMS\Frontend\Volunteer_Portal_Controller` | 4.2 |
| `src/Admin/Volunteer_Admin_Controller.php` | `EMS\Admin\Volunteer_Admin_Controller` | 4.3 |
| `src/REST/Volunteer_REST_Controller.php` | `EMS\REST\Volunteer_REST_Controller` | 4.1 |
| `src/Frontend/Explorer_Portal_Controller.php` | `EMS\Frontend\Explorer_Portal_Controller` | 2.1 |
| `src/Frontend/Parent_Portal_Controller.php` | `EMS\Frontend\Parent_Portal_Controller` | 2.3 |
| `src/Data/Route_Submission_Repository.php` | `EMS\Data\Route_Submission_Repository` | 5.1 |
| `src/Admin/Route_Review_Controller.php` | `EMS\Admin\Route_Review_Controller` | 5.3 |
| `src/Frontend/Route_Submit_Controller.php` | `EMS\Frontend\Route_Submit_Controller` | 5.2 |
| `src/REST/Route_REST_Controller.php` | `EMS\REST\Route_REST_Controller` | 5.1 |
| `src/Integrations/OSM_Pushback_Service.php` | `EMS\Integrations\OSM_Pushback_Service` | 6.2 |

Test files mirror the `src/` structure under `tests/Unit/` (e.g. `src/Integrations/OSM_API_Client.php` → `tests/Unit/Integrations/OSM_API_ClientTest.php`). Mock JSON payloads live in `tests/mocks/`:
- `tests/mocks/osm-get-data-payload.json` — `getDataPayload` (Startup API) response ✅
- `tests/mocks/osm-events.json` — OSM Events list ✅
- `tests/mocks/osm-flexi-records.json` — OSM Flexi-record structure ✅
- `tests/mocks/osm-section-participants.json` — Section participant list *(Phase 1.1)*
