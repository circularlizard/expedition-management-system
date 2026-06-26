# EMS Next Steps — Implementation Plan: Items 1, 3 & 4

Implementation plan for next-steps items 1 (sync timestamp tracking), 3 (link OSM data to WP login), and 4 (training status on expedition view).

---

## Item 1 — Track when explorers were last updated ✅ DONE

### Context
`ems_osm_explorers.synced_at` is already written on every OSM sync but reflects when OSM data was pulled. Two new timestamps are needed:
- **`last_osm_sync_at`** — when OSM last pulled data for this individual (rename/alias of current `synced_at` semantics, kept per-row)
- **`last_ems_push_at`** — when EMS last pushed data back to OSM for this explorer (outbound sync, currently not implemented but column reserved now)
- **`last_local_update_at`** — when any local EMS edit touched this record (FA level change, expedition assignment, etc.)

### Tasks

**1a — Schema migration** ✅
- Add `last_local_update_at DATETIME DEFAULT NULL` to `ems_osm_explorers` via `Table_Installer::run_migrations()`
- Add `last_ems_push_at DATETIME DEFAULT NULL` to `ems_osm_explorers` via migration
- Existing `synced_at` continues to represent inbound OSM sync time (rename deferred — breaking change)

**1b — Stamp on local edit** ✅
- `OSM_Explorer_Repository::update_first_aid_level()` → also write `last_local_update_at = NOW()`
- Any future repo method that mutates an explorer row should do the same (doc in AGENTS.md)

**1c — Surface in Explorer List UI** ✅
- Add `last_local_update_at` and `synced_at` columns to `Explorer` TypeScript interface
- Expose both from the board REST endpoint (already returns explorers list)
- Add a "Last synced" tooltip or column in `OSMReference.tsx` explorer table

**Tests** ✅
- PHPUnit: migration idempotency (column added once), `update_first_aid_level` stamps `last_local_update_at`
- Vitest: Explorer table renders last-synced date when present

### Verified Results
- PHP tests: 278 tests, 565 assertions — all green
- JS tests: 76 tests across 10 files — all green
- Deployed to local WordPress via `bin/deploy.sh`
- Gherkin scenarios written: `tests/features/1-timestamp-tracking.feature`

### Files Changed
- `src/Core/Table_Installer.php` — added `last_local_update_at` and `last_ems_push_at` columns to schema + migration
- `src/Data/OSM_Explorer_Repository.php` — `update_first_aid_level()` now stamps `last_local_update_at`
- `src/Admin/Expedition_Admin_Controller.php` — `list_explorers()` now returns `synced_at` and `last_local_update_at`
- `resources/js/admin/expedition-board/types.ts` — `Explorer` interface extended with timestamp fields
- `resources/js/admin/expedition-board/OSMReference.tsx` — added "Synced" and "Edited" columns with timestamp formatting
- `tests/Unit/Core/Table_InstallerTest.php` — `test_osm_explorers_has_timestamp_columns`
- `tests/Unit/Data/OSM_Explorer_RepositoryTest.php` — `test_update_first_aid_level_stamps_last_local_update_at`
- `tests/js/OSMReference.test.tsx` — 4 new tests for timestamp display
- `tests/features/1-timestamp-tracking.feature` — Gherkin scenarios for observable behavior

---

## Item 3 — Link OSM data to WP login

### Context
The `login-with-google` plugin fires `rtcamp.google_user_logged_in (WP_User, stdClass)` and `rtcamp.google_user_created (int $uid, stdClass)` after successful OIDC login/registration. The stdClass has `.email`. `ems_osm_explorers` has `email`, `parent_email`, and `wp_user_id` (nullable). The link from WP user → explorer is currently **never written**. `OSM_Auth_Integration` already hooks `rtcamp.google_user_logged_in` for meta population.

**No schema change required** — `wp_user_id BIGINT UNSIGNED DEFAULT NULL` already exists on `ems_osm_explorers`. Item 3 is purely a write path that populates an existing column.

**Graceful degradation** — the `login-with-google` plugin may not be installed or active. The EMS plugin must not error if the hooks never fire:
- Hook registration in `OSM_Auth_Integration` is safe regardless (`add_action` on a never-fired hook is harmless)
- The bulk reconciliation UI (preview/confirm) must still render on the OSM Reference page; if no WP users with matching emails exist the preview simply returns an empty matches list
- All EMS features that consume `wp_user_id` (training status, explorer linking) must handle `NULL` gracefully — show "—" or "not linked" rather than failing

### Design decisions
- **Explorer link**: match `$user->user_email` against `ems_osm_explorers.email` → set `wp_user_id`
- **Parent accounts**: deferred — out of scope for now
- **Bulk reconciliation**: admin-triggered REST endpoint that runs the same email-match logic across all WP users with no linked explorer record
- **Shell account merge**: if a WP account already exists with the same email before OIDC login, the hook retrieves the existing user — `wp_user_id` write handles it transparently
- **Priority order**: auto-link on login first, then bulk reconciliation tool

### Tasks

**3a — Auto-link on OIDC login** ✅ COMPLETE (commit `1139d3a`, 288/288 PHPUnit green, deployed)

Extend `OSM_Auth_Integration::handle_osm_login()`:
1. Guard: skip if `$user->user_email` is empty
2. Look up `ems_osm_explorers` by `email = $user->user_email`
3. If found and `wp_user_id` is not set → write `wp_user_id = $user->ID`
4. If found and `wp_user_id` is already set to a **different** user ID → log a warning, do not overwrite
5. If found and `wp_user_id` already matches `$user->ID` → silent no-op
6. Also hook `rtcamp.google_user_created` for the same logic (new registration path)

