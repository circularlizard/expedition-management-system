# Technical Specification: Split Signups Architecture

This document describes the technical implementation plan for dividing the signups system into two distinct workflows: **Participant Place Registration** and **Expedition Entries**. 

All signup data remains fully isolated within its respective custom table. Processing a signup marks it as allocated or processed in its own table; it does not write or copy any data into the `ems_osm_explorers` table.

---

## 1. Database Schema Specifications

We will deprecate and drop the `ems_signups` table and introduce two dedicated tables. Both tables use `$wpdb->prefix`.

### 1.1 `ems_participant_signups`
Stores DofE Participation Place registrations (Form 6).

| Column Name | Data Type | Key / Attribute | Description |
|---|---|---|---|
| `id` | `BIGINT(20) UNSIGNED` | `PRIMARY KEY AUTO_INCREMENT` | Unique record ID. |
| `scout_id` | `INT(11)` | `NOT NULL` | Synced explorer scout ID (link anchor). |
| `parent_user_id` | `BIGINT(20) UNSIGNED` | `NOT NULL` | WP User ID of parent submitting. |
| `unit_id` | `INT(11)` | `NULL` | Selected/detected ESU Unit ID. |
| `unit_name` | `VARCHAR(100)` | `NULL` | ESU Unit Name from form dropdown selection. |
| `explorer_first_name` | `VARCHAR(100)` | `NOT NULL` | Explorer's first name. |
| `explorer_last_name` | `VARCHAR(100)` | `NOT NULL` | Explorer's last name. |
| `explorer_email` | `VARCHAR(100)` | `NOT NULL` | Explorer email address from form. |
| `parent_email` | `VARCHAR(100)` | `NULL` | Parent email address. |
| `dofe_level` | `VARCHAR(20)` | `NOT NULL` | `bronze`, `silver`, or `gold`. |
| `dob` | `DATE` | `NULL` | Explorer's date of birth. |
| `dofe_registered` | `VARCHAR(30)` | `NOT NULL` | `y-scouts`, `y-other`, `y-forgotten`, `n`. |
| `dofe_number` | `VARCHAR(20)` | `NULL` | Existing eDofE ID number if registered. |
| `dofe_org` | `VARCHAR(100)` | `NULL` | Name of other organisation if transferring. |
| `bronze_completion` | `TEXT` | `NULL` | JSON list of completed Bronze sections (Volunteering, Skills, Physical, Expedition, None). |
| `silver_completion` | `TEXT` | `NULL` | JSON list of completed Silver sections. |
| `signup_status` | `VARCHAR(20)` | `DEFAULT 'received'` | `received` (active), `allocated` (processed), `archived`. |
| `payment_status` | `VARCHAR(20)` | `DEFAULT 'pending'` | `pending`, `paid`, `failed`. |
| `processed_by` | `BIGINT(20) UNSIGNED` | `NULL` | WP User ID of administrator who processed (allocated). |
| `processed_at` | `DATETIME` | `NULL` | Timestamp of processing action. |
| `form_submission_id` | `INT(11)` | `NOT NULL` | Fluent Forms submission entry ID. |
| `created_at` | `DATETIME` | `NOT NULL` | Timestamp of signup creation. |
| `updated_at` | `DATETIME` | `NOT NULL` | Timestamp of last modification. |

### 1.2 `ems_expedition_signups`
Stores specific expedition event signup entries (Form 7).

