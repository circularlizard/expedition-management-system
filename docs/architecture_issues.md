# Outstanding Architectural & Code Issues & Signup Go-Live Plan

This document records the architectural discrepancies, bugs, and technical debt identified during the audit of the **Expedition Management System (EMS)** codebase, along with a prioritized triage and remediation plan for the public signup forms launch (Participant Form 6, Expedition Form 7, and Volunteer Signup).

---

## 1. Signup Go-Live Triage Matrix

| ID | Issue | Urgency for Live Signups | Status | Impact on Signup Launch & Operational Rationale |
|---|---|---|---|---|
| **1.2** | **Backup & Portability Engine Sync Omissions** | **🔴 Must Fix Before Live** | ✅ **Resolved** | Updated `Portability_Engine.php` with all active split signup tables (`ems_participant_signups`, `ems_expedition_signups`, `ems_volunteers`, `ems_audit_logs`) and active form configuration options. Full test coverage added. |
| **3.1** | **Fragile OIDC Token Capture Interceptor** | **🟡 Highly Recommended (Pre-Launch)** | ✅ **Resolved** | Hardened `OIDC_Login_Handler::capture_token_from_response` to restrict token capture to verified OSM OAuth endpoints/hosts, ignoring third-party OAuth token traffic. |
| **2.3** | **Fragmented First Aid Columns** | **🟡 High Value (Reconciliation)** | ⏳ Pending Redesign | First aid capture will be simplified to yes/no. Synchronization will be implemented in accordance with simplified model. |
| **1.1** | **Tutor LMS Database Query Join Failure** | **🟡 Quick Fix (Admin Safety)** | ✅ **Resolved** | Fixed queries in `Admin_View_Controller.php` and `Expedition_Admin_Controller.php` to correctly join `ems_unit_patrols` and `ems_units`. |
| **2.1** | **Hardcoded OSM Flexi-Record Schemas** | **🟢 Can Defer (Post-Launch)** | Open | Pushback to OSM occurs downstream after signups close and teams are allocated. The hardcoded `"2026 Expeditions"` schema is valid for the current season. |
| **2.2** | **Tutor LMS Version Divergence (Free vs. Pro)** | **🟢 Can Defer (Post-Launch)** | Open | Internal LMS quiz/lesson query divergence; has no runtime dependency on signup submission or reconciliation. |
| **3.2** | **Unused Auth Provider Abstractions (ADR 012)** | **🟢 Can Defer (Post-Launch)** | Open | Dead interface files (`Auth_Provider.php`, `Mock_Auth_Provider.php`); zero runtime impact. |
| **3.3** | **Deprecated Season CPT & Synthetic Layer** | **🟢 Can Defer (Post-Launch)** | Open | Synthetic season object in expedition board controller; does not affect signup forms. |
| **3.4** | **REST API Gating & Documentation Mismatch** | **🟢 Can Defer (Doc Update)** | Open | Public volunteer wizard uses `/ems/v1/volunteers/signup` (public and functioning). `/volunteers/availability` is intentionally restricted to admins. |

---

## 2. Issue Details & Launch Impact

### 2.1 Database & Schema Issues

