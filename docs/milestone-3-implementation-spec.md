# Milestone 3: Compliance & Training Progress Monitoring — Implementation Spec

This document defines the technical specification, database hooks, and CLI seeder modifications required to implement **Milestone 3 (Compliance & Training Progress Monitoring)**.

---

## 1. Automated Tutor LMS Enrollment System

To ensure explorers are automatically registered in the required courses for their expeditions, we implement a real-time enrollment script with a catch-up trigger for OIDC logins.

### 1.1 Real-Time Assignment Hook
When an explorer is assigned to a team or event via the REST API:
1.  Check if the explorer profile has a valid, non-zero `wp_user_id`.
2.  If `wp_user_id` is zero (unlinked recruit), **skip** immediate enrollment.
3.  If `wp_user_id` is valid:
    *   Fetch the required course IDs from the event's Post Meta (`ems_course_requirements`).
    *   For each required course ID, verify if the user is already enrolled by querying the `tutor_enrolled` Custom Post Type posts where `post_author = wp_user_id` and `post_parent = course_id`.
    *   If no enrollment post exists, insert one to register the explorer:
        ```php
        wp_insert_post( [
            'post_type'   => 'tutor_enrolled',
            'post_title'  => 'Course Enrolled',
            'post_status' => 'completed',
            'post_author' => $wp_user_id,
            'post_parent' => $course_id,
        ] );
        ```

### 1.2 OIDC Login Catch-up Trigger
For new recruits or unlinked explorers who had no WordPress account at the time of assignment:
1.  Hook into the OIDC Oauth Login handler ([OIDC_Login_Handler](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php)) immediately after the WordPress User account is created and mapped to the explorer's `scout_id`.
2.  Query the `ems_team_members` table to find all teams the explorer's `scout_id` is currently assigned to.
3.  For each assigned team, resolve the parent event and fetch its course requirements.
4.  Execute the Tutor LMS enrollment insert for any missing courses immediately using their newly generated `wp_user_id`.

---

## 2. First Aid Warning Flags

*   **Validation Rule**: A team requires at least one member with a verified `first_aid_level` of either `'first_response'` or `'full_first_aid'`.
*   **Behavior**:
    *   The validation runs dynamically in the Expedition Board React SPA.
    *   If no member of a team roster meets this criteria, render a warning badge (e.g. `⚠️ No First Aider`) in the team card header.
    *   This is a visual notification to guide the administrator; it does not hard-block drag-and-drop allocations or event confirmations.

---

## 3. Scale-Testing Tutor LMS Seeder

To verify the training compliance report matrix under realistic loads, we enhance the existing seeder script:

*   **Script Location**: `bin/seed-tutor-lms.php`
*   **Execution**:
    ```bash
    wp eval-file wp-content/plugins/ems-plugin/bin/seed-tutor-lms.php
    ```
*   **Modifications for Scale**:
    1.  *Fetch Roster*: Query all synced explorer records in `ems_osm_explorers` that have an active `wp_user_id > 0`.
    2.  *Generate Course Registrations*: For each explorer, loop through the seeded Tutor LMS courses and randomly assign one of three training states:
        *   **Not Enrolled** (30% probability): No action taken.
        *   **In Progress** (20% probability): Insert a `tutor_enrolled` post for the course, but write no completion meta.
        *   **Completed** (50% probability): Insert a `tutor_enrolled` post and write the course completion flag to user metadata:
            ```php
            update_user_meta( $wp_user_id, '_tutor_completed_course_' . $course_id, time() - rand( 1, 30 ) * DAY_IN_SECONDS );
            ```
    3.  This generates a diverse matrix of training states, enabling developers to verify the batch-loading efficiency of [TutorLMS_Client](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php) and rendering stability in the admin grids.

---

## 4. Gherkin Behavioral Scenarios

Add Gherkin scenarios to `tests/features/milestone3-tutorlms-enrollments.feature`:

```gherkin
Feature: Milestone 3 - Tutor LMS Compliance & Automated Enrollments

  Scenario: Assigning linked explorer automatically triggers Tutor LMS enrollment
    Given a course exists with ID 201
    And an event exists with ID 50 and required course ID 201
    And a synced explorer exists with scout_id 401 and wp_user_id 99
    When the administrator assigns explorer 401 to event 50
    Then explorer 401 should be enrolled in course 201 in Tutor LMS

  Scenario: Assigning unlinked explorer defers enrollment until OIDC login
    Given a course exists with ID 201
    And an event exists with ID 50 and required course ID 201
    And a synced explorer exists with scout_id 402 and wp_user_id 0
    When the administrator assigns explorer 402 to event 50
    Then explorer 402 should not have any enrollments in Tutor LMS
    When explorer 402 completes OIDC login (generating wp_user_id 100)
    Then explorer 402 should be enrolled in course 201 in Tutor LMS
```
