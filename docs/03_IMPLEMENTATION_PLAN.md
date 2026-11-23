# Phased Implementation Plan & Safe Rollout Runbook

**Operational Priority:** Team formation within ~7 days.  
**Prime Directive:** Protect live sign-ups in `ems_participant_signups` and `ems_expedition_signups` (`signup_status` stays `'submitted' | 'archived'`), keep proven `ems_cohorts` / `team` CPT / `Pushback_Sync_Manager` storage working underneath a clean **"Team"** UI, and consolidate admin UI complexity immediately.

> [!IMPORTANT]
> **Protected / Frozen Zone (Do Not Disrupt):**
> * `Fluent_Forms_Sync.php`, Form 6 & Form 7 submission/payment/OTP hooks, and `[ems_signup_banner]` (underscores only) behavior are strictly out of scope for refactoring.
> * Initial Form 6/7 sign-up confirmation emails are handled by Fluent Forms—do not duplicate them in EMS.
> * `signup_status` (`'submitted' | 'archived'`) remains untouched and independent of `kanban_stage`.

---

## Completed Work Summary
*(Full task checklists archived in [IMPLEMENTATION_PLAN_COMPLETED_SLICES.md](archive/IMPLEMENTATION_PLAN_COMPLETED_SLICES.md).)*

- **Slice 1A — Admin UI Consolidation, Sign-up Protection & Anomaly Triage (`[DONE]`):** Consolidated 11 admin menus into 7 hubs with legacy redirects, extracted `ems-portal.css`, added additive `kanban_stage` / `processing_notes` / `ems_notification_logs`, fixed `ems_scout_ids` resolution, and implemented server-side 8-anomaly detection + chasers.
- **Slice 1B — Sign-up Processing Workflow & Consolidated Anomalies (`[DONE]`):** Table-first processing statuses (`new`, `place_allocated`, `team_allocated`, etc.), inline `participant_place_allocated` pairing, bulk updates, `PATCH /ems/v1/signups/{id}/status` with `Event_Bus` events, and consolidated `UnitChaserView`.
- **Slice 2 — Cross-Stage Team Formation, Two-Tier Deadlines & Manual Place Offers (`[DONE]`):** Standardised UI/dispatch on "Team" terminology, built `DeadlinePolicyService` (`ems_deadline_policies` + Settings tab), added `offer_status` tracking, atomic portal `Accept / Query / Decline` with hard-cutoff enforcement, `Pushback_Sync_Manager` `accepted` filtering + override, and Networker `"Myself"` + children switcher.
- **Pre-Slice 3 — Bug Fixes, Status Transitions, Explorer 360° & Portal Polish (`[DONE]`):** Enabled `scout_id = 0` provisional team/event placement (fixed `/teams/{id}/members` route collision & `StageMatrixView`), centralised cross-entity status transitions in `SignupStatusTransitionService`, integrated `Explorer360Drawer` into Team Builder with offer/response interaction history, restricted offer responses to Parents/Networkers, converted child switcher to highlighted buttons, made portal notices dismissable, added `ems_offer_query_email` setting, and enforced a 1000-character query limit + counter.

### Operational / Live Verification Pending (Completed Slices)
- **[PENDING — Operational]** Export Unified JSON Backup (`EMS -> Settings -> Backups`) before production rollout.
- **[PENDING — Operational]** Live browser/staging check of `[ems_signup_banner]` on Elementor, `Table_Installer` DB upgrade, Place Offer dispatch, portal `Accept / Query / Decline`, hard cutoff enforcement, and OSM pushback.

---

## Slice 3 — Final CSS Split & Daily Deadline Crons (Pending)
1. **[PENDING]** Split remaining monolithic `ems-admin.css` / `ems-route-card.css` into scoped token + module stylesheets and eliminate remaining inline styles.
2. **[PENDING]** Enable `ems_daily_deadline_check` WP-Cron for deduplicated reminders against configured communicated deadlines.

---

