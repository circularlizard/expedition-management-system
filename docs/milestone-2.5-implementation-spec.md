# Milestone 2.5: M1/M2 Carryover — Participant Export, Cleanup & Sync

This document consolidates the remaining unimplemented items from Milestone 1 (Signup Processing) and Milestone 2 (Team Formation & Calendar) that were not completed during their original implementation windows.

---

## 1. Participant Download (CSV/Excel Export)

**Origin:** Milestone 1 — Signup Processing & Unit Leader Outreach

### 1.1 Feature Description
An admin interface that allows exporting some or all participant signup records to CSV (and optionally Excel) format for offline review, reporting, and sharing with unit leaders.

### 1.2 REST API Specification

#### `GET ems/v1/signups/participants/export`
Exports participant signups to a CSV download.

*   **Parameters**:
    *   `status` (string, optional): `received`, `processed`, `archived`, or `all` (default: `all`).
    *   `level` (string, optional): `bronze`, `silver`, `gold`, or `all` (default: `all`).
    *   `columns` (string, optional): Comma-separated list of columns to include. Default includes all standard columns.

*   **Response**:
    *   Returns a `Content-Disposition: attachment` header with filename `ems-participant-signups-{YYYY-MM-DD}.csv`.
    *   Content-Type: `text/csv`.

*   **Columns (default set)**:
    | Column | Source |
    |---|---|
    | ID | `ems_participant_signups.id` |
    | Scout ID | `ems_participant_signups.scout_id` |
    | Explorer First Name | from OSM explorer or form data |
    | Explorer Last Name | from OSM explorer or form data |
    | Email | from OSM explorer or form data |
    | Parent Email | from OSM explorer or form data |
    | DofE Level | `ems_participant_signups.dofe_level` |
    | DofE Number | `ems_participant_signups.dofe_number` |
    | First Aid Status | `ems_participant_signups.first_aid_status` |
    | ESU Unit | `ems_participant_signups.unit_name` (resolved from `unit_id`) |
    | Payment Status | `ems_participant_signups.payment_status` |
    | Signup Status | `ems_participant_signups.signup_status` |
    | Linkage Status | `linked` / `proposed` / `unlinked` |
    | Processed By | WP user display name (or empty if not processed) |
    | Processed At | `ems_participant_signups.processed_at` |
    | Reconciled By | WP user display name (or empty if not reconciled) |
    | Reconciled At | `ems_participant_signups.reconciled_at` |
    | Created At | `ems_participant_signups.created_at` |

### 1.3 UI Integration
*   Add an "Export CSV" button to the SignupsBoard component toolbar (visible on both participant and expedition tabs).
*   Button triggers the export endpoint via a direct browser navigation (`window.location.href`) to download the file.
*   Show a loading spinner while the server generates the CSV.

### 1.4 Implementation Details
*   Add `export_participant_signups` method to `Signup_Repository` that accepts `$status`, `$level`, `$columns` parameters.
*   Add `export_participant_signups` controller method in `Expedition_Admin_Controller` that streams CSV output with proper headers.
*   Use `fputcsv()` for safe CSV generation (handles commas, quotes, newlines in data).

---

## 2. Season CPT Removal

**Origin:** Milestone 2 — Team Formation, Event Dates & Calendar Management

### 2.1 Feature Description
Complete the deprecation of the `season` custom post type. The migration logic already exists in `Table_Installer::migrate_season_deprecation()` (detaches expeditions from season parents, deletes season posts). The CPT registration in `CPT_Registry.php` still exists and must be removed.

### 2.2 Implementation Steps

1.  **Verify migration is stable**:
    *   Confirm `ems_season_migration_done` option is set after activation.
    *   Confirm no `expedition` posts have a non-zero `post_parent` pointing to a `season` post.

2.  **Remove CPT registration**:
    *   In `CPT_Registry.php`, remove the `register_post_type('season', ...)` call entirely.
    *   Remove the `get_season_meta_fields()` method.
    *   Remove any `season` references in `Meta_Validator.php`.

3.  **Remove admin menu entries**:
    *   Remove any submenu/page registrations for season-related admin views in `Admin_Page.php`.

4.  **Clean up React code**:
    *   Remove `SeasonDashboard.tsx` and `SeasonForm.tsx` components.
    *   Remove any imports or references to season-related API endpoints.

5.  **Clean up REST API**:
    *   Remove any `/seasons` or `/seasons/:id` REST routes from `Expedition_Admin_Controller`.

### 2.3 Verification
*   Run `bash bin/deploy.sh` and confirm no PHP errors on plugin activation.
*   Verify expeditions still load correctly in the Events Dashboard without season parents.

---

## 3. Cross-Screen State Synchronization (BroadcastChannel)

**Origin:** Milestone 2 — Team Formation, Event Dates & Calendar Management

### 3.1 Feature Description
Uses the browser `BroadcastChannel` API to propagate state changes between multiple open tabs of the EMS admin interface. For example, moving an explorer between teams on the Expedition Board should refresh the Explorer List in another tab without requiring a manual page reload.

### 3.2 Specification

