# Expedition planning

## System Objectives & Boundaries
* Act as the operational hub for SEEE DofE expeditions: signup, team & event allocation, online training compliance, route planning/approval, and lifecycle notifications[cite: 5, 6].
* Keep **Online Scout Manager (OSM)** as the single source of truth for full PII, medical details, risk assessments, payments, and live event attendance — never duplicate OSM as the master of this information[cite: 5].
* Keep the Scouts Membership System as the record of adult membership, including PVG and scouts association online training.
* Serve as the single master record for **Team membership and Expedition allocation**, syncing confirmed changes one-way to OSM[cite: 5].
* Keep public marketing/pathway pages (`/expedition-roadmap/`, `/signup/`, `/training/`, `/costs/`) in Elementor; the plugin provides self-contained shortcodes and portals[cite: 6, 7].

## Key Users & Stakeholder Goals
* **Prospective Participants:** Explore the expedition pathway, costs, and dates via public website pages + `[ems-path-finder]`, and sign up for participant places and expeditions via Fluent Forms[cite: 6, 7].
* **Explorers:** View offered Practice/Qualifier dates and teammates, track/do online training, and co-edit/submit shared Route Cards[cite: 3, 6].
* **Parents / Guardians:** Sign up their children to participant places and expeditions, Track linked children's sign-ups, training, and expedition offers, and Accept / Query / Decline Place Offers on their behalf[cite: 3, 6, 7]. Parents may NOT do online training on their child’s behalf, though they can still do the training.
* **Networkers**: Combination of both Explorer and Parent roles (as they are adult participants)
* **Unit Leaders (DofE Leaders):** Monitor unit sign-up, and see which Teams and Expeditions their unit's Explorers are allocated to (minimal PII)[cite: 5, 7].
* **Expedition Leaders in Charge (LiCs)**: view/audit team readiness (First Aid Rule of Two, training, acceptances), and inspect/annotate/approve Route Cards via `[ems-lic-dashboard]`, cite:[3, 6, 7].
* **Expedition Volunteers:** Register event availability and scout membership details, see events they are assigned to.
* **EMS Coordinators (Admins):** Reconcile sign-ups against OSM, build cross-stage teams, dispatch Place Offers manually, assign volunteers to expeditions, and monitor season-wide readiness[cite: 3, 5, 6].

## Modules
### EMS Admin Dashboard (`ems-plugin`)
* Sign-ups
  * Fluent Forms (Participant place & expedition preferences webhook sync)[cite: 7]
  * Sign-up Kanban & reconciliation board (To Process, On Hold, Returned, Processed, Cancelled; auto/manual OSM matching, eDofE verification)[cite: 3, 6]
  * Data consistency & duplicate detection (`scout_id = 0` unmatched handling)[cite: 3]
  * Automated cron & manual chasers (missing eDofE, unlinked OSM, incomplete training)[cite: 3, 6]
* Expeditions & Teams
  * Event setup (dates, stage, level, transport mode, Leader in Charge, volunteer requirements, route parameters)[cite: 3]
  * Cross-stage Team Builder (Practice & Qualifier continuity, wishlist & date intersection, partial-availability overrides: `assign_pending`, `split_date`, `remove`)[cite: 3]
  * Place offer notifications (staged preview & manual batch dispatch — never automatic)[cite: 3]
  * Place offer responses (all-or-nothing Accept / Query with message / Decline up to 31st Dec cutoff)[cite: 3, 6]
  * Teams & Readiness view (4–7 size check, Rule of Two first aid + expiry, Tutor LMS progress, route card status)[cite: 3]
  * Route card submissions & LiC approval overview[cite: 3]
* Volunteers
  * Requirements & coverage overview per expedition[cite: 3]
  * Volunteer sign-ups (`[ems-volunteer-signup]`)[cite: 7]
  * Volunteer assignments, DBS tracking & overnight/day coverage[cite: 3, 7]
* Units & OSM Sync
  * Connection settings & tokenless OAuth2 sync[cite: 7]
  * Sync status - explorers and events mapping to OSM[cite: 7]
  * Explorers cache (`scout_id` anchor) & confirmed event attendance push to OSM[cite: 3]
  * DofE leader assignments, bulk leader invites & login status[cite: 7]
* Emails & Notifications
  * FluentSMTP integration & 12-month dispatch archive (`ems_notification_logs`)[cite: 3, 5]
  * Template management (`{{token}}` placeholders)[cite: 3, 7]
  * Automated deadline cron toggles & manual dispatch queue[cite: 3]