| Column Name | Data Type | Key / Attribute | Description |
|---|---|---|---|
| `id` | `BIGINT(20) UNSIGNED` | `PRIMARY KEY AUTO_INCREMENT` | Unique record ID. |
| `scout_id` | `INT(11)` | `NOT NULL` | Synced explorer scout ID (link anchor). |
| `parent_user_id` | `BIGINT(20) UNSIGNED` | `NOT NULL` | WP User ID of parent submitting. |
| `unit_id` | `INT(11)` | `NULL` | ESU Unit ID. |
| `unit_name` | `VARCHAR(100)` | `NULL` | ESU Unit Name from form dropdown selection. |
| `explorer_first_name` | `VARCHAR(100)` | `NOT NULL` | Explorer's first name. |
| `explorer_last_name` | `VARCHAR(100)` | `NOT NULL` | Explorer's last name. |
| `explorer_email` | `VARCHAR(100)` | `NOT NULL` | Explorer email address from form. |
| `parent_email` | `VARCHAR(100)` | `NULL` | Parent email address. |
| `dofe_level` | `VARCHAR(20)` | `NOT NULL` | `bronze`, `silver`, or `gold` (mapped from `input_radio_1`). |
| `dofe_number` | `VARCHAR(20)` | `NULL` | eDofE ID number. |
| `expedition_preferences` | `TEXT` | `NOT NULL` | JSON string of type (`exped_type`), practice/qualifier dates, teammate list. |
| `additional_support_needs` | `TEXT` | `NULL` | Travel/health details (mapped from `exped_asn`). |
| `first_aid_status` | `VARCHAR(20)` | `DEFAULT 'none'` | `none`, `online`, `first-response`, `full-first-aid`. |
| `first_aid_expiry` | `DATE` | `NULL` | Qualification expiry date. |
| `signup_status` | `VARCHAR(20)` | `DEFAULT 'pending'` | `pending` (active), `processed`, `archived`. |
| `payment_status` | `VARCHAR(20)` | `DEFAULT 'pending'` | `pending`, `paid`, `failed`. |
| `processed_by` | `BIGINT(20) UNSIGNED` | `NULL` | WP User ID of administrator who processed. |
| `processed_at` | `DATETIME` | `NULL` | Timestamp of processing action. |
| `form_submission_id` | `INT(11)` | `NOT NULL` | Fluent Forms submission entry ID. |
| `created_at` | `DATETIME` | `NOT NULL` | Timestamp of signup creation. |
| `updated_at` | `DATETIME` | `NOT NULL` | Timestamp of last modification. |

---

## 2. Settings & Options Management

Settings and form-to-database field mappings are configured in the `Settings_Page.php` file under a unified "Form Mappings" tab. 

### 2.1 Settings Option Keys
Instead of a single form ID and mapping array, the plugin maintains separate option keys:
- `ems_fluent_participant_form_id`: The Fluent Form ID for Participant Place Registrations (default: `6`).
- `ems_fluent_expedition_form_id`: The Fluent Form ID for Expedition Entries (default: `7`).
- `ems_participant_form_mappings`: Serialized array containing keys to Fluent Form fields for Participant Places:
  - `scout_id_field` (default: `signup_child`)
  - `first_name_field` (default: `signup_child_name`)
  - `last_name_field` (default: `signup_child_name`)
  - `dofe_level_field` (default: `signup_level`)
  - `dob_field` (default: `signup_dob`)
  - `dofe_registered_field` (default: `signup_dofe_registered`)
  - `dofe_number_field` (default: `signup_dofe_number`)
  - `dofe_org_field` (default: `signup_dofe_org`)
  - `bronze_completion_field` (default: `signup_bronze_completion`)
  - `silver_completion_field` (default: `signup_silver_completion`)
  - `esu_patrol_field` (default: `signup_unit`)
  - `explorer_email_field` (default: `signup_explorer_email`)
  - `parent_email_field` (default: `signup_parent_email`)
- `ems_expedition_form_mappings`: Serialized array containing keys to Fluent Form fields for Expedition Signups:
  - `scout_id_field` (default: `signup_child`)
  - `first_name_field` (default: `signup_child_name`)
  - `last_name_field` (default: `signup_child_name`)
  - `dofe_level_field` (default: `input_radio_1`)
  - `dofe_number_field` (default: `signup_dofe_number`)
  - `esu_patrol_field` (default: `signup_unit`)
  - `explorer_email_field` (default: `signup_explorer_email`)
  - `parent_email_field` (default: `signup_parent_email`)
  - `exped_type_field` (default: `exped_type`)
  - `practice_dates_field` (default: `exped_practice_dates`)
  - `qualifier_dates_field` (default: `exped_qualifier_dates`)
  - `team_names_field` (default: `exped_team_names`)
  - `asn_field` (default: `exped_asn`)
  - `first_aid_field` (default: `input_radio`)
  - `first_aid_expiry_field` (default: `datetime`)

### 2.2 Client-Side JavaScript Listener (Dropdown Population)
The child selector script listens on `signup_child` select changes and updates:
1. **Hidden Field `signup_scoutid`**: populates with `scout_id` (extracted from option value `scout_id|first_name|last_name`).
2. **Name Element `signup_child_name`**:
   - `input[name="signup_child_name[first_name]"]` = `first_name`
   - `input[name="signup_child_name[last_name]"]` = `last_name`
3. **Unit dropdown `signup_unit`** and **hidden `signup_unitid`**: populates using localized/ajax lookups.
4. **Leader email `signup_leader_email`**: populates with unit leader's email.

