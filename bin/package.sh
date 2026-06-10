#!/usr/bin/env bash
set -e

# Reads the Version header from ems-plugin.php and produces dist/ems-plugin-{VERSION}.zip
# Usage: bash bin/package.sh

PLUGIN_FILE="ems-plugin.php"
DIST_DIR="dist"
STAGING_DIR="/tmp/ems-plugin-build"

CURRENT=$(grep -m1 "Version:" "$PLUGIN_FILE" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [ -z "$CURRENT" ]; then
  echo "ERROR: Could not read Version from $PLUGIN_FILE"
  exit 1
fi

# Increment patch segment (e.g. 0.1.0 -> 0.1.1)
MAJOR=$(echo "$CURRENT" | cut -d. -f1)
MINOR=$(echo "$CURRENT" | cut -d. -f2)
PATCH=$(echo "$CURRENT" | cut -d. -f3)
PATCH=$(( PATCH + 1 ))
VERSION="${MAJOR}.${MINOR}.${PATCH}"

# Write new version back into the plugin header
sed -i '' "s/ \* Version: ${CURRENT}/ \* Version: ${VERSION}/" "$PLUGIN_FILE"
echo "==> Bumped version: ${CURRENT} → ${VERSION}"

ZIP_NAME="ems-plugin-${VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"

echo "==> Building EMS plugin v${VERSION}..."

# Clean staging area
rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR/ems-plugin"

# Copy source files
rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='bin/' \
  --exclude='tests/' \
  --exclude='wordpress/' \
  --exclude='vendor/' \
  --exclude='dist/' \
  --exclude='node_modules/' \
  --exclude='docker-compose.yml' \
  --exclude='phpunit.xml' \
  --exclude='.gitignore' \
  --exclude='*.log' \
  . "$STAGING_DIR/ems-plugin/"

# Install production Composer dependencies
composer install \
  --no-dev \
  --optimize-autoloader \
  --working-dir="$STAGING_DIR/ems-plugin" \
  --quiet

# Create output directory and zip
mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"

cd "$STAGING_DIR"
zip -r "$OLDPWD/$ZIP_PATH" ems-plugin/ --quiet

echo "==> Built: ${ZIP_PATH}"
echo "    Upload via WP Admin → Plugins → Add New → Upload Plugin"

# Clean up
rm -rf "$STAGING_DIR"
