# Expedition planning

## System Objectives & Boundaries
* Act as the operational hub for SEEE DofE expeditions: sign-up reconciliation & anomaly triage, cross-stage team & event allocation, online training compliance, route planning/approval, and lifecycle notifications.
* Keep **Online Scout Manager (OSM)** as the single source of truth for full PII, medical details, risk assessments, payments, and live event attendance — never duplicate OSM as the master of this information[cite: 6].
* Keep the **Scouts Membership System** as the record of adult membership, including PVG and Scouts Association online training.
* Serve as the single master record for **Team membership and Expedition allocation**, syncing confirmed changes one-way to OSM[cite: 6].
* Keep public marketing/pathway pages (`/expedition-roadmap/`, `/signup/`, `/training/`, `/costs/`) in Elementor; the plugin provides self-contained shortcodes and portals[cite: 5].

## Key Users & Stakeholder Goals
* **Prospective Participants & Families:** Explore the expedition pathway, costs, and dates via public website pages, and sign up for participant places and expeditions via Fluent Forms[cite: 5].
* **Explorers:** View offered Practice/Qualifier dates and teammates, respond to Place Offers, track/complete online training, and co-edit/submit shared Route Cards.
* **Parents / Guardians:** Sign up their children for participant places and expeditions, track linked children's sign-ups, training, and expedition offers, and Accept / Query / Decline Place Offers on their behalf[cite: 6]. Parents may NOT complete online training on their child's behalf (though they can take the training for themselves).
* **Networkers:** Adult participants combining both Explorer and Parent capabilities.
* **Unit Leaders (DofE Leaders):** Monitor unit sign-up reconciliation and see which Teams and Expeditions their unit's Explorers are allocated to (minimal PII)[cite: 9].
* **Expedition Leaders in Charge (LiCs):** View assigned expeditions and volunteer rosters, audit team readiness (First Aid Rule of Two, training, acceptances), and inspect/annotate/approve Route Cards via `[ems-lic-dashboard]`[cite: 5].
* **Expedition Volunteers:** Register event availability and Scout membership details via `[ems-volunteer-signup]` and view events they are assigned to (no route-planning responsibilities)[cite: 9].
* **EMS Coordinators (Admins):** Reconcile sign-ups against OSM, resolve sign-up anomalies, build cross-stage teams, dispatch Place Offers manually, assign volunteers to expeditions, and monitor season-wide readiness[cite: 6, 7, 9].

## Modules
### EMS Admin Dashboard (`ems-plugin`)
* Sign-ups
  * Fluent Forms (Participant place Form 6 & expedition preferences Form 7 webhook sync)[cite: 9]
  * Sign-up Kanban & reconciliation board (`To Process`, `On Hold`, `Returned`, `Processed`, `Cancelled`; auto/manual OSM matching, eDofE verification)[cite: 5, 9]
  * Anomaly & consistency detection (`scout_id = 0` unmatched, Form 6 without Form 7, Form 7 without Form 6, level mismatches, duplicate submissions)
  * Automated cron & manual chasers (missing eDofE, missing Form 7, unlinked OSM, incomplete training)[cite: 5]
* Expeditions & Teams
  * Event setup (dates, stage, level, transport mode, Leader in Charge, volunteer requirements, route parameters)[cite: 9]
  * Cross-stage Team Builder (Practice & Qualifier continuity, wishlist & date intersection, partial-availability overrides: `assign_pending`, `split_date`, `remove`)[cite: 7]
  * Place offer notifications (staged preview & manual batch dispatch — never automatic)[cite: 7]
  * Place offer responses (all-or-nothing Accept / Query with message / Decline up to configured hard cutoff)[cite: 5]
  * Teams & Readiness view (4–7 size check, Rule of Two first aid + expiry, Tutor LMS progress, route card status)[cite: 5, 9]
  * Route card submissions & LiC approval overview[cite: 5]
* Volunteers
  * Requirements & coverage overview per expedition
  * Volunteer sign-ups (`[ems-volunteer-signup]`)[cite: 9]
  * Volunteer assignments, PVG/DBS tracking & overnight/day coverage[cite: 9]
* Units & OSM Sync
  * Connection settings & tokenless OAuth2 sync[cite: 9]
  * Sync status — explorers and events mapping to OSM[cite: 9]
  * Explorers cache (`scout_id` anchor) & confirmed event attendance push to OSM[cite: 6, 9]
  * DofE leader assignments, bulk leader invites & login status[cite: 9]
* Emails & Notifications
  * FluentSMTP integration & 12-month dispatch archive (`ems_notification_logs`)[cite: 6]
  * Template management (`{{token}}` placeholders)[cite: 9]
  * Automated deadline cron toggles & manual dispatch queue
* Route Studio (`RouteEngineModule`)
  * Admin sandbox, OS Terrain 50 DEM tile manager & dynamic Naismith pacing settings[cite: 5, 8]
  * Standalone route test — GPX upload/export, versioned JSON route storage & A4 landscape eDofE print engine[cite: 5, 8]
