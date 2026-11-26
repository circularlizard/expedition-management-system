# Automated team solver — design and implementation plan

**Document status:** Design baseline  
**Scope:** In-plugin teammate preference matching, hash-tracked confirmation modal, and pure PHP server-side team solver  
**Target location:** `expedition-management-system/docs/04_AUTOMATED_TEAM_SOLVER_DESIGN.md`

## Recommendation and architecture

Build the entire automated team-building workflow inside `ems-plugin` with zero external APIs, zero Python tooling, and zero under-18 explorer data leaving the WordPress server.

Rather than adding a new admin menu tab, keep the whole workflow inside a two-step modal launched from a single **Auto-Propose Teams** button on the **Team Builder** toolbar:

1. **Step 1 — Confirm teammate links:** A server-side matcher parses each explorer's free-text `exped_team_names` wishlist against eligible sign-ups in the same `(dofe_level, transport_mode)` bucket. Flagged items (duplicate names, unrecognised names, cross-level requests, or negation wording) appear in a focused review step where you pick the right candidate or override via bucket-scoped search. Confirmed decisions are stored in `ems_teammate_preferences` with an MD5 `wishlist_hash` so unchanged wishlists never need re-reviewing, while edited wishlists automatically re-queue.
2. **Step 2 — Propose and load draft teams:** A pure PHP 8.2 solver (`TeamSolverService`) partitions active sign-ups by `(dofe_level, transport_mode)` and assigns teams named `<practice_code>-<n>` onto Practice and Qualifier expedition codes. It respects per-expedition maximum team caps as a hard rule and uses First Aid status only as a very low-priority tie-breaker. You inspect the proposed teams and compromises in a preview before committing them strictly as `draft` teams.

## Data model and source parsing

All database changes are strictly additive. Existing tables (`ems_participant_signups`, `ems_expedition_signups`, `ems_cohorts`, `ems_team_members`) remain untouched.

### Source format (`ems_expedition_signups.expedition_preferences`)

Each active sign-up stores preferences as JSON in this shape:

```json
{
  "exped_type": "Hillwalking",
  "exped_practice_dates": ["H-SP1", "H-SP3", "H-SP4"],
  "exped_qualifier_dates": ["H-SQ1", "H-SQ2", "H-SQ3"],
  "exped_team_names": "Lucy Hann (Lauderdale ESU)"
}

```

Parsing rules:

* Map `exped_type` case-insensitively to canonical `transport_mode` (`hillwalking` → `foot`, `biking` / `cycling` → `cycle`, `paddling` → `paddle`).
* Read `exped_practice_dates` and `exped_qualifier_dates` directly as available expedition codes, surfacing any code not linked to an active `expedition` CPT as a warning in the solver preview.
* Read `exped_team_names` as the raw wishlist input and compute `wishlist_hash = md5(trim($wishlistRaw))` to detect future edits.

### Additive SQL table (`{$wpdb->prefix}ems_teammate_preferences`)

Add via `Table_Installer.php` (`dbDelta()`) and register with `Backup_Manager` via `BackupContributorInterface` so Unified JSON Backups preserve confirmed preferences.

```sql
CREATE TABLE {$wpdb->prefix}ems_teammate_preferences ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, source_signup_id BIGINT UNSIGNED NOT NULL, target_signup_id BIGINT UNSIGNED NULL, wishlist_hash CHAR(32) NOT NULL, raw_token VARCHAR(255) NOT NULL, unit_hint VARCHAR(120) NULL, preference_type VARCHAR(16) NOT NULL DEFAULT 'prefer', match_status VARCHAR(24) NOT NULL DEFAULT 'not_found', confidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0, candidate_ids_json TEXT NULL, flag_negation TINYINT(1) NOT NULL DEFAULT 0, reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_source_signup (source_signup_id), KEY idx_source_hash (source_signup_id, wishlist_hash), KEY idx_target_signup (target_signup_id), KEY idx_match_status (match_status) ) {$charset_collate};

```

