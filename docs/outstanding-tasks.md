# EMS Outstanding Tasks & Roadmap

This document consolidates all outstanding plans, specifications, and steps that have not yet been implemented for the Expedition Management System (EMS). This allows the main plan files to be archived while retaining a clear, unified checklist of pending work.

---

## Security Audit Remediation Tasks

*   [x] **SSRF Mitigation**: Replace raw `wp_remote_post` in [OSM_Sync_Auth_Handler.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/OSM_Sync_Auth_Handler.php#L171) and `wp_remote_get` in [Live_Driver.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Live_Driver.php#L35) with `wp_safe_remote_post` and `wp_safe_remote_get` respectively.
*   [x] **Credential & Leak Prevention**: Remove error logging of sensitive Bearer tokens/Authorization headers in [Live_Driver.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Live_Driver.php#L31-L32).
*   [x] **Test Suite Isolation**: Stub/mock database calls correctly and execute `Mockery::close()` in test tear-down routines to restore `global $wpdb` context cleanly.


---

## 1. Milestone 4: System-Wide Audit Logging & Log Viewer (Completed)

*   [x] **Centralized Audit Logger**:
    *   Create a reusable logger class (e.g. `EMS\Core\Audit_Logger`) using the existing `ems_audit_logs` schema to write audit rows capturing IP address, user agent, timestamp, action type, user ID, and target scout ID.
*   [x] **Instrumentation of Views & Updates**:
    *   Instrument all REST controllers, background tasks, and admin post actions to ensure critical actions are logged.
    *   *Updates to Log*: Team creations/deletions, member additions/moves/deletions, event configuration modifications, route status changes, and setting updates.
    *   *Views to Log*: Exporting rosters/participants, downloading GPX route files, and accessing personal/sensitive scout details (e.g. the existing ASN data check).
*   [x] **Audit Log Viewer UI**:
    *   Develop a Log Viewer interface on the EMS admin settings pages.
    *   Support filtering by Action Type, User ID/Name, Target Scout ID, and Date Range.
    *   Ensure proper authorization boundaries (accessible only to global EMS administrators/manage_options).
*   [x] **Authentication Auditing & Log Rotation**:
    *   Log OIDC login successes, OIDC failures, role mapping adjustments, and logouts.
    *   To protect the database size, design and implement a log retention policy (e.g. automatically purge logs older than 90 days or rotate logs once they exceed a row limit).


---

## 2. Milestone 5: Offline/Online Scout Manager Write-Back (Push-Back Sync) (Completed)

*   [x] **Data Sync Scope Assessment**:
    *   Determine exactly which data fields should be written back to OSM. Since EMS now maintains a master list of expedition preferences, participant signups, and team formations within its own database, we must determine which attributes (e.g. event attendance, patrol groups, custom flexi-record columns) actually need to map back to OSM fields vs. remaining local to EMS.
    *   Assess updating **OSM Events** (attendance and details) or **Flexi-records** rather than using the generic `updateScout` endpoint.
*   [x] **Authentication Context**:
    *   Explicitly define that all write-backs must take place using the admin OAuth flow (personal client OAuth token triggered via admin screens), rather than within standard frontend user OIDC logins.
*   [x] **Write Preview & Safety Checks**:
    *   Build a UI preview step showing the administrator exactly what updates/syncs will be executed before any data is sent to the OSM API.
*   [x] **Strict Rate Limit & Error Handling**:
    *   Rate limits and error headers returned by the OSM API **MUST be totally respected** to avoid triggering client bans or API blocks.
    *   *Rate-Limit Lockout Safety Valve*: Implement a prominent warning banner in the WordPress Admin Dashboard if the `ems_rate_limit_status` or `ems_api_blocked` options are active, warning administrators against manual sync retries during the cooldown period.
*   [x] **OSM Write Operations**: Implement write operations in `OSM_API_Client` for events and flexi-records.
*   [x] **Admin-Driven Write-Back Recovery UI**:
    *   Build a queue manager interface within the Settings page allowing the administrator, while authenticated with an active OSM OAuth session, to view, review, and manually trigger retries for failed write-back jobs (stored in the `ems_failed_pushback_queue` option). This avoids the need for a background cron job which would violate the "no server-side token storage" security policy.
*   [x] **OSM Event Invitations**: Build tools to trigger and dispatch event invitations from EMS back to OSM.

---

## 3. Milestone 6: Portability, Hardening & Staging Deployment (Pending)

*   [ ] **Unified Backups Engine**: Develop settings tab to export and import all EMS custom tables and options as a single JSON backup.
*   [ ] **Security Hardening Review**:
    *   Ensure that child metadata enrichment debug logging is properly guarded via settings toggle and can be easily toggled on/off to keep system logs clean.
*   [ ] **Website Integration**: Review how shortcodes fit and render inside the parent site's templates and stylesheets.
*   [ ] **Standardise Plugin Headers**: Standardise plugin name, author, and description metadata with the Google login sibling plugin as part of staging preparation.
*   [ ] **Staging Deployment**: Deploy the hardened plugin to the staging site to allow stakeholders (John / Cheryl / Leaders) to view and test it.

---

## 4. Milestone 7: Compliance & Training Progress Monitoring (Pending)

Based on [held-milestone-5-implementation-spec.md](file:///Users/davidstrachan/Projects/expedition-management-system/docs/archive/held-milestone-5-implementation-spec.md):

*   [ ] **Automated Tutor LMS Enrollment System**:
    *   *Real-Time Assignment Hook*: When an explorer is assigned to a team or event via the REST API, if they have a valid, non-zero `wp_user_id`, verify their course enrollments (`tutor_enrolled` custom post type). Auto-insert enrollment posts for missing required courses defined in `ems_course_requirements` meta.
    *   *OIDC Login Catch-up Trigger*: In `OIDC_Login_Handler`, once a new WP user account is mapped to a `scout_id`, resolve all their current team assignments and enroll them in any required courses for those events.
*   [ ] **First Aid Warning Flags**:
    *   Implement team validation logic on the Expedition Board React SPA: highlight teams with a warning badge (e.g., `⚠️ No First Aider`) if no rostered member has a registered first aid qualification (`first_response` or `full_first_aid`).
*   [ ] **Scale-Testing Tutor LMS Seeder**:
    *   Extend `bin/seed-tutor-lms.php` to query all active explorers with `wp_user_id > 0` and randomly seed their training states (Not Enrolled, In Progress, Complete) to support compliance matrix load testing.

---

## 5. Milestone 8: Unit Leader Integration Portal & Kit Supply (Pending)

*   [ ] **Unit Leader Portal**: Build a dashboard showing all explorer allocations and status updates scoped to leaders' managed ESU units.
*   [ ] **Kit List Supply Tool**: Track and map gear/equipment requests back to specific units. Determine if this content should be in a post or a database table.
*   [ ] **Tent & Gear Allocations**: Create the database table `ems_team_tent_groups` and build a UI in the Expedition Board to assign members to tents and map the ESU unit responsible for supplying gear.
*   [ ] **Frontend Leader Landing Page**: Extend the unified frontend portal SPA `[ems-portal]` to support the `ems_leader` role, allowing leaders to authenticate via the OIDC login flow to preview their assigned expeditions, dates, and ESU unit rosters.

---

## 6. Milestone 9: Route Submission & LiC Review Workflow (Pending)

*   [ ] **Upload Handling**: Build `[ems-route-submit]` and `[ems-route-status]` frontend forms. Restrict file types to `.gpx` and `.pdf`, enforce naming conventions (`[Team_Code]_[File_Type]_v[Version].[ext]`), and auto-increment version numbers.
*   [ ] **Secure Storage Proxy**: Block direct access to route files using `.htaccess` (e.g. `/uploads/ems-secure/`) and serve them via a custom REST proxy `/ems/v1/download-route/{id}` gated by participant/leader permissions.
*   [ ] **LiC Review Panel**: Build an interactive feedback form to request modifications (status `feedback_required`) or approve routes (status `approved`), displaying version history side-by-side.
*   [ ] **PII & Medical Export Design**: Conduct a wider review on how to securely generate and download emergency medical contact sheets for LiCs, determining how to access the required OSM data dynamically without violating server-side token storage constraints.

---

## 7. Milestone 10: Email Notification Engine & SMTP Logging (Pending)

*   [ ] **Workflow Triggers**: Trigger emails on events like: Signup Received, Invite ESU Share (unit leader), Volunteer Availability, Assignment Confirmed, and Route Review Feedback.
*   [ ] **SMTP Delivery**: Route notifications using standard `wp_mail()` wrappers configured to run via host SMTP.
*   [ ] **Email Logging**: Create custom table `ems_email_logs` to capture recipient, email type, status (sent/failed), and timestamp for diagnostics.

---

## 8. Milestone 11: Expedition Board Enhancements & Document Export (Pending)

*   [ ] **Unassigned Sidebar**: Add an unassigned explorer sidebar on the Expedition Board for fast drag-and-drop allocations.
*   [ ] **Safeguarding Flags**: Highlight explorers turning 18 before or during the expedition dates.
*   [ ] **Maps Integration**: Add Leaflet map previews for start/end coordinates.
*   [ ] **Export Engine**: Build print templates for Team Sheets (PDF), Volunteer Cover Sheets (Excel), and Route Cards (Zip bundle).

---

## 9. QA, Rollout & Operational Tasks

*   [ ] **Documentation & Screen Review**: Run a detailed review of all active admin screens and portal pages, fixing styling issues and completing any missing inline documentation.
*   [ ] **Rollout & Guides**:
    *   Coordinate alignment/onboarding with John / Cheryl.
    *   Draft processes and manuals for new expedition workflows (e.g. Leader-in-Charge engagement instructions).

---

## 10. Future Enhancements & Extensions

*   [ ] **Network Expedition Signups**: Add support and rules handling signups/eligibility for Network members (aged 18–25).
*   [ ] **Expedition Risk Assessment & Docs**: Add options to attach Risk Assessment (RA) or Parent Info documents to expeditions (or determine if this should be delegated to OSM).
*   [ ] **Volunteers' Front-End View**: Create a public/login frontend view allowing adult volunteers to log in, see which expeditions they are assigned to, check what they volunteered for, and access helper documents.
*   [ ] **Two-Factor Authentication (2FA)**: Review site configuration and verify 2FA settings for administration profiles.
