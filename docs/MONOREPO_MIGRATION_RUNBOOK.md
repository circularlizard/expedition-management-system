# **EMS Two-Plugin Monorepo Migration & QA Runbook**

Consolidate `expedition-management-system` (`ems-plugin`) and `ems-route-card-engine` into a single two-plugin monorepo (`expedition-management-system`) with zero data loss, zero git history loss, and zero downtime for local or production WordPress environments, while establishing a modular kernel architecture (Phase B) inside `ems-plugin` for strict blast-radius isolation.

This runbook separates execution into two independent agent roles:

* **Agent 1 (Migration Engineer):** Executes the repository restructuring, `git subtree` merge, NPM workspace wiring, Docker/script path updates, and release zip packaging.  
* **Agent 2 (QA & Verification Engineer):** Independently audits the baseline snapshot against the post-migration state across Git history, MariaDB tables, Custom Post Types (CPTs), PHPUnit, PHPStan, Vitest, REST APIs, and standalone plugin isolation.

**Hard Constraint for Both Agents:** `login-with-google` (`oauth-login`) remains in its own standalone repository. Do not move, alter, or delete login-with-google. Never run docker-compose down \-v, wp db reset, or any destructive SQL command (DROP, TRUNCATE, DELETE).

# **Target repository architecture**

Both plugins live under plugins/ inside the main expedition-management-system repository, sharing a root NPM workspace, Docker environment, and Git hooks while retaining isolated `composer.json` manifests so each plugin can still be packaged into a standalone `.zip` archive for WordPress deployment.t  
expedition-management-system/          \# Monorepo Root  
├── .agents/                           \# Shared AI agent skills & workflows  
├── .gemini/                           \# Commit enforcer skills  
├── .githooks/                         \# Unified commit guard & mailmap hooks  
├── .github/workflows/                 \# Unified CI pipeline  
├── bin/  
│   ├── deploy.sh                      \# Orchestrates build & rsync for one or both plugins  
│   ├── package.sh                     \# Produces standalone dist/\*.zip for each plugin  
│   ├── preflight-snapshot.sh          \# Baseline state, JSON backup & parity generator (--mode=local\|remote)  
│   └── setup-workstation.sh           \# Turnkey bootstrap for brand-new developer machines  
├── wordpress-env/                     \# Git-ignored local runtime directory for wp-content bind mounts  
│   └── wp-content/  
│       ├── mu-plugins/  
│       ├── plugins/                   \# ems-plugin, ems-route-card-engine, oauth-login, fluentform, tutor  
│       ├── themes/  
│       └── uploads/  
│           └── ems-dem/               \# OS Terrain 50 binary (.bin) elevation tiles  
├── docker-compose.yml                 \# Shared WordPress \+ MariaDB \+ Caddy stack  
├── Caddyfile                          \# Local HTTPS proxy config  
├── AGENTS.md                          \# Root workspace instructions for AI coding agents  
├── GEMINI.md  
├── .mailmap  
├── .gitignore  
├── package.json                       \# NPM Workspaces root (packages/*, plugins/*)  
├── tsconfig.base.json                 \# Shared TypeScript compiler options  
├── packages/  
│   └── contracts/                     \# Shared TypeScript interfaces (RouteCard, Team, etc.)  
│       ├── package.json  
│       ├── tsconfig.json  
│       └── src/index.ts  
└── plugins/  
    ├── ems-plugin/                    \# Core Expedition Management System plugin  
    │   ├── assets/  
    │   ├── docs/  
    │   ├── fluent/  
    │   ├── resources/  
    │   ├── src/  
    │   │   ├── Core/                  \# Kernel: Module\_Registry, Feature\_Flags, Event\_Bus, Shortcode\_Boundary, Table\_Installer, Role\_Manager, CPT\_Registry, Backup\_Manager  
    │   │   ├── Contracts/             \# Shared PHP interfaces & DTOs (FluentSignupDTO, TrainingComplianceInterface, RouteContextProviderInterface, BackupContributorInterface)  
    │   │   ├── Data/                  \# Shared foundational repositories (Units, Explorers)  
    │   │   ├── Integrations/          \# Anti-Corruption Adapters (OSM, FluentForms, TutorLMS, RouteEngineBridge)  
    │   │   ├── Modules/               \# Self-contained vertical domain modules (each booted via ModuleInterface)  
    │   │   │   ├── Signups/           \# Form 6/7 reconciliation, Kanban board, Place Offers  
    │   │   │   ├── Expeditions/       \# Expedition Board, Team Builder, LiC readiness  
    │   │   │   ├── Volunteers/        \# Volunteer management & availability cover board  
    │   │   │   ├── LeaderDashboard/   \# Unit mapping, leader invites, \[ems-leader-dashboard\]  
    │   │   │   ├── Portal/            \# Participant & Parent portal (\[ems-portal\])  
    │   │   │   └── Notifications/     \# Cron chasers & transactional email event listeners  
    │   │   └── Admin/                 \# Shared admin shell & Settings page (with Module Feature Flags & Unified JSON Backups)  
    │   ├── tests/  
    │   ├── tools/  
    │   ├── user-guide/  
    │   ├── composer.json              \# Isolated PSR-4 (EMS) & production vendor/  
    │   ├── composer.lock  
    │   ├── ems-plugin.php  
    │   ├── package.json  
    │   ├── phpstan.neon  
    │   ├── phpstan-baseline.neon  
    │   ├── phpunit.xml  
    │   ├── tsconfig.json  
    │   ├── vite.config.ts  
    │   └── vitest.config.ts  
    └── ems-route-card-engine/         \# Standalone & Companion Route Card Engine  
        ├── bin/                       \# DEM binary tile compiler CLI scripts  
        ├── docs/  
        ├── mockdata/  
        ├── resources/  
        ├── src/  
        ├── tests/  
        ├── composer.json              \# Isolated PSR-4 & production vendor/  
        ├── composer.lock  
        ├── ems-route-card-engine.php  
        ├── package.json  
        ├── phpstan.neon  
        ├── phpunit.xml  
        ├── tsconfig.json  
        ├── vite.config.ts  
        └── vitest.config.ts

````

# Part 1: Agent 1 instructions (Migration Engineer)

## Golden safety rules

