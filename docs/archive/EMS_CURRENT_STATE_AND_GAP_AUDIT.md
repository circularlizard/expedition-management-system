> [!NOTE]
> **LEGACY / SUPERSEDED:** Point-in-time gap audit snapshot (2026-11-18) superseded by the canonical docs in `docs/`. Preserved for historical reference.

# EMS Current State & Gap Audit

**Baseline Date:** 2026-11-18  
**Target Spec:** [`expedition_planning.md`](file:///Users/davidstrachan/Projects/EMS/expedition_planning.md)  
**Inspected Codebase:** `expedition-management-system/` (`plugins/ems-plugin`, `packages/contracts`)  
**Audit Scope:** Strictly read-only discovery and reconciliation. No application code, database schemas, or planning documents were modified.

---

## Executive Summary & Architectural Verdict

The Expedition Management System (`ems-plugin` v1.0.29, PHP 8.2+) is an active, feature-rich WordPress plugin monorepo into which the Route Card Engine (`EMSRoute\`) has already been merged as `RouteEngineModule`. Core operational pipelines—Fluent Forms ingestion (Form 6 & Form 7), OSM OAuth2 sync & pushback, cohort-based Practice/Qualifier scheduling, staged Place Offer email dispatch, Tutor LMS + First Aid readiness evaluation, and interactive DEM/GPX route card generation—are substantially built.

However, independent code verification reveals **five structural fault lines** between [`expedition_planning.md`](file:///Users/davidstrachan/Projects/EMS/expedition_planning.md) and the live runtime:

1. **Split-Brain Entities & Naming Drift:**
   - `ems_teams` does **not** exist. The codebase uses a hybrid of the legacy `team` CPT (`CPT_Registry.php:22-35`), `ems_team_members`, and `ems_cohorts` / `ems_cohort_members` (`Table_Installer.php:665-701`).
   - Route cards are split across two disconnected tables/repositories: `wp_dofe_route_cards` (`Route_Card_Repository.php:35`, interactive JSON cards) and `wp_ems_route_submissions` (`Route_Submission_Repository.php:25`, legacy media attachments read by `TeamReadinessService.php:437`).
   - `ems_scout_id` (singular) vs `ems_scout_ids` (plural) causes broken user lookups in `TutorLmsAdapter::queryCourseProgress()` (`TutorLmsAdapter.php:59-64`), `TeamReadinessService::hasLinkedWordPressUser()` (`TeamReadinessService.php:543-550`), and `Team_Member_Repository::list_unassigned()` (`Team_Member_Repository.php:178-213`).
2. **Unwired Bridges & Dead Event Listeners:**
   - `EmsRouteBridgeAdapter` (`src/Integrations/RouteEngine/EmsRouteBridgeAdapter.php`) and `FluentFormsAdapter` (`src/Integrations/FluentForms/FluentFormsAdapter.php`) are **never instantiated** in `src/`. As a result, `RouteEngine` falls back to standalone default context rather than live EMS expedition/team rosters, and `NotificationsModule::on_signup_form_submitted()` (`NotificationsModule.php:36-38`) is an empty stub listening to an event never fired by `Fluent_Forms_Sync`.
   - `ems_route_card_submitted`, `ems_route_card_reviewed`, `ems.signups.chaser_sent`, and `ems.expedition.offers_dispatched` have **zero listeners** anywhere in `src/`.
3. **Missing Portal & Workflow Enforcement:**
   - **No Place Offer Accept / Query / Decline** endpoints or UI exist in `Portal_Controller.php` or `resources/js/portal/index.tsx`, and the `'31 Dec'` deadline is only an email display token.
   - **14-day Route Card cutoff is not enforced** in `Route_Card_Controller.php` (only displayed as a countdown in `TeamReadinessService.php:459-468`), and LiC review `$feedback` is **not persisted** (`wp_dofe_route_cards` has no `feedback` column).
   - **Zero automated notification crons** exist (only `ems_daily_log_cleanup` and `ems_run_osm_sync`); 11 of the 15 notification scenarios in `expedition_planning.md` are missing.
   - **No embedded Route Planner in `[ems-portal]`**, **no Teams/Expeditions tab in `[ems-leader-dashboard]`**, **no LiC operational view**, and **no Path Finder / public hub shortcodes**.
4. **REST Authorization Gap in Route Engine:**
   - 8 of 9 endpoints in `Route_Card_Controller` (`src/Modules/RouteEngine/Core/REST/Route_Card_Controller.php:119-121`) use `check_authenticated_permission()` (`is_user_logged_in() || current_user_can('edit_posts')`) with **no team-membership or ownership check**, allowing any logged-in user to read, modify, or submit any team's route cards.
5. **UI / Tech-Stack Fragmentation:**
   - Admin screens mix React 18 SPAs (10 Vite entry points), pure server-rendered PHP pages, and hybrid PHP+React pages (`ems-reference`), with duplicated menus (`ems-explorers` vs `ems-reference&tab=explorers`), an orphaned hidden menu (`ems-column-mapper`), dead tab/save handlers, two monolithic CSS files (4,873 + 3,533 lines), 785 inline style occurrences, three icon systems (FontAwesome 7, Dashicons, Unicode entities), and phantom `wp-components` / `wp-element` dependencies.

---

## 1. Repository Architecture, Modules & Active Integrations

### 1.1 Monorepo & Plugin Layout
- **Workspaces (`package.json:4-7`):** `packages/*` (`packages/contracts`) and `plugins/*` (`plugins/ems-plugin`).
- **Single Plugin (`plugins/ems-plugin/ems-plugin.php:1-10, 49-62`):** Version `1.0.29`, requires PHP `8.2` and `oauth-login` (`Circularlizard\OAuthLogin\Plugin`).
- **PSR-4 Namespaces (`plugins/ems-plugin/composer.json:6-11`):**
  - `EMS\` → `src/`
  - `EMSRoute\` → `src/Modules/RouteEngine/`
- **Core Infrastructure (`src/Core/`):** `Access_Control_Guard.php`, `Audit_Logger.php`, `Backup_Manager.php`, `CPT_Registry.php`, `Encryption.php`, `Event_Bus.php`, `Feature_Flags.php`, `Legacy_Aliases.php`, `Log_Rotator.php`, `Module_Registry.php`, `Portability_Engine.php`, `Role_Manager.php`, `Shortcode_Boundary.php`, `Table_Installer.php`.

### 1.2 Module Registry, Feature Flags, Event Bus & Backups
- **7 Registered Modules (`src/Plugin.php:28-34`):**
  1. `SignupsModule` (`signups`, `src/Modules/Signups/SignupsModule.php:9-15`)
  2. `ExpeditionsModule` (`expeditions`, `src/Modules/Expeditions/ExpeditionsModule.php:9-15, 46-51` — implements `BackupContributorInterface`, contributing `ems_cohorts`, `ems_cohort_members`)
  3. `VolunteersModule` (`volunteers`, `src/Modules/Volunteers/VolunteersModule.php:8-14`)
  4. `LeaderDashboardModule` (`leader_dashboard`, `src/Modules/LeaderDashboard/LeaderDashboardModule.php:8-14`)
  5. `PortalModule` (`portal`, `src/Modules/Portal/PortalModule.php:8-14`)
  6. `NotificationsModule` (`notifications`, `src/Modules/Notifications/NotificationsModule.php:9-15`)
  7. `RouteEngineModule` (`route_engine`, `src/Modules/RouteEngine/RouteEngineModule.php:14-20`)
- **`Module_Registry` (`src/Core/Module_Registry.php:27-57`):** Checks `Feature_Flags::is_enabled($slug, $module->isAlwaysOn())`, then executes `register()` and `boot()` inside `try/catch (\Throwable)`, logging failures via `Audit_Logger::log('module_boot_failed:' . $slug)`.
- **`Feature_Flags` (`src/Core/Feature_Flags.php:13-35`):** Supports `EMS_DISABLE_MODULE_<SLUG>` constants and `get_option('ems_feature_flags', [])` (defaults to `true`). Note: The planning doc's `enable_chaser_cron`, `enable_kanban_board`, and `enable_place_offers` flags do not exist.
- **`Event_Bus` (`src/Core/Event_Bus.php:4-44`):** Exception-safe wrapper around `do_action` and `add_action`.
- **`Backup_Manager` (`src/Core/Backup_Manager.php:6-513`):** Exports/imports JSON across `DEFAULT_TABLES` (`ems_osm_explorers`, `ems_units`, `ems_unit_patrols`, `ems_participant_signups`, `ems_expedition_signups`, `ems_team_members`, `ems_volunteers`, `ems_volunteer_availability`, `ems_route_submissions`, `ems_audit_logs`), `DEFAULT_CPTS` (`expedition`, `team`), and `BackupContributorInterface` additions (`ems_cohorts`, `ems_cohort_members`). Includes MD5 parity manifest (`generate_parity_manifest()`, lines `290-345`) and WP-CLI `ems backup` (`lines 427-435`). *(Note: `dofe_route_cards` is not in `DEFAULT_TABLES` or `RouteEngineModule` backup contributions.)*

### 1.3 Active Integrations & Boundaries
- **OSM API & OAuth2:**
  - `OSM_API_Client` (`src/Integrations/OSM_API_Client.php:6-228`) wraps `Live_Driver` / `Mock_Driver` (`ems_api_mode` option, `src/Plugin.php:119-121`), `Rate_Limiter`, and `OSM_Sync_Logger`.
  - **OIDC Login (Tokenless, `OIDC_Login_Handler.php:6-185`):** Hooks `oauth.login_user_logged_in`, `oauth.login_user_created`, and `http_response` to capture the one-time token, call `get_data_payload()`, hydrate user meta (`ems_access_type`, `ems_scout_ids`, `ems_section_ids`, `ems_children`), assign WP roles, and immediately discard the token (`line 118`).
  - **Admin Sync & Pushback (Token-Cached, `OSM_Sync_Auth_Handler.php:9-267`):** Caches encrypted OAuth2 token in `_ems_osm_token` / `_ems_osm_token_expires` user meta (cleared on `wp_logout`, `Plugin.php:607-612`), validates JWT scopes (`section:event:write`, `section:flexirecord:write`), and powers `Pushback_Sync_Manager` (`src/Integrations/Pushback_Sync_Manager.php:7-511`) for `'2026 Expeditions'` flexi-record `multiUpdate` and `/v3/events/event/{event_id}/members/attendance/updateMany` (`invited`).
- **Fluent Forms (`src/Integrations/Fluent_Forms_Sync.php:5-1358`):**
  - Wired in `Plugin.php:129-130` for Form 6 (`ems_fluent_participant_form_id`, default `6`) and Form 7 (`ems_fluent_expedition_form_id`, default `7`).
  - Handles child dropdown population, OTP verification (`send_fluent_otp` / `verify_fluent_otp`), validation, `fluentform/submission_inserted`, and `fluentform/after_payment_status_change`.
  - **Unwired Adapter:** `FluentFormsAdapter` (`src/Integrations/FluentForms/FluentFormsAdapter.php:10-191`) is never instantiated anywhere in `src/`.
- **Tutor LMS:**
  - `TutorLMS_Client` (`src/Integrations/TutorLMS_Client.php:4-497`) powers `Training_Report_Page`, `Admin_View_Controller`, and `Portal_Controller`.
  - `TutorLmsAdapter` (`src/Integrations/TutorLms/TutorLmsAdapter.php:9-172`, implements `TrainingComplianceInterface`) powers `TeamReadinessService` (`TeamReadinessService.php:48, 533`).
- **Route Card Engine (`src/Modules/RouteEngine/`):**
  - Registered as `RouteEngineModule` (`src/Plugin.php:34`).
  - `Context_Manager::getContext()` (`RouteEngine/Core/Context/Context_Manager.php:20-34`) applies `'ems_get_route_card_context'` and falls back to `getDefaultStandaloneContext()`.
  - **Unwired Adapter:** `EmsRouteBridgeAdapter` (`src/Integrations/RouteEngine/EmsRouteBridgeAdapter.php:10-183`) hooks `'ems_get_route_card_context'`, but is **never instantiated or registered** in `Plugin.php` or `RouteEngineModule.php`.
  - **Dead Legacy Entrypoint:** `EMSRoute\Core\Plugin::init()` (`RouteEngine/Core/Plugin.php:13`) is never called (`RouteEngineModule.php:44` only calls `render_studio_shortcode`).

---

## 2. Data Model, Schema, Join Anchors & State Lifecycles

### 2.1 Custom SQL Tables (15 Tables)
Defined in `src/Core/Table_Installer.php:413-725` (and `dofe_route_cards` duplicated in `src/Modules/RouteEngine/Core/Table_Installer.php:14-33`).

| Table (`{$prefix}...`) | Primary Key | Key Columns & Foreign/Join Anchors | Indexes | Source (`file:line`) |
|---|---|---|---|---|
| `ems_team_members` | `id` | `team_post_id`, `scout_id`, `user_id`, `expedition_signup_id`, `cohort_id`, `availability_override`, `notification_status`, `notified_at`, `notified_by`, `added_by`, `added_at` | `idx_team_post_id`, `idx_scout_id`, `idx_user_id`, `idx_expedition_signup`, `idx_cohort` | `Table_Installer.php:416-435` |
| `ems_cohorts` | `id` | `name`, `dofe_level`, `transport_mode`, `practice_expedition_id`, `qualifier_expedition_id`, `practice_team_post_id`, `qualifier_team_post_id`, `cohort_status`, `notes`, `created_by` | `idx_level_mode`, `idx_practice_exp`, `idx_qualifier_exp` | `Table_Installer.php:665-683` |
| `ems_cohort_members` | `id` | `cohort_id`, `expedition_signup_id`, `scout_id`, `availability_override`, `member_status`, `notes`, `added_by` | `uniq_cohort_signup (cohort_id, expedition_signup_id)`, `idx_cohort`, `idx_signup`, `idx_scout` | `Table_Installer.php:685-701` |
| `ems_participant_signups` | `id` | `scout_id`, `parent_user_id`, `unit_id`, `unit_name`, `explorer_*`, `parent_email`, `leader_email`, `dofe_level`, `dob`, `dofe_registered`, `dofe_number`, `dofe_org`, `bronze_completion`, `silver_completion`, `signup_status`, `payment_status`, `processed_by`, `processed_at`, `form_submission_id` | `idx_scout_id`, `idx_parent_user_id`, `idx_unit_id` | `Table_Installer.php:586-615` |
| `ems_expedition_signups` | `id` | `scout_id`, `parent_user_id`, `unit_id`, `unit_name`, `explorer_*`, `parent_email`, `leader_email`, `dofe_level`, `dob`, `dofe_registered`, `dofe_number`, `expedition_preferences`, `additional_support_needs`, `asn_validated`, `first_aid_status`, `first_aid_expiry`, `signup_status`, `form_submission_id` | `idx_scout_id`, `idx_parent_user_id`, `idx_unit_id` | `Table_Installer.php:617-645` |
| `ems_volunteers` | `id` | `osm_user_id`, `user_id`, `first_name`, `last_name`, `email`, `phone`, `dbs_number`, `qualifications`, `preferred_roles`, `constraints` | `idx_email`, `idx_osm_user_id`, `idx_user_id` | `Table_Installer.php:437-455` |
| `ems_volunteer_availability` | `id` | `volunteer_id`, `user_id` (deprecated for guests), `expedition_post_id`, `date`, `overnight`, `confirmed`, `confirmed_by`, `signup_type` | `idx_volunteer_expedition`, `idx_user_expedition`, `idx_date` | `Table_Installer.php:457-472` |
| `ems_route_submissions` | `id` | `team_post_id`, `version`, `file_type`, `wp_media_id`, `submitted_by`, `submitted_at`, `feedback`, `status` | `idx_team_post_id` | `Table_Installer.php:474-486` |
| `dofe_route_cards` | `id` | `expedition_id`, `team_id`, `day_number`, `route_title`, `header_data`, `route_data`, `status`, `approved_by`, `approved_at`, `pdf_file_path` | `exp_team_day (expedition_id, team_id, day_number)` | `Table_Installer.php:647-663` |
| `ems_osm_explorers` | `id` | `scout_id` (UNIQUE), `wp_user_id`, `section_id`, `first_name`, `last_name`, `email`, `parent_email`, `email1/2`, `p1_email1/2`, `p2_email1/2`, `patrol`, `first_aid_level`, `dofe_number`, `additional_support_needs`, `last_local_update_at`, `last_ems_push_at`, `synced_at` | `UNIQUE idx_scout_id`, `idx_section_id`, `idx_wp_user_id` | `Table_Installer.php:488-514` |
| `ems_osm_events` | `id` | `event_id`, `section_id`, `name`, `start_date`, `end_date`, `location`, `yes_members`, `yes_leaders`, `no`, `synced_at` | `UNIQUE idx_event_section`, `idx_section_id` | `Table_Installer.php:529-544` |
| `ems_osm_event_attendance` | `id` | `event_id`, `scout_id`, `status`, `synced_at` | `UNIQUE idx_event_scout`, `idx_event_id`, `idx_scout_id` | `Table_Installer.php:546-556` |
| `ems_units` | `id` | `unit_id`, `district`, `name`, `short_code`, `leader_email` | `UNIQUE idx_unit_id`, `UNIQUE idx_short_code` | `Table_Installer.php:558-570` |
| `ems_unit_patrols` | `id` | `unit_id`, `section_id`, `patrol_id`, `name`, `active`, `synced_at` | `UNIQUE idx_patrol_section`, `idx_unit_id` | `Table_Installer.php:572-583` |
| `ems_audit_logs` | `id` | `user_id`, `action`, `target_scout_id`, `ip_address`, `user_agent`, `timestamp` | `idx_user_id`, `idx_target_scout_id` | `Table_Installer.php:516-527` |

*(Note: `ems_teams` and `ems_route_cards` do not exist; `ems_unit_leaders` was dropped in `Table_Installer.php:410`.)*

### 2.2 Custom Post Types, `team` CPT Status & Schema Drift
- **`expedition` CPT (`CPT_Registry.php:6-20, 38-114`):** Active. Models a single event weekend.
- **`team` CPT (`CPT_Registry.php:22-35, 116-140`):** Still registered (`show_in_menu => false`) and actively used as the stage-specific team anchor (`post_parent` = `expedition` post ID). `ems_cohorts` stores `practice_team_post_id` and `qualifier_team_post_id` pointing to `team` CPT posts (`CohortRepository.php:39, 53-74`), while `ems_team_members.team_post_id`, `ems_route_submissions.team_post_id`, `dofe_route_cards.team_id`, and `Portal_Controller.php:564, 594` all key off `team` CPT IDs.
- **`season` CPT:** Deleted and detached in `Table_Installer::migrate_season_deprecation()` (`Table_Installer.php:210-243`), yet `src/Data/Season_Repository.php:1-118` remains and is still imported in `Expedition_Admin_Controller.php:4`.
- **Undeclared `post_meta` Drift:** `_ems_cohort_id` (`CohortSchedulerService.php:406`, `TeamReadinessService.php:116`), `ems_training_requirements` (`Admin_View_Controller.php:384`, `Portal_Controller.php:632`), `ems_first_aid_level` and `ems_osm_event_url` (`Portal_Controller.php:612, 617`) are read/written without registration in `CPT_Registry.php`. Additionally, `ems_team_number` is written as `0` for `UNALLOCATED` teams (`Table_Installer.php:275`), violating `CPT_Registry.php:122-125` (`'minimum' => 1`).

### 2.3 Split-Brain Identities & Join Anchors
- **Primary Anchor (`scout_id`):** Anchors `ems_osm_explorers` (UNIQUE), `ems_participant_signups`, `ems_expedition_signups`, `ems_cohort_members`, `ems_team_members`, `ems_osm_event_attendance`, and `ems_audit_logs`. `0` denotes an unmatched/guest signup.
- **Broken `ems_scout_id` (Singular) vs `ems_scout_ids` (Plural) Lookups:**
  - `OIDC_Login_Handler.php:110` writes `ems_scout_ids` (plural array), which `Portal_Controller.php:120` and `Fluent_Forms_Sync.php:1038` read.
  - However, `TutorLmsAdapter::queryCourseProgress()` (`TutorLmsAdapter.php:59-64`), `TeamReadinessService::hasLinkedWordPressUser()` (`TeamReadinessService.php:543-550`), and `Team_Member_Repository::list_unassigned()` (`Team_Member_Repository.php:178-213`, which also queries `WHERE team_post_id = %d AND user_id = %d` at `line 193`) query singular `ems_scout_id`.

### 2.4 State Lifecycles
1. **Sign-ups:** `signup_status` = `'submitted'` → `'archived'` (`Signup_Repository.php:288-317`); `payment_status` = `'pending'` → `'paid'` (`Fluent_Forms_Sync.php:764`); `asn_validated` = `NULL | 0 | 1`; OSM reconciliation = `scout_id = 0` → `scout_id > 0` (`Signup_Repository.php:322`).
2. **Cohorts & Members:** `ems_cohorts.cohort_status` = `'forming'` → `'partially_scheduled'` | `'scheduled'` (`CohortSchedulerService.php:267-287`); `ems_cohort_members.member_status` = `'active'` | `'split_practice'` | `'split_qualifier'` | `'removed'` | `'inactive'` | `'declined'` (`CohortSchedulerService.php:596-607`).
3. **Place Offers:** `ems_team_members.notification_status` = `'draft'` → `'notified'` (`ExpeditionOfferDispatcher.php:847, 861`) → `'re_notify_needed'` (`CohortSchedulerService.php:510-511`); `availability_override` = `NULL | 'date_conflict_pending'`. **Missing:** No `acceptance_status` or Accept/Query/Decline states exist.
4. **Route Cards & Submissions:**
   - `dofe_route_cards.status`: `'draft'` → `'submitted'` → `'approved'` | `'changes_requested'` (`Route_Card_Repository.php:209-235`).
   - `ems_route_submissions.status`: `'pending'` | `'approved'` | `'rejected'` | `'changes_requested'` (`Route_Submission_Repository.php:7, 66, 163-172`).
   - `team` CPT `ems_route_status`: `'pending'` | `'feedback_required'` | `'approved'` (`CPT_Registry.php:126-129`).
5. **Chasers:** Persisted in `wp_options` (`ems_chaser_request_history`) rather than a SQL table (`Signup_Chaser_Controller.php:17, 329-332`).
6. **Volunteers:** `ems_volunteer_availability.confirmed` = `0` (unconfirmed) → `1` (confirmed) or `-1` (auto-locked out due to date conflict, `Volunteer_Repository.php:141, 156, 188`).

---

## 3. REST API Inventory & Security / Permission Audit

Three REST namespaces are registered across `src/`:

### 3.1 `ems/v1` (72 Routes)
- **System & Backups:**
  - `GET /sync-status` (`Plugin.php:183-214`, `manage_options`)
  - `GET /backups/export`, `POST /backups/import`, `GET /system/parity-manifest` (`Backup_Manager.php:351-389`, `manage_options`)
  - `GET /audit-logs` (`Audit_Log_Controller.php:7-20`, `manage_options`)
  - `GET /flexi-structure`, `GET|POST /flexi-column-map`, `GET /flexi-review`, `POST /flexi-commit` (`Flexi_Mapper_Controller.php:35-108`, `manage_options`)
- **Units, Sign-ups & Chasers:**
  - `GET /unit-leaders`, `EDITABLE /unit-leaders/(?P<id>\d+)` (`Unit_Leader_Controller.php:15-44`, `manage_options`)
  - `GET /explorer/(?P<scout_id>\d+)`, `GET /team/(?P<team_id>\d+)`, `GET /patrol/(?P<patrol>[^/]+)`, `GET|POST /events/(?P<id>\d+)/training-requirements` (`Admin_View_Controller.php:38-112`, `manage_options`)
  - `GET /signups/chaser-history`, `POST /signups/send-chaser` (`Signup_Chaser_Controller.php:51-69`, `manage_options`)
- **Portal & Leader Dashboard (`Portal_Controller.php:31-74, 151-153`):**
  - `GET /portal/me` (`__return_true` — returns `{logged_in: false}` if unauthenticated, `lines 207-215`)
  - `GET /portal/explorer/(?P<scout_id>\d+)` (`is_user_logged_in`)
  - `GET /leader-dashboard/signups` (`access_ems_leader_portal || manage_options`)
  - `GET /portal/admin/preview-targets` (`manage_options`)
- **Volunteers (`Volunteer_Controller.php:13-93`):**
  - `POST /volunteers/signup` (`__return_true` — public, strips `user_id`/`osm_user_id` at `line 104`)
  - `GET /volunteers/events` (`__return_true` — public read of event titles/codes/dates)
  - `GET /volunteers`, `POST /volunteers/assign`, `POST /volunteers/save`, `POST /volunteers/availability` (`manage_options`)
- **Email Templates (`Email_Templates_Controller.php:26-64`):**
  - `GET /email-templates`, `PUT /email-templates/(?P<slug>[a-z0-9_-]+)`, `POST .../reset`, `POST .../test` (`manage_options`)
- **Cohorts & Place Offers (`CohortController.php:46-190`):**
  - `GET|POST /cohorts`, `EDITABLE|DELETE /cohorts/(?P<id>\d+)`, `POST /cohorts/(?P<id>\d+)/merge`, `POST /cohorts/(?P<id>\d+)/members`, `DELETE /cohorts/(?P<id>\d+)/members/(?P<expedition_signup_id>\d+)`, `GET|POST /cohorts/(?P<id>\d+)/schedule`, `POST /expeditions/notifications/preview`, `POST /expeditions/notifications/dispatch`, `GET /expeditions/teams-readiness` (all `manage_options`)
- **Expeditions, Planning Board & Signups (`Expedition_Admin_Controller.php:50-344, 875-1176`):**
  - 34 routes covering `/events`, `/teams`, `/explorers`, `/expedition-board`, `/osm-events`, `/planning-board/*`, `/signups/*` (all `manage_options`).

### 3.2 `ems/v1/admin` (3 Routes)
- `GET /sync-preview`, `POST /sync-push`, `GET|DELETE /sync-status` (`Sync_Preview_Controller.php:18-58`, `manage_options`).

### 3.3 `ems-route/v1` (14 Routes)
- `GET|POST /settings` (`Settings_Controller.php:18-46`, `manage_options`)
- `GET /dem/tiles`, `POST /dem/upload`, `DELETE /dem/(?P<square>[a-zA-Z]{2})`, `POST /dem/benchmark/(?P<square>[a-zA-Z]{2})` (`DEM_Management_Controller.php:27-76`, `manage_options`)
- `POST /card/(?P<id>\d+)/review` (`Route_Card_Controller.php:74-80, 123-129`, `manage_options || apply_filters('ems_user_can_review_route_cards', current_user_can('edit_others_posts'), user_id)`)
- **Security Gap (`Route_Card_Controller.php:38-71, 83-121`):** The remaining 8 routes (`/upload-gpx`, `GET /card/{exp}/{team}/{day}`, `POST /card/{id}/save`, `POST /card/{id}/submit`, and all 4 PDF routes) use `check_authenticated_permission()` (`is_user_logged_in() || current_user_can('edit_posts')`) with **no team-membership or ownership check**.

---

## 4. Information Architecture, Tech Stack & UX Inconsistencies

### 4.1 `wp-admin` Menu Hierarchy (`Menu_Manager.php:39-69`)
All 10 visible submenus and 1 hidden page require `manage_options`:

| Menu | Slug | Renderer / Tech | Tabs / Sub-views | Source (`file:line`) |
|---|---|---|---|---|
| **Signups** (top `ems`) | `ems` | React SPA (`signups-board.js`) | `dashboard`, `table`, `unmatched_osm`, `unpaired_signups` | `Admin_Page.php:14-31, 154-161` |
| **Expeditions** | `ems-expeditions` | React SPA (`expedition-board.js`) | `dashboard`, `planning`, `readiness` + `detail`, `create` | `Admin_Page.php:33-40, 289-299` |
| **Explorers** | `ems-explorers` | React SPA (`expedition-board.js`) | `<ExplorersPage />` | `Admin_Page.php:58-76, 147-152` |
| **Units & Leaders** | `ems-units` | PHP Server-Rendered + inline JS | `units`, `leaders`, `patrols` (dead `?tab=email` redirects) | `Unit_Leader_Page.php:16-23, 112-150` |
| **Volunteers** | `ems-volunteers` | React SPA (`volunteers.js`) | `staffing`, `registry` | `Admin_Page.php:163-190, 716-721` |
| **Route Studio** | `ems-route-studio` | React SPA (`route-card-studio.js`) | `sandbox`, `dem_manager`, `settings` | `Admin_Studio_Controller.php:15-24, 69-74` |
| **OSM Sync** | `ems-reference` | Hybrid PHP + React | `explorers` (React), `patrols`, `events`, `sections`, `pushback` (React), `diagnostics` | `Admin_Page.php:195-213, 304-425` |
| **Email Templates** | `ems-email-templates` | React SPA (`email-templates.js`) + WP TinyMCE | Master-detail list | `Admin_Page.php:81-125` |
| **Settings** | `ems-settings` | PHP Server-Rendered | `general`, `connection`, `form_mappings`, `backups`, `audit_logs`, `access_control`, `help` | `Settings_Page.php:17-24, 151-229` |
| **Training Report** | `ems-training-report` | PHP Server-Rendered + inline CSS/JS | Paginated matrix + CSV export + diagnostic | `Training_Report_Page.php:22-33, 53-248` |
| *(Hidden)* **Column Mapper** | `ems-column-mapper` | React SPA (`column-mapper.js`) | Flexi-record mapper | `Admin_Page.php:218-236, 723-728` |

### 4.2 Front-End Shortcodes & Vite Entry Points
- **5 Registered Shortcodes:**
  1. `[ems-portal]` (`Plugin.php:43, 790-825`) → `ems-portal.js` (`training`, `practice`, `qualifying` tabs + `<AdminPreviewBar />`)
  2. `[ems-leader-dashboard]` (`Plugin.php:44, 709-751`) → `leader-dashboard.js` (`explorers`, `summary` tabs)
  3. `[ems-volunteer-signup]` (`Plugin.php:42, 753-788`) → `volunteer-signup.js` (4-step wizard)
  4. `[ems_signup_banner]` (`Plugin.php:45, 827-890`) → PHP HTML + inline CSS + `fluent-forms-sync.js`
  5. `[ems_route_card_studio]` (`RouteEngineModule.php:44`) → `route-card-studio.js` (hides admin tabs when `isShortcode` is true)
- **10 Vite Entry Points (`vite.config.ts:26-37`):** `column-mapper`, `expedition-board`, `signups-board`, `email-templates`, `volunteers`, `volunteer-signup`, `ems-portal`, `leader-dashboard`, `fluent-forms-sync`, `route-card-studio`.

### 4.3 Tech Stack & UX Inconsistencies
- **Duplicated & Orphaned Screens:** `ems-explorers` and `ems-reference&tab=explorers` mount the exact same `<ExplorersPage />` into `#ems-explorers-root` (`Admin_Page.php:147-152, 422-424`). `ems-column-mapper` (`null` parent) is never linked anywhere in `src/` or `resources/`. `Unit_Leader_Page::render_email_tab()` (`Unit_Leader_Page.php:907-975`) and `Settings_Page` `ems_save_leader_invite` / `ems_save_chaser_emails` handlers (`Settings_Page.php:131-134, 459-489`) are dead/legacy branches.
- **CSS Bloat & Frontend Leakage:** Two monolithic stylesheets (`resources/css/ems-admin.css` at 4,873 lines; `resources/css/ems-route-card.css` at 3,533 lines with hardcoded `.elementor-page-15340`/`.elementor-page-15510` rules), 528 React `style={{...}}` and 257 PHP `style="..."` inline styles, and `ems-admin.css` + WP `.button` classes leaked onto public shortcodes (`Plugin.php:694, 699, 703, 861`, `signup-wizard.tsx:226, 315-316`).
- **Phantom `@wordpress/*` Dependencies:** PHP enqueues `wp-components`, `wp-element`, and `wp-i18n` (`Admin_Page.php:281, 768`, `Settings_Page.php:34`, `Unit_Leader_Page.php:41`, `Plugin.php:658-796`), yet zero `@wordpress/*` packages are imported in `resources/js/**` (Vite bundles React 18 directly).
- **Mixed State, Error Boundaries & Icons:** Zero global state or query libraries (all `useState` + raw `fetch()` with manual `X-WP-Nonce`); `email-templates/index.tsx` and `route-card/standalone/index.tsx` omit `<ModuleErrorBoundary>`; icons mix FontAwesome 7 (React), WP Dashicons (PHP), and Unicode HTML entities (`Training_Report_Page.php:127, 307-325`).

---

## 5. Notifications, Cron Jobs & Operational Workflows

### 5.1 Email Dispatch, Templates & Cron Inventory
- **`wp_mail()` Call Sites:** `Email_Template_Service::send()` (`Email_Template_Service.php:233` — used by OTP and `Signup_Chaser_Controller`), `Email_Templates_Controller::send_test_email()` (`Email_Templates_Controller.php:193`), `ExpeditionOfferDispatcher::dispatchBatch()` (`ExpeditionOfferDispatcher.php:262` — direct `wp_mail()` to append `Cc: <parent_email>`), and `Unit_Repository::send_leader_invitation_notification()` (`Unit_Repository.php:892` via `wp_new_user_notification()`).
- **5 Registered Templates (`Email_Template_Registry.php:70-337`):** `leader_invite`, `osm_share_request`, `unpaired_chase`, `fluent_otp`, `expedition_place_offer`.
- **2 WP-Cron Jobs (Zero Email Cron):** `ems_daily_log_cleanup` (`Log_Rotator.php:48`) and `ems_run_osm_sync` (one-shot, `Plugin.php:314, 430`).

### 5.2 Operational Process Audit
1. **Form 6 & Form 7 Ingestion + OSM Reconciliation:** Functional via `Fluent_Forms_Sync` (`scout_id` lookup/backfill on submit, manual reconciliation via `POST /ems/v1/signups/reconcile`, manual unit chasers). *Gap:* `OSM_Reference_Sync.php` does not auto-reconcile `scout_id = 0` signups during OSM syncs.
2. **Unit Leader Onboarding & Dashboard:** Functional (`Unit_Repository` creates `ems_leader` user, sends `leader_invite`, auto-logs in on password reset, and redirects out of `wp-admin` to `[ems-leader-dashboard]`).
3. **Team Formation, Scheduling & Place Offers:** Cohort builder, scoring, and staged manual preview/dispatch work (`CohortSchedulerService` never auto-emails). *Critical Gap:* No Accept/Query/Decline endpoints or 31st Dec cutoff enforcement.
4. **Training Compliance:** `TutorLmsAdapter` and `TeamReadinessService` evaluate Tutor LMS + First Aid expiry (`>= 2` valid first aiders). *Bug:* `TutorLmsAdapter` and `TeamReadinessService::hasLinkedWordPressUser()` query singular `ems_scout_id` instead of `ems_scout_ids`.
5. **Route Card Creation, Submission & LiC Review:** Interactive DEM/Naismith/GPX builder and PDF generator work. *Critical Gaps:* 14-day cutoff not enforced in `Route_Card_Controller`, review `$feedback` not persisted, zero notification listeners on `ems_route_card_submitted`/`ems_route_card_reviewed`, and readiness checks `ems_route_submissions` instead of `dofe_route_cards`.
6. **Volunteer Registration & Assignment:** Public signup, DBS storage, and double-booking conflict lock (`confirmed = -1`) work. *Gaps:* No DBS UI in admin registry (`volunteers/index.tsx:544-550`) and no volunteer emails.

---

## 6. Complete Gap Matrix vs `expedition_planning.md`

### 6.1 Modules

| Spec Item (`expedition_planning.md`) | Status | Evidence (`file:line`) |
|---|---|---|
| **EMS Admin Dashboard (`ems-plugin`)** | | |
| Sign-up: Fluent Forms (Form 6 & Form 7 sync) | `[IMPLEMENTED]` | `Fluent_Forms_Sync.php`, `Plugin.php:129-130`, `Settings_Page.php:491-657` |
| Sign-up: Kanban & reconciliation board (5 statuses, eDofE verification) | `[PARTIAL / DRIFTED]` | `SignupsBoard.tsx:49` uses `dashboard\|table\|unmatched_osm\|unpaired_signups` tabs & `submitted\|archived` statuses (`Table_Installer.php:184-185`) |
| Sign-up: Data consistency & duplicate detection (`scout_id = 0`) | `[IMPLEMENTED]` | `SignupsDashboard.tsx:25, 283, 419`, `dashboardMetrics.ts:57, 665-677` |
| Sign-up: Automated & manual chasers (missing eDofE, OSM, training) | `[PARTIAL / DRIFTED]` | Manual `osm_share_request` & `unpaired_chase` in `Signup_Chaser_Controller.php:51-69`; no automated cron or eDofE/training chasers |
| Expedition: Event setup (dates, stage, level, transport, LiC, vols, route) | `[IMPLEMENTED]` | `CPT_Registry.php:38-114`, `EventForm.tsx`, `EventDetailPage.tsx` |
| Expedition: Team builder (cross-stage continuity, overrides) | `[IMPLEMENTED]` | `CohortPlanningMatrix.tsx`, `CohortController.php:51-182`, `CohortSchedulerService.php` |
| Expedition: Place offer notifications (staged preview & manual dispatch) | `[IMPLEMENTED]` | `ExpeditionOfferDispatcher.php:10-58`, `ManualNotifyModal.tsx:17, 107` |
| Expedition: Acceptances, queries & date-change requests (31st Dec cutoff) | `[PARTIAL / DRIFTED]` | `'31 Dec'` exists only as email token (`Email_Template_Registry.php:23, 325`); no portal endpoints or UI |
| Expedition: Teams & Readiness view (4–7 size, Rule of Two, Tutor LMS, route) | `[IMPLEMENTED]` | `TeamReadinessService.php:22, 48, 428-475`, `TeamsReadinessView.tsx` |
| Expedition: Route card submissions & LiC approval overview | `[PARTIAL / DRIFTED]` | `TeamReadinessTable.tsx:316-317` shows status; `Route_Card_Controller.php:73-80` handles review; no unified LiC overview in Expedition Board |
| Volunteer: Requirements & coverage overview per expedition | `[IMPLEMENTED]` | `volunteers/index.tsx:311-346, 406-491`, `EventRosterPanel.tsx` |
| Volunteer: Volunteer sign-ups (`[ems-volunteer-signup]`) | `[IMPLEMENTED]` | `Plugin.php:42, 753-788`, `volunteers/signup-wizard.tsx` |
| Volunteer: Assignments, DBS tracking & overnight/day coverage | `[PARTIAL / DRIFTED]` | Assignments/coverage implemented (`Volunteer_Controller.php`); `dbs_number` column exists (`Table_Installer.php:445`) but no DBS UI (`volunteers/index.tsx:544-550`) |
| Units & OSM: Connection settings & tokenless OAuth2 sync | `[IMPLEMENTED]` | `Settings_Page.php:328-397`, `OSM_Sync_Auth_Handler.php`, `OIDC_Login_Handler.php` |
| Units & OSM: Section & patrol mapping to Explorer Scout Units | `[IMPLEMENTED]` | `Admin_Page.php:392-393`, `Unit_Leader_Page.php:722-896`, `Table_Installer.php:558-583` |
| Units & OSM: Explorers cache (`scout_id`) & confirmed attendance push | `[IMPLEMENTED]` | `Table_Installer.php:488-514`, `Pushback_Sync_Manager.php:7-60`, `PushbackDashboard.tsx` |
| Units & OSM: DofE leader assignments, bulk invites & login status | `[IMPLEMENTED]` | `Unit_Leader_Page.php:569-711, 1276-1350` |
| Email: FluentSMTP integration & 12-month email archive | `[PARTIAL / DRIFTED]` | Links out to FluentSMTP (`UnitChaserView.tsx:421`); no built-in 12-month archive |
| Email: Template management (`{{token}}` placeholders) | `[IMPLEMENTED]` | `Email_Template_Registry.php`, `Email_Template_Service.php`, `EmailTemplatesApp.tsx` |
| Email: Manual dispatch queue & cron chaser toggles | `[MISSING]` | Absent from `Email_Templates_Controller.php` and `Settings_Page.php` |
| System: Access control guard, OIDC login linking & role permissions | `[IMPLEMENTED]` | `Access_Control_Guard.php`, `OIDC_Login_Handler.php`, `Role_Manager.php`, `Settings_Page.php:982-1193` |
| System: Feature flags (`enable_chaser_cron`, `enable_kanban_board`, `enable_place_offers`) | `[PARTIAL / DRIFTED]` | `Feature_Flags.php:13-66` gates modules only (`portal`, `leader_dashboard`, `volunteers`, `route_engine`); named flags & UI absent |
| System: Unified JSON backup, parity manifest & restore | `[IMPLEMENTED]` | `Backup_Manager.php`, `Portability_Engine.php`, `Settings_Page.php:907-980` |
| System: Security & reconciliation audit logs | `[IMPLEMENTED]` | `Audit_Logger.php`, `Audit_Log_Controller.php`, `Log_Rotator.php`, `Settings_Page.php:660-905` |
| **Route Card Engine (`ems-route-card-engine`)** | | |
| Standalone Studio (`Tools -> Route Card Engine`) & embedded portal/LiC modes | `[PARTIAL / DRIFTED]` | Merged as `EMS -> Route Studio` (`Admin_Studio_Controller.php:15-24`); `[ems_route_card_studio]` exists, but not embedded in portal/LiC |
| OS Terrain 50 DEM elevation reader & dynamic Naismith pacing engine | `[IMPLEMENTED]` | `OS_Terrain_Binary_Reader.php:16-44`, `DEM_Storage_Manager.php`, `Naismith_Calculator.php:17-45` |
| GPX upload/export & versioned JSON route storage | `[IMPLEMENTED]` | `GPX_Enrichment_Service.php`, `Route_Card_Controller.php:38-62`, `Table_Installer.php:647-663` |
| Decoupled DofE header & A4 landscape print engine | `[IMPLEMENTED]` | `Route_Card_PDF_Generator.php:1-45`, `Route_Card_Controller.php:82-115` |
| **Shortcode Integration with Website** | | |
| Public: Path Finder hero widget & public hubs (`/expedition-roadmap/`, etc.) | `[MISSING]` | Absent across `src/` and `resources/` |
| Public: Fluent Forms (Form 6 & Form 7) | `[IMPLEMENTED]` | `Fluent_Forms_Sync.php`, `Plugin.php:45, 827-890` (`[ems_signup_banner]`) |
| Public: Volunteer availability form (`[ems-volunteer-signup]`) | `[IMPLEMENTED]` | `Plugin.php:42, 753-788`, `volunteers/signup-wizard.tsx` |
| Public: Shortcode reference / help page | `[IMPLEMENTED]` | `Settings_Page.php:60-66, 1198-1448` |
| Portal (`[ems-portal]`): My sign-ups & OSM link status | `[IMPLEMENTED]` | `Plugin.php:43`, `Portal_Controller.php:31-49`, `portal/index.tsx:41-89` |
| Portal (`[ems-portal]`): My expeditions — dates, teammates, Accept/Query/Decline | `[PARTIAL / DRIFTED]` | Dates & teammates rendered (`portal/index.tsx:10-39`); Accept/Query/Decline absent |
| Portal (`[ems-portal]`): Training to-do, Tutor LMS progress & First Aid | `[IMPLEMENTED]` | `Portal_Controller.php:19`, `portal/index.tsx:61-82` |
| Portal (`[ems-portal]`): Embedded Route Planner (create, co-edit, 14-day cutoff, LiC feedback) | `[MISSING]` | Absent from `resources/js/portal/` and `Portal_Controller.php` |
| Leader Dashboard (`[ems-leader-dashboard]`): Unit sign-ups & reconciliation (PII redacted) | `[IMPLEMENTED]` | `Plugin.php:44`, `Portal_Controller.php:51-59`, `LeaderDashboard.tsx:56-120` |
| Leader Dashboard (`[ems-leader-dashboard]`): Teams & expeditions with my unit's Explorers | `[MISSING]` | `LeaderDashboard.tsx:60` only has `explorers` and `summary` tabs |
| LiC / Volunteer View: My assigned expeditions & volunteer roster | `[MISSING]` | No frontend LiC operational view or shortcode exists |
| LiC / Volunteer View: Teams on my expedition & readiness audit | `[MISSING]` | Admin-only in `TeamsReadinessView.tsx` |
| LiC / Volunteer View: Route card inspection, inline annotation & approval workflow | `[PARTIAL / DRIFTED]` | Backend `/card/{id}/review` exists (`Route_Card_Controller.php:74-80`), but no frontend LiC UI or inline annotation |

### 6.2 Notifications

| Notification Item (`expedition_planning.md`) | Status | Evidence (`file:line`) |
|---|---|---|
| 1a. Sign-up: To Parent / Explorer — confirmation of sign-up | `[MISSING]` | Not in `Email_Template_Registry.php:70-337` |
| 1b. Sign-up: To DofE Leader — confirmation of sign-up | `[MISSING]` | Not in `Email_Template_Registry.php:70-337` |
| 1c. Sign-up: To Admin — sign-up processing or manual reconciliation needed | `[MISSING]` | Not in `Email_Template_Registry.php:70-337` |
| 2a. Consistency: To DofE Leader — inconsistencies or Explorers not shared with OSM | `[PARTIAL / DRIFTED]` | Manual `osm_share_request` & `unpaired_chase` (`Signup_Chaser_Controller.php:51-69`); no automated chaser |
| 2b. Consistency: To Explorer / Parents — missing eDofE or unlinked account chaser | `[MISSING]` | Not in `Email_Template_Registry.php:70-337` |
| 3. Unit Leader onboarding: To DofE Leader — portal invite + password setup link | `[IMPLEMENTED]` | `Email_Template_Registry.php:75-117` (`leader_invite`), `Unit_Repository.php:883-897` |
| 4a. Allocation: To Explorer / Parents — combined Practice & Qualifier place offer | `[IMPLEMENTED]` | `Email_Template_Registry.php:270-336` (`expedition_place_offer`), `ExpeditionOfferDispatcher.php:262` |
| 4b. Allocation: To Admin — expedition accepted, queried, or declined | `[MISSING]` | No acceptance workflow or email |
| 4c. Allocation: To DofE Leader / Admin — Explorer transferred between teams/levels | `[MISSING]` | No transfer notification |
| 5. Training allocation & reminders: To Explorer / Parents | `[MISSING]` | No template or cron |
| 6. Expedition update: To Explorer / Parents (LiC allocated, route info) | `[MISSING]` | No template or trigger |
| 7. Route cards due: To Explorer / Parents (14-day deadline approaching) | `[MISSING]` | No template or cron |
| 8a. Route card submitted: To Admins | `[MISSING]` | `ems_route_card_submitted` has 0 listeners (`Route_Card_Controller.php:275-281`) |
| 8b. Route card submitted: To ExpedLeader (LiC) | `[MISSING]` | `ems_route_card_submitted` has 0 listeners |
| 9a. Route card feedback: To Explorer / Parents — approved | `[MISSING]` | `ems_route_card_reviewed` has 0 listeners (`Route_Card_Controller.php:310`) |
| 9b. Route card feedback: To Explorer / Parents — revisions requested | `[MISSING]` | `ems_route_card_reviewed` has 0 listeners |

### 6.3 Entities

| Entity (`expedition_planning.md`) | Status | Evidence (`file:line`) |
|---|---|---|
| Participant Place Sign-up (`ems_participant_signups`) | `[IMPLEMENTED]` | `Table_Installer.php:586-615` |
| Expedition Sign-up (`ems_expedition_signups`) | `[IMPLEMENTED]` | `Table_Installer.php:617-645` |
| Explorer (`ems_osm_explorers` anchored on `scout_id`) | `[IMPLEMENTED]` | `Table_Installer.php:488-514` |
| Unit (`ems_units` & `ems_unit_patrols`) | `[IMPLEMENTED]` | `Table_Installer.php:558-583` |
| Expedition (`expedition` CPT) | `[IMPLEMENTED]` | `CPT_Registry.php:6-20`, `Expedition_Repository.php` |
| Team (`ems_teams` & `ems_team_members`) | `[PARTIAL / DRIFTED]` | `ems_teams` does not exist; uses `team` CPT (`CPT_Registry.php:22-35`) + `ems_cohorts` / `ems_cohort_members` (`Table_Installer.php:665-701`) + `ems_team_members` |
| Route Card (`ems_route_submissions`) | `[PARTIAL / DRIFTED]` | `ems_route_submissions` (`Table_Installer.php:474-486`) is legacy attachments; active cards are in `dofe_route_cards` (`Table_Installer.php:647-663`) |
| Volunteer (`ems_volunteers` & `ems_volunteer_availability`) | `[IMPLEMENTED]` | `Table_Installer.php:437-472` |
| Training Record (`TutorLmsAdapter` & OSM First Aid) | `[IMPLEMENTED]` | `TutorLmsAdapter.php:9`, `TeamReadinessService.php:48, 533` |
| Audit & Notification Log (`ems_audit_logs` & email dispatch archive) | `[PARTIAL / DRIFTED]` | `ems_audit_logs` implemented (`Table_Installer.php:516-527`); no email dispatch archive table |

### 6.4 `[UNPLANNED / LEGACY IN CODE]` (Present in Code, Omitted from `expedition_planning.md`)
- **Duplicated & Orphaned Menus:** `ems-explorers` (`Admin_Page.php:58-76`) duplicating `ems-reference&tab=explorers` (`Admin_Page.php:386-387`); hidden `ems-column-mapper` (`Admin_Page.php:218-236`).
- **Dead Tabs & Save Handlers:** `Unit_Leader_Page::render_email_tab()` (`Unit_Leader_Page.php:907-975`); legacy `Settings_Page` `ems_save_leader_invite` / `ems_save_chaser_emails` branches (`Settings_Page.php:120-138, 171-172, 459-489`).
- **Training Report Page (`ems-training-report`):** `Menu_Manager.php:65`, `Training_Report_Page.php:1-485`.
- **Legacy `Season_Repository` & `EMSRoute\Core\Plugin`:** `Season_Repository.php:1-118` (imported in `Expedition_Admin_Controller.php:4`); `Modules/RouteEngine/Core/Plugin.php:13`.
- **Unwired Adapters:** `FluentFormsAdapter.php:10`, `EmsRouteBridgeAdapter.php:10`.
- **Unlisted Shortcode & Template:** `[ems_signup_banner]` (`Plugin.php:45, 827-890`), `fluent_otp` template (`Email_Template_Registry.php:240-266`).

---

## 7. Prioritised Remediation & Consolidation Backlog

1. **P0 — Security & Data Integrity:**
   - Enforce team-membership / ownership / LiC authorization checks in `Route_Card_Controller::check_authenticated_permission()` (`Route_Card_Controller.php:119-121`) so logged-in users cannot access or mutate other teams' route cards.
   - Fix `ems_scout_id` (singular) vs `ems_scout_ids` (plural) lookups in `TutorLmsAdapter.php:59-64`, `TeamReadinessService.php:543-550`, and `Team_Member_Repository.php:178-213` (and stop querying `user_id` where `scout_id` is canonical).
   - Register `dofe_route_cards` in `Backup_Manager` (`RouteEngineModule` implementing `BackupContributorInterface`).
2. **P1 — Core Workflow & Bridge Wiring:**
   - Instantiate and wire `EmsRouteBridgeAdapter` in `RouteEngineModule` / `Plugin.php` so Route Studio and `[ems_route_card_studio]` receive live EMS expedition/team context.
   - Add `feedback` persistence to `dofe_route_cards` / `Route_Card_Repository::updateStatus()`, enforce the 14-day cutoff in `Route_Card_Controller::save_card()` / `submit_card()`, and unify `TeamReadinessService::evaluateRoutePlan()` with `dofe_route_cards` (deprecating `ems_route_submissions` or bridging both).
   - Implement Place Offer Accept / Query / Decline endpoints + `ems_team_members` response columns + 31st Dec cutoff enforcement in `Portal_Controller` and `portal/index.tsx`.
   - Wire listeners for `ems_route_card_submitted`, `ems_route_card_reviewed`, and `Fluent_Forms_Sync` sign-up confirmations/chasers into `NotificationsModule`, and decide whether to retire or adopt `FluentFormsAdapter`.
3. **P2 — UI/IA Consolidation & Tech Debt:**
   - Remove duplicate `ems-explorers` menu (or `ems-reference&tab=explorers`), link or remove `ems-column-mapper`, and delete dead `Unit_Leader_Page::render_email_tab()` and `Settings_Page` legacy save handlers.
   - Remove phantom `wp-components`, `wp-element`, and `wp-i18n` dependencies from PHP script/style enqueues; decouple frontend shortcodes from `ems-admin.css` and WP `.button` classes.
   - Build missing frontend views: embedded Route Planner in `[ems-portal]`, Teams & Expeditions tab in `[ems-leader-dashboard]`, LiC operational view, and Path Finder / public hub shortcodes.
