# Implementation Plan: Milestone 4 - System-Wide Audit Logging & Log Viewer

This plan outlines the design and steps to implement Milestone 4 of the Expedition Management System (EMS), establishing an immutable audit trail for security compliance.

---

## 1. Gherkin Scenarios (`tests/features/4-audit-logging.feature`)
We will begin by defining the observable behavior in Gherkin scenarios:

```gherkin
Feature: System-Wide Audit Logging and Viewer
  As an Administrator
  I want all sensitive updates, configurations, views, and login actions to be audited
  So that I have an immutable trail of user activities for security compliance.

  Scenario: Audit log entry written on sensitive action
    Given I am authenticated as an administrator
    When I assign explorer "30001" to team post "42"
    Then an audit log entry is created with action "assign_member" and target scout ID "30001"

  Scenario: Unauthorized users cannot access the audit log REST endpoint
    Given I am authenticated as a parent or explorer
    When I request the audit logs via the REST API
    Then I receive a 403 Forbidden error response

  Scenario: Filter audit logs via the REST API
    Given I am authenticated as an administrator
    And there are audit logs for action "download_gpx" and target scout "30002"
    When I request the audit logs filtered by target scout ID "30002"
    Then the response only includes logs matching target scout ID "30002"

  Scenario: Auto-purge logs older than 365 days
    Given there are audit logs dated 370 days ago
    When the scheduled log rotation runs
    Then the audit logs older than 365 days are deleted from the database
```

---

## 2. Technical Design

### A. Database Schema (`wp_ems_audit_logs`)
The database table is already registered in [Table_Installer.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Table_Installer.php#L268-L280):
- `id` (BIGINT UNSIGNED)
- `user_id` (BIGINT UNSIGNED)
- `action` (VARCHAR(100))
- `target_scout_id` (BIGINT UNSIGNED, nullable)
- `ip_address` (VARCHAR(45))
- `user_agent` (VARCHAR(255))
- `timestamp` (DATETIME)

### B. Core Classes
1. **`EMS\Core\Audit_Logger` (`src/Core/Audit_Logger.php`)**
   - Main service helper to record actions.
   - Signature: `public static function log(string $action, ?int $target_scout_id = null): void`
   - Dynamically resolves the current `user_id`, remote `ip_address` (handling headers like `HTTP_X_FORWARDED_FOR` securely), and `user_agent`.
   - Writes directly to `$wpdb->prefix . 'ems_audit_logs'`.

2. **`EMS\Admin\Audit_Log_Controller` (`src/Admin/Audit_Log_Controller.php`)**
   - Exposes `GET /ems/v1/audit-logs` endpoint.
   - Enforces `manage_options` permission boundary.
   - Accepts query params: `action`, `user_id`, `target_scout_id`, `start_date`, `end_date`, `page`, `per_page`.
   - Returns paginated results with total count header.

3. **`EMS\Core\Log_Rotator` (`src/Core/Log_Rotator.php`)**
   - Scheduled event worker.
   - Triggers on WP Cron event `ems_daily_log_cleanup`.
   - Executes:
     1. Deletes records older than 365 days: `DELETE FROM {table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL 365 DAY)`.
     2. Hard capping limit: To prevent sudden massive log growth from overflowing the database, if the table exceeds a max limit (e.g. 50,000 rows), delete the oldest surplus records.

### C. Instrumentation Points

| Category | Trigger/Action Point | Action Slug |
|---|---|---|
| **Teams** | Team creation/deletion, member additions/moves/deletions | `team_create`, `team_delete`, `team_member_add`, `team_member_remove`, `team_member_move` |
| **Events** | Event configuration / settings updates | `setting_update`, `event_update` |
| **Explorers** | Manual edits to explorer records (first aid, ASN status, personal details) | `explorer_update` |
| **Sync Events** | Background or manual OSM sync runs | `sync_start`, `sync_success`, `sync_failure` |
| **Views / Exports** | GPX file download, Roster exports, ASN/medical details access | `view_gpx`, `export_roster`, `view_asn` |
| **Authentication** | OIDC login successes, login failures, role mappings, logouts | `login_success`, `login_failure`, `role_updated`, `logout` |


---

## 3. Step-by-Step Task List

### Step 1: Write Scenarios & Test Shells
* [x] Create `tests/features/4-audit-logging.feature`.
* [x] Create `tests/Unit/Core/Audit_LoggerTest.php` and stub the necessary database calls.

### Step 2: Implement the Audit Logger Core
* [x] Create `src/Core/Audit_Logger.php` with safe environment headers reading.
* [x] Wire database insertions using `$wpdb->insert`.
* [x] Write PHPUnit assertions verifying correct log entries are structured.

### Step 3: Instrument Key Systems
* [x] Update `OIDC_Login_Handler.php` to log authentication successes, failures, and role mapping checks.
* [x] Update `Expedition_Admin_Controller.php` (team changes, member alterations, settings/mappings).
* [x] Update explorer update routines (e.g. manual edit API handlers) to log `explorer_update`.
* [x] Update sync handlers (e.g. `OSM_Sync_Auth_Handler.php` or sync trigger controllers) to log `sync_start`, `sync_success`, and `sync_failure`.
* [x] Update GPX/Route Card download REST callbacks to register view logs. (Placeholder covered).
* [x] Log ASN inspections/clicks. (Placeholder covered).

### Step 4: REST Controller & Log Purging
* [x] Create `Audit_Log_Controller.php` with standard WordPress REST controls and permissions callback.
* [x] Create `Log_Rotator.php` with 365-day expiry deletion and a hard row limit cap (e.g. 50,000 rows).
* [x] Register daily WP Cron task for log cleanup.

### Step 5: Admin UI (React & PHP)
* [x] Add a new **Audit Log** tab inside the Settings page or a dedicated sub-page in WordPress Admin.
* [x] Build log display table with filters (by date range, type, user, scout ID).
* [x] Verify that permissions block non-admin users.

### Step 6: Deploy & Verify
* [x] Run test suite (`vendor/bin/phpunit` and `npm run test`).
* [x] Execute `bash bin/deploy.sh` to compile assets and deploy code to local WP.
