# EMS Agent Coding Context

This file provides the essential context for any AI coding agent (Cascade, OpenCode, etc.) working on this codebase. Read it in full before writing any code.

---

## 1. Project Overview

A WordPress plugin (`ems-plugin`) managing Duke of Edinburgh expeditions. PHP backend + React/TypeScript frontend. All admin features live in the WP admin dashboard. No frontend-facing PHP rendering — all data served via REST API consumed by React SPAs registered as WP admin pages.

---

## 2. TDD Workflow — Mandatory

All work follows this sequence **without exception**:

1. Write Gherkin scenarios (`tests/features/*.feature`) for **observable behaviour** — business logic, REST API shape/auth, UI behaviour.
2. User reviews and validates scenarios.
3. Write failing PHPUnit / Vitest tests implementing the scenarios (red).
4. Implement production code until tests pass (green).
5. Refactor keeping tests green.

**CPT registration, meta field wiring, and table schema** are tested directly in PHPUnit (Brain Monkey stubs) — not via Gherkin.

---

## 3. Namespace & File Conventions

- PSR-4 autoload: `EMS\` → `src/`, `EMS\Tests\` → `tests/`
- Class naming: `Snake_Case` (e.g. `OSM_Section_Importer`, `Team_Repository`)
- Test files mirror `src/` under `tests/Unit/` (e.g. `src/Data/Team_Repository.php` → `tests/Unit/Data/Team_RepositoryTest.php`)
- Gherkin feature files: `tests/features/{stage}-{topic}.feature`

---

## 4. PHP Test Anatomy

All PHP tests extend `EMS\Tests\EMSTestCase` which sets up Brain Monkey and stubs common WP functions.

```php
<?php
namespace EMS\Tests\Data;

use EMS\Tests\EMSTestCase;
use EMS\Data\Team_Repository;
use Brain\Monkey\Functions;

class Team_RepositoryTest extends EMSTestCase {
    public function test_create_team_generates_sequential_code(): void {
        // Arrange
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('update_post_meta')->justReturn(true);
        // ... stub get_posts to return existing teams

        $repo = new Team_Repository();

        // Act
        $result = $repo->create_team(event_id: 10, event_code: 'H-SP1');

        // Assert
        $this->assertSame('H-SP1-1', $result['team_code']);
    }
}
```

Key patterns:
- `Functions\when('wp_fn')->justReturn($value)` — stub with fixed return
- `Functions\when('wp_fn')->alias(fn($arg) => ...)` — stub with logic
- `Functions\expect('wp_fn')->once()->with($expected)` — assert called once with args
- `Mockery::mock(InterfaceName::class)` — mock interfaces/classes
- `$this->expectException(\Exception::class)` — assert throws

Common stubs already in `EMSTestCase::setUp()`: `delete_transient`, `get_transient`, `set_transient`, `esc_html__`, `__`, `sanitize_text_field`, `esc_url_raw`, `update_option`, `current_time` (returns `'2026-06-13 20:00:00'`), `get_current_user_id` (returns `1`), `wp_die` (throws `\Exception`).

Add any additional stubs your test needs in the individual test `setUp()`.

---

## 5. Key Architecture Rules

### Identity anchor
**`ems_osm_explorers.scout_id` is the primary identity anchor for explorers — NOT `wp_users.ID`.** WP User accounts are NOT created during OSM sync. `wp_user_id` on `ems_osm_explorers` is nullable and only populated after an explorer logs in via OIDC.

When joining explorer data to team membership: `ems_team_members.user_id` → `ems_osm_explorers.wp_user_id` (nullable join). Always fall back to `scout_id` for identity matching.

### No token storage
OSM OAuth tokens are **never stored server-side**. The personal OAuth2 code flow is admin-triggered per sync action. Tokens are used once and immediately discarded.

### API mode
`ems_api_mode` WP Option controls the driver: `mock` | `live` | `live-auth-only` | `live-limited`. Always use `OSM_API_Client` — never call OSM directly.

---

## 6. DB Tables

All created by `Table_Installer` on plugin activation. Do not use `wpdb->prefix` — tables use literal `ems_` prefix.

| Table | Key columns |
|---|---|
| `ems_team_members` | `id, team_post_id, user_id, added_by, added_at` |
| `ems_volunteer_availability` | `id, user_id, expedition_post_id, date, overnight, confirmed, confirmed_by` |
| `ems_route_submissions` | `id, team_post_id, version, file_type, wp_media_id, submitted_by, submitted_at, feedback, status` |
| `ems_osm_explorers` | `id, scout_id (UNIQUE), wp_user_id (nullable), section_id, first_name, last_name, email, parent_email, patrol, synced_at` |
| `ems_osm_events` | `id, event_id, section_id, name, start_date, end_date, location, synced_at` |
| `ems_osm_event_attendance` | `id, event_id, scout_id, status, synced_at` |

---

## 7. Custom Post Types

| CPT slug | Admin label | Post parent |
|---|---|---|
| `season` | Season | — |
| `expedition` | Event | `season` post ID |
| `team` | Team | `expedition` post ID |

### `expedition` meta fields
`ems_event_code`, `ems_type` (`training`\|`practice`\|`qualifying`), `ems_transport` (`hillwalking`\|`biking`\|`paddling`), `ems_level` (`bronze`\|`silver`\|`gold`), `ems_lic_name`, `ems_lic_email`, `ems_lic_phone`, `ems_lic_id`, `ems_start_location`, `ems_end_location`, `ems_start_date`, `ems_start_time`, `ems_end_date`, `ems_end_time`, `ems_osm_event_id`, `ems_route_info`, `ems_route_deadline`, `ems_status`

### `team` meta fields
`ems_team_code`, `ems_team_number`, `ems_route_status`, `ems_route_feedback`, `ems_gpx_file_id`, `ems_route_card_file_id`

Team size 4–7 is the official range — outside this range flag a warning (not a hard block). A team with zero members must be deleted automatically.

---

## 8. REST API Conventions

- All endpoints: `ems/v1/` namespace
- All admin endpoints: `'permission_callback' => fn() => current_user_can('manage_options')`
- Error responses: `new WP_Error('ems_error_code', 'Human message', ['status' => 4xx])`
- Success responses: `new WP_REST_Response($data, 200)`
- Test with `WP_REST_Request` — do not make real HTTP calls in tests

---

## 9. WP User Meta Keys

Written by `OSM_Auth_Integration` on OIDC login:

| Key | Type | Description |
|---|---|---|
| `ems_osm_id` | int | OSM `user_id` |
| `ems_access_type` | string | `'parent'` \| `'member'` \| `'local'` |
| `ems_scout_ids` | int[] | OSM `member_id` list (serialized) |
| `ems_section_ids` | int[] | OSM section IDs this user administers |
| `ems_children` | array | Explorer records linked to parent account |
| `ems_unit` | string | Patrol/unit name from OSM |

---

## 10. WP Options

| Option key | Description |
|---|---|
| `ems_managed_sections` | `{section_id: {name, type}}` — sections under management |
| `ems_api_mode` | `mock` \| `live` \| `live-auth-only` \| `live-limited` |
| `ems_sync_limit` | int — member cap for `live-limited` mode (default 5) |
| `ems_osm_client_id` | OSM OAuth client ID |
| `ems_osm_client_secret` | OSM OAuth client secret (AES-256-CBC encrypted) |
| `ems_osm_api_base_url` | OSM API origin (e.g. `https://www.onlinescoutmanager.co.uk`) |
| `ems_osm_scope` | OAuth scope string |
| `ems_failed_pushback_queue` | Serialized array of failed OSM write jobs |
| `ems_osm_field_map` | `{section_id: {flexi_id, field_map: {ems_field: column_id}}}` |

