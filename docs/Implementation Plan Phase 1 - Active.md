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
| 1.7 | Admin Read Views | ✅ Done — 15 Jun 2026 |
| 1.8 | Diagnostics + Reference Data Display | ✅ Done — 15 Jun 2026 |
| 1.9 | OSM Auth Test Modes + Sync Progress Feedback | ❌ Not started |
| 1.10 | Expedition Board deep review | ❌ Not started |
| 1.11 | Expedition write logic + Explorer Assignment | ❌ Not started |
| 1.12 | Training Status Fallback | ❌ Not started |
| 1.13 | Column Mapper repurpose (OSM write-back) | ❌ Not started |

**Tests**: 174 PHP / 332 assertions green. 16 JS Vitest green.

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

### ✅ Stage 1.7 — Admin Read Views *(complete — 15 Jun 2026)*

**`hydrate_member_data()` bug fixed**: now reads from `ems_osm_explorers` via `wp_user_id` instead of `wp_usermeta`.

**Three new REST endpoints** added to `Admin_View_Controller`:
- `GET ems/v1/explorer/{scout_id}` — name, patrol, email, training summary, `last_synced`
- `GET ems/v1/team/{team_id}` — members hydrated from `ems_osm_explorers`, `first_aid_covered` flag, `last_synced`
- `GET ems/v1/patrol/{patrol}` — all explorers in the patrol ordered by name, `last_synced`

**PHP tests** (6 new): explorer found/404, team with/without first aid, patrol with results/empty.

**React** (`ExpeditionBoard.tsx`): "By Unit" tab renamed to "By Patrol"; `downloadCsv()` utility added; Download CSV button on Explorer, Team, and Patrol tabs.

**Vitest tests** (8 new): loading state, error state, never-synced, empty states per tab, CSV button presence on each tab.

**168 PHP / 322 assertions. 16 JS Vitest.**

---

### ✅ Stage 1.8 — Diagnostics + Reference Data Display *(complete — 15 Jun 2026)*

**`Diagnostic_Panel`** split into `get_system_html()` (always populated) and `get_user_html()` (OIDC users only); `get_html()` retained as backward-compat alias. System panel shows: API mode, client ID configured (yes/no), managed sections, last sync timestamp, DB row counts (explorers/events/attendance), rate limit headers.

**`render_dashboard()`** cleaned up — diagnostic panel removed from Expedition Board page.

**`render_reference_page()`** replaced with four WP nav-tabs (active tab via `?tab=` query param):
- **Explorers** — existing table unchanged
- **Patrols** — grouped summary (patrol name + member count)
- **Events** — events + attendance count JOIN
- **Diagnostics** — system panel + per-user OIDC section (when set)

**6 new PHP tests** (Diagnostic_Panel system diagnostics + backward-compat alias). **174 PHP / 332 assertions.**

---

### Stage 1.9 — OSM Auth Test Modes + Sync Progress Feedback ❌

#### Live OSM auth — current state

`OSM_Sync_Auth_Handler` is **fully built**: initiates OAuth2 code flow → exchanges code for token → fires sync callback → redirects back. Wired in `Plugin.php`: `ems_api_mode=live` uses it; `mock` bypasses it.

**Before live auth works in production:**
- OAuth credentials (`ems_osm_client_id` / `ems_osm_client_secret`) must be configured in Settings
- Redirect URI `admin-post.php?action=ems_osm_callback` must be whitelisted in OSM developer portal
- `wp_die()` error paths in `handle_callback()` should redirect gracefully instead

#### OSM auth test modes

Two new modes in `ems_api_mode` to allow incremental live testing without a full sync:

**Mode: `live-auth-only`**
- Full OAuth2 flow → `get_data_payload()` only — no member/event sync
- Stores raw parsed payload as transient `ems_last_payload_dump` (1h)
- Redirects to OSM Reference page → Diagnostics tab shows: `userid`, `access_type`, `section_ids`, `terms`, `member_access` summary
- Purpose: verify credentials, confirm section IDs, check access scope

**Mode: `live-limited`**
- Full OAuth2 flow → capped sync: first managed section only, first N members (`ems_sync_limit`, default 5)
- Each API call appended to `ems_last_sync_log` transient: timestamp, call type, HTTP status, rate-limit headers remaining
- Redirects to OSM Reference page showing partial sync result + full call log
- Purpose: test progress reporting and rate limiting against real data

**Implementation:**
- Add `live-auth-only` and `live-limited` to `ems_api_mode` dropdown in `Settings_Page`
- Add `ems_sync_limit` integer field (shown only for `live-limited`)
- `Plugin.php` callback handler: reads `ems_api_mode` after token exchange to branch behaviour
- Both new modes redirect to `OSM_Sync_Auth_Handler::initiate()` — same OAuth flow as `live`

#### Sync progress and import feedback

Currently a silent round-trip with a single success/failure notice.

**Tasks:**
- `OSM_Reference_Sync::sync()` returns a result struct: `{members_upserted, members_failed, events_upserted, events_failed, errors[]}`
- Store as transient `ems_last_sync_result` (24h)
- `render_reference_page()`: display sync summary panel above tabs — member count, event count, error count, collapsible error list
- For `live-limited`: display full per-call log (from `ems_last_sync_log`) in the Diagnostics tab
- Replace `wp_die()` OAuth error paths with redirects to reference page with `?error=` param and admin notice

**Complete when**: `live-auth-only` displays parsed payload; `live-limited` runs capped sync + shows call log; full `live` sync stores and displays result summary; error paths redirect gracefully.

