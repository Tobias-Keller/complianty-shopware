#!/usr/bin/env bash
# Quality checks inside the shop container: PHP-CS-Fixer (Shopware config), PHPStan (level 8 + Shopware rules),
# ESLint/Stylelint (Administration), JS tests, PHPUnit (unit) and PHPUnit (integration against a real kernel and
# the test database shopware_test).
#   bin/qa.sh          everything
#   bin/qa.sh fix      let PHP-CS-Fixer repair instead of report
set -euo pipefail

PLUGIN=/var/www/html/custom/plugins/CompliantyConnector
TOOLS=/tmp/qa
CS_FLAGS='--dry-run --diff'
[ "${1:-}" = fix ] && CS_FLAGS='-v'

docker exec complianty_shopware bash -lc "
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
$TOOLS/vendor/bin/phpstan clear-result-cache -c phpstan.neon.dist -q
$TOOLS/vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress --memory-limit=1G
echo '== ESLint + Stylelint (Administration)'
cd $PLUGIN/src/Resources/app/administration
[ -d node_modules ] || npm install --no-audit --no-fund --loglevel=error
npm run lint --silent
npx stylelint 'src/**/*.scss'
echo '== JS tests (Administration logic, node --test)'
npm test --silent
cd $PLUGIN
echo '== PHPUnit (Unit)'
$TOOLS/vendor/bin/phpunit -c phpunit.xml.dist --no-progress
echo '== PHPUnit (integration, kernel + test database)'
$TOOLS/vendor/bin/phpunit -c phpunit.integration.xml.dist --no-progress
"
