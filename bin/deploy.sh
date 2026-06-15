#!/usr/bin/env bash
set -e

# Build and deploy the plugin to the local wordpress directory.
# Usage: bash bin/deploy.sh

PLUGIN_NAME="ems-plugin"
TARGET_DIR="wordpress/wp-content/plugins/$PLUGIN_NAME"
STAGING_DIR="/tmp/ems-plugin-deploy"

echo "==> Deploying EMS plugin to $TARGET_DIR..."

# 1. Build JS assets
echo "==> Building JS assets..."
npm run build --silent

# 2. Clean staging area
rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR/$PLUGIN_NAME"
# 3. Copy source files to staging
echo "==> Staging files..."
rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='wordpress/' \
  --exclude='vendor/' \
  --exclude='dist/' \
  --exclude='node_modules/' \
  --exclude='docker-compose.yml' \
  --exclude='phpunit.xml' \
  --exclude='.gitignore' \
  --exclude='*.log' \
  --exclude='*.crt' \
  . "$STAGING_DIR/$PLUGIN_NAME/"


# 4. Install production Composer dependencies in staging
echo "==> Installing production dependencies..."
composer install \
  --no-dev \
  --optimize-autoloader \
  --working-dir="$STAGING_DIR/$PLUGIN_NAME" \
  --quiet

# 5. Sync staging to target
echo "==> Syncing to $TARGET_DIR..."
mkdir -p "$TARGET_DIR"
rsync -a --delete "$STAGING_DIR/$PLUGIN_NAME/" "$TARGET_DIR/"

# 6. Clean up
rm -rf "$STAGING_DIR"

# 7. Use WP-CLI to ensure plugin is activated
echo "==> Refreshing plugin status..."
docker compose run --rm wpcli plugin activate $PLUGIN_NAME --quiet

echo "==> Done! Plugin deployed and activated."
