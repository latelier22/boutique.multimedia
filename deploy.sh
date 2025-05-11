#!/usr/bin/env bash
set -euo pipefail

# -------- Configuration --------
REMOTE_USER=debian
REMOTE_HOST=vps.latelier22.fr
REMOTE_PATH=/home/debian/sylius112

# -------- Prepare a clean working copy --------
# Use a temporary directory to avoid changing your local branch
TMP_DIR=$(mktemp -d)
echo "🔄 Cloning dev branch into $TMP_DIR"
git clone --depth 1 --branch dev . "$TMP_DIR"

# -------- Build inside the temporary directory --------
cd "$TMP_DIR"
echo "📦 Installing PHP dependencies..."
export APP_ENV=prod
composer install --no-dev --optimize-autoloader --no-interaction

echo "🌐 Building frontend assets..."
pnpm install
pnpm run build

# -------- Deploy to VPS --------
echo "🚀 Syncing files to ${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"
rsync -avz --delete \
  --exclude '.git/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude '.env.local' \
  "$TMP_DIR/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

# -------- Remote post-deploy tasks --------
echo "⚙️  Running migrations and clearing cache on server"
ssh "${REMOTE_USER}@${REMOTE_HOST}" <<EOF
set -e
cd ${REMOTE_PATH}
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod
sudo systemctl restart php8.2-fpm nginx
EOF

# -------- Cleanup local temporary copy --------
echo "🧹 Cleaning up temporary files"
rm -rf "$TMP_DIR"

echo "✅ Deployment completed successfully!"
