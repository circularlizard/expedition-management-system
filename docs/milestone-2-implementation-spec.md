# Milestone 2: Team Formation, Event Dates & Calendar Management — Implementation Spec

This document defines the technical specification, database schema changes, REST API endpoints, React interface designs, and implementation details for **Milestone 2 (Team Formation, Event Dates & Calendar Management)**, updated with new event-level and metadata specifications.

---

## 1. Architectural Decisions & Rejections

### 1.1 Custom Post Type (`expedition`) Over "The Events Calendar" (TEC)
*   **Decision**: EMS will **continue to use its native `expedition` Custom Post Type** as the primary storage model for event and expedition records, rather than integration with third-party plugins like "The Events Calendar" (`tribe_events`).
*   **Rationale**:
    1.  *Isolation*: Avoids a hard dependency on external plugins, preventing vendor lock-in and upgrade conflicts.
    2.  *Metadata Complexity*: Our custom metadata fields (`ems_event_code`, `ems_type`, `ems_transport`, etc.) are deeply integrated into the custom CPT hooks. Storing these inside an external plugin's CPT is risky.
    3.  *Testability*: Native CPTs are easily mocked in PHPUnit using Brain Monkey and WP post factory functions. Mocking third-party classes like `Tribe__Events__Main` introduces heavy test overhead.

### 1.2 Deprecation of the "Season" Concept
*   **Decision**: The CPT `season` is **completely deprecated and removed** from the dashboard architecture.
*   **Rationale**: The season concept added unnecessary hierarchy and complexity without providing functional value. The main entry screen will transition from a season dashboard directly to an **Events Dashboard**.

---

## 2. Implemented Features (Achieved)

The following components and behaviors have been fully implemented in the plugin codebase:

### 2.1 React Expedition Board SPA
*   **Structure**: Integrated as a submenu page in the WordPress admin panel.
*   **CRUD Operations**: Enables creating, reading, updating, and deleting events and teams.
*   **Roster Workspace**: Allows administrators to view all participants assigned to an event and drag-and-drop explorers between teams or unassigned rosters.

### 2.2 Sequential Team Code Generation
*   **Logic**: Handled in `Team_Repository.php`. When creating a new team for an event, the system fetches all existing teams for that event, parses their codes, and generates a sequential code using the event code prefix:
    *   Example: Event code `H-SP1` &rarr; Teams `H-SP1-1`, `H-SP1-2`, etc.

### 2.3 Team Size Validation
*   **Validation Rules**: A team roster must ideally contain between **4 and 7 explorers**.
*   **Visual Feedback**: If a team falls below 4 members or exceeds 7 members, the Expedition Board interface displays a clear warning badge, flagging validation status without blocking save actions. A team with 0 members is automatically cleaned up.

---

## 3. Database & CPT Schema Adjustments

The metadata and custom post type configurations are managed via `CPT_Registry` and `Table_Installer`.

### 3.1 CPT `expedition` Meta Fields (Updated)
Events contain the following metadata keys:
*   `ems_event_code` (string) — Short code (e.g. `H-SP1`)
*   `ems_type` (string: `training` | `practice` | `qualifying`)
*   `ems_transport` (string: `hillwalking` | `biking` | `paddling`)
*   `ems_level` (string: `bronze` | `silver` | `gold`)
*   `ems_lic_name` (string) — Leader in Charge Name
*   `ems_lic_email` (string) — LiC Email
*   `ems_lic_phone` (string) — LiC Phone
*   `ems_lic_id` (int) — WP User ID of the LiC
*   `ems_start_location` / `ems_end_location` (string)
*   `ems_start_date` / `ems_end_date` (date)
*   `ems_start_time` / `ems_end_time` (time)
*   `ems_osm_event_id` (int) — Mapped OSM Event ID
*   `ems_route_info` (text)
*   `ems_route_deadline` (date)
*   `ems_status` (string: `active` | `archived`) — Default: `active`
*   `ems_whatsapp_parent_link` (string) — URL to parents' WhatsApp group
*   `ems_whatsapp_explorer_link` (string) — URL to explorers' WhatsApp group

### 3.2 Synced Explorer Table Alteration
Add `additional_support_needs` TEXT NULL to `ems_osm_explorers` to store local, persistent explorer support needs.

```php
$explorers_table = $wpdb->prefix . 'ems_osm_explorers';
if ( ! $this->column_exists( $wpdb, $explorers_table, 'additional_support_needs' ) ) {
    $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN additional_support_needs TEXT DEFAULT NULL AFTER dofe_number" );
}
```

### 3.3 Audit Log Table (`ems_audit_logs`)
To track access to sensitive medical/Additional Support Needs (PII) information:

