# Outstanding Architectural & Code Issues & Signup Go-Live Plan

This document records the architectural discrepancies, bugs, and technical debt identified during the audit of the **Expedition Management System (EMS)** codebase, along with a prioritized triage and remediation plan for the public signup forms launch (Participant Form 6, Expedition Form 7, and Volunteer Signup).

---

## 1. Signup Go-Live Triage Matrix

| ID | Issue | Urgency for Live Signups | Status | Impact on Signup Launch & Operational Rationale |
|---|---|---|---|---|
| **1.1** | **Tutor LMS Database Query Join Failure** | **🟡 Quick Fix (Admin Safety)** | ✅ **Resolved** | Fixed queries in `Admin_View_Controller.php` (L314–324) and `Expedition_Admin_Controller.php` (L1445–1457) to join `ems_unit_patrols` and `ems_units` on `u.unit_id = p.unit_id`. |
| **1.2** | **Backup & Portability Engine Sync Omissions** | **🔴 Must Fix Before Live** | ✅ **Resolved** | Updated `Portability_Engine.php` with all active split signup tables (`ems_participant_signups`, `ems_expedition_signups`, `ems_volunteers`, `ems_audit_logs`) and active form configuration options. Full test coverage added in `Portability_EngineTest.php`. |
| **3.1** | **Fragile OIDC Token Capture Interceptor** | **🟡 Highly Recommended (Pre-Launch)** | ✅ **Resolved** | Hardened `OIDC_Login_Handler::capture_token_from_response` (L29–48) to restrict token capture to verified OSM OAuth endpoints/hosts, ignoring third-party OAuth token traffic. Unit tested in `OIDC_Login_HandlerTest.php`. |
| **3.2** | **Unused Auth Provider Abstractions (ADR 012)** | **🟢 Can Defer (Post-Launch)** | ✅ **Resolved** | Dead interface and provider files (`Auth_Provider.php`, `Mock_Auth_Provider.php`, `LoginWithGoogle_Auth_Provider.php`) deleted from `src/Auth/` during Phase 3 codebase cleanup (Commit `47e6e9c`). |
| **2.3** | **Fragmented First Aid Columns & Reconciliation Sync** | **🟡 High Value (Reconciliation)** | ⏳ **Open (Pending Action)** | Form 7 captures first aid to `ems_expedition_signups.first_aid_status`. Reconciliation (`Signup_Repository::reconcile_signup`) links `scout_id` but does NOT propagate first aid to `ems_osm_explorers.first_aid_level`, leaving master roster and flexi-record pushback blank. |
| **2.1** | **Hardcoded OSM Flexi-Record Schemas** | **🟢 Can Defer (Post-Launch)** | ⏳ **Open (Deferred)** | Pushback sync logic (`Pushback_Sync_Manager.php` L394, L423) uses hardcoded `'2026 Expeditions'` schema. Valid for current season; can be parameterized post-launch. |
| **2.2** | **Tutor LMS Version Divergence (Free vs. Pro)** | **🟢 Can Defer (Post-Launch)** | ⏳ **Open (Deferred)** | Internal LMS quiz/lesson query divergence in `TutorLMS_Client.php` (L201–325); has no runtime dependency on signup submission or reconciliation. |
| **3.3** | **Deprecated Season CPT & Synthetic Layer** | **🟢 Can Defer (Post-Launch)** | ⏳ **Open (Deferred)** | Season CPT is retired from `CPT_Registry.php`. However, `Expedition_Admin_Controller::get_board()` still synthesizes a virtual season (`ID => 0, post_title => 'All Events'`) for frontend compatibility, and `Season_Repository.php` remains as dead code. |
| **3.4** | **REST API Gating & Documentation Mismatch** | **🟢 Can Defer (Doc Update)** | ℹ️ **Doc Mismatch Only** | Code functions as intended: public volunteer wizard uses `/ems/v1/volunteers/signup` (public), while `/volunteers/availability` is intentionally restricted to admins modifying shifts. |

---

## 2. Issue Details & Launch Impact

### 2.1 Database & Schema Issues

