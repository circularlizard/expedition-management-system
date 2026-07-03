# Milestone 2: Team Formation, Event Dates & Calendar Management — Implementation Spec

This document defines the technical specification, database schema changes, REST API endpoints, and React interface designs required to implement **Milestone 2 (Team Formation, Event Dates & Calendar Management)**.

---

## 1. Architectural Decisions & Rejections

### 1.1 Custom Post Type (`expedition`) Over "The Events Calendar" (TEC)
*   **Decision**: EMS will **continue to use its native `expedition` Custom Post Type** as the primary storage model for event and expedition records, rather than integration with third-party plugins like "The Events Calendar" (`tribe_events`).
*   **Rationale**:
    1.  *Isolation*: Avoids a hard dependency on external plugins, preventing vendor lock-in and upgrade conflicts.
    2.  *Metadata Complexity*: Our custom metadata fields (`ems_event_code`, `ems_type`, `ems_transport`, etc.) are deeply integrated into the custom CPT hooks. Storing these inside an external plugin's CPT is risky.
    3.  *Testability*: Native CPTs are easily mocked in PHPUnit using Brain Monkey and WP post factory functions. Mocking third-party classes like `Tribe__Events__Main` introduces heavy test overhead.

---

## 2. Database Schema Changes

The schema adjustments are managed inside [Table_Installer](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Table_Installer.php).

### 2.1 Table Alterations
1.  **`ems_signups`**: Add `asn_details` TEXT NULL to capture parent-submitted support needs from Fluent Forms.
2.  **`ems_osm_explorers`**: Add `asn_details` TEXT NULL to store local, persistent explorer support needs.

```php
$signups_table = $wpdb->prefix . 'ems_signups';
if ( ! $this->column_exists( $wpdb, $signups_table, 'asn_details' ) ) {
    $wpdb->query( "ALTER TABLE {$signups_table} ADD COLUMN asn_details TEXT DEFAULT NULL AFTER expedition_preferences" );
}

$explorers_table = $wpdb->prefix . 'ems_osm_explorers';
if ( ! $this->column_exists( $wpdb, $explorers_table, 'asn_details' ) ) {
    $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN asn_details TEXT DEFAULT NULL AFTER dofe_number" );
}
```

### 2.2 New Audit Log Table (`ems_audit_logs`)
To track access to sensitive medical/ASN (PII) information, we create a dedicated audit logging table:

```sql
CREATE TABLE IF NOT EXISTS {$prefix}ems_audit_logs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          BIGINT UNSIGNED NOT NULL, -- Admin WP user who accessed the record
    action           VARCHAR(100)    NOT NULL, -- 'view_asn' | 'export_medical_pii'
    target_scout_id  BIGINT UNSIGNED DEFAULT NULL, -- Scout ID whose data was accessed
    ip_address       VARCHAR(45)     NOT NULL, -- Client IP address for audit trail
    user_agent       VARCHAR(255)    NOT NULL,
    timestamp        DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    KEY idx_target_scout_id (target_scout_id)
) {$charset};
```

*   **Copy-on-Process**: When an administrator marks a signup as `'processed'`, the value of `ems_signups.asn_details` is automatically copied into the linked `ems_osm_explorers.asn_details` record.

---

## 3. REST API Specifications

The following endpoints are registered in `Expedition_Admin_Controller` under the `/wp-json/ems/v1/` namespace:

### 3.1 `GET ems/v1/calendar`
Returns calendar events overlays.
*   **Access**: `manage_options` or `ems_leader` role.
*   **Logic**:
    1.  Queries all `expedition` CPT posts within the active season.
    2.  Aggregates calendar availability counts from the `ems_signups` table by parsing the `expedition_preferences` JSON structures (calculates how many explorers selected each date range).
*   **Response Payload**:
    ```json
    [
      {
        "id": 12,
        "title": "Practice Expedition 1",
        "start_date": "2026-06-13",
        "end_date": "2026-06-15",
        "type": "practice",
        "level": "bronze",
        "lic_name": "Dave Leader",
        "available_explorers_count": 18
      }
    ]
    ```

### 3.2 `GET ems/v1/explorers/{scout_id}/asn`
Retrieves sensitive Additional Support Needs (ASN) for a linked explorer.
*   **Access**: Restricted to WP Users with `manage_options` or the user registered as `ems_lic_id` on the explorer's assigned event.
*   **Execution**:
    1.  Write audit entry to `ems_audit_logs` detailing user ID, action (`'view_asn'`), target `scout_id`, IP address, and current timestamp.
    2.  Query and return `ems_osm_explorers.asn_details`.
*   **Response Payload**:
    ```json
    {
      "scout_id": 30001,
      "asn_details": "Requires insulin administration times; carried in medical pouch."
    }
    ```

