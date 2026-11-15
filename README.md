# Expedition Management System (EMS) — Monorepo

The central operational hub and route card engine for coordinating Duke of Edinburgh (DofE) expeditions, managing teams, routes, training compliance, and participant registrations across South East Scotland Scouts.

---

## 1. Monorepo Architecture & Repository Structure

This repository consolidates the core Expedition Management System plugin (`ems-plugin`) and the Route Card & Terrain Engine (`ems-route-card-engine`) into a unified monorepo sharing an NPM workspace root, Docker environment, and automated packaging tools, while maintaining strictly isolated PHP Composer manifests for standalone WordPress deployment.

```
expedition-management-system/               # Monorepo Root
├── bin/                                    # Automation, deployment, and packaging CLI scripts
│   ├── deploy.sh                           # Builds and rsyncs plugins to local WordPress
│   ├── package.sh                          # Compiles standalone production dist/*.zip archives
│   ├── preflight-snapshot.sh               # Non-destructive JSON backup & parity generator
│   └── setup-workstation.sh                # Turnkey developer machine bootstrap script
├── packages/
│   └── contracts/                          # Shared TypeScript interfaces (RouteCard, Team, etc.)
│       ├── package.json
│       ├── tsconfig.json
│       └── src/index.ts
├── plugins/
│   ├── ems-plugin/                         # Core EMS WordPress Plugin (v1.0.x)
│   │   ├── assets/                         # Compiled JS & CSS production bundles
│   │   ├── resources/                      # React & TypeScript source files
│   │   │   ├── css/ems-admin.css
│   │   │   └── js/                         # SPAs (Expedition Board, Signups, Portals)
│   │   ├── src/                            # Modular PHP Kernel & Domain Modules (PSR-4: EMS\)
│   │   │   ├── Core/                       # Registry, Feature Flags, Event Bus, Error Boundaries
│   │   │   ├── Contracts/                  # Shared PHP interfaces & DTOs
│   │   │   ├── Data/                       # Relational repositories (Units, Explorers)
│   │   │   ├── Integrations/               # Anti-Corruption Adapters (OSM, Fluent, Tutor LMS)
│   │   │   ├── Modules/                    # Self-contained vertical domain modules
│   │   │   └── Admin/                      # WP Admin screens & settings
│   │   ├── tests/                          # PHPUnit (Unit tests, Brain Monkey) & Vitest suites
│   │   ├── composer.json                   # Isolated PHP dependencies (PSR-4: EMS\)
│   │   ├── package.json                    # Plugin-level npm workspace config
│   │   ├── phpstan.neon                    # PHPStan static analysis rules & baseline
│   │   ├── phpunit.xml                     # PHPUnit configuration
│   │   └── ems-plugin.php                  # WordPress plugin bootstrap header
│   └── ems-route-card-engine/              # Standalone & Companion Route Engine (v0.1.x)
│       ├── assets/                         # Compiled Route Card Studio JS & CSS
│       ├── bin/                            # Binary elevation tile compilation CLI
│       ├── mockdata/                       # Sample GPX routes and test DEM binary tiles
│       ├── resources/                      # React & TypeScript Route Card Studio source
│       ├── src/                            # Geodesy, DEM reader, PDF generator (PSR-4: EMSRoute\)
│       ├── tests/                          # PHPUnit & Vitest route calculation tests
│       ├── composer.json                   # Isolated PHP dependencies (PSR-4: EMSRoute\)
│       ├── package.json                    # Plugin-level npm workspace config
│       ├── phpstan.neon                    # PHPStan static analysis rules
│       ├── phpunit.xml                     # PHPUnit configuration
│       └── ems-route-card-engine.php       # WordPress plugin bootstrap header
├── wordpress-env/                          # Git-ignored local WordPress bind mount target
│   └── wp-content/
│       ├── plugins/                        # Deployed plugins (ems-plugin, ems-route-card-engine, oauth-login)
│       └── uploads/
│           └── ems-dem/                    # OS Terrain 50 binary elevation tiles (*.bin)
├── docker-compose.yml                      # WordPress 6.7 + MariaDB 10.11 local development stack
├── package.json                            # Root NPM Workspaces definition
├── tsconfig.base.json                      # Shared TypeScript compiler options
└── MONOREPO_MIGRATION_RUNBOOK.md           # Migration, verification gates, and release runbook
```

> [!NOTE]
> **External Standalone Repository (`login-with-google` / `oauth-login`):**
> Per architectural policy, `login-with-google` remains in its own standalone repository alongside this monorepo (`../login-with-google`). When present, `bin/setup-workstation.sh` and `bin/deploy.sh` automatically synchronize and activate it in the local Docker environment without modifying its standalone repository structure.

---

## 2. Core Architecture & Blast-Radius Isolation

