> [!NOTE]
> **LEGACY / SUPERSEDED:** Early planning vision consolidated into [01_TECHNICAL_ARCHITECTURE.md](../01_TECHNICAL_ARCHITECTURE.md) and [05_UI_IA_AND_API_REFERENCE.md](../05_UI_IA_AND_API_REFERENCE.md). Preserved for historical reference.

# Expedition planning

## System Objectives & Boundaries
* Act as the operational hub for SEEE DofE expeditions: sign-up reconciliation & anomaly triage, cross-stage team & event allocation, online training compliance, route planning/approval, and lifecycle notifications.
* Keep **Online Scout Manager (OSM)** as the single source of truth for full PII, medical details, risk assessments, payments, and live event attendance — never duplicate OSM as the master of this information.
* Keep the **Scouts Membership System** as the record of adult membership, including PVG and Scouts Association online training.
* Serve as the single master record for **Team membership and Expedition allocation**, syncing confirmed changes one-way to OSM (defaulting to `offer_status = 'accepted'`, with admin override).
* Keep **Fluent Forms (Forms 6 & 7)** as the owner of initial sign-up intake and initial sign-up confirmation emails.
* Keep public marketing/pathway pages (`/expedition-roadmap/`, `/signup/`, `/training/`, `/costs/`) in Elementor; the plugin provides self-contained shortcodes and portals.

## Key Users & Stakeholder Goals
* **Prospective Participants & Families:** Explore the expedition pathway, costs, and dates via public website pages, and sign up for participant places and expeditions via Fluent Forms.
* **Explorers:** View offered Practice/Qualifier dates and teammates, respond to Place Offers, track/complete online training, and co-edit/submit shared Route Cards.
* **Parents / Guardians:** Sign up their children for participant places and expeditions, track linked children's sign-ups, training, and expedition offers, and Accept / Query / Decline Place Offers on their behalf. Parents may NOT complete online training on their child's behalf (though they can take the training for themselves).
* **Networkers:** Adult participants combining both Explorer and Parent capabilities.
* **Unit Leaders (DofE Leaders):** Monitor unit sign-up reconciliation and see which Teams and Expeditions their unit's Explorers are allocated to (minimal PII).
* **Expedition Leaders in Charge (LiCs):** View assigned expeditions and volunteer rosters, audit team readiness (First Aid Rule of Two, training, acceptances), and inspect/annotate/approve Route Cards via `[ems-lic-dashboard]`.
* **Expedition Volunteers:** Register event availability and Scout membership details via `[ems-volunteer-signup]` and view events they are assigned to (no route-planning responsibilities).
* **EMS Coordinators (Admins):** Manually reconcile sign-ups against OSM, resolve sign-up anomalies, build cross-stage teams, dispatch Place Offers manually, assign volunteers to expeditions, and monitor season-wide readiness.

## Modules
### EMS Admin Dashboard (`ems-plugin`)
* Sign-ups
  * Fluent Forms (Participant place Form 6 & expedition preferences Form 7 webhook sync)
  * Sign-up Kanban & reconciliation board (`To Process`, `On Hold`, `Returned`, `Processed`, `Cancelled` via additive `kanban_stage`, independent of `signup_status`; manual OSM matching, eDofE verification)
  * Anomaly & consistency detection (`scout_id = 0` unmatched, Form 6 without Form 7, Form 7 without Form 6, level mismatches, duplicate submissions)
  * Automated cron & manual chasers (missing eDofE, missing Form 7, unlinked OSM, incomplete training)
* Expeditions & Teams
  * Event setup (dates, stage, level, transport mode, Leader in Charge, volunteer requirements, route parameters)
  * Cross-stage Team Builder (Practice & Qualifier continuity, wishlist & date intersection, partial-availability overrides: `assign_pending`, `split_date`, `remove`)
  * Place offer notifications (staged preview & manual batch dispatch — never automatic)
  * Place offer responses (all-or-nothing Accept / Query with message / Decline up to configured hard cutoff)
  * Teams & Readiness view (4–7 size check, Rule of Two first aid + expiry, Tutor LMS progress, route card status)
  * Route card submissions & LiC approval overview
* Volunteers
  * Requirements & coverage overview per expedition
  * Volunteer sign-ups (`[ems-volunteer-signup]`)
  * Volunteer assignments, PVG/DBS tracking & overnight/day coverage
* Units & OSM Sync
  * Connection settings & tokenless OAuth2 sync
  * Sync status — explorers and events mapping to OSM
  * Explorers cache (`scout_id` anchor) & confirmed event attendance push to OSM (default `offer_status = 'accepted'`, with admin override)
  * DofE leader assignments, bulk leader invites & login status