1. **Never destroy database volumes:** Do not run `docker-compose down -v` or `wp db reset`.
2. **Preserve every commit:** Use `git mv` for `ems-plugin` files and `git subtree add` for `ems-route-card-engine` so all 725+ EMS commits and 36+ Route Engine commits remain intact and searchable via `git log --follow`.
3. **Preserve WordPress plugin slugs:** In WordPress (`wp-content/plugins/`), the deployed folder names must remain `ems-plugin` and `ems-route-card-engine` so WordPress does not deactivate either plugin or lose `active_plugins` option entries.
4. **Halt on baseline failure:** If any unit test, static analysis check, or database snapshot fails during Step 1, stop immediately and report the error before moving files.

## Step 1: Take a non-destructive baseline snapshot

Before modifying a single file, create a safety branch and record exact baseline counts, unified JSON backups, and parity checksums in /tmp/ems-migration-baseline/. The EMS Unified JSON Snapshot & Checksum Generator serves as the primary, server-portable backup and parity mechanism that operates identically across managed staging/production environments (see-expeditions.org.uk / staging4.see-expeditions.org.uk) and local Docker containers. Raw mysqldump output serves strictly as an optional secondary safety net when operating in local Docker environments.

```bash
# 1. Create baseline artifact directory
mkdir -p /tmp/ems-migration-baseline

# 2. Record Git commit counts and HEAD SHAs in both repositories
cd /path/to/expedition-management-system
git status --porcelain > /tmp/ems-migration-baseline/ems-dirty-check.txt
if [ -s /tmp/ems-migration-baseline/ems-dirty-check.txt ]; then
  echo "ERROR: Working tree in expedition-management-system is dirty. Abort."
  exit 1
fi
git rev-parse HEAD > /tmp/ems-migration-baseline/ems-head-sha.txt
git rev-list --count HEAD > /tmp/ems-migration-baseline/ems-commit-count.txt
git checkout -b chore/monorepo-migration
git tag pre-monorepo-backup

cd /path/to/ems-route-card-engine
git status --porcelain > /tmp/ems-migration-baseline/rce-dirty-check.txt
if [ -s /tmp/ems-migration-baseline/rce-dirty-check.txt ]; then
  echo "ERROR: Working tree in ems-route-card-engine is dirty. Abort."
  exit 1
fi
git rev-parse HEAD > /tmp/ems-migration-baseline/rce-head-sha.txt
git rev-list --count HEAD > /tmp/ems-migration-baseline/rce-commit-count.txt
git tag pre-monorepo-rce-backup
````

Next, capture the application-layer unified JSON backup, parity manifest, table row counts, CPT counts, and EMS options using `bin/preflight-snapshot.sh`:  
cd /path/to/expedition-management-system

## **3\. Generate primary unified JSON snapshot and parity manifest**

\# Support local Docker execution or remote HTTPS REST execution  
bash bin/preflight-snapshot.sh \--mode=local \--out=/tmp/ems-migration-baseline/  
\# Or for remote server verification:  
\# bash bin/preflight-snapshot.sh \--mode=remote \--url=https\://staging4.see-expeditions.org.uk \--token=\$REST\_TOKEN \--out=/tmp/ems-migration-baseline/

## **4\. Record row counts, CPT counts, active plugins, and optional raw SQL dump**

docker-compose exec \-T wordpress wp plugin list \--format=json \> /tmp/ems-migration-baseline/wp-plugins-before.json  
docker-compose exec \-T wordpress wp post list \--post\_type=expedition \--format=count \> /tmp/ems-migration-baseline/cpt-expedition-count.txt  
docker-compose exec \-T wordpress wp post list \--post\_type=team \--format=count \> /tmp/ems-migration-baseline/cpt-team-count.txt  
\# Optional secondary safety net for local Docker:  
docker-compose exec \-T db mysqldump \-u wordpress \-pwordpress wordpress \> /tmp/ems-migration-baseline/wordpress-full-backup.sql

for tbl in ems\_osm\_explorers ems\_units ems\_unit\_patrols ems\_participant\_signups ems\_expedition\_signups ems\_team\_members ems\_volunteers ems\_volunteer\_availability ems\_route\_submissions ems\_audit\_logs; do  
docker-compose exec \-T wordpress wp db query "SELECT COUNT(\*) AS cnt FROM wp\_\${tbl};" \--skip-column-names \> "/tmp/ems-migration-baseline/table-\${tbl}-count.txt"  
done

````

Finally, run the existing test suites in both repositories and record the results:

```bash
# 5. Baseline test run for ems-plugin
cd /path/to/expedition-management-system
vendor/bin/phpunit > /tmp/ems-migration-baseline/ems-phpunit-before.txt
vendor/bin/phpstan analyse --memory-limit=2G > /tmp/ems-migration-baseline/ems-phpstan-before.txt
npm run test -- --run > /tmp/ems-migration-baseline/ems-vitest-before.txt
npm run build

# 6. Baseline test run for ems-route-card-engine
cd /path/to/ems-route-card-engine
vendor/bin/phpunit > /tmp/ems-migration-baseline/rce-phpunit-before.txt
vendor/bin/phpstan analyse --memory-limit=2G > /tmp/ems-migration-baseline/rce-phpstan-before.txt
npm run test -- --run > /tmp/ems-migration-baseline/rce-vitest-before.txt
npm run build
````

# **Step 2: Move `ems-plugin` files into `plugins/ems-plugin/` using `git mv`**

Keep root infrastructure files at the repository root and move all plugin-specific code into plugins/ems-plugin/ so Git tracks every file rename with 100% history preservation.

* **Files that STAY at the monorepo root:** `.agents/`, `.gemini/`, `.githooks/`, `.github/`, `.opencode/`, `bin/`, `docker-compose.yml`, `Caddyfile`, `.gitignore`, `.mailmap`, `AGENTS.md`, `GEMINI.md`, `README.md`.  
* **Files that MOVE to `plugins/ems-plugin/`:** `assets/`, `docs/`, `fluent/`, `resources/`, `scratch/`, `src/`, `tests/`, `tools/`, `user-guide/`, `composer.json`, `composer.lock`, `ems-plugin.php`, `package.json`, `package-lock.json`, `phpstan.neon`, `phpstan-baseline.neon`, `phpunit.xml`, `skills-lock.json`, `tsconfig.json`, `vite.config.ts`, `vitest.config.ts`.

