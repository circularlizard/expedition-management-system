# Expedition Management System (EMS) — Systems Architecture Documentation

This document provides a detailed overview of the system architecture, data models, admin screens, and integrations for the **Expedition Management System (EMS)**. It serves as the single source of truth for systems architects and developers.

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

## 2. Architectural Decision Records (ADRs)

Reconciled from technical discovery, these architecture design records govern the EMS implementation:

*   **ADR 001: Data Modeling Strategy**: Use a hybrid approach — Custom Post Types (CPTs) for core hierarchical entities (`season`, `expedition`, `team`) to exploit native WP admin view lists, and custom database tables for relational child mapping data to allow high-performance querying.
*   **ADR 002: OSM Integration & Sync Strategy**: Reference-first data sync using a persistent OSM Scout ID (`scout_id`) as the immutable primary key. WP user accounts are independent and are only generated/hydrated during active OIDC login sessions. Multi-child accounts map children arrays to parent accounts in User Meta.
*   **ADR 003: Frontend Integration Pattern**: React-based SPAs embedded via shortcodes, styled using Elementor's global CSS custom properties (e.g. `--e-global-color-primary`, `--e-global-typography-primary-font-family`) for design consistency with the marketing theme, bypassings Elementor's asset optimization conflicts.
*   **ADR 004: Volunteer Availability & Confirmation Logic**: Custom database table `ems_volunteer_availability` for scheduling availability per user per date. This avoids unqueryable serialized strings and enables fast calendar deficit calculations.
*   **ADR 005: File Management & Security**: Secure route files stored outside public directories (in `/wp-content/uploads/ems-secure/` blocked by `.htaccess`). Downloads are served via a custom REST proxy `/ems/v1/download-route/{id}` that validates explorer, parent, or leader permissions.
*   **ADR 006: Administrative Interface**: Native-looking WordPress administrative views using React bundles enqueued with `@wordpress/components` styling packages (pinned to stable minor versions to avoid breaking changes during core WordPress upgrades).
*   **ADR 007: Test-Driven Development (TDD) Mandate**: All backend and frontend code follows the strict Red-Green-Refactor development loop. Code is not staged or pushed without verifying associated PHPUnit/Vitest coverage.
*   **ADR 008: Testing Frameworks**: Brain Monkey stubs for testing isolated PHP code without loading WordPress core, and Vitest + React Testing Library to verify React behavior from the user's perspective.
*   **ADR 009: Post-Login Hydration Flow (Identity vs. Context)**: Two-step user initialization where the OIDC handshake links the WP user, followed by a secondary startup payload context pull (`getDataPayload`). Captured access tokens are used in memory and **discarded immediately** after meta values are written.
*   **ADR 010: Admin-Triggered OSM Sync OAuth**: Mid-session OAuth code exchange for administrative data pulls and flexi-record syncs. Tokens are used in memory and never stored, keeping the security surface minimal.
*   **ADR 011: Custom Database Tables**: Relational and time-series data (member assignments, availability, submission version histories) reside in custom SQL tables created/updated via `dbDelta()` in [Table_Installer](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Table_Installer.php).
*   **ADR 012: Auth Provider Interface**: Isolation of the concrete `login-with-google` OIDC plugin dependency behind the `EMS\Auth\Auth_Provider` interface.
*   **ADR 013: Flexirecord Mapper**: Configurable JSON mapping configurations (`ems_flexirecord_column_map`) storing columns (e.g. `f_1`, `f_2`) dynamically per section to avoid breaking on structural changes, bucketing data into clean, partial, or unparseable buckets.

---

## 3. Decoupled Data Model

EMS implements a hybrid data model defined in [Table_Installer](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Table_Installer.php) and [CPT_Registry](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/CPT_Registry.php).

### 3.1 Custom Post Types (CPTs)
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
    *   `ems_event_code` (string): Unique event identifier within a season (e.g. `H-SP1`).
    *   `ems_type` (string): `training` | `practice` | `qualifying`
    *   `ems_transport` (string): `hillwalking` | `biking` | `paddling`
    *   `ems_level` (string): `bronze` | `silver` | `gold`
    *   `ems_lic_id` (int): WP User ID of the Leader in Charge.
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
*   **Validation**: Team size of 4–7 is the official range. Sizes outside this range generate an admin warning but are not hard-blocked. Teams with zero members are deleted automatically.

### 3.2 Custom Relational DB Tables
Custom SQL tables store relation, attendance, sync reference, and history data.

