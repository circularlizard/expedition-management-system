# EMS Signups and Authentication — Implementation Plan

This document defines the plan and technical specifications for implementing custom WordPress user roles, mapping these roles on OIDC login, setting up the Fluent Forms signup sync engine with a Unit Leader directory, and building the Admin Signups & Reconciliation Board.

---

## Completed Specs & Phases
Work completed on custom roles and OIDC mapping has been archived in [completed-signups-and-auth.md](file:///Users/davidstrachan/Projects/expedition-management-system/docs/completed-signups-and-auth.md).

## Technical Specifications

### [x] Spec 1: WordPress User Roles & OIDC Mapping (Completed)
Detailed specification and logic have been moved to [completed-signups-and-auth.md](file:///Users/davidstrachan/Projects/expedition-management-system/docs/completed-signups-and-auth.md).

### Spec 2: Consolidated Units & Mappings (Database & UI)

EMS maintains a consolidated units lookup directory mapping synced Online Scout Manager patrols to local Explorer Scout Units (ESUs).

#### 1. Database Table: `ems_units`
```sql
CREATE TABLE IF NOT EXISTS {$prefix}ems_units (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patrol_id         BIGINT          NOT NULL,              -- Synced from OSM (Patrol ID)
    section_id        BIGINT UNSIGNED NOT NULL,              -- Synced from OSM (Section ID)
    name              VARCHAR(100)    NOT NULL DEFAULT '',   -- Synced from OSM (Patrol name)
    active            TINYINT(1)      NOT NULL DEFAULT 1,    -- Synced from OSM
    synced_at         DATETIME        NOT NULL,              -- Synced from OSM
    
    -- Local Admin Mappings (Protected from OSM sync overwrite)
    unit_id           BIGINT UNSIGNED          DEFAULT NULL, -- Manually populated General Unit ID
    short_code        VARCHAR(100)    NOT NULL DEFAULT '',   -- Short ESU identification (defaults to patrol name)
    leader_first_name VARCHAR(100)    NOT NULL DEFAULT '',   -- Manually populated
    leader_last_name  VARCHAR(100)    NOT NULL DEFAULT '',   -- Manually populated
    leader_email      VARCHAR(100)    NOT NULL DEFAULT '',   -- Manually populated
    updated_at        DATETIME                 DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY idx_patrol_section (patrol_id, section_id),
    KEY idx_unit_id (unit_id)
) {$charset};
```

* **OSM Reference Sync**: Updates patrol reference data (`name`, `active`, `synced_at`) using the unique `idx_patrol_section` key, while protecting and preserving the manual settings (`unit_id`, `short_code`, and leader fields).
* **Settings Mapping Tab (UI)**: An administrative screen under *EMS Settings* listing synced ESU patrols grouped by section where the admin can input/edit the manual **Unit ID**, **Short Code** (defaults to patrol name), and **Leader Details**. Uses sticky headers and responsive input sizing.

---

### Spec 3: Signup Data Model & Fluent Forms Sync

Parents submit a Fluent Form to sign up their child for a DofE level and expedition. EMS hooks this submission, parses it, and creates a normalized relational record.

#### 1. Database Table: `ems_signups`
```sql
CREATE TABLE IF NOT EXISTS {$prefix}ems_signups (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scout_id               BIGINT UNSIGNED          DEFAULT NULL,
    parent_user_id         BIGINT UNSIGNED NOT NULL,
    unit_id                BIGINT UNSIGNED          DEFAULT NULL, -- Resolved ESU/Unit ID from lookup
    dofe_level             VARCHAR(20)     NOT NULL, -- 'bronze' | 'silver' | 'gold'
    expedition_preferences TEXT                     DEFAULT NULL, -- JSON string (dates, transport type, etc.)
    first_aid_status       VARCHAR(30)     NOT NULL DEFAULT 'none',
    signup_status          VARCHAR(30)     NOT NULL DEFAULT 'pending', -- 'pending' | 'processed'
    payment_status         VARCHAR(30)     NOT NULL DEFAULT 'pending', -- 'pending' | 'paid' | 'exempt'
    form_submission_id     BIGINT UNSIGNED NOT NULL,
    created_at             DATETIME        NOT NULL,
    updated_at             DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_scout_id (scout_id),
    KEY idx_parent_user_id (parent_user_id),
    KEY idx_unit_id (unit_id)
) {$charset};
```

#### 2. Fluent Forms Sync Integration Flow
1. **Hooks**: 
   * **Signup Creation**: Register a callback on `fluentform/submission_inserted` (fired when a form is submitted). Creates the signup row in `ems_signups` with initial metadata and sets the transaction status.
   * **Payment Processing**: Register callbacks on Fluent Forms payment status change events (e.g. `fluentform/payment_status_updated`). On receipt of payment webhook, dynamically update the `payment_status` column in `ems_signups` (e.g. to `'paid'`).
2. **Form Verification**: Retrieve the form ID from the entry and verify it matches the configuration WP option `ems_fluent_form_id`.
3. **Pre-population & Parsing Payload**:
   * **Pre-population**: When loading the form, EMS retrieves the child's `section_ids` array from the parent's `ems_children` metadata. EMS queries the `ems_units` table for rows matching those `section_ids` where `active = 1`.
     - **0 Matches**: The ESU/Unit selector is left blank, prompting the form filler to select the unit manually.
     - **1 or More Matches**: The first matched patrol name (`short_code` / patrol name) is used to pre-populate and set the dropdown unit selector in the form.
   * **Parse**: Extract `scout_id`, parent `user_id` (from `get_current_user_id()`), DofE level, expedition date/transport preferences, first aid status, and initial payment status.
4. **Leader & Unit Lookup**:
   * **Explorer Unit Identification**: The Fluent Form registration includes a ESU/Unit dropdown field using ESU patrol names as values. If the parent/admin manually selects or overrides the selection, that value takes precedence. Otherwise, the pre-populated unit resolved from the child's `section_ids` is used.
   * **Unit Resolution**: Map the selected patrol name/short_code to the corresponding `ems_units.unit_id` and leader email.
5. **Write Signup**: Insert/update the row in the `ems_signups` table (storing the resolved `unit_id`).
6. **Payload Validation Rules**:
   * **Authentication**: Check that the parent submitting the form is authenticated and matches the logged-in user.
   * **DofE Level Validation**: Validate that the submitted `dofe_level` is strictly one of `'bronze'`, `'silver'`, or `'gold'`.
   * **Scout ID Validation**: If a `scout_id` is submitted, validate that it exists in the `ems_osm_explorers` table.
7. **Dummy Notifications**: Send transaction notifications using standard `wp_mail()`:
   * **Parent Email**: Confirming signup and payment status.
   * **Explorer Email**: Confirming preferences received.
   * **Leader Email**: Notifies the unit leader that an explorer signed up, requesting them to check OSM and confirm the unit profile share.

---

### Spec 4: Admin Signups Board & Reconciliation

Admin dashboards require a unified screen to review Fluent Forms signup data, verify them against OSM reference data, and link them together.

#### 1. Identity Linkage Model
To connect submissions generated by Fluent Forms with existing OSM Explorer records:

```mermaid
graph TD
    FF[Fluent Forms Signup Submission] -->|Parsed & Sync'd| SU[(ems_signups Table)]
    OSM[OSM API Sync] -->|Upserted Reference| EXP[(ems_osm_explorers Table)]
    WP[OIDC Login] -->|OIDC User Account| WPUSR[(wp_users Table)]

    SU -->|1. scout_id Hidden Match| EXP
    SU -.->|2. Fuzzy Matching: Email / Name| EXP
    EXP -->|wp_user_id| WPUSR
```

Reconciliation runs through these ordered priority paths:
1. **Direct Match (Hidden Scout ID)**: If the form is submitted via the Parent Portal, the form embeds the child's `scout_id` as a hidden field. This connects the signup row directly to `ems_osm_explorers.scout_id` with 100% confidence.
2. **Fuzzy Match (Email / Name)**: If `scout_id` is null or zero (e.g., a new recruit signup not yet synced in OSM):
   * Search `ems_osm_explorers` for a row matching the explorer's email address (case-insensitive).
   * If email is missing/blank, search by `first_name` and `last_name` combination.
   * If a match is found, show it as a **"Proposed Link"** on the admin dashboard.
3. **Unlinked (New Recruit)**: If no match is found, flag the signup as "New / Unlinked". The admin cannot process this signup until the explorer is created/synced in OSM.

#### 2. REST API Endpoints
* `GET ems/v1/signups`: Lists all signup records with resolved explorer names, emails, and linked status.
* `POST ems/v1/signups/{id}/reconcile`: Manually links a signup to a specific `scout_id`.
  * **Linkage Rule**: Confirming a manual link updates `ems_signups.scout_id` to link the signup record, but **does not** dynamically rewrite the parent user's WordPress metadata. We rely strictly on the next parent OIDC login hydration call to pull parent-child links from OSM globals (Option B).
  * **Validation Rules**:
    * Verify that both the signup record (`id`) and the target `scout_id` exist.
    * Prevent linking/reconciliation actions if the signup record's status is already marked as `'processed'`.
* `POST ems/v1/signups/{id}/process`: Marks a signup as `processed` (completed back-office allocation).

#### 3. Administrative Interface (React)
A new "Sign Ups" tab is registered in the Explorer View SPA in the WP Admin Dashboard:
* Displays a table of all sign-ups from `ems_signups`.
* For linked signups: Show explorer name, level, first aid, ESU unit, and a tick mark.
* **ESU/Unit Field**: Displays the mapped unit (ESU name and Short Code) based on the signup's `unit_id`. Renders an editable select/dropdown letting the administrator manually override or assign the correct unit at any point before processing.
* **Unit Mapping Exceptions**:
  * **0 Mapped Units**: Displays a warning badge indicating "Unassigned Unit".
  * **Multiple Mapped Units**: Displays an option listing the proposed units, prompting the administrator to click and select/confirm the correct one.
* For proposed/unlinked signups: Renders a warning badge and a "Link Explorer" button opening a search dialog to reconcile manually.
* Filter controls for: Level (Bronze/Silver/Gold), Status (Pending/Processed), ESU/Unit, and Matching Status (Linked/Proposed/Unlinked).
* Batch Action: "Mark Selected as Processed".

---

## Sequencing Recommendation & Phases

```mermaid
gantt
    title EMS Signups & Auth Implementation Phases
    dateFormat  YYYY-MM-DD
    section Phase 1
    Auth Roles & OIDC Mapping       :active, p1, 2026-07-01, 3d
    section Phase 2
    Unit Leader Directory Mapping    : p2, after p1, 3d
    section Phase 2.5
    Consolidated Units & UI Mappings : p25, after p2, 3d
    section Phase 3
    Fluent Forms Sync Engine & CPTs  : p3, after p25, 5d
    section Phase 4
    Admin Signups & Reconciliation Board: p4, after p3, 5d
```

### [x] Phase 1 — WP User Roles & OIDC Mapping (Completed)
Tasks and scenarios implemented. See [completed-signups-and-auth.md](file:///Users/davidstrachan/Projects/expedition-management-system/docs/completed-signups-and-auth.md) for details.

### [x] Phase 2 — Unit Leader Directory & Admin Menus (Completed)
Tasks and scenarios implemented. See [completed-signups-and-auth.md](file:///Users/davidstrachan/Projects/expedition-management-system/docs/completed-signups-and-auth.md) for details.

### Phase 2.5 — Consolidated Units Directory & Settings UI
1. **Behavioral Design (TDD)**: Define repository contract expectations for managing Consolidated Units, and define Settings UI mapping render assertions.
2. **Implementation**:
   * Migrate and create the consolidated `ems_units` database table.
   * Provide repository methods for ESU patrol listings, manual mapping updates (`unit_id`, `short_code` defaults, leader details), and protect custom mappings from being overwritten by OSM sync.
   * Update the Settings page tab to list ESU patrols grouped by OSM section, rendering inputs for manual Unit ID and shortcodes.
3. **Tests**:
   * Write database unit tests in `tests/Unit/Data/Unit_RepositoryTest.php` verifying the consolidated schema, uniqueness constraints, and protected columns during updates.
   * Add Settings Page test cases verifying ESU section-grouped rendering, sticky headers, and input widths.

### Phase 3 — Fluent Forms Sync Engine & Unit Lookup Integration
1. **Behavioral Design (TDD)**: Create Gherkin scenarios in `tests/features/signup-fluentforms-sync.feature` representing signup form submissions and unit lookup mapping logic.
2. **Implementation**:
   * Execute migration to create `ems_signups` table (containing `unit_id`).
   * Bind callback to `fluentform/submission_inserted` to extract signup info, validate user permission, validate the `dofe_level` parameter, and ensure `scout_id` is verified.
   * **Unit Lookup Integration**: Integrate the child ESU mapping logic: query the `ems_units` table for matches based on the child's `section_ids` array to pre-populate or resolve ESU `unit_id`, supporting parent manual override submissions.
   * Insert/update `ems_signups` with the resolved `unit_id`.
3. **Tests**:
   * **PHPUnit (Forms Sync)**: Implement `tests/features/signup-fluentforms-sync.feature` to test parent user matching validation, `dofe_level` range validations, existing `scout_id` checks, automated child section IDs lookup, manual overrides, repository storage, and `wp_mail` lookup notifications.

### Phase 4 — Admin Signups Board & Reconciliation UI
1. **Behavioral Design (TDD)**: Create Gherkin scenarios in `tests/features/admin-reconciliation.feature` covering REST API requests and manual linking constraints.
2. **Implementation**:
   * Implement REST endpoints for `/signups` listing (returning resolved unit details) and `/reconcile` / `/process` actions.
   * Create React Admin Component for "Sign Ups" tab, rendering the reconciliation workflow with dropdown overrides and unassigned/multi-unit warnings.
3. **Tests**:
   * **API Integration Tests**: Implement `tests/features/admin-reconciliation.feature` scenarios verifying `/reconcile` validates signup & `scout_id` existence, blocks reconciliation of already `'processed'` signups, and validates fuzzy matching query logic.
   * **UI Vitest Tests**: Write tests in `tests/js/AdminSignupsBoard.test.tsx` verifying component renders "Unlinked", "Proposed Link", "Unassigned Unit", and "Multiple Mapped Units" statuses, triggers manual search dialogs, and fires action API endpoints appropriately.
