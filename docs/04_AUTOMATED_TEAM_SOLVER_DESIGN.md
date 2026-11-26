# Automated team solver — design and implementation plan

**Document status:** Design baseline  
**Scope:** Full-season planning export/import, LLM wishlist prompt helper, local OR-Tools solver pilot, and conditional in-plugin Web Worker integration  
**Target location:** `expedition-management-system/docs/04_AUTOMATED_TEAM_SOLVER_DESIGN.md`

## Recommendation and phased strategy

Use a hybrid pipeline: an LLM (or local fuzzy matcher) turns unstructured teammate wishlists into a clean preference graph, and a classical constraint solver assigns teams and expedition codes deterministically.

Run the export, wishlist resolution, and solver script in one pass for the whole season, while letting the solver partition and optimise each `(dofe_level, transport_mode)` bucket independently. That gives you one end-to-end workflow and catches cross-level wishlist mistakes automatically, without mixing Silver and Gold teams.

Deliver the system in three gated phases with zero database schema changes and zero risk to live sign-ups:

1. **Phase 1 — Plugin export/import and prompt helper:** Add full-season JSON export/import endpoints and a modal in the Team Builder, including a one-click prompt generator for Gemini or a local LLM.
2. **Phase 2 — Local solver pilot:** Run a standalone Python OR-Tools CP-SAT script against real exported sign-ups to validate team quality, tune weights, and review human-readable trade-off reports.
3. **Phase 3 — In-plugin Web Worker (conditional):** If the pilot saves meaningful time and produces proposals you trust, port the validated scoring model into a browser-side TypeScript Web Worker inside the Team Builder for one-click reuse in future seasons.

## Full-season export and import contracts

Both endpoints sit in `plugins/ems-plugin/src/Modules/Expeditions/` behind `manage_options` capability checks and delegate writes to your existing `CohortRepository` and `CohortSchedulerService`. Every record carries its own `dofe_level` and `transport_mode` so the same contract handles both full-season runs and single-bucket pilots.

The solver works purely on expedition codes (such as `H-SP1` and `H-SQ2`) and does not solve for exact dates or First Aid coverage at this stage. First Aid readiness remains audited in **Teams & Readiness** once draft teams are loaded.

### Planning data export (`GET /wp-json/ems/v1/teams/planning-export`)

**Query parameters:**
* `dofe_level`: `all` | `bronze` | `silver` | `gold` (default `all`)
* `transport_mode`: `all` | `foot` | `cycle` | `paddle` (default `all`)
* `include_locked_teams`: `true` | `false` (default `true`)

**Source parsing (`ems_expedition_signups.expedition_preferences`):**

Each row's `expedition_preferences` column stores JSON in this shape:

```json
{
  "exped_type": "Hillwalking",
  "exped_practice_dates": ["H-SP1", "H-SP3", "H-SP4"],
  "exped_qualifier_dates": ["H-SQ1", "H-SQ2", "H-SQ3"],
  "exped_team_names": "Lucy Hann (Lauderdale ESU)"
}

```

`TeamProposalService::exportPlanningData()` must:

1. Map `exped_type` case-insensitively to canonical `transport_mode` (`hillwalking` → `foot`, `biking`/`cycling` → `cycle`, `paddling` → `paddle`).
2. Export `exped_practice_dates` and `exped_qualifier_dates` directly as `available_practice_codes` and `available_qualifier_codes`, recording any code not matched to an active `expedition` CPT in `export_warnings`.
3. Map `exped_team_names` to `wishlist_raw` and include both `unit_code` and `unit_name` (from `ems_units`) on each explorer record so parenthesised unit hints such as `(Lauderdale ESU)` resolve cleanly.

**Payload (`ems-planning-export-v1`):**

