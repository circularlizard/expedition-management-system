#!/usr/bin/env bash
set -euo pipefail

# bin/setup-workstation.sh - Idempotent workstation bootstrap script
# Usage:
#   bash bin/setup-workstation.sh [--seed /path/to/backup.json]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

# Determine compose command
DC="docker-compose"
if ! command -v docker-compose >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1; then
    DC="docker compose"
  else
    echo "ERROR: docker-compose or docker compose is required."
    exit 1
  fi
fi

# 1. Verify host prerequisites & configure git hooks
echo "==> Verifying host prerequisites..."
for cmd in docker composer node npm rsync; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "ERROR: Missing prerequisite: $cmd"; exit 1; }
done

if [ -d ".githooks" ]; then
  git config core.hooksPath .githooks
fi

# 2. Create host wp-content structure
echo "==> Setting up host wp-content structure..."
mkdir -p wordpress-env/wp-content/{mu-plugins,plugins,themes,uploads/ems-dem}
chmod -R 777 wordpress-env/wp-content/uploads 2>/dev/null || true

# 3. Install NPM & Composer dependencies and build assets
echo "==> Installing NPM dependencies..."
npm install

echo "==> Installing Composer dependencies..."
composer install --working-dir=plugins/ems-plugin --prefer-dist --no-interaction

echo "==> Building frontend assets across workspaces..."
npm run build

# 4. Start Docker stack and wait for DB
echo "==> Starting Docker stack..."
$DC up -d

echo "==> Waiting for MariaDB to be ready..."
until $DC exec -T db mysqladmin ping -uwordpress -pwordpress --silent 2>/dev/null; do
  echo "  Waiting for MariaDB..."
  sleep 2
done

# 5. Bootstrap WordPress core if uninstalled
if ! $DC exec -T wordpress wp core is-installed --allow-root >/dev/null 2>&1; then
  echo "==> Bootstrapping WordPress core..."
  $DC exec -T wordpress wp core install \
    --url="https://localhost" \
    --title="EMS Local Development" \
    --admin_user="admin" \
    --admin_password="password" \
    --admin_email="admin@example.local" \
    --allow-root
  $DC exec -T wordpress wp rewrite structure '/%postname%/' --allow-root
fi

# 6. Install third-party plugins
echo "==> Installing and activating third-party plugins (fluentform, tutor)..."
$DC exec -T wordpress wp plugin install fluentform tutor --activate --allow-root || true

# 7. Sync login-with-google if available locally
if [ -d "../login-with-google" ]; then
  echo "==> Syncing local login-with-google (oauth-login)..."
  mkdir -p wordpress-env/wp-content/plugins/oauth-login/
  rsync -av --delete --exclude='.git' ../login-with-google/ wordpress-env/wp-content/plugins/oauth-login/
  $DC exec -T wordpress wp plugin activate oauth-login --allow-root || true
fi

# 8. Deploy monorepo plugins, copy DEM mock tiles & optional seed restore
echo "==> Deploying monorepo plugins..."
bash bin/deploy.sh all

echo "==> Copying DEM mock tiles if present..."
if [ -d "plugins/ems-plugin/mockdata" ]; then
  cp -n plugins/ems-plugin/mockdata/*.bin wordpress-env/wp-content/uploads/ems-dem/ 2>/dev/null || true
fi

if [ "${1:-}" = "--seed" ] && [ -n "${2:-}" ] && [ -f "${2}" ]; then
  echo "==> Restoring seed backup from ${2}..."
  $DC exec -T wordpress wp ems backup import "$2" --allow-root || true
fi

echo "==> Workstation setup complete! Access HTTPS at https://localhost"
