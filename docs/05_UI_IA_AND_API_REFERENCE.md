# UI, Information Architecture & API Reference

## 1. WordPress Admin Information Architecture (7 Consolidated Hubs)

All `wp-admin` pages are registered by `Menu_Manager` under the top-level **EMS** menu (`manage_options` capability):

| # | Menu Title | Slug | Implementation & Active Tabs |
| :--- | :--- | :--- | :--- |
| 1 | **EMS / Sign-ups** | `ems` | React SPA (`signups-board.js` → `#ems-signups-root`). Tabs: `dashboard` (**Dashboard**), `table` (**All Sign-ups**), `anomalies` (**Anomalies & Chasers**). |
| 2 | **Expeditions & Teams** | `ems-expeditions` | React SPA (`expedition-board.js` → `#ems-expedition-board-root`). Tabs: `dashboard` (**Expeditions**), `date-grid` (**Availability**), `team-builder` (**Teams**), `readiness` (**Readiness**), plus `detail` and `create` sub-views (`?tab=planning` maps to `team-builder`). |
| 3 | **Volunteers** | `ems-volunteers` | React SPA (`volunteers.js` → `#ems-volunteers-root`). Tabs: `staffing` (**Event Staffing Console**), `registry` (**Volunteer Registry**). |
| 4 | **Units & OSM Sync** | `ems-units-sync` | Hybrid PHP + React (`#ems-explorers-root`, `#ems-pushback-root`). 7 tabs: `units` (**Units & Districts**), `leaders` (**Leaders & Access**), `explorers` (**Explorers**), `events` (**Events**), `sections` (**Managed Sections**), `pushback` (**Pushback Sync**), `diagnostics` (**Diagnostics**). |
| 5 | **Route Studio** | `ems-route-studio` | React SPA (`route-card-studio.js` → `#ems-route-card-studio-root`). 3 tabs: `sandbox` (**Route Card Studio & Analysis**), `dem_manager` (**Local DEM Tile Manager**), `settings` (**Map & API Settings**). |
| 6 | **Emails & Notifications** | `ems-emails` | React SPA (`email-templates.js` → `#ems-email-templates-root`) + WP TinyMCE editor. Category-grouped template editor and test email sender. |
| 7 | **Settings** | `ems-settings` | PHP Server-Rendered. 8 tabs: `general` (**General**), `connection` (**OSM Connection**), `form_mappings` (**Form Mappings**), `backups` (**Backups**), `audit_logs` (**Audit Logs**), `access_control` (**Access Control**), `deadlines` (**Deadlines & Cutoffs**), `help` (**Help & Shortcodes**). |

### Legacy Admin URL Redirects

`Menu_Manager::handle_legacy_redirects()` handles `GET` requests in `is_admin()` (skipping `POST` and AJAX):
- `ems-explorers` → `ems-units-sync&tab=explorers`
- `ems-column-mapper` → `ems-units-sync&tab=units`
- `ems-reference` → `ems-units-sync` (`unit_mapping&view=leaders` → `leaders`; `unit_mapping` / `patrols` / `osm_patrols` → `units`; `explorers|events|sections|pushback|diagnostics` → matching tab)
- `ems-units` → `ems-emails` (`?tab=email`) or `ems-units-sync&tab=units|leaders`
- `ems-units-sync&tab=patrols|osm_patrols|mapper` → `ems-units-sync&tab=units`
- `ems-training-report` → `ems-expeditions&tab=readiness`
- `ems-email-templates` → `ems-emails`
- `ems-settings&tab=leader_invite|chaser_emails` → `ems-emails`

---

## 2. Front-End Shortcodes

Five shortcodes are registered in the plugin:

| Shortcode | Feature Flag | Mount / Renderer | Purpose & Tabs |
| :--- | :--- | :--- | :--- |
| **`[ems-portal]`** | `portal` | `ems-portal` → `#ems-portal-root` | Explorer & Parent portal. 4 tabs: `expeditions` (**My Expeditions**), `training` (**Training**), `route` (**Route Planner**), `signups` (**My Sign-ups**). |
| **`[ems-leader-dashboard]`** | `leader_dashboard` | `ems-leader-dashboard` → `#ems-leader-dashboard-root` | Unit Leader portal (requires `access_ems_leader_portal` or `manage_options`). 2 tabs: `explorers`, `summary`. |
| **`[ems-volunteer-signup]`** | `volunteers` | `ems-volunteer-signup` → `#ems-volunteer-signup-root` | 4-step `VolunteerSignupWizard` for public volunteer registration. |
| **`[ems_signup_banner]`** | — | Server-rendered HTML + `ems-portal` CSS | OSM login & OTP sign-up gate banner (underscore syntax only; restricts logged-in access to `administrator`, `ems_parent`, `ems_network_member`). |
| **`[ems_route_card_studio]`** | — | `ems-route-card-studio` → `#ems-route-card-studio-root` | Embeddable Route Card Studio (`expedition_id`, `team_id`, `day` attributes; hides DEM Manager and Settings tabs). |

