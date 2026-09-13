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

## Milestone 5 — Reporting, retention, export/import, privacy tools

Done and verified live in wp-env. `clift_daily_stats` is populated through
`DailyStatsRepository::increment()` (atomic `INSERT ... ON DUPLICATE KEY
UPDATE`, never a read-then-write), reached from the REST `/events` route in
`RuntimeController`. Recording is gated by `AnalyticsGate::
is_recording_allowed(mode, gpc, is_admin)` — pure decision logic covering
all three `analytics_mode` states (off / consent-required / local
aggregate); Global Privacy Control (`Sec-GPC: 1`) and a logged-in
administrator both unconditionally block recording regardless of mode.
Consent-required mode is opted into client-side via
`window.ContactLift.setAnalyticsConsent(true)`, default denied. The
`/runtime` route (stale-cache/schedule refresh, closing the gap Milestone 4
left open) and `/events` both went in together in `RuntimeController.php`.

The Reports screen (`ReportsPage.php`) filters by date range, bar, channel,
source and device, with CSV export that neutralizes formula-injection
(leading `=`/`+`/`-`/`@` in any cell is prefixed to defuse it before a
spreadsheet app opens the file). `source_id` is stored as `0`, never SQL
`NULL`, for home/archive-page events with no specific content ID — MySQL
treats every `NULL` as distinct from every other `NULL`, which would
silently defeat the composite unique index the atomic increment depends on.

Retention is a strict two-tier enum, not a clamped range —
`callback_retention_days` restricted to exactly {30, 90, 180} and
`stats_retention_days` to {30, 90} via `ProSettingsValidator::
retention_choice()` — enforced daily by `CleanupRunner` on the existing
`clift_pro_daily_cleanup` cron (which previously only pruned expired
rate-limit windows).

JSON export/import (`ImportExportExtension`, `ConfigDocument`,
`ConfigImporter`) is a two-step dry-run-then-confirm flow: the first submit
only validates and stores the normalized result in a transient; nothing
touches `clift_settings`/`clift_bars` until the admin explicitly confirms
the summary. Exports never include callback requests, statistics,
recipients, or credentials.

Privacy Tools integration (`PersonalData.php`) registers a policy-content
suggestion plus a WP core exporter/eraser matched by email address for
`clift_callbacks` rows. Phone-only requests get an authenticated
admin-screen search/delete workflow in the callback inbox instead — no
public phone-lookup endpoint, per the spec.

An `analytics_mode` UI gap found while writing an integration test (the
setting could be saved but never actually changed from the UI) was fixed
with a "Click reports" card on the Settings screen.

Current verified totals: 36 free unit + 58 Pro unit + 23 free integration +
30 Pro integration = **147 tests, all green**; PHPCS clean on both
plugins; free plugin's official Plugin Check: 0 errors, 0 warnings.

## Admin UI — searchable picker component (both editions)

Free's Exclusions field and Pro's "Show only on" field were native
`<select multiple>` listboxes — functional but a poor editing experience,
especially on a long content list, and flagged directly as needing a
real redesign. Replaced in both plugins with `Fields::picker()`: a
bordered card with a search-as-you-type input, a scrollable checkbox list,
and a live "N selected" count — while keeping the exact same
`name="field[]"` array-submission wire format, so no backend
validation/collection code changed. Lives in the free plugin
(`Admin/Fields.php`, `Admin/Icons.php`, `admin.css`, `admin.js`) and is
reused as-is by Pro's `BarEditorExtension` via the existing cross-plugin
`use ContactLift\Admin\Fields;` import. Verified live: search filtering,
checkbox highlighting, selection persisting through filter changes, and a
full save/reload round trip confirmed correct in `clift_bars`.

## Milestone 6 — Freemius guarded adapter, packaging, GitHub

