# Progress

## Done

**Milestone 1 — Foundation.** Verified, not just written:

- Repo scaffolded per the spec's layout (`plugins/contactlift`,
  `plugins/contactlift-pro` reserved, `tests/`, `docs/`, `scripts/`,
  `dist/`). Git initialized.
- Tooling: root `composer.json` (dev-only: PHPCS WordPress-Extra +
  PHPCompatibilityWP, PHPUnit 9.6 + yoast/phpunit-polyfills), `.wp-env.json`
  (ports 8890/8891 to coexist with the sibling FormBridge project's own
  wp-env), `package.json` scripts, `phpcs.xml.dist`, two PHPUnit configs
  (unit / integration).
- Free plugin bootstrap (`contactlift.php`): headers, constants, hand-rolled
  autoloader, PHP/WP version gate with an admin notice (not a fatal), a
  network-wide-activation rejection, `clift_loaded` fired on boot.
- Domain layer, unit-tested without booting WordPress: `ChannelRegistry`
  with `call`/`whatsapp`/`email`/`link` channels (each validates its own
  fields and builds its own URL — `tel:`, `wa.me`, `mailto:`, `https://`
  only), `TokenResolver` (plain-text substitution, missing tokens dropped
  cleanly, no stray braces/punctuation), `BarValidator` + `SettingsValidator`
  + `EditionLimits` implementing the spec's full schema/limits.
- Storage layer: `SettingsRepository` — non-autoloaded `clift_settings` /
  `clift_bars` options, install() that never overwrites existing data,
  atomic validated saves (a failed save leaves the previous config live),
  revision bumping.
- Admin: one top-level "ContactLift" menu, a working Settings screen
  (business name, enable flag, delete-on-uninstall flag) wired through
  `admin-post.php` with a nonce, capability check, and inline validation
  errors that preserve the submitted values.
- `uninstall.php` — deletes only the free plugin's own options, and only
  when `delete_data_on_uninstall` is set; never touches anything Pro owns.

**Verification actually run** (see docs/verification-report.md for the
running log this will roll into at Milestone 6):
- `composer run test` — 36 unit tests, 43 assertions, all green.
- `composer run lint` — PHPCS clean on the free plugin.
- `npm run env:start` — real wp-env instance (WP 6.8-line core image, PHP
  8.2), plugin activates cleanly, no entries in `wp-content/debug.log`
  attributable to ContactLift.
- Logged into the real wp-admin (browser-driven): Settings screen renders
  under one "ContactLift" top-level menu; saved a business name — persisted
  to `clift_settings`, `revision` incremented 1→2; submitted a 151-character
  name — rejected with an inline "too long" error, submitted value
  redisplayed, and the database confirmed unchanged (still the prior valid
  config at revision 2), proving the atomic-save requirement rather than
  just asserting it in a unit test.

**Milestone 2 — Free product.** Core functionality done and browser-verified:
bar editor with a live preview panel (reuses the real frontend CSS), all
four channels, mobile bottom bar + desktop launcher with the funky default
theme, `[contactlift]` shortcode with dedupe (single and repeated,
verified), sitewide exclusion targeting UI, admin design system (topbar,
tabs, cards, a shared toggle component). Several real UI bugs found via
manual testing and fixed: icon sizing, native-checkbox tick bleeding
through the custom toggle, action-row alignment, mobile horizontal
overflow in the live preview.

**Official Plugin Check: 0 errors, 0 warnings** (all categories, Error +
Warning), after fixing what it found on the first run (12 errors, 6
warnings) — ABSPATH guards on every file, exception-message escaping,
`readme.txt` created, `$_POST` sanitization, `load_plugin_textdomain()`
removed, two VIP false-positives annotated. Details in decisions.md and
verification-report.md.

**Free-plugin release-readiness push — done:**

