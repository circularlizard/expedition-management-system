# Event Planning & Scheduling View — Implementation Specification

This document defines the technical specification for implementing the **Event Planning View** under Milestone 2.

---

## 1. Preferences Mapping & Alignment

Date preferences are stored inside `expedition_preferences` in the `ems_expedition_signups` table:
```json
{
  "exped_type": "Hillwalking",
  "exped_practice_dates": ["H-SP1", "H-SP2"],
  "exped_qualifier_dates": ["H-SQ1"],
  "exped_team_names": ""
}
```
Form selection values will be updated in Fluent Forms to store the **Event Short Code** directly (e.g. `"H-SP1"`, `"H-SQ1"`, `"G-SP1"`, `"G-SQ1"`).

---

## 2. REST API Endpoints

We will register the following endpoints in [Expedition_Admin_Controller.php](file:///Users/davidstrachan/Projects/expedition-management-system/src/Admin/Expedition_Admin_Controller.php):

### 2.1 `GET ems/v1/planning-board`
Returns availability and allocation stats for all active events (Silver and Gold levels only).
*   **Logic**:
    1.  Fetch all active `expedition` CPT posts (sorted chronologically).
    2.  For each event, fetch all expedition signup preferences.
    3.  Count how many signups match the event's code in their preferences array (`available_count`).
    4.  Count how many explorers are currently members of teams inside this event (`allocated_count`).
*   **Response Payload**:
    ```json
    [
      {
        "id": 12,
        "title": "Hill Practice 1",
        "event_code": "H-SP1",
        "type": "practice",
        "level": "silver",
        "start_date": "2026-06-28",
        "end_date": "2026-06-30",
        "available_count": 14,
        "allocated_count": 5
      }
    ]
    ```

### 2.2 `GET ems/v1/planning-board/availability/{event_code}`
Returns the list of explorers who have indicated availability for a specific event code.
*   **Response Payload**:
    ```json
    [
      {
        "scout_id": 30001,
        "first_name": "Alice",
        "last_name": "MacLeod",
        "unit_name": "SMESU",
        "allocated_event_code": "H-SP2", // populated if they are already in another practice/qualifier event at this level
        "allocated_team_code": "H-SP2-1"  // populated if allocated
      }
    ]
    ```

### 2.3 `POST ems/v1/planning-board/allocate`
Allocates or moves explorers to an event.
*   **Payload**:
    ```json
    {
      "scout_ids": [30001, 30002],
      "event_id": 12,
      "allocation_mode": "unallocated" | "existing_team" | "new_team",
      "target_team_id": 105 // required if allocation_mode is "existing_team"
    }
    ```
*   **Execution Logic**:
    1.  For each `scout_id`, check if they are already allocated to any team in an event of the same level/type.
    2.  If so, remove them from their old team.
    3.  If that old team becomes empty (0 members), automatically delete that old team CPT post.
    4.  Add them to the target event:
        - If `unallocated`: Add to the event's virtual `UNALLOCATED` team.
        - If `existing_team`: Add to `target_team_id`.
        - If `new_team`: Call `Team_Repository::create` to generate a new team CPT post (with sequential code, e.g. `H-SP1-3`), then add the members to it.

---

## 3. UI Design (React SPA)

We will add a new tab **"Event Planning"** to the main **Events Dashboard** toolbar:

```
┌────────────────────────────────────────────────────────────────────────┐
│  [ Upcoming Events ]   [ Past Events ]   [ Event Planning ]            │
├────────────────────────────────────────────────────────────────────────┤
│ LEVEL: [ Silver ]  TYPE: (•) Practice  ( ) Qualifier                   │
├───────────────────────────────┬────────────────────────────────────────┤
│ SELECT AN EVENT               │ EXPLORER AVAILABILITY (H-SP1)          │
│ ┌───────────────────────────┐ │ Sort by: [ Name ]                      │
│ │ Hill Practice 1 (H-SP1)   │ │                                        │
│ │ Date: 28 Jun - 30 Jun     │ │ [ ] Alice MacLeod (SMESU)             │
│ │ Available: 14 | Alloc: 5  │ │ [ ] Bob Smith (Kelso) — [H-SP2-1]      │
│ ├───────────────────────────┤ │ [ ] Charlie Brown (Selkirk)            │
│ │ Hill Practice 2 (H-SP2)   │ │                                        │
│ │ Date: 04 Jul - 06 Jul     │ │ Select: [ All ] [ None ]               │
│ │ Available: 8  | Alloc: 0  │ │ Action: [ Add to Unallocated        ]  │
│ └───────────────────────────┘ │         [ Add to Team: H-SP1-1      ]  │
│                               │         [ Add to New Team           ]  │
│                               │         [ Apply Action ]               │
└───────────────────────────────┴────────────────────────────────────────┘
```

### UI Features
1.  **Level / Type Filter**: Toggle between Silver/Gold and Practice/Qualifier (Bronze is out of scope).
2.  **Two-Column Split**:
    *   **Left Column (Event Selection)**: Lists CPT expeditions matching the level and type. Ordered by date. Each card shows the Available vs Allocated counters. Includes a button/link to navigate directly to the event's detail page.
    *   **Right Column (Explorers List)**: Displays when an event card is selected. Lists names, units, and active allocation codes (if any). Sortable by Name or Unit. Contains checkboxes for bulk selection.
3.  **Actions Panel**:
    *   Dropdown to choose placement: Unallocated (virtual team), an existing team (fetched for the selected event), or New Team.
    *   Trigger button executes the allocation. If any selected explorer is already allocated, displays a native `window.confirm()` confirmation before executing the move.
