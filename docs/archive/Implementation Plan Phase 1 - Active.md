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
| 1.11                  | Expedition Board deep review                   | ✅ Done — 23 Jun 2026 |
| 1.12                  | Expedition write logic + Explorer Assignment   | ✅ Done — 23 Jun 2026 |
| 1.13                  | Training Status Fallback                       | ❌ Not started        |
| 1.14                  | Column Mapper repurpose (OSM write-back)       | ❌ Not started        |

**Tests**: 259 PHP / 526 assertions green. 37 JS Vitest green.

**Live OAuth status**: Working end-to-end as of 16 Jun 2026. Fixes this session: `wp_redirect()` for external OAuth redirect; correct `ext/` API endpoint paths; `sectionname` property for display names; section `type` stored and displayed; error handling for OSM error params and `getDataPayload` failures.

---

## Active Stages

### Stage 1.10 — OSM Auth Test Modes + Sync Progress Feedback ✅ Done — 16 Jun 2026

Archived. See `docs/archive/Implementation Plan Phase 1 - Completed.md` for full spec.

---

### Stage 1.11 — Expedition Board & Data Model Review ✅ Done — 23 Jun 2026

The current board and data model must be reviewed and updated to match the Expedition Planner spec (see PRD §4.1 and Data Schema §1) before write functionality is built on top.

**Step 1a — Extend PHPUnit tests** ✅ Done (`tests/Unit/Core/CPT_RegistryTest.php`):
- `season` CPT: `register_post_type()` called with correct args and all meta fields registered
- `expedition` CPT: all new meta fields registered (`ems_event_code`, `ems_transport`, `ems_lic_name/email/phone`, `ems_start_location`, `ems_end_location`, `ems_start_time`, `ems_end_time`, `ems_route_info`)
- `ems_type` enum includes `training`
- `team` CPT: `ems_team_number` and `ems_team_code` registered

**Step 1b — Write Gherkin scenarios** ✅ Done (`tests/features/1.11-board.feature`):
- Expedition board REST endpoint returns data shaped for the season/event/team hierarchy
- Board returns empty-season state when no events exist

**Step 2 — Review + validate scenarios with user** ✅ Done

**Step 3 — Write failing tests** ✅ Done — board hierarchy + empty-season covered by `Expedition_Admin_ControllerTest::test_get_board_*`

**Step 4 — Implement** ✅ Done:
- `season` CPT registered; `expedition` and `team` meta updated in `CPT_Registry` (`ems_event_code`, `ems_team_number`, etc.)
- `ems_type` enum extended to include `training`
- `get_board()` in `Expedition_Admin_Controller` returns the `seasons → events → teams → members` hierarchy with `member_count` and `size_warning`

**Step 5 — User review of board shape** ✅ Done

**Complete when**: All 1.11 Gherkin scenarios pass; CPTs registered with new meta; board endpoint returns correct hierarchy and empty-season state. ✅ All met.

---

### Stage 1.12 — Expedition Planner Write Logic ✅ Done — 23 Jun 2026

Implements full CRUD for seasons, events, and teams plus all assignment operations specified in PRD §4.1.

**Step 1 — Write Gherkin scenarios** ✅ All feature files written:

| File | Happy paths | Edge cases |
|---|---|---|
| `tests/features/1.12-seasons.feature` | Create, list (newest first), archive | Duplicate year, archive non-existent, empty list |
| `tests/features/1.12-events.feature` | Create (required + optional blank), edit, link OSM event, delete (no teams), code unique across seasons | Duplicate code in season, delete with teams, invalid enum, missing required fields |
| `tests/features/1.12-teams.feature` | First team gets suffix 1, second gets 2, independent across events, delete empty | Size warning (< 4 or > 7), cascade delete on last member, renumber on gap, block direct delete with members |
| `tests/features/1.12-members.feature` | Add to team, remove (others remain), move within event, move across same-type events | Duplicate add, move to incompatible type, last member cascade, non-existent explorer |
| `tests/features/1.12-team-operations.feature` | Move re-codes + renumbers source, move assigns next code in target, duplicate copies members + leaves original, populate-from-practice copies all teams | Move to incompatible type blocked, move to same event blocked |
| `tests/features/1.12-api.feature` | 201/200 response shapes for all endpoints, correct body fields | 403 auth, 404 not found, 409 conflict (teams/members), 422 incompatible type |
| `tests/features/1.12-ui-season-dashboard.feature` | Events grouped by level, team/member counts, expand to teams, member names visible | Empty season prompt, size warning badge, no seasons prompt |
| `tests/features/1.12-ui-event-form.feature` | All fields present, submit required-only, optional fields blank, OSM selector populated, edit pre-populates | Duplicate code inline error, missing code/start date/end date validation |
| `tests/features/1.12-ui-cross-event-view.feature` | Member overlap shown, update assignment reflects immediately, correct events shown (same type only) | "Not yet assigned" state, no other same-type events empty state, no team selected prompt |
| `tests/features/1.12-ui-explorer-move.feature` | Move within event updates counts, move across same-type events, target dropdown correct | Last member removes team, incompatible-type teams not shown |
| `tests/features/1.12-ui-team-move.feature` | Move preview shows re-code, confirm relocates team, duplicate preview + confirm, populate copies all teams | Populate warns if target has teams, incompatible type not in dropdown, current event not in dropdown |

