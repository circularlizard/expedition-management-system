# Outstanding Architectural & Code Issues

This document records the architectural discrepancies, bugs, and technical debt identified during the systematic audit of the **Expedition Management System (EMS)** codebase.

---

## 1. Critical Database Query & Schema Issues

### 1.1 Tutor LMS Database Query Join Failure (Code Bug)
*   **Description**: In [`Admin_View_Controller.php` (Line 393)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Admin_View_Controller.php#L393), the training compliance query joins cached explorers to master units:
    ```sql
    LEFT JOIN wp_ems_units u ON e.section_id = u.section_id AND e.patrol = u.name
    ```
    However, during table refactoring, the columns `section_id` and `patrol` were deprecated and deleted from the `wp_ems_units` table (moved to `wp_ems_unit_patrols`). **Consequently, this database query will crash in production.**
*   **Action Required**: Refactor the query to join on the correct master unit identifier:
    ```sql
    LEFT JOIN wp_ems_units u ON e.section_id = u.unit_id
    ```

### 1.2 Backup & Portability Engine Sync Omissions (Data Loss Risk)
*   **Description**: The backup/export system in [`Portability_Engine.php` (Lines 21-31)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Portability_Engine.php#L21-L31) is out of sync with active database tables:
    1.  It references the legacy, deleted table `ems_signups`.
    2.  It completely omits the active tables: `ems_participant_signups`, `ems_expedition_signups`, `ems_volunteers`, and `ems_audit_logs`.
    *Result*: Importing or exporting settings will fail to preserve any signups, volunteer rosters, or audit log records.
*   **Action Required**: Update the table registration lists inside `Portability_Engine.php` to include the current tables.

---

## 2. Integration & Config Coupling Issues

### 2.1 Hardcoded Online Scout Manager (OSM) Flexi-Record Schemas
*   **Description**: In [`Pushback_Sync_Manager.php` (Lines 394, 423)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Pushback_Sync_Manager.php#L394), the pushback sync logic relies on a hardcoded flexi-record name of `"2026 Expeditions"` and expects six hardcoded columns (`PRACTICE GROUPS`, `PRACTICE ACCEPTED`, `QUALIFIER GROUPS`, `QUALIFIER ACCEPTED`, `TRAINING DAY`, `FIRST AID`). If they do not exist in OSM, the code attempts to create them on the fly.
*   **Action Required**: Centralize these schema strings as class constants or database-stored settings to allow future years (e.g. 2027) without code updates.

### 2.2 Tutor LMS Version Divergence (Free vs. Pro)
*   **Description**: The tracking code in [`TutorLMS_Client.php` (Lines 234-300)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php#L234) contains divergent check logic. It performs direct SQL queries on `wp_posts` (lessons/assignments) and `tutor_quiz_attempts` for Tutor LMS Free, but parses serialized user meta `_tutor_reading_info_{course_id}` for Tutor LMS Pro. This logic is complex and undocumented.
*   **Action Required**: Abstract version-specific check paths behind a clear adapter pattern inside the Client class.

### 2.3 Fragmented First Aid Columns
*   **Description**: First Aid status captured during Form 7 submission is written to `ems_expedition_signups.first_aid_status`, while the admin panel uses `ems_osm_explorers.first_aid_level` for the master roster. These fields are not automatically synchronized upon reconciliation.
*   **Action Required**: Implement a mapping handler that propagates first aid status updates to the cached explorer record upon signup processing.

---

## 3. Security & Code Hygiene Issues

### 3.1 Fragile OIDC Token Capture Interceptor
*   **Description**: [`OIDC_Login_Handler.php` (Lines 29-39)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php#L29) intercepts outgoing requests via the `http_response` filter, checking if the destination URL contains `/token` or `/oauth/token` to grab the OIDC token. This is fragile: any third-party plugin running a token handshake on a URL containing these strings will overwrite the in-memory `$captured_token`.
*   **Action Required**: Restrict URL matching to target only the specific OSM OAuth endpoint, or check specific request parameters/contexts.

### 3.2 Unused Auth Provider Abstractions (ADR 012 Dead Code)
*   **Description**: While `Auth_Provider.php`, `LoginWithGoogle_Auth_Provider.php`, and `Mock_Auth_Provider.php` were created to isolate OIDC dependencies, they are never instantiated or used. The OIDC handler hooks directly into WordPress instead.
*   **Action Required**: Remove the dead interface files or refactor `OIDC_Login_Handler` to implement them.

### 3.3 Deprecated Season CPT & Repository
*   **Description**: Even though the `season` CPT was deprecated and deleted (as documented in ADR 003), the repository files are still defined and injected. Furthermore, [`Expedition_Admin_Controller::get_expedition_board()`](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L670) mocks a "synthetic" season to satisfy the React frontend API contract.
*   **Action Required**: Refactor the React frontend and WP REST controllers to completely remove the synthetic season layer.
