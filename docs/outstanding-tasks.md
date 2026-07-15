# EMS Outstanding Tasks & Roadmap

This document consolidates all outstanding plans, specifications, and steps that have not yet been implemented for the Expedition Management System (EMS). This allows the main plan files to be archived while retaining a clear, unified checklist of pending work.

---

## 1. Milestone 5: Compliance & Training Progress Monitoring (Held / Deferred)

Based on [held-milestone-5-implementation-spec.md](file:///Users/davidstrachan/Projects/expedition-management-system/docs/archive/held-milestone-5-implementation-spec.md):

*   [ ] **Automated Tutor LMS Enrollment System**:
    *   *Real-Time Assignment Hook*: When an explorer is assigned to a team or event via the REST API, if they have a valid, non-zero `wp_user_id`, verify their course enrollments (`tutor_enrolled` custom post type). Auto-insert enrollment posts for missing required courses defined in `ems_course_requirements` meta.
    *   *OIDC Login Catch-up Trigger*: In `OIDC_Login_Handler`, once a new WP user account is mapped to a `scout_id`, resolve all their current team assignments and enroll them in any required courses for those events.
*   [ ] **First Aid Warning Flags**:
    *   Implement team validation logic on the Expedition Board React SPA: highlight teams with a warning badge (e.g., `⚠️ No First Aider`) if no rostered member has a registered first aid qualification (`first_response` or `full_first_aid`).
*   [ ] **Scale-Testing Tutor LMS Seeder**:
    *   Extend `bin/seed-tutor-lms.php` to query all active explorers with `wp_user_id > 0` and randomly seed their training states (Not Enrolled, In Progress, Complete) to support compliance matrix load testing.

---

## 2. Milestone 6: Unit Leader Integration Portal & Kit Supply (Pending)

*   [ ] **Unit Leader Portal**: Build a dashboard showing all explorer allocations and status updates scoped to leaders' managed ESU units.
*   [ ] **Kit List Supply Tool**: Track and map gear/equipment requests back to specific units.
*   [ ] **Tent & Gear Allocations**: Create the database table `ems_team_tent_groups` and build a UI in the Expedition Board to assign members to tents and map the ESU unit responsible for supplying gear.
*   [ ] **Frontend Leader Landing Page**: Create a frontend website portal page `[ems-leader-portal]` allowing leaders to preview their assigned expeditions, dates, and rosters without WP admin dashboard login.

---

## 3. Milestone 7: Offline/Online Scout Manager Write-Back (Push-Back Sync) (Pending)

*   [ ] **OSM Write Operations**: Implement write operations in `OSM_API_Client` targeting the `updateScout` endpoint.
*   [ ] **Failed Write-Back Recovery**: Build a background dispatcher to process and retry failed jobs stored in the `ems_failed_pushback_queue` option.
*   [ ] **OSM Event Invitations**: Build tools to trigger and dispatch event invitations from EMS back to OSM.

---

## 4. Milestone 8: Environment Replication & Configuration Portability (Pending)

*   [ ] **Export/Import Engine**: Implement a backup and restore mechanism for EMS-specific database configurations and WP options.
*   [ ] **ESU Unit Mapping Portability**: Create an export/import utility (JSON/CSV) to migrate ESU unit leader listings, patrol linkages, and active section structures between development, staging, and production environments.
*   [ ] **Environment Replicator CLI/Script**: Setup automated deployment helper scripts to initialize database tables, import default settings, and verify system compliance on a clean WordPress target.

---

## 5. Milestone 9: Route Submission & LiC Review Workflow (Pending)

*   [ ] **Upload Handling**: Build `[ems-route-submit]` and `[ems-route-status]` frontend forms. Restrict file types to `.gpx` and `.pdf`, enforce naming conventions (`[Team_Code]_[File_Type]_v[Version].[ext]`), and auto-increment version numbers.
*   [ ] **Secure Storage Proxy**: Block direct access to route files using `.htaccess` (e.g. `/uploads/ems-secure/`) and serve them via a custom REST proxy `/ems/v1/download-route/{id}` gated by participant/leader permissions.
*   [ ] **LiC Review Panel**: Build an interactive feedback form to request modifications (status `feedback_required`) or approve routes (status `approved`), displaying version history side-by-side.
*   [ ] **PII & Medical Export**: Provide authorized LiCs and global admins with secure, logged emergency medical contact sheet downloads generated dynamically from current OSM data (never stored statically).

---

## 6. Milestone 10: Email Notification Engine & SMTP Logging (Held / Deferred)

*   [ ] **Workflow Triggers**: Trigger emails on events like: Signup Received, Invite ESU Share (unit leader), Volunteer Availability, Assignment Confirmed, and Route Review Feedback.
*   [ ] **SMTP Delivery**: Route notifications using standard `wp_mail()` wrappers configured to run via host SMTP.
*   [ ] **Email Logging**: Create custom table `ems_email_logs` to capture recipient, email type, status (sent/failed), and timestamp for diagnostics.

---

## 7. Milestone 11: Expedition Board Enhancements & Document Export (Held / Deferred)

*   [ ] **Unassigned Sidebar**: Add an unassigned explorer sidebar on the Expedition Board for fast drag-and-drop allocations.
*   [ ] **Safeguarding Flags**: Highlight explorers turning 18 before or during the expedition dates.
*   [ ] **Maps Integration**: Add Leaflet map previews for start/end coordinates.
*   [ ] **Export Engine**: Build print templates for Team Sheets (PDF), Volunteer Cover Sheets (Excel), and Route Cards (Zip bundle).