**Identity rule:** Both `source_signup_id` and `target_signup_id` reference `ems_expedition_signups.id`. Links stay collision-free even when an explorer's OSM `scout_id` is still `0`.

## Bucket-aware wishlist matching

`WishlistMatcherService` parses `exped_team_names` into individual name tokens and resolves each token against active sign-ups in strict priority order:

1. **Hash-based drift check:** Compare `md5(trim($wishlistRaw))` against stored `wishlist_hash` rows for `source_signup_id`. If the hash matches and rows are already `confirmed` or `ignored`, skip re-parsing so human decisions are preserved. If `exped_team_names` changed since review, purge stale rows for that explorer and re-parse.
2. **Tokenisation and unit extraction:** Split comma-, semicolon-, or newline-separated lists, strip common prose prefixes (such as `"Same Wildlife group as per Bronze:"`), and extract parenthesised unit hints such as `(Lauderdale ESU)`. Where names are space-separated with no commas, use sliding two-token windows against the eligible roster.
3. **Negation guard:** Flag any wishlist containing exclusion wording (`not`, `except`, `without`, `avoid`, `don't`, `do not`) with `flag_negation = 1`. Negation-flagged tokens never auto-confirm; they always enter Step 1 of the modal so an admin can set `preference_type = 'avoid'` or `prefer`.
4. **Eligible pool matching (same level and mode):** Match tokens only against active sign-ups sharing the exact same `dofe_level` and `transport_mode` as `source_signup_id`. Compare against `explorer_first_name`, `explorer_last_name`, and fuzzy edit distance (Levenshtein / phonetic matching).
5. **Unit-assisted disambiguation:** When two or more eligible explorers share a name (for example, two John Smiths in Silver Hillwalking), compare `unit_hint` against `ems_units.name` and `ems_units.short_code`. A clear unit match promotes the right candidate; without a decisive hint, all plausible candidates are stored in `candidate_ids_json` and flagged `ambiguous`.
6. **Cross-bucket detection:** If a token finds no match in the eligible pool, check sign-ups in other levels or modes. If found there, store the match in `candidate_ids_json` with status `cross_bucket` so the modal explains why they cannot be paired (for example, *"Signed up for Gold Hillwalking — ineligible"*).

### Preference status states

| Status | Meaning | Solver behaviour |
| --- | --- | --- |
| **`auto_matched`** | Single high-confidence match in the same level and mode, no negation flag | Included as a soft edge (editable in Step 1 if desired) |
| **`confirmed`** | Explicitly confirmed or linked by an admin in Step 1 | Included as a confirmed `prefer` or `avoid` edge |
| **`ambiguous`** | Two or more plausible matches in the same level and mode | Ignored by solver until resolved in Step 1 |
| **`cross_bucket`** | Matches a sign-up in a different level or mode | Ignored by solver (ineligible) |
| **`not_found`** | No match in any bucket | Ignored by solver |
| **`ignored`** | Admin dismissed the token (friend not signed up or note not actionable) | Ignored by solver |

## Two-step Auto-Propose modal inside Team Builder

Launch from a single **Auto-Propose Teams** button in the Team Builder toolbar (no new top-level menu tab).

### Step 1 — Confirm teammate links

* **Default filter:** Shows **Needs Review** (`ambiguous`, `not_found`, `cross_bucket`, `flag_negation = 1`) with a toggle to **Show All Parsed Wishlists**.
* **Disambiguation and notices:** For `ambiguous` matches (such as two John Smiths), renders radio buttons showing each candidate's full name, unit (`ems_units.name`), and shared expedition code count. For `cross_bucket` and `not_found` tokens, displays clear inline notices.
* **Bucket-scoped search override:** Every token row includes a **Search & override** picker querying strictly within the explorer's own `(dofe_level, transport_mode)` bucket by name or unit, plus a `Prefer` / `Avoid` toggle and an **+ Add teammate** action for prose-only notes.
* **Proceed to Solver:** Saves decisions to `ems_teammate_preferences` and advances to Step 2 (or lets the admin jump straight to Step 2 if zero flagged items remain).

