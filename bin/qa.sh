#!/usr/bin/env bash
# Quality checks inside the shop container: PHP-CS-Fixer (Shopware config), PHPStan (level 8 + Shopware rules),
# ESLint/Stylelint (Administration), JS tests, PHPUnit (unit) and PHPUnit (integration against a real kernel and
# the test database shopware_test).
#   bin/qa.sh          everything
#   bin/qa.sh fix      let PHP-CS-Fixer repair instead of report
set -euo pipefail

C="${SHOPWARE_CONTAINER:-complianty_shopware}"
PLUGIN=/var/www/html/custom/plugins/CompliantyConnector
TOOLS=/tmp/qa
CS_FLAGS='--dry-run --diff'
[ "${1:-}" = fix ] && CS_FLAGS='-v'

docker exec "$C" bash -lc "
set -e
if [ ! -x $TOOLS/vendor/bin/phpunit ]; then
  mkdir -p $TOOLS && cd $TOOLS
  echo '{\"require-dev\":{\"phpunit/phpunit\":\"^11.5\",\"phpstan/phpstan\":\"^2.1\",\"friendsofphp/php-cs-fixer\":\"^3.95\",\"shopwarelabs/phpstan-shopware\":\"*\",\"phpstan/phpstan-strict-rules\":\"^2.0\",\"phpstan/phpstan-deprecation-rules\":\"^2.0\",\"phpstan/phpstan-phpunit\":\"^2.0\"},\"config\":{\"allow-plugins\":{\"phpstan/extension-installer\":false}}}' > composer.json
  composer update --no-interaction --no-progress -q
fi
# The integration tests need symfony/browser-kit for the KernelBrowser. The dockware image ships no dev dependencies.
if [ ! -d /var/www/html/vendor/symfony/browser-kit ]; then
  (cd /var/www/html && composer require --dev symfony/browser-kit symfony/css-selector --no-interaction --no-progress -q)
fi
cd $PLUGIN
echo '== PHP-CS-Fixer'
PHP_CS_FIXER_IGNORE_ENV=1 $TOOLS/vendor/bin/php-cs-fixer fix $CS_FLAGS --config .php-cs-fixer.dist.php
echo '== PHPStan'
# Clear the result cache: a gate must not depend on cache state.
$TOOLS/vendor/bin/phpstan clear-result-cache -c phpstan.plugin.neon -q
$TOOLS/vendor/bin/phpstan analyse -c phpstan.plugin.neon --no-progress --memory-limit=1G
echo '== ESLint + Stylelint (Administration)'
# The lint tools live outside the plugin's Administration folder: Shopware runs npm install for every
# package.json it finds there, also on customers' shops.
# The rules come from the shop's own vendor directory, and their options change between Shopware lines.
# The configuration is derived from 6.6, so it only runs there.
if (cd /var/www/html && bin/console --version | grep -q 'Shopware 6\\.6\\.'); then
  cd $PLUGIN/tools/admin-lint
  [ -d node_modules ] || npm install --no-audit --no-fund --loglevel=error
  npm run lint --silent
  npm run lint:scss --silent
else
  echo 'skipped: the lint configuration is derived from Shopware 6.6'
fi
echo '== JS tests (Administration logic, node --test)'
cd $PLUGIN/src/Resources/app/administration
node --test --experimental-default-type=module tests/
cd $PLUGIN
echo '== PHPUnit (Unit)'
$TOOLS/vendor/bin/phpunit -c phpunit.xml.dist --no-progress
echo '== PHPUnit (integration, kernel + test database)'
$TOOLS/vendor/bin/phpunit -c phpunit.integration.xml.dist --no-progress
"
