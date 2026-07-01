# Completed Signups and Authentication — Spec 1, 2 & 3

This document archives the completed technical specifications and sequencing tasks for **Spec 1: WordPress User Roles & OIDC Mapping**, **Spec 2: Consolidated Units & Mappings**, and **Spec 3: Signup Data Model & Fluent Forms Sync**.

---

## Completed Technical Specifications

### [x] Spec 1: WordPress User Roles & OIDC Mapping

#### [x] 1. Custom Roles Registration
EMS registers three custom WordPress roles programmatically on plugin activation (and checks for alignment if they already exist):

| Role Slug | Display Name | Default Capabilities |
|---|---|---|
| `ems_parent` | ESU Parent | `read: true`, `access_ems_parent_portal: true` |
| `ems_explorer` | ESU Explorer | `read: true`, `access_ems_explorer_portal: true` |
| `ems_leader` | ESU Leader | `read: true`, `edit_posts: true` (limited), `access_ems_leader_portal: true` |

* **Implementation Class**: `EMS\Core\Role_Manager` registered under the `init` hook and plugin activation/upgrade hooks.

#### [x] 2. Dynamic OIDC Role Assignment & Relationship Mapping

##### [x] A. Dynamic Hydration Flow (Post-Login & Registration)
1. **Identity Authentication**: The standard OIDC handshake handles initial Google identity login, verifying the email address and returning a temporary `access_token` (gated in the `login-with-google` plugin).
2. **Access Token Interception**: Registered an `http_response` filter to capture the access token from the HTTP request body during the OIDC handshake token exchange.
3. **OSM Hydration Call**: On OIDC login (`rtcamp.google_user_logged_in`) and registration (`rtcamp.google_user_created`), EMS triggers a secondary backend API call using the captured `access_token` to Online Scout Manager's `getDataPayload` endpoint (startup API - `ext/generic/startup/`). This returns the rich OSM context payload (as seen in `mockdata/getDataPayload.json`).
4. **Context Parsing**: The response is processed by `OSM_Parser` to extract the user's roles, section permissions, member access details, and child relationships before discarding the `access_token`.

##### [x] B. How Access Type is Determined
The user's `ems_access_type` is determined by scanning the nested `member_access` block under `$payload['data']['globals']['member_access']`:
* Inside `OSM_Parser::parse_access_type()`, the code iterates over all sections, and then through each member block under `members`.
* It extracts the `access_type` key from the member records (e.g., returns `'member'` for explorers, `'parent'` for parents, or `'local'`/`'leader'` configurations).
* The resolved string is saved in the WordPress user's meta under `ems_access_type`.

##### [x] C. How Parent-Explorer Relationships are Parsed & Stored
Since an individual child explorer may appear under multiple sections in the `member_access` structure (e.g. in `data.globals.member_access.{section_id}.members.{scout_id}` as shown in `mockdata/getDataPayload.json`), the parser deduplicates children and aggregates their sections:
1. **Deduplication Rules**:
   * Scans each section under the `member_access` object.
   * For each member under `members` (keyed by `scout_id`), it filters for rows where `access_type === 'parent'`.
   * Deduplicates by the unique explorer `scout_id`.
   * For duplicate explorer IDs across multiple sections, it merges all unique `section_id`s into a single `section_ids` array.
2. **Metadata Storage**: Saves the resolved child mapping to:
   * **`ems_children`**: A serialized array of deduplicated child objects.
     * Structure: `[ { scout_id: 30001, first_name: "Child", last_name: "One", section_ids: [99001, 99002] }, ... ]`
   * **`ems_scout_ids`**: A simple flat array of unique child IDs: `[30001, 30002]`.
3. **Portal Usage**: The Parent Portal SPA reads the parent's `ems_children` meta to render child selectors, and pre-populates the correct child `scout_id` as a hidden field in the Fluent Form when initiating a signup.

