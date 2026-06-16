# Implementation Plan Phase 1 — Active Work

> Completed stages (1.1–1.6, foundations, environment) archived to:
> `docs/archive/Implementation Plan Phase 1 - Completed.md`
>
> Phase 2+ plan: `docs/Implementation Plan Phase 2+.md`

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

### Stage 1.11 — Expedition Board Deep Review ❌

The current board exists but needs a thorough review before building write functionality on top of it.

**Review tasks:**
- Verify all four tab views render correctly with real post-sync data (not just mock)
- Confirm `ems_team_members.user_id` → `ems_osm_explorers.wp_user_id` join works end-to-end (is `wp_user_id` actually populated after OIDC logins?)
- Assess whether the expedition/team data model (custom post types) is the right fit or needs revisiting
- Review UX: is the expedition board layout clear and usable? What's missing?
- Identify any data that should be on the board but isn't (e.g. expedition status, route deadline, LiC contact)
- Identify missing empty-state handling

**Complete when**: Board review notes captured; any blocking bugs fixed; UX gaps documented for 1.12.

---

### Stage 1.12 — Expedition Write Logic + Explorer Assignment ❌

**TDD Tasks:**
- `Expedition_Admin_Controller` — create expedition (validates code format, rejects duplicate), edit (LiC, WhatsApp, route info, dates), assign explorer to expedition, reassign between teams
- REST endpoints for the above (with tests)
- React "Create/Edit Expedition" form — validation, submission, edit pre-population
- React "Explorer Assignment" view — move explorers from unassigned pool into teams

**Complete when**: Controller tests pass; form component tests pass; assignment component tests pass.

---

### Stage 1.13 — Training Status Fallback ❌

When a Tutor LMS record is linked to a parent `user_id` rather than the explorer's, fall back to `ems_scout_id` anchor.

**TDD Tasks**: match found via fallback; no record found returns `null`.

**Complete when**: Both fallback paths tested; admin view shows correct status for parent-trained explorers.

---

### Stage 1.14 — Column Mapper Repurpose (OSM Write-back) ❌

The existing Column Mapper React component (drag-and-drop flexi-record import mapping) will be replaced with a simpler per-section configuration form for the EMS → OSM write-back direction. EMS fields are fixed; only the OSM flexi-record column IDs need to be configured once per section.

**Context from 1.9:** Managed sections are now stored without `extraid`. The flexi-record association is configured here instead.

**Per-section config:**
- Select which flexi-record applies to this section (fetched via `GET ems/v1/flexi-structure/{section_id}`)
- Map each EMS write-back field (expedition code, team code, event status, route info) to the corresponding column ID — presented as a dropdown of column names from the flexi-record structure

**Tasks:**
- Replace existing Column Mapper component with new simplified form (React)
- Implement `GET ems/v1/flexi-structure/{section_id}` REST endpoint (calls OSM API, requires auth token — determine flow)
- Persist mapping as `ems_osm_field_map` option: `{section_id: {flexi_id, field_map: {ems_field: column_id}}}`
- Wire into OSM write-back flow (Phase 2)

**Complete when**: Mapping can be configured and saved per section; flexi-record structure endpoint tested.

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
| `src/Admin/Expedition_Admin_Controller.php` | Create/edit expeditions, assign explorers | 1.12 |
| `resources/js/admin/expedition-board/ExpeditionBoard.tsx` | Board review + write-action UI | 1.11/1.12 |
| `resources/js/admin/column-mapper/` | Replace with write-back config form | 1.14 |

---

## §8 Deferred Items

### 8.3 Event/attendance upsert tests *(implemented, untested)*

`sync_events_and_attendance()` in `OSM_Reference_Sync` upserts to `ems_osm_events` and `ems_osm_event_attendance` and calls `get_event_attendance()`. The implementation is complete but dedicated upsert-correctness tests have not been written. Add these when extending Step 0 mock data.

### 8.4 Patrol reference data *(architecture decision pending)*

Patrol names/IDs are denormalised on `ems_osm_explorers.patrol`. Sufficient for current views. Revisit if patrol-leader or cross-section patrol queries are needed — may warrant a dedicated `ems_osm_patrols` table.
