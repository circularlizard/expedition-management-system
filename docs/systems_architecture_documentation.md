# Expedition Management System (EMS) — Systems Architecture Documentation

This document provides a detailed overview of the system architecture, data models, admin screens, and integrations for the **Expedition Management System (EMS)**. It is written for systems architects and developers looking to understand the technical structure and integrations of the system.

---

## 1. Architectural Overview & Context

The EMS is a WordPress plugin ([ems-plugin.php](file:///Users/davidstrachan/Projects/expedition-management-system/ems-plugin.php)) designed to manage Duke of Edinburgh (DofE) expeditions, teams, explorer training compliance, and parental registrations. It is built using a modern decoupled architecture:
*   **Backend**: Object-oriented PHP 8.2 with namespaces, autoloading (PSR-4), strict typing, and a test-driven (TDD) foundation.
*   **Frontend**: React-based Single Page Applications (SPAs) embedded in the WordPress Admin dashboard using ES Modules.
*   **Database**: A hybrid data model utilizing WordPress Custom Post Types (CPTs) for core entities and custom SQL relational tables for high-performance querying and relationship mapping.
*   **Integrations**: Online Scout Manager (OSM) as the identity anchor and reference provider, Tutor LMS for training status verification, and Fluent Forms for parent signups and Stripe payment processing.

### System Components & Integrations

```mermaid
graph TD
    subgraph WordPress Admin Dashboard (React SPA)
        ExpeditionBoard[Expedition Board / Team Builder]
        ExplorerList[Explorer List / Ref Grid]
        ColumnMapperUI[Flexi-Record Column Mapper]
    end

    subgraph WordPress Admin Views (PHP Rendered)
        TrainingReport[Tutor LMS Training Report]
        SettingsPage[Plugin Configuration Page]
        SignupsPage[Fluent Forms Signups View]
    end

    subgraph EMS Plugin Core
        PluginInit[Plugin Core Hook Registry]
        OSMClient[OSM API Client]
        OSMReferenceSync[OSM Reference Sync Manager]
        FluentSync[Fluent Forms Sync Handler]
        TutorClient[Tutor LMS client]
        RESTControllers[WP REST API Controllers]
        DataModel[CPT Registry & Repository Layer]
    end

    subgraph Database (MySQL)
        WPCpt[(WP Post Meta & CPTs)]
        CustomTables[(Custom Relational SQL Tables)]
    end

    subgraph External Systems
        OSM[(Online Scout Manager API)]
        TutorLMSPlugin[(Tutor LMS Database)]
        FluentFormsPlugin[(Fluent Forms Database)]
    end

    %% Frontend Interactions
    ExpeditionBoard -->|REST API| RESTControllers
    ExplorerList -->|REST API| RESTControllers
    ColumnMapperUI -->|REST API| RESTControllers

    %% Backend Controllers to Repositories
    RESTControllers --> DataModel
    PluginInit --> DataModel

    %% Repositories to DB
    DataModel --> WPCpt
    DataModel --> CustomTables

    %% Integration Handlers to External Systems & DB
    OSMReferenceSync -->|OAuth2 / REST| OSMClient
    OSMClient <--> OSM
    FluentSync -->|Hooks / Submissions| FluentFormsPlugin
    FluentSync --> CustomTables
    TutorClient --> TutorLMSPlugin
    TrainingReport --> TutorClient
    
    classDef default fill:#f9f9f9,stroke:#333,stroke-width:1px;
    classDef database fill:#e1f5fe,stroke:#0288d1,stroke-width:1.5px;
    classDef external fill:#fff3e0,stroke:#f57c00,stroke-width:1.5px;
    class WPCpt,CustomTables database;
    class OSM,TutorLMSPlugin,FluentFormsPlugin external;
```

---

## 2. Decoupled Data Model

To resolve performance bottlenecks and fit cleanly into the WordPress ecosystem, EMS implements a **hybrid data model** defined in [Table_Installer](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Table_Installer.php) and [CPT_Registry](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/CPT_Registry.php).

### 2.1 Custom Post Types (CPTs)
CPTs are used for major hierarchical assets, allowing the system to leverage native WordPress administrative list views, post lifecycles, and revision storage.

```
Season (season CPT)
  └── Event / Expedition (expedition CPT)
        └── Team (team CPT)
```

#### A. Season (`season`)
Top-level container for a flight of events in an academic year.
*   **Meta Fields**:
    *   `ems_season_year` (string, e.g. `2026-27`)
    *   `ems_season_status` (string: `active` | `archived`)

#### B. Event / Expedition (`expedition`)
Manages individual training events, practice expeditions, and qualifying expeditions.
*   **Hierarchy**: Belongs to a parent `season` (Post Parent ID).
*   **Meta Fields**:
    *   `ems_event_code` (string): Manually assigned short code, unique within a season (e.g. `H-SP1` for Hillwalking Silver Practice 1). Used to generate team prefixes.
    *   `ems_type` (string): `training` | `practice` | `qualifying`
    *   `ems_transport` (string): `hillwalking` | `biking` | `paddling`
    *   `ems_level` (string): `bronze` | `silver` | `gold`
    *   `ems_lic_id` (int): WP User ID of the Leader in Charge (LiC).
    *   `ems_lic_name` / `ems_lic_email` / `ems_lic_phone` (strings): Contact details of the LiC.
    *   `ems_start_date` / `ems_end_date` (ISO 8601 date strings).
    *   `ems_start_time` / `ems_end_time` (time strings).
    *   `ems_start_location` / `ems_end_location` (strings).
    *   `ems_osm_event_id` (int): Links to an Online Scout Manager event.
    *   `ems_route_deadline` (ISO 8601 date).
    *   `ems_route_info` (HTML string): Routing notes, map links, instructions.
    *   `ems_status` (string): `planning` | `open` | `confirmed` | `completed`

#### C. Team (`team`)
Groups participants within an event.
*   **Hierarchy**: Belongs to a parent `expedition` (Post Parent ID).
*   **Meta Fields**:
    *   `ems_team_code` (string): Unique identifier (e.g. `H-SP1-1`). Generated sequentially from the parent event's `ems_event_code`.
    *   `ems_team_number` (int): The numeric suffix (enforced sequentially).
    *   `ems_route_status` (string): `pending` | `feedback_required` | `approved`
    *   `ems_route_feedback` (string): Most recent feedback from the Leader in Charge.
    *   `ems_gpx_file_id` (int): WP Media ID of the latest GPX file.
    *   `ems_route_card_file_id` (int): WP Media ID of the latest PDF/doc route card.
*   **Relationships** (stored in custom tables, not Post Meta):
    *   Participants: see `ems_team_members` table (§4)
    *   Submission history: see `ems_route_submissions` table (§4)
*   **Validation**: Team size of 4–7 is the official range. Sizes outside this range generate an admin warning but are not hard-blocked. Teams with zero members are deleted automatically.

### 2.2 Custom Relational DB Tables
Custom SQL tables store relation, attendance, sync reference, and history data. This prevents performance bottlenecks that occur when querying serialized arrays in WordPress Post Meta.

```
┌────────────────────────────────────────────────────────────────────────┐
│                          CUSTOM SQL SCHEMAS                            │
├──────────────────────────────┬─────────────────────────────────────────┤
│ Table Name                   │ Key Fields                              │
├──────────────────────────────┼─────────────────────────────────────────┤
│ ems_team_members             │ id, team_post_id, scout_id, user_id     │
│ ems_volunteer_availability   │ id, user_id, expedition_post_id, date   │
│ ems_route_submissions        │ id, team_post_id, wp_media_id, status   │
│ ems_osm_explorers            │ id, scout_id (UNIQUE), wp_user_id       │
│ ems_osm_events               │ id, event_id (UNIQUE), section_id       │
│ ems_osm_event_attendance     │ id, event_id, scout_id (UNIQUE COMBINED)│
│ ems_units                    │ id, patrol_id, section_id, short_code   │
│ ems_signups                  │ id, scout_id, form_submission_id        │
└──────────────────────────────┴─────────────────────────────────────────┘
```

#### A. Team Members (`ems_team_members`)
Links Explorers to Teams.
*   `team_post_id` (BIGINT UNSIGNED): Links to the `team` CPT record.
*   `scout_id` (BIGINT UNSIGNED): Primary identity anchor (OSM `member_id`).
*   `user_id` (BIGINT UNSIGNED): Optional link to `wp_users.ID` (0 if the explorer hasn't logged in via OIDC yet).
*   `added_by` / `added_at`: Tracking attributes.

#### B. Volunteer Availability (`ems_volunteer_availability`)
Tracks volunteer cover for expedition dates.
*   `user_id` (BIGINT UNSIGNED): Mapped volunteer user.
*   `expedition_post_id` (BIGINT UNSIGNED): Mapped event.
*   `date` (DATE): Target date.
*   `overnight` (TINYINT): Mapped overnight availability flag.
*   `confirmed` (TINYINT) / `confirmed_by` (BIGINT): Sign-off tracking.

#### C. Route Submissions (`ems_route_submissions`)
Maintains a versioned audit trail of route file updates and status changes.
*   `team_post_id` (BIGINT UNSIGNED): Associated team.
*   `version` (INT): Incremental integer.
*   `file_type` (VARCHAR): `gpx` | `route_card`
*   `wp_media_id` (BIGINT UNSIGNED): Mapped file in WordPress Media Library.
*   `status` (VARCHAR): `pending` | `feedback_required` | `approved`

#### D. Synced OSM Explorers (`ems_osm_explorers`)
Local cache of Online Scout Manager participants. 
> [!IMPORTANT]
> **Identity Anchor Strategy**: The primary identity anchor for explorers is `scout_id` (OSM `member_id`), **not** `wp_users.ID`. A WordPress user is only created when an explorer logs in via OIDC, but they can be synced and assigned to teams beforehand.
*   `scout_id` (BIGINT UNSIGNED, UNIQUE): Mapped OSM member ID.
*   `wp_user_id` (BIGINT UNSIGNED, Nullable): Mapped WordPress User ID.
*   `section_id` (BIGINT UNSIGNED): OSM section identifier.
*   `first_name` / `last_name` / `email` / `parent_email` / `patrol` (VARCHARs).
*   `first_aid_level` (VARCHAR): Local override for first aid qualifications (`none` | `first_response` | `full_first_aid`).
*   `last_local_update_at` / `last_ems_push_at` (DATETIME): Used to track local changes that need to be pushed back to OSM.

#### E. Synced OSM Events (`ems_osm_events`)
Cached OSM events.
*   `event_id` (BIGINT UNSIGNED): OSM event ID.
*   `section_id` (BIGINT UNSIGNED): Sync source section.
*   `name` / `start_date` / `end_date` / `location` (event properties).
*   `yes_members` / `yes_leaders` / `no` (INTs): Aggregated attendance statistics.

#### F. Synced OSM Event Attendance (`ems_osm_event_attendance`)
RSVP statuses for synced events.
*   `event_id` / `scout_id` (BIGINT UNSIGNED, UNIQUE index): Relational link.
*   `status` (VARCHAR): Member attendance state (e.g. `Attending`, `Declined`).

#### G. Units / Patrols (`ems_units`)
Cached Scout Explorer Units (mapped from OSM patrols).
*   `patrol_id` (BIGINT) / `section_id` (BIGINT): Identifiers from OSM.
*   `name` / `short_code` (VARCHAR): Identification properties.
*   `leader_first_name` / `leader_last_name` / `leader_email` (VARCHAR): Unit leader contact details (configured in EMS settings).

#### H. Explorer Signups (`ems_signups`)
DofE/Expedition registration records submitted via Fluent Forms.
*   `scout_id` (BIGINT UNSIGNED, Nullable): Links to the synced explorer.
*   `parent_user_id` (BIGINT UNSIGNED): The WordPress User ID of the submitting parent.
*   `dofe_level` (VARCHAR): `bronze` | `silver` | `gold`
*   `signup_status` / `payment_status` (VARCHAR): State flags.
*   `form_submission_id` (BIGINT UNSIGNED): Submission record ID.

### 2.3 WordPress User Roles & Metadata
EMS registers three custom user roles managed via [Role_Manager](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Role_Manager.php):
1.  `ems_explorer`: Standard participant role.
2.  `ems_parent`: Account representing a parent or guardian.
3.  `ems_leader`: Administrative role for local unit leaders and Leaders-in-Charge.

During OIDC login, [OIDC_Login_Handler](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php) hydrates standard WP User accounts with the following metadata:
*   `ems_osm_id` (int): User's OSM account ID.
*   `ems_access_type` (string): Account class (`parent` | `member` | `local`).
*   `ems_scout_ids` (array): List of OSM member IDs linked to the logged-in user.
*   `ems_section_ids` (array): List of OSM section IDs the user administers.
*   `ems_children` (array): Synced child profile details (name, DOB, section IDs).
*   `ems_unit` (string): Synced patrol/unit name.

---

## 3. Administrative Interface (Screens & Functions)

The administrative views are registered by [Admin_Page](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Admin_Page.php) and handled via WP REST API routes in [Admin_View_Controller](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Admin_View_Controller.php) and [Expedition_Admin_Controller](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php).

### 3.1 Expedition Board (React SPA)
*   **Hook Location**: `EMS` (Top Level Submenu)
*   **Enqueued Script**: `assets/js/expedition-board.js` (rendered from [SeasonDashboard.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/expedition-board/SeasonDashboard.tsx))
*   **Endpoints Consumed**: `GET ems/v1/expedition-board`
*   **Key Capabilities**:
    *   **Hierarchical Navigation**: Renders a vertical layout of Seasons → Events → Teams. Each team block displays its current member count and hydrates member profiles.
    *   **Warning Indicators**: Highlights teams with size exceptions (less than 4 or more than 7 members) using visual warnings.
    *   **Season & Event CRUD**: Interactive modals ([EventForm.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/expedition-board/EventForm.tsx)) allow admins to create new seasons, events, and link WordPress events directly to OSM Events.
    *   **Team Lifecycle**: Create teams (which automatically generates sequential codes based on the event code, such as `H-SP1-1`) or delete empty teams.
    *   **Cross-Event Operations**:
        *   **Move Team**: Moves a team and all its members to another event of the same type (e.g. from Practice 1 to Practice 2), automatically updating their team codes.
        *   **Duplicate Team**: Clones team structures and memberships into a target event.
        *   **Populate Event**: Bulk populates teams from one event stage to another (e.g. copying all team configurations from a completed *Practice* event to a *Qualifying* event).
    *   **Team Assignment Panels**: Slide-out panels ([ExplorerMovePanel.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/expedition-board/ExplorerMovePanel.tsx) and [TeamMovePanel.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/expedition-board/TeamMovePanel.tsx)) allow admins to add, remove, or swap explorers between teams.

### 3.2 Explorer List (React SPA)
*   **Hook Location**: `EMS -> Explorers`
*   **Enqueued Script**: `assets/js/expedition-board.js` (rendered from [OSMReference.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/expedition-board/OSMReference.tsx))
*   **Key Capabilities**:
    *   **Integrated Roster View**: Consolidates synced OSM member details with Tutor LMS course completion counts and active team assignments.
    *   **Expedition Status Filtering**: Allows filtering by event status (all, assigned to any event, assigned to no events) to help identify unassigned participants.
    *   **Compliance Tracking**: Integrates compliance data, showing course counts and percentage progress.
    *   **Inline First Aid Management**: Allows editing of first aid levels (`none`, `first_response`, `full_first_aid`) directly in the table. This triggers `POST ems/v1/explorers/{scout_id}/first-aid`.

### 3.3 Explorer Signups Board (PHP List Table)
*   **Hook Location**: `EMS -> Signups`
*   **Key Capabilities**:
    *   Displays a paginated table of registrations from `ems_signups`.
    *   Tracks DofE level, first aid status, signup status, and payment status (updated via Stripe webhooks).

### 3.4 OSM Sync Page (PHP)
*   **Hook Location**: `EMS -> OSM Sync` (managed by [OSM_Reference_Page.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/OSM_Reference_Page.php))
*   **Tabs**:
    *   **Explorers**: Lists synced cached members.
    *   **Patrols**: Displays units and active statuses.
    *   **Events**: Lists synced OSM events and rsvp metrics.
    *   **Diagnostics** (rendered by [Diagnostic_Panel.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Diagnostic_Panel.php)): Displays PHP version, database tables status, transient queues, rate limiter statuses, and raw JSON payloads.
*   **Execution Handler**: Implements the OAuth authorization code exchange flow. It displays a real-time progress bar when a sync is running, and includes a "Cancel Sync" fallback.

### 3.5 Flexi-Record Column Mapper (React SPA)
*   **Hook Location**: Embedded under the `EMS -> Column Mapper` submenu (rendered from [ColumnMapper.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/column-mapper/ColumnMapper.tsx))
*   **Endpoints Consumed**: `GET /flexi-structure`, `POST /flexi-column-map`, `GET /flexi-review`, `POST /flexi-commit`
*   **Key Capabilities**:
    *   **Structure Fetching**: Fetches fields from an OSM Flexi-Record dynamically.
    *   **Schema Mapping**: Maps flexi-record fields (e.g. "Practice Group Name", "Qualifying Group Name") to EMS system fields.
    *   **Import Bucketing Review**: Fetches row data, parses it against mappings, and groups rows into three buckets ([ImportReview.tsx](file:///Users/davidstrachan/Projects/expedition-management-system/resources/js/admin/column-mapper/ImportReview.tsx)):
        1.  `clean`: Complete records with matching WordPress users, ready to import.
        2.  `partial`: Records with missing fields or unmatched scout IDs (skipped).
        3.  `unparseable`: Corrupted or invalid rows.
    *   **Commit Phase**: Writes valid records to CPTs and `ems_team_members` tables.

### 3.6 Settings Page (PHP)
*   **Hook Location**: `EMS -> Settings` (managed by [Settings_Page.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Settings_Page.php))
*   **Key Capabilities**:
    *   **API Mode Driver Selector**: Selects between `mock`, `live`, `live-auth-only`, and `live-limited` driver configurations.
    *   **OSM Credentials**: Input fields for OAuth Client ID, OAuth Client Secret (stored encrypted), scopes, and API URLs.
    *   **Managed Sections**: Selects which imported OSM sections are managed by the plugin.
    *   **Unit Mapping Settings**: Map ESU patrols to leaders and email contacts.

### 3.7 Training Report Page (PHP)
*   **Hook Location**: `EMS -> Training Report` (managed by [Training_Report_Page.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Training_Report_Page.php))
*   **Key Capabilities**:
    *   Queries Tutor LMS courses and student enrollments.
    *   Renders a training completion matrix showing course statuses (Complete, In Progress, Not Enrolled) for each explorer.
    *   Supports exporting the report to a CSV file.

---

## 4. Third-Party Integrations Architecture

### 4.1 Online Scout Manager (OSM) Integration

OSM acts as the source of truth for membership, patrol configurations, events, and attendance data.

```
                  ┌─────────────────────────────────┐
                  │           OSM API Client        │
                  └────────────────┬────────────────┘
                                   │
                    ┌──────────────┴──────────────┐
                    ▼                             ▼
       ┌─────────────────────────┐   ┌─────────────────────────┐
       │       Live Driver       │   │       Mock Driver       │
       └────────────┬────────────┘   └────────────┬────────────┘
                    │                             │
                    ▼                             ▼
       ┌─────────────────────────┐   ┌─────────────────────────┐
       │   Live API Requests     │   │   Local JSON Mock Files │
       │  - OAuth2 Bearer Token  │   │   - osm-events.json     │
       │  - Rate Limited (10/s)  │   │   - osm-member-list.json│
       └─────────────────────────┘   └─────────────────────────┘
```

#### A. API Client and Driver Model
EMS accesses the OSM API through [OSM_API_Client](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OSM_API_Client.php). The client decouples the request structure from the transport layer using a driver pattern:
*   [Driver_Interface](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Driver_Interface.php): Defines core API transport methods.
*   [Live_Driver](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Live_Driver.php): Performs HTTPS requests using OAuth2 bearer tokens.
*   [Mock_Driver](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Mock_Driver.php): Emulates OSM API responses using mock JSON payloads stored in `tests/mocks/` for local development and unit testing.

#### B. API Rate Limiting
The system enforces a rate limit of **10 requests per second** (or custom intervals specified by the server) using a client-side token bucket algorithm in [Rate_Limiter](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Rate_Limiter.php). This prevents rate-limit lockouts during bulk data imports.

#### C. OAuth 2.0 Auth Flow & Token Disposal Strategy
To minimize security risks, the system uses a **zero-persistence token strategy**:
1.  **OIDC Authentication**: During OIDC login, the user's browser goes through the OAuth exchange flow. The system captures the one-time `access_token` in memory.
2.  **Immediate Hydration**: The system immediately fetches the user's startup payload (`getDataPayload`) to determine access levels, child links, and administered sections.
3.  **Token Disposal**: Once the user's metadata is written to the database, the token is discarded. **No user tokens are stored on the server.**
4.  **Admin Sync Flow**: When an admin runs a manual sync, they complete a temporary OAuth redirect to obtain a single-use token. This token is stored in memory, used to complete the sync, and immediately discarded.

#### D. Sync Sequence
The reference data sync runs the following sequence in [OSM_Reference_Sync](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OSM_Reference_Sync.php):

```
1. Fetch Startup Payload (getDataPayload) 
   ├── Resolve Section Names & Section IDs
   └── Resolve Current Term ID per Section
2. Sync Patrols (getPatrols)
   └── Write to local `ems_units` table
3. Sync Members (getListOfMembers)
   ├── Loop through members
   ├── Fetch contact details (getMemberDetail)
   └── Upsert details into `ems_osm_explorers`
4. Sync Events (getEvents)
   ├── Fetch attendance (getEventAttendance)
   └── Upsert data into `ems_osm_events` & `ems_osm_event_attendance`
```

---

### 4.2 Tutor LMS Integration

EMS integrates with the **Tutor LMS** plugin database to track explorer training progress.

```
                     ┌──────────────────────────────┐
                     │       TutorLMS_Client        │
                     └──────────────┬───────────────┘
                                    │
           ┌────────────────────────┴────────────────────────┐
           ▼                                                 ▼
┌──────────────────────────────────────┐   ┌───────────────────────────────────┐
│          Course Lookup Query         │   │      Enrollment Matrix Query      │
│  - Selects publish 'courses' posts   │   │  - Query 'tutor_enrolled' posts   │
│  - Ordered by course title           │   │  - Query '_tutor_completed_...'   │
└──────────────────────────────────────┘   └───────────────────────────────────┘
```

#### A. Database Queries
To run efficiently on shared hosting, the system avoids loading the entire Tutor LMS codebase. Instead, [TutorLMS_Client](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php) queries the Tutor LMS tables directly:
*   **Courses**: Fetches published courses (`post_type = 'courses'`).
*   **Enrollment Status**: Queries the WordPress posts table for `post_type = 'tutor_enrolled'` where the post parent is the course ID and the post author is the user's ID.
*   **Completion Status**: Checks for the existence of user meta key `_tutor_completed_course_{course_id}`.

#### B. Batch Queries for Reporting
To prevent performance bottlenecks on the Training Report page, `TutorLMS_Client` loads the entire enrollment matrix using two optimized database queries:
1.  **Query 1**: Fetches all distinct user IDs enrolled in any of the active course IDs:
    ```sql
    SELECT DISTINCT post_author FROM wp_posts
    WHERE post_type = 'tutor_enrolled' AND post_status = 'completed' AND post_parent IN (...)
    ```
2.  **Query 2**: Fetches all course completion timestamps from user meta in a single batch:
    ```sql
    SELECT user_id, meta_key FROM wp_usermeta
    WHERE meta_key IN ('_tutor_completed_course_1', '_tutor_completed_course_2', ...) AND user_id IN (...)
    ```
The client compiles this data into a two-dimensional array (`$matrix[$userId][$courseId] = 'complete' | 'in_progress' | 'not_enrolled'`), allowing the training report table to render instantly without executing nested SQL queries for each cell.

---

### 4.3 Fluent Forms Integration

EMS integrates with the **Fluent Forms** database to manage expedition signups and payment processing via [Fluent_Forms_Sync](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Fluent_Forms_Sync.php).

```
                      ┌──────────────────────────────┐
                      │      Fluent_Forms_Sync       │
                      └──────────────┬───────────────┘
                                     │
           ┌─────────────────────────┼─────────────────────────┐
           ▼                         ▼                         ▼
┌─────────────────────┐   ┌─────────────────────┐   ┌─────────────────────┐
│ Child Dropdown      │   │ Submission Validation│   │ Submission Handling │
│ - Dynamically lists │   │ - Checks parental   │   │ - Mapped to         │
│   synced children   │   │   permission link   │   │   `ems_signups`     │
│   from user meta    │   │ - Validates level   │   │ - Mapped to         │
│                     │   │                     │   │   Stripe payments   │
└─────────────────────┘   └─────────────────────┘   └─────────────────────┘
```

#### A. Dynamic Dropdown Population
When rendering the registration form, the system intercepts the dropdown fields:
*   **Child Selector**: Filters the `signup_child` select field options, dynamically populating them with the logged-in parent's verified children cached in user meta (`ems_children`). Option values are formatted as `scout_id|first_name|last_name`.
*   **Unit Pre-population**: Resolves the ESU unit short code for the child and pre-selects it in the form.

#### B. Validation Hooks
During submission, the system hooks into `fluentform/validation_errors` to run the following checks:
*   Verifies the selected child's `scout_id` belongs to the logged-in parent's child list in user meta (`ems_children`).
*   Verifies the selected DofE Level is valid (`bronze`, `silver`, or `gold`).

#### C. Database Synchronization & Stripe Payments
Upon successful form submission (`fluentform/submission_inserted`), the system extracts the form data and upserts a record into the custom `ems_signups` table.
*   **Stripe Integration**: The system hooks into `fluentform/after_payment_status_change` to monitor Stripe payment status changes. When a payment completes, the hook updates the corresponding record's `payment_status` in the `ems_signups` table.

---

## 5. Security & Verification Strategy

### 5.1 Verification and Error Handling
The integration pipeline includes the following safety features:
*   **Clean Database Delta Migrations**: Relational table changes are applied using additive SQL migrations in `Table_Installer::run_migrations()`, preventing data loss during plugin updates.
*   **Transactional Safe Writes**: Changes to CPT configurations and relational table writes are wrapped in safe sanitization and validation checks using `sanitize_text_field()` and `Meta_Validator`.
*   **Write-Back Failures Handling**: Failed push-back requests to OSM (e.g. rate limit blocks or response timeouts) are added to a serialized queue (`ems_failed_pushback_queue`) in WordPress options for admin review and retry.

### 5.2 Test Coverage (TDD Workflow)
The codebase is developed using Test-Driven Development (TDD):
*   **Backend Tests**: Run on PHPUnit and mock WordPress APIs using Brain Monkey stubs (see unit tests under `tests/Unit/`).
*   **Frontend Tests**: Run on Vitest and React Testing Library, mocking API endpoints using mock datasets (see tests under `resources/js/admin/`).