* System & Settings
  * Access control guard, OIDC + local WP login linking & role permissions[cite: 9]
  * Configurable Deadlines & Cutoffs (communicated soft deadlines vs. system-enforced hard cutoffs)
  * Feature flags[cite: 8]
  * Unified JSON backup, parity manifest & restore[cite: 9]
  * Security & reconciliation audit logs[cite: 9]
  * Help & shortcode reference[cite: 5]

### Shortcode Integration with Website
* Public & Pre-auth gateways (embedded in Elementor pages)
  * OIDC sign-up banner (`[ems-signup-banner]` / `[ems_signup_banner]`) & Fluent Forms 6 & 7[cite: 9]
  * Volunteer availability wizard (`[ems-volunteer-signup]`)[cite: 9]
* Participant portal for Explorers, Networkers, and Parents (`[ems-portal]`)
  * My sign-ups & OSM link status
  * My expeditions — dates, teammates, and all-or-nothing Place Offer Accept / Query (with message) / Decline (enforcing configured hard cutoff)[cite: 5, 6]
  * Training to-do, Tutor LMS progress & First Aid status (child progress read-only for Parents)
  * Embedded Route Planner — shared team route card co-editing, submission (enforcing configured hard cutoff), revision history & LiC feedback[cite: 5]
* Unit Leaders dashboard (`[ems-leader-dashboard]`)[cite: 9]
  * Unit sign-ups & reconciliation status (medical/contact PII redacted)[cite: 9]
  * Teams & expeditions with Explorers from my unit
* Leaders in Charge (LiC) operational portal (`[ems-lic-dashboard]`)
  * My assigned expeditions & volunteer roster[cite: 5]
  * Teams on my expedition & readiness audit (first aid, training, acceptances)[cite: 5]
  * Route card inspection, inline annotation & approval/revision workflow[cite: 5]

## Notifications
1. Sign-up (Event-triggered)
   * To Parent / Explorer — confirmation of sign-up[cite: 6]
   * To DofE Leader — confirmation of sign-up
   * To Admin — sign-up processing or manual reconciliation needed
2. Sign-up data consistency & chasers (Cron-driven against configured deadlines + Manual)
   * To DofE Leader — sign-up inconsistencies or Explorers not shared with OSM
   * To Explorer / Parents — missing eDofE number, missing Form 7 expedition preferences, or unlinked account chaser[cite: 5]
3. Unit Leader onboarding (Manual / Event-triggered)
   * To DofE Leader — portal invitation with password setup link[cite: 9]
4. Expedition allocation & responses (Manual batch dispatch + Event-triggered responses)
   * To Explorer / Parents — combined Practice & Qualifier place offer (displaying communicated acceptance deadline and any date-conflict notice)[cite: 7]
   * To Admin — place offer accepted, queried (with participant/parent message), or declined[cite: 5, 6]
   * To DofE Leader / Admin — Explorer transferred between teams or award levels[cite: 5]
5. Training reminders (Cron-driven against configured training deadline)
   * To Explorer / Parents — online training & first aid incomplete reminder
6. Expedition update (Event-triggered)
   * To Explorer / Parents — expedition information updated (LiC allocated, joining instructions, or route parameters added)
7. Route cards due (Cron-driven against configured communicated deadline)
   * To Explorer / Parents — route card submission deadline approaching[cite: 5]
8. Route card submitted (Event-triggered)
   * To Admin & LiC — route card submitted for review[cite: 5]
9. Route card feedback (Event-triggered)
   * To Explorer / Parents — route card approved[cite: 5]
   * To Explorer / Parents — route card revisions requested (with LiC comments)[cite: 5]

## Entities
* Participant Place Sign-up (`ems_participant_signups` — Form 6, with additive `kanban_stage`)[cite: 9]
* Expedition Sign-up (`ems_expedition_signups` — Form 7, with additive `kanban_stage`)[cite: 9]
* Explorer (`ems_osm_explorers` — anchored on `scout_id`)[cite: 9]
* Unit (`ems_units` & `ems_unit_patrols` OSM mappings)[cite: 9]
* Expedition (`expedition` CPT — single Practice or Qualifier weekend)[cite: 9]
* Team (`ems_cohorts` + `ems_team_members` + stage `team` CPT allocations — presented uniformly as "Team" in UI/REST, with stage overrides & all-or-nothing offer status)[cite: 7, 9]
* Route Card (`dofe_route_cards` — shared daily team route, versioned GPX/JSON, LiC review & feedback history)[cite: 5]
* Volunteer (`ems_volunteers` & `ems_volunteer_availability` — covering both Expedition Volunteers and LiCs)[cite: 9]
* Training Record (`TutorLmsAdapter` course completion & OSM First Aid expiry)[cite: 8, 9]
* Audit & Notification Log (`ems_audit_logs` & `ems_notification_logs` 12-month dispatch archive)[cite: 6, 9]