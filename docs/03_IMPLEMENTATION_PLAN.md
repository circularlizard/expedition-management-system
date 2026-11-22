# Phased Implementation Plan & Safe Rollout Runbook

**Operational Priority:** Team formation within ~7 days.  
**Prime Directive:** Protect live sign-ups in `ems_participant_signups` and `ems_expedition_signups` (`signup_status` stays `'submitted' | 'archived'`), keep proven `ems_cohorts` / `team` CPT / `Pushback_Sync_Manager` storage working underneath a clean **"Team"** UI, and consolidate admin UI complexity immediately.

> [!IMPORTANT]
> **Protected / Frozen Zone (Do Not Disrupt):**
> * `Fluent_Forms_Sync.php`, Form 6 & Form 7 submission/payment/OTP hooks, and `[ems_signup_banner]` (underscores only) behavior are strictly out of scope for refactoring.
> * Initial Form 6/7 sign-up confirmation emails are handled by Fluent Forms—do not duplicate them in EMS.
> * `signup_status` (`'submitted' | 'archived'`) remains untouched and independent of `kanban_stage`.

---

## Slice 1A — Admin UI Consolidation, Sign-up Protection & Anomaly Triage (Current Stage / Days 1–2)
*Goal: Cut the cluttered 11-page admin menu down to 7 clean hubs, eliminate duplicate/orphan screens and script bloat, lay down the additive DB columns/tables, and fix identity & anomaly detection before building the Kanban UI in Slice 1B.*

1. **[PENDING — Operational]** **Pre-Flight Snapshot:** Export Unified JSON Backup (`EMS -> Settings -> Backups`) before production rollout.
2. **[PARTIAL]** **Admin Menu & Navigation Rationalisation (`Menu_Manager.php` + `Admin_Page.php`):**
   - **[DONE]** Consolidate the 11 menus into the 7 clean domain hubs (`Sign-ups`, `Expeditions & Teams`, `Volunteers`, `Units & OSM Sync`, `Route Studio`, `Emails & Notifications`, `Settings & System`) with backward-compatible URL redirects from old slugs (`ems-explorers`, `ems-reference`, `ems-training-report`, `ems-units`, `ems-email-templates`, `ems-column-mapper`). *(Gap: `ExpeditionBoard.tsx` does not read `?tab=readiness` from the URL yet, so `ems-training-report` redirect lands on `dashboard` instead of `readiness`.)*
   - **[DONE]** Remove the duplicate `ems-explorers` page, surface `Flexi-Record Mapper` under `Units & OSM Sync`, fold `Training Report` into `Expeditions & Teams -> Teams & Readiness`, and delete dead `Unit_Leader_Page::render_email_tab()` and legacy `Settings_Page` email handlers. *(Note: unused `Training_Report_Page` / `Admin_Page::register_*` methods remain in `src/` as dead code.)*
   - **[DONE]** Strip phantom `wp-components`, `wp-element`, and `wp-i18n` dependencies from PHP enqueues in `Plugin.php` and admin pages.
3. **[DONE]** **Isolated Public Shortcode CSS Extraction (Reversible Step):**
   - Extract the CSS classes used by `[ems_signup_banner]`, `[ems-portal]`, `[ems-leader-dashboard]`, and `[ems-volunteer-signup]` out of `ems-admin.css` into `ems-portal.css` and dequeue `ems-admin.css` from public pages.
4. **[PARTIAL]** **Additive Schema, Identity Fixes & Anomaly Chasers:**
   - **[DONE]** Add additive `kanban_stage VARCHAR(24) NOT NULL DEFAULT 'to_process'` and `processing_notes TEXT NULL` to `ems_participant_signups` and `ems_expedition_signups` in `Table_Installer.php` (keeping `signup_status` untouched and independent), plus the `ems_notification_logs` table.
   - **[DONE]** Fix `ems_scout_id` (singular) -> `ems_scout_ids` (plural) lookups via `ExplorerUserResolver`.
   - **[DONE]** Keep OSM `scout_id = 0` matching **manual** in the Sign-ups hub.
   - **[PARTIAL]** Move/extend anomaly detection server-side (`SignupAnomalyService`, all 8 anomalies) and add `missing_form7` / `missing_edofe` manual chasers logged to `ems_notification_logs` in `Signup_Chaser_Controller.php`. *(Gap: `missing_form7` / `missing_edofe` chasers are not yet exposed in `UnitChaserView.tsx` / `SignupsBoard.tsx` UI.)*