### Modular Kernel (`ems-plugin`)
To isolate feature failures and prevent third-party integrations from crashing WordPress, `ems-plugin` employs a modular kernel inside `src/Core/`:
- **`ModuleInterface` & `Module_Registry`**: Every domain feature boots through an isolated `boot()` / `register()` boundary. If a module throws an unhandled exception during boot, `Module_Registry` catches it, records the failure in `wp_ems_audit_logs`, marks that single module as degraded, and continues booting all other modules.
- **`Feature_Flags`**: Modules (`signups`, `expeditions`, `volunteers`, `leader_dashboard`, `portal`, `notifications`) can be toggled on/off dynamically in **EMS → Settings → Feature Flags** or via WP-CLI (`wp option update ems_feature_flags '{"volunteers":false}' --format=json`), with constant overrides (`EMS_DISABLE_MODULE_*`).
- **`Shortcode_Boundary`**: Every public shortcode (`[ems-portal]`, `[ems-leader-dashboard]`, `[ems-volunteer-signup]`, `[ems_signup_banner]`) is wrapped in an execution boundary that intercepts fatal errors and renders a styled fallback maintenance card rather than white-screening the public page.
- **Anti-Corruption Adapters (`src/Integrations/`)**: Domain modules never make direct calls to third-party APIs. `TutorLmsAdapter` degrades gracefully to `TrainingStatusDTO::unavailable()` if Tutor LMS is deactivated, while `FluentFormsAdapter` maps raw form fields into typed DTOs.
- **Frontend Error Boundaries (`<ModuleErrorBoundary />`)**: React SPAs wrap distinct widgets and tabs so a runtime render fault in one panel shows a retry card without disrupting sibling tabs or navigation.

### Route Card & Local Terrain Engine (`ems-route-card-engine`)
- **Standalone Operability**: Operates as a companion to `ems-plugin` or as an independent standalone plugin on external WordPress instances.
- **Local Binary Elevation Engine**: $O(1)$ random-access elevation lookups from headerless `int16_le` decimetre grids (55 Ordnance Survey OS Terrain 50 `.bin` tiles, 8 MB per 100 km grid square).
- **In-Browser Route Card Studio**: Zero-latency waypoint insertion, bearing calculation, and Naismith's Rule time calculations with eDofE-compatible PDF generation.

### Shared Database Schema

#### Custom Post Types (CPTs)
- `expedition`: Top-level post representing an expedition event (dates, mode of travel, Leader-in-Charge, checkpoints).
- `team`: Child post linked to an `expedition` post ID (team code, GPX route submission, route card state).

#### Relational MariaDB Tables (`wp_ems_*`)
1. `wp_ems_osm_explorers`: Synced cache of Online Scout Manager explorers (`scout_id`, contact, patrol).
2. `wp_ems_units`: Directory of Explorer Scout Units and leader contacts.
3. `wp_ems_unit_patrols`: Mapping between OSM sections/patrols and master units.
4. `wp_ems_participant_signups`: Form 6 Participation Place registrations.
5. `wp_ems_expedition_signups`: Form 7 Expedition Preference submissions.
6. `wp_ems_team_members`: Relational links connecting explorers to teams.
7. `wp_ems_volunteers`: Registered adult helpers and DBS credentials.
8. `wp_ems_volunteer_availability`: Day and overnight availability cover board.
9. `wp_ems_route_submissions`: Route card version histories and GPX uploads.
10. `wp_ems_audit_logs`: Operational action log and fault boundary records.

---

## 3. Developer Workstation Setup

### Prerequisites
- Docker & Docker Compose
- PHP ≥ 8.2 & Composer ≥ 2
- Node.js ≥ 18 & npm ≥ 9
- `rsync`, `zip`, `unzip`, and `curl`

### Turnkey Bootstrap
Run the idempotent workstation bootstrap script to configure Git hooks, install dependencies, start containers, initialize WordPress, install plugins, and seed DEM mock tiles:

```bash
bash bin/setup-workstation.sh
```

Once running, access the local environment:
- WordPress HTTPS: `https://localhost` or `http://localhost:8080`
- WP Admin: `https://localhost/wp-admin` (User: `admin`, Password: `password`)
- EMS Expedition Board: `https://localhost/wp-admin/admin.php?page=ems-expedition-board`
- Route Card Studio Diagnostic: `https://localhost/wp-admin/tools.php?page=ems-route-card-engine`

---

## 4. Test Procedures & Verification

EMS enforces strict quality gates across both plugins. All automated suites must pass with zero failures before release.

### Root Monorepo Commands
```bash
# Run all frontend Vitest suites across both plugins
npm run test

# Run PHPUnit across plugins
npm run test:php:ems
npm run test:php:rce

# Run PHPStan static analysis across plugins
npm run stan:ems
npm run stan:rce

# Full quality verification pipeline (Vitest + Build + PHPUnit + PHPStan)
npm run verify:all
```

