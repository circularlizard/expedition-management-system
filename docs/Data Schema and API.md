# Data Schema and API Specification: EMS

This document defines the metadata, relationships, and API endpoints for the Expedition Management System.

## 1. Custom Post Types (CPTs)

### 1.1 `expedition`
Used to manage individual expedition events.
- **Title**: e.g., "Silver Practice - Pentland Hills"
- **Meta Fields**:
    - `ems_type`: string (`practice` | `qualifying`)
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
    - `ems_team_code`: string (e.g., `SP1-1`, `GQ2-3`)
    - `ems_participants`: array (List of WP User IDs)
    - `ems_route_status`: string (`pending` | `feedback_required` | `approved`)
    - `ems_route_feedback`: string (Most recent feedback text)
    - `ems_gpx_file_id`: integer (WP Media ID)
    - `ems_route_card_file_id`: integer (WP Media ID)
    - `ems_submission_history`: array (Log of file versions and dates)

## 2. User Metadata
Extending standard WP User records.
- `ems_osm_id`: integer (Primary key for OSM sync)
- `ems_first_aid_status`: string (`none` | `first_response` | `full`)
- `ems_teammate_preferences`: string (Text from Gravity Forms)
- `ems_volunteer_availability`: array (List of dates/overnights signed up for)
- `ems_parent_children`: array (List of Explorer WP User IDs)
- `ems_unit`: string (Pulled from OSM 'patrol' field)

## 3. REST API Surface
All endpoints prefixed with `/wp-json/ems/v1/`.

### 3.1 Public/Participant Endpoints (Explorer/Parent)
- `GET /my-expeditions`: Returns current expeditions and team status for the logged-in Explorer or their children.
- `POST /submit-route`: Uploads GPX/Route card for a team.
- `GET /download-route/[id]`: Securely serves route files.

### 3.2 Volunteer Endpoints
- `GET /available-expeditions`: List of expeditions open for volunteer signup.
- `POST /volunteer-signup`: Submit availability for an expedition or specific dates.

### 3.3 Administrative Endpoints (Admin/LiC)
- `GET /reconciliation`: Pulls Gravity Forms vs. OSM comparison data.
- `POST /sync-osm`: Triggers a manual sync for a section or event.
- `GET /expedition-board`: Returns full dataset for the Team Builder UI.
- `PATCH /update-team`: Move explorers between teams or expeditions.
- `POST /route-feedback`: LiC submits approval or feedback for a team's route.

## 4. Custom Database Tables (Optional/Future)
While we are using CPTs, we may create a small helper table for **Volunteer Availability** if serialized meta becomes difficult to query for the seasonal calendar view.
- `ems_availability`: `[id, user_id, date, status, expedition_id]`
