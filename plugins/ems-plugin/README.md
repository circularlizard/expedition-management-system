# EMS Core Plugin (`ems-plugin`)

The core WordPress plugin for the South East Scotland Scouts Expedition Management System (EMS). It provides Duke of Edinburgh (DofE) expedition coordination, team building, participant and preference registration reconciliation, volunteer cover management, and Online Scout Manager (OSM) synchronization.

---

## 1. Directory Structure

```
plugins/ems-plugin/
├── assets/                          # Compiled JS/CSS production bundles (Vite output)
│   ├── js/                          # Client bundles, SPAs, and stylesheets
│   └── build-manifest.json          # Build timestamp metadata
├── fluent/                          # Fluent Forms export definitions (Forms 6 & 7)
├── resources/                       # Frontend source files
│   ├── css/ems-admin.css            # Shared stylesheet
│   └── js/                          # React + TypeScript SPAs
│       ├── admin/                   # Expedition Board, Signups Board, Column Mapper
│       ├── leader-dashboard/        # Unit Leader Dashboard SPA
│       ├── portal/                  # Public Participant & Parent Portal SPA
│       ├── shared/                  # Reusable components (e.g. ModuleErrorBoundary)
│       └── volunteers/              # Volunteer availability wizard
├── src/                             # PSR-4 PHP Source (EMS\ namespace)
│   ├── Core/                        # Modular Kernel
│   │   ├── Module_Registry.php      # Fault-isolated module lifecycle manager
│   │   ├── Feature_Flags.php        # Dynamic module toggles (ems_feature_flags)
│   │   ├── Event_Bus.php            # Typed in-memory event dispatcher
│   │   ├── Shortcode_Boundary.php   # Safe shortcode execution boundary
│   │   ├── Backup_Manager.php       # Unified JSON backup & parity exporter
│   │   ├── Role_Manager.php         # WordPress custom roles & capabilities
│   │   ├── Table_Installer.php      # Custom SQL table creation & migrations
│   │   └── CPT_Registry.php         # Custom Post Types (expedition, team)
│   ├── Contracts/                   # Shared PHP interfaces and DTOs
│   │   ├── ModuleInterface.php      # Contract implemented by all domain modules
│   │   ├── TrainingComplianceInterface.php
│   │   └── DTOs/                    # Immutable data transfer objects
│   ├── Modules/                     # Vertical domain modules (booted via Module_Registry)
│   │   ├── Signups/                 # Form 6/7 reconciliation, Place Offers
│   │   ├── Expeditions/             # Expedition Board, Team Builder, LiC readiness
│   │   ├── Volunteers/              # Volunteer management & availability cover board
│   │   ├── LeaderDashboard/         # Unit mapping & [ems-leader-dashboard]
│   │   ├── Portal/                  # [ems-portal] and participant workflows
│   │   └── Notifications/           # Transactional email and cron chasers
│   ├── Integrations/                # Anti-Corruption Adapters
│   │   ├── TutorLms/                # TutorLmsAdapter (gracefully degrades on outage)
│   │   ├── FluentForms/             # FluentFormsAdapter (DTO mapper)
│   │   ├── OSM/                     # Online Scout Manager OAuth & sync clients
│   │   └── RouteEngine/             # EmsRouteBridgeAdapter
│   ├── Data/                        # Relational repositories (Units, Explorers)
│   └── Admin/                       # WP Admin menus (Menu_Manager), settings, diagnostic panels
├── tests/                           # Verification suites
│   ├── Unit/                        # PHPUnit test cases (EMSTestCase + Brain Monkey)
│   └── js/                          # Vitest component and logic tests
├── composer.json                    # Isolated PHP dependencies (PSR-4: EMS\)
├── package.json                     # NPM workspace dependencies & scripts
├── phpstan.neon                     # PHPStan static analysis configuration
├── phpstan-baseline.neon            # PHPStan baseline exceptions
├── phpunit.xml                      # PHPUnit configuration
├── vite.config.ts                   # Vite multi-entry production build config
├── vitest.config.ts                 # Vitest testing environment configuration
└── ems-plugin.php                   # Main WordPress plugin bootstrap header
```