- **Integration test suite** built from scratch (`tests/integration/`,
  real WP test suite via wp-env — the fast unit suite deliberately never
  boots WordPress, so this is genuinely new coverage): 23 tests, 43
  assertions. Covers AT16 (capability gates — logged-out/subscriber/editor
  denied everywhere, even with a stolen valid nonce), activation defaults +
  repeated-activation, all of `GlobalExclusions` against real WP query
  context including WooCommerce's cart page, full frontend rendering
  end-to-end (real `tel:` output, shortcode/footer dedupe, token
  resolution), and `uninstall.php`'s preserve/delete behavior.
- **Performance budget measured**: 2,575 bytes gzip combined CSS+JS
  against the spec's 35KB target — about 7%.
- **Accessibility verified** with a standalone harness running the real,
  unmodified frontend assets over an actual HTTP server (not a static
  `file://` snapshot, which doesn't execute JS): ARIA structure, visible
  keyboard focus, tab order, Escape-closes-and-returns-focus, 44px touch
  targets, RTL mirroring. One honestly-flagged gap: native button
  Enter/Space activation couldn't be demonstrated through this session's
  browser-automation tool specifically (confirmed it's a tool limitation,
  not a code defect, by showing even a bare vanilla `<button>` doesn't
  respond to its synthetic keypresses either) — the markup itself is a
  correct native `<button>`, which the HTML spec guarantees is
  keyboard-operable independent of any JS.
- **Packaging built and run**: `scripts/build.sh` + `scripts/check-build.sh`
  (mirroring the sibling FormBridge project's pattern). Produces
  `dist/contactlift-1.0.0.zip` with a SHA-256 checksum. Plugin Check passes
  clean **against the actual built zip**, not just the dev tree. Clean
  activation verified in an environment that never had the dev copy
  bind-mounted, including a real frontend render from the packaged files.
- **Theme/editor/device compatibility**: default block theme, a classic
  theme (Twenty Twenty-One), a real Elementor-built page, 360px and the
  768px mobile/desktop boundary.
- **Deliverables added**: `LICENSE.txt` (GPLv2), `languages/contactlift.pot`
  (132 strings), `docs/release-checklist.md`.

See docs/release-checklist.md for the full done/not-done picture and
docs/verification-report.md for exactly how each item was tested.

## Milestone 3 — Pro dependency layer, targeting and schedules

Done and verified live in wp-env (see docs/decisions.md for the design
reasoning): `plugins/contactlift-pro/` scaffolded with its own autoloader,
bootstrap (`Requires Plugins: contactlift`, activation/deactivation guards,
`clift_loaded` version check), and `Plugin.php` service wiring. Domain
engines — `ScheduleEvaluator`/`ScheduleValidator`/`Clock`/`Minutes`
(AT06-AT09, including a real DST fall-back case) and
`BarTargetingResolver`/`BarMatcher`/`MatchContext` (AT03-AT05) — are pure,
injectable-clock, unit-tested (34 Pro unit tests, 28 domain + 6 for the new
`ClosedActionResolver`). `ProInstaller` creates `clift_callbacks`/
`clift_daily_stats`/`clift_rate_limits` via dbDelta plus `clift_pro_settings`
and the daily cleanup cron, confirmed against a real database.

Wired into the actually-running plugin, not just tested in isolation:
content-ID include/exclude targeting, priority tie-break, per-device
resolution (mobile/desktop resolved independently), and the schedule +
closed-action (keep/hide/replace) engine all confirmed live — two real
pages resolving to two different bars, an always-closed schedule
correctly suppressing a bar entirely. Admin UI for it reuses free's own
Bars/bar-editor screens (extra rows, Include-targeting card, Business-hours
card, priority field, per-action "while closed" + replacement fields) via
new extension points rather than a forked screen. `tests/integration-pro/`
(6 tests) locks this in against real WP hooks with both plugins loaded —
`npm run test:integration:pro`.

Not done yet: product/category targeting has no admin UI or WooCommerce
category data source (engine-ready, spec'd for Milestone 4); the spec's
runtime REST refresh endpoint (polling/backoff for stale-cache recovery);
`BarValidator::validate_replacement()`'s replacement channel is currently
limited to a single generic destination field in the admin UI (no
per-channel-type conditional fields the way the primary action has);
exceptions in the schedule UI are a fixed "5 new rows per save" affordance
rather than a dynamic repeater (the engine itself supports the spec's full
100-exception cap regardless of the UI). None of these block Milestone 4.

## Milestone 4 — Product tokens and the callback request system

Done and verified live in wp-env (see docs/decisions.md for the two real
bugs this surfaced and how they were fixed — both worth reading before
touching `ChannelRegistry` or `frontend.js` again). `CallbackChannel` is
now registered via a new `clift_register_channels` filter; product tokens
(`{product_name}/{product_sku}/{product_url}/{variation}`) merge in via a
new `clift_token_values` filter; product-category targeting's data source
(`Plugin::product_cat_ids()`) is real, closing the gap Milestone 3 left
open.

The full callback pipeline is built and tested end to end: a signed,
stateless challenge token (`ChallengeSigner`), field validation
(`CallbackValidator`), rate limiting (`RateLimiter`, both per-visitor and
sitewide, admin-configurable), idempotent persistence with payload-hash
conflict detection (`CallbackRepository`, AT12/AT13), atomic
notification-claim + `wp_mail()` (`Mailer`, AT14), and REST routes
(`POST /callback-challenge`, `POST /callbacks`) with origin checks, a
honeypot, and body-size/content-type limits. Frontend: an accessible modal
dialog (focus trap, Escape-closes-and-restores-focus, "Request received"
confirmation, loaded only when a callback action is actually present).
Admin: a callback-settings card on the Settings screen (recipients,
privacy URL, consent copy, retention, rate-limit thresholds) and a
Callback requests inbox (list/filter, detail view, status change, private
notes, notification retry, delete) — both new screens, no forked
free-plugin UI needed here since neither reuses an existing free screen's
form.

`tests/integration-pro/CallbackRestTest.php` (11 tests, AT12-AT16 directly
against the real REST server) plus two regression tests for the bugs
above bring the Pro integration suite to 18 tests; unit suites: 36 free +
52 Pro (28 domain + 6 closed-action + 18 callback/challenge), all green,
PHPCS clean throughout.

Not done, deferred honestly rather than faked: WooCommerce's live
variation-switcher interaction (binding to WC's own variation-form JS so
`{variation}` and the link update without a reload — currently resolves to
the parent product's tokens only, which is itself the spec-correct default
state); the exceptions UI is one interval per day / 5 new rows per save
rather than a full dynamic list up to the spec's 100-cap (the validator and
evaluator underneath already handle the full case; this is an admin-UI
scope cut only); the `/runtime` and `/events` REST routes (stale-cache
refresh and click-report events — `/events` belongs to Milestone 5's
reporting work anyway).

## Next

Milestone 5 — reporting (`clift_daily_stats`, the `/events` route,
consent modes and Global Privacy Control, the Reports screen), retention
and scheduled cleanup (the `clift_pro_daily_cleanup` cron currently only
prunes expired rate-limit windows — callback/stats retention per the
settings already saved needs to hook in), JSON export/import, and the
privacy export/erasure integrations.

Also outstanding from Milestone 2, unrelated to Pro: WordPress.org
directory screenshots/asset brief (needs the owner's final branding
choices), WebKit testing (no Safari-based browser available through this
session's tools), a checked-in Playwright suite. None of these are
engineering gaps — see the "Before public release" section of
release-checklist.md.

## Blockers

None for the free plugin's engineering. Per the spec's "External setup
boundaries" and "Launch handoff," a WordPress.org account/slug and final
business/support URLs are owner-only values (tracked in
docs/owner-setup.md) needed before *submission*, not before the plugin
itself is done. Freemius/Pro items don't block the free plugin at all.
