#!/usr/bin/env bash
set -euo pipefail

REMOTE_USER=debian
SSH_ALIAS=vps-latelier22
REMOTE_PATH=/home/debian/sylius112

ACTION=${1:-all}
TMP_DIR=""

if [[ "$ACTION" =~ ^(all|build)$ ]]; then
  TMP_DIR=$(mktemp -d)
  echo "🔄 Cloning dev branch into $TMP_DIR"
  git clone --depth 1 --branch dev . "$TMP_DIR"
  cd "$TMP_DIR"
  export APP_ENV=prod
  echo "📦 Installing PHP dependencies..."
  composer install --no-dev --optimize-autoloader --no-interaction
  echo "🌐 Building frontend assets..."
  pnpm install
  pnpm run build
fi

if [[ "$ACTION" =~ ^(all|rsync)$ ]]; then
  echo "🚀 Prepare remote directory"
  ssh -o IdentitiesOnly=yes "$SSH_ALIAS" \
    "sudo mkdir -p ${REMOTE_PATH} && sudo chown -R ${REMOTE_USER}:www-data ${REMOTE_PATH}"

  echo "🚀 Syncing files to ${SSH_ALIAS}:${REMOTE_PATH}"
  RSYNC_SSH="ssh -o IdentitiesOnly=yes"
  rsync -avz -e "$RSYNC_SSH" --delete \
    --exclude '.git/' \
    --exclude 'node_modules/' \
    --exclude 'vendor/' \
    --exclude '.env.local' \
    "${TMP_DIR:-.}/" "${SSH_ALIAS}:${REMOTE_PATH}"

  echo "⚙️  Running remote post-deploy tasks"
  ssh -o IdentitiesOnly=yes "$SSH_ALIAS" <<EOF
set -e
cd ${REMOTE_PATH}
cp .env.prod .env
php bin/console cache:clear
sudo chown -R ${REMOTE_USER}:www-data ${REMOTE_PATH}
sudo find ${REMOTE_PATH} -type d -exec chmod 755 {} +
sudo find ${REMOTE_PATH} -type f -exec chmod 644 {} +
sudo chmod -R 775 ${REMOTE_PATH}/var
sudo chmod -R 775 ${REMOTE_PATH}/public/media
sudo systemctl restart php8.2-fpm nginx
EOF
fi

if [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]]; then
  echo "🧹 Cleaning up local temporary directory"
  rm -rf "$TMP_DIR"
fi

echo "✅ Deployment ($ACTION) completed successfully!"
