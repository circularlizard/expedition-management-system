# Implementation Plan Phase 1 — Completed Stages Archive

> This file archives the completed sections of `docs/Implementation Plan Phase 1.md`.
> Moved here to keep the live plan focused on active and upcoming work.
> Last updated: 15 June 2026.

---

## Environment & Infrastructure (Complete)

### 1. Development Environment

#### 1.1 Local Development (Docker)
- **Image**: `wordpress:php8.2-apache`, **Database**: `mariadb:10.11`, **Tools**: WP-CLI, Composer, Node/NPM.

#### 1.2 Testing Infrastructure
- **PHP**: `phpunit/phpunit` + `brain/monkey`
- **JS**: `vitest` + `@testing-library/react`
- **E2E**: `playwright` (staging only)

#### 1.3 Test Server
- Staging subdomain on SiteGround. CI/CD via GitHub Actions.

#### 1.4 Local HTTPS ✅
Caddy reverse-proxy (`caddy:2-alpine`) proxies `https://localhost:443` → `wordpress:80`. `WP_HOME`/`WP_SITEURL`/`FORCE_SSL_ADMIN` enforce HTTPS. One-time CA trust setup per machine:
```bash
docker compose up -d
docker compose cp caddy:/data/caddy/pki/authorities/local/root.crt ./caddy-local-ca.crt
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ./caddy-local-ca.crt
rm ./caddy-local-ca.crt
```

---

### 2. OSM Integration Strategy

#### 2.1 Authentication (OIDC) & Hydration
- Base plugin: `login-with-google` configured for OSM OIDC.
- EMS hooks `rtcamp.google_user_logged_in` → fetches `getDataPayload` → hydrates User Meta → discards token.
- All EMS-to-OSM operations use admin-triggered personal OAuth2 flow. No tokens stored.

#### 2.2 OSM Push-back Failure Handling
- On failure: job persisted to `ems_failed_pushback_queue` WP Option + admin notice with Retry button.
- Escalation path: WP Cron if retry frequency becomes operational problem.

#### 2.3 Rate Limiting & Performance
- Token-bucket `Rate_Limiter` with header-awareness (`x-ratelimit-*`).
- WordPress Transients for caching. Batch fetches where API allows.

#### 2.4 Mock Data Layer
- Driver pattern: `Live_Driver` (real HTTP) / `Mock_Driver` (static JSON from `tests/mocks/`).
- Switching via `ems_api_mode` WP Option or `EMS_TEST_MODE` constant.

---

### 3. CI/CD Pipeline

- GitHub Actions on push/PR: PHP lint → PHPUnit → Vitest.
- Deployment to staging: manual, after checks pass.
- Deployment to production: manual promotion from staging.

---

## Foundations — ✅ Complete

**Test counts at foundations baseline**: 107 PHP / 178 assertions. 8 JS tests. All green.

### Infrastructure & Tooling
- ✅ Docker images pinned; WP-CLI service; `.github/workflows/ci.yml`; `bin/package.sh`.

### OSM API Client
- ✅ `OSM_API_Client`, `Driver_Interface`, `Live_Driver`, `Mock_Driver`.
- ✅ `OSM_Parser` — parses `getDataPayload`, section participants, flexi-records, terms, member detail.
- ✅ `Rate_Limiter` (token-bucket with header-awareness).
- ✅ Mock payloads: `osm-get-data-payload.json`, `osm-events.json`, `osm-flexi-records.json`, `osm-list-of-members.json`, `osm-member-detail.json`, `osm-event-attendance.json`.

### Authentication
- ✅ `Auth_Provider` interface, `LoginWithGoogle_Auth_Provider`, `Mock_Auth_Provider`.
- ✅ `OSM_Auth_Integration` — hydrates User Meta on login; does not store `access_token`.

### Admin Foundation
- ✅ `expedition` and `team` CPTs; `Meta_Validator`.
- ✅ `Admin_Page` with menu structure: EMS → OSM Reference → Training Report → Flexirecord Mapper → Settings.
- ✅ `Settings_Page` — mock/live toggle, OSM OAuth client ID/secret (encrypted).
- ✅ `Diagnostic_Panel`.
- ✅ `Training_Report_Page` — Tutor LMS CSV report.

### Reconciliation
- ✅ `Gravity_Forms_Client`, `Reconciliation_Controller`.
- ✅ React `ReconciliationDashboard` — 8 Vitest tests.

---

## Stage 1.1 ✅ — Data Structures & Repositories

- `Expedition_Repository`, `Team_Repository`, `Team_Member_Repository` implemented and tested.
- `Table_Installer` creates all 6 custom tables on activation:
  - `ems_team_members`, `ems_volunteer_availability`, `ems_route_submissions`
  - `ems_osm_explorers`, `ems_osm_events`, `ems_osm_event_attendance`
- CPT meta: `ems_expedition_lic`, `ems_expedition_whatsapp`, `ems_expedition_route_info`.

---

## Stage 1.2 ✅ — Smart Rate Limiting & Live Driver

- `Rate_Limiter::update_from_headers()` implemented and tested.
- `Live_Driver` implemented with `wp_remote_get`, extracts rate-limit headers.
- `Mock_Driver` loads anonymised JSON from `tests/mocks/`.

---

## Stage 1.3 ✅ — Admin-Triggered Sync OAuth Handler

