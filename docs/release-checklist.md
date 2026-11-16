# Release checklist — ContactLift (free) and ContactLift Pro

Status as of 2026-11-16. "Done" means actually run and verified — see
[verification-report.md](verification-report.md) for the evidence behind each
line. Nothing here is checked off on the strength of the code simply existing.

## Build quality — both plugins

- [x] PHPCS clean (WordPress-Extra + PHPCompatibilityWP) across both plugins
      and `tests/`
- [x] PHP unit suites green — 94 tests (36 free, 58 Pro), no WordPress boot
- [x] Integration suites green against the real WP test suite — 128 tests
      (24 free, 104 Pro). WooCommerce is loaded in both, so WooCommerce paths
      run for real rather than skipping.
- [x] JS unit tests green — 13 tests running the unmodified `tracking.js`
      (`npm run test:js`)
- [x] Real-concurrency check green — 8 parallel PHP processes, separate DB
      connections (`npm run test:concurrency`)
- [x] All 20 acceptance tests AT01–AT20 covered — matrix in
      verification-report.md
- [x] Official Plugin Check, run against the **built zips**
      (`npm run check-build`): free **0 errors, 0 warnings**; Pro **0 errors,
      2 warnings** — both understood and intentionally left (see below)
- [x] `scripts/build.sh` produces both zips with SHA-256 checksums
- [x] Build guard: a Pro zip with placeholder Freemius credentials is built
      with a loud warning, and `CLIFT_RELEASE=1 npm run build` refuses it; a
      zip with real credentials but no SDK is refused; with the SDK present,
      only `vendor/freemius/` is shipped. Each path run against a scratch copy.
- [x] Tests were checked to be able to fail: the variation-mismatch, capability
      gates (screen and handler), uninstall opt-in, consent revocation,
      plain-permalink URL and the atomic counter were each broken on purpose
      and the relevant test caught it

### The two Pro Plugin Check warnings (expected, not defects)

1. `plugin_header_requires_plugins_not_in_directory` — Pro declares the free
   plugin as required; WordPress.org's checker can't find it *yet*. Goes away
   once the free plugin is published. Pro itself is not hosted there.
2. `load_plugin_textdomain()` discouraged — true only for plugins hosted on
   WordPress.org. Pro is distributed through Freemius, where translations are
   not auto-loaded, so the call is still required.

## Free plugin — product verification

- [x] All 4 channels (call/WhatsApp/email/link): validation + URL building
      unit-tested; real links browser-verified
- [x] Bar editor: live preview, action repeater, colour pickers, device
      targeting, searchable Exclusions picker (replaced a native multi-select)
- [x] `[contactlift]` shortcode dedupe — single and repeated
- [x] Global exclusions (admin, feed, 404, password-protected, WooCommerce
      cart) — integration-tested
- [x] AT16 capability gates on every free screen and save handler
- [x] Activation defaults, non-autoloaded storage, repeated activation
- [x] Uninstall preserve-by-default / delete-when-opted-in, and free's uninstall
      never touches Pro's data
- [x] Performance budget: 2,575 bytes gzip combined CSS+JS (budget: 35,000)
- [x] Accessibility spot checks (ARIA, focus, tab order, Escape, 44px targets,
      RTL) — see verification-report.md for what was and wasn't demonstrable
- [x] Block theme, classic theme, Gutenberg, Elementor, 360px/768px widths

## Pro — product verification

- [x] Targeting (page/post/product/category include + exclude, priority,
      lowest-ID tie-break) — unit + live-hooks integration
- [x] Business hours: boundaries, overnight, date exceptions, DST — unit
- [x] Closed-action behaviour (keep / hide / replace) — unit + integration
- [x] Callback requests end to end: challenge → submit → store → notify;
      idempotent retries; conflict; mail failure keeps the request; database
      failure is never reported as success; rate limit with recovery
