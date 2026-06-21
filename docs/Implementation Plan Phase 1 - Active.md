# Implementation Plan Phase 1 — Active Work

> Completed stages (1.1–1.6, foundations, environment) archived to:
> `docs/archive/Implementation Plan Phase 1 - Completed.md`
>
> Phase 2+ plan: `docs/Implementation Plan Phase 2+.md`

---

## TDD Workflow — Gherkin First

All remaining stages follow this sequence **without exception**:

1. **Write Gherkin scenarios** (`tests/features/*.feature`) covering the behaviour to be implemented — happy path, edge cases, validation failures, and guard conditions.
2. **Review scenarios** with the user before any code is written. Scenarios are the specification.
3. **Write failing tests** (PHPUnit / Vitest) that implement the scenarios — confirm they fail (red).
4. **Implement the production code** until all tests pass (green).
5. **Refactor** as needed, keeping tests green.

### What Gherkin covers

Gherkin is for **observable behaviour** — things a user or API caller causes and observes. Use it for:

- **Business logic** — validation rules, guard conditions, sequential numbering, cascade deletes
- **REST API** — request/response shape, auth checks, error codes
- **UI behaviour** — component rendering, form validation, empty states (Vitest)

### What Gherkin does NOT cover

**CPT registration and meta field configuration are structural/wiring concerns**, not behaviour. These are tested directly in PHPUnit using Brain Monkey stubs (asserting `register_post_type()` and `register_meta()` are called with the correct args) — the same pattern already used in `tests/Unit/Core/CPT_RegistryTest.php`. Do not write Gherkin for these.

Likewise, **table schema** is tested directly in PHPUnit against `Table_Installer`, not via Gherkin.

Feature files live in `tests/features/` and are organised by stage (e.g. `tests/features/1.12-events.feature`).

---

## Current Status — 16 June 2026

| Stage                 | Description                                    | Status               |
| -----------------------| ------------------------------------------------| ----------------------|
| Foundations + 1.1–1.6 | All completed                                  | ✅ See archive        |
| Step 0                | Anonymised mock data generation                | ✅ Done — 15 Jun 2026 |
| 1.7                   | Admin Read Views                               | ✅ Done — 15 Jun 2026 |
| 1.8                   | Diagnostics + Reference Data Display           | ✅ Done — 15 Jun 2026 |
| 1.9                   | Settings page tabs + Managed Sections redesign | ✅ Done — 16 Jun 2026 |
| 1.10                  | OSM Auth Test Modes + Sync Progress Feedback   | ✅ Done — 16 Jun 2026 |
| 1.11                  | Expedition Board deep review                   | ❌ Not started        |
| 1.12                  | Expedition write logic + Explorer Assignment   | ❌ Not started        |
| 1.13                  | Training Status Fallback                       | ❌ Not started        |
| 1.14                  | Column Mapper repurpose (OSM write-back)       | ❌ Not started        |

**Tests**: 231 PHP / 463 assertions green. 16 JS Vitest green.

**Live OAuth status**: Working end-to-end as of 16 Jun 2026. Fixes this session: `wp_redirect()` for external OAuth redirect; correct `ext/` API endpoint paths; `sectionname` property for display names; section `type` stored and displayed; error handling for OSM error params and `getDataPayload` failures.

---

## Active Stages

### Stage 1.10 — OSM Auth Test Modes + Sync Progress Feedback ✅ Done — 16 Jun 2026

Archived. See `docs/archive/Implementation Plan Phase 1 - Completed.md` for full spec.

---

### Stage 1.11 — Expedition Board & Data Model Review ❌

The current board and data model must be reviewed and updated to match the Expedition Planner spec (see PRD §4.1 and Data Schema §1) before write functionality is built on top.

**Step 1a — Extend PHPUnit tests** for structural wiring (`tests/Unit/Core/CPT_RegistryTest.php`):
- `season` CPT: `register_post_type()` called with correct args and all meta fields registered
- `expedition` CPT: all new meta fields registered (`ems_event_code`, `ems_transport`, `ems_lic_name/email/phone`, `ems_start_location`, `ems_end_location`, `ems_start_time`, `ems_end_time`, `ems_route_info`)
- `ems_type` enum includes `training`
- `team` CPT: `ems_team_number` and `ems_team_code` registered

**Step 1b — Write Gherkin scenarios** (`tests/features/1.11-board.feature`) for observable behaviour:
- Expedition board REST endpoint returns data shaped for the season/event/team hierarchy
- Board returns empty-season state when no events exist

**Step 2 — Review + validate scenarios with user**

**Step 3 — Write failing tests** (PHPUnit for board REST endpoint)

