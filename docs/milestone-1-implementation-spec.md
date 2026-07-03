# Milestone 1: Signups Processing & Reconciliation — Implementation Specification

This document defines the technical specification, database schema changes, REST API endpoints, and React user interface designs required to implement **Milestone 1 (Signups Processing & Reconciliation)**.

---

## 1. Database Schema Changes

The database modifications are managed inside [Table_Installer](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Table_Installer.php). We require additions to two existing custom tables:

### 1.1 `ems_signups` Table
We add columns to support DofE number capture and back-office audit tracking:
*   `dofe_number` (VARCHAR(50), NULL): Stores the explorer's DofE registration number submitted via Fluent Forms.
*   `processed_by` (BIGINT UNSIGNED, NULL): WordPress User ID of the administrator who processed the signup.
*   `processed_at` (DATETIME, NULL): Timestamp when the signup was marked as processed.
*   `reconciled_by` (BIGINT UNSIGNED, NULL): WordPress User ID of the administrator who manually linked the signup to a Scout ID.
*   `reconciled_at` (DATETIME, NULL): Timestamp when the manual reconciliation link was completed.

### 1.2 `ems_osm_explorers` Table
We add a column to store the verified DofE number:
*   `dofe_number` (VARCHAR(50), NULL): Mapped DofE number synced from the processed signup details.

### 1.3 Schema Migration Script
In `Table_Installer::run_migrations()`, add the following database modifications:
```php
$signups_table = $wpdb->prefix . 'ems_signups';
if ( ! $this->column_exists( $wpdb, $signups_table, 'dofe_number' ) ) {
    $wpdb->query( "ALTER TABLE {$signups_table} ADD COLUMN dofe_number VARCHAR(50) DEFAULT NULL AFTER dofe_level" );
    $wpdb->query( "ALTER TABLE {$signups_table} ADD COLUMN processed_by BIGINT UNSIGNED DEFAULT NULL AFTER payment_status" );
    $wpdb->query( "ALTER TABLE {$signups_table} ADD COLUMN processed_at DATETIME DEFAULT NULL AFTER processed_by" );
    $wpdb->query( "ALTER TABLE {$signups_table} ADD COLUMN reconciled_by BIGINT UNSIGNED DEFAULT NULL AFTER processed_at" );
    $wpdb->query( "ALTER TABLE {$signups_table} ADD COLUMN reconciled_at DATETIME DEFAULT NULL AFTER reconciled_by" );
}

$explorers_table = $wpdb->prefix . 'ems_osm_explorers';
if ( ! $this->column_exists( $wpdb, $explorers_table, 'dofe_number' ) ) {
    $wpdb->query( "ALTER TABLE {$explorers_table} ADD COLUMN dofe_number VARCHAR(50) DEFAULT NULL AFTER first_aid_level" );
}
```

---

## 2. Fluent Forms Sync Integration (`dofe_number`)

To capture the DofE number during registrations, [Fluent_Forms_Sync](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Fluent_Forms_Sync.php) and [Signup_Repository](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Signup_Repository.php) must be updated.

### 2.1 Form Mapping Keys
Extend `ems_form_mappings` options schema to support a new key: `dofe_number_field`.
```json
{
  "form_id": {
    "scout_id_field": "signup_child",
    "dofe_level_field": "signup_level",
    "dofe_number_field": "signup_dofe_number",
    "pref_fields": ["expedition_preferences"]
  }
}
```

### 2.2 Form Submission Sync
In `Fluent_Forms_Sync::handle_submission()`, extract the DofE number field:
```php
$dofe_num_field = $config['dofe_number_field'] ?? '';
$dofe_number = ! empty( $dofe_num_field ) ? ( $formData[ $dofe_num_field ] ?? '' ) : '';
```
Pass this value to `Signup_Repository::create_signup()` so it is inserted into the `ems_signups` table.

---

## 3. REST API Specifications

