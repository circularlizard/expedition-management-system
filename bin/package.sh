#!/usr/bin/env bash
set -euo pipefail

# bin/package.sh - Staging & Production .zip artifact builder
# Usage:
#   bash bin/package.sh [ems|rce|all] [--no-bump|--bump=patch|minor|major]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_ROOT="/tmp/ems-pkg-stage"

cd "${ROOT_DIR}"
mkdir -p "${DIST_DIR}"

TARGET="all"
BUMP_MODE="patch"

# Parse arguments
for arg in "$@"; do
  case "$arg" in
    ems)
      TARGET="ems"
      ;;
    rce|route-engine)
      TARGET="rce"
      ;;
    all)
      TARGET="all"
      ;;
    --no-bump)
      BUMP_MODE="none"
      ;;
    --bump=patch)
      BUMP_MODE="patch"
      ;;
    --bump=minor)
      BUMP_MODE="minor"
      ;;
    --bump=major)
      BUMP_MODE="major"
      ;;
    *)
      echo "Usage: bash bin/package.sh [ems|rce|all] [--no-bump|--bump=patch|minor|major]"
      exit 1
      ;;
  esac
done

# Cross-platform sed -i helper
sed_i() {
  if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "$@"
  else
    sed -i "$@"
  fi
}

bump_version() {
  local current="$1"
  local mode="$2"

  local major minor patch
  major=$(echo "$current" | cut -d. -f1)
  minor=$(echo "$current" | cut -d. -f2)
  patch=$(echo "$current" | cut -d. -f3)

  case "$mode" in
    major)
      major=$((major + 1))
      minor=0
      patch=0
      ;;
    minor)
      minor=$((minor + 1))
      patch=0
      ;;
    patch|*)
      patch=$((patch + 1))
      ;;
  esac
  echo "${major}.${minor}.${patch}"
}

package_plugin() {
  local folder="$1"
  local slug="$2"
  local main_file="$3"
  local plugin_dir="${ROOT_DIR}/plugins/${folder}"
  local stage_dir="${STAGE_ROOT}/${slug}"

  if [ ! -d "${plugin_dir}" ]; then
    echo "ERROR: Plugin directory not found: ${plugin_dir}"
    exit 1
  fi

  echo "==> Packaging ${slug}..."

  # 1. Read version from plugin bootstrap header
  local version
  version=$(grep -E "^\s*\*\s*Version:" "${plugin_dir}/${main_file}" | awk '{print $NF}' | tr -d '\r')

  if [ -z "${version}" ]; then
    echo "ERROR: Could not read Version from ${plugin_dir}/${main_file}"
    exit 1
  fi

  # 2. Bump version if requested
  if [ "${BUMP_MODE}" != "none" ]; then
    local new_version
    new_version=$(bump_version "${version}" "${BUMP_MODE}")
    echo "  --> Bumping version: ${version} -> ${new_version} (${BUMP_MODE})"
    sed_i -E "s/(\* Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+/\1${new_version}/" "${plugin_dir}/${main_file}"

    if grep -q "define([[:space:]]*'EMS_VERSION'" "${plugin_dir}/${main_file}"; then
      sed_i -E "s/(define\([[:space:]]*'EMS_VERSION',[[:space:]]*')[^']+(')/\1${new_version}\2/" "${plugin_dir}/${main_file}"
    elif grep -q "define([[:space:]]*'EMS_ROUTE_CARD_VERSION'" "${plugin_dir}/${main_file}"; then
      sed_i -E "s/(define\([[:space:]]*'EMS_ROUTE_CARD_VERSION',[[:space:]]*')[^']+(')/\1${new_version}\2/" "${plugin_dir}/${main_file}"
    fi
    version="${new_version}"
  else
    echo "  --> Packaging current version: ${version} (--no-bump)"
  fi

  # 3. Build production frontend assets
  echo "  --> Building production frontend assets..."
  npm run build --workspace="plugins/${folder}"

  # 4. Stage clean copy in /tmp so working tree dev dependencies remain intact
  rm -rf "${stage_dir}"
  mkdir -p "${stage_dir}"
  echo "  --> Staging clean files into ${stage_dir}..."
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

  # 5. Install production-only PHP autoloader inside staging folder
  if [ -f "${stage_dir}/composer.json" ]; then
    echo "  --> Installing production Composer dependencies..."
    composer install --working-dir="${stage_dir}" --no-dev --optimize-autoloader --no-interaction --quiet
  fi

  # 6. Create standard WordPress plugin zip with top-level <slug>/ folder
  local zip_path="${DIST_DIR}/${slug}-${version}.zip"
  rm -f "${zip_path}"
  (cd "${STAGE_ROOT}" && zip -rq "${zip_path}" "${slug}")
  echo "==> Created ${zip_path}"
  rm -rf "${stage_dir}"
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