**Step 4 — Implement**:
- Register `season` CPT; update `expedition` and `team` meta in `CPT_Registry`
- `ems_type` enum extended to include `training`
- Fix any blocking bugs found during board review

**Step 5 — Board review checks** (once tests green):
- Verify all current board tab views render with real post-sync data
- Confirm `ems_team_members.user_id` → `ems_osm_explorers.wp_user_id` join works end-to-end
- Assess UX gaps for 1.12; document missing empty-state handling

**Complete when**: All 1.11 Gherkin scenarios pass; CPTs registered with new meta; review notes captured; UX gaps documented.

---

### Stage 1.12 — Expedition Planner Write Logic ❌

Implements full CRUD for seasons, events, and teams plus all assignment operations specified in PRD §4.1.

**Step 1 — Write Gherkin scenarios** before any code. Feature files:
- `tests/features/1.12-seasons.feature` — season CRUD, archiving
- `tests/features/1.12-events.feature` — event creation (code validation, uniqueness within season), edit all fields, delete guard (no teams), OSM event linking
- `tests/features/1.12-teams.feature` — team creation (auto-generated sequential code), delete cascade (last member triggers team deletion), sequential numbering enforcement (no gaps), team size warning (outside 4–7)
- `tests/features/1.12-members.feature` — add member to team, remove member, move member between teams within event, move member between events of same type
- `tests/features/1.12-team-operations.feature` — move team between events (re-codes), duplicate team to another event (new codes), populate qualifier from practice
- `tests/features/1.12-api.feature` — REST endpoint request/response shape, auth requirements, error codes for all endpoints in Data Schema §3.3
- `tests/features/1.12-ui-season-dashboard.feature` — Dashboard shows all events grouped by level; event shows team count and member count; clicking an event expands to show teams; team shows member list with first aid indicators; size-warning badge on teams outside 4–7; empty season shows prompt to create first event
- `tests/features/1.12-ui-event-form.feature` — Create form validates required fields (code, dates); duplicate event code shows inline error; OSM event selector lists synced events; optional fields (LiC, locations, times) can be left blank; saved event appears in dashboard
- `tests/features/1.12-ui-cross-event-view.feature` — Selecting a team shows same members' appearances in other events of same type/level; member with no other assignments shown as "not yet assigned elsewhere"; update assignment in another event reflects immediately
- `tests/features/1.12-ui-explorer-move.feature` — Moving explorer from one team to another updates both team member counts; moving last member from a team removes the team from the view; moving explorer between events of same type works; moving between different types is blocked
- `tests/features/1.12-ui-team-move.feature` — Moving team to another event re-codes team and shows preview before confirm; duplicating team creates new team in target event with same members; populate-from-practice copies all practice teams into qualifier event

**Step 2 — Review + validate scenarios with user**

**Step 3 — Implement via OpenCode RALPH loop** (Qwen3-27B, 64k context). One session per group below — feed `AGENTS.md` + the feature file(s) + only the source files directly in scope:

| Session | Feature files | Classes in scope |
|---|---|---|
| A | `1.12-seasons.feature` + `1.12-events.feature` | `Season_Repository`, `Expedition_Admin_Controller` |
| B | `1.12-teams.feature` + `1.12-members.feature` | `Team_Repository`, `Team_Member_Repository` |
| C | `1.12-team-operations.feature` | `Team_Repository` extensions (move/duplicate/renumber) |
| D | `1.12-api.feature` | REST endpoint tests for all Data Schema §3.3 endpoints |
| E | `1.12-ui-season-dashboard.feature` + `1.12-ui-event-form.feature` | `SeasonDashboard`, `EventForm` |
| F | `1.12-ui-cross-event-view.feature` + `1.12-ui-explorer-move.feature` + `1.12-ui-team-move.feature` | `CrossEventTeamView`, `ExplorerMovePanel`, `TeamMovePanel` |

Session preamble for each: *"Read AGENTS.md. Implement failing tests then production code for [feature file]. Run `docker compose run --rm wordpress vendor/bin/phpunit` (or `npm run test`) after each change. Stop when all tests pass."*

**Step 4 — Refactor** keeping all tests green.

**Complete when**: All Gherkin scenarios implemented and green (PHPUnit + Vitest); Season Dashboard renders full season/event/team/member hierarchy; all assignment operations work end-to-end.

---

### Stage 1.13 — Training Status Fallback ❌

When a Tutor LMS record is linked to a parent `user_id` rather than the explorer's, fall back to `ems_scout_id` anchor.

