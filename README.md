# ContactLift

A floating contact bar that helps visitors call, message, book or request a
callback through the right channel for the page they're on. Free plugin
(`plugins/contactlift`) plus a separate Pro add-on
(`plugins/contactlift-pro`, sold via Freemius) that requires it.

Full product/technical spec: see the build specification this repo
implements (kept outside version control). Implementation choices left open
by that spec are recorded in [docs/decisions.md](docs/decisions.md); current
status in [docs/progress.md](docs/progress.md).

## Local development

```bash
npm install
npm run env:start
```

Starts a local WordPress site via `@wordpress/env` on `http://localhost:8890`
(test site on `8891` — non-default ports so this can run alongside other
`wp-env`-based projects on the same machine), with both plugins
bind-mounted and active, plus WooCommerce, Elementor and the official Plugin
Check plugin for verification.

```bash
composer install
composer run lint       # PHPCS (WordPress-Extra + PHPCompatibilityWP)
composer run lint:fix   # PHPCBF autofix
composer run test       # fast unit suite, no WordPress boot required
npm run env:stop
```

### Running tests

Four PHPUnit suites — free and Pro each have a fast unit suite plus an
integration suite that boots the real WP test suite — plus JS tests and a
concurrency check:

- **Free unit** (`phpunit.xml.dist`) — domain logic (validators, channels,
  token resolution) tested without booting WordPress. `composer run test`.
- **Pro unit** (`phpunit-pro.xml.dist`) — the schedule and targeting
  engines, same no-WP-boot approach, injectable clock for the DST/overnight
  cases. `composer run test:pro`.
- **Free integration** (`phpunit-integration.xml.dist`) — free plugin only,
  real WP hooks and query context:

  ```bash
  npm run env:start
  npm run test:integration
  ```
- **Pro integration** (`phpunit-integration-pro.xml.dist`) — both plugins
  loaded together, proving the `clift_resolve_bar`/`clift_validate_schedule`
  wiring actually runs, not just each plugin's own domain classes in
  isolation:

  ```bash
  npm run test:integration:pro
  ```
- **JS** (`tests/js`) — the shipped `tracking.js` running unmodified in a
  Node sandbox with stubbed browser APIs: consent, revocation, GPC and REST
  URL handling. `npm run test:js` (no wp-env needed).
- **Concurrency** (`scripts/concurrency-check.sh`) — real parallel PHP
  processes racing the same callback UUID and stats counter (spec AT12/AT18).
  `npm run test:concurrency`.

Two things to know before running these. The PHPUnit suite and the wp-env site
on port 8891 share one database and the `wp_` prefix, so don't drop tables on
that site. And Docker Desktop's VM can be small (about 2 GB): the concurrency
check deliberately boots WordPress core only with 8 workers, because many
full-plugin processes at once have OOM-killed the database before.

## Packaging a release build

```bash
npm run build
npm run check-build
```

`scripts/build.sh` stages runtime-only files for both plugins and zips them
to `dist/contactlift-1.0.0.zip` / `dist/contactlift-pro-1.0.0.zip` with
SHA-256 checksums. `scripts/check-build.sh` installs a fresh build into a
throwaway copy inside wp-env and runs the official Plugin Check plugin
against it.

The Pro zip ships the Freemius SDK from `plugins/contactlift-pro/vendor/freemius/`
(and nothing else from `vendor/`). While the product ID and public key in
`contactlift-pro.php` are placeholders the build still succeeds but warns that
the zip is **not releasable**; `CLIFT_RELEASE=1 npm run build` refuses it. See
[docs/owner-setup.md](docs/owner-setup.md) for the activation steps.

### ⚠️ Packaging safety note

Never run `wp plugin install <zip> --force` (or "Replace current with
uploaded") inside wp-env while a dev copy is bind-mounted and active —
WordPress/WP-CLI can match plugins by their "Plugin Name" header rather
than by folder, which has previously deleted an active bind-mounted plugin
source on a sibling project in this line. Use `check-build.sh`'s
throwaway-copy approach instead of installing a build manually.

## Project layout

- `plugins/contactlift/` — free plugin. `contactlift.php` bootstrap;
  `src/Domain/` validation, channels (call/WhatsApp/email/link), token
  resolution — testable without WordPress; `src/Storage/` options +
  migrations; `src/Admin/` settings screens.
- `plugins/contactlift-pro/` — Pro add-on: targeting, business hours,
  callback requests, local click reports, JSON export/import, privacy tools,
  data lifecycle, and a guarded Freemius SDK adapter.
- `tests/unit/`, `tests/unit-pro/` — fast domain-logic tests.
  `tests/integration/`, `tests/integration-pro/` — real WP test suite.
  `tests/js/` — client-side tests. (`tests/e2e/` is reserved; no browser suite
  is checked in.)
- `docs/` — architecture, decisions, progress, owner setup, release
  checklist, verification report.
- `scripts/` — build/verify/packaging automation.