*   **Channel name**: `ems-state-sync`
*   **Message format**:
    ```typescript
    interface EMSStateMessage {
        action: string;    // e.g. 'explorer_moved', 'team_created', 'team_deleted', 'event_updated'
        payload: {
            event_id?: number;
            team_id?: number;
            scout_id?: number;
            source?: string;  // component name that triggered the event
        };
        timestamp: number;
    }
    ```

*   **Trigger points**:
    | Action | Triggered After | Payload |
    |---|---|---|
    | `explorer_moved` | ExplorerMovePanel completes drag | `{ event_id, scout_id, team_id }` |
    | `team_created` | New team created on Event Detail | `{ event_id, team_id }` |
    | `team_deleted` | Team deleted from Event Detail | `{ event_id, team_id }` |
    | `event_updated` | Event metadata saved | `{ event_id }` |
    | `signup_processed` | Signup process/archive completed | `{ signup_id }` |

*   **Listeners**:
    | Component | Listens For | Action |
    |---|---|---|
    | `ExplorersPage` | `explorer_moved`, `team_created`, `team_deleted` | Re-fetch explorer roster |
    | `SignupsBoard` | `signup_processed` | Re-fetch signups list |
    | `EventPlanningBoard` | `event_updated`, `team_*` | Re-fetch event data |
    | `EventDetailPage` | `explorer_moved`, `team_*` | Re-fetch teams and rosters |

### 3.3 Implementation
*   Create a shared hook: `useEMSSync()` in `resources/js/admin/shared/hooks/useEMSSync.ts`.
*   The hook initializes the `BroadcastChannel` on mount, subscribes to relevant events, and triggers a `refetch()` callback on matching messages.
*   Each component calls `useEMSSync({ actions: ['explorer_moved', ...], onMessage: refetch })`.
*   When an action completes (e.g., after a successful API call in `ExplorerMovePanel`), dispatch a message via `channel.postMessage(...)`.
*   Include a `source` field so components can ignore messages they originated (to prevent self-refetch).

---

## 4. Communication Notifications (Explorer Assignments)

**Origin:** Milestone 2 — Team Formation, Event Dates & Calendar Management

### 4.1 Feature Description
Send email notifications when explorers are assigned to events, moved between teams, or removed from teams. Notifications go to the explorer (if they have an email) and their parent (if a parent email is available).

### 4.2 Notification Triggers

| Trigger | Recipient(s) | Subject Template |
|---|---|---|
| Explorer assigned to event (team) | Explorer + Parent | "You've been assigned to {event_name} — Team {team_code}" |
| Explorer moved to different team | Explorer + Parent | "Team change: you're now on {team_code} for {event_name}" |
| Explorer moved to unallocated | Explorer + Parent | "Team update: {event_name} roster change" |

### 4.3 Email Content
*   Explorer name, event name, event dates, team code, LiC name and contact details.
*   Link to the Explorer Portal (Milestone 4, if available) or a confirmation page.
*   Standard footer with EMS contact details.

### 4.4 Implementation Details
*   Add a `Notification_Sender` class in `src/Services/Notification_Sender.php`.
*   The class wraps `wp_mail()` and accepts structured payloads.
*   Hook into the team membership mutation methods in `Team_Member_Repository`:
    *   `add_member()` → trigger "assigned" notification.
    *   `move_member()` → trigger "moved" notification.
*   Add a WP option `ems_disable_assignment_emails` (boolean, default `false`) to allow admins to opt out during bulk operations.
*   Log all sent emails to `ems_email_logs` table (if Milestone 10's table exists; otherwise skip logging for now).

### 4.5 Admin UI
*   Add a checkbox on the ExplorerMovePanel: "Send notification email" (checked by default).
*   Add a bulk operation setting: "Disable notifications for this session" checkbox on the Expedition Board.

---

## 5. Gherkin Behavioral Scenarios

```gherkin
Feature: Milestone 2.5 - Carryover Items

  Scenario: Participant signups export produces valid CSV
    Given 3 participant signups exist with varied statuses
    When the administrator requests the participant export CSV
    Then the response should have Content-Type text/csv
    And the CSV should contain 3 data rows
    And each row should include ID, DofE Level, Signup Status, and Payment Status columns

  Scenario: Season CPT is no longer registered
    Given the EMS plugin is activated
    When the administrator checks registered post types
    Then 'season' should not be a registered post type
    And all expedition posts should have post_parent = 0

  Scenario: Explorer move broadcasts to other tabs
    Given the Event Detail Page is open with teams loaded
    When an explorer is moved from team A to team B
    Then a BroadcastChannel message with action 'explorer_moved' should be dispatched
    And the message payload should include event_id, scout_id, and team_id

  Scenario: Explorer assignment sends email notification
    Given an explorer exists with scout_id 30001 and email "explorer@example.com"
    And the explorer is added to team "H-SP1-1" on event 10
    When the team membership is saved
    Then an email should be queued to "explorer@example.com"
    And the email subject should contain "H-SP1-1"
```

---

## 6. Prioritization & Dependencies

| Item | Priority | Dependencies |
|---|---|---|
| Season CPT Removal | High | None (migration already exists, safe to remove registration) |
| Participant Download | Medium | None |
| Cross-Screen State Sync | Medium | None (pure frontend) |
| Communication Notifications | Low | Milestone 10 (Email Logging) for full audit trail, but can ship without it |
