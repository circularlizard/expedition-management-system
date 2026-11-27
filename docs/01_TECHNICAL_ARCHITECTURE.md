# Technical Architecture

## 1. System Overview & Objectives

The **Expedition Management System (EMS)** is a WordPress-based operational platform for managing Duke of Edinburgh's Award (DofE) Bronze, Silver, and Gold expeditions for Explorer Scouts. It coordinates participant sign-ups, expedition event scheduling, team formation, volunteer staffing, route card planning and approval, and automated communications across participants, parents, unit leaders, Leaders in Charge (LICs), and district administrators.

### Core Objectives

- **Single Operational Hub:** Consolidate sign-up processing, expedition team allocation, route planning, and volunteer staffing into a unified `wp-admin` and front-end portal experience.
- **Identity & Data Integrity:** Anchor expedition team and cohort memberships on immutable signup IDs (`expedition_signup_id`) paired with Online Scout Manager (OSM) `scout_id` values once matched, preventing collisions for unmatched explorers (`scout_id = 0`).
- **Automated Team & Cohort Management:** Support practice/qualifier cohort pairing, wishlist preference parsing, and simulated-annealing team proposals while preserving human review and manual overrides.
- **Controlled External Sync:** Keep OSM as the external system of record for member profiles and event attendance, while isolating external integrations behind anti-corruption adapters.

### External System-of-Record Boundaries

| External System | Role & Boundary | Integration Mechanism |
| :--- | :--- | :--- |
| **Online Scout Manager (OSM)** | Authoritative source for Explorer profiles (`scout_id`), managed sections, events, and event attendance. Supports controlled event pushback from EMS. | OAuth2 + `OSM_API_Client` (`Live_Driver` / `Mock_Driver`) with rate limiting and `Pushback_Sync_Manager`. OIDC login via external `oauth-login` plugin. |
| **Scouts Membership System** | Not directly integrated with EMS; membership governance remains external to the plugin. | None (external process). |
| **Fluent Forms (Forms 6 & 7)** | Public intake forms for Participant Sign-up (Form 6) and Expedition Sign-up (Form 7), including email OTP verification. | `Fluent_Forms_Sync` hooks and `FluentFormsAdapter` anti-corruption layer. |
| **Tutor LMS** | Training course delivery and completion tracking for Explorers. | `TutorLmsAdapter` (`TrainingComplianceInterface`) and `TutorLMS_Client`, plus `Access_Control_Guard` route protection. |
| **Elementor / WordPress Block Editor** | Page layout host for front-end portals and sign-up banners. | Standard WordPress shortcodes embedded in pages, Elementor layouts, or block templates (no custom Elementor widgets). |

> **Retired Integrations:** OSM Patrols (`ems_unit_patrols`) and OSM Flexi-Records / Column Mapper (`ems_flexirecord_column_map`) have been retired. Legacy admin URLs for these features redirect to `ems-units-sync&tab=units`.

---

## 2. Monorepo & Plugin Architecture

The project is organized as an npm workspaces monorepo (`seee-expedition-monorepo`):

