# Outstanding Architectural & Technical Debt Issues

This document tracks the **active, unresolved architectural issues, data reconciliation gaps, and technical debt** in the **Expedition Management System (EMS)** codebase.

> [!NOTE]
> Previously resolved issues (1.1 SQL Join Fix, 1.2 Portability Engine Sync, 3.1 OIDC Token Interceptor Hardening, and 3.2 Dead Auth Provider Removal) have been verified and archived in [`docs/archive/architecture_issues.md`](file:///Users/davidstrachan/Projects/expedition-management-system/docs/archive/architecture_issues.md).

---

## 1. Active Issues Triage Matrix

| ID | Issue | Priority / Urgency | Status | Category | Impact & Operational Context |
|---|---|---|---|---|---|
| **2.3** | **Fragmented First Aid Columns & Reconciliation Sync** | **🟡 High (Pre-Launch Recommended)** | ⏳ **Open (Pending Action)** | Data Consistency | Form 7 captures first aid to `ems_expedition_signups.first_aid_status`. Reconciliation (`Signup_Repository::reconcile_signup`) links `scout_id` but does NOT propagate first aid to `ems_osm_explorers.first_aid_level`, leaving the master roster and OSM flexi-record pushback blank. |
| **2.1** | **Hardcoded OSM Flexi-Record Schemas** | **🟢 Low (Post-Launch Deferral)** | ⏳ **Open (Deferred)** | Integration Coupling | Pushback sync logic (`Pushback_Sync_Manager.php` L394, L423) uses hardcoded `'2026 Expeditions'` schema and fixed column names. Valid for current season; needs parameterization post-launch. |
| **2.2** | **Tutor LMS Version Divergence (Free vs. Pro)** | **🟢 Low (Post-Launch Deferral)** | ⏳ **Open (Deferred)** | Architecture / Polymorphism | Internal LMS quiz/lesson completion check logic in `TutorLMS_Client.php` (L201–325) hardcodes fallback paths across Tutor Free vs Pro. No runtime failure for signups. |
| **3.3** | **Deprecated Season CPT & Synthetic Layer** | **🟢 Low (Post-Launch Deferral)** | ⏳ **Open (Deferred)** | Technical Debt / Dead Code | Season CPT is retired. `Expedition_Admin_Controller::get_board()` still synthesizes a virtual season (`ID => 0, post_title => 'All Events'`) for frontend backward compatibility, and `Season_Repository.php` remains as dead code. |
| **3.4** | **REST API Gating & Documentation Alignment** | **ℹ️ Informational (Doc Clarification)** | ℹ️ **Documented / Clarified** | Security Architecture | Public volunteer wizard targets `/ems/v1/volunteers/signup` (public with `user_id`/`osm_user_id` stripped), while `/volunteers/availability` is restricted to admins (`manage_options`). Clear architectural role distinction documented. |

---

## 2. Detailed Technical Breakdown

### Issue 2.3: Fragmented First Aid Columns & Reconciliation Sync

*   **Priority:** **Pre-Launch Priority** (High Value for Go-Live)
*   **Target Files:**
    *   [`src/Data/Signup_Repository.php` (Lines 316–368)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Signup_Repository.php#L316-L368)
    *   [`src/Integrations/Fluent_Forms_Sync.php` (Lines 446–447)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Fluent_Forms_Sync.php#L446-L447)
    *   [`src/Admin/Expedition_Admin_Controller.php` (Lines 1282–1298)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L1282-L1298)
*   **Problem Analysis:**
    *   Participant Expedition Form 7 collects the explorer's first aid qualification status (e.g., `'Full'`, `'Emergency'`, `'None'`, `'Expiring'`).
    *   When the form is submitted, `Fluent_Forms_Sync::handle_expedition_signup()` writes this value to the database column `ems_expedition_signups.first_aid_status`.
    *   However, the Team Planning Board, Event Detail views, Master Explorer Roster, and OSM Pushback Engine read and push `ems_osm_explorers.first_aid_level`.
    *   When an administrator reconciles an unmatched signup against an existing OSM explorer via `Signup_Repository::reconcile_signup()`, the method updates `scout_id` and sets `signup_status = 'processed'`, but it **does not** synchronize `first_aid_status` into `ems_osm_explorers.first_aid_level`.
*   **Operational & Launch Impact:**
    *   Form submissions succeed without database errors.
    *   However, after signups are reconciled, the Master Explorer Roster and Event Planning views continue to display first aid as blank or stale.
    *   Downstream OSM pushback (`Pushback_Sync_Manager`) pushes empty first aid strings back to OSM flexi-records unless leaders manually edit each explorer profile via the single-explorer REST endpoint.
*   **Remediation Plan:**
    1.  In [`Signup_Repository::reconcile_signup()`](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Signup_Repository.php#L316), check if the reconciled signup record is from `ems_expedition_signups` and contains a non-empty `first_aid_status`.
    2.  If present, execute an update query against `$wpdb->prefix . 'ems_osm_explorers'` to update `first_aid_level` for the matching `scout_id`, updating `last_local_update_at = current_time('mysql')`.
    3.  Add unit test coverage in `tests/Unit/Data/Signup_RepositoryTest.php` asserting that reconciling an expedition signup updates `ems_osm_explorers.first_aid_level`.

---

### Issue 2.1: Hardcoded OSM Flexi-Record Schemas

*   **Priority:** **Post-Launch Deferral** (Low Urgency)
*   **Target Files:**
    *   [`src/Integrations/Pushback_Sync_Manager.php` (Lines 394, 405, 423–430)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Pushback_Sync_Manager.php#L394)
*   **Problem Analysis:**
    *   The pushback routine currently contains hardcoded string literals:
        *   Flexi-record name: `'2026 Expeditions'`
        *   Expected columns: `'PRACTICE GROUPS'`, `'PRACTICE ACCEPTED'`, `'QUALIFIER GROUPS'`, `'QUALIFIER ACCEPTED'`, `'TRAINING DAY'`, `'FIRST AID'`.
    *   If a district changes the flexi-record name in Online Scout Manager for a new season (e.g. `'2027 Expeditions'`) or renames columns, the flexi-record structure lookup fails.
*   **Operational & Launch Impact:**
    *   Zero impact for the upcoming 2026 season launch, as the active OSM flexi-record structure is already configured as `'2026 Expeditions'` with these exact columns.
    *   Pushback sync only runs after teams are allocated, which occurs weeks after the initial signup window closes.
*   **Remediation Plan:**
    1.  Introduce a WordPress option (`ems_osm_flexirecord_name`, defaulting to `'2026 Expeditions'`) or define class constants with a fallback filter (`apply_filters('ems_flexirecord_schema_name', ...)`).
    2.  Allow column mapping overrides via the existing `Flexi_Mapper_Controller` admin settings page.

---

### Issue 2.2: Tutor LMS Version Divergence (Free vs. Pro)

*   **Priority:** **Post-Launch Deferral** (Low Urgency)
*   **Target Files:**
    *   [`src/Integrations/TutorLMS_Client.php` (Lines 201–325)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php#L201-L325)
*   **Problem Analysis:**
    *   `TutorLMS_Client::get_user_course_progress()` implements a sequential 5-step fallback to determine lesson and quiz completion:
        1. Checks for Tutor LMS Pro serialized postmeta `_tutor_reading_info_{course_id}`.
        2. Falls back to querying `tutor_quiz_attempts` table directly.
        3. Falls back to querying WordPress `wp_comments` (where Tutor LMS Free historically logged completed lessons as comment type `tutor_lesson_completed`).
        4. Validates active course enrollment in `wp_posts` with `post_type = 'tutor_enrolled'`.
    *   While robust in practice, this monolithic query logic couples Free and Pro database schemas into a single procedural method without adapter polymorphism.
*   **Operational & Launch Impact:**
    *   Zero impact on public signup forms (Forms 6, 7, and Volunteer Signup have no runtime dependency on Tutor LMS progress evaluation).
    *   Current tests in `TutorLMS_ClientTest.php` pass across both mock scenarios.
*   **Remediation Plan:**
    1.  Define a `Tutor_LMS_Adapter_Interface` with methods `is_course_completed(int $user_id, int $course_id): bool` and `get_course_progress(int $user_id, int $course_id): array`.
    2.  Extract `Tutor_LMS_Pro_Adapter` and `Tutor_LMS_Free_Adapter` classes with runtime detection.

---

### Issue 3.3: Deprecated Season CPT & Synthetic Layer

*   **Priority:** **Post-Launch Deferral** (Technical Debt Cleanup)
*   **Target Files:**
    *   [`src/Admin/Expedition_Admin_Controller.php` (Lines 668–682)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php#L668-L682)
    *   [`src/Data/Season_Repository.php` (Lines 1–119)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Season_Repository.php#L1-L119)
*   **Problem Analysis:**
    *   The `season` CPT has been fully retired and removed from `CPT_Registry.php`. All expeditions now live at top level (`post_parent = 0`).
    *   However, to avoid breaking legacy React frontend components that expect a `season` wrapper object in the planning board payload, `Expedition_Admin_Controller::get_board()` synthesizes a virtual season:
        ```php
        $seasons = [
            [
                'ID' => 0,
                'post_title' => 'All Events',
                'events' => $events,
            ],
        ];
        ```
    *   Additionally, [`src/Data/Season_Repository.php`](file:///Users/davidstrachan/Projects/expedition-management-system/src/Data/Season_Repository.php) still exists on disk even though no active service or controller injects it.
*   **Operational & Launch Impact:**
    *   Zero impact on system functionality. The synthetic layer maintains complete backward compatibility with the frontend React components (`EventPlanningBoard.tsx`).
*   **Remediation Plan:**
    1.  Refactor `resources/js/admin/expedition-board/` components to consume flat event lists directly without requiring a parent season grouping object.
    2.  Remove the synthetic season loop in `Expedition_Admin_Controller::get_board()`.
    3.  Delete `src/Data/Season_Repository.php` and its associated unit test `tests/Unit/Data/Season_RepositoryTest.php`.

---

### Issue 3.4: REST API Gating & Documentation Alignment

*   **Priority:** **Informational / Clarified**
*   **Target Files:**
    *   [`src/Admin/Volunteer_Controller.php` (Lines 23–31, 63–71)](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Volunteer_Controller.php#L23-L31)
*   **Architectural Clarification:**
    *   An earlier review flagged a potential permissions mismatch between volunteer endpoints. Inspection confirms the architecture is working exactly as intended:
        *   **`/ems/v1/volunteers/signup` (POST)**: Intended for prospective public volunteers. Protected by permission callback `__return_true`. Critical security hardening was applied in Phase 1 (Commit `fe814fe`) to unconditionally strip `user_id` and `osm_user_id` from client payloads, preventing unauthorized privilege escalation.
        *   **`/ems/v1/volunteers/availability` (POST)**: Intended exclusively for administrators and Leaders in Charge (LiCs) to confirm and modify volunteer allocations. Appropriately restricted to `manage_options`.
*   **Remediation Plan:**
    *   Maintained as documented architectural specification. No code changes required.

---

## 3. Recommended Sequencing

1.  **Immediate Step (Pre-Launch Priority):**
    *   Implement **Issue 2.3** in `src/Data/Signup_Repository.php` so that reconciling expedition signups automatically updates `ems_osm_explorers.first_aid_level`.
2.  **Post-Launch Step 1 (Technical Debt):**
    *   Implement **Issue 3.3** to eliminate the synthetic season layer and remove `Season_Repository.php`.
3.  **Post-Launch Step 2 (Configuration Flexibility):**
    *   Implement **Issue 2.1** to make OSM flexi-record schemas configurable for future expedition seasons (2027+).
4.  **Post-Launch Step 3 (LMS Refactor):**
    *   Implement **Issue 2.2** to extract Tutor LMS Free vs Pro adapters.
