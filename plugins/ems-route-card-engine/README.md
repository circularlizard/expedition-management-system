# EMS Route Card & Local Terrain Engine (`ems-route-card-engine`)

A companion WordPress plugin for the Expedition Management System (EMS) and standalone route card builder for Duke of Edinburgh (DofE) expeditions. It provides local Ordnance Survey (OS Terrain 50) binary elevation analysis, an interactive GPX route card studio, Naismith's Rule timing calculations, and eDofE-compatible PDF export.

---

## 1. Directory Structure

```
plugins/ems-route-card-engine/
├── assets/                          # Compiled Route Card Studio JS & CSS bundles
├── bin/                             # Binary tile compiler CLI scripts
│   ├── build-terrain-tile.php       # CLI script converting ASCII .asc to binary .bin
│   ├── deploy.sh                    # Plugin-level deployment helper
│   └── package.sh                   # Plugin-level packaging helper
├── mockdata/                        # Sample GPX files and mock elevation tiles
├── resources/                       # Frontend source files (React + TypeScript)
│   ├── css/ems-route-card.css       # Route card styling
│   └── js/                          # React Route Card Studio components
│       ├── components/              # RouteCardBuilder, RouteLegCard, RouteElevationProfile
│       ├── standalone/              # Diagnostic Studio entrypoint
│       ├── types/                   # TypeScript interfaces (RouteCard, Leg, Waypoint)
│       └── utils/                   # Geodesy & Naismith calculation utilities
├── src/                             # PSR-4 PHP Source (EMSRoute\ namespace)
│   ├── Admin/                       # Diagnostic Studio controller (Tools → Route Card Engine)
│   └── Core/
│       ├── Context/                 # EMS companion context bridge & fallback provider
│       ├── DEM/                     # Binary tile reader (OS_Terrain_Binary_Reader) & storage
│       ├── Geodesy/                 # OSGB36 to WGS84 coordinates converter
│       ├── GPX/                     # GPX upload parser & enrichment service
│       ├── PDF/                     # eDofE-compliant PDF exporter
│       ├── REST/                    # Endpoints under /wp-json/ems-route/v1/
│       ├── Storage/                 # Route card repository
│       ├── Timing/                  # Naismith's Rule calculator
│       ├── Plugin.php               # Main plugin lifecycle controller
│       └── Table_Installer.php     # Custom database table installer
├── tests/                           # Verification suites
│   ├── js/                          # Vitest route calculation & UI unit tests
│   └── php/                         # PHPUnit tests (DEM reader, Naismith, Geodesy)
├── composer.json                    # Isolated PHP dependencies (PSR-4: EMSRoute\)
├── package.json                     # NPM workspace dependencies & scripts
├── phpstan.neon                     # PHPStan static analysis configuration
├── phpunit.xml                      # PHPUnit configuration
├── vite.config.ts                   # Vite build configuration
├── vitest.config.ts                 # Vitest testing configuration
└── ems-route-card-engine.php        # WordPress plugin bootstrap header
```

---

## 2. Key Capabilities & Standalone Isolation

- **Standalone Operability:** Can be deployed to any standard WordPress site without `ems-plugin`. In standalone mode, `Context_Manager` activates a standalone fallback context provider.
- **Local Binary Elevation Engine:** Decodes headerless `int16_le` decimetre grids ($8\text{ MB}$ per $100\text{ km}$ OS Grid square) in $O(1)$ random-access time.
- **In-Browser Route Card Studio:** Enriches route vertices upon upload; supports splitting, merging, bearing calculation, and rest stop adjustments entirely in the browser.
- **Diagnostic Studio:** Hosted at **Tools → Route Card Engine**, providing interactive DEM tile integrity checks, coordinates conversion testing, and GPX testing.
- **EMS Companion Integration:** When `ems-plugin` is present, automatically receives expedition dates, rosters, and supervisor details.

---

## 3. Testing Procedures

From within `plugins/ems-route-card-engine`:

```bash
# Run PHPUnit test suite (DEM reader, Geodesy, Naismith timing)
composer exec phpunit

# Run PHPStan static analysis
composer exec -- phpstan analyse --memory-limit=2G

# Run Vitest frontend test suite
npm run test -- --run
```

Alternatively, from the monorepo root:
```bash
npm run test:php:rce
npm run stan:rce
npm run test --workspace=plugins/ems-route-card-engine -- --run
```

---

## 4. Build Procedures

Compile the Route Card Studio frontend assets:

```bash
# Inside plugins/ems-route-card-engine
npm run build

# Or from monorepo root
npm run build --workspace=plugins/ems-route-card-engine
```

Compiled bundles are output to `assets/js/route-card-studio.js` and `assets/js/route-card-studio.css`.

### Compiling OS Terrain 50 Binary Tiles
```bash
php bin/build-terrain-tile.php --input=/path/to/asc_tiles/ --output=/path/to/dofe-dem-data/
```

---

## 5. Deployment & Release

- **Local Deployment:** Run from monorepo root:
  ```bash
  bash bin/deploy.sh rce
  ```
- **Production Packaging:** Builds a clean staging environment and packages `dist/ems-route-card-engine-<version>.zip` with production Composer autoloader (`--no-dev`) and dev dependencies stripped:
  ```bash
  bash bin/package.sh rce --no-bump
  ```