#### 1.1 Tutor LMS Database Query Join Failure (Code Bug) — STATUS: RESOLVED
*   **Location**: [`Admin_View_Controller.php` (Lines 314–324)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Admin_View_Controller.php#L314-L324) & [`Expedition_Admin_Controller.php` (Lines 1445–1457)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L1445-L1457)
*   **Resolution**: Refactored the training compliance query and explorer profile query to route through `ems_unit_patrols`:
    ```sql
    FROM {$explorers_table} e
    LEFT JOIN {$wpdb->prefix}ems_unit_patrols p ON e.section_id = p.section_id AND e.patrol = p.name
    LEFT JOIN {$wpdb->prefix}ems_units u ON (p.unit_id = u.unit_id OR e.section_id = u.unit_id)
    WHERE e.scout_id IN ({$ids_placeholder})
    ```
    Eliminates fatal SQL error when viewing Training Compliance reports. Verified clean.

#### 1.2 Backup & Portability Engine Sync Omissions (Data Loss & Option Omissions) — STATUS: RESOLVED
*   **Location**: [`Portability_Engine.php` (Lines 6–41)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Portability_Engine.php#L6-L41)
*   **Resolution**: Updated `TABLES_TO_EXPORT` to include all 12 split active tables (`ems_volunteers`, `ems_team_members`, `ems_volunteer_availability`, `ems_route_submissions`, `ems_osm_explorers`, `ems_osm_events`, `ems_osm_event_attendance`, `ems_units`, `ems_unit_patrols`, `ems_participant_signups`, `ems_expedition_signups`, `ems_audit_logs`). Updated `OPTIONS_TO_EXPORT` to include all active Fluent Forms IDs and form mappings. Verified by `Portability_EngineTest.php`.

---

### 2.2 Integration & Config Coupling Issues

#### 2.1 Hardcoded Online Scout Manager (OSM) Flexi-Record Schemas — STATUS: OPEN (DEFERRED)
*   **Location**: [`Pushback_Sync_Manager.php` (Lines 394, 405, 423–430)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Pushback_Sync_Manager.php#L394)
*   **Description**: Pushback sync logic relies on a hardcoded flexi-record name of `"2026 Expeditions"` and expects six hardcoded columns (`PRACTICE GROUPS`, `PRACTICE ACCEPTED`, `QUALIFIER GROUPS`, `QUALIFIER ACCEPTED`, `TRAINING DAY`, `FIRST AID`).
*   **Launch Impact**: None for signup launch. Pushback runs downstream after team allocations. The hardcoded 2026 schema is valid for the current season.
*   **Action Required**: Centralize schema strings as class constants or database settings (can be deferred).

#### 2.2 Tutor LMS Version Divergence (Free vs. Pro) — STATUS: OPEN (DEFERRED)
*   **Location**: [`TutorLMS_Client.php` (Lines 201–325)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php#L201-L325)
*   **Description**: Divergent check logic for Tutor LMS Free (direct SQL on `wp_posts` and `tutor_quiz_attempts`) vs Pro (serialized meta `_tutor_reading_info_{course_id}`).
*   **Launch Impact**: None for signup launch.
*   **Action Required**: Abstract version-specific check paths behind an adapter interface (can be deferred).

#### 2.3 Fragmented First Aid Columns & Reconciliation Sync — STATUS: OPEN (PENDING ACTION)
*   **Location**: [`Signup_Repository.php` (Lines 316–368)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Signup_Repository.php#L316-L368), [`Fluent_Forms_Sync.php` (Lines 446–447)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Fluent_Forms_Sync.php#L446-L447), [`Expedition_Admin_Controller.php` (Lines 1282–1298)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L1282-L1298)
*   **Description**: First Aid status captured during Form 7 submission is written to `ems_expedition_signups.first_aid_status`, while the admin panel and team planner use `ems_osm_explorers.first_aid_level`. In `Signup_Repository::reconcile_signup()`, matching an unmatched signup only updates `scout_id` on the signup tables. It does **not** copy `first_aid_status` to `ems_osm_explorers.first_aid_level`.
*   **Launch Impact**: Form submissions succeed, but manual or automatic reconciliation does not populate first aid on the master roster. As a consequence, OSM flexi-record pushback pushes blank first aid values unless organizers manually update first aid via the single-explorer REST endpoint.
*   **Action Required**: Update `Signup_Repository::reconcile_signup()` to propagate `first_aid_status` from `ems_expedition_signups` to `ems_osm_explorers.first_aid_level`.

---

### 2.3 Security & Code Hygiene Issues

#### 3.1 Fragile OIDC Token Capture Interceptor — STATUS: RESOLVED
*   **Location**: [`OIDC_Login_Handler.php` (Lines 29–48)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php#L29-L48)
*   **Resolution**: Hardened in commit `fe814fe` to strictly validate that destination URLs match configured OSM hosts (`ems_osm_token_url` or `ems_osm_api_base_url`), ignoring third-party OAuth token traffic. Verified by unit tests in `OIDC_Login_HandlerTest.php`.

#### 3.2 Unused Auth Provider Abstractions (ADR 012 Dead Code) — STATUS: RESOLVED
*   **Location**: `src/Auth/`
*   **Resolution**: Dead interface and provider files (`Auth_Provider.php`, `Mock_Auth_Provider.php`, `LoginWithGoogle_Auth_Provider.php`) were deleted during Phase 3 dead code removal (Commit `47e6e9c`).

#### 3.3 Deprecated Season CPT & Synthetic Layer — STATUS: OPEN (DEFERRED)
*   **Location**: [`Expedition_Admin_Controller.php` (Lines 668–682)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L668-L682) & [`Season_Repository.php` (Lines 1–119)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Season_Repository.php#L1-L119)
*   **Description**: The `season` CPT has been retired and removed from `CPT_Registry.php`. However, a synthetic season object (`ID => 0, post_title => 'All Events'`) remains in `Expedition_Admin_Controller::get_board()` to support legacy React expectations, and `Season_Repository.php` remains as dead code.
*   **Launch Impact**: None for signup forms.
*   **Action Required**: Remove synthetic season layer when frontend is updated, and delete `Season_Repository.php` (can be deferred).

#### 3.4 REST API Gating & Documentation Mismatch — STATUS: RESOLVED (DOCUMENTATION CLARIFIED)
*   **Location**: [`Volunteer_Controller.php` (Lines 23–31, 63–71)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Volunteer_Controller.php#L23-L31)
*   **Description**: Code operates correctly as designed:
    *   `/ems/v1/volunteers/signup` (POST) is public (`__return_true`) for prospective volunteers submitting wizard registrations (with `user_id`/`osm_user_id` stripped).
    *   `/ems/v1/volunteers/availability` (POST) is restricted to `manage_options` (Admin only) for leaders modifying confirmed shifts.
*   **Launch Impact**: None. The public form already targets `/volunteers/signup`.

---

## 3. Pre-Launch Action Plan

To ensure seamless public signup operations and data safety, the pre-launch steps are tracked below:

1.  **Step 1: Fix Portability Engine Table & Option Lists (Issue 1.2)** — ✅ **COMPLETED**
    *   Updated `Portability_Engine.php` to register `ems_participant_signups`, `ems_expedition_signups`, `ems_volunteers`, and `ems_audit_logs`.
    *   Updated `OPTIONS_TO_EXPORT` to include all active Fluent Forms mapping and ID options.
    *   PHPUnit test coverage verified in `Portability_EngineTest.php`.

2.  **Step 2: Harden OIDC Token Capture (Issue 3.1)** — ✅ **COMPLETED**
    *   Updated `OIDC_Login_Handler::capture_token_from_response` to validate destination URLs against configured OSM endpoints.
    *   PHPUnit test coverage verified in `OIDC_Login_HandlerTest.php`.

3.  **Step 3: Fix Tutor LMS Training Query (Issue 1.1)** — ✅ **COMPLETED**
    *   Updated SQL joins in `Admin_View_Controller.php` and `Expedition_Admin_Controller.php` to reference `u.unit_id = p.unit_id`.
    *   Training Compliance report and explorer profile queries verified clean.

4.  **Step 4: Connect First Aid Synchronization on Reconciliation (Issue 2.3)** — ⏳ **PENDING ACTION**
    *   Update `Signup_Repository::reconcile_signup` to copy `first_aid_status` from `ems_expedition_signups` into `ems_osm_explorers.first_aid_level` when matching expedition signups.