- **`plugins/ems-plugin/`** — The unified WordPress plugin (`EMS`, v1.0.49, PHP `>=8.2`, requires `oauth-login` plugin).
  - PSR-4 namespaces: `EMS\` (`src/`) and `EMSRoute\` (`src/Modules/RouteEngine/`).
  - PHP dependencies: `dompdf/dompdf` for Route Card PDF generation.
  - Frontend stack: React 18, TypeScript, Vite, Vitest, Leaflet, `proj4` / `proj4leaflet`, and FontAwesome.
- **`packages/contracts/`** — Shared TypeScript DTO contracts (`@seee/contracts`) consumed by the admin SPAs, portal, and route studio.
- **`tools/`** — Monorepo root `tools/` is reserved/empty; the automated team solver is implemented in PHP (`EMS\Modules\Expeditions\Services\TeamSolverService`), and `plugins/ems-plugin/tools/osm-tester.php` provides a plugin-level OSM diagnostic utility.

### Module Lifecycle & Feature Flags

`Plugin` bootstraps `Legacy_Aliases`, `CPT_Registry`, `Module_Registry`, and `Backup_Manager`, then registers seven domain modules implementing `ModuleInterface`:

| Module | Slug | `isAlwaysOn` | Responsibilities |
| :--- | :--- | :--- | :--- |
| `SignupsModule` | `signups` | `false` | Sign-up ingestion, reconciliation, chasers, Explorer 360° views. |
| `ExpeditionsModule` | `expeditions` | `false` | Events, teams, cohorts, readiness, offers, preferences, team solver. Implements `BackupContributorInterface`. |
| `VolunteersModule` | `volunteers` | `false` | Volunteer registry, availability, and event staffing console. |
| `LeaderDashboardModule` | `leader_dashboard` | `false` | Unit/leader management and front-end leader dashboard endpoints. |
| `PortalModule` | `portal` | `false` | Explorer/parent front-end portal and offer response handling. |
| `NotificationsModule` | `notifications` | `false` | Email template registry, test dispatch, and log rotation hook. |
| `RouteEngineModule` | `route_engine` | `false` | Route Card Studio, GPX/DEM elevation analysis, PDF export, and `EmsRouteBridgeAdapter`. |

`Module_Registry::boot_all()` checks `Feature_Flags::is_enabled($slug, $isAlwaysOn)` for each module, then invokes `register()` and `boot()` inside isolated `try/catch (\Throwable)` blocks. Any module failure is recorded in `$degraded_modules` and logged via `Audit_Logger` (`module_boot_failed:<slug>`) without crashing the rest of the plugin.

`Feature_Flags::is_enabled()` evaluates:
1. Always-on flag (`true` short-circuits).
2. PHP constants `EMS_DISABLE_MODULE_<UPPER_SNAKE_SLUG>` or `EMS_DISABLE_MODULE_<UPPER_SLUG>`.
3. `ems_feature_flags` WordPress option array.
4. Defaults to `true`.

### `Event_Bus` Hooks

`Event_Bus` wraps WordPress `do_action()` and `add_action()` with exception isolation and `Audit_Logger` error reporting (`event_dispatch_failed:<event>`):

| Event Name | Dispatcher | Consumers / Purpose |
| :--- | :--- | :--- |
| `ems.signup.form_submitted` | `FluentFormsAdapter::handleSubmission()` | `NotificationsModule::on_signup_form_submitted()` (hooked for post-submission notification flows). |
| `ems.expedition.offers_dispatched` | `ExpeditionOfferDispatcher` | Signals completion of batch expedition place offer dispatches. |
| `ems.signup.processed` | `SignupStatusTransitionService` | Dispatched on signup status/kanban transitions. |
| `ems.signup.status_changed` | `SignupStatusTransitionService` | Dispatched alongside `ems.signup.processed` with old/new stage metadata. |
| `ems.signups.chaser_sent` | `Signup_Chaser_Controller` | Signals that a missing-data or unpaid chaser email was sent. |

### Anti-Corruption Adapters

- **`FluentFormsAdapter`:** Listens to `fluentform/submission_inserted` for configured participant and expedition form IDs (defaults `6` and `7`), normalizes raw form payloads into immutable `ParticipationSignupDTO` and `ExpeditionPreferenceDTO` objects, and dispatches `ems.signup.form_submitted`.
- **`TutorLmsAdapter`:** Implements `TrainingComplianceInterface`. Resolves WP user IDs from `scout_id` via `ExplorerUserResolver` and queries Tutor LMS enrollment/completion meta, returning `TrainingStatusDTO::unavailable()` when Tutor LMS is inactive.
- **`EmsRouteBridgeAdapter`:** Hooks `ems_route_engine/context_provider` and `ems_get_route_card_context` to hydrate RouteEngine with live expedition, team, and explorer roster data from `Expedition_Repository`, `Team_Repository`, `Team_Member_Repository`, and `ems_osm_explorers`.

---

## 3. Security, Authentication & Authorization Model

### Authentication Flows

1. **OSM OIDC Login (`OIDC_Login_Handler`):**
   - Integrates with the external `oauth-login` plugin (`oauth.login_user_logged_in`, `oauth.login_user_created`).
   - Captures the transient OAuth access token via `http_response`, fetches the user's OSM profile payload (`globals`, `member_access`, `scout_ids`, `section_ids`, `children`), persists user meta (`ems_access_type`, `ems_scout_ids`, `ems_section_ids`, `ems_children`, `ems_osm_id`, `ems_unit`), and immediately discards the OAuth access token.
   - Maps `access_type` to WordPress roles (`member` -> `ems_explorer`, `network_member` -> `ems_network_member`, `parent` -> `ems_parent`, `leader`/`local`/non-empty `section_ids` -> `ems_leader`). Unknown access types terminate via `wp_die()`.
2. **Local WordPress Login:**
   - Used by WordPress `administrator` accounts and invited `ems_leader` accounts (via password-reset onboarding links).
   - `Unit_Repository` hooks `login_redirect`, `wp_redirect`, `after_password_reset`, and `admin_init` to keep `ems_leader` users on the front-end Leader Dashboard rather than `wp-admin`.

### Custom Roles & Capabilities

| Role | Capabilities | Primary Portal / Access |
| :--- | :--- | :--- |
| `ems_parent` | `read`, `access_ems_parent_portal` | `[ems-portal]` (linked children), `[ems_signup_banner]` |
| `ems_explorer` | `read`, `access_ems_explorer_portal` | `[ems-portal]` (own expeditions/training/routes), Tutor LMS courses |
| `ems_network_member` | `read`, `access_ems_explorer_portal` | `[ems-portal]`, `[ems_signup_banner]` |
| `ems_leader` | `read`, `edit_posts`, `access_ems_leader_portal` | `[ems-leader-dashboard]` |
| `administrator` | Full WordPress `manage_options` | All `wp-admin` hubs, REST admin endpoints, and admin portal preview |

### Personas & Access Enforcement

The system serves eight operational personas: **Unauthenticated Visitor**, **Parent / Guardian**, **Explorer**, **Network Member**, **Unit Leader**, **Leader in Charge (LIC)**, **Volunteer / Assessor**, and **Administrator**.

- **Current Enforcement:** Authorization is enforced via controller `permission_callback` methods (`manage_options` for admin endpoints; `access_ems_leader_portal || manage_options` for leader dashboard; `is_user_logged_in` plus inline `ExplorerUserResolver` parent/scout ownership checks for portal endpoints; `edit_others_posts` / `ems_user_can_review_route_cards` filter for route card reviews), `Access_Control_Guard` (protecting Tutor LMS routes for `ems_explorer` + `administrator` and custom `ems_page_roles` mappings), and the `[ems_signup_banner]` role gate (`administrator`, `ems_parent`, `ems_network_member`).
- **Planned Policy Refactor:** Dedicated standalone policy classes (`TeamAccessPolicy`, `UnitScopePolicy`, `RouteCardAccessPolicy`) are planned to centralize team, unit-scoped, and route-card authorization checks currently handled inline in controllers.

---

## 4. Background Processing, Deadlines & Notifications

### WP-Cron Jobs

| Cron Hook | Schedule | Handler | Purpose |
| :--- | :--- | :--- | :--- |
| `ems_daily_deadline_check` | Daily | `DeadlineReminderCron` | Evaluates `place_offer` and `route_card` communicated deadlines; sends `3d` (1–3 days prior) and `0d` (due date) reminders with `ems_notification_logs.dedupe_key` deduplication. |
| `ems_daily_log_cleanup` | Daily | `Log_Rotator` | Purges `ems_audit_logs` older than 365 days and caps the table at 50,000 rows. |
| `ems_run_osm_sync` | Single-event | `Plugin` (`OSM_Reference_Sync`) | Spawned asynchronously after live OSM OAuth callback to sync reference data without blocking the HTTP redirect. |

### Two-Tier Deadline Policy (`DeadlinePolicyService`)

Stored in `wp_options` (`ems_deadline_policies`) for `place_offer` and `route_card`:
- **Soft / Communicated Deadline (`communicated_date`):** Displayed to participants/leaders and used by `DeadlineReminderCron` to trigger `3d` and `0d` reminders.
- **Hard Cutoff (`hard_cutoff_date`) & Rolling Grace (`grace_days`, default `7`):** Enforced by `Portal_Controller` when responding to offers. If `allocated_at + grace_days` extends beyond `hard_cutoff_date`, the effective cutoff extends to that rolling grace date.

### Email & Notification Matrix

Templates are managed in `Email_Template_Registry` (with TinyMCE editing and test-send support in `ems-emails`) and all automated/batch dispatches are recorded in `wp_ems_notification_logs`:

| Template / Slug | Trigger | Audience | Notes |
| :--- | :--- | :--- | :--- |
| `leader_invite` | Manual / Admin | Unit Leaders | Sends Leader Dashboard onboarding/password-set link. |
| `osm_share_request` | Manual / Admin | Unit Leaders | Requests OSM section sharing permissions for EMS sync. |
| `unpaired_chase` | Manual / Chaser | Parents / Explorers | Chases sign-ups not yet matched to an OSM `scout_id`. |
| `fluent_otp` | Real-time AJAX | Form Applicants | One-time verification code during Fluent Forms sign-up. |
| `expedition_place_offer` | Batch Dispatch | Explorers & Parents | Dispatched from Team Readiness / Modal with `notification_status` tracking. |
| `place_offer_reminder` | `DeadlineReminderCron` / Batch | Explorers & Parents | Automated `3d` / `0d` reminders or manual compliance reminders. |
| `missing_form7` / `missing_edofe` | Chaser Controller | Parents / Explorers | Targeted chasers for missing Expedition Sign-up (Form 7) or eDofE registration. |

---

## 5. Backup, Portability & Dev/Deploy Workflow

### Backup & Portability

- **`Backup_Manager`:** Exports and imports `ems_*` options, core `DEFAULT_TABLES`, `DEFAULT_CPTS` (`expedition`, `team`), and module-contributed tables/CPTs/options via `BackupContributorInterface` (e.g., `ExpeditionsModule` contributes `ems_cohorts`, `ems_cohort_members`, `ems_teammate_preferences`). Accessible via REST (`/ems/v1/backups/export`, `/ems/v1/backups/import`, `/ems/v1/system/parity-manifest`) and WP-CLI (`wp ems backup export|import|parity`).
- **`Portability_Engine`:** Provides whitelisted JSON export/import (`export_data`, `import_data`, `export_units`, `import_units`) for configuration and unit records, stripping deprecated `patrol_id` fields from legacy unit exports during import.

### Local Verification & Deployment

From `./expedition-management-system/`:

- **Full Verification Gate:** `npm run verify:all` runs:
  1. `npm run test` (Vitest workspace tests)
  2. `npm run build` (Vite production bundle + `@seee/contracts`)
  3. `npm run test:php` (`phpunit` in `plugins/ems-plugin`)
  4. `npm run stan` (`phpstan analyse --memory-limit=2G` in `plugins/ems-plugin`)
- **Local Docker Deploy:** `npm run deploy` (or `bash bin/deploy.sh ems [--skip-build]`) builds `plugins/ems-plugin`, installs Composer dependencies, `rsync`s plugin files into the WordPress container (excluding `tests/`, `node_modules/`, `docs/`, `scratch/`, `mockdata/`), deactivates/removes any retired `ems-route-card-engine` plugin, and activates `ems-plugin`.
