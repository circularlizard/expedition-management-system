# Data Schema and API Specification: EMS

This document defines the metadata, relationships, and API endpoints for the Expedition Management System.

## 1. Custom Post Types (CPTs)

### 1.0 `season`
Top-level container for a flight of events (training, practice, qualifying) within an academic year.
- **Title**: e.g., "2026–27 Season"
- **Meta Fields**:
    - `ems_season_year`: string (e.g. `2026-27`)
    - `ems_season_status`: string (`active` | `archived`)

### 1.1 `expedition` *(admin-facing label: "Event")*
Used to manage individual training events, practice expeditions, and qualifying expeditions within a season. Admin UI refers to these as **events** to match the user spec; the CPT slug remains `expedition` for backwards compatibility.
- **Title**: e.g., "Hillwalking Silver Practice 1"
- **Post Parent**: ID of the associated `season` CPT record.
- **Meta Fields**:
    - `ems_event_code`: string — manually assigned short code, unique within a season (e.g. `H-SP1` for Hillwalking Silver Practice 1). Replaces the former `ems_expedition_code`; used to auto-generate team codes (`H-SP1-1`, `H-SP1-2`, …).
    - `ems_type`: string (`training` | `practice` | `qualifying`)
    - `ems_transport`: string (`hillwalking` | `biking` | `paddling`)
    - `ems_level`: string (`bronze` | `silver` | `gold`)
    - `ems_lic_name`: string (Leader in Charge display name — may be blank/TBC)
    - `ems_lic_email`: string (Leader in Charge email — may be blank/TBC)
    - `ems_lic_phone`: string (Leader in Charge phone — may be blank/TBC)
    - `ems_lic_id`: integer (WP User ID of the Leader in Charge, if they have a WP account — optional)
    - `ems_start_location`: string (free text — may be blank/TBC)
    - `ems_end_location`: string (free text — may be blank/TBC)
    - `ems_start_date`: string (ISO 8601 date)
    - `ems_start_time`: string (HH:MM, optional)
    - `ems_end_date`: string (ISO 8601 date)
    - `ems_end_time`: string (HH:MM, optional)
    - `ems_osm_event_id`: integer (Link to OSM event record synced in `ems_osm_events` — may be blank/TBC)
    - `ems_route_info`: string (rich text / HTML — route planning notes, map links, etc.)
    - `ems_route_deadline`: string (ISO 8601 date)
    - `ems_status`: string (`planning` | `open` | `confirmed` | `completed`)

> **Deprecated field**: `ems_expedition_code` is replaced by `ems_event_code`. Any migration needed when this field is first introduced.