#### A. Team Members (`ems_team_members`)
Links Explorers to Teams.
*   `team_post_id` (BIGINT UNSIGNED): Links to the `team` CPT record.
*   `scout_id` (BIGINT UNSIGNED): Primary identity anchor (OSM `member_id`).
*   `user_id` (BIGINT UNSIGNED): Link to `wp_users.ID` (0 if the explorer hasn't logged in via OIDC yet).
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

### 3.3 WordPress User Roles & Metadata
EMS registers custom user roles managed via [Role_Manager](file:///Users/davidstrachan/Projects/expedition-management-system/src/Core/Role_Manager.php):
*   `ems_explorer`: Custom capabilities for participants (`ESU Explorer` display name).
*   `ems_parent`: Account representing a parent or guardian (`ESU Parent` display name).
*   `ems_leader`: Administrative role for local unit leaders (`ESU Leader` display name).

During OIDC login, [OIDC_Login_Handler](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OIDC_Login_Handler.php) hydrates standard WP User accounts with the following metadata:
*   `ems_osm_id` (int): User's OSM account ID.
*   `ems_access_type` (string): Account class (`parent` | `member` | `local`).
*   `ems_scout_ids` (array): List of OSM member IDs linked to the logged-in user.
*   `ems_section_ids` (array): List of OSM section IDs the user administers.
*   `ems_children` (array): Synced child profile details (name, DOB, section IDs).
*   `ems_unit` (string): Synced patrol/unit name.

### 3.4 WordPress Options
EMS stores global configurations in standard WP Options:
*   `ems_managed_sections`: Serialized array mapping active OSM sections (`section_id` keys to names, types, current term ID, extra IDs, and column mapping associations).
*   `ems_api_mode`: Selection representing active driver configuration (`mock` | `live` | `live-auth-only` | `live-limited`).
*   `ems_sync_limit`: Synced participant cap for `live-limited` mode.
*   `ems_osm_client_id` / `ems_osm_client_secret`: Enclosed OAuth parameters (secret is stored encrypted at rest using AES-256-CBC).
*   `ems_osm_api_base_url` / `ems_osm_scope` / `ems_osm_auth_url` / `ems_osm_token_url`: OAuth endpoint roots.
*   `ems_failed_pushback_queue`: Serialized array containing payloads of failed OSM writes.
*   `ems_fluent_form_id`: The target ID of the Fluent Forms signup questionnaire.
*   `ems_form_mappings`: Maps Fluent Form fields to database columns.

---

## 4. REST API Specifications

All endpoints are registered under the `/wp-json/ems/v1/` namespace. Endpoints verify standard WordPress REST nonces (`X-WP-Nonce`) and implement role-based security:

| Endpoint | HTTP Method | Access Rules | Description |
|---|---|---|---|
| `/expedition-board` | `GET` | `manage_options` | Returns full seasons, events, teams, and member datasets for the Team Builder. |
| `/seasons` | `GET` | `manage_options` | Lists all seasons. |
| `/seasons` | `POST` | `manage_options` | Creates a new season. |
| `/seasons/{id}/archive`| `POST`/`PUT` | `manage_options` | Archives a target season (toggles status). |
| `/seasons/{id}` | `DELETE` | `manage_options` | Deletes a season (only if it has no events). |
| `/events` | `POST` | `manage_options` | Creates a new event under a season. |
| `/events/{id}` | `POST`/`PUT` | `manage_options` | Updates event details (dates, locations, LiC details, OSM links). |
| `/events/{id}` | `DELETE` | `manage_options` | Deletes an event (only if it contains no teams). |
| `/events/{id}/teams` | `POST` | `manage_options` | Creates a new team in the event (auto-generates sequential codes). |
| `/events/{src}/populate/{target}` | `POST` | `manage_options` | Bulk-copies team structures from a source event to a target event. |
| `/teams/{id}` | `DELETE` | `manage_options` | Deletes a team (cascading cleanup). |
| `/teams/{id}/move` | `POST`/`PUT` | `manage_options` | Moves a team to a different event of the same type. |
| `/teams/{id}/duplicate`| `POST` | `manage_options` | Clones a team's configuration to a target event. |
| `/teams/{id}/members` | `POST` | `manage_options` | Adds an explorer to a team roster. |
| `/teams/{id}/members/{scout_id}` | `DELETE` | `manage_options` | Removes an explorer from a team roster (triggers auto-cleanup). |
| `/explorers/{scout_id}/move-team` | `POST`/`PUT` | `manage_options` | Moves a single explorer between teams. |
| `/explorers/{scout_id}/first-aid` | `POST`/`PUT` | `manage_options` | Updates an explorer's local first aid qualification. |
| `/explorer/{scout_id}` | `GET` | `manage_options` | Returns detailed contact and training compliance data for a member. |
| `/team/{team_id}` | `GET` | `manage_options` | Returns hydrated team roster details and first-aid compliance flags. |
| `/patrol/{patrol}` | `GET` | `manage_options` | Returns list of explorers matching a patrol/unit string. |
| `/events/{id}/training-requirements` | `GET` | `manage_options` | Lists mapped Tutor LMS course IDs required for the event. |
| `/events/{id}/training-requirements` | `POST` | `manage_options` | Saves mapped Tutor LMS course requirement IDs for the event. |
| `/sync-status` | `GET` | `manage_options` | Returns execution status of background OSM sync cron. |
| `/flexi-structure` | `GET` | `manage_options` | Returns structure of an OSM flexi-record. |
| `/flexi-column-map` | `GET` / `POST` | `manage_options` | Configures and saves OSM flexi-record column maps. |
| `/flexi-review` | `GET` | `manage_options` | Fetches flexi-record rows and buckets them (clean, partial, unparseable). |
| `/flexi-commit` | `POST` | `manage_options` | Syncs the clean bucketed flexi-record data into local tables. |

---

## 5. Third-Party Integrations Architecture

### 5.1 Online Scout Manager (OSM) Integration
OSM acts as the source of truth for membership, patrol configurations, events, and attendance data.

*   **API Client and Driver Model**: Managed by [OSM_API_Client](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OSM_API_Client.php). The client decouples the request structure from the transport layer using [Driver_Interface](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Driver_Interface.php):
    *   [Live_Driver](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Live_Driver.php): Performs HTTPS requests using OAuth2 bearer tokens.
    *   [Mock_Driver](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Drivers/Mock_Driver.php): Emulates OSM API responses using local JSON mock files for development.
*   **API Rate Limiting**: Client-side token bucket algorithm in [Rate_Limiter](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Rate_Limiter.php) limiting requests to **10 per second** (or custom headers from response).
*   **Zero-Persistence Token Policy**: OAuth tokens are captured in memory to fetch context upon login or sync. **Tokens are discarded immediately after configuration updates are written.** No user tokens are stored on the server.
*   **Sync Sequence** (in [OSM_Reference_Sync](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/OSM_Reference_Sync.php)):
    1.  *Term Resolution*: Fetch section term IDs (`get_data_payload`).
    2.  *Patrol Sync*: Fetch patrol structures (`get_patrols`) and write to `ems_units`.
    3.  *Member Sync*: Pull section members (`get_section_participants`) and contact profiles (`get_member_detail`), then write to `ems_osm_explorers`.
    4.  *Event Sync*: Pull events (`get_section_events`) and attendance rosters (`get_event_attendance`), writing to `ems_osm_events` and `ems_osm_event_attendance`.

### 5.2 Tutor LMS Integration
EMS integrates with the **Tutor LMS** database to verify training compliance.

*   **Direct Database Queries**: [TutorLMS_Client](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/TutorLMS_Client.php) bypasses core Tutor LMS overhead. It queries posts directly for published courses (`post_type = 'courses'`) and enrollment records (`post_type = 'tutor_enrolled'`).
*   **Completion Flags**: Checked via WordPress user meta keys (`_tutor_completed_course_{course_id}`).
*   **Batch Compilation**: Matrix layouts resolve student training progress in **only two queries**:
    1.  *Query 1*: Selects distinct user IDs enrolled in specified courses.
    2.  *Query 2*: Selects all course completion values for matching users in a single meta-key request.

### 5.3 Fluent Forms Integration
EMS connects with **Fluent Forms** to process parent signups and payments.

*   **Dropdown Filters**: [Fluent_Forms_Sync](file:///Users/davidstrachan/Projects/expedition-management-system/src/Integrations/Fluent_Forms_Sync.php) filters the child select dropdown (`signup_child`), dynamically listing children mapped under user meta (`ems_children`).
*   **Parent Validation**: Gates submissions to verify that the selected explorer is associated with the logged-in parent.
*   **Stripe Webhook Mappings**: Listens to Stripe payment webhook changes. If a payment status completes (`paid` or `succeeded`), the sync engine updates `payment_status` in the custom `ems_signups` table, maintaining an idempotent guard to prevent payment downgrades.

---

## 6. Frontend Portal Shortcodes (Proposed Layouts)

EMS React SPAs are rendered within custom page templates (`ems-page-template.php`) using WordPress shortcodes:

| Shortcode | Portal | Target Audience | Primary Function |
|---|---|---|---|
| `[ems-explorer-portal]` | Explorer SPA | Synced Explorers | Display assigned team members, routes, checklists, and Tutor LMS progress checklists. |
| `[ems-parent-portal]` | Parent SPA | Verified Parents | Select children, view timelines, and launch expedition registration forms. |
| `[ems-volunteer-dashboard]` | Volunteer Board | Adult Helpers | Submit dates availability and overnight coverage grids. |
| `[ems-leader-portal]` | Leader Portal | Unit Leaders / LiCs | View allocations, assigned events helper rosters, and download print sheets. |
| `[ems-route-submit]` | Upload Form | Team Members | Upload `.gpx` maps and `.pdf` route cards (version audited). |
| `[ems-route-status]` | Status Board | Team Members | View route card review statuses and Leader-in-Charge annotations. |