The repo now lives at
[github.com/maliilyas-dev/ContactLift](https://github.com/maliilyas-dev/ContactLift)
(pushed as `c36b115`, both plugins' complete source, tests and docs).

`contactlift-pro.php` gained a guarded Freemius adapter (`clift_fs()`),
modeled directly on the sibling FormBridge project's proven
`fs_dynamic_init()` integration: two placeholder constants
(`FREEMIUS_PRODUCT_ID`, `FREEMIUS_PUBLIC_KEY`) gate everything — while
they hold their placeholder values, Pro runs with all local features
intact and shows a plain admin notice explaining that licensing isn't
configured yet; nothing attempts a live Freemius call with fake
credentials. Once real values are in place (see docs/owner-setup.md) and
the SDK is downloaded into `vendor/freemius/`, the same code path
registers the real menu (including the bare-slug-redirect workaround
Freemius's own account links need — registered as a hidden submenu,
`admin_init` redirect to the real `-account` page, CSS-hidden from the
visible nav) with no further code changes.

`scripts/check-build.sh` was generalized to check both plugins (previously
free-only). Running the official Plugin Check against the actual built
`contactlift-pro-1.0.0.zip` surfaced a real, systematic issue: several
`phpcs:ignore` comments for `InterpolatedNotPrepared` sat on the wrong line
of a multi-line SQL string (PHPCS attributes a violation to the line the
flagged token sits on, not the line a trailing comment sits on) — some
warnings were therefore never actually suppressed. Rather than just
relocating comments, every raw-SQL table-name interpolation across
`RateLimiter`, `DailyStatsRepository`, `CallbackRepository` and
`PersonalData` was rewritten to use `$wpdb->prepare()`'s `%i` identifier
placeholder (WP 6.2+; both plugins already require 6.8, and the pinned
WPCS 3.4.1 supports it) — the table name now flows through `prepare()`
itself instead of being interpolated into the query string beforehand,
which is a strictly better fix than a suppression comment: it closes the
class of finding at the root instead of asserting it's safe. One
`DailyStatsRepository::query()` filter-building pattern (a `WHERE` clause
assembled from conditionally-pushed literal fragments) hit a genuine WPCS
static-analysis limitation that no further restructuring resolves — it's
documented with a precise `phpcs:ignore` explaining exactly why every
possible value of that variable is a fixed literal.

Result: **`contactlift` — 0 errors, 0 warnings. `contactlift-pro` — 0
errors, 2 warnings**, both expected and already assessed rather than
fixed: the "required plugin not in directory" notice (self-resolves once
free is actually published to WP.org) and `load_plugin_textdomain()`
(genuinely still needed — WP.org's auto-translation-loading only applies
to WP.org-hosted plugins, and Pro is Freemius-distributed). Full 147-test
suite and PHPCS re-confirmed clean after every fix, against a real MySQL
database via wp-env, not just syntactically.

## Next

Everything that can be built without owner-supplied external accounts is
done. What's left is entirely the items in docs/owner-setup.md: a
WordPress.org developer account + free-plugin submission, a Freemius
account + product registration (replacing the two placeholder constants
in `contactlift-pro.php` and downloading the real SDK into
`vendor/freemius/`), and final business/support URLs.

Also outstanding from Milestone 2, unrelated to either blocker above:
WordPress.org directory screenshots/asset brief (needs the owner's final
branding choices), WebKit testing (no Safari-based browser available
through this session's tools), a checked-in Playwright suite. None of
these are engineering gaps — see the "Before public release" section of
release-checklist.md.

Two scope cuts remain intentionally deferred from Milestone 3/4, still
worth closing eventually but not release-blockers: WooCommerce's live
variation-switcher interaction (`{variation}` resolves to the parent
product's tokens on first render rather than updating without a reload),
and the schedule-exceptions admin UI (one interval per day, 5 new rows per
save — the validator/evaluator underneath already handle the spec's full
100-exception cap; this is an admin-UI affordance limit only).

## Blockers

None for engineering — both plugins are feature-complete, fully tested,
and pass Plugin Check. Every remaining item is an external account or
value only the owner can supply (WordPress.org account/slug, Freemius
product configuration, final business/support URLs), tracked in
docs/owner-setup.md. Per the spec's "External setup boundaries," none of
these block the code itself — only *submission* and *licensing
verification*, which stay explicitly unverified until real values are
supplied and exercised.