```json
{
  "schema_version": "ems-planning-export-v1",
  "exported_at": "2026-11-26T11:30:00Z",
  "scope": {
    "dofe_level": "all",
    "transport_mode": "all"
  },
  "expeditions": [
    {
      "code": "H-SP1",
      "dofe_level": "silver",
      "transport_mode": "foot",
      "stage": "practice",
      "capacity_teams": 4
    },
    {
      "code": "H-SQ2",
      "dofe_level": "silver",
      "transport_mode": "foot",
      "stage": "qualifier",
      "capacity_teams": 4
    }
  ],
  "explorers": [
    {
      "expedition_signup_id": 104,
      "display_name": "Adam Taylor",
      "unit_code": "CR-PI-Larkhall",
      "unit_name": "Larkhall ESU",
      "dofe_level": "silver",
      "transport_mode": "foot",
      "available_practice_codes": ["H-SP1", "H-SP3", "H-SP4"],
      "available_qualifier_codes": ["H-SQ1", "H-SQ2", "H-SQ3"],
      "wishlist_raw": "Lucy Hann (Lauderdale ESU)"
    }
  ],
  "locked_teams": [
    {
      "cohort_id": 12,
      "name": "H-SP2-1",
      "dofe_level": "silver",
      "transport_mode": "foot",
      "practice_code": "H-SP2",
      "qualifier_code": "H-SQ2",
      "member_signup_ids": [101, 102, 103, 108]
    }
  ],
  "export_warnings": []
}

```

**Data rules:**

* **Minimal PII:** Excludes parent emails, explorer emails, phone numbers, dates of birth, ASN notes, and First Aid fields. Only names, unit details, availability codes, and raw wishlists leave EMS.
* **Collision-free identity:** Anchored strictly on `expedition_signup_id` so unmatched sign-ups (`scout_id = 0`) never collide.

### Team proposal import (`POST /wp-json/ems/v1/teams/import-proposal`)

**Team naming convention:**
Every proposed team name must follow `<practice_code>-<n>`, where `<n>` is a 1-based sequence number within that practice expedition code (for example, `H-SP1-1`, `H-SP1-2`, `H-SP2-1`). If locked teams already occupy sequence numbers for a practice code, the solver continues from the next available integer.

**Payload (`ems-team-proposal-v1`):**

```json
{
  "schema_version": "ems-team-proposal-v1",
  "options": {
    "dry_run": true,
    "clear_existing_drafts": false
  },
  "teams": [
    {
      "name": "H-SP1-1",
      "dofe_level": "silver",
      "transport_mode": "foot",
      "practice_code": "H-SP1",
      "qualifier_code": "H-SQ2",
      "notes": "Auto-proposed: 6/6 mutual friends.",
      "members": [
        {
          "expedition_signup_id": 104,
          "practice_override": "matched",
          "qualifier_override": "matched"
        },
        {
          "expedition_signup_id": 109,
          "practice_override": "matched",
          "qualifier_override": "date_conflict_pending"
        }
      ]
    }
  ],
  "split_date_placements": [
    {
      "expedition_signup_id": 115,
      "home_team_name": "H-SP1-1",
      "dofe_level": "silver",
      "transport_mode": "foot",
      "stage": "qualifier",
      "guest_team_name": "H-SP1-2",
      "expedition_code": "H-SQ3"
    }
  ]
}

```

On import, `TeamProposalService::importProposal()` resolves `practice_code`, `qualifier_code`, and `expedition_code` to their matching WordPress `expedition` CPT post IDs before delegating to `CohortRepository` and `CohortSchedulerService`.

### Strict safety guards on import

| Guard | Behaviour |
| --- | --- |
| **Mandatory dry-run preview** | The UI always posts `dry_run: true` first. The backend verifies that every `expedition_signup_id` and expedition code exists and matches the team's `dofe_level` and `transport_mode`, checks 4–7 team sizes per stage, and returns a structured preview before writing. |
| **Draft-only writes** | Every imported team and `ems_team_members` row is written strictly as `notification_status = 'draft'`. The importer never calls `wp_mail()` or `ExpeditionOfferDispatcher`. |
| **Scoped draft clearing** | If `clear_existing_drafts: true` is selected, the importer clears only 100%-draft teams belonging to the `(dofe_level, transport_mode)` buckets present in the uploaded file. Importing a Silver file never touches Gold drafts. |
| **Notified protection** | Any team or member already marked `notified` is never modified or deleted; conflicts are skipped and surfaced in the dry-run summary. |

