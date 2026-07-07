# EMS Milestone Roadmap & Execution Plan

This document outlines the roadmap for the remaining deliverables of the Expedition Management System (EMS). It details what has been achieved for each milestone, what needs to be implemented next, and serves as a planning workspace to expand on requirements.

## Roadmap Summary & Progress

| Phase | Milestone | Focus Area | Status |
|---|---|---|---|
| Phase 1 | Milestone 1: Signup Processing & Unit Leader Outreach | Fluent Forms sync, Payments, and Reconciliation | **Complete** |
| Phase 2 | Milestone 2: Team Formation, Event Dates & Calendar Management | React Expedition Board, Calendar view, and State Sync | **Complete** |
| Phase 2.5 | [Milestone 2.5: M1/M2 Carryover](#milestone-25-m1m2-carryover) | Participant export, Season CPT cleanup, BroadcastChannel sync, Assignment emails | Pending |
| Phase 3 | [Milestone 3: Adult Volunteer Availability Mapping](#milestone-3-adult-volunteer-availability-mapping) | Scheduling grids and supervisor deficit calculations | Pending |
| Phase 4 | [Milestone 4: Explorer & Parent Front-Facing Web Portal](#milestone-4-explorer--parent-front-facing-web-portal) | Explorer & Parent shortcode SPAs and timeline tracking | Pending |
| Phase 5 | [Milestone 6: Unit Leader Integration Portal & Kit Supply](#milestone-6-unit-leader-integration-portal--kit-supply) | ESU unit visibility, tent groups, and Leader Portal | Pending |
| Phase 6 | [Milestone 7: Offline/Online Scout Manager Write-Back (Push-Back Sync)](#milestone-7-offlineonline-scout-manager-write-back-push-back-sync) | Writing back status updates and invitations to OSM | Pending |
| Phase 7 | [Milestone 8: Environment Replication & Configuration Portability](#milestone-8-environment-replication--configuration-portability) | Backup/restore tools, CLI scripts, and configuration exports | Pending |
| Phase 8 | [Milestone 9: Route Submission & LiC Review Workflow](#milestone-9-route-submission--lic-review-workflow) | GPX/PDF secure uploads, permissions check proxy, and review panel | Pending |
| Held | [Milestone 5: Compliance & Training Progress Monitoring](#milestone-5-compliance--training-progress-monitoring) | Tutor LMS automated enrollments and first aid warning flags | Held / Deferred |
| Held | [Milestone 10: Email Notification Engine & SMTP Logging](#milestone-10-email-notification-engine--smtp-logging) | State-triggered notifications and mail audit logs | Held / Deferred |
| Held | [Milestone 11: Expedition Board Enhancements & Document Export](#milestone-11-expedition-board-enhancements--document-export) | Drag-and-drop drawers, safeguarding warnings, and printable exports | Held / Deferred |

---

## Milestone 1: Signup Processing & Unit Leader Outreach
*Collects DofE levels, payment validation, and expedition preferences, enabling manual admin processing and leader notification.*

**Status: Complete** — Implementation spec archived at `docs/archive/milestone-1-implementation-spec.md`.

All core items completed, including reconciliation, fuzzy matching, manual link dialog, DofE push, leader outreach, explorer list, and ESU override. Remaining carryover items moved to [Milestone 2.5](#milestone-25-m1m2-carryover).

---

## Milestone 2: Team Formation, Event Dates & Calendar Management
*Assigns explorers to specific dates and teams, and handles progressive details (dates → teams → route cards).*

**Status: Complete** — Implementation spec archived at `docs/archive/milestone-2-implementation-spec.md`.

All core items completed, including Events Dashboard, calendar view, first aid warnings, WhatsApp QR codes, UNALLOCATED teams, and ASN tracking. Remaining carryover items moved to [Milestone 2.5](#milestone-25-m1m2-carryover).

---

## Milestone 2.5: M1/M2 Carryover
*Consolidates the small number of items that carried over from Milestones 1 and 2.*

Full specification: [milestone-2.5-implementation-spec.md](milestone-2.5-implementation-spec.md)

*   **Next Steps (Remains)**:
    *   [ ] **Participant Download (M1 carryover)**: Build an interface that allows the admin user to download some or all of the participant records (CSV/Excel).
    *   [ ] **Season CPT Removal (M2 carryover)**: Remove `season` CPT registration from `CPT_Registry.php` — migration already exists in `Table_Installer`, just needs registration cleanup.
    *   [ ] **Cross-Screen State Synchronization (M2 carryover)**: Implement a `BroadcastChannel('ems-state-sync')` mechanism to sync updates between Expedition Board rosters and Explorer Lists.
    *   [ ] **Communication Notifications (M2 carryover)**: Add emails or alerts triggered when explorers are assigned to dates or teams.

---

## Milestone 3: Adult Volunteer Availability Mapping
*Gathers adult availability, maps volunteer coverage across dates, and notifies cover assignments.*

**Status: Complete** — Implementation spec archived at: [milestone-3-implementation-spec.md](archive/milestone-3-implementation-spec.md)

---

## Milestone 4: Explorer & Parent Front-Facing Web Portal
*Exposes expedition details, team status, route cards, and training progress to participants.*

*   **Achieved**:
    *   Custom Page Templates (`ems-page-template.php`) registered for rendering.
    *   Theme headers/footers mapping support.
*   **Next Steps (Remains)**:
    *   [ ] Build the `[ems-explorer-portal]` shortcode SPA showing:
        *   Assigned events, dates, and locations.
        *   Teammate lists and assigned Leader-in-Charge.
        *   GPX and route card submission history.
        *   Tutor LMS training checklists.
        *   Resources: WhatsApp group joining links, QR codes, and kit checklists.
    *   [ ] Build the `[ems-parent-portal]` shortcode SPA showing:
        *   Multi-child selection cards.
        *   Expedition/level signup forms.
        *   Child progress status timeline ("Signed Up" -> "Assigned" -> "Team Formed" -> "Route Approved").

---

## Milestone 6: Unit Leader Integration Portal & Kit Supply
*Provides unit leaders with visibility into allocation, signup states, and kit requirements.*

*   **Achieved**:
    *   ESU unit leader contact configurations mapping directory.
*   **Next Steps (Remains)**:
    *   [ ] **Unit Leader Portal**: Create a Unit Leader portal showing all explorer allocations from their ESU unit.
    *   [ ] **Kit List Supply**: Implement kit lists tracking tool mapping equipment requests to specific units.
    *   [ ] **Tent & Gear Allocations**: Create custom table `ems_team_tent_groups` and build a UI in the Expedition Board to assign members to tents and map the ESU unit responsible for supplying gear.
    *   [ ] **Frontend Leader Landing Page**: Create a frontend website portal page `[ems-leader-portal]` and public signup forms allowing leaders to log in, review their own assigned expeditions/dates, and preview who they have signed up to support without logging into the WordPress admin backend.

---

## Milestone 7: Offline/Online Scout Manager Write-Back (Push-Back Sync)
*Pushes team selections and group assignments back into Online Scout Manager flexi-records.*

*   **Achieved**:
    *   OAuth client-credentials structure mapping.
    *   Local tables tracking dirty states (`last_local_update_at`).
*   **Next Steps (Remains)**:
    *   [ ] Implement write operations in `OSM_API_Client` (`updateScout` endpoint POST delivery).
    *   [ ] Build the failed write-back recovery dispatcher (`ems_failed_pushback_queue` processor).
    *   [ ] **OSM Event Invitations**: Build tools to trigger and dispatch event invitations from EMS back to OSM.

---

## Milestone 8: Environment Replication & Configuration Portability
*Enables replicating environment setups across instances by exporting and importing system configuration, settings, and ESU unit mappings.*

*   **Achieved**:
    *   Basic Settings panel registered under `EMS -> Settings` storing configurations in standard WP Options.
*   **Next Steps (Remains)**:
    *   [ ] **Export/Import Engine**: Implement a backup and restore mechanism for EMS-specific database configurations and WP options.
    *   [ ] **ESU Unit Mapping Portability**: Create an export/import utility (JSON/CSV) to migrate ESU unit leader listings, patrol linkages, and active section structures between development, staging, and production environments.
    *   [ ] **Environment Replicator CLI/Script**: Setup automated deployment helper scripts to initialize database tables, import default settings, and verify system compliance on a clean WordPress target.

---

## Milestone 9: Route Submission & LiC Review Workflow
*Enables teams or parents to upload route planning files and Leader-in-Charge (LiC) to review and approve them.*

*   **Achieved**:
    *   Custom table `ems_route_submissions` registered in schema.
*   **Next Steps (Remains)**:
    *   [ ] **Upload Handling**: Build `[ems-route-submit]` and `[ems-route-status]` forms, restricting file types to `.gpx` and `.pdf`, enforcing standard names (`[Team_Code]_[File_Type]_v[Version].[ext]`), and auto-incrementing the version number.
    *   [ ] **Secure Storage Proxy**: Store files outside public directories (e.g. `/uploads/ems-secure/` blocked by `.htaccess`) and serve them via a custom REST proxy `/ems/v1/download-route/{id}` gated by participant/leader permissions.
    *   [ ] **LiC Review Panel**: Build an interactive feedback form to request modifications (status `feedback_required`) or approve routes (status `approved`), displaying version history side-by-side.
    *   [ ] **PII & Medical Export**: Provide authorized LiCs and global admins with secure, logged emergency medical contact sheet downloads generated dynamically from current OSM data (never stored statically).

---

## Held / Deferred Milestones

### Milestone 5: Compliance & Training Progress Monitoring
*Specifies training requirements for expeditions, validates completions, and monitors team health.*

*   **Achieved**:
    *   Course requirement configuration per event in Post Meta.
    *   Compliance status overview grid mapping explorers to completion states.
    *   Batch-optimized Tutor LMS matrix retrieval completing in only two DB queries.
    *   First aid status tracking (configurable levels: `none`, `first_response`, `full_first_aid`) on explorer rosters.
*   **Next Steps (Remains)**:
    *   [ ] Develop an automated enrollment script that hooks into EMS team assignments to register explorers in corresponding Tutor LMS courses.
    *   [ ] Implement automatic warnings on the Expedition Board when a team does not have a member with an active first aid qualification.
    *   [ ] **Test Data Seeding**: Build a mock data generator for Tutor LMS completion and enrollment states (Complete, In Progress, Not Enrolled) to test training compliance matrices at scale. matrices at scale.

### Milestone 10: Email Notification Engine & SMTP Logging
*Handles dispatching automated email notifications on key state changes with SMTP configuration and audit logging.*

*   **Achieved**:
    *   No automatic triggers are active (emails are currently delegated to Fluent Forms for signups).
*   **Next Steps (Remains)**:
    *   [ ] **Workflow Triggers**: Implement triggers for Signup Received (parent/explorer), Invite ESU Share (unit leader), Volunteer Availability (admin), Assignment Confirmed (explorer/parent), and Route Review Feedback (explorer/parent).
    *   [ ] **SMTP Delivery**: Route notifications using standard `wp_mail()` wrappers configured to run via host SMTP.
    *   [ ] **Email Logging**: Create custom table `ems_email_logs` to capture email type, recipient, status (sent/failed), and timestamp for diagnostics.

### Milestone 11: Expedition Board Enhancements & Document Export
*Adds team organization tools, compliance safety indicators, maps preview, and document printing templates.*

*   **Achieved**:
    *   Standard drag-and-drop rosters on the board.
*   **Next Steps (Remains)**:
    *   [ ] **Unassigned Sidebar**: Add an unassigned explorer sidebar on the Expedition Board for fast drag-and-drop allocations.
    *   [ ] **Safeguarding Flags**: Highlight explorers turning 18 before or during the expedition dates.
    *   [ ] **Maps Integration**: Add Leaflet map previews for start/end coordinates.
    *   [ ] **Export Engine**: Build print templates for Team Sheets (PDF), Volunteer Cover Sheets (Excel), and Route Cards (Zip bundle).
