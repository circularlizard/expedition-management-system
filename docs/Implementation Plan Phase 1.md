# Development and Deployment Strategy: EMS

This document outlines the environment, testing, and incremental rollout plan for the Expedition Management System.

> **Note**: The original phased plan (Phases 0–5) has been archived to `docs/archive/Development and Deployment - v1-original.md`. This document reflects the revised phasing from `docs/NewPhases/Phases.md`, starting from the current state of the application.

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

### 1.4 Local HTTPS (Required for Stage 1.2+)

The OSM OIDC callback redirect URI **must** be HTTPS. Staging and production are already HTTPS. For the local Docker environment, a Caddy reverse-proxy service handles TLS termination using Caddy's built-in local CA.

**What is already configured** (`docker-compose.yml` + `Caddyfile`):
- A `caddy` service (image `caddy:2-alpine`) proxies `https://localhost:443` → `wordpress:80`.
- `WP_HOME`, `WP_SITEURL` are set to `https://localhost`; `FORCE_SSL_ADMIN` is `true`.
- The `X-Forwarded-Proto` header is forwarded so WordPress correctly detects HTTPS.

**One-time developer setup** (per machine, before Stage 1.2):

1. Bring the stack up to let Caddy generate its local CA:
    ```bash
    docker compose up -d
    ```
2. Export and trust the Caddy root CA (macOS):
    ```bash
    docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt ./caddy-local-ca.crt
    sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ./caddy-local-ca.crt
    rm ./caddy-local-ca.crt
    ```
3. Restart your browser. Navigate to `https://localhost` — the browser should show a valid (green) certificate.

> **Note**: After this setup, primary browser access is `https://localhost`. The direct HTTP port `8080` remains available for PHPUnit test runs and quick checks, but WordPress admin will redirect to HTTPS. The `caddy-local-ca.crt` file is intentionally deleted after trusting — it never needs to be committed.

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

## 4. Current State (Foundations — ✅ Complete)

The following infrastructure was built during the original Phases 0–2 and is the starting point for all new phases. These do not need to be re-built.

### 4.1 Infrastructure & Tooling
- ✅ Docker images pinned (`wordpress:php8.2-apache`, `mariadb:10.11`); WP-CLI service added.
- ✅ PHPUnit + Brain Monkey (PHP) and Vitest + React Testing Library (JS) test runners configured.
- ✅ `.github/workflows/ci.yml` — PHP lint + PHPUnit + Vitest on push/PR.
- ✅ `bin/package.sh` produces `dist/ems-plugin-{VERSION}.zip`.

### 4.2 OSM API Client
- ✅ `OSM_API_Client` with `Driver_Interface`, `Live_Driver`, `Mock_Driver` — tests passing.
- ✅ `OSM_Parser` — parses `getDataPayload`, section participants, flexi-records.
- ✅ `Rate_Limiter` (token-bucket) — rate limiting tests pass.
- ✅ Mock payloads: `tests/mocks/osm-get-data-payload.json`, `osm-events.json`, `osm-flexi-records.json`.

### 4.3 Authentication
- ✅ `Auth_Provider` interface, `LoginWithGoogle_Auth_Provider` adapter, `Mock_Auth_Provider`.
- ✅ `OSM_Auth_Integration` — hooks into `rtcamp.google_user_logged_in`, hydrates User Meta, sets `ems_access_type`. Does not store `access_token`.

### 4.4 Admin Foundation
- ✅ `expedition` and `team` CPTs registered (`CPT_Registry`); `Meta_Validator` tests passing.
- ✅ `Admin_Page` — top-level EMS menu with sub-pages (Dashboard, Reconciliation, Settings).
- ✅ `Admin\Settings_Page` — mock/live API toggle (`ems_api_mode`), OSM API base URL (HTTPS only).
- ✅ `Admin\Diagnostic_Panel` — shows `ems_access_type`, `ems_section_ids`, `ems_scout_ids` for current user.
- ✅ `Training_Report_Page` — Tutor LMS training completion report with CSV export.

### 4.5 Reconciliation
- ✅ `Gravity_Forms_Client` and `Mock_Gravity_Forms_Client`.
- ✅ `Reconciliation_Controller` — OSM members vs GF entries matching logic; tests passing.
- ✅ React `ReconciliationDashboard` component — Vitest component tests passing (8 tests).

