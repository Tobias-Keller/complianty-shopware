# Complianty Connector für Shopware 6

Lokale Entwicklungsumgebung auf Basis von dockware/dev (Shopware 6.6).

```bash
bin/up.sh
```

| Dienst | URL |
|---|---|
| Storefront | http://localhost:8085 |
| Admin | http://localhost:8085/admin (admin / shopware) |
| Adminer | http://localhost:8086 (Server `127.0.0.1`, user `root`, pw `root`, DB `shopware`) |
| MySQL | 127.0.0.1:3307 |

Das Plugin liegt in `plugin/CompliantyConnector` und ist per Volume nach
`custom/plugins/CompliantyConnector` gemountet. Aus dem Container erreicht man
das lokale Complianty-Backend unter `http://host.docker.internal:8003`.

Konsole im Container:

```bash
docker exec -it -u www-data complianty_shopware bash
```

## Qualitätsprüfung

```bash
bin/qa.sh        # alles
bin/qa.sh fix    # PHP-CS-Fixer repariert statt nur zu melden
```

Läuft im Shop-Container und prüft der Reihe nach:

| Schritt | Werkzeug | Konfiguration |
|---|---|---|
| PHP-Stil | PHP-CS-Fixer | `.php-cs-fixer.dist.php`, übernommen aus shopware/shopware |
| Statische Analyse | PHPStan Level 8 + `shopwarelabs/phpstan-shopware`, strict-, deprecation-, phpunit-Regeln | `phpstan.neon.dist` |
| Admin-JS und Twig | ESLint mit der Shopware-Admin-Config (6.6) | `src/Resources/app/administration/.eslintrc.js` |
| Admin-SCSS | Stylelint mit der Shopware-Config | `.stylelintrc` |
| Admin-Logik | `node --test` (Polling, Ergebnis-Abbildung), ohne Zusatzpakete | `src/Resources/app/administration/tests/` |
| Unit-Tests | PHPUnit | `phpunit.xml.dist` |
| Integrationstests | PHPUnit gegen echten Kernel, Fake-Complianty-API (echter HTTP-Server) | `phpunit.integration.xml.dist` |

Die Integrationstests nutzen die Datenbank `shopware_test` (der `TestBootstrapper` hängt `_test` an). Die
Entwicklungsdatenbank `shopware` bleibt unberührt. `TEST_TOKEN=none` ist gesperrt.