## Slice 4 — Retire OSM Patrols & Standardise on Sign-up Unit Mapping (Partial — UI Done, Backend/Sync Pending)
*Goal: Rely exclusively on the unit captured at sign-up (`ems_participant_signups` / `ems_expedition_signups` `unit_id` & `unit_name`) mapped to `ems_units` via `short_code` (and `unit_id`), rather than OSM patrol strings.*

1. **[DONE in UI]** **Hide Patrol UI:** Hidden the `patrols` (*OSM Patrol Mapping*) and `osm_patrols` (*Patrols*) tabs from `Admin_Page.php` (`ems-units-sync`), and removed the *OSM Patrols* column from the *Units & Districts* table in `Unit_Leader_Page.php`.
2. **[PENDING]** **Redirects, Sync & Repository Cleanup:** Redirect legacy `?tab=patrols` / `?tab=osm_patrols` URLs in `Menu_Manager.php` / `Admin_Page.php` to `ems-units-sync&tab=units` (currently still redirects to `osm_patrols` / `patrols`), remove the underlying `render_patrols_tab()` handlers, and stop syncing patrols in `OSM_Reference_Sync::sync_patrols()` and `Unit_Repository` (`sync_patrol()`, `find_matching_unit()`, `get_patrols()`, `link_patrol()`, `unlink_patrol()`, `find_by_patrol_section()`).
3. **[PENDING]** **Replace Patrol-Based Lookups:** Update `Expedition_Admin_Controller.php` (`leader_email` lookup) and `Admin_View_Controller.php` (`unit_name` resolution) to resolve `ems_units` directly via sign-up `unit_id` / `ems_units.short_code` instead of joining `ems_unit_patrols`.
4. **[PENDING]** **Update UI Fallbacks & Backups:** Replace `patrol` fallback labels in `AdminPreviewBar.tsx` and `portal/index.tsx` with the resolved `unit_name` / `ems_units` name, and deprecate `ems_unit_patrols` from `Backup_Manager` / `Portability_Engine` / `Table_Installer` (keeping legacy import compatibility if needed for older backups).

---

## Slice 5 — Volunteer Management & PVG/DBS Registry (Pending — Needs Detailed Refinement)
> [!NOTE]
> **Refinement Required:** This slice requires substantial functional, data-model, and compliance refinement before implementation begins.

1. **[PENDING]** Add PVG/DBS & Scout membership fields to `Volunteers -> Volunteer & PVG/DBS Registry` and define the broader volunteer management, role assignment, and compliance tracking workflows.

---

## Slice 6 — Route Card Security, Cutoff Enforcement & LiC / Unit Leader Views (Pending)
*Goal: Close the Route Engine authorization gap, enforce configurable cutoffs, and launch `[ems-lic-dashboard]` + embedded Route Planner.*

1. **[PARTIAL]** **Security & Bridge Wiring:** `EmsRouteBridgeAdapter` exists in `src/Integrations/RouteEngine/EmsRouteBridgeAdapter.php` with unit tests (`EmsRouteBridgeAdapterTest.php`), but `RouteCardAccessPolicy` is not yet implemented or enforced across `ems-route/v1` endpoints (`Route_Card_Controller.php` / `RouteEngineModule`).
2. **[PENDING]** **Route Card Persistence & Revision Loop:** Add `version`, `gpx_raw`, `latest_feedback`, and `feedback_history` to `dofe_route_cards`, register `dofe_route_cards` (and `ems_notification_logs`) in `Backup_Manager`, enforce the configured `route_card` hard cutoff (`DeadlinePolicyService::evaluate_cutoff('route_card', ...)`), and wire `ems.route_card.submitted` / `ems.route_card.reviewed` notifications.
3. **[PENDING]** **Front-End Portals:** Embed shared team Route Planner in `[ems-portal]`, launch `[ems-lic-dashboard]` (OIDC + local WP accounts), and add `Unit Teams & Expeditions` tab to `[ems-leader-dashboard]` (`UnitScopePolicy` PII redaction).
4. **[PENDING]** **Slice 6 Verification:** Verify unauthorized users receive `403` on other teams' `ems-route/v1` endpoints, and verify LiC `Approve` / `Request Revisions` persists `feedback_history`.