```
cd /path/to/expedition-management-system
mkdir -p plugins/ems-plugin

git mv assets docs fluent resources scratch src tests tools user-guide plugins/ems-plugin/
git mv composer.json composer.lock ems-plugin.php package.json package-lock.json \
       phpstan.neon phpstan-baseline.neon phpunit.xml skills-lock.json \
       tsconfig.json vite.config.ts vitest.config.ts plugins/ems-plugin/

git commit -m "chore(monorepo): relocate ems-plugin into plugins/ems-plugin directory"
```

# **Step 3: Import `ems-route-card-engine` with full commit history via `git subtree`**

Add the local `ems-route-card-engine` repository as a remote and import its `main` branch under `plugins/ems-route-card-engine` without squashing commits:

```
cd /path/to/expedition-management-system
git remote add route-engine /path/to/ems-route-card-engine
git fetch route-engine main

# Merge full commit history into plugins/ems-route-card-engine
git subtree add --prefix=plugins/ems-route-card-engine route-engine main -m "chore(monorepo): import ems-route-card-engine with full commit history"

# Remove duplicate root-only files inside plugins/ems-route-card-engine (.githooks, .mailmap)
git rm -r plugins/ems-route-card-engine/.githooks plugins/ems-route-card-engine/.mailmap
git commit -m "chore(monorepo): deduplicate git hooks and mailmap in route-card-engine"
```

**Note on `plugins/ems-route-card-engine/bin/`:** Keep any CLI scripts inside `plugins/ems-route-card-engine/bin/` (such as the OS Terrain 50 binary tile compiler), and only remove or redirect its old `bin/deploy.sh` and `bin/package.sh` so the root `bin/` scripts act as the single source of truth.

# **Step 4: Configure NPM Workspaces and shared TypeScript contracts**

Create the shared contracts package (packages/contracts) and root package.json so both plugins share TypeScript interfaces and build orchestration while outputting their own compiled `assets/` bundles.

1. Create `packages/contracts/package.json`:

```
{
  "name": "@seee/contracts",
  "version": "0.1.0",
  "private": true,
  "main": "./src/index.ts",
  "types": "./src/index.ts"
}
```

2. Create `packages/contracts/src/index.ts` exporting shared placeholder interfaces (`RouteContextDTO`, `RouteCardSubmissionDTO`, `ApprovalStatus`).  
3. Create the root `package.json` with NPM workspaces:

```
{
  "name": "seee-expedition-monorepo",
  "private": true,
  "workspaces": [
    "packages/*",
    "plugins/*"
  ],
  "scripts": {
    "build": "npm run build --workspaces --if-present",
    "test": "npm run test --workspaces --if-present",
    "test:php:ems": "composer --working-dir=plugins/ems-plugin exec phpunit",
    "test:php:rce": "composer --working-dir=plugins/ems-route-card-engine exec phpunit",
    "stan:ems": "composer --working-dir=plugins/ems-plugin exec -- phpstan analyse --memory-limit=2G",
    "stan:rce": "composer --working-dir=plugins/ems-route-card-engine exec -- phpstan analyse --memory-limit=2G",
    "verify:all": "npm run test && npm run build && npm run test:php:ems && npm run test:php:rce && npm run stan:ems && npm run stan:rce"
  }
}
```

4. Check `vite.config.ts`, `vitest.config.ts`, and `tsconfig.json` inside both `plugins/ems-plugin/` and `plugins/ems-route-card-engine/` to ensure all entry and output paths resolve via `__dirname` relative to their own plugin directory (so `npm run build` inside `plugins/ems-plugin/` still writes bundles to `plugins/ems-plugin/assets/`).  
5. Run `npm install` at the monorepo root and run `composer install` inside each plugin folder (`plugins/ems-plugin` and `plugins/ems-route-card-engine`).

# **Step 5: Update bin/deploy.sh, bin/package.sh, and workstation setup (docker-compose.yml & wp-content)**

Both deployment and release packaging scripts accept explicit target arguments (ems, rce, all — defaulting to all) so you can build, deploy, or package a single plugin or both plugins together.

### **1\. bin/deploy.sh (Local development build & rsync script)**

Usage contract:

1. `bash bin/deploy.sh` or `bash bin/deploy.sh all` — Builds and deploys both `ems-plugin` and `ems-route-card-engine` to `wordpress-env/wp-content/plugins/`.  
2. `bash bin/deploy.sh ems` — Builds and deploys **only** `ems-plugin`.  
3. `bash bin/deploy.sh rce` — Builds and deploys **only** `ems-route-card-engine`.  
4. Optional flag `--skip-build`: Rsyncs PHP files immediately without re-running `npm run build` or `composer install`.

Implementation for `bin/deploy.sh`:

```sh
#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-all}"
SKIP_BUILD="${2:-}"
WP_PLUGINS_DIR="wordpress-env/wp-content/plugins"

deploy_plugin() {
  local folder="$1"
  local slug="$2"
  echo "==> Deploying ${slug}..."

  if [ "${SKIP_BUILD}" != "--skip-build" ]; then
    npm run build --workspace="plugins/${folder}"
    composer install --working-dir="plugins/${folder}"
  fi

  mkdir -p "${WP_PLUGINS_DIR}/${slug}"
  rsync -a --delete \
    --exclude='.git/' \
    --exclude='node_modules/' \
    --exclude='tests/' \
    --exclude='scratch/' \
    "plugins/${folder}/" "${WP_PLUGINS_DIR}/${slug}/"

  if docker-compose ps wordpress | grep -q "Up"; then
    if ! docker-compose exec -T wordpress wp plugin is-active "${slug}"; then
      docker-compose exec -T wordpress wp plugin activate "${slug}"
    fi
  fi
  echo "==> ${slug} deployed and active."
}

case "${TARGET}" in
  ems)
    deploy_plugin "ems-plugin" "ems-plugin"
    ;;
  rce|route-engine)
    deploy_plugin "ems-route-card-engine" "ems-route-card-engine"
    ;;
  all)
    deploy_plugin "ems-plugin" "ems-plugin"
    deploy_plugin "ems-route-card-engine" "ems-route-card-engine"
    ;;
  *)
    echo "Usage: bash bin/deploy.sh [ems|rce|all] [--skip-build]"
    exit 1
    ;;
esac
```

