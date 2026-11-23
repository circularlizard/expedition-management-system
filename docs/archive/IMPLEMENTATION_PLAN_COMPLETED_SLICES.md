# Archived Completed Implementation Slices (1A, 1B, 2 & Pre-Slice 3)

This document archives the detailed task checklists for completed slices moved out of [03_IMPLEMENTATION_PLAN.md](../03_IMPLEMENTATION_PLAN.md).

---

## Slice 1A — Admin UI Consolidation, Sign-up Protection & Anomaly Triage (Completed)
*Goal: Cut the cluttered 11-page admin menu down to 7 clean hubs, eliminate duplicate/orphan screens and script bloat, lay down the additive DB columns/tables, and fix identity & anomaly detection before building the Sign-up Processing UI in Slice 1B.*

1. **[DONE]** **Admin Menu & Navigation Rationalisation (`Menu_Manager.php` + `Admin_Page.php`):**
   - Consolidate the 11 menus into the 7 clean domain hubs (`Sign-ups`, `Expeditions & Teams`, `Volunteers`, `Units & OSM Sync`, `Route Studio`, `Emails & Notifications`, `Settings & System`) with backward-compatible URL redirects from old slugs (`ems-explorers`, `ems-reference`, `ems-training-report`, `ems-units`, `ems-email-templates`, `ems-column-mapper`). `ExpeditionBoard.tsx` reads `?tab=readiness` (and `planning` / `team-builder` / `date-grid` / `event_id` / `level` / `transport`) from the URL.
   - Remove the duplicate `ems-explorers` page, surface `Flexi-Record Mapper` under `Units & OSM Sync`, fold `Training Report` into `Expeditions & Teams -> Teams & Readiness`, and delete dead `Unit_Leader_Page::render_email_tab()` and legacy `Settings_Page` email handlers. *(Cleanup note: unused `Training_Report_Page` and `Admin_Page::register_explorers_menu()` / `register_reference_menu()` / `register_mapper_menu()` methods remain in `src/` as dead code.)*
   - Strip phantom `wp-components`, `wp-element`, and `wp-i18n` dependencies from PHP enqueues in `Plugin.php` and admin pages.
2. **[DONE]** **Isolated Public Shortcode CSS Extraction (Reversible Step):**
   - Extract the CSS classes used by `[ems_signup_banner]`, `[ems-portal]`, `[ems-leader-dashboard]`, and `[ems-volunteer-signup]` out of `ems-admin.css` into `ems-portal.css` and dequeue `ems-admin.css` from public pages.
3. **[DONE]** **Additive Schema, Identity Fixes & Anomaly Chasers:**
   - Add additive `kanban_stage VARCHAR(24) NOT NULL DEFAULT 'to_process'` and `processing_notes TEXT NULL` to `ems_participant_signups` and `ems_expedition_signups` in `Table_Installer.php` (keeping `signup_status` untouched and independent), plus the `ems_notification_logs` table.
   - Fix `ems_scout_id` (singular) -> `ems_scout_ids` (plural) lookups via `ExplorerUserResolver`.
   - Keep OSM `scout_id = 0` matching **manual** in the Sign-ups hub.
   - Move/extend anomaly detection server-side (`SignupAnomalyService`, all 8 anomalies) and add `missing_form7` / `missing_edofe` manual chasers logged to `ems_notification_logs` in `Signup_Chaser_Controller.php`. `missing_edofe` and `unpaired_signups` / `osm_share` chasers are exposed in `UnitChaserView.tsx`, `SignupsBoard.tsx`, and `Explorer360Drawer.tsx`.

---

## Slice 1B — Sign-up Processing Workflow & Consolidated Anomalies (Completed)
*Goal: Provide Sign-up processing workflow and anomaly triage on top of the stabilised Slice 1A admin shell and schema.*

1. **[DONE]** **Ingestion & Repository Support (`Signup_Repository.php` + `Fluent_Forms_Sync.php`):**
   - Form 6/7 submissions write `signup_status = 'submitted'` and `kanban_stage = 'to_process'`.
   - Include `kanban_stage` and `processing_notes` in `Signup_Repository` queries and `GET /ems/v1/signups` responses (alongside server-side anomaly flags).
2. **[DONE]** **Processing Status Transition Endpoint (`Expedition_Admin_Controller.php`):**
   - Added `PATCH /ems/v1/signups/{id}/status` (`manage_options`) to transition `kanban_stage` and save `processing_notes`, keeping `signup_status` (`'submitted' | 'archived'`) untouched.
   - Enforced transition guards and wired `ems.signup.processed` / `ems.signup.status_changed` via `Event_Bus`.
3. **[DONE]** **Table-First Sign-up Processing & Consolidated Anomalies UI (`SignupsBoard.tsx` + `UnitChaserView.tsx` + `Explorer360Drawer.tsx`):**
   - Replaced the standalone `SignupsKanbanView.tsx` drag-and-drop view with a table-first processing status workflow (`new`, `place_allocated`, `team_allocated`, etc.), inline `participant_place_allocated` pairing, bulk stage updates, all 8 server-side anomaly filters/badges, and consolidated `UnitChaserView` with individual recipient selection.