---

## 11. Mock Data Files (`tests/mocks/`)

| File | Used by |
|---|---|
| `osm-get-data-payload-explorer.json` | Explorer OIDC login (userid 20001, scout 30001) |
| `osm-get-data-payload-parent.json` | Parent OIDC login (userid 20002, children 30001/30002) |
| `osm-list-of-members.json` | 127 members, scout IDs 3417257+, patrol IDs 99200+ |
| `osm-member-detail.json` | Keyed map `{scout_id: {email, parent_email}}` |
| `osm-events.json` | 2 events, IDs 40001/40002 |
| `osm-event-attendance.json` | All 127 members, varied status |
| `osm-flexi-record-structure.json` | Section 99001, extraid 99848 |
| `osm-flexi-record-data.json` | All 127 members, varied flexi fields |
| `osm-patrols.json` | Mock patrol IDs matching member list |

---

## 12. Running Tests

```bash
# PHP (runs on host, not inside container)
vendor/bin/phpunit

# JS
npm run test

# Single PHP test file
vendor/bin/phpunit tests/Unit/Data/Team_RepositoryTest.php
```

### UI feature tests

Gherkin UI scenarios are implemented with **Vitest + React Testing Library**. The UI component uses a data-fetch hook (e.g. `useExpeditionBoard`) that calls the REST API in production. In Vitest, the API hook is mocked with `vi.mock()` or inline mocked data so the component can render states and respond to interactions without a WordPress server. Full browser E2E tests (Playwright) are reserved for staging smoke tests.

---

## 13. OSM API Call Flow (authoritative)

1. `get_data_payload(token)` → `ext/generic/startup/` — sections + terms
2. `get_section_members(section_id, term_id)` → `ext/members/contact/`
3. Per member: `get_member_detail(section_id, scout_id, term_id)` → `ext/customdata/`
4. `get_section_events(section_id, term_id)` → `ext/events/summary/`
5. `get_event_attendance(section_id, event_id)` → `ext/events/summary/`
6. `get_flexi_structure(section_id, term_id)` → `ext/members/flexirecords/`

Never call OSM endpoints directly. Always use `OSM_API_Client` methods.
