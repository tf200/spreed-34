#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

# ==========================================
# CONFIGURATION (Read from environment, with defaults)
# ==========================================
VPS_USER="${VPS_USER:-root}"
VPS_HOST="${VPS_HOST:-62.171.172.160}"
VPS_NC_PATH="${VPS_NC_PATH:-/opt/nextcloud-stack/nextcloud}"
APP_NAME="${APP_NAME:-spreed}"
CONTAINER_NAME="${CONTAINER_NAME:-nextcloud-app}"

# ==========================================
# VALIDATION
# ==========================================
if [ "$VPS_HOST" = "your-vps-ip" ]; then
  echo "⚠️  Error: Please configure VPS_HOST in your .env file."
  exit 1
fi

# ==========================================
# 1. BUILD LOCAL ASSETS
# ==========================================
echo "📦 Building local assets..."
npm run build
composer install --no-dev --no-scripts

# ==========================================
# 2. RSYNC FILES TO VPS (Syncing to custom_apps volume)
# ==========================================
echo "🚀 Syncing files to VPS volume..."
rsync -avz --delete \
  --exclude='node_modules/' \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='tests/' \
  --exclude='cypress/' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='package.json' \
  --exclude='package-lock.json' \
  --exclude='webpack.js' \
  --exclude='.eslint*' \
  --exclude='.stylelint*' \
  --exclude='deploy.sh' \
  --exclude='Justfile' \
  --exclude='.env' \
  ./ "$VPS_USER@$VPS_HOST:$VPS_NC_PATH/custom_apps/$APP_NAME/"

# ==========================================
# 3. DOCKER COMMANDS: SET PERMISSIONS AND UPDATE APP
# ==========================================
echo "🔧 Setting permissions and enabling app inside Docker container..."
ssh "$VPS_USER@$VPS_HOST" "
  # Set ownership of the app folder to www-data inside the container
  docker exec -u root $CONTAINER_NAME chown -R www-data:www-data /var/www/html/custom_apps/$APP_NAME

  # Tell Nextcloud to update/enable the app
  docker exec -u www-data $CONTAINER_NAME php occ app:enable $APP_NAME
"

echo "✅ Deployment finished successfully!"