##### [x] D. Role Mapping & Persistence
After resolving the access type and relationships:
1. **Mapping Logic**:
   * If `ems_access_type === 'member'` $\rightarrow$ Add `ems_explorer` role to user; remove default `subscriber` or other non-EMS member roles.
   * If `ems_access_type === 'parent'` $\rightarrow$ Add `ems_parent` role to user; remove other subscriber roles.
   * If `ems_access_type === 'local'` or the user has a non-empty list of administered section IDs in `ems_section_ids` $\rightarrow$ Add `ems_leader` role.
2. **Persistence**: Call `$user->set_role( $target_role )` securely.
3. **Payload Validation**: If critical fields (such as `member_access` or `globals`) are missing from the Online Scout Manager payload, EMS must gracefully log a warning and abort the role assignment rather than disrupting the OIDC login process or throwing hard exceptions.

#### [x] Spec 2: Consolidated Units & Mappings (Database & UI)

EMS maintains a consolidated units lookup directory mapping synced Online Scout Manager patrols to local Explorer Scout Units (ESUs).

##### [x] 1. Database Table: `ems_units`
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

* **Data Source & General Unit Lookup**: The list of ESU units provides a general unit lookup mapping from synced OSM patrol names (`patrol_id` mapping from `ems_osm_patrols`). It stores the unit name, the manually populated `unit_id`, the `short_code` (which matches the patrol name), and the patrol reference.
* **Form Integration**: The ESU/Unit selection options in the Fluent Forms chained select fields (District $\rightarrow$ Unit) are statically hardcoded in the form configuration. The `ems_unit_leaders` mapping table is used solely in the backend to look up the unit leader name and email matching the submitted ESU/Unit value.
* **Admin UI**: A tab under Settings where admins can view ESU units and assign/edit a leader's name, email, and manual `unit_id` entries, with sticky header scrolling and responsive inputs.
* **Validation Rules**:
  * **Email Validation**: Validate that leader email addresses match standard format constraints on creation/update.

