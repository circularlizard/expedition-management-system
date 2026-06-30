# EMS Future Phases — Outline Specifications

This document provides outline specifications and architectural guidelines for features and phases of the Expedition Management System (EMS) that do not yet have active step-by-step implementation specs.

---

## Spec 5: Participant Portals (Explorer & Parent SPAs)

### 1. Explorer Portal SPA (`[ems-explorer-portal]`)
* **Objective**: Provide explorers with a single-page view of their active season, team details, and outstanding tasks.
* **UI Structure**:
  * **My Team**: Renders team code (e.g. `H-SP1-1`), teammate list, and assigned Leader in Charge (LiC).
  * **Training Status**: Renders completed Tutor LMS courses and lists remaining requirements with links to courses.
  * **Expedition Details**: Renders date, start/end locations, transport type, and route planning info (rich text maps/rules).
  * **Resources**: WhatsApp group joining links / QR codes, kit checklists.

### 2. Parent Portal SPA (`[ems-parent-portal]`)
* **Objective**: Allow parents to manage sign-ups and view details for multiple children from a single login.
* **UI Structure**:
  * **Child Selector**: Multi-child card view displaying active levels and link statuses for children mapped under user meta `ems_children`.
  * **DofE Sign Up**: Pre-populates the Fluent Form with the selected child's `scout_id` for level/expedition signup.
  * **Child Status Timeline**: Renders current status (e.g. "Signed Up" $\rightarrow$ "Assigned to Event" $\rightarrow$ "Team Formed" $\rightarrow$ "Route Approved").

---

## Spec 6: Route Submission & LiC Review Workflow

### 1. Online Submission (`[ems-route-submit]` & `[ems-route-status]`)
* **Objective**: Enable teams (or parents) to submit planning files.
* **Upload Handler**:
  * Restricts file types to `.gpx` (route line) and `.pdf` (route card/notes).
  * Enforces naming standard: `[Team_Code]_[File_Type]_v[Version].[ext]` (e.g., `H-SP1-1_RouteCard_v2.pdf`).
  * Auto-increments the `version` column in the `ems_route_submissions` table on each upload.
* **Security Proxy**: Files are stored outside public directories (e.g. `/uploads/ems-secure/`). Access is gated behind a custom REST proxy `/ems/v1/download-route/{id}` checking the user's role (`ems_explorer`, `ems_parent`, or `ems_leader`).

### 2. LiC Route Review Panel
* **Objective**: Allow Leaders in Charge to review, annotate, and approve route submissions.
* **UI & Logic**:
  * **Feedback Form**: Renders a rich-text input area to write corrections.
  * **Action Buttons**: "Request Modifications" (sets status to `feedback_required`) or "Approve Route" (sets status to `approved` and updates team metadata).
  * **Version History**: Lists all historical uploads with associated feedback side-by-side.

### 3. PII & Medical Emergency Data Export
* **Objective**: Allow authorized LiCs to securely download medical and emergency contact sheets.
* **Security Rules**:
  * Only accessible to the user registered as `ems_lic_id` on the expedition or global admins.
  * Downloads are logged (audit trail in database).
  * Files are dynamically generated from current OSM reference data (never cached or stored statically in public uploads).

---

## Spec 7: Volunteer Management & Deficit Tracking

### 1. Volunteer Dashboard SPA (`[ems-volunteer-dashboard]`)
* **Objective**: Gather adult volunteer availability.
* **Form & Calendar**:
  * Calendar grid displaying all open expedition events for the season.
  * Options for each event: "Whole Expedition" checkbox, or individual date-toggles plus "Overnight Stay" checkboxes.
  * Availability data is written to the custom `ems_volunteer_availability` table.

### 2. Volunteer Command Center (Admin)
* **Objective**: Manage assignments and identify staffing shortfalls.
* **Staffing Deficit Logic**:
  * Each expedition configuration defines minimum volunteer thresholds (e.g., "Requires 2 supervisors and 1 assessor").
  * Dashboard aggregates availability data per event day and overlays coverage markers:
    * 🔴 **Deficit**: Coverage falls below minimum requirements.
    * 🟡 **Pending**: Minimum coverage met but includes unconfirmed volunteers.
    * 🟢 **Confirmed**: Minimum requirements fully staffed and confirmed.
  * Admin can click a row to toggle `confirmed = 1` for individual volunteers.