5. **[PARTIAL]** **Slice 1A Verification:** Build, TypeScript check, and local deploy verified; live browser check of `[ems_signup_banner]` on Elementor and `Table_Installer` upgrade in local WP pending user verification.

---

## Slice 1B — Sign-up Kanban Board & Processing Workflow (Triggered After Slice 1A)
*Goal: Build the 5-stage Sign-up Kanban Board on top of the stabilised Slice 1A admin shell and schema without conflicting with in-progress Slice 1A changes.*

1. **[DONE]** **Ingestion & Repository Support (`Signup_Repository.php` + `Fluent_Forms_Sync.php`):**
   - Form 6/7 submissions write `signup_status = 'submitted'` and `kanban_stage = 'to_process'`.
   - Include `kanban_stage` and `processing_notes` in `Signup_Repository` queries and `GET /ems/v1/signups` responses (alongside server-side anomaly flags).
2. **[DONE]** **Kanban Transition Endpoint (`Expedition_Admin_Controller.php`):**
   - Added `PATCH /ems/v1/signups/{id}/status` (`manage_options`) to transition `kanban_stage` (`to_process | on_hold | returned | processed | cancelled`) and save `processing_notes`, keeping `signup_status` (`'submitted' | 'archived'`) untouched.
   - Enforced transition guards (`processed` requires `scout_id > 0` and verified/waived `dofe_number`) and wired `ems.signup.processed` / `ems.signup.status_changed` via `Event_Bus`.
3. **[PARTIAL]** **5-Stage Kanban UI (`SignupsBoard.tsx` + `SignupsKanbanView.tsx`):**
   - **[DONE]** Added the `Kanban Board` view (`To Process`, `On Hold`, `Returned`, `Processed`, `Cancelled`) with drag-and-drop, stage selector, inline `processing_notes`, and `"Participant Place"` / `"Expedition"` terminology (zero `"Form 6"`/`"Form 7"` strings).
   - *(Gap: `SignupsDashboard` summary cards and `Signups Table` row badges still highlight only the original 4 anomalies; dedicated unit tests for `SignupsKanbanView` and `update_signup_kanban` guards are not yet added.)*
4. **[PARTIAL]** **Slice 1B Verification:** `tsc --noEmit`, `SignupsBoard.test.tsx` (26/26), `SignupAnomalyServiceTest`, and `Signup_RepositoryTest` (17/17) passed and deployed locally; live browser verification pending.

---

## Slice 2 — Cross-Stage Team Formation, Two-Tier Deadlines & Manual Place Offers (Days 3–5)
*Goal: Complete the Team Builder UI, add configurable two-tier deadlines, and support manual Place Offer dispatch + portal Accept / Query / Decline.*

1. **Standardise on "Team" Terminology & Streamline `Expeditions & Teams` UI:**
   - Keep `ems_cohorts`, `ems_cohort_members`, `ems_team_members`, `team` CPT, and `Pushback_Sync_Manager` intact under the hood—avoiding a high-risk migration of stage-specific `team` CPT anchors (`practice_team_post_id` / `qualifier_team_post_id`, `dofe_route_cards.team_id`, and `Pushback_Sync_Manager`) during the 7-day team formation window—while renaming all UI copy, tabs, and REST DTOs from "Cohort" to **"Team"** so admins see one consistent mental model.
   - Refine the `Expeditions & Teams` hub (`Events & Calendar`, `Team Builder`, `Teams & Readiness`) for fast cross-stage team assembly, wishlist mutual-highlighting, date intersection scoring, and partial-availability overrides (`assign_pending`, `split_date`, `remove`).
2. **Configurable Two-Tier Deadlines (`DeadlinePolicyService`):**
   - Add `ems_deadline_policies` option (`communicated` soft date/offset vs. `hard` system cutoff + rolling grace fallback for late allocations) and **Settings & System -> Deadlines & Cutoffs** tab.
3. **Place Offer Dispatch, Portal Responses & OSM Pushback (`[ems-portal]`):**
   - Add `offer_status` (`pending | accepted | queried | declined`), `offer_query_message`, `offer_responded_at`, and `offer_responded_by` to `ems_team_members`.
   - Ensure all-or-nothing `Accept / Query (with message) / Decline` actions **atomically update all active `ems_team_members` rows** sharing `(cohort_id, expedition_signup_id)`, enforce the configured Place Offer hard cutoff, and email admins immediately on `Query`.
   - If a `notified` member's team or expedition date changes (`re_notify_needed`), reset `offer_status` to `pending` upon re-dispatch.
   - Update `Pushback_Sync_Manager` to default to pushing `offer_status = 'accepted'` members to OSM events / `'2026 Expeditions'`, with an admin choice/override to include other statuses if needed.
   - Support Explorer, Parent, and **Networker** (`"Myself"` + linked children switcher, keeping child training read-only for Parents) in `[ems-portal]` (keeping `ems/v1/portal/me` accessible for logged-out login prompt rendering).