> **Note:** `[ems-lic-dashboard]` and hyphenated `[ems-signup-banner]` are not implemented in the codebase.

---

## 3. Frontend Entrypoints, Shared Components & CSS

### Vite Entrypoints (`vite.config.ts`)

All 9 entrypoints compile to `assets/js/[name].js` with `assets/build-manifest.json`:
- `expedition-board` (`resources/js/admin/expedition-board/index.tsx` — mounts `#ems-expedition-board-root`, `#ems-explorers-root`, `#ems-pushback-root`)
- `signups-board` (`resources/js/admin/signups-board/index.tsx`)
- `email-templates` (`resources/js/admin/email-templates/index.tsx`)
- `volunteers` (`resources/js/admin/volunteers/index.tsx`)
- `volunteer-signup` (`resources/js/admin/volunteers/signup-wizard.tsx`)
- `ems-portal` (`resources/js/portal/index.tsx`)
- `leader-dashboard` (`resources/js/leader-dashboard/index.tsx`)
- `fluent-forms-sync` (`resources/js/fluent-forms/index.ts`)
- `route-card-studio` (`resources/js/route-card/standalone/index.tsx`)

### Shared UI Components

- `ModuleErrorBoundary` (`resources/js/shared/ModuleErrorBoundary.tsx`) — React error boundary for admin boards, portal, and leader dashboard.
- `TeamEmailDispatchModal` (`resources/js/shared/TeamEmailDispatchModal.tsx`) — Recipient preview, warnings, sample email preview, and batch dispatch (`place_offer` | `compliance_reminder`).
- `TeamReadinessTable` (`resources/js/shared/TeamReadinessTable.tsx`) — Readiness matrix (team size, offer acceptance counts, First Aid Rule of Two, Tutor LMS summary, route plan status).
- `Explorer360Drawer` (`resources/js/admin/shared/Explorer360Drawer.tsx`) — Slide-over Explorer 360° profile and history drawer used by Sign-ups and Expeditions boards.

### Modular CSS Architecture (`resources/css/`)

- `tokens.css` — Shared CSS custom properties (`--ems-theme-color*`, `--ems-bg-main`, `--ems-card-bg`, `--ems-border`, `--ems-primary*`, `--ems-accent*`, `--ems-radius*`, `--ems-shadow-*`).
- `ems-admin.css` — Imports FontAwesome, `tokens.css`, `admin/base.css`, `admin/expeditions.css`, `admin/signups.css`, and `admin/components.css`.
- `ems-route-card.css` — Imports Leaflet CSS, `tokens.css`, `route-card/studio.css`, and `route-card/table.css`.
- `ems-portal.css` — Frontend shortcode stylesheet (imports FontAwesome and defines matching `:root` tokens inline).

---

## 4. Active REST API Reference

### Namespace: `/wp-json/ems/v1`

#### System, Backups, Audit & Sync (`manage_options`)
- `GET /sync-status` (`Plugin`) — Current OSM sync status.
- `GET /backups/export`, `POST /backups/import`, `GET /system/parity-manifest` (`Backup_Manager`).
- `GET /audit-logs` (`Audit_Log_Controller`).
- `GET /admin/sync-preview`, `POST /admin/sync-push`, `GET|DELETE /admin/sync-status` (`Sync_Preview_Controller`).

#### Emails & Chasers (`manage_options`)
- `GET /email-templates`, `PUT /email-templates/{slug}`, `POST /email-templates/{slug}/reset`, `POST /email-templates/{slug}/test` (`Email_Templates_Controller`).
- `GET /signups/chaser-history`, `POST /signups/send-chaser`, `GET /signups/{type}/{id}/history` (`Signup_Chaser_Controller`).