- `OSM_Sync_Auth_Handler::initiate()` → OSM authorization URL with nonce state.
- `handle_callback()` → exchanges code for token, fires sync callback, discards token.
- Redirect URI: `admin_url('admin-post.php?action=ems_osm_callback')`.
- State: `wp_create_nonce('ems_osm_sync')` / `wp_verify_nonce()`.
- Mock payload: `tests/mocks/osm-oauth-callback.json`.

---

## Stage 1.4 ✅ — Membership Pull (OSM Reference Sync)

- `OSM_Section_Importer` implemented (writes to `ems_osm_explorers`, not WP User Meta).
- `OSM_Reference_Sync` orchestrates full sync: members + events + attendance.
- **Term resolution**: `OSM_Parser::parse_terms()` + `find_current_term()` resolve active term per section from `getDataPayload`.
- **Per-member email fetch**: `get_member_detail(section_id, scout_id, term_id)` → `ext/customdata/?action=getData` (group_id=6, col 12=email, col 14=parent_email).
- `OSM_Reference_Sync::sync(array $section_ids, array $payload)` — payload required for term resolution.

### OSM API Call Flow (authoritative, as at June 2026)
1. `get_data_payload(token)` → sections + terms
2. `parse_section_ids()` → managed sections
3. `parse_terms()` → terms dict keyed by section_id
4. `find_current_term(terms, section_id)` → active term (falls back to most recent past)
5. `get_section_members(section_id, term_id)` → scoutid, firstname, lastname, patrolid, patrol (NO email)
6. `parse_members()` → member_id, first_name, last_name, patrol, patrol_id
7. Per member: `get_member_detail(section_id, scout_id, term_id)` → `parse_member_detail()` → email, parent_email
8. `get_section_events(section_id, term_id)` + `get_event_attendance(section_id, event_id)`

---

## Stage 1.5 ✅ — Flexi-Record Column Mapper

- `Flexi_Structure_Parser`, `Flexi_Column_Map`, `Flexi_Mapper_Controller`.
- React `ColumnMapper` component with Vitest tests.
- Mock: `osm-flexi-record-structure.json`.

---

## Stage 1.6 ✅ — Flexi-Record Import

- `Flexi_Record_Importer` — three-bucket parsing (clean/partial/unparseable), commit step, `ems_osm_last_sync` written on success.
- `Flexi_Record_Importer` matches `scout_id` against `ems_osm_explorers`.
- React `ImportReview` component with Vitest tests.
- Mock: `osm-flexi-record-data.json`.

---

## Tooling Added

- **`bin/reset-db.php`** — truncates all 6 EMS tables + deletes `ems_*` options:
  ```bash
  docker compose run --rm wpcli eval-file wp-content/plugins/ems-plugin/bin/reset-db.php
  ```
- **`bin/seed-settings.php`** — re-seeds API mode, OSM URLs, managed sections.

---

## Technical Reference

### WP User Meta Keys (written by `OSM_Auth_Integration`)
| Key | Type | Description |
| --- | --- | --- |
| `ems_osm_id` | int | OSM `user_id` |
| `ems_access_type` | string | `'parent'` \| `'member'` \| `'local'` |
| `ems_scout_ids` | int[] | OSM `member_id` list (serialized) |
| `ems_section_ids` | int[] | OSM section IDs this user administers |
| `ems_children` | array | Explorer records linked to parent account |
| `ems_unit` | string | Patrol/unit name from OSM |

### DB Tables (created by `Table_Installer`)
- `ems_team_members` — `id, team_post_id, user_id, added_by, added_at`
- `ems_volunteer_availability` — `id, user_id, expedition_post_id, date, overnight, confirmed, confirmed_by`
- `ems_route_submissions` — `id, team_post_id, version, file_type, wp_media_id, submitted_by, submitted_at, feedback, status`
- `ems_osm_explorers` — `id, scout_id (UNIQUE), wp_user_id (nullable), section_id, first_name, last_name, email, parent_email, patrol, synced_at`
- `ems_osm_events` — `id, event_id, section_id, name, start_date, end_date, synced_at`
- `ems_osm_event_attendance` — `id, event_id, scout_id, status, synced_at`

### WP Options Written in Phase 1
| Option key | Written by | Description |
| --- | --- | --- |
| `ems_managed_sections` | Admin Settings | Section IDs + config |
| `ems_flexirecord_column_map` | `Flexi_Column_Map` | EMS field → OSM column_id map |
| `ems_osm_last_sync` | Flexi_Record_Importer commit | ISO 8601 UTC timestamp |
| `ems_osm_client_id` | Admin Settings | OSM OAuth client ID |
| `ems_osm_client_secret` | Admin Settings | OSM OAuth client secret (encrypted) |

### Coding Conventions
- PSR-4: `EMS\` → `src/`, `EMS\Tests\` → `tests/`
- Class naming: `OSM_Section_Importer` style
- Tests extend `EMS\Tests\EMSTestCase`; use `Brain\Monkey\Functions` for WP stubs; `Mockery` for interfaces
- REST endpoints: `ems/v1/` prefix, `manage_options` permission_callback
- Auto-upgrade: `Plugin::maybe_upgrade()` on `plugins_loaded` if `ems_db_version` != `EMS_VERSION`