```sql
CREATE TABLE IF NOT EXISTS {$prefix}ems_audit_logs (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id                BIGINT UNSIGNED NOT NULL, -- Admin WP user who accessed the record
    action                 VARCHAR(100)    NOT NULL, -- 'view_asn' | 'export_medical_pii'
    target_scout_id        BIGINT UNSIGNED DEFAULT NULL, -- Scout ID whose data was accessed
    ip_address             VARCHAR(45)     NOT NULL, -- Client IP address for audit trail
    user_agent             VARCHAR(255)    NOT NULL,
    timestamp              DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    KEY idx_target_scout_id (target_scout_id)
) {$charset};
```

---

## 4. REST API Specifications

The following endpoints are registered in `Expedition_Admin_Controller` under the `/wp-json/ems/v1/` namespace:

### 4.1 `GET ems/v1/events`
Returns all registered events.
*   **Params**: `status` (string: `active` | `archived` | `all`, default: `active`)
*   **Response Payload**:
    ```json
    [
      {
        "id": 12,
        "title": "Practice Expedition 1",
        "event_code": "H-SP1",
        "type": "practice",
        "transport": "hillwalking",
        "level": "bronze",
        "start_date": "2026-06-13",
        "end_date": "2026-06-15",
        "status": "active",
        "first_aid_requirements": "Min 1 certified team member required"
      }
    ]
    ```

### 4.2 `GET ems/v1/calendar`
Returns calendar events overlays for scheduling views.
*   **Logic**: Queries all `expedition` CPT posts with status `active` and aggregates explorer date preferences.

### 4.3 `GET ems/v1/explorers/{scout_id}/asn`
Retrieves sensitive Additional Support Needs (ASN) for a linked explorer and writes to `ems_audit_logs`.

---

## 5. UI Components & React Layouts

### 5.1 Events Dashboard (Replacing Season Dashboard)
*   **Chronological Sorting**: Events are listed in ascending chronological order based on `ems_start_date`.
*   **Historical Separation (Tabs)**:
    *   **Upcoming Events**: Current date is before or equal to the event's end date.
    *   **Past Events**: Current date is after the event's end date.
*   **Visibility Control**: By default, archived events (`ems_status === 'archived'`) are hidden. A checkbox toggle *"View Archived Events"* allows admins to display them.
*   **Columns displayed**: Event Name, Short Code, Mode of Transport, Level, First Aid Requirements, Dates.
*   **Interaction**: Clicking an event row navigates the SPA directly to the **Event Detail Page**.

### 5.2 Event Detail Page & Team Formation Workspace
*   **Consolidated View**: Team formation and drag-and-drop rosters are relocated strictly onto the Event Detail Page.
*   **Roster Tabs**:
    1.  **Teams**: Drag-and-drop workspace showing created teams.
    2.  **QR Codes**: Displays generated QR codes for:
        *   Parents' WhatsApp Group Link
        *   Explorers' WhatsApp Group Link
        *   *Implementation*: QR codes are generated dynamically via secure client-side SVG rendering or the Google Charts QR API (`https://chart.googleapis.com/chart?chs=180x180&cht=qr&chl=...`).
*   **Unallocated Explorers / Roster Support**:
    *   Allows assigning explorers directly to an event without placing them in a team.
    *   *Implementation*: Stored in the database with a default team mapping (e.g., assigning them to a virtual "Unallocated" team with code `UNALLOCATED` or a `0` team ID), preserving the underlying relational data model.

### 5.3 ASN Warnings & Secure Drawer
*   **Roster Warning**: Renders an alert icon (e.g., `⚠️`) next to names in team lists if `additional_support_needs` is populated.
*   **Action**: Clicking the icon slides open a secure drawer fetching details from `/ems/v1/explorers/{scout_id}/asn`, prompting an audit log.

---

## 6. Cross-Screen State Synchronization (BroadcastChannel)

Uses `BroadcastChannel('ems-state-sync')` to propagate updates (like team movements or new assignments) between open tabs (e.g. Event Details Board and separate Explorer Listings) automatically.

---

## 7. Gherkin Behavioral Scenarios

Add feature scenarios to `tests/features/milestone2-events-and-teams.feature`:

```gherkin
Feature: Milestone 2 - Events Dashboard, Teams, and Metadata

  Scenario: Viewing active events does not show archived events by default
    Given an event exists with code "H-SP1" and status "active"
    And an event exists with code "H-SP2" and status "archived"
    When the administrator lists active events
    Then the response should contain "H-SP1"
    And the response should not contain "H-SP2"

  Scenario: Listing past events shows events with end dates in the past
    Given an event exists with code "H-SP1" and end_date "2026-05-15" (past)
    And an event exists with code "H-SP2" and end_date "2026-07-20" (upcoming)
    When the administrator lists past events
    Then the response should contain "H-SP1"
    And the response should not contain "H-SP2"

  Scenario: Dragging explorer to Unallocated updates team assignment to virtual code
    Given a team exists with code "UNALLOCATED" on event 10
    And an explorer exists with scout_id 201 assigned to team "H-SP1-1"
    When the administrator moves explorer 201 to the Unallocated roster
    Then explorer 201 should be assigned to team "UNALLOCATED"
```