**Test counts at this baseline**: PHP 107 tests / 178 assertions (all green). JS 8 tests (all green).

---

## 5. Incremental Implementation Plan

### Phase 1: Admin Views *(Admin-only, read from OSM, write to WP)*
- **Goal**: Establish the EMS data structures, populate them from OSM (membership lists + flexi-record), build read-only admin views, then layer in EMS-internal update logic. Two loosely coupled data stores: (1) OSM snapshot data and (2) EMS-managed data (expeditions, teams, LiC, WhatsApp, assignments).
- **Audience**: WordPress admins only.
- **Stage order rationale**: Data structures and population first, views second, update/edit logic last. The 2026 season data is already in OSM flexi-records and will be imported as a seed. Future seasons will be built in EMS first and exported to OSM (Phase 6).
- **Key decision to resolve**:
    - OSM Parent/Explorer linkage when training records were completed by a parent login — define fallback strategy (Stage 1.7).

#### Stage 1.1 — Data Structures & Repositories
- **TDD Task**: Write tests for `Expedition_Repository` — create expedition (code, dates, DofE level, LiC `user_id`, WhatsApp link), retrieve by ID, list all. Test: required fields validated, duplicate expedition code rejected.
- **TDD Task**: Write tests for `Team_Repository` — create team linked to expedition, auto-generate team code from expedition code (`SP1` → `SP1-1`, `SP1-2`), code uniqueness per expedition.
- **TDD Task**: Write tests for `Team_Member_Repository` — assign explorer to team, prevent duplicate, list by team, list by expedition (all members), list unassigned explorers for an expedition.
- Implement `Expedition_Repository`, `Team_Repository`, `Team_Member_Repository`.
- `Table_Installer` already creates all three custom tables (`ems_team_members`, `ems_volunteer_availability`, `ems_route_submissions`) on plugin activation — **no changes to `Table_Installer` are needed**. Write a test confirming `Table_Installer::install()` emits the correct SQL for `ems_team_members` (see §7.7 for table schema).
- Add `ems_expedition_lic`, `ems_expedition_whatsapp`, `ems_expedition_route_info` to the `expedition` CPT meta fields.
- **Stage Complete When**: All repository tests pass; `ems_team_members` table created correctly on activation; team code auto-increment tests pass.

#### Stage 1.2 — Admin-Triggered Sync OAuth Handler

> **Pre-requisite**: Local HTTPS must be configured (§1.4) before this stage. The OSM OIDC redirect URI (`admin_url('admin-post.php?action=ems_osm_callback')`) resolves to `https://localhost/wp-admin/admin-post.php?action=ems_osm_callback`. Register this URL in the OSM OAuth application before testing the callback end-to-end.