### 1.2 `team`
Used to group participants within an event.
- **Title**: e.g., "H-SP1-1"
- **Post Parent**: ID of the associated `expedition` CPT record (the event).
- **Meta Fields**:
    - `ems_team_code`: string (e.g., `H-SP1-1` — auto-generated from the parent event's `ems_event_code` with a sequential suffix; numbers must be contiguous within an event)
    - `ems_team_number`: integer (the numeric suffix; enforced sequential within the event)
    - `ems_route_status`: string (`pending` | `feedback_required` | `approved`) — current state shortcut field
    - `ems_route_feedback`: string (Most recent LiC feedback — current state shortcut field)
    - `ems_gpx_file_id`: integer (WP Media ID of current approved/latest GPX)
    - `ems_route_card_file_id`: integer (WP Media ID of current approved/latest route card)
- **Relationships** (stored in custom tables, not Post Meta):
    - Participants: see `ems_team_members` table (§4)
    - Submission history: see `ems_route_submissions` table (§4)
- **Validation**: Team size of 4–7 is the official range. Sizes outside this range generate an admin warning but are not hard-blocked. Teams with zero members are deleted automatically.

## 2. User Metadata
Extending standard WP User records.
- `ems_osm_user_id`: integer (OSM `user_id` - The OIDC login account identifier)
- `ems_scout_id`: integer (OSM `member_id` - The primary identifier for an Explorer record. Used to link parents to children before a child WP account exists)
- `ems_osm_access_type`: string (`parent` | `member` - The primary role identifier retrieved from OSM)
- `ems_first_aid_status`: string (`none` | `first_response` | `full`)
- `ems_teammate_preferences`: string (Text from Gravity Forms)
- `ems_available_date`: string (ISO 8601). **Note**: Stored as multiple individual meta rows per user, NOT serialized, to allow fast `WP_User_Query` lookups for the calendar view.
- `ems_parent_children`: array (List of Explorer **Scout IDs** - Used for multi-child aggregation and linking)
- `ems_unit`: string (Pulled from OSM 'patrol' field)

## 3. REST API Surface
All endpoints prefixed with `/wp-json/ems/v1/`.

### 3.1 Public/Participant Endpoints (Explorer/Parent)
- `GET /my-expeditions`: Returns current expeditions and team status for the logged-in Explorer or their children. (Available to both roles).
- `POST /submit-route`: Uploads GPX/Route card for a team. (Available to both roles).
- `GET /download-route/[id]`: Securely serves route files. (Available to both roles).
- `POST /signup-level`: Initiates signup for a new DofE level. (**Parent only**; must validate that the Explorer has an email in OSM).
- `GET /training-access`: Validates course completion status. (**Explorer only**).

### 3.2 Volunteer Endpoints
- `GET /available-expeditions`: List of expeditions open for volunteer signup.
- `POST /volunteer-signup`: Submit availability for an expedition or specific dates.

### 3.3 Administrative Endpoints (Admin/LiC)
- POST `/sync-osm`: Triggers a manual sync for a section or event.
- GET `/expedition-board`: Returns full dataset for the Season Dashboard / Team Builder UI.
- POST `/seasons`: Create a new season.
- POST `/events`: Create a new event within a season.
- PATCH `/events/{id}`: Update event details (LiC, locations, dates, OSM link, route info).
- DELETE `/events/{id}`: Delete an event (only if it has no teams).
- POST `/events/{id}/teams`: Create a new team in an event (auto-generates next sequential team code).
- DELETE `/teams/{id}`: Delete a team (only if it has no members, or as part of last-member-removed cascade).
- POST `/teams/{id}/members`: Add an explorer to a team.
- DELETE `/teams/{id}/members/{scout_id}`: Remove an explorer from a team.
- PATCH `/teams/{id}/move`: Move a team to a different event of the same type (re-codes the team).
- POST `/teams/{id}/duplicate`: Duplicate a team's membership to another event (new team created in target event).
- PATCH `/explorers/{scout_id}/move-team`: Move an explorer between teams (within or across events of same type).
- POST `/route-feedback`: LiC submits approval or feedback for a team's route.


## 4. Custom Database Tables
Three custom tables are created on plugin activation (via `dbDelta()`). These are a definitive part of the data model (see ADR 011), not optional.

### 4.1 `ems_team_members`
Links Explorers (WP Users) to Teams. Replaces the unqueryable serialized `ems_participants` Post Meta.
- `id`: int, auto-increment PK
- `team_post_id`: int (WP Post ID of the `team` CPT record)
- `user_id`: int (WP User ID of the Explorer)
- `added_by`: int (WP User ID of the admin who made the assignment)
- `added_at`: datetime

### 4.2 `ems_volunteer_availability`
Stores per-day volunteer availability for the seasonal calendar and expedition-specific views.
- `id`: int, auto-increment PK
- `user_id`: int (WP User ID of the Volunteer)
- `expedition_post_id`: int (WP Post ID of the `expedition` CPT record)
- `date`: date
- `overnight`: tinyint (1 = available for overnight)
- `confirmed`: tinyint (0 = pending, 1 = confirmed)
- `confirmed_by`: int (WP User ID of confirming Admin/LiC, nullable)

### 4.3 `ems_route_submissions`
Stores the full version history of route submissions with LiC feedback per version.
- `id`: int, auto-increment PK
- `team_post_id`: int (WP Post ID of the `team` CPT record)
- `version`: int (auto-incremented per team)
- `file_type`: string (`gpx` | `route_card`)
- `wp_media_id`: int (WP Attachment ID)
- `submitted_by`: int (WP User ID)
- `submitted_at`: datetime
- `feedback`: text (nullable)
- `status`: string (`pending` | `feedback_required` | `approved`)

### 4.4 `ems_osm_explorers`
Stores reference information for all section members synced from OSM.
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

### 4.5 `ems_osm_events`
Stores reference information for OSM events synced from OSM.
- `id`: BIGINT UNSIGNED (PK)
- `event_id`: BIGINT UNSIGNED
- `section_id`: BIGINT UNSIGNED
- `name`: VARCHAR(255)
- `start_date`: DATETIME
- `end_date`: DATETIME
- `synced_at`: DATETIME

### 4.6 `ems_osm_event_attendance`
Tracks member status for specific events synced from OSM.
- `id`: BIGINT UNSIGNED (PK)
- `event_id`: BIGINT UNSIGNED
- `scout_id`: BIGINT UNSIGNED
- `status`: VARCHAR(50) (e.g., 'Accepted', 'Declined', 'Invited', 'Show in Parent Portal')
- `synced_at`: DATETIME

## 5. WP Options: Reference & Configuration Data

All EMS configuration is stored in WP Options (via `get_option` / `update_option`). These are set during initial plugin setup via an admin settings screen and must be in place before any OSM push-back operation is attempted.

### 5.1 `ems_managed_sections`
Type: serialized array. Defines every OSM section the EMS manages. Each entry is keyed by OSM `sectionid`.

```php
[
    '99001' => [
        'name'           => 'Test District: Silver ESU',   // display label
        'type'           => 'explorers',                    // OSM section type string — required by updateScout POST
        'termid'         => '897113',                       // current OSM term ID — changes annually, must be updated each year
        'extraid'        => '50001',                        // OSM flexi-record ID for this section
        'column_map'     => [                               // maps EMS field names to opaque OSM f_N column IDs
            'practice_group'     => 'f_1',
            'practice_accepted'  => 'f_2',
            'qualifier_group'    => 'f_3',
            'qualifier_accepted' => 'f_4',
            'first_aid'          => 'f_5',
        ],
    ],
    // ... additional sections
]
```

> **Note on `termid`**: OSM terms are academic-year periods. The `termid` must be refreshed annually (before the new term's expeditions begin). A future admin notice should prompt renewal when the stored term expires.

### 5.2 `ems_gravity_form_id`
Type: integer. The ID of the Gravity Forms expedition signup form. Cannot be hardcoded as form IDs are environment-specific.

### 5.3 `ems_tutor_course_map`
Type: serialized array. Maps DofE level to Tutor LMS course ID for training access validation.

```php
[
    'bronze' => 12,
    'silver' => 34,
    'gold'   => 56,
]
```

### 5.4 `ems_osm_client_id`
Type: string. The OSM OAuth application client ID. Used by `OSM_Sync_Auth_Handler` to build the authorization URL and perform the token exchange. Also used by the `login-with-google` plugin for OIDC user login. Set via `Admin\Settings_Page`.

### 5.5 `ems_osm_client_secret` *(encrypted)*
Type: string. The OSM OAuth application client secret. Stored encrypted at rest (AES-256-CBC, key derived from `AUTH_KEY` / `SECURE_AUTH_KEY` WP constants). Never exposed in the admin UI after initial entry (write-only field). Set via `Admin\Settings_Page`. Used only during the token exchange step; discarded immediately after.

### 5.6 `ems_failed_pushback_queue`
Type: serialized array. Persists failed `updateScout` / event-status write jobs for admin retry. Each entry contains the full POST payload required to re-dispatch the job.

```php
[
    [
        'attempted_at' => '2026-08-01 10:32:00',
        'endpoint'     => 'updateScout',
        'payload'      => [
            'sectionid' => '99001',
            'termid'    => '897113',
            'section'   => 'explorers',
            'extraid'   => '50001',
            'scoutid'   => '30001',
            'column'    => 'f_1',
            'value'     => 'SP1-1',
        ],
        'error'        => 'HTTP 429 Too Many Requests',
    ],
]
```

## 6. Gravity Forms Integration Note
The Reconciliation Dashboard reads Gravity Forms signup data using **`GFAPI::get_entries()`**, filtered by form ID and Explorer email address. This is the official Gravity Forms PHP API and is preferred over direct `WPDB` queries to avoid coupling to GF's internal schema.
- **Matching Key**: Explorer's personal email address (must be captured as a dedicated field in the GF form — not the submitter/parent email).
- **Logic**: Compare GF entries against the OSM section participant list; highlight records present in one source but not the other.
