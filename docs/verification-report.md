# Verification report

Running log of what was actually tested and the results — updated at each
milestone gate. Nothing here is claimed unless it ran; a milestone that
hasn't reached its gate yet simply has no entry.

## Environment

Current (the 2026-11-16 round, last section below):

- WordPress 7.1.4, PHP 8.2.34, MariaDB 12.3.3 (wp-env, `core: null` = current
  stable)
- WooCommerce 11.3.0, Elementor 4.4.0, official Plugin Check 2.2.0
- Node 24.9.0 (`node:test`) for the JS tests
- macOS host, Docker Desktop (VM: about 1.9 GB RAM, 2 CPUs), `@wordpress/env`
  ^10.0.0
- Browser checks: the desktop app's built-in Chromium pane

The Milestone 1 and 2 entries below were recorded on the versions then current
(WordPress 7.1, PHP 8.2.33) and are left as they were.

## Milestone 1 — Foundation

Gate: "Free plugin activates cleanly and saves validated settings."

| Check | Command / method | Result |
|---|---|---|
| Unit suite | `composer run test` | 36 tests, 43 assertions, 0 failures |
| Coding standards | `composer run lint` (PHPCS, WordPress-Extra + PHPCompatibilityWP) | 0 errors, 0 warnings |
| Plugin activates | `npm run env:start`, `wp plugin list` | `contactlift` → `active`, no fatal |
| No PHP errors on activation | `wp-content/debug.log` after activation | No ContactLift-related entries (one pre-existing WooCommerce notice unrelated to this plugin) |
| Defaults created on activation | `wp option get clift_settings` / `clift_bars` | Matches the spec's schema shape; default bar disabled, empty actions (no placeholder destination shipped) |
| Admin menu + settings screen | Logged into wp-admin via browser automation | One top-level "ContactLift" menu; Settings screen renders |
| Save persists | Entered a business name, saved | `clift_settings.business_name` updated, `revision` 1→2 |
| Validation rejects invalid input | Submitted a 151-character business name (bypassing the HTML `maxlength` via devtools, to actually exercise server-side validation) | Inline error "The business name is too long." shown; submitted value redisplayed |
| Atomic save (failure doesn't clobber prior state) | Re-checked `clift_settings` after the rejected submission above | Unchanged — still the prior valid value at revision 2, not overwritten and not bumped |

Not yet run (out of scope for this milestone's gate): frontend rendering,
Plugin Check, accessibility checks, browser-version matrix, packaging. These
land at their respective milestones below.

## Milestone 2 — Free product

Not formally gated yet (full accessibility matrix, RTL, 200% zoom and the
performance budget measurement are still outstanding — tracked for
Milestone 6), but the following has actually been run and verified:

| Check | Command / method | Result |
|---|---|---|
| Unit suite | `composer run test` | 36 tests, 43 assertions, 0 failures |
| Coding standards | `composer run lint` | 0 errors, 0 warnings |
| Frontend renders | Browser-driven: saved a Call action, visited the real site | Mobile bottom bar and desktop launcher both render with the funky default theme; `tel:` link correct |
| Shortcode dedupe | `[contactlift]` once, then twice, on a test page | Exactly one `.clift` root each time; floating auto-output correctly suppressed when the shortcode claims the page |
| Sitewide exclusion targeting | Excluded a post ID via the bar's exclude list, visited that post | (Re-verify after the concurrent edits during manual testing settled — logic confirmed by code review of `BarResolver::is_content_excluded()`, not re-run live after the last data reset) |
| No PHP errors/warnings | `wp-content/debug.log`, admin + frontend | Clean |
| **Official Plugin Check** | `tools.php?page=plugin-check`, all categories (General/Plugin Repo/Security/Performance/Accessibility), Error+Warning | First run: **12 errors, 6 warnings**. Fixed (ABSPATH guards on all 36 PHP files, exception-message escaping, `load_plugin_textdomain()` removed, `$_POST` sanitization on `business_name`/`bottom_offset`, false-positive VIP "exclude" warnings annotated, `readme.txt` created). Re-run: **0 errors, 0 warnings.** |

Fixing the ABSPATH guards briefly broke the unit suite (every `Domain`/
`Support` class started calling `exit;` the moment the autoloader touched it
outside WordPress) — caught immediately because `composer run test` went
silent instead of printing results; fixed by defining a dummy `ABSPATH` in
`tests/unit/bootstrap.php`. Recorded in decisions.md.

**Integration suite** (`npm run test:integration`, real WP test suite via
wp-env, not the WP-independent unit suite): 23 tests, 43 assertions, all
green. Covers AT16 (unauthenticated/subscriber/editor all denied on every
admin screen and both save handlers, even with a stolen valid nonce, since
capability is checked before the nonce; administrator succeeds), activation
defaults + non-autoloaded storage + repeated-activation doesn't overwrite,
all of GlobalExclusions against real WP query context (admin, feed, 404,
password-protected with/without the correct cookie, WooCommerce cart),
frontend rendering end-to-end (real `tel:` output, disabled-bar renders
nothing, shortcode+footer dedupe against each other, `{page_title}` token
resolution), and uninstall.php's preserve-by-default / delete-when-opted-in
behavior. PHPCS clean on `tests/` too (added to phpcs.xml.dist to match
FormBridge's own full-repo linting convention).

One infrastructure note: the wp-env `tests-cli` container's global `phpunit`
resolves to 10.5.64, which the bundled WP core test suite isn't compatible
with (`parseTestMethodAnnotations` doesn't exist in PHPUnit 10) — the
integration test command must invoke this project's own
`vendor/bin/phpunit` (9.6.36) explicitly, not the bare `phpunit` on PATH.
`package.json`'s `test:integration` script does this.

**Performance budget** (spec target: <35KB gzip combined for the free bar):
`gzip -c frontend.css frontend.js | wc -c` → **2,575 bytes combined** (2,067
CSS + 629 JS, concatenated then compressed). About 7% of budget.

**Accessibility** — verified with a standalone harness serving the real,
unmodified `frontend.css`/`frontend.js` over a local HTTP server (so the
JS genuinely executes, unlike a bare `file://` static snapshot), driven by
real keyboard/DOM inspection rather than assertion-by-reading-the-code:

| Check | Method | Result |
|---|---|---|
| ARIA structure | Rendered-markup inspection | `aria-expanded`/`aria-controls`/`aria-label` correct on the launcher; icons `aria-hidden="true" focusable="false"`; accessible name comes from real visible text, not an icon-only label; `<nav aria-label="Contact options">` landmark |
| Visible keyboard focus | Real Tab key, screenshot | Clear focus ring in the bar's accent colour on both a mobile-bar link and (separately) the desktop launcher |
| Tab order | Real Tab key + `document.activeElement` | Hidden panel links correctly excluded from tab order while closed; launcher reachable in natural DOM order |
| Escape closes + returns focus | Open via click, real Escape key, `document.activeElement` check | Panel hides, `aria-expanded` flips to `false`, focus lands back on the launcher — not lost to `<body>` |
| Touch targets ≥44px | `getBoundingClientRect()` | Launcher 58×58; open panel action rows 189×44.16. Mobile-bar rows share the same base `min-height: 44px` rule (verified in source; not independently re-measured live — see below) |
| RTL | `dir="rtl"` on `<html>`, computed style | `.clift-desktop--right` correctly flips from `right` to `left: 16px` |
| Reduced motion | Source review | `@media (prefers-reduced-motion: reduce)` zeroes animation/transition on launcher, panel and actions — this browser pane has no control to force the OS-level preference, so it's verified by reading the rule, not by observing it fire |
| Enter/Space activates the launcher | Attempted via synthetic keypress | **Inconclusive by this method** — a plain, unmodified vanilla `<button>` with no ContactLift code at all *also* didn't activate on this tool's synthetic Enter/Space, confirming it's a limitation of the automation tool's synthetic key dispatch, not a defect in this markup. The launcher is a genuine `<button type="button">` with no `preventDefault` on keydown, which the HTML spec guarantees is keyboard-activatable — not independently reproduced with real hardware/a real screen reader |
| Mobile-bar touch target, live viewport measurement | `resize_window` to 390×844 | Viewport emulation for this tab didn't take effect this pass (`window.innerWidth` stayed at desktop width) — not re-verified live this session; relying on the shared `min-height: 44px` CSS rule instead |
| Screen reader smoke test (VoiceOver/NVDA) | — | **Not run** — no screen-reader software available through these tools |
| 200% browser zoom | — | **Not run directly** — approximated by the narrow-viewport overflow testing done earlier (no horizontal scroll at 375px), which is the practical effect of high zoom on a fixed-position overlay this small, but not the same test |

**Packaging** — `scripts/build.sh` and `scripts/check-build.sh` written
(mirroring the sibling FormBridge project's `bin/build.sh` /
`bin/check-build.sh` pattern) and actually run, not just written:

| Check | Method | Result |
|---|---|---|
| Build produces a clean zip | `bash scripts/build.sh` | `dist/contactlift-1.0.0.zip`, 59 files, one top-level `contactlift/` folder at the zip root; SHA-256 checksum written alongside it |
| No dev tooling leaked in | `unzip -l` inspection | Confirmed — no `.git`, `tests/`, `.wp-env.json`, `composer.*`, `node_modules`; `readme.txt` and the new `LICENSE.txt` (full GPLv2 text) present |
| Plugin Check against the real zip | `bash scripts/check-build.sh` — extracts the actual built zip into a throwaway-named, never-activated copy inside the wp-env container (never `wp plugin install --force`, per the standing packaging hazard) and runs `wp plugin check` | **"Success: Checks complete. No errors found."** — against the packaged artifact itself, not just the dev source tree the browser-based check used earlier |
| Clean-install activation | Extracted the same zip under a throwaway name in the separate `tests-wordpress` container (never bind-mounted the dev copy) via plain `unzip` + `wp plugin activate` | Activated cleanly; `clift_settings`/`clift_bars` created with correct defaults; empty `debug.log` |
| Frontend renders from the packaged copy | `wp eval` against that same throwaway install: saved a real call action, rendered via the actual `Renderer` class loaded from the zip | Correct `tel:+15551234567` markup produced — confirms the packaged file paths/autoloader work end-to-end, not just at the class level |
| Uninstall preserves-by-default | Attempted via `wp plugin delete` on the throwaway copy | **Not actually verified this way** — `wp plugin delete` removes files but does not invoke `uninstall.php` (only `wp plugin uninstall` does); the options simply being present afterward doesn't prove anything. This behavior *is* properly verified — directly, by including `uninstall.php` with `WP_UNINSTALL_PLUGIN` defined, exactly as WordPress itself does — by the integration suite's `UninstallTest.php` (both the preserve-by-default and delete-when-opted-in paths, passing) |

(When this entry was written Pro didn't exist yet, so only the free plugin
was built. Both plugins are built and checked now — see the last section.)

**Theme, editor and device-width checks**:

| Check | Method | Result |
|---|---|---|
| Default block theme | Twenty Twenty-Five (already active) | Bar renders correctly (seen throughout this session) |
| Classic (non-block) theme | Switched to Twenty Twenty-One, reloaded the site | Bar renders correctly, no layout conflict with the classic theme's own footer |
| Gutenberg page | `[contactlift]` in ordinary post/page content throughout this session | Renders correctly, dedupe verified |
| Elementor page | Created a page with real `_elementor_data`/`_elementor_edit_mode` postmeta (a shortcode widget wrapping `[contactlift]`), rendered through Elementor's own frontend | Renders correctly inline, no console errors, no visual conflict |
| 360px width | `resize_window` to 360×740 | Clean, no overflow |
| 768px boundary | `resize_window` to 768×1024 | Correctly shows the **desktop** launcher, not the mobile bar — matches the spec's own "desktop is 768 or above" definition exactly |
| 390px, desktop-wide | Covered earlier in this session (accessibility harness, admin preview, live site) | Clean |
| 1024px | — | **Not explicitly captured as its own screenshot** — covered in spirit by the 768px and desktop-wide checks either side of it, but not run as its own discrete step |
| WooCommerce theme compat | Active + cart/checkout/account exclusion logic integration-tested | Covered above |

Test page, plugin config changes (theme switches, Elementor test data) were
all cleaned up / reverted after each check — the live site was left as
found (Twenty Twenty-Five, no test pages left behind).

Not yet run: WebKit/Safari (this session only has one Chromium-based
pane), 1024px as its own discrete screenshot, a full automated Playwright
suite (spec's "automate Chromium flows" — done manually/via `javascript_tool`
this session, not as a checked-in, re-runnable test suite).

## Pro add-on, hardening and release prep — 2026-11-16 round

Everything below was run, not read. Where a check could not be done it says so.

### Automated results (final code)

| Suite | Command | Result |
|---|---|---|
| Coding standards | `composer run lint` | 0 errors, 0 warnings (plugins + tests) |
| PHP unit — free | `composer run test` | 36 tests, 43 assertions |
| PHP unit — Pro | `composer run test:pro` | 58 tests, 85 assertions |
| Integration — free | `npm run test:integration` | 24 tests, 45 assertions |
| Integration — Pro | `npm run test:integration:pro` | 104 tests, 223 assertions |
| JS — `tracking.js` | `npm run test:js` | 13 tests |
| Concurrency | `npm run test:concurrency` | 10 checks, passed |
| **Total automated tests** | | **235** (+ the concurrency harness) |
| Plugin Check, free zip | `npm run check-build` | "Success: Checks complete. No errors found." — 0 warnings |
| Plugin Check, Pro zip | `npm run check-build` | 0 errors, 2 warnings (both expected — see release-checklist.md) |

The two zips are rebuilt by `check-build`; their SHA-256 checksums are written
next to them in `dist/` rather than quoted here, since they change every build.
Free: 59 files. Pro: 67 files, including `uninstall.php`, `LICENSE.txt` and its
translation template.

### Acceptance tests AT01–AT20

Scenario names are the spec's own. "Unit" means the pure-PHP suite; "Integration"
means the real WordPress test suite.

| AT | Scenario | Covered by |
|---|---|---|
| 01 | Normalised international WhatsApp number, Unicode message | Unit `WhatsAppChannelTest::test_at01_digits_only_path_and_single_encoding_pass` |
| 02 | Script markup, CRLF, `javascript:` inputs | Unit `WhatsAppChannelTest::test_at02_rejects_control_characters_in_template`, `LinkChannelTest` (`rejects_javascript_scheme`, `rejects_embedded_crlf`), `EmailChannelTest::test_rejects_header_injection_via_control_characters` |
| 03 | Exclusion and inclusion both match | Unit `BarTargetingResolverTest::test_at03_exclusion_wins_even_when_included` |
| 04 | Equal priorities, multiple matches | Unit `…::test_at04_equal_priorities_tie_break_on_lowest_id` |
| 05 | Default bar and targeted bar both eligible | Unit `…::test_at05_targeted_bar_wins_over_default`; Integration `ProWiringTest::test_at05_…` against live hooks |
| 06 | 09:00 / 17:00 boundaries | Unit `ScheduleEvaluatorTest::test_at06_open_at_start_boundary_closed_at_end_boundary` |
| 07 | Monday 22:00 to Tuesday 02:00 | Unit `…::test_at07_overnight_interval_correct_availability` |
| 08 | Tuesday closed exception after Monday overnight | Unit `…::test_at08_closed_exception_overrides_mondays_overnight_carry_into_tuesday` |
| 09 | UTC, Asia/Karachi, America/New_York incl. DST | Unit `…::test_at09_correct_local_evaluation_in_asia_karachi` and `…_across_a_dst_boundary_in_america_new_york` (UTC is the clock's own base zone, not separately named) |
| 10 | Unknown token, absent product SKU | Unit `TokenResolverTest::test_unknown_token_is_dropped`, `…::test_absent_product_sku_leaves_no_braces_or_broken_punctuation` |
| 11 | Private post or mismatched variation ID | Integration `CallbackRestTest`: `test_a_private_post_is_rejected_as_context`, **`test_at11_a_variation_belonging_to_a_different_product_is_rejected`** (real WooCommerce variation objects), **`test_at11_a_variation_id_is_rejected_outside_a_product_context`** |
| 12 | Two simultaneous retries, same UUID | Integration `CallbackRestTest::test_at12_…` (sequential retry) **plus the parallel run in `npm run test:concurrency`**: 8 processes, 1 created / 7 duplicate / 1 stored row / 1 notification claim |
| 13 | Same UUID, altered payload | Integration `…::test_at13_…` |
| 14 | Mail failure after insertion | Integration `…::test_at14_…` |
| 15 | Database failure | Integration **`…::test_at15_a_database_failure_is_never_reported_as_success`** — the callback INSERT is forced to fail; asserts HTTP 500, no `received` status, zero notification attempts, nothing stored |
| 16 | Unauthenticated / low-privilege admin actions | Free: `CapabilityGatesTest` (8). Pro: **`ProCapabilityGatesTest`** — all 13 privileged handlers and both Pro screens, each as logged-out, subscriber and editor, with a *valid* nonce so the capability check alone must deny; plus a check that a denied delete leaves the stored request intact |
| 17 | Rate-limit threshold and expired window | Integration `CallbackRestTest::test_the_rate_limit_returns_429_with_retry_after` (429 + `Retry-After`); **`RateLimiterTest`**: refused over the limit, allowed again in the next window, expired windows pruned, fails closed when the counter can't be written |
| 18 | Parallel report increments | Integration `ReportingRestTest::test_at18_…` (sequential) **plus the parallel run**: 8 processes × 25 increments of one counter = exactly 200 on a single row |
| 19 | Analytics disabled or consent revoked | Unit `AnalyticsGateTest`; Integration `ReportingRestTest` (off, GPC, administrator); **JS `tracking.test.js` (13)**; and a real-browser run (below) |
| 20 | Malformed or oversize import | Integration `ConfigImportExportTest`: malformed JSON, newer schema version, a bar over the edition limit rejected entirely (no partial change). The 256 KB upload cap in the upload handler itself isn't exercised by a test |

Bold = added in this round. Before it, AT15 had no test, AT11 and AT17 were
half-covered, and AT12/AT18 were only run sequentially.

### What this round found and fixed

| # | Finding | How it was found | Fix and proof |
|---|---|---|---|
| 1 | **Click reports 404'd on plain-permalink sites.** `tracking.js` appended `runtime?source_type=…` to a REST base that already contains `?rest_route=`, corrupting the route | Real browser: the runtime request returned 404 and no row was written | URL built with `URL`/`searchParams`; JS test for both permalink forms (failed before, passes after) |
| 2 | **Click reports 400'd on the home page and archives, on every permalink setting.** An empty `source_id=` was sent for pages with no content ID; the route types it as an integer | Real browser, immediately after fixing #1 (the 404 had been hiding it) | Argument omitted when there is no ID; JS test (failed before, passes after); real browser then showed runtime 200 + event 200 + one stats row |
| 3 | A consent revocation landing between a click and the async send could still let that event through | Reading the code against the spec ("revocation stops subsequent events"), then reproduced by a failing JS test | Consent re-checked immediately before sending; JS test; real browser: runtime fetched, **no event sent**, database unchanged |
| 4 | **`build.sh` excluded every `vendor/` directory**, so the Freemius SDK (meant for `vendor/freemius/`) would have been left out of the Pro zip and licensing would silently do nothing | Reading the build script while checking the release path | Include rule for `vendor/freemius/` only; refuses a real-credentials build without the SDK; warns on placeholders; `CLIFT_RELEASE=1` refuses them. All four paths run against a scratch copy |
| 5 | Real Freemius credentials with a missing SDK failed silently | Same review | Admin-only notice; "configured" now requires **both** the product ID and the key. Both notices rendered via WP-CLI |
| 6 | **Pro had no `uninstall.php`**, so its "delete data on uninstall" flag did nothing — and no screen could even set it. No admin Data removal tool either (both are in the spec) | Checking the spec's lifecycle section against the code | `DataRemover` (shared), `uninstall.php`, a Settings toggle, a Tools-screen removal card (capability + nonce + explicit confirmation), tests. Run for real against real tables: flag off keeps everything; flag on drops Pro's three tables, options, transients and cron event and leaves the free plugin's data |
| 7 | **Schema migration only ran on activation.** Updates never fire the activation hook, so a future schema change would never apply and missing tables never healed | Tracing why tables were not recreated (see incidents) | Runs on `init`; the version option is autoloaded so the check costs no query once current (asserted by a test). The three tables dropped during testing came back on the next load |
| 8 | Privacy-tools exporter and eraser had no tests | Searching tests for them | `PersonalDataTest` (own records only, exact match, case-insensitive, erasure scoped) |
| 9 | Pro had no translation template; free's was stale; Pro had no `LICENSE.txt` | Checking deliverables | 199 and 147 strings generated; both ship in their zips; GPL text added |
| 10 | Release docs described a world where Pro didn't exist | Reading them | Rewritten |

### Real-browser consent check

Scratch site (the wp-env tests site, port 8891, Twenty Twenty-One, plain
permalinks), a bar with one Call action, click reports in **consent-required**
mode, an anonymous visitor in the built-in Chromium. The `tel:` navigation was
cancelled; ContactLift's own click listener ran untouched. Results after the
fixes, with the database as the judge:

| Phase | Requests made | Database |
|---|---|---|
| Click with no consent given | none at all | no row |
| `setAnalyticsConsent(true)`, click | runtime fetch 200, then event beacon 200 | one row: `home`, `source_id 0`, `call`, `click_count 1` |
| `setAnalyticsConsent(false)`, click | none | unchanged |
| Grant, click, revoke in the same tick | runtime fetch only, **no event** | unchanged (still 1) |

Before the fixes the same page returned 404 (then 400) from the runtime route
and never recorded anything. The site was restored afterwards (plugins
deactivated, options and rows removed).

Not checked in a browser: pretty permalinks (covered by the JS test only), and
the administrator exclusion — `tracking.js` isn't even enqueued for an
administrator (`Frontend\Assets::maybe_enqueue_tracking()`), with the REST
handler as a second gate.

### Concurrency check (`npm run test:concurrency`)

Eight separate PHP processes (WP-CLI, WordPress core only), each with its own
database connection, wait for one shared start time and then race the same
callback UUID and the same stats counter.

- AT12: 1 request created, 7 told "duplicate", 0 unexpected results, 1 stored
  row, exactly 1 process wins the notification claim, 1 attempt recorded
- AT18: 8 × 25 increments = **200** on a single row
- No PHP or database errors in any worker

To confirm the harness can fail: the atomic upsert was temporarily replaced by a
read-then-write counter (the pattern the spec forbids). The check reported
**52 instead of 200** — 148 lost updates. The file was restored byte-for-byte.

### Mutation checks

Each of these was broken on purpose, the relevant tests were confirmed to fail,
and the file was restored and compared byte-for-byte with what was committed:

| Broken on purpose | Caught by |
|---|---|
| Variation must belong to the viewed product (check removed) | `test_at11_a_variation_belonging_to_a_different_product_is_rejected` |
| Reports screen capability gate | all 3 role cases for that screen |
| Callback inbox handler capability gate | all 12 handler cases for the inbox |
| Uninstall opt-in flag ignored (always deletes) | both "preserved" tests |
| Atomic counter made read-then-write | the concurrency check (52 ≠ 200) |
| `tracking.js` URL building / empty `source_id` / revocation race | the three corresponding JS tests, against the old script |

### Environment incidents (recorded because they affect how to run things)

- **Docker memory.** The Docker VM has about 1.9 GB. A first concurrency run with
  16 workers, each loading the full plugin set (WooCommerce, Elementor, …), got
  both of this project's MariaDB containers OOM-killed (exit 137). The sibling
  project's containers were unaffected. `wp-env start` restored them with no data
  loss. The harness now boots core only and defaults to 8 workers, and says so in
  its header.
- **The PHPUnit suite and the port-8891 site share one database and the `wp_`
  prefix.** A real `DROP TABLE` check on the 8891 site therefore dropped the
  Pro tables the integration suite uses. The suite only worked beforehand because
  of tables left by an earlier activation. This led to finding #7 above: Pro now
  creates its tables on load, so the suite recreates them itself.

### Not verified

See "Not verified (and why)" in release-checklist.md. In short: Safari/WebKit, a
screen reader, the Pro admin screens and callback dialog as browser-rendered
pages (rendering is covered by integration tests; no browser pass is recorded),
a successful outbound email, the 256 KB import cap, and anything that needs a
Freemius account.
