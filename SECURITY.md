# Security Policy

Please report security issues privately by e-mail to mail@complianty.de.

Do not open public issues for vulnerabilities. We acknowledge reports within 3 working days.

## Supported versions

The latest release.

## What is in scope

- Handling of the connection token (storage, transport, display, logs).
- Output of data from the service in the admin and in the storefront (XSS).
- ACL and authentication checks of the Admin API routes.
- Anything that makes the plugin contact a host other than the Complianty service, or send more than the token and the plugin version.

## Token model

The token belongs to one website and one integration. It is stored encrypted in the system configuration (libsodium secretbox, key derived from the shop's `APP_SECRET`) and is never sent to the Administration, which only receives a short hint such as `clp_site_AbC1…`. The encryption protects against a leak of the database alone, not against access to `APP_SECRET`. A leaked token can be revoked in the Complianty account at any time.

The token travels only in the `Authorization` header of requests to the Complianty service. Shopware's HTTP client is used, so proxy settings of the shop apply. Redirects are not followed.