The endpoints are registered in `Expedition_Admin_Controller` under the `/wp-json/ems/v1/` namespace:

### 3.1 `GET ems/v1/signups`
Lists all signup records.
*   **Parameters**:
    *   `status` (string, optional): `active` (default, returns `pending`), `processed` (returns processed rows), `archived` (returns archived rows), or `all`.
*   **Response Payload**:
    ```json
    [
      {
        "id": 1,
        "scout_id": 30001,
        "parent_user_id": 4,
        "explorer_first_name": "John",
        "explorer_last_name": "Doe",
        "dofe_level": "silver",
        "dofe_number": "D-123456",
        "first_aid_status": "none",
        "signup_status": "pending",
        "payment_status": "paid",
        "unit_id": 99001,
        "unit_name": "Silver ESU",
        "linkage_status": "linked", // "linked" | "proposed" | "unlinked"
        "proposed_scout_id": null
      }
    ]
    ```
*   **Logic (Fuzzy Link Matching)**:
    When querying signups, check if `scout_id` is linked. If `scout_id` is null or 0:
    1.  Fuzzy search `ems_osm_explorers` by email (case-insensitive).
    2.  If email matches, set `linkage_status = 'proposed'` and `proposed_scout_id` to that explorer's `scout_id`.
    3.  If no match is found, check if first/last name matches. Set `linkage_status = 'proposed'` on match.
    4.  Otherwise, set `linkage_status = 'unlinked'`.

### 3.2 `POST ems/v1/signups/{id}/reconcile`
Manually links a signup record to a verified synced explorer profile.
*   **Body Payload**:
    ```json
    { "scout_id": 30001 }
    ```
*   **Validation**:
    *   Verify signup record and target `scout_id` exist.
    *   Verify the signup's current `signup_status` is not `'processed'`.
*   **Execution**:
    *   Update `ems_signups.scout_id` to the selected `scout_id`.
    *   Update `reconciled_by = get_current_user_id()` and `reconciled_at = current_time('mysql')`.

### 3.3 `POST ems/v1/signups/{id}/process`
Marks the registration as completed/processed and binds the DofE number.
*   **Validation**:
    *   Verify the signup record has a valid `scout_id` (must be linked).
    *   Verify `payment_status` is `'paid'`.
    *   Verify the signup is not already `'processed'`.
