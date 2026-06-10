#!/bin/sh
set -eu

cd /opt/drupal

DB_HOST="${SLM_DB_HOST:-database}"
DB_NAME="${SLM_DB_NAME:-drupal}"
DB_USER="${SLM_DB_USER:-drupal}"
DB_PASSWORD="${SLM_DB_PASSWORD:-drupal}"
ADMIN_USER="${SLM_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${SLM_ADMIN_PASSWORD:-admin}"
SETTINGS_FILE="web/sites/default/settings.php"
READY_FILE="web/sites/default/files/.secure-location-map-ready"

mkdir -p web/sites/default/files
chown -R www-data:www-data web/sites/default

if [ ! -f "$SETTINGS_FILE" ]; then
  echo "Installing Drupal for the first time..."
  vendor/bin/drush site:install standard \
    --db-url="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}/${DB_NAME}" \
    --site-name="Secure Location Map Demo" \
    --account-name="$ADMIN_USER" \
    --account-pass="$ADMIN_PASSWORD" \
    --yes
fi

vendor/bin/drush cache:rebuild --yes
vendor/bin/drush pm:enable secure_location_map --yes

if [ ! -f "$READY_FILE" ]; then
  echo "Importing the supplied CSV files. The bank file can take several minutes..."
  vendor/bin/drush scr /usr/local/lib/secure-location-map-import.php
  touch "$READY_FILE"
  chown www-data:www-data "$READY_FILE"
fi

vendor/bin/drush cache:rebuild --yes
echo "Secure Location Map is ready at http://localhost:8080/local-media-finder"

exec docker-php-entrypoint apache2-foreground

