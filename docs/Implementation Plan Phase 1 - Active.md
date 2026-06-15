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
| 1.8 | Sync UX + Diagnostics + Reference Data Display | ❌ Not started |
| 1.9 | Expedition Board deep review | ❌ Not started |
| 1.10 | Expedition write logic + Explorer Assignment | ❌ Not started |
| 1.11 | Training Status Fallback | ❌ Not started |
| 1.12 | Column Mapper repurpose (OSM write-back) | ❌ Not started |

**Tests**: 168 PHP / 322 assertions green. 16 JS Vitest green.

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

### Stage 1.8 — Sync UX, Diagnostics, Reference Data Display ❌

#### Live OSM Auth — current state

`OSM_Sync_Auth_Handler` is **fully built**: initiates OAuth2 code flow → redirects to OSM → exchanges code for token → fires sync callback → redirects back with success. Wired in `Plugin.php`: `ems_api_mode=live` uses it; `mock` mode bypasses it.

**What still needs doing before live auth works in production:**
- `ems_osm_client_id` / `ems_osm_client_secret` must be configured in Settings (Settings page exists but these fields need verifying)
- OSM app registration: redirect URI `admin-post.php?action=ems_osm_callback` must be whitelisted in the OSM developer portal
- No end-to-end smoke test has run against a real OSM sandbox
- Error handling in `handle_callback()` calls `wp_die()` — should redirect to a graceful error page instead

#### OSM auth test modes

The "Sync from OSM" button on the OSM Reference page is the entry point for all OSM auth. In `Plugin.php`, the `admin_post_ems_sync_osm` handler branches on `ems_api_mode`: `mock` runs the sync immediately; `live` calls `OSM_Sync_Auth_Handler::initiate()` to begin the OAuth2 code flow. The callback handler (`admin_post_ems_osm_callback`) exchanges the code, receives the token, then calls `OSM_Reference_Sync::sync()`.

Two new modes are needed in `ems_api_mode` (or a separate `ems_sync_mode` option) to allow incremental live testing:

**Mode: `live-auth-only`**
- Initiates the full OAuth2 flow identically to `live`
- After receiving the token, calls `get_data_payload()` only — no member/event sync
- Stores the raw payload as transient `ems_last_payload_dump` (expires 1h)
- Redirects to OSM Reference page and displays the full parsed payload: `userid`, `access_type`, `section_ids`, `terms`, `member_access` summary — so the admin can verify auth is working and the data structure is as expected before committing to a full sync
- Useful for: verifying OAuth credentials, confirming section IDs, checking access scope

**Mode: `live-limited`**
- Initiates full OAuth2 flow
- After auth, runs a capped sync: fetches members for the first managed section only, limited to the first N members (configurable via `ems_sync_limit`, default 5)
- Each API call logs to `ems_last_sync_log` transient with timestamp + call type + HTTP status + rate limit headers remaining
- Redirects to OSM Reference page showing the partial sync result + full call log
- Useful for: testing progress reporting, verifying rate limiting behaviour, confirming member detail parsing works against live data

**Implementation:**
- Add `live-auth-only` and `live-limited` to the `ems_api_mode` dropdown in `Settings_Page`
- Add `ems_sync_limit` field (integer, default 5) shown only when `live-limited` is selected
- In `Plugin.php` `admin_post_ems_sync_osm` handler: add branches for these two modes — both redirect to `OSM_Sync_Auth_Handler::initiate()`; the mode is read again in the callback to determine what to do after token exchange
- The call log from `live-limited` feeds directly into the sync result display and diagnostic panel built in the rest of this stage

#### Sync progress and import feedback

Currently the sync is a silent HTTP round-trip with a single success/failure notice on redirect. The admin has no visibility into what happened.

**Tasks:**
- `OSM_Reference_Sync::sync()` must accumulate and return a structured result: `{members_upserted, members_failed, events_upserted, events_failed, errors[]}` rather than returning `void`
- Store result as transient `ems_last_sync_result` (expires in 24h)
- `render_reference_page()`: after sync, display a summary panel: how many members imported, how many events, how many errors, and a collapsible error list
- Replace the bare `wp_die()` OAuth error paths with redirects back to the reference page with an error query param and a notice

#### Diagnostic panel — fix and relocate

`Diagnostic_Panel` is **blank for all local admin accounts** because it only shows content when `ems_access_type` user meta is set — which only happens via OIDC login, not via the admin sync OAuth flow.

