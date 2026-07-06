# Milestone 3: Adult Volunteer Availability Mapping — Implementation Spec

This document defines the technical specification, database schema changes, REST API endpoints, and user interfaces required to implement **Milestone 3 (Adult Volunteer Availability Mapping)**.

---

## 1. Database Schema Specifications

To accommodate both authenticated (OSM/WordPress linked) and guest volunteers, we introduce a new `ems_volunteers` details table and update the existing `ems_volunteer_availability` table.

```mermaid
erDiagram
    ems_volunteers ||--o{ ems_volunteer_availability : "has"
    wp_users ||--o| ems_volunteers : "links to"
    ems_volunteers {
        int id PK
        int osm_user_id NULL
        int user_id NULL
        string first_name
        string last_name
        string email
        string phone
        string dbs_number
        json qualifications
        json preferred_roles
        datetime created_at
        datetime updated_at
    }
    ems_volunteer_availability {
        int id PK
        int volunteer_id FK
        int user_id NULL
        int expedition_post_id
        date date
        boolean overnight
        int confirmed "0=pending, 1=confirmed, -1=conflicted/declined"
        int confirmed_by
        datetime updated_at
    }
```

### 1.1 New Table: `ems_volunteers`
Stores volunteer profiles, qualifications, preferred roles, and security details.
*   `id` (bigint, unsigned, primary key, auto-increment)
*   `osm_user_id` (bigint, unsigned, nullable): Linked Online Scout Manager member/user ID.
*   `user_id` (bigint, unsigned, nullable): Linked WordPress User ID (`wp_users.ID`), populated if they complete OIDC login.
*   `first_name` (varchar(255))
*   `last_name` (varchar(255))
*   `email` (varchar(255), indexed)
*   `phone` (varchar(50), nullable)
*   `dbs_number` (varchar(100), nullable): Safeguarding validation.
*   `qualifications` (longtext/json, nullable): e.g. `{ "first_aid": "full_first_aid", "permits": ["hillwalking_t1"] }`
*   `preferred_roles` (longtext/json, nullable): e.g. `["supervisor", "assessor", "basecamp"]`
*   `created_at`, `updated_at` (datetime)

### 1.2 Updated Table: `ems_volunteer_availability`
Adjusted to support linkage to `ems_volunteers.id` rather than strictly relying on `wp_users.id` for guests.
*   `volunteer_id` (bigint, unsigned, foreign key referencing `ems_volunteers.id`)
*   `confirmed` (tinyint, default 0): `0` = Pending, `1` = Confirmed, `-1` = Conflicted / Declined (due to double-booking prevention).

---

## 2. Volunteer Signup Wizard (External-Facing UI)

Implemented as a shortcode `[ems-volunteer-signup]` rendering a React SPA. The UI must adapt to the host theme styling while maintaining EMS usability patterns.

### 2.1 Enrollment / Auth Options
On loading, the form offers two choices:
1.  **Sign In via OSM**: Triggers OAuth2 authentication flow. Upon successful authentication:
    *   Pre-populates contact details (First Name, Last Name, Email).
    *   Retrieves previous availability submissions if they exist.
2.  **Sign Up as Guest**: Proceed to wizard without auth. The wizard relies on email validation and triggers a confirmation email on completion.

### 2.2 Wizard Steps (Progressive Disclosure)
*   **Step 1: Macro-Selection**:
    *   Presents upcoming event cards grouped by date overlap using the **Date Bundle Pattern**.
    *   Displays compound cards for concurrent events with choices: *"I'm available for EITHER event"* (pre-selected) or *"I only want to opt-into specific events"*.
*   **Step 2: Availability Builder**:
    *   Uses accordions for each selected date block.
    *   Shows the **Gatekeeper Question**: *"Are you available for the entire duration?"* (Yes auto-selects all shifts; No expands the builder grid).
    *   **Shift Grid**: Daytime/Overnight checkbox blocks for each day. Overnights are anchored to the starting day (Sunday night omitted for weekend-ending events).
    *   **Cross-Event Copy**: Let users duplicate their configured availability matrices across similar multi-day blocks with one click.
*   **Step 3: Details & Qualifications**:
    *   Form fields for contact details (guest only), DBS number, First Aid level selection, and preferred roles checklist.
*   **Step 4: Review & Submit**:
    *   Summarizes the confirmed shifts, preferences, and details. Commit details to local storage on change.

---

## 3. Conflict Prevention & Allocation Logic

To prevent double-booking volunteers across concurrent events, the backend controller enforces the following allocation rules:

1.  **Overlapping Pending Insert**:
    *   When a volunteer selects "I'm available for EITHER event" for overlapping events `A` and `B` on the same date `D`, pending rows (`confirmed = 0`) are created in `ems_volunteer_availability` for both `A` and `B`.