### Step 2 — Generate proposal and load draft teams

* **Scope and weight controls:** Choose current `(dofe_level, transport_mode)` filter or **Full Season (All Buckets)**, adjust optional priority weights, and click **Generate Proposal**.
* **Read-only preview:** Displays proposed teams (`H-SP1-1`, `H-SP1-2`, etc.) with Practice and Qualifier codes, member lists, team size (4–7), expedition capacity usage (for example, `3/4 teams on H-SP1`), friend-match counts, and an **Exceptions & Compromises** summary (`date_conflict_pending`, `split_date`, unfulfilled mutual requests).
* **Load as Draft Teams:** Commits the previewed proposal strictly as `notification_status = 'draft'` teams via `CohortRepository` and `CohortSchedulerService`, with an optional **Replace existing draft teams in scope** checkbox.

## Server-side PHP solver (`TeamSolverService`)

Because each `(dofe_level, transport_mode)` bucket holds roughly 15–55 explorers, a pure PHP 8.2 greedy + Simulated Annealing solver completes a full season in under two seconds with zero external dependencies and no shared-hosting timeout risk.

### Partitioned model

The solver partitions unlocked sign-ups, expedition codes, locked teams, and confirmed/auto-matched preferences by `(dofe_level, transport_mode)` and solves each bucket independently.

**Hard constraints (never broken):**

* Every unlocked explorer is placed in one Practice team and one Qualifier team within their `(dofe_level, transport_mode)` bucket.
* Every active team on every expedition code has strictly 4–7 members.
* **Per-expedition maximum teams:** The number of teams assigned to any expedition code (including already locked teams on that code) must not exceed that expedition's configured team capacity (`_ems_max_teams` / `_ems_capacity_teams` meta on the `expedition` CPT; treated as uncapped when `0` or unset).
* Locked teams (any existing team containing `notified` members, or manually locked by the admin) remain fixed.

**Tunable soft objectives (default weights):**

| Objective | Default weight | Effect |
| --- | --- | --- |
| **Avoid exclusion** | `-500` | Strongly separates `preference_type = 'avoid'` pairs |
| **Cross-stage continuity** | `+100` | Keeps the same team together across Practice and Qualifier (minimises `split_date`) |
| **Code availability** | `+80` | Avoids unavailable expedition codes (minimises `date_conflict_pending`) |
| **Mutual wishlist pair** | `+25` | Keeps mutually requested friends together |
| **One-way wishlist request** | `+10` | Satisfies one-way friend requests where availability allows |
| **Same unit tie-breaker** | `+5` | Groups explorers from the same Unit when no wishlist link exists |
| **First Aid presence (low priority)** | `+5` | Gentle tie-breaker when a team already has valid First Aid (`COALESCE(e.first_aid_level, s.first_aid_status)`), without overriding friend links or availability |

### Team naming convention

Every proposed team name follows `<practice_code>-<n>`, where `<n>` is a 1-based sequence number within that practice expedition code (for example, `H-SP1-1`, `H-SP1-2`, `H-SP2-1`). If locked teams already occupy sequence numbers for a practice code, the solver continues from the next available integer.

### Strict safety guards

* **Draft-only writes:** Every created team and `ems_team_members` row is written strictly as `notification_status = 'draft'`. Neither the solver nor the apply endpoint ever calls `wp_mail()` or `ExpeditionOfferDispatcher`.
* **Scoped draft clearing:** When **Replace existing draft teams in scope** is selected, clears only 100%-draft teams belonging to the `(dofe_level, transport_mode)` buckets in the proposal.
* **Notified protection:** Any team or member already marked `notified` is never modified or deleted.

## REST API contracts (`/wp-json/ems/v1/`)

All endpoints require `manage_options` and WordPress REST nonce verification.