### 2.3 Submission Sync
* Webhook checks submission `form_id`.
* If it matches `ems_fluent_participant_form_id`, it maps fields via `ems_participant_form_mappings` and calls `Signup_Repository::create_participant_signup()`.
* If it matches `ems_fluent_expedition_form_id`, it maps fields via `ems_expedition_form_mappings` and calls `Signup_Repository::create_expedition_signup()`.

---

## 3. REST API & State Transitions

Endpoints are registered under `ems/v1/`:

### 3.1 Participant Place Endpoints
* `GET ems/v1/signups/participants`: returns participant signup records.
* `POST ems/v1/signups/participants/{id}/process`: sets status to `allocated`, records `processed_by`/`processed_at`, and saves the entered `dofe_number` if passed in payload body.
* `POST ems/v1/signups/participants/{id}/archive`: archives registration (sets status to `archived`).

### 3.2 Expedition Endpoints
* `GET ems/v1/signups/expeditions`: returns expedition signups.
* `POST ems/v1/signups/expeditions/{id}/process`: processes expedition entry (status -> `processed`).
* `POST ems/v1/signups/expeditions/{id}/archive`: archives expedition entry (status -> `archived`).

---

## 4. WP Admin Menu & React UI Dashboards

### 4.1 "Participant Signups" Menu Page
* **Slug**: `ems-participant-signups`
* **Bundle**: `assets/js/participant-signups-board.js`
* **Grid Columns**:
  1. **Submission date/time**: Created date formatted as `Y-m-d H:i`.
  2. **Explorer name**: First Name + Last Name.
  3. **Level**: Bronze, Silver, Gold.
  4. **ESU**: Value of ESU unit name from form (no link validation required).
  5. **Explorer Email Address**: From `explorer_email`.
  6. **Prior level status**: Icons representing prior completion section checkboxes:
     - Volunteering (`V`): white letter on **Red** circle.
     - Skills (`S`): white letter on **Blue** circle.
     - Physical (`P`): white letter on **Yellow** circle.
     - Expedition (`E`): white letter on **Green** circle.
     - If "None" is checked: display a **Red X** icon.
  7. **DofE number**: eDofE ID number (if provided, else blank).

### 4.2 Explorer Detail Panel (Inspector Pane)
* Clicking a participant row opens an inspector panel on the side.
* **Navigation Controls**:
  - Close button.
  - Back/Forward pagination buttons to cycle through all entries in the `'received'` (unprocessed) state.
* **Metadata Displays**:
  - Full details from the form: DOB, parent email, previous award details (using the same V, S, P, E color icons as the main table).
* **Key Actions**:
  - **Mark Allocated**: Mark that a participation place has been allocated (updates status to `'allocated'`).
  - **Enter eDofE number**: An input field to enter/edit the eDofE number.

### 4.3 "Expedition Signups" Menu Page
* **Slug**: `ems-expedition-signups`
* **Bundle**: `assets/js/expedition-signups-board.js`
* **React Columns**: Explorer Name, Expedition Level, Unit, Travel/Transport Pref, Teammate Pref, First Aid Status, Payment, Status, DofE Number, Actions (Process, Archive).

---

## 5. Testing Plan

### 5.1 Gherkin Scenarios
Feature files:
- `tests/features/milestone1-participant-signups.feature`
- `tests/features/milestone1-expedition-signups.feature`

### 5.2 PHPUnit Unit & Integration Tests
- [Table_InstallerTest.php](file:///Users/davidstrachan/Projects/expedition-management-system/tests/Unit/Core/Table_InstallerTest.php): verify table sql schemas.
- [Signup_RepositoryTest.php](file:///Users/davidstrachan/Projects/expedition-management-system/tests/Unit/Data/Signup_RepositoryTest.php): test CRUD and state updates for both tables.
- [Fluent_Forms_SyncTest.php](file:///Users/davidstrachan/Projects/expedition-management-system/tests/Unit/Integrations/Fluent_Forms_SyncTest.php): test submission handling for both form IDs.
- [Expedition_Admin_ControllerTest.php](file:///Users/davidstrachan/Projects/expedition-management-system/tests/Unit/Admin/Expedition_Admin_ControllerTest.php): mock requests to both sets of REST routes.

### 5.3 Vitest Frontend Tests
- `tests/js/ParticipantSignupsBoard.test.tsx`
- `tests/js/ExpeditionSignupsBoard.test.tsx`
