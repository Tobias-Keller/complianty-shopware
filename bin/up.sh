#!/usr/bin/env bash
# Starts the Shopware development environment and installs the plugin.
set -euo pipefail
cd "$(dirname "$0")/.."
C="${SHOPWARE_CONTAINER:-complianty_shopware}"
P="${SHOPWARE_PORT:-8085}"
docker compose up -d
echo "Waiting for Shopware (the first start takes 1-3 minutes) ..."
until curl -fsS -o /dev/null http://localhost:$P/admin 2>/dev/null; do sleep 3; done
# Apache/PHP do not see the Compose environment, Symfony reads it from .env.local.
docker exec "$C" bash -lc '
  cd /var/www/html &&
  grep -q COMPLIANTY_API_BASE .env.local 2>/dev/null ||
    { echo "COMPLIANTY_API_BASE=http://host.docker.internal:8003/api/v1/connect" >> .env.local; chown www-data .env.local; }'
docker exec -u www-data "$C" bash -lc '
  cd /var/www/html &&
  PUB=custom/plugins/CompliantyConnector/src/Resources/public/administration &&
  bin/console plugin:refresh &&
  # Install first: the Administration build only includes plugins that are installed and active.
  bin/console plugin:install --activate CompliantyConnector &&
  bin/console bundle:dump >/dev/null &&
  if bin/console --version | grep -q "Shopware 6\.6\."; then
    # 6.6 builds with webpack into js/ and css/. build-administration.sh ends with "Killed" (exit 137) in this
    # image although the plugin bundle is built, so success is judged by the bundle, not by the exit code.
    rm -f "$PUB/js/complianty-connector.js" &&
    { bin/build-administration.sh >/tmp/admin-build.log 2>&1 || true; } &&
    { test -s "$PUB/js/complianty-connector.js" || { echo "Administration build did not produce the plugin bundle (6.6), build log follows"; tail -n 60 /tmp/admin-build.log; exit 1; }; }
  else
    # 6.7 builds with Vite into assets/ and .vite/. Building only the extensions is enough and needs far less memory.
    rm -rf "$PUB/assets" "$PUB/.vite" &&
    SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1 bin/build-administration.sh >/tmp/admin-build.log 2>&1 &&
    { test -s "$PUB/.vite/entrypoints.json" || { echo "Administration build did not produce the plugin bundle (6.7), build log follows"; tail -n 60 /tmp/admin-build.log; exit 1; }; }
  fi &&
  bin/console assets:install >/dev/null &&
  bin/console scheduled-task:register >/dev/null &&
  bin/console cache:clear'
# Point the storefront domain at the published port. Set directly, so that running this script again is harmless
# (sales-channel:update:domain appends the port again on every run).
docker exec "$C" mysql -uroot -proot -h127.0.0.1 shopware -e "UPDATE sales_channel_domain SET url='http://localhost:$P' WHERE url LIKE 'http://localhost%'"
docker exec -u www-data "$C" bash -lc 'cd /var/www/html && bin/console cache:clear -q'
echo "Storefront: http://localhost:$P  Admin: http://localhost:$P/admin (admin / shopware)"