| Route | Method | Purpose |
| --- | --- | --- |
| `ems/v1/preferences/parse` | `POST` | Run `WishlistMatcherService` across all (or scoped) sign-ups, skipping explorers whose `wishlist_hash` matches already `confirmed` / `ignored` rows |
| `ems/v1/preferences/queue` | `GET` | Return review items (`dofe_level`, `transport_mode`, `filter=needs_review|all`) with candidate metadata and unit names |
| `ems/v1/preferences/candidates` | `GET` | Bucket-scoped search (`source_signup_id`, `q`) returning only eligible sign-ups in the same `(dofe_level, transport_mode)` |
| `ems/v1/preferences/{source_signup_id}` | `PUT` | Save reviewed links (`confirmed` / `ignored`, `prefer` / `avoid`, manual additions) for one explorer |
| `ems/v1/teams/solver/propose` | `POST` | Run `TeamSolverService` in memory for one bucket or all buckets; return proposed teams, capacity counts, and exceptions report (zero DB writes) |
| `ems/v1/teams/solver/apply` | `POST` | Validate and commit an accepted proposal strictly as `draft` teams via `CohortRepository` / `CohortSchedulerService` |

## Single-evening implementation plan

1. **Backend schema, matcher, and solver (`Subagent 1A`):**
* Add `ems_teammate_preferences` (with `wishlist_hash`) to `Table_Installer.php` (bump `EMS_DB_VERSION`) and register with `Backup_Manager`.
* Implement `WishlistPreferenceRepository.php`, `WishlistMatcherService.php`, and `TeamSolverService.php` (partitioned greedy + Simulated Annealing enforcing 4–7 team sizes, per-expedition max teams, locked-team preservation, `<practice_code>-<n>` naming, and `+5` First Aid tie-breaker).
* Register the 6 REST routes in `CohortController.php` (`manage_options`) and add PHPUnit tests covering hash drift detection, duplicate-name disambiguation, cross-bucket warnings, negation flags, per-expedition max teams, `<practice_code>-<n>` naming, `draft`-only writes, and zero `wp_mail()` calls.


2. **Shared contracts and Team Builder two-step modal (`Subagent 1B`):**
* Add TypeScript DTOs in `packages/contracts/src/index.ts`.
* Build `AutoProposeTeamsModal.tsx` inside `resources/js/admin/expedition-board/` with Step 1 (**Confirm Teammate Links** — radio candidates, cross-bucket/missing notices, bucket-scoped search override, `Prefer` / `Avoid` toggle, `+ Add teammate`) and Step 2 (**Propose Teams** — scope selector, weight controls, preview of `<practice_code>-<n>` teams and compromises, and **Load as Draft Teams**).
* Update Team Builder pool cards to read confirmed edges from `ems_teammate_preferences` where present, and add Vitest tests for both modal steps.


3. **Verification and local deploy (`Main Agent`):**
* Run `npm run verify:all` inside `./expedition-management-system`, deploy locally with `bash bin/deploy.sh ems`, and smoke-test against your live sign-ups snapshot.




---

### Single-evening Antigravity (`agy`) prompt

Save the file above, then run this prompt at `EMS/`:

```text
Read `./expedition-management-system/docs/04_AUTOMATED_TEAM_SOLVER_DESIGN.md` and `./expedition-management-system/docs/02_TARGET_DATA_MODEL_AND_CONTRACTS.md`.
You are the Lead Builder implementing the **Automated Team Solver & Two-Step Confirmation Modal** inside `./expedition-management-system`.

CRITICAL SAFETY RULES:
- Never modify or drop existing columns on `ems_participant_signups`, `ems_expedition_signups`, `ems_cohorts`, or `ems_team_members`. Only add the new `ems_teammate_preferences` table via `Table_Installer.php` (`dbDelta()`).
- Imported/proposed teams and members MUST be written strictly as `notification_status = 'draft'`. Never call `wp_mail()` or `ExpeditionOfferDispatcher`.
- Never overwrite or delete any team or member already marked `notified`.
- Do NOT add a new top-level tab in `Expeditions & Teams`; keep the workflow inside a two-step modal launched from the **Team Builder** toolbar.

Execute using 2 parallel subagents on disjoint scopes:

1. **Subagent 1A (`backend-matcher-and-solver`):**
   - Add `{$wpdb->prefix}ems_teammate_preferences` (including `wishlist_hash CHAR(32)`) to `plugins/ems-plugin/src/Core/Table_Installer.php` (bump `EMS_DB_VERSION`) and register it with `Backup_Manager` (`BackupContributorInterface`).
   - Create `WishlistPreferenceRepository.php` and `WishlistMatcherService.php` in `plugins/ems-plugin/src/Modules/Expeditions/Services/`:
     - Parse `ems_expedition_signups.expedition_preferences` (`exped_type`, `exped_practice_dates`, `exped_qualifier_dates`, `exped_team_names`).
     - Preserve `confirmed` / `ignored` rows when `md5(trim($wishlistRaw)) === wishlist_hash`; purge and re-parse when the hash changes.
     - Extract parenthesised unit hints (`(Lauderdale ESU)`), flag negation wording (`not`, `except`, `without`, `avoid`), disambiguate duplicate names within the same `(dofe_level, transport_mode)` using `ems_units`, and flag cross-bucket matches (`cross_bucket`).
   - Create `TeamSolverService.php` implementing a partitioned greedy + Simulated Annealing solver per `(dofe_level, transport_mode)`:
     - Hard constraints: 4–7 members per team per stage, per-expedition maximum team capacity (uncapped if unset/`0`), and fixed locked/`notified` teams.
     - Soft weights: Avoid (`-500`), Cross-stage continuity (`+100`), Code availability (`+80`), Mutual wishlist (`+25`), One-way wishlist (`+10`), Same unit (`+5`), First Aid presence (`+5` low-priority tie-breaker).
     - Name proposed teams `<practice_code>-<n>` continuing after any existing sequence numbers on that practice code.
   - Register `POST /preferences/parse`, `GET /preferences/queue`, `GET /preferences/candidates` (strictly bucket-scoped), `PUT /preferences/{source_signup_id}`, `POST /teams/solver/propose`, and `POST /teams/solver/apply` in `Rest/CohortController.php` (`manage_options`).
   - Add PHPUnit tests covering hash drift detection, duplicate-name disambiguation, cross-bucket flagging, negation flagging, bucket-scoped candidate search, per-expedition max teams, `<practice_code>-<n>` naming, `draft`-only writes, scoped draft clearing, and zero `wp_mail()` calls.

2. **Subagent 1B (`contracts-and-team-builder-modal`):**
   - Add shared TypeScript DTOs for preference items, review candidates, solver weights, proposal previews, and apply requests in `packages/contracts/src/index.ts`.
   - Build `AutoProposeTeamsModal.tsx` inside `plugins/ems-plugin/resources/js/admin/expedition-board/` launched by an **Auto-Propose Teams** button on the Team Builder toolbar:
     - **Step 1 (Confirm Teammate Links):** Runs `/preferences/parse`, defaults to **Needs Review** (`ambiguous`, `not_found`, `cross_bucket`, `flag_negation`) with a toggle for **All**, renders radio candidates with unit names for duplicate names, provides a bucket-scoped **Search & override** picker, `Prefer` / `Avoid` toggle, `+ Add teammate` search, and saves via `PUT /preferences/{source_signup_id}`.
     - **Step 2 (Propose & Load Draft Teams):** Scope selector (current bucket vs Full Season), adjustable weight inputs, **Generate Proposal** preview showing `<practice_code>-<n>` teams, expedition capacity counts, and compromises (`date_conflict_pending`, `split_date`, unfulfilled mutual pairs), plus **Load as Draft Teams** with optional scoped draft replacement.
   - Update Team Builder pool cards to read confirmed edges from `ems_teammate_preferences` when available, and add Vitest tests covering both modal steps.

Main Agent after subagents finish:
- Run `npm run verify:all` inside `./expedition-management-system`.
- Run `bash bin/deploy.sh ems` inside `./expedition-management-system`, verify `wp_ems_teammate_preferences` is created cleanly and existing sign-up rows are untouched, and report the summary and commit log.

```