# Architecture

## Editions, one model

Free (`plugins/contactlift`) and Pro (`plugins/contactlift-pro`) share one
renderer and one configuration schema (`clift_settings` + `clift_bars`).
What differs between editions is enforced by `EditionLimits`, a small value
object passed into `Domain\Validation\BarValidator` — free's limits (1 bar,
5 excluded content IDs, no include list, no product targeting, no schedule,
no `callback` channel) are read directly off the spec's capability table.
Pro constructs a more permissive `EditionLimits` and, where the free
validator delegates (schedule structure, product-category targeting), plugs
in its own richer validation. Neither edition duplicates the other's
validation logic for the parts they share.

## Layers (free plugin)

- **`Domain/`** — pure PHP, unit-testable without booting WordPress.
  - `Channels/` — the allowlisted channel registry (`call`, `whatsapp`,
    `email`, `link`; Pro adds `callback`). Each channel validates its own
    fields and builds its own destination URL — nothing else in the
    codebase constructs a `tel:`/`wa.me`/`mailto:` string by hand.
  - `Tokens/TokenResolver` — plain-text `{token}` substitution. Resolution
    is always a single plain-text pass; whatever calls it encodes the
    result exactly once, afterwards, for its own destination scheme.
  - `Validation/` — `BarValidator`, `SettingsValidator`, `EditionLimits`.
    Throw `ValidationException` (a plain value object, not `WP_Error`) with
    a flat field→message map on failure.
- **`Storage/SettingsRepository`** — the only code that calls
  `get_option()`/`update_option()` for `clift_settings`/`clift_bars`.
  Validates before writing; a failed validation never touches the stored
  option. Non-autoloaded options throughout.
- **`Admin/`** — Settings API-style screens, capability-checked
  (`manage_options`), nonce-protected `admin-post.php` handlers. Validation
  errors + the submitted (invalid) values round-trip through a short-lived
  per-user transient so the form can redisplay them after the redirect.
- **`Frontend/`** — renders through `wp_footer` and the
  `[contactlift]` shortcode; one shared partial/template so both paths stay
  in sync, with dedupe so only one instance ever renders on a page.

## Extension contract

The free plugin fires `do_action( 'clift_loaded', CONTACTLIFT_API_VERSION )`
once it has finished booting. Pro checks that action fired and that the API
version is compatible before registering anything; if the free plugin is
missing or the version mismatches, Pro shows a plain dependency notice
(never a fatal, never an auto-install). If Pro is inactive or removed, the
free plugin keeps rendering the saved default bar on its own — Pro-only
tables/options are additive, never required for the free path.

## Data

`clift_settings` and `clift_bars` are free-plugin-owned, non-autoloaded
options. Pro adds its own `clift_pro_settings` option plus
three dedicated tables (`clift_callbacks`, `clift_daily_stats`,
`clift_rate_limits`) via idempotent `dbDelta` migrations, entirely
independent of the free plugin's uninstall path — see the spec's "Storage
and migrations" section for exact columns/indexes.

## Layers (Pro add-on)

Same shape as free, loaded only after free has fired `clift_loaded`.

- **`Domain/`** — pure PHP again. `Schedule/` (the injectable `Clock`,
  `ScheduleEvaluator`, `ScheduleValidator`, `ClosedActionResolver`),
  `Targeting/` (`BarTargetingResolver`, `BarMatcher`, `MatchContext`),
  `Callbacks/` (`CallbackValidator`, `ChallengeSigner`, `CallbackReadiness`),
  `Reports/AnalyticsGate`, `ImportExport/` (`ConfigDocument`,
  `ConfigImporter`), `Validation/ProSettingsValidator`, and the `callback`
  channel.
- **`Storage/`** — the only code that touches Pro's tables and options:
  `CallbackRepository`, `DailyStatsRepository`, `RateLimiter`,
  `ProSettingsRepository`, `Mailer`, `CleanupRunner` (daily retention),
  `ProInstaller` (dbDelta migrations) and `DataRemover` (see Lifecycle). Every
  query goes through `$wpdb->prepare()`; table names use the `%i` identifier
  placeholder rather than string interpolation.
- **`Rest/`** — `CallbackController` (`callback-challenge`, `callbacks`) and
  `RuntimeController` (`runtime`, `events`). Public by design; each route has a
  real permission callback, size caps and same-origin checks.
- **`Admin/`** — extensions that plug into free's own screens through
  `clift_admin_*` hooks (bar editor fields, settings cards, Tools cards), plus
  Pro's own `CallbackInboxPage` and `ReportsPage`. Every handler checks
  capability **before** the nonce.
- **`Frontend/`** — `Assets` (loads `callback.js` / `tracking.js` only when
  needed; `tracking.js` is not loaded for administrators), `CallbackDialog`,
  `ProductTokens`, `ContextValidator` (never trusts a client-supplied title or
  URL — only IDs, resolved server-side).
- **`Privacy/PersonalData`** — WordPress export/erasure by email.

## Lifecycle

Per the spec: deactivation stops output and unschedules cron but keeps every
record; nothing is deleted because a licence lapses or the base plugin is
switched off. Uninstall preserves everything unless the owner opts in.

- **Schema** is brought up to date on `init` (`Plugin::maybe_migrate()`), not
  only on activation, because updates don't fire activation hooks. Once the
  stored version matches, it is a cached option read.
- **`DataRemover`** is the one place that knows what "Pro's data" is. It backs
  both `uninstall.php` (drops the three tables, deletes options and per-user
  transients, clears the cron event — only when the opt-in flag is set) and the
  admin Data removal tool (deletes callback requests and/or statistics on
  demand, behind capability + nonce + an explicit confirmation).
- Free's `uninstall.php` never touches Pro's data; Pro's never touches free's
  bar. Both are asserted by tests.

## Licensing (Freemius)

`contactlift-pro.php` holds a guarded adapter. While the product ID and public
key are placeholders it does nothing (no SDK load, no network) and shows an
admin notice. With both set it expects `vendor/freemius/start.php`; if that is
missing it shows an error notice instead of silently running unlicensed. The
build script bundles only `vendor/freemius/` and refuses to package the
inconsistent states. See owner-setup.md for the activation steps.

## Testing and tooling

| Layer | Where | Runs with |
|---|---|---|
| PHP unit (no WordPress) | `tests/unit`, `tests/unit-pro` | `composer run test`, `test:pro` |
| Integration (real WP test suite, WooCommerce loaded) | `tests/integration`, `tests/integration-pro` | `npm run test:integration`, `test:integration:pro` |
| JS unit (real `tracking.js` in a sandbox) | `tests/js` | `npm run test:js` |
| Real concurrency (parallel processes) | `scripts/concurrency-*` | `npm run test:concurrency` |
| Packaging + official Plugin Check on the built zips | `scripts/build.sh`, `scripts/check-build.sh` | `npm run build`, `npm run check-build` |

Two things worth knowing before running these: the PHPUnit suite and the wp-env
site on port 8891 share one database and table prefix, so don't `DROP` tables
on that site; and Docker Desktop's VM can be small enough (about 2 GB) that
many full-WordPress processes at once will OOM the database — the concurrency
harness deliberately boots core only.

## Full details

This file tracks structure; the build specification is the source of truth
for exact schema fields, limits, REST contracts and acceptance criteria.
Implementer decisions not spelled out by the spec are in
[decisions.md](decisions.md).
