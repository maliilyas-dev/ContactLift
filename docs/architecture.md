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
- **`Frontend/`** *(Milestone 2)* — renders through `wp_footer` and the
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
options. Pro (Milestone 3+) adds its own `clift_pro_settings` option plus
three dedicated tables (`clift_callbacks`, `clift_daily_stats`,
`clift_rate_limits`) via idempotent `dbDelta` migrations, entirely
independent of the free plugin's uninstall path — see the spec's "Storage
and migrations" section for exact columns/indexes.

## Full details

This file tracks structure; the build specification is the source of truth
for exact schema fields, limits, REST contracts and acceptance criteria.
Implementer decisions not spelled out by the spec are in
[decisions.md](decisions.md).