* Emails & Notifications
  * FluentSMTP integration & 12-month dispatch archive (`ems_notification_logs`)
  * Template management (`{{token}}` placeholders)
  * Automated deadline cron toggles & manual dispatch queue
* Route Studio (`RouteEngineModule`)
  * Admin sandbox, OS Terrain 50 DEM tile manager & dynamic Naismith pacing settings
  * Standalone route test — GPX upload/export, versioned JSON route storage & A4 landscape eDofE print engine
* System & Settings
  * Access control guard, OIDC + local WP login linking & role permissions
  * Configurable Deadlines & Cutoffs (communicated soft deadlines vs. system-enforced hard cutoffs)
  * Feature flags
  * Unified JSON backup, parity manifest & restore
  * Security & reconciliation audit logs
  * Help & shortcode reference

### Shortcode Integration with Website
* Public & Pre-auth gateways (embedded in Elementor pages)
  * OIDC sign-up banner (`[ems_signup_banner]`, underscores only) & Fluent Forms 6 & 7
  * Volunteer availability wizard (`[ems-volunteer-signup]`)
* Participant portal for Explorers, Networkers, and Parents (`[ems-portal]`)
  * My sign-ups & OSM link status
  * My expeditions — dates, teammates, and all-or-nothing Place Offer Accept / Query (with message) / Decline (enforcing configured hard cutoff)
  * Training to-do, Tutor LMS progress & First Aid status (child progress read-only for Parents)
  * Embedded Route Planner — shared team route card co-editing, submission (enforcing configured hard cutoff), revision history & LiC feedback
* Unit Leaders dashboard (`[ems-leader-dashboard]`)
  * Unit sign-ups & reconciliation status (medical/contact PII redacted)
  * Teams & expeditions with Explorers from my unit
* Leaders in Charge (LiC) operational portal (`[ems-lic-dashboard]`)
  * My assigned expeditions & volunteer roster
  * Teams on my expedition & readiness audit (first aid, training, acceptances)
  * Route card inspection, inline annotation & approval/revision workflow

## Notifications
*(Initial Form 6/7 sign-up confirmation emails are handled by Fluent Forms, not EMS.)*

1. Sign-up data consistency & chasers (Cron-driven against configured deadlines + Manual)
   * To DofE Leader — sign-up inconsistencies or Explorers not shared with OSM
   * To Explorer / Parents — missing eDofE number, missing Form 7 expedition preferences, or unlinked account chaser
2. Unit Leader onboarding (Manual / Event-triggered)
   * To DofE Leader — portal invitation with password setup link
3. Expedition allocation & responses (Manual batch dispatch + Event-triggered responses)
   * To Explorer / Parents — combined Practice & Qualifier place offer (displaying communicated acceptance deadline and any date-conflict notice)
   * To Admin — place offer accepted, queried (with participant/parent message), or declined
   * To DofE Leader / Admin — Explorer transferred between teams or award levels
4. Training reminders (Cron-driven against configured training deadline)
   * To Explorer / Parents — online training & first aid incomplete reminder
5. Expedition update (Event-triggered)
   * To Explorer / Parents — expedition information updated (LiC allocated, joining instructions, or route parameters added)
6. Route cards due (Cron-driven against configured communicated deadline)
   * To Explorer / Parents — route card submission deadline approaching
7. Route card submitted (Event-triggered)
   * To Admin & LiC — route card submitted for review
8. Route card feedback (Event-triggered)
   * To Explorer / Parents — route card approved
   * To Explorer / Parents — route card revisions requested (with LiC comments)

## Entities
* Participant Place Sign-up (`ems_participant_signups` — Form 6, with additive `kanban_stage` independent of `signup_status`)
* Expedition Sign-up (`ems_expedition_signups` — Form 7, with additive `kanban_stage` independent of `signup_status`)
* Explorer (`ems_osm_explorers` — anchored on `scout_id`)
* Unit (`ems_units` & `ems_unit_patrols` OSM mappings)
* Expedition (`expedition` CPT — single Practice or Qualifier weekend)
* Team (`ems_cohorts` + `ems_team_members` + stage `team` CPT allocations — presented uniformly as "Team" in UI/REST, with stage overrides & all-or-nothing offer status)
* Route Card (`dofe_route_cards` — shared daily team route, versioned GPX/JSON, LiC review & feedback history)
* Volunteer (`ems_volunteers` & `ems_volunteer_availability` — covering both Expedition Volunteers and LiCs)
* Training Record (`TutorLmsAdapter` course completion & OSM First Aid expiry)
* Audit & Notification Log (`ems_audit_logs` & `ems_notification_logs` 12-month dispatch archive)
