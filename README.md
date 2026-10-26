# Complianty Connector for Shopware

Shopware 6 plugin that connects a shop to a [Complianty](https://complianty.de/) account. It shows the scan status in the Administration, can start a scan, and outputs the approved privacy policy from the account through a Shopping Experiences element.

- Shopware 6.6 and 6.7, PHP 8.2 or newer, GPL-2.0-or-later
- No runtime dependencies beyond Shopware itself.
- The Administration bundles are committed in `src/Resources/public/administration/`, one build per Shopware line: webpack output (`js/`, `css/`) for 6.6 and Vite output (`assets/`, `.vite/`) for 6.7. Shopware loads the one that fits. CI fails when a committed bundle does not match what the build produces.

## How it works

| Part | Where |
|---|---|
| Only place that talks to the service | `src/Api/Client.php` |
| Response validation, whitelist per endpoint, error code whitelist | `src/Api/Client.php`, `src/Api/Result.php` |
| Token storage (encrypted system config) | `src/Service/TokenStore.php` |
| Privacy policy filtering (HTMLPurifier, narrow whitelist) | `src/Service/DseSanitizer.php` |
| Local copy of the policy and its sync | `src/Service/DseStore.php`, `src/Service/DseSync.php`, `src/ScheduledTask/` |
| Shopping Experiences element `complianty-dse` | `src/Cms/`, `src/Resources/views/`, `src/Resources/app/administration/src/cms-element/` |
| Admin API routes (connect, status, scan, sync) | `src/Controller/ConnectionController.php` |
| Administration page (Settings > Plugins > Complianty) | `src/Resources/app/administration/src/module/` |

Rules that the code and the tests hold to:

- Nothing is sent before a token is entered.
- The token travels only in the `Authorization` header. It is never logged, shown, stored in plain text or sent to the Administration. The UI only gets a short hint.
- Everything from the service is untrusted: validated against a schema, unknown fields dropped, length capped, error codes checked against a whitelist. The privacy policy HTML is filtered with a narrow whitelist when it is stored and again when it is output.
- Visitors do not trigger requests while a local copy exists. The copy is refreshed every 6 hours by a scheduled task. Only a page view without any copy makes one request with a 3 second timeout.
- Scans are started only by a click of a user with write permission. The service enforces cooldown and daily budget, the plugin shows the result.
- Redirects are never followed. `COMPLIANTY_API_BASE` is ignored in production.

## Install

With Composer, in the shop project:

```bash
composer require complianty/shopware-connector
bin/console plugin:refresh
bin/console plugin:install --activate CompliantyConnector
bin/console cache:clear
```

Or download `CompliantyConnector-x.y.z.zip` from the [releases](https://github.com/Tobias-Keller/complianty-shopware/releases), unpack it into `custom/plugins/` and run the same `plugin:` commands. The ZIP contains the built Administration bundles, so no build step is needed.

## Use it

1. Create a token for the website and the Shopware integration in your Complianty account.
2. In the Administration open Settings > Plugins > Complianty, paste the token and click Connect.
3. In the Shopping Experiences editor, add the element "Privacy policy (Complianty)" to your privacy page layout.

## Development

The setup uses [dockware](https://dockware.io/) and needs Docker.

```bash
bin/up.sh        # start Shopware 6.6, install the plugin, build the Administration
bin/qa.sh        # run all checks
bin/qa.sh fix    # let PHP-CS-Fixer repair instead of report
```

| URL | |
|---|---|
| http://localhost:8085 | Storefront |
| http://localhost:8085/admin | Administration (`admin` / `shopware`, the dockware default) |
| http://localhost:8086 | Adminer |

To point the plugin at a Complianty backend running on your machine, set `COMPLIANTY_API_BASE` (the default in `docker-compose.yml` is `http://host.docker.internal:8003/api/v1/connect`). The override works only outside of `APP_ENV=prod`.

`bin/qa.sh` runs, in this order:

| Step | Tool | Configuration |
|---|---|---|
| PHP style | PHP-CS-Fixer | `.php-cs-fixer.dist.php`, taken from shopware/shopware |
| Static analysis | PHPStan level 8, `shopwarelabs/phpstan-shopware`, strict, deprecation and PHPUnit rules | `phpstan.plugin.neon` |
| Administration JS and Twig | ESLint with the Shopware Administration rules | `src/Resources/app/administration/.eslintrc.js`, tools in `tools/admin-lint/` |
| Administration SCSS | Stylelint with the Shopware config | `src/Resources/app/administration/.stylelintrc` |
| Administration logic | `node --test` (polling, result mapping) | `src/Resources/app/administration/tests/` |
| Unit tests | PHPUnit | `phpunit.xml.dist` |
| Integration tests | PHPUnit against a real kernel and a fake Complianty API served by a real HTTP server | `phpunit.integration.xml.dist` |

Integration tests use the database `shopware_test` (the Shopware `TestBootstrapper` appends `_test`). The development database is not touched, and `TEST_TOKEN=none` is rejected.

The lint tools sit in `tools/admin-lint/` and not in the Administration folder of the plugin on purpose: Shopware runs `npm install` for every `package.json` it finds there, on every shop that builds the plugin.

The PHP-CS-Fixer, ESLint and Stylelint configurations are derived from [shopware/shopware](https://github.com/shopware/shopware) (MIT).

## Release

1. `bin/version.sh set 1.2.3` writes the version into `composer.json`. Add an entry for it to `CHANGELOG.md` and `CHANGELOG_de-DE.md`.
2. If the Administration code changed, run `bin/up.sh` against 6.6 and against 6.7 (see the comment in `docker-compose.yml`) and commit both bundles.
3. Commit, tag `v1.2.3`, push the tag.

The `Release` workflow then runs the checks, builds `CompliantyConnector-1.2.3.zip` from the tagged commit, validates it with `shopware-cli extension validate`, and publishes a GitHub release with the ZIP and its SHA-256.

Packagist picks up new tags by itself once the repository is registered there, see below.

## Packagist

Register the repository once at [packagist.org/packages/submit](https://packagist.org/packages/submit) with `https://github.com/Tobias-Keller/complianty-shopware`. Enable the GitHub hook in the Packagist settings so that every tag is published without a manual update. The package name is `complianty/shopware-connector` and the type `shopware-platform-plugin`. `composer validate` warns about the `version` field in `composer.json`. It stays on purpose, because Shopware reads the plugin version from it.

## Security

See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