### **2\. bin/package.sh (Staging & Production .zip artifact builder)**

Usage contract:

5. `bash bin/package.sh` or `bash bin/package.sh all` — Packages both `dist/ems-plugin-<version>.zip` and `dist/ems-route-card-engine-<version>.zip` (bumping patch version by default).  
6. `bash bin/package.sh ems` — Packages **only** `dist/ems-plugin-<version>.zip`.  
7. `bash bin/package.sh rce` — Packages **only** `dist/ems-route-card-engine-<version>.zip`.  
8. Optional flags: `--no-bump` (packages current version without incrementing) or `--bump=patch|minor|major` (defaults to `patch`).

Crucial packaging rules for `bin/package.sh`:

9. 

```sh
#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-all}"
FLAG="${2:---bump=patch}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_ROOT="/tmp/ems-pkg-stage"

mkdir -p "${DIST_DIR}"

package_plugin() {
  local folder="$1"
  local slug="$2"
  local main_file="$3"
  local plugin_dir="${ROOT_DIR}/plugins/${folder}"
  local stage_dir="${STAGE_ROOT}/${slug}"

  echo "==> Packaging ${slug}..."

  # 1. Read version from plugin bootstrap header
  local version
  version=$(grep -E "^\s*\*\s*Version:" "${plugin_dir}/${main_file}" | awk '{print $NF}' | tr -d '\r')

  # 2. Build production frontend assets
  npm run build --workspace="plugins/${folder}"

  # 3. Stage clean copy in /tmp so working tree dev dependencies remain intact
  rm -rf "${stage_dir}"
  mkdir -p "${stage_dir}"
  rsync -a \
    --exclude='.git*' \
    --exclude='node_modules/' \
    --exclude='tests/' \
    --exclude='docs/' \
    --exclude='scratch/' \
    --exclude='user-guide/' \
    --exclude='mockdata/' \
    --exclude='phpunit.xml*' \
    --exclude='phpstan*.neon' \
    --exclude='tsconfig*.json' \
    --exclude='vite.config.ts' \
    --exclude='vitest.config.ts' \
    "${plugin_dir}/" "${stage_dir}/"

  # 4. Install production-only PHP autoloader inside staging folder
  composer install --working-dir="${stage_dir}" --no-dev --optimize-autoloader --no-interaction

  # 5. Create standard WordPress plugin zip with top-level <slug>/ folder
  local zip_path="${DIST_DIR}/${slug}-${version}.zip"
  rm -f "${zip_path}"
  (cd "${STAGE_ROOT}" && zip -rq "${zip_path}" "${slug}")
  echo "==> Created ${zip_path}"
}

case "${TARGET}" in
  ems)
    package_plugin "ems-plugin" "ems-plugin" "ems-plugin.php"
    ;;
  rce|route-engine)
    package_plugin "ems-route-card-engine" "ems-route-card-engine" "ems-route-card-engine.php"
    ;;
  all)
    package_plugin "ems-plugin" "ems-plugin" "ems-plugin.php"
    package_plugin "ems-route-card-engine" "ems-route-card-engine" "ems-route-card-engine.php"
    ;;
  *)
    echo "Usage: bash bin/package.sh [ems|rce|all] [--no-bump|--bump=patch|minor|major]"
    exit 1
    ;;
esac
```

10. **Clean staging directory:** Copy the target plugin into a temporary staging folder (`/tmp/ems-pkg-stage/<slug>`) so production `composer install --no-dev --optimize-autoloader` does not wipe out PHPUnit or PHPStan inside your working tree.  
11. **Root folder inside .zip:** The top-level folder inside `ems-plugin-<version>.zip` must be `ems-plugin/` and inside `ems-route-card-engine-<version>.zip` must be `ems-route-card-engine/`. WordPress uses this top-level directory name when replacing an existing plugin via **Plugins → Add New → Upload Plugin**.  
12. **Production exclusions:** Strip `node_modules/`, `tests/`, `docs/`, `scratch/`, `user-guide/`, `mockdata/`, `phpunit.xml`, `phpstan*.neon`, `tsconfig.json`, `vite.config.ts`, `vitest.config.ts`, and `.git*` from the `.zip` archive, keeping `src/`, `assets/`, `vendor/`, `fluent/`, and the main bootstrap `.php` file.  
13. **Update package.json root scripts:** Add `"deploy:ems": "bash bin/deploy.sh ems"`, `"deploy:rce": "bash bin/deploy.sh rce"`, `"package:ems": "bash bin/package.sh ems"`, `"package:rce": "bash bin/package.sh rce"`, and `"package:all": "bash bin/package.sh all"` to the root `package.json`.

To ensure any developer or coding agent can clone the repository and establish a fully functioning local environment with a single command, standardise the workstation infrastructure as follows:  
**Configure host runtime directory structure (wordpress-env/):** All WordPress content directories persist in `wordpress-env/wp-content/` at the monorepo root (added to root `.gitignore` so runtime uploads, third-party plugins, and themes are never committed to Git):

```
wordpress-env/
└── wp-content/
    ├── mu-plugins/
    ├── plugins/
    │   ├── ems-plugin/              # Synced via bin/deploy.sh
    │   ├── ems-route-card-engine/   # Synced via bin/deploy.sh
    │   ├── oauth-login/             # Synced if ../login-with-google exists
    │   ├── fluentform/              # Auto-installed via WP-CLI
    │   └── tutor/                   # Auto-installed via WP-CLI
    ├── themes/
    └── uploads/
        └── ems-dem/                 # OS Terrain 50 binary (.bin) elevation tiles
```

**Standardise docker-compose.yml volume mounts:** Ensure `docker-compose.yml` contains the following bind mounts and named volumes:

```yaml
services:
  wordpress:
    image: wordpress:6.7-php8.2-apache
    volumes:
      - ./wordpress-env/wp-content:/var/www/html/wp-content
  db:
    image: mariadb:10.11
    volumes:
      - db_data:/var/lib/mysql
  caddy:
    image: caddy:2-alpine
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile
volumes:
  db_data:
```

**Create bin/setup-workstation.sh:** Implement an idempotent workstation bootstrap script that automates complete machine setup:

```sh
#!/usr/bin/env bash
set -euo pipefail

# 1. Verify host prerequisites & configure git hooks
for cmd in docker composer node npm rsync; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "ERROR: Missing prerequisite: $cmd"; exit 1; }
done
git config core.hooksPath .githooks

# 2. Create host wp-content structure
mkdir -p wordpress-env/wp-content/{mu-plugins,plugins,themes,uploads/ems-dem}
chmod -R 777 wordpress-env/wp-content/uploads

# 3. Install NPM & Composer dependencies and build assets
npm install
composer install --working-dir=plugins/ems-plugin
composer install --working-dir=plugins/ems-route-card-engine
npm run build

# 4. Start Docker stack and wait for DB
docker-compose up -d
until docker-compose exec -T db mysqladmin ping -uwordpress -pwordpress --silent; do
  echo "Waiting for MariaDB..."
  sleep 2
done

# 5. Bootstrap WordPress core if uninstalled
if ! docker-compose exec -T wordpress wp core is-installed; then
  docker-compose exec -T wordpress wp core install \
    --url="https://localhost" \
    --title="EMS Local Development" \
    --admin_user="admin" \
    --admin_password="password" \
    --admin_email="admin@example.local"
  docker-compose exec -T wordpress wp rewrite structure '/%postname%/'
fi

# 6. Install third-party plugins
docker-compose exec -T wordpress wp plugin install fluentform tutor --activate

# 7. Sync login-with-google if available locally
if [ -d "../login-with-google" ]; then
  rsync -av --delete --exclude='.git' ../login-with-google/ wordpress-env/wp-content/plugins/oauth-login/
  docker-compose exec -T wordpress wp plugin activate oauth-login || true
fi

# 8. Deploy monorepo plugins, copy DEM mock tiles & optional seed restore
bash bin/deploy.sh all
cp -n plugins/ems-route-card-engine/mockdata/*.bin wordpress-env/wp-content/uploads/ems-dem/ || true
if [ "${1:-}" = "--seed" ] && [ -f "${2:-}" ]; then
  docker-compose exec -T wordpress wp ems backup import "$2"
fi
echo "Workstation setup complete! Access HTTPS at https://localhost"
```

# **Part 1B: Agent 1 instructions — Modularising `ems-plugin` for blast-radius isolation**

*Crucial Sequencing Rule: Complete Steps 1–5 (the monorepo file move) and verify all tests pass before starting Steps 6–10. Never mix repository file moves with PHP namespace refactoring in the same commit, otherwise git log \--follow cannot track file history cleanly.*

## **Step 6: Build the modular kernel (ModuleInterface, Module\_Registry, Feature\_Flags, and Event\_Bus)**

Create four foundational classes inside `plugins/ems-plugin/src/Core/` so every domain feature boots in isolation and can be toggled on or off without crashing the rest of the plugin:

4. **EMS\\Contracts\\ModuleInterface:**

```php
namespace EMS\Contracts;

interface ModuleInterface {
    public function getSlug(): string;
    public function isAlwaysOn(): bool;
    public function register(): void;
    public function boot(): void;
}
```

5. **EMS\\Core\\Feature\_Flags:** Reads module toggle states from the `ems_feature_flags` WordPress option (with constant overrides in `wp-config.php`, e.g., `EMS_DISABLE_MODULE_NOTIFICATIONS`). Core modules remain always-on; feature modules (`signups`, `expeditions`, `volunteers`, `leader_dashboard`, `portal`, `notifications`) can be toggled independently in **EMS → Settings → Feature Flags**.  
6. **EMS\\Core\\Module\_Registry:** Loops through registered `ModuleInterface` providers during `plugins_loaded`. Crucially, wrap each module's `register()` and `boot()` call inside a `try / catch (\Throwable $e)` boundary: If a module throws an unhandled error during boot or REST registration, `Module_Registry` logs the failure to `ems_audit_logs` (and `error_log`), marks that single module as degraded, and continues booting all other modules so a bug in one module never white-screens WordPress.  
7. **EMS\\Core\\Event\_Bus:** A typed wrapper around WordPress actions (`dispatch( string $event, object $payload )` and `listen( string $event, callable $listener )`) that wraps every listener execution in a `try / catch (\Throwable $e)`. If an email chaser or notification listener fails, the exception is logged without aborting the database transaction or HTTP response that triggered it.  
8. **EMS\\Contracts\\BackupContributorInterface & EMS\\Core\\Backup\_Manager:** Defines the contract for domain modules to register custom tables, CPTs, and options for application-layer backing up and restoring. `Backup_Manager` orchestrates the creation of portable JSON archives (`ems-unified-backup.json`) and parity manifests (`ems-parity-manifest.json`), exposing REST endpoints under `/wp-json/ems/v1/backups/*` and `/wp-json/ems/v1/system/parity-manifest` as well as WP-CLI commands (`wp ems backup export|import|parity`).

## **Step 7: Strangler-fig migration into src/Modules/ (preserving backwards compatibility)**

Refactor existing classes from horizontal layers (`src/Admin/`, `src/Integrations/`) into vertical domain modules under `src/Modules/`, doing **one module per commit** using `git mv`:

2. **src/Modules/Signups/SignupsModule.php:** Owns the Signups & Reconciliation Board (`ems-signups`), `[ems_signup_banner]`, and `/ems/v1/signups/*` REST controllers. (Prepares the isolated boundary for Phase 3 Kanban & Place Offers).  
3. **src/Modules/Expeditions/ExpeditionsModule.php:** Owns the Expedition Board (`ems-expedition-board`), Team Builder, and `/ems/v1/expeditions/*` and `/ems/v1/teams/*` REST controllers.  
4. **src/Modules/Volunteers/VolunteersModule.php:** Owns Volunteer Management (`ems-volunteers`), `[ems-volunteer-signup]`, and `/ems/v1/volunteers/*` REST controllers.  
5. **src/Modules/LeaderDashboard/LeaderDashboardModule.php:** Owns Unit Mapping (`ems-reference&tab=units`), leader invitation workflows, and `[ems-leader-dashboard]`.  
6. **src/Modules/Portal/PortalModule.php:** Owns `[ems-portal]` and `/ems/v1/portal/*` REST controllers.  
7. **src/Modules/Notifications/NotificationsModule.php:** Owns all transactional email listeners and cron schedules (preparing for the Phase 3 Automated Email & Chaser engine).