2.  **Double-Booking Lock (Confirmation Rule)**:
    *   When an administrator sets `confirmed = 1` for volunteer `V` on event `A` for date `D`:
        *   The backend automatically queries all other pending availability rows for volunteer `V` on date `D` for different events (e.g. event `B`).
        *   These overlapping rows are updated to `confirmed = -1` (Conflicted) to lock the volunteer to event `A` and prevent double-booking.
3.  **Release Rule (Unassignment)**:
    *   If volunteer `V` is unassigned from event `A` (set `confirmed = 0` or row deleted), any corresponding conflicted overlapping rows for other events on that date are restored back to `confirmed = 0` (Pending) so they can be selected again.

---

## 4. Admin Interfaces (WordPress Backend)

All admin UI components must adhere to the style guide in [style-refactor-spec.md](style-refactor-spec.md) and use the native WordPress/EMS palette.

### 4.1 Volunteer Signups Page & Matrix Grid
Add a submenu page **Volunteer Signups** (`EMS -> Volunteer Signups`) rendering a comprehensive grid.

*   **Matrix Layout**:
    *   Rows represent volunteers (`ems_volunteers`), columns represent upcoming event dates.
    *   Grid cells display status badges:
        *   🟢 Confirmed assignment (Event Code, e.g., `H-SP1`)
        *   🟡 Pending availability (e.g., `Day`, `Night`, `Both`)
        *   🔴 Conflicted / unavailable
*   **Inspector Panel (RHS Drawer)**:
    *   Selecting a volunteer row slides open an Inspector pane showing:
        *   **Contact Info**: Name, Email, Phone, OSM Link Status.
        *   **Safeguarding & Qualifications**: DBS check number, First Aid status, permits.
        *   **Preferred Roles**: Checklist of opted-in roles.
        *   **Availability Detail Grid**: An accordion list displaying the exact shifts selected for each event.

### 4.2 Expedition Detail Page "Volunteers" Tab
Add a **Volunteers** tab inside the `EventDetailPage` React component.

*   **Staffing Deficit Header**:
    *   Renders staffing indicators based on validation rules: e.g., *"Requires 2 Supervisors, 1 Assessor"* (Badge states: 🔴 Deficit, 🟡 Pending, 🟢 Confirmed).
*   ** Roster Panels**:
    *   **Assigned Volunteers**: Table listing all volunteers confirmed (`confirmed = 1`) for this event with role details.
    *   **Available / Pending Volunteers**: Table listing volunteers who indicated availability for these dates (either specifically for this event, or via the "either" assignment strategy).
*   **Roster Actions**:
    *   *Assign / Confirm*: Single-click button to confirm a pending volunteer, automatically triggering the conflict prevention checks.
    *   *Remove*: Option to unassign a volunteer, releasing their schedule lock.

---

## 5. REST API Specifications

All endpoints require permission callback validation matching WP Admin capacity: `current_user_can('manage_options')`.

### 5.1 `GET ems/v1/volunteers`
Returns a list of all volunteers with their aggregated schedules and details.

### 5.2 `POST ems/v1/volunteers/signup`
Saves or updates a volunteer's profile and availability matrix.
*   **Access**: Public (gated for guests or OSM oauth tokens).

### 5.3 `POST ems/v1/volunteers/assign`
Confirms a volunteer assignment.
*   **Payload**: `{ volunteer_id: 10, expedition_post_id: 42, dates: ["2026-08-14", "2026-08-15"], role: "supervisor" }`
*   **Response**: Triggers double-booking checks; returns updated lists.

---

## 6. Gherkin Behavioral Scenarios

```gherkin
Feature: Milestone 3 - Adult Volunteer Availability Mapping

  Scenario: Volunteer submits guest signup wizard
    When a guest volunteer submits availability for event 10 (dates: 2026-08-14 to 2026-08-16)
    Then a record should be created in the ems_volunteers table
    And 3 rows should be created in ems_volunteer_availability with confirmed = 0

  Scenario: Overlapping events choose either option inserts pending rows
    Given event A (Highland Trek) and event B (Mourne Mountains) run concurrently on 2026-08-14
    When a volunteer selects "either" event during signup
    Then pending availability records (confirmed = 0) should be created for both event A and event B

  Scenario: Confirming overlapping event locks out alternative assignment
    Given a volunteer has pending availability for event A and event B on 2026-08-14
    When the administrator confirms the volunteer on event A
    Then the availability record for event A should be confirmed = 1
    And the availability record for event B should automatically set confirmed = -1 (Conflicted)

  Scenario: Unassigning overlapping event releases schedule lock
    Given a volunteer has confirmed = 1 on event A and confirmed = -1 on event B for 2026-08-14
    When the administrator unassigns the volunteer from event A
    Then the availability record for event B should reset to confirmed = 0 (Pending)
```
