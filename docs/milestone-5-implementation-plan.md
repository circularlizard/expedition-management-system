# Milestone 5: Online Scout Manager Write-Back (Push-Back Sync) — Implementation Plan

This document outlines the detailed plan to implement **Milestone 5: Offline/Online Scout Manager Write-Back (Push-Back Sync)** based on the clarified specifications.

---

## 1. Overview of Requirements

1.  **Event Attendance Sync (Explicit Action)**:
    *   Synced as an explicit administrative action to prevent spamming OSM notifications on every update.
    *   Invitations are triggered by setting the status of the explorer on the OSM event to `'invited'`. OSM handles the invitation dispatch.
    *   **Workflow**:
        1.  Fetch the current event attendance from OSM.
        2.  For any explorer added to the event in EMS whose status in OSM is blank, unset, or `null` (and who has not already declined or accepted), execute an `updateMany` call with status `invited`.
        3.  Do not override statuses already set in OSM (e.g. if declined or accepted).
    *   **Event Identification & Fallback**: The target event to update in OSM is resolved via the expedition post's `ems_osm_event_id` meta field. If `ems_osm_event_id` is missing, empty, or the corresponding event cannot be found on OSM, we **skip** updating OSM event attendance for that expedition but continue to update the flexi-record.
2.  **EMS Teams & Patrols**:
    *   EMS teams remain purely local to EMS and **do not** map back to OSM Patrols.
3.  **Flexi-record Sync**:
    *   **Identification & Mapping**: EMS will store a per-section identifier of the flexi-record that it manages (within `ems_managed_sections` or `ems_osm_flexi_record_{section_id}`). If none is stored, EMS will create a new flexi-record structure on OSM and store the resulting `id` and column configurations. Once created, flexi-records cannot be changed.
    *   **Flexi-record Columns & Target Values**:
        *   `PRACTICE GROUPS`: The team code assigned in EMS (e.g. `"HGP1-4"`).
        *   `PRACTICE ACCEPTED`: The team code + event date + attendance status (e.g. `"HGP1 29/5 N"` or `"HGP1 29/5 Y"`).
        *   `QUALIFIER GROUPS`: The team code assigned in EMS (e.g. `"HGQ1-3"`).
        *   `QUALIFIER ACCEPTED`: The team code + event date + attendance status (e.g. `"HGQ1 12/8 N"` or `"HGQ1 12/8 Y"`).
        *   `TRAINING DAY`: The training day assignment (e.g. `"TD3"`).
        *   `FIRST AID`: The scout's registered first aid qualification (e.g. `"FIRST RESPONSE"` or `"FULL FIRST AID"`).
    *   Create these columns if they do not exist on the target flexi-record.
    *   **Diff-Based Updates**: Updates are based on a diff between local EMS data and OSM data. Only divergent records are pushed.
    *   **Batching**: Updates can be batch-updated using multi-update actions (as described in `multiUpdate.txt` or OSM multi-update equivalent) to reduce HTTP request counts.
4.  **Write Preview & Safety Step**:
    *   Implement as a standalone separate admin dashboard view.
    *   Fetch and show the entire flexi-record data and entire event attendance data in the preview step (fetched in one call per record/event).
5.  **Strict Rate Limits & Error Recovery**:
    *   An event update cannot fail partially because `updateMany` is a single transaction/call.
    *   If a write-back action fails (e.g., due to network issues, expired tokens, or OSM rate-limits), update the `ems_failed_pushback_queue` option with the status of the sync attempt (e.g., error details, timestamp, count of unsynced items).
    *   We do not need to retry specific itemized calls. Clicking "Retry" in the UI simply recalculates the current diff against OSM and retries pushing all unsynced changes, correcting any state drift.
    *   If rate-limited or blocked, show a warning banner in the WP Admin Dashboard and restrict manual retries.
6.  **Concurrency & Session Safety**:
    *   **Sync Lock**: Implement a transient lock (`ems_pushback_sync_lock`) during push executions to prevent concurrent API write requests.
    *   **OAuth Session Validation**: Since tokens are not persisted server-side, the UI and API must verify token availability before sync execution. If expired/missing, prompt the user to re-authenticate first.
    *   **Term ID Resolution**: Flexi-record batch updates require the active term identifier (`termid`). The sync engine must dynamically fetch and pass the current active Term ID from OSM config payloads for each updated section.