## Built-in LLM prompt helper for wishlists

The **Auto-Plan (Export / Import)** modal in the Team Builder includes a **Copy Wishlist Prompt** button alongside the JSON download. It copies a pre-populated prompt containing the full roster (`expedition_signup_id`, `display_name`, `unit_code`, `unit_name`, `dofe_level`, `transport_mode`, `wishlist_raw`) ready to paste into Gemini or a local model (Ollama / LM Studio).

```text
You are a data-cleaning assistant. Map each explorer's free-text teammate wishlist to exact `expedition_signup_id` values from the provided full-season roster.

RULES:
1. Output valid JSON matching the schema below. No markdown prose.
2. Match nicknames, minor spelling errors, and unit hints (including parenthesised units such as "(Lauderdale ESU)" or phrases like "Same Wildlife group") against `display_name`, `unit_code`, and `unit_name`.
3. Use `weight: 1` for preferred teammates and `weight: -10` for explicit "do not pair" requests.
4. Only add an edge to `edges` when source and target share the same `dofe_level` and `transport_mode`.
5. If a requested teammate is in the roster under a different `dofe_level` or `transport_mode`, record them in `cross_bucket_mismatches` rather than `edges`.
6. If a requested name does not appear in the roster at all, record them in `unresolved` instead of guessing.

OUTPUT SCHEMA:
{
  "edges": [
    { "source_id": 104, "target_id": 112, "weight": 1, "confidence": "high", "note": "Exact match" }
  ],
  "cross_bucket_mismatches": [
    { "source_id": 104, "target_id": 205, "raw_text": "Dylan Fletcher", "reason": "Source is silver/foot; target is gold/foot" }
  ],
  "unresolved": [
    { "source_id": 104, "raw_text": "Sam Jones", "reason": "Not found in roster" }
  ]
}

ROSTER:
{{INSERT_ROSTER_JSON_HERE}}

```

> **Under-18 PII note:** If you prefer not to send explorer names to a third-party free-tier API, run the prompt against a local model (such as Ollama) or use the offline `fuzzy_wishlist.py` (`rapidfuzz`) script included in `tools/team-solver/`.

## Local solver pilot (`tools/team-solver/`)

The pilot solver lives under `expedition-management-system/tools/team-solver/`, outside `plugins/ems-plugin/` so it adds zero weight to the WordPress deployable zip.

### Inputs and outputs

* **Inputs:** `planning-export.json` (from EMS), `preferences.json` (from the LLM prompt or `fuzzy_wishlist.py`), and `weights.json` (tunable priorities).
* **Outputs:**
1. `proposal.json` — conforms strictly to `ems-team-proposal-v1` with `<practice_code>-<n>` team names for upload into EMS.
2. `tradeoff_report.md` — human-readable audit grouped by `(dofe_level, transport_mode)` showing each team's name, Practice/Qualifier codes, size, friend-match rate, `cross_bucket_mismatches`, and an **Exceptions & Compromises** table (`date_conflict_pending`, `split_date`, unfulfilled mutual requests).



### Partitioned optimisation model (Google OR-Tools CP-SAT)

The script partitions `expeditions`, `explorers`, `locked_teams`, and `edges` by `(dofe_level, transport_mode)` and solves each bucket independently in under two seconds.

**Hard constraints (never broken):**

* Every unlocked explorer is placed in one Practice team and one Qualifier team within their `(dofe_level, transport_mode)` bucket.
* Every active team on every expedition code has strictly 4–7 members.
* Locked teams from `locked_teams[]` remain fixed.