---

## 2. Architecture & Modular Isolation

`ems-plugin` implements a modular kernel designed for blast-radius isolation:
- **Module Isolation:** Every module in `src/Modules/` implements `EMS\Contracts\ModuleInterface`. During boot, `Module_Registry` catches unhandled exceptions per-module, logs them to `wp_ems_audit_logs`, and continues booting other modules.
- **Dynamic Feature Flags:** Modules can be toggled without code deployment via `Feature_Flags` (persisted in WordPress option `ems_feature_flags` or overridden via `EMS_DISABLE_MODULE_*` constants).
- **Shortcode Fault Boundaries:** Shortcodes execute within `Shortcode_Boundary::execute()` so any runtime fatal renders an inline notification card rather than causing a white screen of death.
- **Anti-Corruption Adapters:** Direct calls to external third-party functions (such as `tutor_utils()`) are prohibited in `src/Modules/`. External systems are accessed exclusively through adapters in `src/Integrations/` that provide fallback states (`TrainingStatusDTO::unavailable()`) if a third-party plugin is deactivated.
- **Unified Admin Menu Coordination:** All WordPress admin menu items (top-level `ems` and its submenus) are registered through a single coordinator, `EMS\Admin\Menu_Manager`, hooked at `admin_menu` (priority 10). Individual modules and controllers do not attach separate `admin_menu` hooks with scattered priority offsets. Submenus are registered in explicit call order:
  1. **Signups** (`admin.php?page=ems`)
  2. **Expeditions** (`admin.php?page=ems-expeditions`)
  3. **Explorers** (`admin.php?page=ems-explorers`)
  4. **Units & Leaders** (`admin.php?page=ems-units`)
  5. **Volunteers** (`admin.php?page=ems-volunteers`)
  6. **Route Studio** (`admin.php?page=ems-route-studio`)
  7. **OSM Sync** (`admin.php?page=ems-reference`)
  8. **Settings** (`admin.php?page=ems-settings`)
  9. **Training Report** (`admin.php?page=ems-training-report`)
  Utility screens such as **Column Mapper** (`admin.php?page=ems-column-mapper`) are registered with `$parent_slug = null`, keeping them accessible for modal/direct flows without displaying in the sidebar menu.

---

## 3. Testing Procedures

From within `plugins/ems-plugin`:

```bash
# Run PHPUnit test suite (Unit & Service tests via Brain Monkey stubs)
composer exec phpunit

# Run specific unit test or filter
composer exec phpunit -- --filter Module_RegistryTest
composer exec phpunit -- --filter Shortcode_BoundaryTest

# Run PHPStan static analysis (Level 5+)
composer exec -- phpstan analyse --memory-limit=2G

# Run Vitest frontend test suite
npm run test -- --run
```

Alternatively, from the monorepo root:
```bash
npm run test:php:ems
npm run stan:ems
npm run test --workspace=plugins/ems-plugin -- --run
```

---

## 4. Build Procedures

Compile production frontend assets with Vite:

```bash
# Inside plugins/ems-plugin
npm run build

# Or from monorepo root
npm run build --workspace=plugins/ems-plugin
```

Assets are written to `plugins/ems-plugin/assets/` and registered with cache-busting version hashes.

---

## 5. Deployment & Release

- **Local Deployment:** Run from monorepo root:
  ```bash
  bash bin/deploy.sh ems
  ```
- **Production Packaging:** Builds a clean staging environment and packages `dist/ems-plugin-<version>.zip` with production Composer autoloader (`--no-dev`) and dev dependencies stripped:
  ```bash
  bash bin/package.sh ems --no-bump
  ```