---

## 1.5 Pre-requisites: OAuth Scopes

To perform write operations on Online Scout Manager, the requested OAuth scope string (`ems_osm_scope` option) must be updated to request write permissions. 

*   **Current Scope**: `section:member:read section:event:read section:flexirecord:read`
*   **Required Target Scope**: `section:member:read section:event:write section:flexirecord:write`
*   **Default Option Updates**: Update `OSM_Sync_Auth_Handler.php` default scope fallback to include `section:event:write` and `section:flexirecord:write`.

---

## 2. Technical Component Design

### 2.1 Backend / API Client Additions
*   Extend `Driver_Interface` and `OSM_API_Client` with:
    *   `update_event_attendance(int $section_id, int $event_id, array $member_updates)`: Updates multiple member attendance states using OSM's `updateMany` style endpoint or equivalent. Note: The `member_id` identifier used here maps directly to the explorer's `scout_id`.
    *   `create_flexi_record(int $section_id, string $name)`: Creates a new flexi-record structure on OSM.
    *   `add_flexi_record_column(int $section_id, int $flexi_id, string $column_name)`: Adds a custom column to a flexi-record.
    *   `update_flexi_record_data(int $section_id, int $flexi_id, array $values)`: Batch updates flexi-record columns for scouts.

### 2.2 Sync Engine & Status Tracking (`EMS\Integrations\Pushback_Sync_Manager`)
*   **Failed Status Structure** in `ems_failed_pushback_queue` Option:
    ```php
    [
        'last_failed_at' => string, // Timestamp
        'error_message'  => string, // Error message from the failed attempt
        'unsynced_items' => int     // Number of unsynced items remaining
    ]
    ```
*   Implement `EMS\Integrations\Pushback_Sync_Manager` class containing methods to:
    *   Compare local EMS state against current OSM state and construct a diff.
    *   Attempt write-backs to OSM.
    *   Update `ems_failed_pushback_queue` on failure (or clear it on successful sync).

### 2.3 WP REST API Endpoints
*   `GET /ems/v1/admin/sync-preview`: Fetches current local changes compared to current OSM state (event attendance & flexi-records) to construct the preview data.
*   `POST /ems/v1/admin/sync-push`: Starts the push-back process (used for initial sync and retries).
*   `GET /ems/v1/admin/sync-status`: Retrieves the last sync execution status/error log.
*   `DELETE /ems/v1/admin/sync-status`: Resets/clears the failed status details.

### 2.4 UI Views (React / TypeScript)
1.  **Sync Preview & Push Dashboard**:
    *   Standalone subpage inside WP Admin settings/EMS dashboard.
    *   Lists proposed additions/updates side-by-side with OSM's current records.
    *   A prominent "Execute Push-Back Sync" button (re-labeled to "Retry Sync" when there is a failed sync history).
2.  **Sync Status Panel**:
    *   Displays the summary of the last run, success/failure status, last error details, and timestamp.
3.  **Dashboard Alert**:
    *   Renders a warning box if `ems_api_blocked` or rate-limit lockouts are active.

### 2.4.5 Defensive Execution & Diagnostic Logging
*   **Defensive Checks**: Since the capability of OIDC admin tokens for writes (like `multiUpdate` or `addRecordSet`) is unverified on the live API, the execution engine must catch, parse, and log any HTTP `403 Forbidden`, `401 Unauthorized`, or JSON error payloads gracefully.
*   **Trace Logging**: Implement a configurable toggle `ems_pushback_debug_log` (stored in options). When enabled, all JSON payloads sent to OSM, as well as the exact response headers and content returned, will be written to the WordPress debug log (`error_log`) with an `[EMS Pushback Debug]` prefix.

### 2.5 OSM API Endpoints Reference