*   **Execution**:
    *   Update `ems_signups.signup_status = 'processed'`.
    *   Update `processed_by = get_current_user_id()` and `processed_at = current_time('mysql')`.
    *   Copy `ems_signups.dofe_number` to `ems_osm_explorers.dofe_number` for the linked explorer profile.
    *   Copy parent-submitted `ems_signups.first_aid_status` to `ems_osm_explorers.first_aid_level` (only if the explorer's current verified level is `'none'` or lower than the submitted status).
*   **Sequencing Rationale**:
    > [!NOTE]
    > Since new recruits do not have an `ems_osm_explorers` record when they submit the signup form (they must be added to OSM and synced first), copying master profile attributes (such as the DofE registration number and first aid qualification) is strictly deferred to this processing phase. This ensures that the target explorer record is guaranteed to exist and is linked to the signup record before copying.

### 3.4 `POST ems/v1/signups/{id}/archive`
Archives the signup, removing it from active views.
*   **Execution**:
    *   Update `ems_signups.signup_status = 'archived'`.

---

## 4. UI Screen Specifications (React SPA)

The legacy PHP HTML rendering inside `Admin_Page::render_signups_page()` is replaced with a React mount point.

### 4.1 Asset Registration
In [Admin_Page](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Admin_Page.php):
*   Enqueue `assets/js/signups-board.js` for the `ems-signups` submenu hook.
*   Render `<div id="ems-signups-root"></div>`.
*   Use standard `@wordpress/components` styling templates.

### 4.2 Signups Board React Layout
A tabbed grid displaying all signups:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        EMS EXPLORER SIGNUPS                            │
├────────────────────────────────────────────────────────────────────────┤
│ Filter Level: [ All ]  Status: (•) Active  ( ) Processed  ( ) Archived │
├────────────────────────────────────────────────────────────────────────┤
│ Name       │ Level  │ ESU Unit   │ Payment │ Link Status   │ DofE Num  │
├────────────┼────────┼────────────┼─────────┼───────────────┼───────────┤
│ Jane Doe   │ Silver │ ESU 1      │ Paid    │ ✅ Linked     │ D-991234  │
│ [Process] [Archive]                                                    │
├────────────┼────────┼────────────┼─────────┼───────────────┼───────────┤
│ John Smith │ Bronze │ ESU 2      │ Paid    │ 🟡 Proposed    │ D-112233  │
│ [Link Explorer] [Archive]                  (Doe, John)                 │
├────────────┼────────┼────────────┼─────────┼───────────────┼───────────┤
│ Mark Davis │ Gold   │ Unassigned │ Pending │ ❌ Unlinked   │ —         │
│ [Link Explorer] [Archive]                                              │
└────────────────────────────────────────────────────────────────────────┘
```

#### A. Roster Columns:
*   **Explorer Name**: Explorer's first and last name.
*   **Level**: Badge indicating Bronze, Silver, or Gold.
*   **ESU Unit**: Mapped unit based on `unit_id`.
*   **Payment**: Green status indicator if Paid, else red Pending label.
*   **Link Status**:
    *   `✅ Linked`: Shows explorer is reconciled.
    *   `🟡 Proposed (Fuzzy Match)`: Renders explorer matching name/email, with a "Confirm Link" quick action.
    *   `❌ Unlinked`: Renders a warning with a "Link Explorer" search action button.
*   **DofE Number**: Displays the submitted DofE identifier.

#### B. Filter Bar:
*   **Level filter**: Bronze / Silver / Gold / All.
*   **Status toggles**: Radio buttons selecting `Active` (Pending), `Processed`, or `Archived` lists (default is `Active` so processed/archived rows are hidden by default).

#### C. Manual Link Search Dialog:
Clicking "Link Explorer" opens a modal overlay:
*   Renders a search input field queryable against `ems_osm_explorers` (searching by name, email, or patrol).
*   Displays search results in a list. Clicking "Confirm Link" dispatches a POST request to `/reconcile`.

#### D. Record Action Buttons:
*   **Process**: Dispatches a POST request to `/process` (updating the record and syncing the DofE number). *Disabled if Link Status is Unlinked or Payment Status is Pending.*
*   **Link Explorer**: Displays link selector modal.
*   **Archive**: Dispatches a POST request to `/archive`.

---

## 5. Gherkin Behavioral Scenarios

The Gherkin scenarios are added to `tests/features/milestone1-signups-board.feature` to test and guide backend compliance:

```gherkin
Feature: Milestone 1 - Signups Board & Reconciliation

  Scenario: Fuzzy matching maps signups to explorers by email
    Given a synced explorer exists with scout_id 101 and email "jane@example.com"
    And a signup exists with email "jane@example.com" and no scout_id
    When the administrator fetches signups list
    Then the signup record linkage_status should be "proposed"
    And the proposed_scout_id should be 101

  Scenario: Manually reconciling links signup to scout_id
    Given a synced explorer exists with scout_id 102
    And a signup exists with ID 10 and scout_id null
    When the administrator manually reconciles signup 10 to scout_id 102
    Then signup 10 scout_id should be 102
    And reconciled_by should match the current admin user ID

  Scenario: Processing copies DofE number to synced explorer record
    Given a synced explorer exists with scout_id 103 and dofe_number null
    And a paid signup exists with ID 11, scout_id 103, and dofe_number "D-882233"
    When the administrator processes signup 11
    Then signup 11 signup_status should be "processed"
    And explorer 103 dofe_number should be "D-882233"
    And processed_by should match the current admin user ID
```
