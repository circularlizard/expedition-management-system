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

### Design decisions
- **Explorer link**: match `$user->user_email` against `ems_osm_explorers.email` → set `wp_user_id`
- **Parent accounts**: deferred — out of scope for now
- **Bulk reconciliation**: admin-triggered REST endpoint that runs the same email-match logic across all WP users with no linked explorer record
- **Shell account merge**: if a WP account already exists with the same email before OIDC login, the hook retrieves the existing user — `wp_user_id` write handles it transparently
- **Priority order**: auto-link on login first, then bulk reconciliation tool

### Tasks

**3a — Auto-link on OIDC login**

Extend `OSM_Auth_Integration::handle_osm_login()`:
1. Look up `ems_osm_explorers` by `email = $user->user_email`
2. If found and `wp_user_id` is not already set → `UPDATE ems_osm_explorers SET wp_user_id = $user->ID WHERE scout_id = X`
3. Also hook `rtcamp.google_user_created` for the same logic (new registration path)

New method: `OSM_Explorer_Repository::link_wp_user_by_email( string $email, int $wp_user_id ): int` (returns rows affected; no-op if already linked)

**3b — Bulk reconciliation endpoint**

New REST route: `POST ems/v1/reconcile-explorers`
- Iterates all WP users, for each calls `link_wp_user_by_email`
- Returns `{ linked: N, already_linked: M, unmatched: K }`
- Admin-only (`manage_options`)

Admin UI: add "Reconcile Explorer Links" button to the OSM Reference page with result summary.

**Tests (TDD order)**
- Gherkin: `tests/features/3-explorer-login-link.feature` — explorer logs in → `wp_user_id` set; already-linked user not overwritten; unknown email is a no-op
- PHPUnit: `OSM_Explorer_Repository::link_wp_user_by_email` unit tests (email match, no-op when already set, unknown email)
- PHPUnit: `OSM_Auth_Integration::handle_osm_login` — asserts `link_wp_user_by_email` called with correct args
- PHPUnit: bulk reconciliation endpoint response shape

---

## Item 4 — Training status on expedition view

### Context
`Training_Report_Page` already queries TutorLMS via `TutorLMS_Client` for course completion. `Member` type has `training?: TrainingSummary`. `Admin_View_Controller::hydrate_member_data()` populates training via `get_user_training_summary()` — but this is only used in the volunteer/admin view, not the expedition board. The expedition board's `hydrate_members()` in `Expedition_Admin_Controller` does **not** populate training. No concept of "which training is required for which event level" exists yet.

### Tasks

**4a — Training requirements config**
- New WP Option: `ems_training_requirements` — map of `{ level: { course_ids: [] } }` e.g. `{ bronze: [101, 102], silver: [101,102,103], gold: [...] }`
- New admin sub-page (or section on Training Report page): simple UI to assign Tutor LMS courses to each level
- REST: `GET/POST ems/v1/training-requirements`

**4b — Hydrate training into expedition board members**
- Extend `Expedition_Admin_Controller::hydrate_members()` to call `TutorLMS_Client::get_enrollment_matrix()` for the batch of `wp_user_id`s in the team
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
