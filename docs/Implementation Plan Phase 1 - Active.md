# Implementation Plan Phase 1 — Active Work

> Completed stages (1.1–1.6, foundations, environment) archived to:
> `docs/archive/Implementation Plan Phase 1 - Completed.md`
>
> Phase 2+ plan: `docs/Implementation Plan Phase 2+.md`

---

## Current Status — 15 June 2026

| Stage                 | Description                                    | Status               |
| -----------------------| ------------------------------------------------| ----------------------|
| Foundations + 1.1–1.6 | All completed                                  | ✅ See archive        |
| Step 0                | Anonymised mock data generation                | ✅ Done — 15 Jun 2026 |
| 1.7                   | Admin Read Views                               | ✅ Done — 15 Jun 2026 |
| 1.8                   | Diagnostics + Reference Data Display           | ✅ Done — 15 Jun 2026 |
| 1.9                   | Settings page tabs + Managed Sections redesign | ✅ Done — 16 Jun 2026 |
| 1.10                  | OSM Auth Test Modes + Sync Progress Feedback   | ❌ Not started        |
| 1.11                  | Expedition Board deep review                   | ❌ Not started        |
| 1.12                  | Expedition write logic + Explorer Assignment   | ❌ Not started        |
| 1.13                  | Training Status Fallback                       | ❌ Not started        |
| 1.14                  | Column Mapper repurpose (OSM write-back)       | ❌ Not started        |

**Tests**: 184 PHP / 344 assertions green. 16 JS Vitest green.

---

## Active Stages

### Stage 1.10 — OSM Auth Test Modes + Sync Progress Feedback ❌

#### Live OSM auth — current state

`OSM_Sync_Auth_Handler` is **fully built**: initiates OAuth2 code flow → exchanges code for token → fires sync callback → redirects back. Wired in `Plugin.php`: `ems_api_mode=live` uses it; `mock` bypasses it.

**Before live auth works in production:**
- OAuth credentials (`ems_osm_client_id` / `ems_osm_client_secret`) must be configured in Settings → OSM Connection
- Redirect URI `admin-post.php?action=ems_osm_callback` must be whitelisted in OSM developer portal
- `wp_die()` error paths in `handle_callback()` should redirect gracefully instead

#### Settings additions (OSM Connection tab)

- **OAuth Scope** — new text field `ems_osm_scope` (default `section:member:read section:events:read section:flexirecord:read`); value passed as `scope` parameter in the OAuth2 authorization URL. Admin can adjust if OSM adds new scopes or access is denied.

#### UI parity principle

**The UI must behave identically regardless of API mode.** Mock mode exists so the full UI flow can be exercised without a live OSM connection. Every panel, notice, log, and summary that appears after a live sync must also appear after a mock sync, populated from mock data. Mode differences are purely in the data source, never in what the UI renders.

#### Sync progress feedback (all modes: mock, live, live-auth-only, live-limited)

Currently sync is a silent round-trip with a single success/failure notice. All modes must produce full feedback:

- `OSM_Reference_Sync::sync()` returns a result struct and **always** writes `ems_last_sync_result` (transient, 24h) and `ems_last_sync_log` (transient, 24h)
- `render_reference_page()` displays a **sync summary panel** above the tabs whenever `ems_last_sync_result` exists: member count upserted/failed, event count upserted/failed, error count, rate-limit warning if applicable, collapsible error list
- The Diagnostics tab always shows the full per-call log from `ems_last_sync_log` with a **Download log** button (JSON) — shown whenever a log exists, regardless of mode

#### OSM auth test modes

Two modes for incremental live testing, both using the same OAuth2 flow as `live`:

**Mode: `live-auth-only`**
- Full OAuth2 flow → `get_data_payload()` only — no member/event sync
- Stores payload as transient `ems_last_payload_dump` (1h); also populates `ems_available_sections` transient via `parse_section_names()`
- Redirects to OSM Reference page → Diagnostics tab displays `ems_last_payload_dump`: `userid`, `access_type`, `section_ids`, `terms`, `member_access` summary
- Purpose: verify credentials, confirm section IDs, check access scope