---

### Stage 1.10 — Expedition Board Deep Review ❌

The current board exists but needs a thorough review before building write functionality on top of it.

**Review tasks:**
- Verify all four tab views render correctly with real post-sync data (not just mock)
- Confirm `ems_team_members.user_id` → `ems_osm_explorers.wp_user_id` join works end-to-end (is `wp_user_id` actually populated after OIDC logins?)
- Assess whether the expedition/team data model (custom post types) is the right fit or needs revisiting
- Review UX: is the expedition board layout clear and usable? What's missing?
- Identify any data that should be on the board but isn't (e.g. expedition status, route deadline, LiC contact)
- Identify missing empty-state handling

**Complete when**: Board review notes captured; any blocking bugs fixed; UX gaps documented for 1.11.

---

### Stage 1.11 — Expedition Write Logic + Explorer Assignment ❌

**TDD Tasks:**
- `Expedition_Admin_Controller` — create expedition (validates code format, rejects duplicate), edit (LiC, WhatsApp, route info, dates), assign explorer to expedition, reassign between teams
- REST endpoints for the above (with tests)
- React "Create/Edit Expedition" form — validation, submission, edit pre-population
- React "Explorer Assignment" view — move explorers from unassigned pool into teams

**Complete when**: Controller tests pass; form component tests pass; assignment component tests pass.

---

### Stage 1.12 — Training Status Fallback ❌

When a Tutor LMS record is linked to a parent `user_id` rather than the explorer's, fall back to `ems_scout_id` anchor.

**TDD Tasks**: match found via fallback; no record found returns `null`.

**Complete when**: Both fallback paths tested; admin view shows correct status for parent-trained explorers.

---

### Stage 1.13 — Column Mapper Repurpose (OSM Write-back) ❌

The Column Mapper was originally built for flexible import mapping (flexi-record columns → EMS fields). The write-back direction (EMS → OSM) is simpler: EMS fields are fixed; the OSM flexi-record column IDs just need to be configured once and persisted.

**Decision:** The current drag-and-drop flexibility is likely overkill for write-back. Replace with a simpler configuration form:
- Per managed section: select which flexi-record, then map each EMS field (expedition code, team code, event status, route info) to the corresponding column ID from the flexi-record structure
- The mapper fetches the flexi-record structure via `GET ems/v1/flexi-structure/{section_id}` and presents the column names as a dropdown

**Tasks:**
- Decide whether to adapt the existing Column Mapper component or replace it
- Implement `GET ems/v1/flexi-structure/{section_id}` REST endpoint
- Build the simplified config form (React)
- Persist the mapping as `ems_osm_field_map` option
- Wire into the OSM write-back flow (Phase 2)

**Complete when**: Mapping can be configured and saved; flexi-record structure endpoint tested.

---

## Phase 1 Complete When
- All 1.8–1.13 tests pass (`vendor/bin/phpunit`, `npm run test`)
- Diagnostic panel shows useful content for any admin login; moved to OSM Reference page (1.8)
- Explorers / Patrols / Events visible as tabs on OSM Reference page (1.8)
- `live-auth-only` and `live-limited` modes working against real OSM; sync result displayed (1.9)
- Expedition board reviewed and any blocking bugs fixed (1.10)
- Admin can create/edit expeditions and reassign explorers (1.11)
- Training fallback logic tested and passing (1.12)
- Column mapper repurposed for write-back config (1.13)

---

## Source Directory Map — Active Files

Files built in completed stages: see archive. New/modified files for remaining stages:

| File | Class/Purpose | Stage |
|---|---|---|
| `src/Admin/Diagnostic_Panel.php` | System-level diagnostics; relocate to OSM Reference page | 1.8 |
| `src/Admin/Admin_Page.php` | Tabbed reference page (Explorers/Patrols/Events); move diagnostic | 1.8 |
| `src/Admin/Settings_Page.php` | Add `live-auth-only`, `live-limited`, `ems_sync_limit` fields | 1.9 |
| `src/Plugin.php` | Branches for new sync modes in callback handler | 1.9 |
| `src/Integrations/OSM_Reference_Sync.php` | Return sync result struct; support member limit | 1.9 |
| `src/Admin/Expedition_Admin_Controller.php` | Create/edit expeditions, assign explorers | 1.11 |
| `resources/js/admin/expedition-board/ExpeditionBoard.tsx` | Board review + write-action UI | 1.10/1.11 |

---

## §8 Deferred Items

### ~~8.1 `hydrate_member_data()` inconsistency~~ ✅ Resolved (Stage 1.7)

Fixed: now reads from `ems_osm_explorers` via `wp_user_id`.

### ~~8.2 Mock data: distinct emails per member~~ ✅ Resolved (Step 0)

Fixed: keyed map + `Mock_Driver` lookup by scout_id.

### 8.3 Event/attendance upsert tests *(implemented, untested)*

`sync_events_and_attendance()` in `OSM_Reference_Sync` upserts to `ems_osm_events` and `ems_osm_event_attendance` and calls `get_event_attendance()`. The implementation is complete but dedicated upsert-correctness tests have not been written. Add these when extending Step 0 mock data.

### 8.4 Patrol reference data *(architecture decision pending)*

Patrol names/IDs are denormalised on `ems_osm_explorers.patrol`. Sufficient for current views. Revisit if patrol-leader or cross-section patrol queries are needed — may warrant a dedicated `ems_osm_patrols` table.