**Step 2 — Review + validate scenarios with user** ✅ Done

**Step 3 — Implement** ✅ Done. Sessions A–F all complete:

> **Test tooling**: Backend feature files → PHPUnit + Brain Monkey; UI feature files → Vitest + React Testing Library with mocked API hooks (no Playwright E2E this stage).

| Session | Status | Classes delivered |
|---|---|---|
| A | ✅ | `Season_Repository`, `Expedition_Admin_Controller` (seasons + events endpoints) |
| B | ✅ | `Team_Repository`, `Team_Member_Repository` (sequential numbering, add/remove/cascade) |
| C | ✅ | `Team_Repository` `move()` / `duplicate()` / `renumber_event()` / `populate_from_event()` |
| D | ✅ | `Expedition_Admin_ControllerTest` — 201/200/400/404/409/422 across all §3.3 endpoints |
| E | ✅ | `SeasonDashboard.tsx`, `EventForm.tsx` + `useBoard.ts` hook |
| F | ✅ | `CrossEventTeamView.tsx`, `ExplorerMovePanel.tsx`, `TeamMovePanel.tsx` + `boardUtils.ts` |

**Step 4 — Refactor + wiring** ✅ Done:
- `ExpeditionBoard.tsx` rewired to the new `seasons` payload via `useBoard`; mounts `SeasonDashboard` + the three management panels as tabs. Legacy explorer/patrol/CSV views removed.
- `Expedition_Admin_Controller` registered in `Plugin.php`; `OSM_Explorer_Repository` added for explorer identity lookups.
- `WP_REST_Request` stub added to `tests/bootstrap.php`.

**Complete when**: All Gherkin scenarios implemented and green (PHPUnit + Vitest); Season Dashboard renders full season/event/team/member hierarchy; all assignment operations work end-to-end. ✅ All met — 259 PHP / 526 assertions, 37 Vitest green; `tsc --noEmit` clean.

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
| `src/Data/OSM_Explorer_Repository.php` | Explorer identity lookups (scout_id / wp_user_id) | 1.12 |
| `src/Admin/Expedition_Admin_Controller.php` | Create/edit/delete events, manage unassigned pool | 1.12 |
| `src/Data/Team_Repository.php` | Sequential team code logic, move/duplicate team | 1.12 |
| `src/Data/Team_Member_Repository.php` | Add/remove/move members, cascade delete empty team | 1.12 |
| `resources/js/admin/expedition-board/ExpeditionBoard.tsx` | Board review + Season Dashboard entry point | 1.11/1.12 |
| `resources/js/admin/expedition-board/SeasonDashboard.tsx` | Compact at-a-glance season view | 1.12 |
| `resources/js/admin/expedition-board/EventForm.tsx` | Create/edit event form | 1.12 |
| `resources/js/admin/expedition-board/CrossEventTeamView.tsx` | Cross-event team/member view | 1.12 |
| `resources/js/admin/expedition-board/ExplorerMovePanel.tsx` | Move explorer between teams | 1.12 |
| `resources/js/admin/expedition-board/TeamMovePanel.tsx` | Move/duplicate team between events | 1.12 |
| `resources/js/admin/expedition-board/useBoard.ts` | Board data-fetch hook (mocked in Vitest) | 1.12 |
| `resources/js/admin/expedition-board/boardUtils.ts` | Cross-event helpers (same-type filter, member lookup, code preview) | 1.12 |
| `resources/js/admin/column-mapper/` | Replace with write-back config form | 1.14 |

---

## §8 Deferred Items

### 8.3 Event/attendance upsert tests *(implemented, untested)*

`sync_events_and_attendance()` in `OSM_Reference_Sync` upserts to `ems_osm_events` and `ems_osm_event_attendance` and calls `get_event_attendance()`. The implementation is complete but dedicated upsert-correctness tests have not been written. Add these when extending Step 0 mock data.

### 8.4 Patrol reference data *(architecture decision pending)*

Patrol names/IDs are denormalised on `ems_osm_explorers.patrol`. Sufficient for current views. Revisit if patrol-leader or cross-section patrol queries are needed — may warrant a dedicated `ems_osm_patrols` table.
