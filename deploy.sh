#!/bin/bash

# === CONFIGURATION ===
USER="debian"
HOST="vps.latelier22.fr"
REMOTE_PATH="/home/debian/boutique.multimedia"
LOCAL_PATH="$(pwd)"

# === BUILD EN LOCAL ===
echo "==> Compilation locale (composer + pnpm) en mode production..."
export APP_ENV=prod APP_DEBUG=0 && composer install --no-dev --optimize-autoloader
pnpm install
pnpm run build

# === TRANSFERT COMPLET DU DOSSIER ===
echo "==> Transfert vers $USER@$HOST:$REMOTE_PATH ..."
rsync -avz \
  --delete \
  --exclude ".git" \
  --exclude "node_modules" \
  --exclude "var" \
  "$LOCAL_PATH/" "$USER@$HOST:$REMOTE_PATH"

# === POST-DÉPLOIEMENT SUR LE SERVEUR ===
echo "==> Post-déploiement distant..."

ssh "$USER@$HOST" <<EOF
  cd $REMOTE_PATH

  echo "==> Préparation dossier var et permissions..."
  mkdir -p var
  chmod -R 775 var
  chown -R www-data:www-data .

  echo "==> Symfony : cache et migrations..."
  APP_ENV=prod php bin/console cache:clear
  APP_ENV=prod php bin/console cache:warmup

  echo "✅ Déploiement terminé avec succès sur $HOST."
EOF