* Route Studio (`RouteEngineModule`)
  * Admin sandbox, OS Terrain 50 DEM tile manager & dynamic Naismith pacing settings[cite: 3, 4]
  * Standalone route test - GPX upload/export, versioned JSON route storage & A4 landscape eDofE print engine[cite: 3]
* System & Settings
  * Access control guard, OIDC + local WP login linking & role permissions[cite: 7]
  * Feature flags
  * Unified JSON backup, parity manifest & restore[cite: 4]
  * Security & reconciliation audit logs & shortcode reference[cite: 6, 7]
  * Help

### Shortcode Integration with Website
* Public & Pre-auth gateways (embedded in Elementor pages)
  * OIDC sign-up banner (`[ems_signup_banner]`) & Fluent Forms 6 & 7[cite: 7]
  * Volunteer availability wizard (`[ems-volunteer-signup]`)[cite: 7]
* Participant portal for Explorers and parents (`[ems-portal]`)[cite: 7]
  * My sign-ups & OSM link status
  * My expeditions — dates, teammates, and all-or-nothing Place Offer Accept / Query (with message) / Decline (by 31st Dec)[cite: 3, 6]
  * Training to-do, Tutor LMS progress & First Aid status[cite: 3, 7]
  * Embedded Route Planner — shared team route card co-editing, submission (14-day cutoff), revision history & LiC feedback[cite: 3, 6]
* Unit Leaders dashboard (`[ems-leader-dashboard]`)[cite: 7]
  * Unit sign-ups & reconciliation status (medical/contact PII redacted)[cite: 7]
  * Teams & expeditions with Explorers from my unit
* Leaders in Charge (LiC) operational portal (`[ems-lic-dashboard]`)
  * My assigned expeditions & volunteer roster
  * Teams on my expedition & readiness audit (first aid, training, acceptances)[cite: 3]
  * Route card inspection, inline annotation & approval/revision workflow[cite: 3, 6]

## Notifications
1. Sign-up (Event-triggered)
   * To Parent / Explorer — confirmation of sign-up
   * To DofE Leader — confirmation of sign-up
   * To Admin — sign-up processing or manual reconciliation needed
2. Sign-up data consistency & chasers (Cron-driven for due dates/deadlines + Manual)
   * To DofE Leader — sign-up inconsistencies or Explorers not shared with OSM
   * To Explorer / Parents — missing eDofE number or unlinked account chaser
3. Unit Leader onboarding (Manual / Event-triggered)
   * To DofE Leader — portal invitation with password setup link[cite: 7]
4. Expedition allocation & responses (Manual batch dispatch + Event-triggered responses)
   * To Explorer / Parents — combined Practice & Qualifier place offer (with date-conflict notice if applicable)[cite: 3]
   * To Admin — place offer accepted, queried (with participant/parent message), or declined[cite: 3, 6]
   * To DofE Leader / Admin — Explorer transferred between teams or award levels[cite: 6]
5. Training reminders (Cron-driven against expedition due dates)
   * To Explorer / Parents — online training & first aid incomplete reminder
6. Expedition update (Event-triggered)
   * To Explorer / Parents — expedition information updated (LiC allocated, joining instructions, or route parameters added)
7. Route cards due (Cron-driven against 14-day cutoff)
   * To Explorer / Parents — 14-day route card deadline approaching[cite: 3, 6]
8. Route card submitted (Event-triggered)
   * To Admin & LiC — route card submitted for review[cite: 3]
9. Route card feedback (Event-triggered)
   * To Explorer / Parents — route card approved
   * To Explorer / Parents — route card revisions requested (with LiC comments)[cite: 3, 6]

## Entities
* Participant Place Sign-up (`ems_participant_signups` — Form 6)[cite: 3, 7]
* Expedition Sign-up (`ems_expedition_signups` — Form 7)[cite: 3, 7]
* Explorer (`ems_osm_explorers` — anchored on `scout_id`)[cite: 3]
* Unit (`ems_units` & `ems_unit_patrols` OSM mappings)[cite: 7]
* Expedition (`expedition` CPT — single Practice or Qualifier weekend)[cite: 3, 7]
* Team (`ems_teams` & `ems_team_members` — persistent group of 4–7 Explorers across Practice & Qualifier, with stage overrides & all-or-nothing offer status)[cite: 3]
* Route Card (`ems_route_cards` — shared daily team route, versioned GPX/JSON, LiC review & feedback history)[cite: 3]
* Volunteer (`ems_volunteers` & `ems_volunteer_availability`)[cite: 7]
* Training Record (`TutorLmsAdapter` course completion & OSM First Aid expiry)[cite: 3, 7]
* Audit & Notification Log (`ems_audit_logs` & `ems_notification_logs` 12-month dispatch archive)[cite: 3, 5]