### Core Plugin Tests (`plugins/ems-plugin`)
```bash
# 1. PHPUnit Suite (Unit, Service, and Fault-Isolation tests)
composer --working-dir=plugins/ems-plugin exec phpunit

# Run specific fault-isolation tests
composer --working-dir=plugins/ems-plugin exec phpunit -- --filter Module_RegistryTest
composer --working-dir=plugins/ems-plugin exec phpunit -- --filter Shortcode_BoundaryTest

# 2. PHPStan Static Analysis (Level 5+)
composer --working-dir=plugins/ems-plugin exec -- phpstan analyse --memory-limit=2G

# 3. Vitest Frontend Suites
npm run test --workspace=plugins/ems-plugin -- --run
```

### Route Card Engine Tests (`plugins/ems-route-card-engine`)
```bash
# 1. PHPUnit Suite (Geodesy, DEM reader, and Naismith calculation tests)
composer --working-dir=plugins/ems-route-card-engine exec phpunit

# 2. PHPStan Static Analysis
composer --working-dir=plugins/ems-route-card-engine exec -- phpstan analyse --memory-limit=2G

# 3. Vitest Frontend Suites (RouteCardStudio, calculations, leg cards)
npm run test --workspace=plugins/ems-route-card-engine -- --run
```

### Architectural Boundary Enforcement Checks
Verify that domain modules never call external functions or hooks directly outside of designated adapters:
```bash
# Verify zero direct calls to tutor_utils() in domain modules
grep -rn "tutor_utils" plugins/ems-plugin/src/Modules/

# Verify zero fluentform/* hooks outside of src/Integrations/
grep -rn "fluentform" plugins/ems-plugin/src/Modules/
```

### Database Parity & Snapshot Verification
Generate application-layer JSON snapshots and parity manifests:
```bash
# Local container snapshot
bash bin/preflight-snapshot.sh --mode=local --out=/tmp/ems-snapshot/

# Remote server snapshot
bash bin/preflight-snapshot.sh --mode=remote --url=https://staging4.see-expeditions.org.uk --token=$TOKEN --out=/tmp/ems-snapshot/
```

---

## 5. Build Procedures

Production assets are compiled with Vite and TypeScript:

```bash
# Build all workspaces (packages/contracts, ems-plugin, ems-route-card-engine)
npm run build

# Build ems-plugin frontend assets only
npm run build --workspace=plugins/ems-plugin

# Build ems-route-card-engine frontend assets only
npm run build --workspace=plugins/ems-route-card-engine
```

### Compiling Elevation Tiles (CLI)
To convert raw Ordnance Survey ASCII grid tiles (`.asc`) into binary decimetre `.bin` tiles:
```bash
php plugins/ems-route-card-engine/bin/build-terrain-tile.php --input=/path/to/asc/ --output=wordpress-env/wp-content/uploads/ems-dem/
```

---

## 6. Deployment & Release Procedures

### Local WordPress Deployment (`bin/deploy.sh`)
Builds production frontend assets, installs Composer dependencies, rsyncs plugin directories into the local WordPress container, and verifies activation:

```bash
# Deploy both plugins
bash bin/deploy.sh all

# Deploy only ems-plugin
bash bin/deploy.sh ems

# Deploy only ems-route-card-engine
bash bin/deploy.sh rce

# Deploy without rebuilding JS/CSS (faster for PHP-only updates)
bash bin/deploy.sh all --skip-build
```

### Production Release Packaging (`bin/package.sh`)
Packages self-contained, standalone WordPress plugin `.zip` archives into `./dist/`. Each archive contains top-level `<slug>/` directory structure, compiled assets, and production-only autoloaders (`composer install --no-dev --optimize-autoloader`), with test suites and developer files stripped:

```bash
# Package both plugins (auto-bumps patch version)
bash bin/package.sh all

# Package without version bumping
bash bin/package.sh all --no-bump

# Package specific plugin with version increment
bash bin/package.sh ems --bump=patch
bash bin/package.sh rce --no-bump
```

Generated release artifacts:
- `dist/ems-plugin-<version>.zip`
- `dist/ems-route-card-engine-<version>.zip`

### Staging & Production Promotion Workflow

Deployments to remote staging (`staging4.see-expeditions.org.uk`) and production (`see-expeditions.org.uk`) require zero command-line tools on the server:

1. **Pre-Deployment Backup:**
   Log into WordPress Admin → **EMS → Settings → Backups** and download the current Unified JSON Backup (or run `bash bin/preflight-snapshot.sh --mode=remote ...`).
2. **Build Release Packages:**
   Run `bash bin/package.sh all --no-bump` (or desired target) locally.
3. **Upload in WordPress Admin:**
   Navigate to **Plugins → Add New Plugin → Upload Plugin**, select the `.zip` archive from `dist/`, and click **Replace current with uploaded**.
4. **Post-Deployment Verification:**
   - Check **EMS → Settings → Feature Flags** to verify all modules report healthy status.
   - Run parity check: `bash bin/preflight-snapshot.sh --mode=remote ...` and verify table counts match pre-upload values.
5. **Rollback Procedure:**
   If any discrepancy occurs, navigate to **EMS → Settings → Backups → Restore** and upload the pre-deployment JSON backup.