#### 1.1 Tutor LMS Database Query Join Failure (Code Bug)
*   **Location**: [`Admin_View_Controller.php` (Line 393)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Admin_View_Controller.php#L393)
*   **Description**: The training compliance query joins cached explorers to master units:
    ```sql
    LEFT JOIN wp_ems_units u ON e.section_id = u.section_id AND e.patrol = u.name
    ```
    However, during table refactoring, the columns `section_id` and `patrol` were deprecated and deleted from `wp_ems_units` (moved to `wp_ems_unit_patrols`). **Viewing the training report causes a fatal SQL error in production.**
*   **Launch Impact**: Does not block public form submissions directly, but crashes the Training Compliance dashboard when administrators or unit leaders review participant training progress.
*   **Action Required**: Refactor the query to join on the unit identifier:
    ```sql
    LEFT JOIN wp_ems_units u ON e.section_id = u.unit_id
    ```

#### 1.2 Backup & Portability Engine Sync Omissions (Data Loss & Option Omissions)
*   **Location**: [`Portability_Engine.php` (Lines 21-31)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Portability_Engine.php#L21-L31)
*   **Description**: The backup/export system is out of sync with active database tables and configuration options:
    1.  **Table Omissions**: References legacy `ems_signups` and omits active tables: `ems_participant_signups`, `ems_expedition_signups`, `ems_volunteers`, and `ems_audit_logs`.
    2.  **Option Omissions**: Exports legacy options (`ems_form_mappings`, `ems_fluent_form_id`) while omitting active configuration settings (`ems_fluent_participant_form_id`, `ems_fluent_expedition_form_id`, `ems_participant_form_mappings`, `ems_expedition_form_mappings`, `ems_page_roles`, `ems_protect_tutor_lms`, `ems_osm_auth_url`, `ems_osm_token_url`, `ems_osm_resource_url`).
*   **Launch Impact**: **CRITICAL BLOCKER FOR LIVE OPERATIONS.**
    *   Migrating configuration from staging to production via Portability Engine will drop form mappings and form IDs.
    *   Any backup taken after launch will completely omit all live participant signups, expedition signups, and volunteer rosters.
*   **Action Required**: Update `TABLES_TO_EXPORT` and `OPTIONS_TO_EXPORT` arrays in `Portability_Engine.php` to include current split tables and active configuration options.

---

### 2.2 Integration & Config Coupling Issues

#### 2.1 Hardcoded Online Scout Manager (OSM) Flexi-Record Schemas
*   **Location**: [`Pushback_Sync_Manager.php` (Lines 394, 423)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Pushback_Sync_Manager.php#L394)
*   **Description**: Pushback sync logic relies on a hardcoded flexi-record name of `"2026 Expeditions"` and expects six hardcoded columns (`PRACTICE GROUPS`, `PRACTICE ACCEPTED`, `QUALIFIER GROUPS`, `QUALIFIER ACCEPTED`, `TRAINING DAY`, `FIRST AID`).
*   **Launch Impact**: None for signup launch. Pushback runs downstream after team allocations. The hardcoded 2026 schema is valid for the current season.
*   **Action Required**: Centralize schema strings as class constants or database settings (can be deferred).

#### 2.2 Tutor LMS Version Divergence (Free vs. Pro)
*   **Location**: [`TutorLMS_Client.php` (Lines 234-300)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php#L234)
*   **Description**: Divergent check logic for Tutor LMS Free (direct SQL on `wp_posts` and `tutor_quiz_attempts`) vs Pro (serialized meta `_tutor_reading_info_{course_id}`).
*   **Launch Impact**: None for signup launch.
*   **Action Required**: Abstract version-specific check paths behind an adapter interface (can be deferred).

#### 2.3 Fragmented First Aid Columns
*   **Location**: [`Signup_Repository.php`](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Signup_Repository.php) & [`Fluent_Forms_Sync.php`](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Fluent_Forms_Sync.php)
*   **Description**: First Aid status captured during Form 7 submission is written to `ems_expedition_signups.first_aid_status`, while the admin panel and team planner use `ems_osm_explorers.first_aid_level`. Reconciling a signup does not currently propagate first aid status to the explorer record.
*   **Launch Impact**: Form submissions succeed, but manual or automatic reconciliation does not populate first aid on the master roster, requiring duplicate entry.
*   **Action Required**: Propagate first aid status to `ems_osm_explorers` during signup reconciliation / approval.

---

### 2.3 Security & Code Hygiene Issues

#### 3.1 Fragile OIDC Token Capture Interceptor
*   **Location**: [`OIDC_Login_Handler.php` (Lines 29-39)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php#L29)
*   **Description**: Intercepts outgoing requests via `http_response` filter, checking if destination URL contains `/token` or `/oauth/token`. Any third-party plugin running a token handshake on a URL containing these strings can overwrite `$captured_token`.
*   **Launch Impact**: High risk for parents logging in via OSM OIDC to complete signup forms. If the captured token is lost/corrupted, EMS cannot fetch the parent's children from OSM, resulting in an empty "Select Child" dropdown.
*   **Action Required**: Restrict URL matching to target only the configured OSM OAuth endpoint (`ems_osm_token_url` or `ems_osm_api_base_url`).

#### 3.2 Unused Auth Provider Abstractions (ADR 012 Dead Code)
*   **Location**: `src/Auth/`
*   **Description**: Unused dead interface files (`Auth_Provider.php`, `LoginWithGoogle_Auth_Provider.php`, `Mock_Auth_Provider.php`).
*   **Launch Impact**: None (no runtime impact).
*   **Action Required**: Remove dead interfaces or refactor handler (can be deferred).

#### 3.3 Deprecated Season CPT & Repository
*   **Location**: [`Expedition_Admin_Controller.php` (Line 670)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L670)
*   **Description**: Synthetic season layer remains to support legacy React frontend expectations.
*   **Launch Impact**: None for signup forms.
*   **Action Required**: Remove synthetic season layer and cleanup repository (can be deferred).

#### 3.4 REST API Gating & Documentation Mismatch
*   **Location**: [`Volunteer_Controller.php` (Line 65)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Volunteer_Controller.php#L65)
*   **Description**: `/volunteers/availability` (POST) is restricted to `manage_options` (Admin only), whereas the public volunteer signup form uses `/volunteers/signup` (public).
*   **Launch Impact**: None. The public form already uses the correct endpoint.
*   **Action Required**: Align documentation with code permissions.

---

## 3. Pre-Launch Action Plan

To ensure seamless public signup operations and data safety, implement the following steps before opening the signup forms:

1.  **Step 1: Fix Portability Engine Table & Option Lists (Issue 1.2)**
    *   Update `Portability_Engine.php` to register `ems_participant_signups`, `ems_expedition_signups`, `ems_volunteers`, and `ems_audit_logs`.
    *   Update `OPTIONS_TO_EXPORT` to include all active Fluent Forms mapping and ID options.
    *   Add PHPUnit test coverage for export/import integrity.

2.  **Step 2: Harden OIDC Token Capture (Issue 3.1)**
    *   Update `OIDC_Login_Handler::capture_token_from_response` to validate destination URLs against configured OSM endpoints.
    *   Verify parent OIDC login test cases pass.

3.  **Step 3: Fix Tutor LMS Training Query (Issue 1.1)**
    *   Update SQL join in `Admin_View_Controller.php` to reference `u.unit_id`.
    *   Verify Training Compliance report loads cleanly without database errors.

4.  **Step 4: Connect First Aid Synchronization on Reconciliation (Issue 2.3)**
    *   Ensure `Signup_Repository::reconcile_signup` updates `ems_osm_explorers.first_aid_level` when matching expedition signups.
