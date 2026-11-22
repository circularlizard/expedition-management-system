# Phased Implementation Plan & Safe Rollout Runbook

**Operational Priority:** Team formation within ~7 days[cite: 5].  
**Prime Directive:** Protect live sign-ups in `ems_participant_signups` and `ems_expedition_signups` (`signup_status` stays `'submitted' | 'archived'`), keep proven `ems_cohorts` / `team` CPT / `Pushback_Sync_Manager` storage working underneath a clean **"Team"** UI, and consolidate admin UI complexity immediately[cite: 5, 7, 9].

---

## Slice 1 — Admin UI Consolidation & Sign-up Anomaly Triage (Days 1–2)
*Goal: Cut the cluttered 11-page admin menu down to 7 clean hubs, eliminate duplicate/orphan screens and CSS/script bloat, and give you an Anomaly & Kanban view to clean sign-ups before forming teams.*

1. **Pre-Flight Snapshot:** Export Unified JSON Backup (`EMS -> Settings -> Backups`)[cite: 5].
2. **Admin Menu & Navigation Rationalisation (`Menu_Manager.php` + `Admin_Page.php`):**
   - Consolidate the 11 menus into the 7 clean domain hubs (`Sign-ups`, `Expeditions & Teams`, `Volunteers`, `Units & OSM Sync`, `Route Studio`, `Emails & Notifications`, `Settings & System`) with backward-compatible URL redirects from old slugs (`ems-explorers`, `ems-reference`, `ems-training-report`) so no bookmarks break.
   - Remove the duplicate `ems-explorers` page (single home under `Units & OSM Sync -> Explorers & Patrols`), surface `Flexi-Record Mapper` as a proper tab under `Units & OSM Sync`, fold `Training Report` into `Expeditions & Teams -> Teams & Readiness`, and delete dead `Unit_Leader_Page::render_email_tab()` and legacy `Settings_Page` email handlers.
   - Strip phantom `wp-components`, `wp-element`, and `wp-i18n` dependencies from PHP enqueues and decouple `ems-admin.css` from public front-end shortcodes.
3. **Additive Sign-up Protection & Anomaly Triage (`SignupsModule` + `signups-board`):**
   - Add additive `kanban_stage VARCHAR(24) NOT NULL DEFAULT 'to_process'` and `processing_notes TEXT NULL` to `ems_participant_signups` and `ems_expedition_signups` (do NOT alter `signup_status`)[cite: 5, 9].
   - Fix `ems_scout_id` (singular) -> `ems_scout_ids` (plural) lookups via `ExplorerUserResolver`[cite: 9].
   - Ship the **Anomalies** filter & badges (`place_without_expedition`, `expedition_without_place`, `level_mismatch`, `duplicate_signup`, `unmatched_osm` `scout_id = 0`, `pending_osm_sync`, `missing_edofe`, `unvalidated_asn`)[cite: 5, 9] + 5-stage Kanban board + `missing_form7` / `missing_edofe` manual chasers + `ems_notification_logs` table.

---

## Slice 2 — Cross-Stage Team Formation, Two-Tier Deadlines & Manual Place Offers (Days 3–5)
*Goal: Complete the Team Builder UI, add configurable two-tier deadlines, and support manual Place Offer dispatch + portal Accept / Query / Decline.*

1. **Standardise on "Team" Terminology & Streamline `Expeditions & Teams` UI:**
   - Keep `ems_cohorts`, `ems_cohort_members`, `ems_team_members`, `team` CPT, and `Pushback_Sync_Manager` intact under the hood[cite: 5, 7], while renaming all UI copy, tabs, and REST DTOs from "Cohort" to **"Team"** so admins see one consistent mental model.
   - Refine the `Expeditions & Teams` hub (`Events & Calendar`, `Team Builder`, `Teams & Readiness`) for fast cross-stage team assembly, wishlist mutual-highlighting, date intersection scoring, and partial-availability overrides (`assign_pending`, `split_date`, `remove`)[cite: 5, 7].
2. **Configurable Two-Tier Deadlines (`DeadlinePolicyService`):**
   - Add `ems_deadline_policies` option (`communicated` soft date/offset vs. `hard` system cutoff + rolling grace fallback for late allocations) and **Settings & System -> Deadlines & Cutoffs** tab.
3. **Place Offer Dispatch & Portal Responses (`[ems-portal]`):**
   - Add `offer_status` (`pending | accepted | queried | declined`), `offer_query_message`, `offer_responded_at`, and `offer_responded_by` to `ems_team_members`.
   - Support Explorer, Parent, and **Networker** (`"Myself"` + linked children switcher, keeping child training read-only for Parents) in `[ems-portal]`.
   - Ship all-or-nothing `Accept / Query (with message) / Decline` actions enforcing the configured Place Offer hard cutoff and emailing admins immediately on `Query`[cite: 6, 8].

---

## Slice 3 — Route Card Security, Cutoff Enforcement & LiC / Unit Leader Views (Post-Offers)
*Goal: Close the Route Engine authorization gap, enforce configurable cutoffs, and launch `[ems-lic-dashboard]` + embedded Route Planner.*

1. **Security & Bridge Wiring:** Enforce `RouteCardAccessPolicy` across all `ems-route/v1` endpoints (supporting split-stage members via `override_expedition_id`) and instantiate `EmsRouteBridgeAdapter` in `RouteEngineModule`[cite: 7].
2. **Route Card Persistence & Revision Loop:** Add `version`, `gpx_raw`, `latest_feedback`, and `feedback_history` to `dofe_route_cards`, register `dofe_route_cards` in `Backup_Manager`, enforce the configured Route Card hard cutoff, and wire `ems.route_card.submitted` / `ems.route_card.reviewed` notifications[cite: 8, 9].
3. **Front-End Portals:** Embed shared team Route Planner in `[ems-portal]`[cite: 8], launch `[ems-lic-dashboard]` (OIDC + local WP accounts)[cite: 8, 9], and add `Unit Teams & Expeditions` tab to `[ems-leader-dashboard]` (`UnitScopePolicy` PII redaction)[cite: 9].

---

## Slice 4 — Final CSS Split, PVG/DBS Registry & Daily Deadline Crons (Polish)
1. Split monolithic `ems-admin.css` / `ems-route-card.css` into scoped token + module stylesheets and eliminate remaining inline styles.
2. Add PVG/DBS & Scout membership fields to `Volunteers -> Volunteer & PVG/DBS Registry`[cite: 9].
3. Enable `ems_daily_deadline_check` WP-Cron for deduplicated reminders against configured communicated deadlines[cite: 8].