**Tunable soft objectives (`weights.json`):**

| Objective | Default weight | Effect |
| --- | --- | --- |
| **Do-not-pair exclusion** | `-500` | Strongly separates explicit exclusions |
| **Cross-stage continuity** | `+100` | Keeps the same team together across Practice and Qualifier (minimises `split_date`) |
| **Code availability** | `+80` | Avoids unavailable expedition codes (minimises `date_conflict_pending`) |
| **Mutual wishlist pair** | `+25` | Keeps mutually requested friends together |
| **One-way wishlist request** | `+10` | Satisfies one-way friend requests where availability allows |
| **Same unit tie-breaker** | `+5` | Groups explorers from the same Unit when no wishlist link exists |

## Phased implementation plan

### Phase 1 — Plugin export/import and prompt helper (1–2 days)

1. **Shared contracts (`packages/contracts/src/index.ts`):** Add `PlanningExportDTO`, `TeamProposalDTO`, and `ProposalDryRunResultDTO`.
2. **Backend service and REST routes (`plugins/ems-plugin/src/Modules/Expeditions/`):**
* Create `Services/TeamProposalService.php` implementing `exportPlanningData()` (parsing `expedition_preferences` JSON and including `unit_name`) and `importProposal()` (resolving expedition codes to `expedition` CPT post IDs and delegating writes to `CohortRepository` and `CohortSchedulerService`).
* Register `GET /wp-json/ems/v1/teams/planning-export` and `POST /wp-json/ems/v1/teams/import-proposal` in `Rest/CohortController.php` (`manage_options`).
* Add PHPUnit tests covering `expedition_preferences` parsing, full-season vs single-bucket exports, dry-run validation, scoped `clear_existing_drafts`, `draft`-only writes, zero `wp_mail()` calls, and protection of `notified` teams.


3. **Team Builder UI (`resources/js/admin/expedition-board/`):**
* Add an **Auto-Plan (Export / Import)** button in the Team Builder toolbar opening a modal with a **Full Season (All Levels & Modes)** toggle (defaulting to checked), **Download Planning JSON**, **Copy Wishlist Prompt**, and **Import Proposal JSON** (dry-run preview followed by **Load as Draft Teams**).



### Phase 2 — Local solver pilot (1–2 days)

1. **Scaffold `tools/team-solver/`:**
* `solve.py` (partitioned OR-Tools CP-SAT solver, `<practice_code>-<n>` team namer, `proposal.json` writer, and `tradeoff_report.md` generator).
* `fuzzy_wishlist.py` (offline `rapidfuzz` fallback supporting parenthesised unit hints).
* `weights.json` and `requirements.txt` (`ortools`, `rapidfuzz`).


2. **Pilot and tune:**
* Export your live season sign-ups from EMS, parse wishlists, and run `python solve.py`.
* Inspect `tradeoff_report.md`, adjust `weights.json` if needed, import `proposal.json` as Drafts in local/staging EMS, and verify how much manual tweaking remains in the Team Builder.



### Phase 3 — In-plugin Web Worker integration (post-pilot decision gate)

Proceed only if Phase 2 proves the solver produces reliable proposals and saves meaningful manual work:

1. **Pure TypeScript solver (`resources/js/admin/expedition-board/solver/`):** Port the validated `weights.json` scoring model to a greedy + Simulated Annealing solver running inside a browser Web Worker (zero PHP server load, zero WASM payload, tested against anonymised season fixtures in Vitest).
2. **In-browser wishlist review:** Add a lightweight fuzzy matcher with a quick review screen for unresolved names and cross-bucket mismatches.
3. **One-click modal:** Upgrade the **Auto-Plan** modal to run the Web Worker locally, display the trade-off summary, and post `TeamProposalDTO` directly to `/wp-json/ems/v1/teams/import-proposal`, keeping JSON export/import tucked under an **Advanced** toggle.