**Tasks:**
- Add system-level diagnostics that are always populated regardless of who is logged in:
  - `ems_api_mode` (mock/live)
  - `ems_osm_client_id` configured? (yes/no, don't show value)
  - `ems_managed_sections` list
  - `ems_osm_last_sync` timestamp
  - `ems_last_sync_result` summary (from transient above)
  - OSM rate limit headers (already rendered, just conditionally hidden)
  - DB row counts for each EMS table (explorers, events, attendance)
- Move diagnostic panel from Expedition Board page to the OSM Reference page where it belongs
- Keep current per-user OIDC section on Reference page (access type, scout IDs, section IDs) — but only show it when `ems_access_type` is set

#### Reference data display — patrols and events

`render_reference_page()` shows the explorers table only. After a sync, the admin also needs to see patrol and event data to verify the import worked.

**Tasks:**
- Add a **Patrols summary** below the Explorers table: group `ems_osm_explorers` by `patrol`, show patrol name + member count
- Add an **Events** table from `ems_osm_events`: event name, start date, end date, location, attendance count (JOIN with `ems_osm_event_attendance`)
- Both sections collapse gracefully to "No data — run a sync first" when empty

**Complete when**: sync result stored + displayed; diagnostic shows useful info for a fresh admin login; patrols and events visible on reference page; live auth error paths redirect gracefully.

---

### Stage 1.9 — Expedition Board Deep Review ❌

The current board exists but needs a thorough review before building write functionality on top of it.

**Review tasks:**
- Verify all four tab views render correctly with real post-sync data (not just mock)
- Confirm `ems_team_members.user_id` → `ems_osm_explorers.wp_user_id` join works end-to-end (is `wp_user_id` actually populated after OIDC logins?)
- Assess whether the expedition/team data model (custom post types) is the right fit or needs revisiting
- Review UX: is the expedition board layout clear and usable? What's missing?
- Identify any data that should be on the board but isn't (e.g. expedition status, route deadline, LiC contact)
- Identify missing empty-state handling

**Complete when**: Board review notes captured; any blocking bugs fixed; UX gaps documented for 1.10.

---

### Stage 1.10 — Expedition Write Logic + Explorer Assignment ❌

**TDD Tasks:**
- `Expedition_Admin_Controller` — create expedition (validates code format, rejects duplicate), edit (LiC, WhatsApp, route info, dates), assign explorer to expedition, reassign between teams
- REST endpoints for the above (with tests)
- React "Create/Edit Expedition" form — validation, submission, edit pre-population
- React "Explorer Assignment" view — move explorers from unassigned pool into teams

**Complete when**: Controller tests pass; form component tests pass; assignment component tests pass.

---

### Stage 1.11 — Training Status Fallback ❌

When a Tutor LMS record is linked to a parent `user_id` rather than the explorer's, fall back to `ems_scout_id` anchor.

**TDD Tasks**: match found via fallback; no record found returns `null`.

**Complete when**: Both fallback paths tested; admin view shows correct status for parent-trained explorers.

---

### Stage 1.12 — Column Mapper Repurpose (OSM Write-back) ❌

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
- All 1.8–1.11 tests pass (`vendor/bin/phpunit`, `npm run test`)
- Sync produces visible import summary in the UI
- Diagnostic panel shows useful info for any admin login
- Patrol and event data visible on OSM Reference page
- Expedition board reviewed and any blocking bugs fixed
- `live-auth-only` and `live-limited` modes working against real OSM (1.8)
- Admin can create/edit expeditions and reassign explorers (1.10)
- Training fallback logic tested and passing (1.11)
- Column mapper repurposed for write-back config (1.12)

---

## Source Directory Map — Active Files

Files built in completed stages: see archive. New/modified files for remaining stages:

| File | Class/Purpose | Stage |
|---|---|---|
| `src/Admin/Settings_Page.php` | Add `live-auth-only`, `live-limited`, `ems_sync_limit` fields | 1.8 |
| `src/Plugin.php` | Branches for new sync modes in callback handler | 1.8 |
| `src/Integrations/OSM_Reference_Sync.php` | Return sync result struct; support member limit | 1.8 |
| `src/Admin/Diagnostic_Panel.php` | System-level diagnostics | 1.8 |
| `src/Admin/Admin_Page.php` | Move diagnostic, add patrol/event tables, sync result + call log panel | 1.8 |
| `src/Admin/Expedition_Admin_Controller.php` | Create/edit expeditions, assign explorers | 1.10 |
| `resources/js/admin/expedition-board/ExpeditionBoard.tsx` | Board review + write-action UI | 1.9/1.10 |

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