### 3.3 `POST ems/v1/events/{id}/publish-assignments`
Batches and triggers email notifications for all explorers assigned to teams/dates within the event.
*   **Access**: `manage_options` or Event LiC.
*   **Logic**:
    1.  Queries `ems_team_members` for the target event that have not yet had their assignment notified.
    2.  Groups explorers by family/parent mapping.
    3.  Dispatches batched HTML emails using `wp_mail()` detailing assignment dates, team codes, and route submission deadlines.
    4.  Updates status flags to prevent double-notification.

---

## 4. UI Components & React Layouts

### 4.1 Calendar View Tab (Expedition Board)
A new "Calendar" tab is registered in the Expedition Board SPA:
*   **Interface**: Renders a monthly calendar grid.
*   **Event Overlay**: Renders expedition cards spanning their start and end dates. Cards are color-coded by expedition type (e.g. Green for Qualifying, Blue for Practice).
*   **Availability Tooltip**: Renders a count indicator (e.g. `+12 Available`) on calendar cells by referencing the calculated signup preferences count.
*   **Navigation**: Clicking an event card immediately shifts the active tab to the "Teams Workspace" view filtered to that event.

### 4.2 ASN Warnings & Secure Drawer
To display and protect ASN details:
*   **Roster Indicator**: On the Expedition Board team rosters and the Explorer List table, if an explorer has `asn_details` populated, a warning icon (e.g. `⚠️` or `ℹ️`) is displayed next to their name.
*   **Slide-Drawer Panel**: Clicking the ASN icon opens a slide-drawer component from the right side of the screen.
*   **Secure API Handshake**:
    1.  The drawer component mounts and triggers a `fetch` query to `/ems/v1/explorers/{scout_id}/asn`.
    2.  While loading, renders a security warning disclaimer: *"Access to medical records is restricted and audit-logged."*
    3.  On success, renders the details. The server has automatically written the access log entry.

### 4.3 Publish Assignments Action
*   **Control Panel**: A "Publish Assignments" button with a notifications count badge (e.g. `Publish (5)`) is placed in the Expedition Board header.
*   **Modal Dialog**: Clicking the button opens a modal listing the names of all team members who have pending assignments (modified allocations).
*   **Execution**: Clicking "Send Notifications" dispatches the batch command to `/publish-assignments`.

---

## 5. Cross-Screen State Synchronization (BroadcastChannel)

To sync data across separate browser tabs without manual page refreshes:

```
Browser Tab 1 (Expedition Board)           Browser Tab 2 (Explorer List)
    ├── Admin drags Explorer to Team             ├── Listening on 'ems-state-sync'
    ├── REST API writes change                   │
    ├── Dispatches BroadcastChannel Event ──────>├── Receives: 'REFRESH_ROSTERS'
    └── Updates local React State                └── Refetches REST API in Tab 2
```

### 5.1 Utility class `BroadcastSync`
Define a shared JavaScript wrapper in `resources/js/utils/BroadcastSync.ts`:
```typescript
class BroadcastSync {
  private channel: BroadcastChannel;

  constructor() {
    this.channel = new BroadcastChannel('ems-state-sync');
  }

  public publish(actionType: string, payload: any = {}) {
    this.channel.postMessage({ type: actionType, payload });
  }

  public subscribe(onMessage: (type: string, payload: any) => void) {
    const handler = (event: MessageEvent) => {
      onMessage(event.data.type, event.data.payload);
    };
    this.channel.addEventListener('message', handler);
    return () => this.channel.removeEventListener('message', handler);
  }
}

export const broadcastSync = new BroadcastSync();
```

### 5.2 React Implementation
*   **In Expedition Board mutations**: When drag-and-drop moves or team creations complete, invoke `broadcastSync.publish('REFRESH_ROSTERS')`.
*   **In Explorer List components**: Subscribe on mount. If a sync event fires, trigger a silent refetch of the explorer list roster data.

---

## 6. Gherkin Behavioral Scenarios

Add Gherkin scenarios to `tests/features/milestone2-calendar-and-asn.feature`:

```gherkin
Feature: Milestone 2 - Calendar, ASN, and Audit Logs

  Scenario: Viewing ASN records creates an audit log entry
    Given a synced explorer exists with scout_id 201 and asn_details "Needs Epipen"
    And the current user is logged in as an administrator
    When the administrator requests ASN details for scout_id 201
    Then the response should contain "Needs Epipen"
    And a row should exist in ems_audit_logs with action "view_asn", user_id current_user, and target_scout_id 201

  Scenario: Non-authorized users cannot view ASN records
    Given a synced explorer exists with scout_id 202 and asn_details "Needs Epipen"
    And the current user is logged in as a parent
    When the parent requests ASN details for scout_id 202
    Then the response status should be 403
    And no row should be written in ems_audit_logs

  Scenario: Publishing assignments sends batched notifications
    Given a team exists with ID 45
    And a team member exists with scout_id 301 on team 45 (notification_pending is true)
    When the administrator publishes assignments for team 45
    Then team member 301 notification_pending should be false
    And a batch email notification should be dispatched
```