New method: `OSM_Explorer_Repository::link_wp_user_by_email( string $email, int $wp_user_id ): int` (returns rows updated; 0 on no-op or mismatch)

**Edge cases handled by `link_wp_user_by_email`:**
- **Blank email**: return 0 immediately if `$email === ''` — avoids matching explorer rows with a blank email field
- **Already linked to different user**: return 0 and trigger `error_log` warning — never silently reassign
- **Multiple explorer rows with same email**: `email` has no UNIQUE constraint; link all unlinked matches (same WP user logged in with one email should own all matching explorer rows for now — revisit if this causes issues)
- **`rtcamp.google_user_created` signature**: hook passes `(int $user_id, stdClass $user_data)` — handler must call `get_user_by('id', $user_id)` to obtain `WP_User` before linking
- **OSM API call fails**: email-match link runs regardless — it only needs `$user->user_email`, not the OSM payload

**3b — Bulk reconciliation (preview + confirm)**

Two REST routes:

| Route | Purpose |
|---|---|
| `GET ems/v1/reconcile-explorers/preview` | Dry-run: returns proposed matches without writing anything |
| `POST ems/v1/reconcile-explorers/confirm` | Writes only the scout_ids explicitly included in the request body |

Preview response shape: `{ matches: [{ scout_id, first_name, last_name, explorer_email, wp_user_id, wp_display_name }], already_linked: N, unmatched: N }`

Confirm request body: `{ scout_ids: [int, ...] }` — admin selects which rows to link.

Admin UI flow:
1. Admin clicks "Preview Explorer Links" on the OSM Reference page
2. Table renders proposed matches (explorer name, email, WP account to be linked) plus counts of already-linked and unmatched
3. Admin can deselect individual rows, then clicks "Confirm Selected" to POST the approved `scout_ids`
4. Result summary shown after confirmation

**Tests (TDD order)**
- Gherkin: `tests/features/3-explorer-login-link.feature` — explorer logs in → `wp_user_id` set; already-linked to same user is a no-op; already-linked to different user logs warning and does not overwrite; blank email is a no-op; unknown email is a no-op; preview returns matches without writing; confirm writes only approved scout_ids
- PHPUnit: `OSM_Explorer_Repository::link_wp_user_by_email` unit tests (email match, no-op when already set, unknown email)
- PHPUnit: `OSM_Auth_Integration::handle_osm_login` — asserts `link_wp_user_by_email` called with correct args
- PHPUnit: preview endpoint returns correct shape without side effects
- PHPUnit: confirm endpoint writes only submitted scout_ids

---

## Item 4 — Training status on expedition view

### Context
`Training_Report_Page` already queries TutorLMS via `TutorLMS_Client` for course completion (assessing course status as `'complete'`, `'in_progress'`, or `'not_enrolled'`). `Member` type has `training?: TrainingSummary`. `Admin_View_Controller::hydrate_member_data()` populates training via `get_user_training_summary()` — but this is only used in the volunteer/admin view, not the expedition board. The expedition board's `hydrate_members()` in `Expedition_Admin_Controller` does **not** populate training. No concept of "which training is required for which event level" exists yet.

To prevent duplicating the training assessment logic, the expedition view will reuse the same `TutorLMS_Client::get_enrollment_matrix()` methods that power the existing training views.

### Tasks

**4a — Training requirements config**
- New WP Option: `ems_training_requirements` — map of `{ level: { course_ids: [] } }` e.g. `{ bronze: [101, 102], silver: [101,102,103], gold: [...] }`
- New admin sub-page (or section on Training Report page): simple UI to assign Tutor LMS courses to each level
- REST: `GET/POST ems/v1/training-requirements`

**4b — Hydrate training into expedition board members**
- Extend `Expedition_Admin_Controller::hydrate_members()` to call `TutorLMS_Client::get_enrollment_matrix()` (reusing the existing course status assessment logic) for the batch of `wp_user_id`s in the team
- Add `training_gaps: string[]` (names of required courses not yet complete) and `training_ok: bool` to `Member` (only populated when `wp_user_id` is known)
- Extend `Member` TypeScript interface: `training_ok?: boolean; training_gaps?: string[]`


**4c — UI: expedition view team table**
- Add "Training" column to `TeamRow` in `ExpeditionView.tsx`
- Green tick if `training_ok`, amber warning icon + tooltip listing gaps if not
- Only show when `wp_user_id` is present (explorers not yet linked show "—")

**4d — Flag explorers with gaps**
- In `ExpeditionDetail`, show aggregate warning if any team member has training gaps for the event's level

**Tests (TDD order)**
- Gherkin: `tests/features/4-training-status.feature` — expedition view shows training status per member; member with gap flagged; unlinked explorer shows "—"
- PHPUnit: `Expedition_Admin_Controller::hydrate_members` includes training fields when `wp_user_id` present
- PHPUnit: training requirements REST endpoint save/read
- Vitest: `TeamRow` renders training tick / gap warning

---

## Sequencing recommendation

| Order | Item | Why |
|---|---|---|
| 1 | **Item 1 — timestamps** ✅ DONE | Pure schema + minimal code; no dependencies; quick win |
| 2 | **Item 3 — login link** | Unlocks `wp_user_id` population, which Item 4 depends on |
| 3 | **Item 4 — training status** | Requires `wp_user_id` to be populated to be useful |

Each item follows the standard TDD loop: Gherkin → user review → PHPUnit (red) → implementation (green) → refactor.
