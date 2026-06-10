FROM drupal:11-apache

WORKDIR /opt/drupal

RUN composer require drush/drush:^13 --no-interaction --no-progress

COPY docker/start-drupal.sh /usr/local/bin/start-secure-location-map
COPY docker/import-data.php /usr/local/lib/secure-location-map-import.php

RUN chmod +x /usr/local/bin/start-secure-location-map

ENTRYPOINT ["start-secure-location-map"]