##### [x] 2. WP Admin Menu Restructuring
Modify the WordPress Admin menu structure according to the PRD Stage 4 layout to group EMS features cleanly:
* **ESM** (Parent Menu)
  * **Expeditions** (Calendar, Team set up, Expedition Detail, Explorers' Preferences)
  * **Explorers** (Sign Ups, Explorer view, Training view)
  * **Volunteers** (Sign Ups, Expedition Assignment)
  * **OSM Sync** (OSM data, Flexi record mapping, Sync manager, Account reconciliation)
  * **Settings** (OAuth settings, Unit Leader mappings)

##### [x] 3. Custom Post Type Menu Hiding
Update custom post type registrations (`season`, `expedition`, `team`) in `CPT_Registry` to set `'show_in_menu' => false` to prevent duplicate menu entries in the WordPress admin sidebar, routing their management strictly through the custom ESM submenus.

---

## Completed Sequencing Recommendations & Phases

### [x] Phase 1 — WP User Roles & OIDC Mapping
1. **Behavioral Design (TDD)**: Created Gherkin scenarios in `tests/features/auth-oidc-mapping.feature` defining OIDC role resolution (`ems_parent` deduplication, `ems_leader` section matching) and validation failure conditions.
2. **Implementation**:
   * Registered custom roles (`ems_parent`, `ems_explorer`, `ems_leader`) on plugin activation via `EMS\Core\Role_Manager`.
   * Extended `OIDC_Login_Handler` to assign the target role on successful login and registration based on `ems_access_type`.
3. **Tests**:
   * Implemented `tests/features/auth-oidc-mapping.feature` scenarios using PHPUnit/Brain Monkey stubs to assert roles are correctly assigned on login hooks, metadata is mapped correctly, capabilities are set, and OIDC payloads with missing critical fields log a warning without interrupting the OIDC login process.

### [x] Phase 2 — Unit Leader Directory & Admin Menus
1. **Behavioral Design (TDD)**: Defined repository contract expectations for unit leader CRUD operations, and defined admin menu structure registration assertions.
2. **Implementation**:
   * Executed migration to create the `ems_unit_leaders` table.
   * Provided CRUD repository methods and simple REST endpoints for managing mapping entries.
   * Updated custom post type registrations (`season`, `expedition`, `team`) in `CPT_Registry` to set `'show_in_menu' => false`.
   * Restructured the WP admin menus to follow the `ESM` parent and nested submenu layout.
3. **Tests**:
   * Wrote database unit tests in `tests/Unit/Data/Unit_Leader_RepositoryTest.php` verifying table schema, unique keys on `unit_name`, and CRUD helper methods.
   * Verified email format validation and uniqueness check for `unit_name` on save.
   * Implemented PHPUnit tests in `tests/Unit/Core/CPT_RegistryTest.php` asserting that `register_post_type` calls for `season`, `expedition`, and `team` receive `'show_in_menu' => false`.
   * Added tests verifying the correct hierarchy of registered admin menus and submenus.

### [x] Phase 2.5 — Consolidated Units Directory & Settings UI
1. **Behavioral Design (TDD)**: Defined repository contract expectations for managing Consolidated Units, and defined Settings UI mapping render assertions.
2. **Implementation**:
   * Migrated and created the consolidated `ems_units` database table.
   * Provided repository methods for ESU patrol listings, manual mapping updates (`unit_id`, `short_code` defaults, leader details), and protected custom mappings from being overwritten by OSM sync.
   * Updated the Settings page tab to list ESU patrols grouped by OSM section, rendering inputs for manual Unit ID and shortcodes.
3. **Tests**:
   * Wrote database unit tests in `tests/Unit/Data/Unit_RepositoryTest.php` verifying the consolidated schema, uniqueness constraints, and protected columns during updates.
   * Added Settings Page test cases verifying ESU section-grouped rendering, sticky headers, and input widths.

---

### [x] Spec 3: Signup Data Model & Fluent Forms Sync

Parents submit a Fluent Form to sign up their child for a DofE level and expedition. EMS hooks this submission, parses it, and creates a normalised relational record.

#### 1. Database Table: `ems_signups`
```sql
CREATE TABLE IF NOT EXISTS {$prefix}ems_signups (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scout_id               BIGINT UNSIGNED          DEFAULT NULL,
    parent_user_id         BIGINT UNSIGNED NOT NULL,
    unit_id                BIGINT UNSIGNED          DEFAULT NULL, -- Resolved ESU/Unit ID from lookup
    explorer_first_name    VARCHAR(100)    NOT NULL DEFAULT '',
    explorer_last_name     VARCHAR(100)    NOT NULL DEFAULT '',
    dofe_level             VARCHAR(20)     NOT NULL, -- 'bronze' | 'silver' | 'gold'
    expedition_preferences TEXT                     DEFAULT NULL, -- JSON string (dates, transport type, etc.)
    first_aid_status       VARCHAR(30)     NOT NULL DEFAULT 'none',
    signup_status          VARCHAR(30)     NOT NULL DEFAULT 'pending', -- 'pending' | 'processed'
    payment_status         VARCHAR(30)     NOT NULL DEFAULT 'pending', -- 'pending' | 'paid'
    form_submission_id     BIGINT UNSIGNED NOT NULL,
    created_at             DATETIME        NOT NULL,
    updated_at             DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_scout_id (scout_id),
    KEY idx_parent_user_id (parent_user_id),
    KEY idx_unit_id (unit_id)
) {$charset};
```

#### 2. Form Mapping Indirection Layer & Admin UI
To decouple database ingestion from form changes and support multiple Fluent Forms:
* **Storage Option (`ems_form_mappings`)**: A serialised configuration matching form IDs to target database fields:
  ```json
  {
    "form_id": {
      "scout_id_field": "signup_child",
      "first_name_field": "signup_first_name",
      "last_name_field": "signup_last_name",
      "dofe_level_field": "signup_level",
      "esu_patrol_field": "signup_unit",
      "first_aid_field": "input_radio",
      "pref_fields": [
        "exped_practice_dates",
        "exped_qualifier_dates",
        "exped_type",
        "exped_team_names",
        "exped_asn"
      ]
    }
  }
  ```
* **Admin Mapping UI**: A tab under *EMS Settings* allowing the admin to select a form ID and map its raw input field names to the required EMS database fields.

#### 3. Fluent Forms Sync Integration — Hooks Implemented

| Hook | Purpose |
|---|---|
| `fluentform/rendering_field_data_select` | Populates `signup_child` dropdown from `ems_children` user meta |
| `fluentform/validate_input_item_select` | Bypasses FF strict value-matching for dynamic choices |
| `fluentform/validation_errors` | Enforces parent ownership of `scout_id` and valid `dofe_level` |
| `fluentform/submission_inserted` | Extracts fields via `ems_form_mappings`, resolves `unit_id`, calls `create_signup()` |
| `fluentform/after_payment_status_change` | Maps `paid`/`succeeded` → `'paid'`; all else → `'pending'`; idempotency guard |
| `fluentform/before_form_render` | Enqueues inline JS; syncs unit + email fields when child selector changes |
| `fluentform/rendering_field_data_input_email` ×3 | Pre-populates hidden email fields on form render |

#### 4. Email Notification Architecture
All email notifications are handled by **Fluent Forms' built-in notification system** — no `wp_mail` calls from EMS. EMS pre-populates three hidden email fields on render and updates them via JS when the parent changes the child selector:

| Field | Source | Notes |
|---|---|---|
| `signup_parent_email` | WP user account email | Always available |
| `signup_explorer_email` | `ems_osm_explorers.email` via `scout_id` | Left empty if child not yet synced — no API call made |
| `signup_leader_email` | `ems_units.leader_email` for resolved unit | Left empty if no unit mapping exists |

Admins configure FF notification rules to use `{signup_parent_email}`, `{signup_explorer_email}`, and `{signup_leader_email}` as recipient smart tags. The three hidden email fields must be added to the Fluent Form in the FF dashboard.

#### 5. Client-side Sync Script (`window.emsFormMappings`)
Inline JS rendered by `before_form_render`. Per child entry includes `unitCode`, `unitId`, `explorerEmail`, and `leaderEmail`. Uses `jQuery(el).data('choicesjs')` (the correct Fluent Forms Choices.js key) with a 100 ms polling loop (3 s max) to handle the `fluentform_init` timing race. Falls back to native `<select>` assignment if Choices.js is absent.

#### 6. Payment Status Mapping
* `paid` / `succeeded` → `'paid'`
* All other statuses → `'pending'`
* Idempotency guard: never downgrades a row already marked `paid`

---

### [x] Phase 3 — Fluent Forms Sync Engine & Unit Lookup Integration (Completed)
1. **Behavioral Design (TDD)**: Gherkin scenarios written in `tests/features/signup-fluentforms-sync.feature` covering child dropdown pre-population, valid form submission, parent ownership validation, and payment status updates (including idempotency scenarios).
2. **Implementation**:
   * Migrated `ems_signups` table via `EMS\Core\Table_Installer`.
   * Implemented `EMS\Integrations\Fluent_Forms_Sync` with all seven hooks listed above.
   * Implemented `EMS\Data\Signup_Repository` with `create_signup()`, `get_signup()`, `get_signup_by_submission_id()`, `update_payment_status_by_submission_id()`, and `get_all_signups()`.
   * Extended `resolve_unit_for_child()` to return `leader_email` alongside `short_code` and `unit_id`.
3. **Tests** (13 tests, all green):
   * `tests/Unit/Integrations/Fluent_Forms_SyncTest.php` — 13 tests: dropdown injection, validation, submission (confirms no `wp_mail`), parent/explorer/leader email population, and all payment-status paths.
   * `tests/Unit/Data/Signup_RepositoryTest.php` — create, get, update payment status.
4. **Bug Fixes Applied**:
   * **Choices.js sync**: `el.choicesInstance` is always `undefined` in Fluent Forms (instance stored under jQuery `.data('choicesjs')`). Fixed lookup and added polling retry.
   * **Payment status mapping**: `completed` was dead code (never sent by Fluent Forms). Corrected map, added idempotency guard, removed debug `file_put_contents` logger.
5. **Admin setup required** (one-time, in FF dashboard):
   * Add three hidden email fields (`signup_parent_email`, `signup_explorer_email`, `signup_leader_email`) to the Fluent Form.
   * Configure FF notification rules using those field values as recipient smart tags.
