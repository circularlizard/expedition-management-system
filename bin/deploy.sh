#!/usr/bin/env bash
set -euo pipefail

# bin/deploy.sh - Build and deploy plugins to local WordPress environment
# Usage:
#   bash bin/deploy.sh [ems|rce|route-engine|all] [--skip-build]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

# Parse arguments
TARGET="all"
SKIP_BUILD=""

if [ "${1:-}" = "--skip-build" ]; then
  TARGET="all"
  SKIP_BUILD="--skip-build"
else
  TARGET="${1:-all}"
  SKIP_BUILD="${2:-}"
fi

# Determine WordPress plugins destination directory
if [ -n "${WP_PLUGINS_DIR:-}" ]; then
  DEST_DIR="${WP_PLUGINS_DIR}"
elif [ -d "${ROOT_DIR}/wordpress-env/wp-content/plugins" ]; then
  DEST_DIR="${ROOT_DIR}/wordpress-env/wp-content/plugins"
elif [ -d "${ROOT_DIR}/../wordpress-env/wordpress/wp-content/plugins" ]; then
  DEST_DIR="${ROOT_DIR}/../wordpress-env/wordpress/wp-content/plugins"
elif [ -d "${ROOT_DIR}/../wordpress-env/wp-content/plugins" ]; then
  DEST_DIR="${ROOT_DIR}/../wordpress-env/wp-content/plugins"
else
  DEST_DIR="${ROOT_DIR}/wordpress-env/wp-content/plugins"
fi

# Find running WordPress container ID or name
find_wp_container() {
  # 1. Check local docker-compose
  if command -v docker-compose >/dev/null 2>&1; then
    local cid
    cid=$(docker-compose ps -q wordpress 2>/dev/null | head -n1 || true)
    if [ -n "${cid}" ]; then
      echo "${cid}"
      return 0
    fi
  fi

  # 2. Check docker compose
  local cid_dc
  cid_dc=$(docker compose ps -q wordpress 2>/dev/null | head -n1 || true)
  if [ -n "${cid_dc}" ]; then
    echo "${cid_dc}"
    return 0
  fi

  # 3. Check any running container named *wordpress* (excluding db/mariadb/caddy/cli/cron)
  local candidate
  candidate=$(docker ps --filter "status=running" --format "{{.Names}}" 2>/dev/null | grep -E "wordpress" | grep -v -E "db|database|mysql|mariadb|caddy|cli|cron" | head -n1 || true)
  if [ -n "${candidate}" ]; then
    echo "${candidate}"
    return 0
  fi

  return 1
}

# Ensure plugin is activated in running container
activate_plugin() {
  local slug="$1"
  local container
  if container=$(find_wp_container); then
    echo "  --> Verifying plugin activation in container: ${container}..."
    if ! docker exec -i "${container}" wp plugin is-active "${slug}" --allow-root >/dev/null 2>&1; then
      echo "  --> Activating ${slug}..."
      docker exec -i "${container}" wp plugin activate "${slug}" --allow-root >/dev/null 2>&1 || true
    fi
  fi
}

cleanup_retired_plugin() {
  local container
  if container=$(find_wp_container); then
    if docker exec -i "${container}" wp plugin is-installed ems-route-card-engine --allow-root >/dev/null 2>&1; then
      echo "  --> Deactivating retired ems-route-card-engine in container..."
      docker exec -i "${container}" wp plugin deactivate ems-route-card-engine --allow-root >/dev/null 2>&1 || true
    fi
  fi
  if [ -d "${DEST_DIR}/ems-route-card-engine" ]; then
    echo "  --> Removing retired ems-route-card-engine directory from ${DEST_DIR}..."
    rm -rf "${DEST_DIR}/ems-route-card-engine"
  fi
}

deploy_plugin() {
  local folder="$1"
  local slug="$2"
  local plugin_src="${ROOT_DIR}/plugins/${folder}"

  if [ ! -d "${plugin_src}" ]; then
    echo "ERROR: Plugin source directory not found: ${plugin_src}"
    exit 1
  fi

  echo "==> Deploying ${slug} to ${DEST_DIR}/${slug}..."

  if [ "${SKIP_BUILD}" != "--skip-build" ]; then
    echo "  --> Building frontend assets (${folder})..."
    npm run build --workspace="plugins/${folder}"

    if [ -f "${plugin_src}/composer.json" ]; then
      echo "  --> Installing Composer dependencies (${folder})..."
      composer install --working-dir="${plugin_src}" --no-interaction --prefer-dist --quiet
    fi
  else
    echo "  --> Skipping build (--skip-build specified)"
  fi

  mkdir -p "${DEST_DIR}/${slug}"
  rsync -a --delete \
    --exclude='.git*' \
    --exclude='node_modules/' \
    --exclude='tests/' \
    --exclude='scratch/' \
    --exclude='mockdata/' \
    --exclude='docs/' \
    --exclude='user-guide/' \
    "${plugin_src}/" "${DEST_DIR}/${slug}/"

  activate_plugin "${slug}"
  echo "==> ${slug} deployed and active."
}

cleanup_retired_plugin

case "${TARGET}" in
  ems|rce|route-engine|all)
    deploy_plugin "ems-plugin" "ems-plugin"
    ;;
  *)
    echo "Usage: bash bin/deploy.sh [ems|all] [--skip-build]"
    exit 1
    ;;
esac
