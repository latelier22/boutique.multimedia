PROJECT=/var/www/boutique.multimedia
cd "$PROJECT"

# 1) Proprio "déployeur:grp-web"
sudo chown -R debian:www-data .

# 2) Modes corrects (setgid sur dossiers)
sudo find var -type d -exec chmod 2775 {} \;
sudo find var -type f -exec chmod 0664 {} \;

# Si tu as ces dossiers, fais pareil :
[ -d public/media ] && {
  sudo find public/media -type d -exec chmod 2775 {} \;
  sudo find public/media -type f -exec chmod 0664 {} \;
}
[ -d private ] && {
  sudo find private -type d -exec chmod 2775 {} \;
  sudo find private -type f -exec chmod 0664 {} \;
}

# 3) ACL : debian + www-data ont rwX maintenant ET par défaut pour le futur
sudo setfacl -R  -m u:debian:rwx,u:www-data:rwx var public/media private 2>/dev/null || true
sudo setfacl -dR -m u:debian:rwx,u:www-data:rwx var public/media private 2>/dev/null || true

