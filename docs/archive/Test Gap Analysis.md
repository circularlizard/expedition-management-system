# Test Gap Analysis

*Generated: 2026-06-12*

## Overall

107 PHP + 8 JS tests — solid foundation, some gaps.

---

## Strengths

**Meta_Validator** (16 tests) — Excellent. Good data-driven patterns with `foreach`, covers valid/invalid for every enum, boundary values (0, -1), and the permissive-fallback design decision. Very high confidence.

**OSM_Parser** (17 tests) — Strong. Tests against real mock JSON payloads for both explorer and parent roles. Covers all parsing paths: IDs, access types, children aggregation, section dedup. Well structured.

**TutorLMS_Client** (18 tests) — Best suite in the project. The enrollment matrix tests cover every completion path: lesson-only, assignment-only, combined, reading info, comments fallback, CB content usage table. The `$wpdb` mock setup is clean and reusable.

**Rate_Limiter** (6 tests) — Time-injection pattern is well tested. Covers capacity, refill, and cap-at-max.

---

## Moderate Gaps

**CPT_Registry** (7 tests) — Only verifies `register_post_type` is called and a few args are present. Missing:
- `public`, `has_archive`, `rewrite` args
- Meta field registration calls (`register_meta`)
- Taxonomy registration (if any)
- Capability mapping

**OSM_Auth_Integration** (7 tests) — Good security coverage (token not stored, ADR 009), but missing:
- What happens when `get_data_payload` returns an empty array or throws an exception
- Verification that `ems_children` user meta is stored for parent users
- Verification that `ems_section_ids` are stored

**Reconciliation_Controller** (8 tests) — Core logic is well covered, but missing:
- Duplicate email handling within the same source (OSM or GF)
- Performance with large datasets (100+ entries)
- What happens when `get_entries` or `get_section_participants` returns `null` or malformed data

**Diagnostic_Panel** (5 tests) — Tests HTML output contains strings, which is brittle. Any copy change breaks tests. Should assert on structural elements rather than string content.

---

## Significant Missing Areas

**Plugin.php** — No integration test verifying the bootstrap sequence. If constructor wiring breaks (e.g., wrong driver selected, hooks not registered), nothing catches it.

**Table_Installer** — No test for SQL correctness or `get_table_names`. The `dbDelta` calls are untested.

**Settings_Page** — Good validation tests, but missing nonces, capability checks, and REST endpoint tests if the settings are exposed via API.

**OSM_API_Client** — Only tests delegation. Missing: rate limiter actually being called on each method, error handling when driver throws.

**Mock_Driver & Live_Driver** — Untested. `Mock_Driver` file-not-found fallback to `[]` isn't tested. `Live_Driver` stubs are fine for now but should have a sanity-check test.

**LoginWithGoogle_Auth_Provider** — Minimal. Tests only state storage. Missing: hook registration with `rtcamp`, what happens when `capture` is called twice (overwrite behavior).

**Gravity_Forms_Client** — Only the mock variant exists; it's mocked in tests rather than tested directly.

**JS Tests** — Only 8 tests for `ReconciliationDashboard`. Pure snapshot-style text matching. Missing: interaction tests (filtering, pagination), TypeScript type safety tests, error states.

---

## Recommendations (priority order)

1. **Add Plugin bootstrap test** — highest ROI, catches wiring bugs
2. **Add Table_Installer test** — SQL is hard to debug later
3. **Add error-path tests to OSM_Auth_Integration** — exception handling, API failure
4. **Add malformed input tests to Reconciliation_Controller** — null, missing email keys
5. **Replace string assertions in Diagnostic_Panel** with structural assertions
6. **Add OSM_API_Client error propagation tests**