*Zero-Breakage Class Alias Rule: Whenever you move a class from EMS\\Admin\\Foo\_Controller to EMS\\Modules\\Signups\\Foo\_Controller, register a class\_alias( \\EMS\\Modules\\Signups\\Foo\_Controller::class, \\EMS\\Admin\\Foo\_Controller::class ); in src/Core/Legacy\_Aliases.php until all unit tests and references are updated. Run composer \--working-dir=plugins/ems-plugin exec phpunit after every single module move.*

## **Step 8: Isolate Fluent Forms, Tutor LMS, and Route Engine behind Anti-Corruption Adapters**

Domain modules in `src/Modules/` must never query Tutor LMS tables or read raw Fluent Forms `$formData` arrays directly:

1. **FluentFormsAdapter (src/Integrations/FluentForms/):** Listens to `fluentform/submission_inserted` for Forms 6 and 7, maps the raw form field IDs (configured in **EMS → Settings → Form Mappings**) into immutable `ParticipationSignupDTO` and `ExpeditionPreferenceDTO` objects, and dispatches `ems.signup.form_submitted` via `Event_Bus`.  
2. **TutorLmsAdapter (src/Integrations/TutorLms/):** Implements `EMS\Contracts\TrainingComplianceInterface` (`getExplorerCourseProgress( int $scoutId ): TrainingStatusDTO`). If Tutor LMS is deactivated in WordPress, `TutorLmsAdapter` returns an explicit `TrainingStatusDTO::unavailable()` state instead of throwing a fatal `Call to undefined function tutor_utils()` error.  
3. **EmsRouteBridgeAdapter (src/Integrations/RouteEngine/):** Hooks into `ems_route_engine/context_provider` so `ems-route-card-engine` automatically receives live expedition and team rosters when both plugins are active.

## **Step 9: Wrap all shortcodes and React SPA roots in fault-isolation boundaries**

* **PHP Shortcode\_Boundary (src/Core/Shortcode\_Boundary.php):** Wrap every shortcode callback (`[ems-portal]`, `[ems-leader-dashboard]`, `[ems-volunteer-signup]`, `[ems_signup_banner]`) in a safe executor that catches `\Throwable`, logs to `ems_audit_logs`, and renders a clean, styled inline fallback card (*"This section of the expedition portal is temporarily unavailable."*) rather than crashing the public WordPress page.  
* **React \<ModuleErrorBoundary /\>:** In `plugins/ems-plugin/resources/js/shared/ModuleErrorBoundary.tsx`, create a reusable React Error Boundary component and wrap each major tab and widget in `admin/`, `leader-dashboard/`, and `portal/`. If a component throws a runtime render error, only that panel shows a retry card while navigation and sibling tabs keep working.

# **Part 2: Agent 2 instructions (QA & Verification Engineer)**

**Role Isolation:** Agent 2 must not trust Agent 1's self-reported output. Agent 2 must independently run the verification suite below against the /tmp/ems-migration-baseline/ artifacts captured before migration and produce a signed-off pass/fail audit table.

# **Gate 1: Git history & commit integrity audit**

Verify that not a single commit from either repository was lost and that `git log --follow` traverses across the directory move:

```
cd /path/to/expedition-management-system

# 1. Total commit count must be >= (EMS baseline count + RCE baseline count)
BEFORE_EMS=$(cat /tmp/ems-migration-baseline/ems-commit-count.txt)
BEFORE_RCE=$(cat /tmp/ems-migration-baseline/rce-commit-count.txt)
EXPECTED_MIN=$((BEFORE_EMS + BEFORE_RCE))
ACTUAL_COUNT=$(git rev-list --count HEAD)
echo "Expected >= ${EXPECTED_MIN}, Actual: ${ACTUAL_COUNT}"
test "${ACTUAL_COUNT}" -ge "${EXPECTED_MIN}"

# 2. Verify git log --follow traces history through the directory move
EMS_HISTORY=$(git log --follow --oneline plugins/ems-plugin/ems-plugin.php | wc -l)
RCE_HISTORY=$(git log --follow --oneline plugins/ems-route-card-engine/ems-route-card-engine.php | wc -l)
test "${EMS_HISTORY}" -gt 10
test "${RCE_HISTORY}" -gt 5
```

# **Gate 2: Zero-data-loss unified parity & backup audit**

Run `bash bin/deploy.sh` and generate a post-deployment parity manifest. Verify that table row counts, CPT post counts, options, and SHA-256 table hashes match the pre-migration baseline perfectly across local Docker or remote staging/production servers:  
\# 1\. Generate post-migration parity manifest  
bash bin/preflight-snapshot.sh \--mode=local \--out=/tmp/ems-migration-after/

\# 2\. Compare baseline vs. post-migration parity manifest deterministically  
diff \-u /tmp/ems-migration-baseline/ems-parity-manifest.json /tmp/ems-migration-after/ems-parity-manifest.json

\# 3\. Verify CPT counts match baseline explicitly  
EXP\_AFTER=\$(docker-compose exec \-T wordpress wp post list \--post\_type=expedition \--format=count)  
TEAM\_AFTER=\$(docker-compose exec \-T wordpress wp post list \--post\_type=team \--format=count)  
diff \-u /tmp/ems-migration-baseline/cpt-expedition-count.txt \<(echo "\${EXP\_AFTER}")  
diff \-u /tmp/ems-migration-baseline/cpt-team-count.txt \<(echo "\${TEAM\_AFTER}")

\# 4\. Verify all 10 custom SQL tables match baseline row counts  
for tbl in ems\_osm\_explorers ems\_units ems\_unit\_patrols ems\_participant\_signups ems\_expedition\_signups ems\_team\_members ems\_volunteers ems\_volunteer\_availability ems\_route\_submissions ems\_audit\_logs; do  
  AFTER\_CNT=\$(docker-compose exec \-T wordpress wp db query "SELECT COUNT(\*) AS cnt FROM wp\_\${tbl};" \--skip-column-names)  
  diff \-u "/tmp/ems-migration-baseline/table-\${tbl}-count.txt" \<(echo "\${AFTER\_CNT}")  
