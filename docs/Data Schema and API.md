# Data Schema and API Specification: EMS

This document defines the metadata, relationships, and API endpoints for the Expedition Management System.

## 1. Custom Post Types (CPTs)

### 1.1 `expedition`
Used to manage individual expedition events.
- **Title**: e.g., "Silver Practice - Pentland Hills"
- **Meta Fields**:
    - `ems_level`: string (`bronze` | `silver` | `gold`)
    - `ems_type`: string (`practice` | `qualifying`)
    - `ems_expedition_code`: string (manually assigned short code, e.g. `SP1` for Silver Practice 1 — used to auto-generate team codes)
    - `ems_start_date`: string (ISO 8601)
    - `ems_end_date`: string (ISO 8601)
    - `ems_location_name`: string
    - `ems_location_coordinates`: string (optional)
    - `ems_lic_id`: integer (WP User ID of the Leader in Charge)
    - `ems_route_deadline`: string (ISO 8601)
    - `ems_osm_event_id`: integer (Link to OSM Event)
    - `ems_status`: string (`planning` | `open` | `confirmed` | `completed`)

### 1.2 `team`
Used to group participants within an expedition.
- **Title**: e.g., "Team 1"
- **Post Parent**: ID of the associated `expedition`.
- **Meta Fields**:
    - `ems_team_code`: string (e.g., `SP1-1`, `GQ2-3` — auto-generated from the parent expedition's `ems_expedition_code` with an auto-incremented team number)
    - `ems_route_status`: string (`pending` | `feedback_required` | `approved`) — current state shortcut field
    - `ems_route_feedback`: string (Most recent LiC feedback — current state shortcut field)
    - `ems_gpx_file_id`: integer (WP Media ID of current approved/latest GPX)
    - `ems_route_card_file_id`: integer (WP Media ID of current approved/latest route card)
- **Relationships** (stored in custom tables, not Post Meta):
    - Participants: see `ems_team_members` table (§4)
    - Submission history: see `ems_route_submissions` table (§4)

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
- `GET /reconciliation`: Pulls Gravity Forms vs. OSM comparison data.
- `POST /sync-osm`: Triggers a manual sync for a section or event.
- `GET /expedition-board`: Returns full dataset for the Team Builder UI.
- `PATCH /update-team`: Move explorers between teams or expeditions.
- `POST /route-feedback`: LiC submits approval or feedback for a team's route.

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

### 5.4 `ems_service_account_tokens` *(encrypted)*
Type: serialized array. Stores OAuth tokens for the EMS service account used for all push-back write operations. Managed by the service account auth flow (see ADR 010). Values stored encrypted at rest.

```php
[
    'access_token'  => '...',
    'refresh_token' => '...',
    'expires_at'    => 1780000000,  // Unix timestamp
]
```

### 5.5 `ems_failed_pushback_queue`
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