Based on the [mockdata index](file:///Users/davidstrachan/Projects/expedition-management-system/mockdata/mockdata.txt), we will implement interactions with the following endpoints:

1.  **Check Flexi-records Existence (List records)**:
    *   **Endpoint**: `GET /ext/members/flexirecords/?action=getFlexiRecords&sectionid={section_id}&archived=n`
    *   **Purpose**: Verify if the managed flexi-record set (e.g. `"2026 Expeditions"`) exists.
2.  **Fetch Flexi-record Structure**:
    *   **Endpoint**: `GET /ext/members/flexirecords/?action=getStructure&sectionid={section_id}&extraid={flexi_record_id}`
    *   **Purpose**: Fetch the column configurations to see which columns already exist and resolve their code IDs (e.g. `f_9`).
3.  **Flexi-record Creation**:
    *   **Endpoint**: `POST /ext/members/flexirecords/?action=addRecordSet&sectionid={section_id}`
    *   **Payload**: `name={record_name}&patrol=1&type=none`
    *   **Response**: `{"id": 75534}`
4.  **Add Column to Flexi-record**:
    *   **Endpoint**: `POST /ext/members/flexirecords/?action=addColumn&sectionid={section_id}&extraid={flexi_record_id}`
    *   **Payload**: `columnName={column_name}`
    *   **Response**: JSON structure from which the new column code (e.g. `f_1`) is extracted.
5.  **Update Flexi-record Scout Value (Single Update)**:
    *   **Endpoint**: `POST /ext/members/flexirecords/?action=updateScout&nototal`
    *   **Payload**: `termid={term_id}&section={section_type}&sectionid={section_id}&extraid={flexi_record_id}&scoutid={scout_id}&column={column_code}&value={value}`
6.  **Batch Update Flexi-record Column (Multi-Update)**:
    *   **Endpoint**: `POST /ext/members/flexirecords/?action=multiUpdate&sectionid={section_id}`
    *   **Payload**: `scouts={json_array_of_scout_ids}&value={value}&col={column_code}&extraid={flexi_record_id}` (e.g. `scouts=["3417257","1587452"]&value=xy&col=f_9&extraid=73848`)
    *   **Response**: `{"error":false}`
7.  **Fetch Event Attendance**:
    *   **Endpoint**: `GET /v3/events/event/{event_id}/members/attendance?term_id={term_id}`
    *   **Purpose**: Get current status in OSM before changing them.
8.  **Update Event Attendance (Invite Many)**:
    *   **Endpoint**: `POST /v3/events/event/{event_id}/members/attendance/updateMany`
    *   **Payload**: `field=attending&value=invited&member_ids={comma_separated_scout_ids}`

### 2.6 Test Mocking & Database Seeding Mapping

To facilitate correct tests, we will mock API responses using the structure of the existing test dataset:

*   **Mock Files to leverage/adapt**:
    *   **OSM Events**: [osm-events.json](file:///Users/davidstrachan/Projects/expedition-management-system/tests/mocks/osm-events.json) (Event IDs like `40001`, `40002` corresponding to `ems_osm_event_id`).
    *   **Event Attendance**: [osm-event-attendance.json](file:///Users/davidstrachan/Projects/expedition-management-system/tests/mocks/osm-event-attendance.json) (contains member attendance records with `member_id` matching `scout_id` and initial state like `invited` or `yes`).
    *   **Flexi-record Structure**: [osm-flexi-record-structure.json](file:///Users/davidstrachan/Projects/expedition-management-system/tests/mocks/osm-flexi-record-structure.json) (mock record set `99848` named `"2026 Expeditions"` with columns like `"f_9"`, `"f_10"` etc.).
    *   **Flexi-record Data**: [osm-flexi-record-data.json](file:///Users/davidstrachan/Projects/expedition-management-system/tests/mocks/osm-flexi-record-data.json) (contains synced items with scout IDs and column values matching local test participants).
*   **Mock File Alignment**:
    *   The mock files used for API testing will be aligned/made consistent with the already implemented database seed values.
    *   This ensures that target mock explorer records (such as scout IDs, emails) and mock event details (such as event IDs) perfectly match the pre-seeded values in the local database, facilitating integration test validation.

---

## 3. Mandatory TDD Testing Workflow

Following the **TDD Workflow**:
1.  **Write Feature Scenarios**: Add Gherkin scenarios to `tests/features/5-pushback-sync.feature`.
2.  **Write PHPUnit Tests**: Target `tests/Unit/Integrations/Pushback_Sync_ManagerTest.php` and mock dependencies.
3.  **Implement Production Code**: Complete classes and API handlers until tests pass.
4.  **Write JS Tests**: Target React component tests for the preview and queue manager.
5.  **Staging Deployment**: Execute `bash bin/deploy.sh` to sync updates with the local WP target.