---

## Slice 2 — Cross-Stage Team Formation, Two-Tier Deadlines & Manual Place Offers (Completed)
*Goal: Complete the Team Builder UI, add configurable two-tier deadlines, and support manual Place Offer dispatch + portal Accept / Query / Decline.*

1. **[DONE]** **Standardise on "Team" Terminology & Streamline `Expeditions & Teams` UI:**
   - Kept `ems_cohorts`, `ems_team_members`, `team` CPT, and `Pushback_Sync_Manager` storage intact under the hood while standardising all UI copy, tabs (`Events Dashboard`, `Team Builder`, `Availability & Date Grid`, `Teams & Readiness`), and dispatch flows on **"Team"** terminology (`ExpeditionBoard.tsx`, `CohortsContinuityView.tsx`, `StageMatrixView.tsx`, `TeamsReadinessView.tsx`, `TeamReadinessTable.tsx`, `TeamEmailDispatchModal.tsx`, `ManualNotifyModal.tsx`, and `[ems-portal]`).
   - Refined `Expeditions & Teams` for direct Event Team assembly, wishlist mutual-highlighting, availability & date grid scoring, and `availability_override` support (`TeamController.php`).
2. **[DONE]** **Configurable Two-Tier Deadlines (`DeadlinePolicyService`):**
   - Added `DeadlinePolicyService` (`ems_deadline_policies` option with `communicated_date`, `hard_cutoff_date`, and rolling `grace_days` for `place_offer` and `route_card`) and the **Settings & System -> Deadlines & Cutoffs** tab in `Settings_Page.php`.
3. **[DONE]** **Place Offer Dispatch, Portal Responses & OSM Pushback (`[ems-portal]`):**
   - Added `offer_status` (`pending | accepted | queried | declined`), `offer_query_message`, `offer_responded_at`, and `offer_responded_by` to `ems_team_members` in `Table_Installer.php`.
   - Implemented atomic `Accept / Query (with message) / Decline` in `Portal_Controller::respond_to_offer()` updating all `ems_team_members` rows sharing `expedition_signup_id` inside a DB transaction, enforcing the `place_offer` hard cutoff via `DeadlinePolicyService::evaluate_cutoff()`, and emailing `ems_offer_query_email` (falling back to `admin_email`) immediately on `Query`.
   - Wired `ExpeditionOfferDispatcher` to reset `offer_status = 'pending'`, `offer_query_message = NULL`, and `offer_responded_at = NULL` upon re-dispatch (`re_notify_needed`).
   - Updated `Pushback_Sync_Manager.php` to default to `tm.offer_status = 'accepted'` when syncing to OSM, with an `include_unaccepted` admin override checkbox in `PushbackDashboard.tsx`.
   - Supported Explorer, Parent, and **Networker** (`"Myself"` + linked children switcher) in `Portal_Controller.php` and `resources/js/portal/index.tsx`, keeping `ems/v1/portal/me` (`__return_true`) accessible for logged-out login prompt rendering.

---

## Pre-Slice 3 — Bug Fixes, Status Transitions, Explorer 360° & Portal Polish (Completed)
1. **[DONE]** **Allow `scout_id = 0` Explorers to Be Added to Expeditions / Teams:**
   - Updated `StageMatrixView.tsx`, `Expedition_Admin_Controller::allocate_planning_explorers()`, `Expedition_Admin_Controller::add_member()` (delegating `expedition_signup_ids` to `Rest\TeamController`), `Expedition_Admin_Controller::remove_member()`, and `Team_Member_Repository::remove_by_signup()`.
2. **[DONE]** **Structured Sign-up Status Transitions (`SignupStatusTransitionService`):**
   - Created `SignupStatusTransitionService` (`src/Modules/Signups/Services/SignupStatusTransitionService.php`) as the single source of truth for valid stages and cross-entity transitions, wired into `Expedition_Admin_Controller` and `TeamController` (`add_team_members`, `remove_team_member`, `copy_team_to_event`, `delete_team`), with unit tests in `SignupStatusTransitionServiceTest.php`.
3. **[DONE]** **Explorer 360° Integration & Offer History:**
   - Wired `Explorer360Drawer` into Team Builder (`CohortsContinuityView.tsx` + `ExpeditionBoard.tsx`) and included `Place Offer Sent` and `Place Offer Accepted / Queried / Declined` events in `Admin_View_Controller::get_explorer_360()` history.
4. **[DONE]** **Parent/Networker Portal Refinements:**
   - Restricted offer `Accept / Query / Decline` responses to `parent` and `network_member` roles (explorers see read-only status).
   - Converted the portal child/profile selector from a dropdown to highlighted buttons (`ems-portal-switcher__btn`).
   - Made portal status/error notices dismissable.
   - Added configurable `Place Offer Query Email` (`ems_offer_query_email`) in `Settings_Page.php` and enforced a 1000-character limit + live counter on query messages.