**Step 1 — Write Gherkin scenarios** (`tests/features/1.13-training-fallback.feature`):
- Explorer record found via direct `user_id` match — returns status
- Explorer record not found via `user_id`; found via `ems_scout_id` fallback — returns status
- Explorer record not found via either path — returns `null`
- Admin view displays correct status for parent-trained explorers

**Step 2 — Review + validate scenarios with user**

**Step 3 — Write failing tests** (PHPUnit)

**Step 4 — Implement** fallback logic in `TutorLMS_Client`

**Complete when**: All three Gherkin paths green; admin view shows correct status.

---

### Stage 1.14 — Column Mapper Repurpose (OSM Write-back) ❌

The existing Column Mapper React component will be replaced with a simpler per-section configuration form for the EMS → OSM write-back direction.

**Context from 1.9:** Managed sections are now stored without `extraid`. The flexi-record association is configured here instead.

**Step 1 — Write Gherkin scenarios** (`tests/features/1.14-flexi-mapper.feature`):
- `GET ems/v1/flexi-structure/{section_id}` returns flexi-record columns for a section
- Endpoint requires admin authentication; returns 403 otherwise
- Mapping saved per section as `ems_osm_field_map` option with correct structure
- Saving with a missing required field returns a validation error
- Config form renders column dropdowns populated from flexi-record structure
- Saved mapping is pre-populated on revisit

**Step 2 — Review + validate scenarios with user**

**Step 3 — Write failing tests** (PHPUnit for endpoint; Vitest for form component)

**Step 4 — Implement**:
- Replace existing Column Mapper component with simplified per-section form (React)
- Implement `GET ems/v1/flexi-structure/{section_id}` REST endpoint
- Persist mapping as `ems_osm_field_map` option: `{section_id: {flexi_id, field_map: {ems_field: column_id}}}`

**Complete when**: All Gherkin scenarios green; mapping configurable and saved per section.

---

## Phase 1 Complete When
- All 1.10–1.14 tests pass (`vendor/bin/phpunit`, `npm run test`)
- `live-auth-only` and `live-limited` modes working against real OSM; sync result displayed (1.10)
- Expedition board reviewed and any blocking bugs fixed (1.11)
- Admin can create/edit expeditions and reassign explorers (1.12)
- Training fallback logic tested and passing (1.13)
- Column mapper repurposed for write-back config (1.14)

---

## Source Directory Map — Active Files

Files built in completed stages: see archive. New/modified files for remaining stages:

| File | Class/Purpose | Stage |
|---|---|---|
| `src/Plugin.php` | Fetch-sections live OAuth; branches for new sync modes; 429/blocked handling | 1.10 |
| `src/Integrations/OSM_Reference_Sync.php` | Return sync result struct; support member limit | 1.10 |
| `src/Core/CPT_Registry.php` | Register `season` CPT; update `expedition` and `team` meta registration | 1.11 |
| `src/Data/Season_Repository.php` | Create/list/archive seasons | 1.12 |
| `src/Admin/Expedition_Admin_Controller.php` | Create/edit/delete events, manage unassigned pool | 1.12 |
| `src/Data/Team_Repository.php` | Sequential team code logic, move/duplicate team | 1.12 |
| `src/Data/Team_Member_Repository.php` | Add/remove/move members, cascade delete empty team | 1.12 |
| `resources/js/admin/expedition-board/ExpeditionBoard.tsx` | Board review + Season Dashboard entry point | 1.11/1.12 |
| `resources/js/admin/expedition-board/SeasonDashboard.tsx` | Compact at-a-glance season view | 1.12 |
| `resources/js/admin/expedition-board/EventForm.tsx` | Create/edit event form | 1.12 |
| `resources/js/admin/expedition-board/CrossEventTeamView.tsx` | Cross-event team/member view | 1.12 |
| `resources/js/admin/expedition-board/ExplorerMovePanel.tsx` | Move explorer between teams | 1.12 |
| `resources/js/admin/expedition-board/TeamMovePanel.tsx` | Move/duplicate team between events | 1.12 |
| `resources/js/admin/column-mapper/` | Replace with write-back config form | 1.14 |

---

## §8 Deferred Items

### 8.3 Event/attendance upsert tests *(implemented, untested)*

`sync_events_and_attendance()` in `OSM_Reference_Sync` upserts to `ems_osm_events` and `ems_osm_event_attendance` and calls `get_event_attendance()`. The implementation is complete but dedicated upsert-correctness tests have not been written. Add these when extending Step 0 mock data.

### 8.4 Patrol reference data *(architecture decision pending)*

Patrol names/IDs are denormalised on `ems_osm_explorers.patrol`. Sufficient for current views. Revisit if patrol-leader or cross-section patrol queries are needed — may warrant a dedicated `ems_osm_patrols` table.
