#!/usr/bin/env bash
# Starts the Shopware development environment and installs the plugin.
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose up -d
echo "Waiting for Shopware (the first start takes 1-3 minutes) ..."
until curl -fsS -o /dev/null http://localhost:8085/admin 2>/dev/null; do sleep 3; done
# Apache/PHP do not see the Compose environment, Symfony reads it from .env.local.
docker exec complianty_shopware bash -lc '
  cd /var/www/html &&
  grep -q COMPLIANTY_API_BASE .env.local 2>/dev/null ||
    { echo "COMPLIANTY_API_BASE=http://host.docker.internal:8003/api/v1/connect" >> .env.local; chown www-data .env.local; }'
docker exec -u www-data complianty_shopware bash -lc '
  cd /var/www/html &&
  bin/console plugin:refresh &&
  # Install first: the Administration build only includes plugins that are installed and active.
  bin/console plugin:install --activate CompliantyConnector &&
  bin/console bundle:dump >/dev/null &&
  rm -f custom/plugins/CompliantyConnector/src/Resources/public/administration/js/complianty-connector.js &&
  # build-administration.sh ends with "Killed" (exit 137) in this image although the plugin bundle is built.
  # So success is judged by the bundle, not by the exit code.
  { bin/build-administration.sh >/tmp/admin-build.log 2>&1 || true; } &&
  { test -s custom/plugins/CompliantyConnector/src/Resources/public/administration/js/complianty-connector.js ||
    { echo Administration build did not produce the plugin bundle, last lines of the build log follow; tail -n 60 /tmp/admin-build.log; exit 1; }; } &&
  bin/console assets:install >/dev/null &&
  bin/console scheduled-task:register >/dev/null &&
  { bin/console sales-channel:update:domain localhost:8085 >/dev/null || true; } &&
  bin/console cache:clear'
echo "Storefront: http://localhost:8085  Admin: http://localhost:8085/admin (admin / shopware)"
