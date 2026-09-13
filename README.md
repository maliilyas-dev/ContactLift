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
`wp-env`-based projects on the same machine), with the free plugin
bind-mounted and active, plus WooCommerce and the official Plugin Check
plugin for verification.

```bash
composer install
composer run lint       # PHPCS (WordPress-Extra + PHPCompatibilityWP)
composer run lint:fix   # PHPCBF autofix
composer run test       # fast unit suite, no WordPress boot required
npm run env:stop
```

### Running tests

Four PHPUnit suites — free and Pro each have a fast unit suite plus an
integration suite that boots the real WP test suite:

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
- `plugins/contactlift-pro/` — Pro add-on (targeting, schedules, callbacks,
  reports, Freemius SDK adapter). Not yet scaffolded — lands Milestone 3.
- `tests/unit/` — fast domain-logic tests. `tests/integration/` — WP test
  suite. `tests/e2e/` — Playwright, against a running wp-env.
- `docs/` — architecture, decisions, progress, owner setup, release
  checklist, verification report.
- `scripts/` — build/verify/packaging automation.
