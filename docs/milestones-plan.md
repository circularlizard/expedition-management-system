# EMS Milestone Roadmap & Execution Plan

This document outlines the roadmap for the remaining deliverables of the Expedition Management System (EMS). It details what has been achieved for each milestone, what needs to be implemented next, and serves as a planning workspace to expand on requirements.

---

## Milestone 1: Signup Processing & Unit Leader Outreach
*Collects DofE levels, payment validation, and expedition preferences, enabling manual admin processing and leader notification.*

*   **Achieved**:
    *   Dynamic parent-child dropdown fields populated on registration forms.
    *   Stripe payment hooks connecting sandbox gateway state changes to local record statuses.
    *   Automatic resolution of ESU units, pre-populating parent, explorer, and leader emails in hidden fields.
    *   Read-only back-office list view (`ems_signups` table mapping).
*   **Next Steps (Remains)**:
    *   [ ] **Manual Actions**: Add triggers to the Signups list view to mark registrations as "Processed" or "Archived".
    *   [ ] **Leader Outreach**: Build an interface or triggers to email unit leaders requesting their OSM section share when a child signs up.
    *   [ ] **Reconciliation View**: Build an admin dashboard tab to reconcile Fluent Forms signups against the OSM sync explorer reference list.
    *   [ ] **Fuzzy Matching Logic**: Implement ordered matching priority: hidden Scout ID match, fallback to case-insensitive email match, and second fallback to first/last name matches. Displays unlinked accounts as "Proposed Link" or "New Recruit".
    *   [ ] **Manual Link Dialog**: Create a search dialog dialog overlay to manually link a signup to a synced `scout_id` (triggering `/reconcile` REST endpoint).
    *   [ ] **ESU Unit Override Dropdown**: Render an editable select dropdown allowing admins to manually override or assign ESU units, showing warnings for unassigned units or options for multiple mapped units.
    *   [ ] **REST Endpoints**: Register `GET ems/v1/signups`, `POST ems/v1/signups/{id}/reconcile`, and `POST ems/v1/signups/{id}/process`.
    *   [ ] **Test Data Seeding**: Create a signup test data generator. Generate multiple variants of form submissions to represent realistic test scenarios (different levels, payment statuses, and units), and scale this using AI expansion to produce a large, diverse dataset.

---

## Milestone 2: Team Formation, Event Dates & Calendar Management
*Assigns explorers to specific dates and teams, and handles progressive details (dates → teams → route cards).*

*   **Achieved**:
    *   React Expedition Board SPA allowing seasons, events, and teams CRUD.
    *   Interactive drag-and-drop movement of explorers between teams/events.
    *   Automatic sequential team code generation (e.g. `H-SP1-1`).
    *   Contiguous layout and team size warning validation (checks for 4–7 members).
*   **Next Steps (Remains)**:
    *   [ ] Implement a calendar dashboard interface showing chronological timelines of all practice/qualifying events.
    *   [ ] Add communication notifications (emails or alerts) triggered when explorers are assigned to dates or teams.
    *   [ ] Track Additional Support Needs (ASN) alongside team details.
    *   [ ] **Cross-Screen State Synchronization**: Implement a synchronization mechanism (e.g. React Context, broadcast events, or shared local caching) so that operations completed on one dashboard page (such as team reassignments on the Expedition Board) immediately refresh or propagate changes to other dashboard views (such as the Explorer List) without requiring manual browser page refreshes.

---

## Milestone 3: Compliance & Training Progress Monitoring
*Specifies training requirements for expeditions, validates completions, and monitors team health.*

*   **Achieved**:
    *   Course requirement configuration per event in Post Meta.
    *   Compliance status overview grid mapping explorers to completion states.
    *   Batch-optimized Tutor LMS matrix retrieval completing in only two DB queries.
    *   First aid status tracking (configurable levels: `none`, `first_response`, `full_first_aid`) on explorer rosters.
*   **Next Steps (Remains)**:
    *   [ ] Develop an automated enrollment script that hooks into EMS team assignments to register explorers in corresponding Tutor LMS courses.
    *   [ ] Implement automatic warnings on the Expedition Board when a team does not have a member with an active first aid qualification.
    *   [ ] **Test Data Seeding**: Build a mock data generator for Tutor LMS completion and enrollment states (Complete, In Progress, Not Enrolled) to test training compliance matrices at scale.

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

## Milestone 5: Adult Volunteer Availability Mapping
*Gathers adult availability, maps volunteer coverage across dates, and notifies cover assignments.*

*   **Achieved**:
    *   Database table `ems_volunteer_availability` handles schema mapping.
    *   Volunteer admin menu registered.
*   **Next Steps (Remains)**:
    *   [ ] Build the volunteer signup front-facing form.
    *   [ ] Enqueue React components on the Volunteers Admin page to render an availability scheduling grid.
    *   [ ] Build an assignment engine to link volunteers to events and alert them of scheduled cover.
    *   [ ] **Staffing Deficit Logic**: Define supervisor/assessor ratios (e.g. "requires 2 supervisors, 1 assessor") and render alert badges (🔴 Deficit, 🟡 Pending, 🟢 Confirmed) based on day-to-day coverage.

---

## Milestone 6: Unit Leader Integration Portal & Kit Supply
*Provides unit leaders with visibility into allocation, signup states, and kit requirements.*

*   **Achieved**:
    *   ESU unit leader contact configurations mapping directory.
*   **Next Steps (Remains)**:
    *   [ ] Create a Unit Leader portal showing all explorer allocations from their ESU unit.
    *   [ ] Implement kit lists tracking tool mapping equipment requests to specific units.
    *   [ ] **Tent & Gear Allocations**: Create custom table `ems_team_tent_groups` and build a UI in the Expedition Board to assign members to tents and map the ESU unit responsible for supplying gear.

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

## Milestone 10: Email Notification Engine & SMTP Logging
*Handles dispatching automated email notifications on key state changes with SMTP configuration and audit logging.*

*   **Achieved**:
    *   No automatic triggers are active (emails are currently delegated to Fluent Forms for signups).
*   **Next Steps (Remains)**:
    *   [ ] **Workflow Triggers**: Implement triggers for Signup Received (parent/explorer), Invite ESU Share (unit leader), Volunteer Availability (admin), Assignment Confirmed (explorer/parent), and Route Review Feedback (explorer/parent).
    *   [ ] **SMTP Delivery**: Route notifications using standard `wp_mail()` wrappers configured to run via host SMTP.
    *   [ ] **Email Logging**: Create custom table `ems_email_logs` to capture email type, recipient, status (sent/failed), and timestamp for diagnostics.

---

## Milestone 11: Expedition Board Enhancements & Document Export
*Adds team organization tools, compliance safety indicators, maps preview, and document printing templates.*

*   **Achieved**:
    *   Standard drag-and-drop rosters on the board.
*   **Next Steps (Remains)**:
    *   [ ] **Unassigned Sidebar**: Add an unassigned explorer sidebar on the Expedition Board for fast drag-and-drop allocations.
    *   [ ] **Safeguarding Flags**: Highlight explorers turning 18 before or during the expedition dates.
    *   [ ] **Maps Integration**: Add Leaflet map previews for start/end coordinates.
    *   [ ] **Export Engine**: Build print templates for Team Sheets (PDF), Volunteer Cover Sheets (Excel), and Route Cards (Zip bundle).