- [x] Variation / private-post context validation, with real WooCommerce
- [x] Click reports: server gating (off / consent-required / local aggregate,
      GPC, administrators) and **the browser client in a real browser** —
      default-denied consent, revocation, GPC, plain and pretty permalinks,
      home/archive pages
- [x] JSON export/import: dry-run then confirm, malformed / newer-schema /
      over-limit rejected without partial changes
- [x] WordPress privacy export and erasure: own records only, exact email
      match, case-insensitive
- [x] Data lifecycle: nothing is deleted on deactivation or licence lapse;
      uninstall deletes only when the owner opted in, and never the free bar;
      an admin Data removal tool; verified against real tables
- [x] AT16 capability gates on all 13 privileged Pro handlers and both Pro
      screens, for logged-out / subscriber / editor, with valid nonces
- [x] Schema migration runs on load (not only on activation) and costs no
      query once current

## Deliverables present

- [x] `readme.txt` — free and Pro
- [x] `LICENSE.txt` (GPLv2) — free and Pro
- [x] `uninstall.php` — free and Pro
- [x] `languages/contactlift.pot` (147 strings), `languages/contactlift-pro.pot`
      (199 strings), both shipped inside their zips
- [x] `docs/`: architecture, decisions, progress, owner-setup,
      verification-report, this file
- [x] Source on GitHub: github.com/maliilyas-dev/ContactLift
- [ ] WordPress.org screenshots/banner/icon (mobile bar, desktop launcher,
      bar editor, Bars screen) — not yet captured as final, labelled assets
- [ ] WordPress.org directory-asset brief (icon/banner/screenshot captions)
- [ ] Both zips installed and exercised by a human on a real WordPress site
      outside this Docker setup

## Known limitations shipped in v1.0 (decided, not oversights)

- WooCommerce **live variation switching**: `{variation}` and its link resolve
  from the parent product on first render and don't update when a shopper picks
  a variation without reloading. The server already validates variation IDs
  (AT11); only the front-end interaction is missing.
- Schedule **exceptions UI**: one interval per exception day, 5 new rows per
  save. The validator and evaluator already handle the spec's full 100-exception
  case; this is an admin-form affordance limit only.
- No browser/e2e suite is checked in. Browser behaviour is covered by the JS
  unit tests plus the manual real-browser checks recorded in the verification
  report.

## Not verified (and why)

- WebKit/Safari — only a Chromium browser was available
- Screen-reader software (VoiceOver/NVDA) — not available
- The Pro callback dialog's keyboard/screen-reader behaviour and the Reports,
  Callback inbox, Tools and Settings screens as *rendered pages* — covered by
  integration tests that render them, but no browser pass is recorded here
  (the in-app browser was not logged in to wp-admin)
- A successful outbound email — wp-env has no mail transport; only the
  failure path is tested
- The 256 KB upload cap in the import handler (needs `$_FILES`)
- Real Freemius licensing, update delivery and checkout — needs your account

## Before public release (owner-only — see owner-setup.md)

- [ ] WordPress.org account created, slug availability confirmed, free plugin
      submitted
- [ ] Freemius product created; real product ID and public key put into
      `contactlift-pro.php`; SDK downloaded into
      `plugins/contactlift-pro/vendor/freemius/`; licensing/upgrade/update flows
      tested with Freemius test credentials
- [ ] `CLIFT_RELEASE=1 npm run build` succeeds, then `npm run check-build` is
      re-run on that zip (Plugin Check will now scan the bundled SDK — expect
      to review its findings)
- [ ] Final author/support/product URLs (currently placeholders)
- [ ] You review screenshots, readme copy and this report before submitting

## Bottom line

Both plugins are feature-complete for v1.0 and verified as far as this
environment allows. What remains is the owner-only external setup (accounts,
Freemius credentials, final URLs), the WordPress.org presentation assets, and a
human smoke test on a real site — plus the unverified items listed above.
