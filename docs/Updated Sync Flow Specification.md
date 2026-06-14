# Specification: Updated OSM Sync & Team Building Flow

This document details the shift from a WordPress User-centric sync to an OSM Reference Data-centric sync flow.

## 1. Overview of the New Flow

The system now prioritizes fetching and storing reference data from Online Scout Manager (OSM) before any local user accounts are created or Flexi-record data is processed. This ensures that the Expedition Management System (EMS) has a complete view of the section's participants and events as they exist in OSM.

### 1.1 Step 1: Sync Reference Data from OSM
The "Sync from OSM" action now performs the following:
1.  **Fetch Members**: Retrieves the full list of members for all managed sections and stores them in `ems_osm_explorers`.
2.  **Fetch Events**: Retrieves all scheduled events for managed sections and stores them in `ems_osm_events`.
3.  **Fetch Attendance**: Retrieves the attendance/acceptance status for all members across these events and stores them in `ems_osm_event_attendance`.

### 1.2 Step 2: OSM Reference View
A new administrative view displays the raw OSM data:
- List of all explorers in the section.
- Overview of scheduled events.
- Matrix of explorers and their acceptance status for specific events.
- *Note: This view is independent of Flexi-records.*

### 1.3 Step 3: Load Flexi-Record Data
Once reference data is synced, the Flexi-record import can be triggered:
- **Team View Population**: The Flexi-record data (expedition codes, team codes) is used to populate the team builder.
- **Identity Matching**: Participants in Flexi-record rows are matched against the `ems_osm_explorers` table using the `scout_id`.
- **WP User Independence**: This view and the initial team assignments **do not require** a WordPress User record to exist. Relationship mapping is done via `scout_id`.

## 2. Data Model Changes

To support this flow, the following database tables are introduced.

### 2.1 `ems_osm_explorers`
Stores reference information for all section members.
- `id`: BIGINT UNSIGNED (PK)
- `scout_id`: BIGINT UNSIGNED (OSM member_id)
- `wp_user_id`: BIGINT UNSIGNED (Nullable link to wp_users.id)
- `section_id`: BIGINT UNSIGNED
- `first_name`: VARCHAR(100)
- `last_name`: VARCHAR(100)
- `email`: VARCHAR(100)
- `parent_email`: VARCHAR(100)
- `patrol`: VARCHAR(100)
- `synced_at`: DATETIME

### 2.2 `ems_osm_events`
Stores reference information for OSM events.
- `id`: BIGINT UNSIGNED (PK)
- `event_id`: BIGINT UNSIGNED
- `section_id`: BIGINT UNSIGNED
- `name`: VARCHAR(255)
- `start_date`: DATETIME
- `end_date`: DATETIME
- `synced_at`: DATETIME

### 2.3 `ems_osm_event_attendance`
Tracks member status for specific events.
- `id`: BIGINT UNSIGNED (PK)
- `event_id`: BIGINT UNSIGNED
- `scout_id`: BIGINT UNSIGNED
- `status`: VARCHAR(50) (e.g., 'Accepted', 'Declined', 'Invited', 'Show in Parent Portal')
- `synced_at`: DATETIME

## 3. Application Amendments

### 3.1 `Table_Installer.php`
- Add the three new tables defined in §2.

### 3.2 `OSM_Section_Importer.php`
- **Current**: Creates/Updates `WP_User` records.
- **New**: Updates `ems_osm_explorers` table. User account creation is deferred until a login or explicit "provision" action is required.

### 3.3 New: `OSM_Reference_Sync.php`
- Orchestrates the fetching of members, events, and attendance.
- Uses `OSM_API_Client` to retrieve the data.
- Handles the persistence into the new `ems_osm_*` tables.

### 3.4 `Flexi_Record_Importer.php`
- **Current**: Matches `scout_id` to `WP_User` records.
- **New**: Matches `scout_id` to `ems_osm_explorers` records.
- The "Team View" (Expedition Board) will now display explorer names and details pulled from the reference table, not `WP_User` meta.

### 3.5 Admin Dashboard
- Implement a new "OSM Reference" tab/page.
- Update the "Expedition Board" to load data from the reference tables.

## 4. Alignment with PRD
- **Gravity Forms Reconciliation**: Still uses email matching, but can now match GF entries against `ems_osm_explorers` instead of only `WP_User` records.
- **Volunteer Management**: Still requires `WP_User` records (as volunteers must be logged in), but this is unchanged by the participant-focused sync update.