- **TDD Task**: Write tests for `OSM_Sync_Auth_Handler` — `initiate()` returns a correctly formed OSM authorization URL (correct base URL, scopes `section:member:read section:flexirecord:read`, state param, redirect URI). `handle_callback()` exchanges an authorization code for a token pair and invokes the registered sync callback. Test: valid callback fires callback, missing `state` param rejected, OSM error response surfaced as admin notice.
- Implement `OSM_Sync_Auth_Handler` (see [ADR 010](./Technical Architecture.md#adr-010-revised-admin-triggered-osm-sync-oauth)). Wire "Sync from OSM" button in `Admin_Page` to `OSM_Sync_Auth_Handler::initiate()`.
- Register the WP admin callback endpoint that receives the OSM redirect. Implementation anchors (see §7.5 for full notes): redirect URI is `admin_url('admin-post.php?action=ems_osm_callback')`; register via `add_action('admin_post_ems_osm_callback', ...)`; state param is a WP nonce (`wp_create_nonce` / `wp_verify_nonce`). OSM authorization and token exchange URLs are in `docs/OSM Oauth.md`.
- Add mock OAuth callback payloads to `tests/mocks/`.
- **Stage Complete When**: Auth handler tests pass; no token is persisted after the callback; mock callback correctly fires the sync callback.

#### Stage 1.3 — Membership Pull
- **TDD Task**: Write tests for `OSM_Section_Importer` — given a valid access token and a list of section IDs, calls `get_section_members()` for each, parses `member_id` (scout ID), first name, last name, explorer email, parent email, unit. Upserts to WP User Meta (keyed by `ems_scout_id` — singular int). Meta keys written per imported user: `ems_scout_id`, `ems_first_name`, `ems_last_name`, `ems_explorer_email`, `ems_parent_email`, `ems_unit` (see §7.2). Section IDs to import are read from the `ems_managed_sections` WP Option (see `docs/Data Schema and API.md §5.1`). Test: new member created, existing member updated, member with missing email handled gracefully, duplicate scout ID skipped.
- Implement `OSM_Section_Importer`. Wire to the `OSM_Sync_Auth_Handler` callback.
- `tests/mocks/members.json` already exists and is loaded by `Mock_Driver::get_section_members()`. Extend it with any additional fields needed (e.g. `parent_email`) rather than creating a new file.
- **Stage Complete When**: Importer tests pass for all member states; WP User Meta contains `ems_scout_id`, `ems_first_name`, `ems_last_name`, `ems_explorer_email`, `ems_parent_email`, `ems_unit` after a mock import.

#### Stage 1.4 — Flexi-Record Column Mapper
- **TDD Task**: Write tests for `Flexi_Structure_Parser` — given a `getFlexiStructure` response, returns a flat list of `{ column_id, column_name }` objects for display in the mapper UI.
- **TDD Task**: Write tests for `Flexi_Column_Map` — saves a mapping array to WP Options (`ems_flexirecord_column_map`), retrieves it, validates required EMS fields are mapped (`expedition_code`, `team_code`, `participant_scout_id`). Test: valid map saves, missing required field returns validation error.
- **Stage 1.4 prerequisite**: `get_flexi_record_structure(int $section_id, int $flexi_id): array` exists on `Driver_Interface` and `Mock_Driver` but is **not yet exposed on `OSM_API_Client`**. Add this method to `OSM_API_Client` before implementing `Flexi_Structure_Parser` (see §7.3).
- Implement `Flexi_Structure_Parser` and `Flexi_Column_Map`.
- Build React "Column Mapper" admin UI: fetch live flexi-record structure, display columns, allow admin to assign each column to an EMS field (or mark as unmapped), save mapping. Write Vitest component tests.
- Create `tests/mocks/osm-flexi-record-structure.json` — this is the exact filename loaded by `Mock_Driver::get_flexi_record_structure()` (see §7.4).
- **Stage Complete When**: Parser and map tests pass; component saves a valid mapping; missing required field validation fires correctly.

#### Stage 1.5 — Flexi-Record Import: Parse, Review & Commit
- **TDD Task**: Write tests for `Flexi_Record_Importer` — given a `getFlexiRecords` response and a saved column map, produces three buckets: clean rows (all required fields parsed), partial rows (some fields parsed), unparseable rows (no required fields matched). Test: clean row, row with one missing field, row with a `participant_scout_id` not present in the membership snapshot from Stage 1.3 (flagged as unmatched), empty row.
- **TDD Task**: Write tests for the commit step — given a validated set of clean rows, creates `expedition` CPTs and `team` CPTs, inserts rows into `ems_team_members`. Test: idempotent on re-import (no duplicates), partial rows not committed, unmatched scout IDs not committed.
- **TDD Task**: After a successful commit, the WP Option `ems_osm_last_sync` is updated to the current UTC datetime as an ISO 8601 string. Test: option is set on successful commit; option is **not** updated if commit throws or inserts zero rows.
- Implement `Flexi_Record_Importer`. Wire to the `OSM_Sync_Auth_Handler` callback (runs after Stage 1.3 membership pull).
- Build React "Import Review" admin screen: shows three buckets, allows admin to override or skip partial/failed rows, presents a "Commit" button. Write Vitest component tests for each bucket state and the override interaction.
- Create `tests/mocks/osm-flexi-record-data.json` — this is the exact filename loaded by `Mock_Driver::get_flexi_record_data()` (see §7.4). Include clean, partial, and bad rows.
- **Stage Complete When**: Importer bucketing tests pass; commit idempotency tests pass; `ems_osm_last_sync` is written on success and not written on failure; review component tests pass for all bucket states.

#### Stage 1.6 — Admin Read Views
- **TDD Task**: Write tests for `Admin_View_Controller` — returns correctly shaped payloads for each view: by explorer (expeditions + teams + training status), by team (members + first aid coverage flag), by expedition (all teams and members), by unit/patrol. Every payload includes a top-level `last_synced` field populated from the `ems_osm_last_sync` WP Option (ISO 8601 string, or `null` if never synced).
- Implement `Admin_View_Controller` and wire to REST endpoints.
- Build React admin views (four tabs or sub-pages):
    - **By Explorer**: expedition/team assignment, training status (Tutor LMS), first aid declaration.
    - **By Team**: team members, first aid coverage indicator.
    - **By Expedition**: all teams and members.
    - **By Unit (Patrol)**: all explorers in a patrol across expeditions.
- Add "Download CSV" to each view. Write tests for CSV serialisation.
- Write Vitest component tests for each view (data rendering, empty state, loading state). Each view renders a "Last synced: [date]" indicator; test the `null` (never synced) state renders "Never synced".
- **Stage Complete When**: All view controller tests pass (`last_synced` field present in every payload); all four view component tests pass including last-synced display; CSV download tests pass.

#### Stage 1.7 — EMS-Internal Update Logic
- **TDD Task**: Write tests for `Expedition_Admin_Controller` — create expedition (validates code format, rejects duplicate), edit expedition (LiC, WhatsApp link, route info, dates), assign explorer to expedition, reassign explorer between teams.
- Implement `Expedition_Admin_Controller` and wire to WP admin meta boxes / REST endpoints.
- Build React "Create/Edit Expedition" form (code, dates, DofE level, LiC user picker, WhatsApp URL, route info text). Write Vitest component tests (validation states, submission, edit pre-population).
- Build React "Explorer Assignment" drag-and-drop view — move explorers from unassigned pool into teams. Write Vitest component tests.
- **Stage Complete When**: Controller tests pass; expedition form component tests pass; drag-drop assignment component tests pass.

#### Stage 1.8 — Training Status Fallback
- **TDD Task**: Write tests for training record fallback: when a Tutor LMS record is linked to a parent `user_id` rather than the explorer's `user_id`, the system falls back to the `ems_scout_id` anchor to retrieve the record. Test: match found via fallback, no record found (returns `null`).
- Implement fallback logic in `TutorLMS_Client`.
- **Stage Complete When**: Both fallback paths tested and passing; admin view shows correct training status for parent-trained explorers.

- **Phase 1 Complete When**:
    - All Stage 1.1–1.8 tests pass (`vendor/bin/phpunit`, `npm run test`).
    - Admin can trigger an OSM sync (membership + flexi-record), review the import, and commit it.
    - Admin can view all data in the four view modes and download CSV.
    - Admin can create/edit expeditions (LiC, WhatsApp, route info) and reassign explorers between teams.
    - Training fallback logic tested and passing.



## 6. Source Directory Map

Canonical class-to-file mapping for agent scaffolding. All classes use the `EMS\` namespace root via PSR-4 Composer autoload.

### Existing (Foundations)

| File                                            | Class / Interface                           | Status |
| -------------------------------------------------| ---------------------------------------------| --------|
| `src/Plugin.php`                                | `EMS\Plugin`                                | ✅      |
| `src/Core/CPT_Registry.php`                     | `EMS\Core\CPT_Registry`                     | ✅      |
| `src/Core/Table_Installer.php`                  | `EMS\Core\Table_Installer`                  | ✅      |
| `src/Integrations/OSM_API_Client.php`           | `EMS\Integrations\OSM_API_Client`           | ✅      |
| `src/Integrations/OSM_Auth_Integration.php`     | `EMS\Integrations\OSM_Auth_Integration`     | ✅      |
| `src/Integrations/OSM_Parser.php`               | `EMS\Integrations\OSM_Parser`               | ✅      |
| `src/Integrations/TutorLMS_Client.php`          | `EMS\Integrations\TutorLMS_Client`          | ✅      |
| `src/Integrations/Drivers/Driver_Interface.php` | `EMS\Integrations\Drivers\Driver_Interface` | ✅      |
| `src/Integrations/Drivers/Live_Driver.php`      | `EMS\Integrations\Drivers\Live_Driver`      | ✅      |
| `src/Integrations/Drivers/Mock_Driver.php`      | `EMS\Integrations\Drivers\Mock_Driver`      | ✅      |
| `src/Auth/Auth_Provider.php`                    | `EMS\Auth\Auth_Provider` *(interface)*      | ✅      |
| `src/Auth/LoginWithGoogle_Auth_Provider.php`    | `EMS\Auth\LoginWithGoogle_Auth_Provider`    | ✅      |
| `src/Admin/Training_Report_Page.php`            | `EMS\Admin\Training_Report_Page`            | ✅      |
| `src/Admin/Admin_Page.php`                      | `EMS\Admin\Admin_Page`                      | ✅      |
| `src/Admin/Settings_Page.php`                   | `EMS\Admin\Settings_Page`                   | ✅      |
| `src/Admin/Diagnostic_Panel.php`                | `EMS\Admin\Diagnostic_Panel`                | ✅      |
| `src/Admin/Reconciliation_Controller.php`       | `EMS\Admin\Reconciliation_Controller`       | ✅      |
| `src/Integrations/Gravity_Forms_Client.php`     | `EMS\Integrations\Gravity_Forms_Client`     | ✅      |

### To Be Built (Phases 1–6)

| File                                           | Class / Interface                          | Phase |
| ------------------------------------------------| --------------------------------------------| -------|
| `src/Data/Expedition_Repository.php`           | `EMS\Data\Expedition_Repository`           | 1.1   |
| `src/Data/Team_Repository.php`                 | `EMS\Data\Team_Repository`                 | 1.1   |
| `src/Data/Team_Member_Repository.php`          | `EMS\Data\Team_Member_Repository`          | 1.1   |
| `src/Admin/OSM_Sync_Auth_Handler.php`          | `EMS\Admin\OSM_Sync_Auth_Handler`          | 1.2   |
| `src/Integrations/OSM_Section_Importer.php`    | `EMS\Integrations\OSM_Section_Importer`    | 1.3   |
| `src/Integrations/Flexi_Structure_Parser.php`  | `EMS\Integrations\Flexi_Structure_Parser`  | 1.4   |
| `src/Integrations/Flexi_Column_Map.php`        | `EMS\Integrations\Flexi_Column_Map`        | 1.4   |
| `src/Integrations/Flexi_Record_Importer.php`   | `EMS\Integrations\Flexi_Record_Importer`   | 1.5   |
| `src/Admin/Admin_View_Controller.php`          | `EMS\Admin\Admin_View_Controller`          | 1.6   |
| `src/REST/Expedition_REST_Controller.php`      | `EMS\REST\Expedition_REST_Controller`      | 1.6   |
| `src/Admin/Expedition_Admin_Controller.php`    | `EMS\Admin\Expedition_Admin_Controller`    | 1.7   |
| `src/Integrations/GF_Signup_Processor.php`     | `EMS\Integrations\GF_Signup_Processor`     | 3.1   |
| `src/Data/Volunteer_Repository.php`            | `EMS\Data\Volunteer_Repository`            | 4.1   |
| `src/Frontend/Volunteer_Portal_Controller.php` | `EMS\Frontend\Volunteer_Portal_Controller` | 4.2   |
| `src/Admin/Volunteer_Admin_Controller.php`     | `EMS\Admin\Volunteer_Admin_Controller`     | 4.3   |
| `src/REST/Volunteer_REST_Controller.php`       | `EMS\REST\Volunteer_REST_Controller`       | 4.1   |
| `src/Frontend/Explorer_Portal_Controller.php`  | `EMS\Frontend\Explorer_Portal_Controller`  | 2.1   |
| `src/Frontend/Parent_Portal_Controller.php`    | `EMS\Frontend\Parent_Portal_Controller`    | 2.3   |
| `src/Data/Route_Submission_Repository.php`     | `EMS\Data\Route_Submission_Repository`     | 5.1   |
| `src/Admin/Route_Review_Controller.php`        | `EMS\Admin\Route_Review_Controller`        | 5.3   |
| `src/Frontend/Route_Submit_Controller.php`     | `EMS\Frontend\Route_Submit_Controller`     | 5.2   |
| `src/REST/Route_REST_Controller.php`           | `EMS\REST\Route_REST_Controller`           | 5.1   |
| `src/Integrations/OSM_Pushback_Service.php`    | `EMS\Integrations\OSM_Pushback_Service`    | 6.2   |

Test files mirror the `src/` structure under `tests/Unit/` (e.g. `src/Integrations/OSM_API_Client.php` → `tests/Unit/Integrations/OSM_API_ClientTest.php`). Mock JSON payloads live in `tests/mocks/`:
- `tests/mocks/osm-get-data-payload.json` — `getDataPayload` (Startup API) response ✅
- `tests/mocks/osm-events.json` — OSM Events list ✅
- `tests/mocks/osm-flexi-records.json` — OSM Flexi-record structure ✅
- `tests/mocks/osm-section-participants.json` — Section participant list *(Phase 1.1)*

---

## 7. Agent Technical Reference

Precise implementation anchors for agents working on Phase 1. Use this section to resolve any ambiguity in the stage TDD tasks above. Cross-reference `docs/Data Schema and API.md` for full table schemas and WP Options structure.

### 7.1 Coding Conventions

**Namespaces & Files**
- Root PSR-4 autoload: `EMS\` → `src/`, `EMS\Tests\` → `tests/` (see `composer.json`).
- Class naming: words separated by underscores, each capitalised — e.g. `OSM_Section_Importer`.
- File path mirrors namespace under `src/`: `EMS\Integrations\OSM_Section_Importer` → `src/Integrations/OSM_Section_Importer.php`.
- Test file mirrors `src/` under `tests/Unit/`: `src/Integrations/OSM_Section_Importer.php` → `tests/Unit/Integrations/OSM_Section_ImporterTest.php`.
- Test namespace: `EMS\Tests\Unit\<Subsystem>\`.

**PHP Test Anatomy**

All PHP tests extend `EMS\Tests\EMSTestCase` (`tests/EMSTestCase.php`). `EMSTestCase`:
- Calls `Brain\Monkey\setUp()` / `tearDown()` around every test.
- Pre-stubs `delete_transient` and `get_transient`.
- Additional WP functions stubbed per test with `Brain\Monkey\Functions\when('func_name')->justReturn(...)`.
- Use `Mockery::mock(Interface::class)` for interface mocks; call `\Mockery::close()` in `tearDown()`.

```php
namespace EMS\Tests\Unit\Data;

use EMS\Data\Expedition_Repository;
use EMS\Tests\EMSTestCase;
use Brain\Monkey\Functions;

class Expedition_RepositoryTest extends EMSTestCase {
    protected function tearDown(): void {
        \Mockery::close();
        parent::tearDown();
    }
    public function test_example(): void { /* ... */ }
}
```

### 7.2 WP User Meta Keys — Authoritative List

> **Note**: `docs/Data Schema and API.md §2` has naming discrepancies from the live code. The keys below are authoritative.

**Written by `OSM_Auth_Integration` (foundations — already in place):**

| Key | Type | Description |
| --- | --- | --- |
| `ems_osm_id` | int | OSM `user_id` (OIDC account identifier) |
| `ems_access_type` | string | `'parent'` \| `'member'` \| `'local'` |
| `ems_scout_ids` | int[] | OSM `member_id` list for this login (serialized array) |
| `ems_section_ids` | int[] | OSM section IDs this user administers (serialized array) |
| `ems_children` | array | Explorer records linked to a parent account |
| `ems_unit` | string | Patrol/unit name from OSM |

**Written by `OSM_Section_Importer` (Stage 1.3 — new, one row per imported WP User):**

| Key | Type | Description |
| --- | --- | --- |
| `ems_scout_id` | int | OSM `member_id` for this user (singular — primary lookup key) |
| `ems_first_name` | string | From OSM member record |
| `ems_last_name` | string | From OSM member record |
| `ems_explorer_email` | string | Explorer's personal email from OSM |
| `ems_parent_email` | string | Parent email from OSM (empty string if absent) |

### 7.3 `OSM_API_Client` Public Surface

`src/Integrations/OSM_API_Client.php` currently exposes:

```php
get_data_payload(string $access_token): array
get_section_participants(int $section_id): array   // wraps driver→parse_members()
get_section_events(int $section_id): array          // wraps driver→parse_events()
get_flexi_record_data(int $section_id, int $flexi_id): array
```

**Missing — add as first step of Stage 1.4:**
```php
get_flexi_record_structure(int $section_id, int $flexi_id): array
```
This method exists on `Driver_Interface` and `Mock_Driver` but has not yet been added to `OSM_API_Client`. Add it with the same rate-limiter/delegate pattern as the existing methods.

### 7.4 Mock Driver File Registry

`Mock_Driver` loads fixed filenames from `tests/mocks/`. The filenames below are what the driver **actually** loads — use these when creating mock payloads, not the aspirational names sometimes referenced in TDD task descriptions.

| `Driver_Interface` method | Actual mock filename | Status |
| --- | --- | --- |
| `get_section_members()` | `members.json` | ✅ Already exists |
| `get_flexi_records()` | `osm-flexi-records.json` | ✅ Already exists |
| `get_flexi_record_structure()` | `osm-flexi-record-structure.json` | Create in Stage 1.4 |
| `get_flexi_record_data()` | `osm-flexi-record-data.json` | Create in Stage 1.5 |
| `get_data_payload()` | `osm-get-data-payload-explorer.json` | ✅ Already exists |

### 7.5 Stage 1.2 OAuth Implementation Notes

- **OSM URLs**: Authorization endpoint and token exchange URL are defined in `docs/OSM Oauth.md`. Do not hardcode them; read that document when implementing.
- **Redirect URI**: `admin_url('admin-post.php?action=ems_osm_callback')`
- **Callback registration**: `add_action('admin_post_ems_osm_callback', [$handler, 'handle_callback'])`
- **State param**: generate with `wp_create_nonce('ems_osm_sync')`; verify in `handle_callback()` with `wp_verify_nonce($state, 'ems_osm_sync')`.
- **Token lifecycle**: The access token obtained from OSM must **not** be persisted. `handle_callback()` passes it directly to the registered sync callback (e.g. `$this->sync_callback($token, $section_ids)`) and then it is discarded. The sync callback is responsible for constructing an `OSM_API_Client` with the token for the duration of the import.

### 7.6 REST Endpoint Registration Pattern

All Phase 1 admin endpoints are registered on `rest_api_init` and require `manage_options`:

```php
add_action('rest_api_init', function () {
    register_rest_route('ems/v1', '/expedition-board', [
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => [$this, 'handle_get'],
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
});
```

Phase 1 admin REST paths (from `docs/Data Schema and API.md §3.3`): `/reconciliation`, `/sync-osm`, `/expedition-board`, `/update-team`.

### 7.7 `Table_Installer` Status

`src/Core/Table_Installer.php` already creates all three custom tables on plugin activation via `dbDelta()`. **No changes to `Table_Installer` are required in Phase 1.** The actual SQL columns (for reference when writing repository tests) are:

**`ems_team_members`**: `id` (BIGINT PK), `team_post_id` (BIGINT), `user_id` (BIGINT), `added_by` (BIGINT), `added_at` (DATETIME). Indexes on `team_post_id` and `user_id`.

**`ems_volunteer_availability`**: `id`, `user_id`, `expedition_post_id`, `date` (DATE), `overnight` (TINYINT), `confirmed` (TINYINT), `confirmed_by` (nullable BIGINT).

**`ems_route_submissions`**: `id`, `team_post_id`, `version` (INT), `file_type` (VARCHAR 20), `wp_media_id`, `submitted_by`, `submitted_at` (DATETIME), `feedback` (TEXT nullable), `status` (VARCHAR 30, default `'pending'`).

### 7.8 WP Options Written in Phase 1

| Option key | Type | Written by | Description |
| --- | --- | --- | --- |
| `ems_managed_sections` | serialized array | Admin Settings | Section IDs + config (see `docs/Data Schema and API.md §5.1`) |
| `ems_flexirecord_column_map` | serialized array | Stage 1.4 `Flexi_Column_Map` | Maps EMS field names to OSM `f_N` column IDs |
| `ems_osm_last_sync` | string (ISO 8601 UTC) | Stage 1.5 commit step | Timestamp of last successful OSM sync; `null` / absent if never synced |