**Mode: `live-limited`**
- Behaves identically to `live-auth-only` after OAuth (payload dump + section list stored)
- Sync is **not** triggered automatically — admin manually clicks "Sync from OSM" on the Reference page as a separate step
- When sync runs in `live-limited` mode: capped to first managed section only, first N members (`ems_sync_limit`, default 5)
- Produces the same sync summary panel and log as all other modes
- Purpose: decouple auth verification from sync testing; run sync only when ready

#### Rate limiting — full compliance (OSM spec)

OSM will **permanently block** applications that are frequently blocked. Every rule below is mandatory:

**Per-response header logging** — `Live_Driver` reads and passes these headers back with every API response:
- `X-RateLimit-Limit` — total requests per hour for this user
- `X-RateLimit-Remaining` — requests left before block
- `X-RateLimit-Reset` — seconds until limit resets (not a timestamp)
- `Retry-After` — seconds to wait (only present on 429 responses)
- `X-Deprecated` — if present, log a warning with the deprecation date; do not suppress
- `X-Blocked` — if present, **immediately stop all requests and treat as permanent**; log prominently; surface a critical admin notice. Continuing after `X-Blocked` escalates to a permanent block.

**HTTP 429 hard stop:**
- `Live_Driver` throws `Rate_Limit_Exception` immediately on 429, carrying `retry_after` seconds from the `Retry-After` header
- `OSM_Reference_Sync::sync()` catches it at the top level — **stops all further API calls, no retry, no backoff**
- Writes `ems_last_sync_log` and `ems_last_sync_result` with `rate_limited: true` before exiting
- UI notice: *"Sync stopped: OSM rate limit reached. Retry after: Xs (resets in Ys)."*

**X-Blocked hard stop:**
- `Live_Driver` throws `Api_Blocked_Exception` immediately on receiving `X-Blocked` header (any response)
- Same immediate stop as 429 — no further requests under any circumstances
- UI notice (error level, not dismissible): *"OSM has blocked this application. No further API calls will be made. Check the sync log and contact OSM support."*

**Response validation:**
- `Live_Driver` validates every response before returning: checks HTTP status, confirms response is parseable JSON, checks for unexpected structure
- Malformed or unexpected responses throw `Api_Response_Exception` — sync stops, error recorded in log and result struct
- Purpose: OSM blocks applications that frequently send invalid follow-up requests based on bad data

**Internal rate limiter (`Rate_Limiter`):**
- Already exists; must be kept active on all live API calls
- Must enforce a conservative limit well below the OSM per-hour cap to leave headroom

#### Sync log (`ems_last_sync_log`)

- **Overwritten** (not appended) each time a new sync starts — prevents unbounded growth
- Each entry: `{timestamp, call_type, url, http_status, rate_limit_limit, rate_limit_remaining, rate_limit_reset_seconds, retry_after, deprecated_header, blocked_header, duration_ms}`
- Mock driver writes `null` for all rate-limit header fields (same log structure)
- Terminal entries on 429: `{call_type: "rate_limited", http_status: 429, retry_after: N, ...}`
- Terminal entries on `X-Blocked`: `{call_type: "api_blocked", ...}`
- Diagnostics tab: **Download log** button (JSON) — always shown when a log exists, regardless of mode

#### Sync result (`ems_last_sync_result`)

```
{
  mode,
  started_at,
  members_upserted, members_failed,
  events_upserted, events_failed,
  errors[],
  rate_limited: bool,
  retry_after_seconds: int|null,
  rate_limit_remaining: int|null,
  rate_limit_reset_seconds: int|null,
  api_blocked: bool,
  deprecated_endpoints: string[]
}
```

#### Error handling

- Replace all `wp_die()` OAuth error paths with redirects to the reference page with `?error=<slug>` and a dismissible admin notice
- 429: prominent warning notice with retry-after time
- `X-Blocked`: non-dismissible error notice; no sync button shown until mode is reset
- `X-Deprecated` warnings: logged + shown as a low-priority admin notice listing affected endpoints

**Complete when**: mock sync produces full summary panel + log; `live-auth-only` completes OAuth and displays payload dump on Diagnostics tab; `live-limited` does the same then allows manual sync trigger (capped) with full feedback; 429 terminates sync immediately with `Retry-After` in log + notice; `X-Blocked` terminates and surfaces non-dismissible error; `X-Deprecated` logged and noticed; response validation stops sync on bad data; log overwrites on each new sync; Download log button available; full `live` sync stores and displays result summary; all `wp_die()` OAuth error paths replaced.

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