done

\# Rollback procedure (if parity check fails):  
\# Restore via Unified JSON UI/CLI:  
\# docker-compose exec \-T wordpress wp ems backup import /tmp/ems-migration-baseline/ems-unified-backup.json  
\# Or via remote REST API:  
\# curl \-X POST https\://staging4.see-expeditions.org.uk/wp-json/ems/v1/backups/import \-H "Authorization: Bearer \$REST\_TOKEN" \-F "file=@/tmp/ems-migration-baseline/ems-unified-backup.json"

```
# 1. Verify CPT counts match baseline
EXP_AFTER=$(docker-compose exec -T wordpress wp post list --post_type=expedition --format=count)
TEAM_AFTER=$(docker-compose exec -T wordpress wp post list --post_type=team --format=count)
diff -u /tmp/ems-migration-baseline/cpt-expedition-count.txt <(echo "${EXP_AFTER}")
diff -u /tmp/ems-migration-baseline/cpt-team-count.txt <(echo "${TEAM_AFTER}")

# 2. Verify all 10 custom SQL tables match baseline row counts
for tbl in ems_osm_explorers ems_units ems_unit_patrols ems_participant_signups ems_expedition_signups ems_team_members ems_volunteers ems_volunteer_availability ems_route_submissions ems_audit_logs; do
  AFTER_CNT=$(docker-compose exec -T wordpress wp db query "SELECT COUNT(*) AS cnt FROM wp_${tbl};" --skip-column-names)
  diff -u "/tmp/ems-migration-baseline/table-${tbl}-count.txt" <(echo "${AFTER_CNT}")
done
```

# **Gate 3: Automated unit test & static analysis parity**

Run all six automated quality gates from the monorepo root and compare test counts against `/tmp/ems-migration-baseline/*-before.txt`:

1. `composer --working-dir=plugins/ems-plugin exec phpunit` — 0 failures, identical test and assertion count to `ems-phpunit-before.txt`.  
2. `composer --working-dir=plugins/ems-route-card-engine exec phpunit` — 0 failures, identical test and assertion count to `rce-phpunit-before.txt`.  
3. `composer --working-dir=plugins/ems-plugin exec -- phpstan analyse --memory-limit=2G` — 0 errors outside existing `phpstan-baseline.neon`.  
4. `composer --working-dir=plugins/ems-route-card-engine exec -- phpstan analyse --memory-limit=2G` — 0 errors.  
5. `npm run test --workspace=plugins/ems-plugin -- --run` — all Vitest suites pass.  
6. `npm run test --workspace=plugins/ems-route-card-engine -- --run` — all Vitest suites pass.

# **Gate 4: WordPress runtime, REST API & UI smoke tests**

Verify that WordPress loads both plugins cleanly without PHP warnings or broken asset URLs:

4. **Plugin activation status:** Run `docker-compose exec -T wordpress wp plugin list` and confirm `ems-plugin`, `ems-route-card-engine`, and `oauth-login` (if installed in local WP) all show `status: active`.  
5. **REST namespace discovery:** Query `/wp-json/ems/v1/` and the Route Card Engine REST endpoints; confirm HTTP 200/401 responses (no 404 or 500 fatal errors).  
6. **Admin screen & shortcode asset resolution:** Load `wp-admin/admin.php?page=ems-expedition-board`, `wp-admin/admin.php?page=ems-signups`, `wp-admin/tools.php?page=ems-route-card-engine`, `[ems-portal]`, and `[ems-leader-dashboard]`. Confirm zero 404s on `.js`/`.css` bundles and zero console errors.

# **Gate 5: Standalone deployability & isolation test**

Because `ems-route-card-engine` must remain deployable to external WordPress installations without `ems-plugin`, Agent 2 must verify both standalone runtime isolation and `.zip` artifact integrity, as well as testing target selection flags (`bash bin/deploy.sh ems`, `bash bin/deploy.sh rce`, `bash bin/package.sh ems --no-bump`, `bash bin/package.sh rce --no-bump`) to confirm single-target building and packaging work independently:

```sh
# 1. Temporarily deactivate ems-plugin in local WordPress
docker-compose exec -T wordpress wp plugin deactivate ems-plugin

# 2. Verify Route Card Engine still boots and serves its diagnostic studio without fatal errors
docker-compose exec -T wordpress wp eval 'echo defined("EMS_ROUTE_ENGINE_VERSION") || class_exists("EmsRouteEngine\Plugin") ? "RCE_OK\n" : "RCE_FAIL\n";'

# 3. Re-activate ems-plugin and verify all data is untouched
docker-compose exec -T wordpress wp plugin activate ems-plugin

# 4. Test selective packaging and inspect generated zip archives
bash bin/package.sh ems --no-bump
bash bin/package.sh rce --no-bump
bash bin/package.sh all --no-bump
unzip -l dist/ems-plugin-*.zip | grep -E "ems-plugin.php|vendor/autoload.php|assets/"
unzip -l dist/ems-route-card-engine-*.zip | grep -E "ems-route-card-engine.php|vendor/autoload.php|assets/"
```

# **Gate 6: Modular blast-radius & fault-isolation verification**

Agent 2 must actively test that the new modular architecture contains failures without taking down sibling modules or the main website:

14. **Module feature-flag toggle test:**  
    * Disable the `volunteers` module via WP-CLI: `docker-compose exec -T wordpress wp option update ems_feature_flags '{"volunteers":false}' --format=json`  
    * Verify `[ems-volunteer-signup]` renders its clean disabled/maintenance notice, while `[ems-portal]`, `[ems-leader-dashboard]`, and `ems-expedition-board` continue returning HTTP 200 and passing all REST checks.  
    * Re-enable all modules (`wp option delete ems_feature_flags`).  
15. **Third-party plugin resilience test (Tutor LMS & Fluent Forms):**  
    * Temporarily deactivate Tutor LMS in the local environment (or mock its absence in integration tests) and load `[ems-portal]` and `ems-expedition-board`. Confirm `TutorLmsAdapter` degrades gracefully (*Training status unavailable*) with zero PHP fatal errors.  