---

## Spec 8: Unit Leaders Portal & Kit Supply Tracking

### 1. Unit Leader Dashboard
* **Objective**: Give ESU (Explorer Scout Unit) leaders visibility into sign-ups, team allocations, and kit duties.
* **UI**: Gated to the `ems_leader` role. Filters participants by the leader's section ID (`ems_section_ids`). Displays who has signed up, their training status, and what event dates they are assigned to.

### 2. Kit & Tent Group Allocation
* **Objective**: Coordinate gear allocation across different ESU units.
* **Schema & Model**:
  * A custom table mapping tent groups to ESU units:
    ```sql
    CREATE TABLE IF NOT EXISTS {$prefix}ems_team_tent_groups (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        team_post_id BIGINT UNSIGNED NOT NULL,
        group_number INT             NOT NULL,
        scout_ids    TEXT            NOT NULL, -- Serialized/JSON list of scout_ids in this tent
        supply_unit  VARCHAR(100)             DEFAULT NULL, -- ESU unit providing the gear
        PRIMARY KEY (id)
    ) {$charset};
    ```
  * Admin UI within the Expedition Board to group team members into tents and select the ESU responsible for supplying the tent/stoves.

---

## Spec 9: Outbound Sync (OSM Push-back Engine)

### 1. Field Mapping Writer
* **Objective**: Write EMS expedition and team status data back to OSM.
* **Logic**:
  * The `ems_osm_field_map` configuration determines which column IDs (e.g., `f_1`, `f_2`) in the OSM Flexi-record correspond to fields like `practice_group`, `practice_status`, `qualifier_group`, etc.
  * Updates are grouped and written back via a secure OAuth POST call to OSM `updateScout` endpoint.

### 2. Push-back Queue & Offline Retry
* **Objective**: Ensure temporary API drops or rate limits do not lose sync states.
* **Failed Jobs Table**:
  * Database queue stored under Option key `ems_failed_pushback_queue`.
  * Renders a dashboard warning badge if the queue is non-empty.
  * Allows admins to retry single failed push operations or trigger a batch clear.

---

## Spec 10: Email Notifications Engine

### 1. Workflow Triggers
EMS will dispatch automated email notifications for key state changes:

| Trigger Event | Recipient(s) | Template Details |
|---|---|---|
| Signup Received | Parent, Explorer | Acknowledges DofE level registration and payments. |
| Invite ESU Share | Unit Leader | Requests the leader to approve OSM profile access to EMS. |
| Volunteer Availability | Admin | Notifies that an adult has signed up to help. |
| Assignment Confirmed | Explorer, Parent | Confirms dates, team assignments, and route deadlines. |
| Route Review Feedback | Explorer, Parent | Link to portal to view corrections/feedback from the LiC. |

### 2. SMTP Delivery & Logging
* Dispatched using the standard `wp_mail()` wrapper.
* Gated to run via SMTP settings (configured on the host, e.g. SiteGround SMTP).
* Includes a logging table `ems_email_logs` to capture email type, recipient, status (sent/failed), and timestamp for admin diagnostics.

---

## Spec 11: Expedition View Enhancements & Export

### 1. Team Builder Enhancements
* **Unassigned Explorer Sidebar**: Renders a list of all signed-up explorers for the season who have not yet been assigned to a team for the current event. Allows drag-and-drop assignment directly into teams.
* **Over-18 Warning flags**: Statically parses explorer date of birth (synced to custom meta or reference data) and highlights explorers who turn 18 before or during the expedition dates (for safeguarding compliance).
* **Maps Integration**: Integrates leaflet or lightweight map preview for start/end points using coordinates or location codes.

### 2. PDF & Excel Export Engine
* **Format Exports**:
  * **Team Sheet (PDF)**: A print-ready document listing team codes, member names, ESU, emergency contact emails, and first aid status.
  * **Volunteer Cover Sheet (Excel)**: Detailed spreadsheet showing supervisor cover per day for a season.
  * **Route Cards Bundle (Zip)**: Batch download of all approved GPX and route card PDFs for an expedition event.