#### Sign-ups, Explorers & Expedition Admin (`manage_options`)
- `GET /explorer/{scout_id}`, `GET /explorers/360`, `GET /team/{team_id}`, `GET /patrol/{patrol}`, `GET|POST /events/{id}/training-requirements` (`Admin_View_Controller`).
- `GET|POST /events`, `POST|PUT|PATCH|DELETE /events/{id}`, `GET|POST /events/{id}/whatsapp` (`Expedition_Admin_Controller`).
- `GET|POST /events/{event_id}/teams`, `DELETE /teams/{id}`, `POST|PUT|PATCH /teams/{id}/move`, `POST /teams/{id}/duplicate`, `POST /teams/{team_id}/members`, `DELETE /teams/{team_id}/members/{scout_id}` (`Expedition_Admin_Controller`).
- `POST|PUT|PATCH /explorers/{scout_id}/move-team`, `POST|PUT|PATCH /explorers/{scout_id}/first-aid`, `GET|POST /explorers/{scout_id}/asn`, `GET /explorers/{scout_id}/profile`, `POST /explorers/{scout_id}/sync-contacts-from-osm`, `GET /explorers` (`Expedition_Admin_Controller`).
- `GET /expedition-board`, `GET /osm-events` (`Expedition_Admin_Controller`).
- `GET /planning-board/availability/{event_code}`, `POST /planning-board/allocate`, `POST /planning-board/add-explorer`, `GET /planning-board/synced-explorers`, `GET /planning-board/matrix`, `POST /planning-board/explorers/{scout_id}/asn-validation` (`Expedition_Admin_Controller`).
- `GET /signups`, `POST /signups/reconcile`, `POST /signups/unlink`, `POST /signups/unit`, `POST /signups/level`, `GET /signups/anomalies`, `PATCH|POST /signups/{id}/status`, `POST /signups/bulk-status`, `POST /signups/expeditions/{id}/place-allocated`, `PATCH|POST /signups/{type}/{id}/kanban`, `GET /signups/participants`, `GET /signups/participants/export`, `POST /signups/participants/{id}/process`, `POST /signups/participants/{id}/archive`, `GET /signups/expeditions`, `POST /signups/expeditions/{id}/archive`, `POST /signups/expeditions/{id}/asn-validation` (`Expedition_Admin_Controller`).

#### Team Builder, Readiness, Preferences & Solver (`manage_options`)
- `GET /teams-builder`, `POST /teams`, `DELETE /teams/{id}`, `POST /teams/{id}/members`, `DELETE /teams/{id}/members/{expedition_signup_id}`, `POST /teams/{id}/copy-to-event`, `POST /teams/resolve-query` (`TeamController`).
- `GET /teams-readiness`, `GET /expeditions/teams-readiness` (`TeamController`).
- `POST /notifications/expedition-offers/preview`, `POST /expeditions/notifications/preview`, `POST /notifications/expedition-offers/dispatch`, `POST /expeditions/notifications/dispatch` (`TeamController`).
- `POST /preferences/parse`, `GET /preferences/queue`, `GET /preferences/candidates`, `POST|PUT|PATCH /preferences/genders`, `POST|PUT|PATCH /preferences/{source_signup_id}` (`TeamController`).
- `GET|POST|PUT|PATCH /teams/solver/weights`, `POST /teams/solver/propose`, `POST /teams/solver/apply` (`TeamController`).

#### Volunteers & Unit Leaders
- `GET /volunteers`, `POST /volunteers/assign`, `POST /volunteers/save`, `POST /volunteers/availability` (`Volunteer_Controller`, `manage_options`).
- `POST /volunteers/signup`, `GET /volunteers/events` (`Volunteer_Controller`, public).
- `GET /unit-leaders`, `POST|PUT|PATCH /unit-leaders/{id}` (`Unit_Leader_Controller`, `manage_options`).

#### Front-End Portal & Leader Dashboard (`Portal_Controller`)
- `GET /portal/me` — Public/logged-in user context (supports admin preview).
- `GET /portal/explorer/{scout_id}` — `is_user_logged_in` + inline parent/scout ownership check.
- `POST /portal/offers/respond` — `is_user_logged_in` + inline ownership check + `DeadlinePolicyService` cutoff check.
- `GET /leader-dashboard/signups` — `access_ems_leader_portal || manage_options`.
- `GET /portal/admin/preview-targets` — `manage_options`.

### Namespace: `/wp-json/ems-route/v1`

- **Route Cards (`Route_Card_Controller`):**
  - `POST /upload-gpx`, `GET /card/{expedition_id}/{team_id}/{day}`, `POST /card/{id}/save`, `POST /card/{id}/submit`, `GET /card/{id}/pdf`, `GET /card/{expedition_id}/{team_id}/{day}/pdf`, `GET /card/{expedition_id}/{team_id}/pdf`, `POST /card/preview-pdf` — `is_user_logged_in() || edit_posts`.
  - `POST /card/{id}/review` — `manage_options` or `ems_user_can_review_route_cards` filter (defaults to `edit_others_posts`).
- **DEM Tiles (`DEM_Management_Controller`, `manage_options`):**
  - `GET /dem/tiles`, `POST /dem/upload`, `DELETE /dem/{square}`, `POST /dem/benchmark/{square}`.
- **Route Settings (`Settings_Controller`, `manage_options`):**
  - `GET|POST /settings`.