4. **Slice 2 Verification:** Confirm manual batch Place Offer preview/dispatch, atomic `Accept / Query / Decline` across Practice + Qualifier rows, hard cutoff enforcement, and `Pushback_Sync_Manager` filtering.

---

## Slice 3 — Route Card Security, Cutoff Enforcement & LiC / Unit Leader Views (Post-Offers)
*Goal: Close the Route Engine authorization gap, enforce configurable cutoffs, and launch `[ems-lic-dashboard]` + embedded Route Planner.*

1. **Security & Bridge Wiring:** Enforce `RouteCardAccessPolicy` across all `ems-route/v1` endpoints (supporting split-stage members via `override_expedition_id`) and instantiate `EmsRouteBridgeAdapter` in `RouteEngineModule`.
2. **Route Card Persistence & Revision Loop:** Add `version`, `gpx_raw`, `latest_feedback`, and `feedback_history` to `dofe_route_cards`, register `dofe_route_cards` (and `ems_notification_logs`) in `Backup_Manager`, enforce the configured Route Card hard cutoff, and wire `ems.route_card.submitted` / `ems.route_card.reviewed` notifications.
3. **Front-End Portals:** Embed shared team Route Planner in `[ems-portal]`, launch `[ems-lic-dashboard]` (OIDC + local WP accounts), and add `Unit Teams & Expeditions` tab to `[ems-leader-dashboard]` (`UnitScopePolicy` PII redaction).
4. **Slice 3 Verification:** Verify unauthorized users receive `403` on other teams' `ems-route/v1` endpoints, and verify LiC `Approve` / `Request Revisions` persists `feedback_history`.

---

## Slice 4 — Final CSS Split, PVG/DBS Registry, Daily Deadline Crons & Patrol Retirement (Polish)
1. Split remaining monolithic `ems-admin.css` / `ems-route-card.css` into scoped token + module stylesheets and eliminate remaining inline styles.
2. Add PVG/DBS & Scout membership fields to `Volunteers -> Volunteer & PVG/DBS Registry`.
3. Enable `ems_daily_deadline_check` WP-Cron for deduplicated reminders against configured communicated deadlines.
4. **Retire OSM Patrols & Standardise on Sign-up Unit Mapping (`ems_units.short_code`):**
   - **Source of Truth:** Rely exclusively on the unit captured at sign-up (`ems_participant_signups` / `ems_expedition_signups` `unit_id` & `unit_name`) mapped to `ems_units` via `short_code` (and `unit_id`), rather than OSM patrol strings.
   - **Admin UI Removal (Done in UI, Remaining Backend/Sync Cleanup):**
     - *Done in UI:* Hidden the `patrols` (*OSM Patrol Mapping*) and `osm_patrols` (*Patrols*) tabs from `Admin_Page.php` (`ems-units-sync`), and removed the *OSM Patrols* column from the *Units & Districts* table in `Unit_Leader_Page.php`.
     - *Remaining:* Redirect legacy `?tab=patrols` / `?tab=osm_patrols` URLs in `Menu_Manager.php` / `Admin_Page.php` to `ems-units-sync&tab=units`, remove the underlying `render_patrols_tab()` handlers, and stop syncing patrols in `OSM_Reference_Sync::sync_patrols()` and `Unit_Repository` (`sync_patrol()`, `find_matching_unit()`, `get_patrols()`, `link_patrol()`, `unlink_patrol()`, `find_by_patrol_section()`).
   - **Replace Patrol-Based Lookups:** Update `Expedition_Admin_Controller.php` (`leader_email` lookup) and `Admin_View_Controller.php` (`unit_name` resolution) to resolve `ems_units` directly via sign-up `unit_id` / `ems_units.short_code` instead of joining `ems_unit_patrols` on `explorer.patrol`.
   - **Update UI Fallbacks & Backups:** Replace `patrol` fallback labels in `SignupsBoard.tsx`, `EventPlanningBoard.tsx`, `OSMReference.tsx`, `AdminPreviewBar.tsx`, and `portal/index.tsx` with the resolved `unit_name` / `ems_units` name, and deprecate `ems_unit_patrols` from `Backup_Manager` / `Portability_Engine` / `Table_Installer` (keeping legacy import compatibility if needed for older backups).
