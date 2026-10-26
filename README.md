# Complianty Connector for Shopware

Shopware 6 plugin that connects a shop to a [Complianty](https://complianty.de/) account. It shows the scan status in the Administration, can start a scan, and outputs the approved privacy policy from the account through a Shopping Experiences element.

- Shopware 6.6, PHP 8.2 or newer, GPL-2.0-or-later
- No runtime dependencies beyond Shopware itself.
- No build step for the Administration other than Shopware's own (`bin/build-administration.sh`). The built files are committed in `src/Resources/public/administration/`.

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
| Static analysis | PHPStan level 8, `shopwarelabs/phpstan-shopware`, strict, deprecation and PHPUnit rules | `phpstan.neon.dist` |
| Administration JS and Twig | ESLint with the Shopware Administration rules | `src/Resources/app/administration/.eslintrc.js`, tools in `tools/admin-lint/` |
| Administration SCSS | Stylelint with the Shopware config | `src/Resources/app/administration/.stylelintrc` |
| Administration logic | `node --test` (polling, result mapping) | `src/Resources/app/administration/tests/` |
| Unit tests | PHPUnit | `phpunit.xml.dist` |
| Integration tests | PHPUnit against a real kernel and a fake Complianty API served by a real HTTP server | `phpunit.integration.xml.dist` |

Integration tests use the database `shopware_test` (the Shopware `TestBootstrapper` appends `_test`). The development database is not touched, and `TEST_TOKEN=none` is rejected.

The lint tools sit in `tools/admin-lint/` and not in the Administration folder of the plugin on purpose: Shopware runs `npm install` for every `package.json` it finds there, on every shop that builds the plugin.

The PHP-CS-Fixer, ESLint and Stylelint configurations are derived from [shopware/shopware](https://github.com/shopware/shopware) (MIT).

## Security

See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