16. **Boot-time fault-isolation test:**  
    * Write a PHPUnit test (`tests/Unit/Core/Module_RegistryTest.php`) that registers a mock `BrokenModule` throwing a `RuntimeException` inside `boot()`, alongside `SignupsModule` and `ExpeditionsModule`. Assert that `Module_Registry::bootAll()` catches the exception, logs it to `Audit_Logger`, and still boots `SignupsModule` and `ExpeditionsModule`.  
17. **Architecture boundary enforcement (PHPStan / test check):**  
    * Verify that no file inside `plugins/ems-plugin/src/Modules/` directly calls `tutor_utils()` or hooks `fluentform/*` outside `src/Integrations/`.

# **Gate 7: Clean workstation bootstrap & wp-content verification**

Verify that executing `bash bin/setup-workstation.sh` on a fresh machine environment boots the entire stack cleanly and populates `wordpress-env/wp-content/` correctly:  
**Host directory structure:** Confirm `wordpress-env/wp-content/plugins/{ems-plugin,ems-route-card-engine,fluentform,tutor}` and `wordpress-env/wp-content/uploads/ems-dem/` exist on the host filesystem.  
**Plugin activation:** Confirm WP-CLI reports all four plugins (and `oauth-login` if present) as active.  
**DEM Tile availability:** Confirm DEM binary tile files (`.bin`) are readable inside `wordpress-env/wp-content/uploads/ems-dem/` and accessible to Route Engine elevation queries.

# **Part 3: Staging & Production release promotion workflow**

All repository restructuring, `git subtree` merging, and namespace modularisation happen **exclusively on your local workstation**. Neither Staging (`staging4.see-expeditions.org.uk`) nor Production (`see-expeditions.org.uk`) needs Git, Composer, Node, or Docker installed. Once Agent 2 signs off on Gates 1–7 locally, the output is two standard, self-contained WordPress plugin `.zip` archives in `dist/`:

* `dist/ems-plugin-<version>.zip`  
* `dist/ems-route-card-engine-<version>.zip`

Follow this four-step promotion sequence to deploy to Staging and Production with zero data loss:

### **1\. Pre-upload server snapshot (Staging / Production)**

9. Log into WordPress Admin on the target server (`staging4.see-expeditions.org.uk` first, then `see-expeditions.org.uk`).  
10. Navigate to **EMS → Settings → Backups** and click **Download Unified JSON Backup** (or run `bash bin/preflight-snapshot.sh --mode=remote --url=https://staging4.see-expeditions.org.uk --token=$REST_TOKEN --out=/tmp/staging-before/`).  
11. Save the downloaded `.json` snapshot locally.

### **2\. Build the desired deployable .zip artifact(s)**

From the verified local monorepo root, build whichever plugin(s) changed:

* Both plugins: `bash bin/package.sh all`  
* Only Core EMS: `bash bin/package.sh ems`  
* Only Route Card Engine: `bash bin/package.sh rce`

### **3\. Upload & replace in WordPress Admin**

12. In the target server's WordPress Admin, go to **Plugins → Add New Plugin → Upload Plugin**.  
13. Upload `dist/ems-plugin-<version>.zip` (and/or `dist/ems-route-card-engine-<version>.zip`).  
14. Click **Replace current with uploaded** when WordPress prompts to overwrite the existing `ems-plugin` or `ems-route-card-engine` directory.  
15. Because the internal plugin folder names (`ems-plugin/` and `ems-route-card-engine/`) and database table names (`wp_ems_*`) are unchanged, WordPress swaps the PHP and JS files in place without deactivating the plugin or touching a single database row.

### **4\. Post-upload parity & smoke check on server**

16. Run the remote parity check (`bash bin/preflight-snapshot.sh --mode=remote --url=https://staging4.see-expeditions.org.uk --token=$REST_TOKEN --out=/tmp/staging-after/` and `diff -u` the parity manifests) or check **EMS → Settings → Backups / Audit Logs** to confirm all record counts match pre-upload values.  
17. Visit **EMS → Settings → Feature Flags** to confirm all modules report `Active (Healthy)`.  
18. Repeat Steps 1–4 on Production (`see-expeditions.org.uk`) once Staging is verified.

# **Rollback procedure**

If any parity, REST, or database verification check fails after deployment to Staging or Production, execute the following non-destructive rollback:

7. **Local Docker restore:** Import the baseline JSON snapshot via WP-CLI: `docker-compose exec -T wordpress wp ems backup import /tmp/ems-migration-baseline/ems-unified-backup.json`  
8. **Remote server restore:** Post the backup snapshot to the REST endpoint: `curl -X POST https://staging4.see-expeditions.org.uk/wp-json/ems/v1/backups/import -H "Authorization: Bearer $REST_TOKEN" -F "file=@/tmp/ems-migration-baseline/ems-unified-backup.json"` or upload it via **EMS → Settings → Backups → Restore**.

# **Agent 2 verification & sign-off matrix**

| Gate | Verification Criteria | Status |
| :---- | :---- | :---- |
| Gate 1: Git History | Subtree merge preserves 725+ EMS and 36+ RCE commits; git log \--follow traverses move. | \[ \] PASS |
| Gate 2: Zero Data Loss | All 10 SQL tables, CPT counts, and options match preflight baseline checksums. | \[ \] PASS |
| Gate 3: Automated Tests & Static Analysis | PHPUnit (EMS & RCE), PHPStan 2G (EMS & RCE), and Vitest suites pass with 0 errors. | \[ \] PASS |
| Gate 4: Runtime & REST | wp-admin pages, REST endpoints (/ems/v1/), and shortcodes return HTTP 200 without console/PHP errors. | \[ \] PASS |
| Gate 5: Standalone RCE | Route Card Engine operates cleanly when ems-plugin is deactivated. | \[ \] PASS |
| Gate 6: Modular Isolation | Module flags, Tutor LMS absence, and boot exceptions degrade gracefully without white-screening. | \[ \] PASS |
| Gate 7: Workstation Setup | setup-workstation.sh bootstraps fresh environment, mounts wordpress-env/wp-content, and seeds DEM tiles. | \[ \] PASS |
| Gate 8: Selective Deployment | deploy.sh and package.sh support ems, rce, and all targets independently. | \[ \] PASS |
| Gate 9: Release Packaging | zip archives unpack cleanly with top-level plugin folders and production autoloaders. | \[ \] PASS |

19. 