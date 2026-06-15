# Implementation Plan Phase 1 — Active Work

> Completed stages (1.1–1.6, foundations, environment) archived to:
> `docs/archive/Implementation Plan Phase 1 - Completed.md`
>
> Phase 2+ plan: `docs/Implementation Plan Phase 2+.md`

---

## Current Status — 15 June 2026

| Stage | Description | Status |
|---|---|---|
| Foundations + 1.1–1.6 | All completed | ✅ See archive |
| Step 0 | Anonymised mock data generation | ✅ Done — 15 Jun 2026 |
| 1.7 | Admin Read Views | ⚠️ Partial — Expedition Board only |
| 1.8 | Expedition_Admin_Controller | ❌ Not started |
| 1.9 | Training Status Fallback | ❌ Not started |

**Tests**: 162 PHP / 304 assertions green. 8 JS Vitest green.

---

## Immediate Next Steps (in order)

### ✅ Step 0 — Anonymised Mock Data Generation *(complete — 15 Jun 2026)*

`bin/generate-mock-data.py` written and validated. Regenerates all `tests/mocks/` files deterministically (`random.seed(42)`). Re-run at any time to refresh from `mockdata/`.

**Files generated** (9 total):
- `osm-list-of-members.json` — 127 members, Scottish fictitious names, scout IDs `3417257+`, patrol IDs `99200+`
- `osm-member-detail.json` — keyed map `{scout_id: {email, parent_email}}`, `scout.{id}@example-ems.test`
- `osm-patrols.json` — mock patrol IDs matching member list
- `osm-events.json` — 2 events, IDs `40001/40002`
- `osm-event-attendance.json` — all 127 members, varied `yes`/`no`/`""` attending
- `osm-flexi-record-structure.json` — mock section `99001`, extraid `99848`
- `osm-flexi-record-data.json` — all 127 members, varied `f_9`–`f_18` flexi fields
- `osm-get-data-payload-explorer.json` — userid `20001`, `member_access` scout `30001` in sections `99001`/`99002`
- `osm-get-data-payload-parent.json` — userid `20002`, children `30001`/`30002`

**`Mock_Driver::get_member_detail()`** updated: looks up by `$scout_id` in keyed map, wraps in raw `getData` structure for `parse_member_detail`.

**3 test files updated** to use predictable email format. **162/162 tests green**.

---

### Stage 1.7 (remaining) — Admin Read Views

Expedition Board REST endpoint and React component are complete. Three views remaining.

**Bug to fix first**: `Admin_View_Controller::hydrate_member_data()` reads `wp_usermeta` (`ems_first_name` etc.) but Stage 1.4 writes to `ems_osm_explorers`, not user meta. Members assigned to teams must be hydrated from `ems_osm_explorers` via `scout_id`.

**TDD Tasks**:
- `GET ems/v1/explorer/{scout_id}` — explorer detail: name, patrol, email, expedition/team assignments, training summary, `last_synced`
- `GET ems/v1/team/{team_id}` — team members + first aid coverage flag, `last_synced`
- `GET ems/v1/patrol/{patrol}` — all explorers in a patrol across expeditions, `last_synced`
- Every payload includes `last_synced` from `ems_osm_last_sync` option (ISO 8601 or `null`)

**React views** (add as tabs or sub-pages under OSM Reference):
- **By Explorer**: expedition/team assignment, training summary, first aid declaration
- **By Team**: member list, first aid coverage indicator
- **By Patrol**: all explorers in a patrol across expeditions

**Also**: "Download CSV" on each view. Vitest tests for each component (data, empty state, loading, "Never synced").

**Complete when**: All three REST endpoints tested; all three React views tested; `hydrate_member_data()` bug fixed; CSV tests pass.

---

### Stage 1.8 — EMS-Internal Update Logic ❌

**TDD Tasks**:
- `Expedition_Admin_Controller` — create expedition (validates code format, rejects duplicate), edit (LiC, WhatsApp, route info, dates), assign explorer to expedition, reassign between teams
- React "Create/Edit Expedition" form — validation, submission, edit pre-population
- React "Explorer Assignment" view — move explorers from unassigned pool into teams

**Complete when**: Controller tests pass; form component tests pass; assignment component tests pass.

---

### Stage 1.9 — Training Status Fallback ❌

When a Tutor LMS record is linked to a parent `user_id` rather than the explorer's, fall back to `ems_scout_id` anchor.

**TDD Tasks**: match found via fallback; no record found returns `null`.

**Complete when**: Both fallback paths tested; admin view shows correct status for parent-trained explorers.

---

## Phase 1 Complete When
- All Stage 1.7–1.9 tests pass (`vendor/bin/phpunit`, `npm run test`)
- Admin can view all data (four view modes) and download CSV
- Admin can create/edit expeditions and reassign explorers between teams
- Training fallback logic tested and passing

---

## Source Directory Map — Active Files

Files built in completed stages: see archive. New files to build:

| File | Class | Stage |
|---|---|---|
| `bin/generate-mock-data.py` | — | Step 0 |
| `src/Admin/Expedition_Admin_Controller.php` | `EMS\Admin\Expedition_Admin_Controller` | 1.8 |
| `src/REST/Expedition_REST_Controller.php` | `EMS\REST\Expedition_REST_Controller` | 1.7 |

---

## §8 Deferred Items

### 8.1 `hydrate_member_data()` inconsistency *(blocks Stage 1.7)*

`Admin_View_Controller::hydrate_member_data()` reads `wp_usermeta` keys (`ems_first_name`, `ems_last_name`, `ems_scout_id`, `ems_unit`) but Stage 1.4 writes to `ems_osm_explorers`, not user meta. Fix: join `ems_team_members` → `ems_osm_explorers` on `scout_id` instead of reading user meta.

### 8.2 Mock data: distinct emails per member *(blocks meaningful sync tests)*

`Mock_Driver::get_member_detail()` returns the same static email regardless of scout_id. Fix in Step 0: keyed map + `Mock_Driver` lookup by scout_id. Email format: `scout.{id}@example-ems.test`.

### 8.3 Event/attendance upsert tests *(implemented, untested)*

`sync_events_and_attendance()` in `OSM_Reference_Sync` upserts to `ems_osm_events` and `ems_osm_event_attendance` and calls `get_event_attendance()`. The implementation is complete but dedicated upsert-correctness tests have not been written. Add these when extending Step 0 mock data.

### 8.4 Patrol reference data *(architecture decision pending)*

Patrol names/IDs are denormalised on `ems_osm_explorers.patrol`. Sufficient for current views. Revisit if patrol-leader or cross-section patrol queries are needed — may warrant a dedicated `ems_osm_patrols` table.
