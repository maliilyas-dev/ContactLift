# Verification report

Running log of what was actually tested and the results — updated at each
milestone gate. Nothing here is claimed unless it ran; a milestone that
hasn't reached its gate yet simply has no entry.

## Environment used so far

- WordPress 7.1 (wp-env `core: null` → current stable at time of testing)
- PHP 8.2.33
- MariaDB 12.3.3 (Ubuntu 24.04 image)
- WooCommerce (latest stable, installed but not yet exercised)
- Official Plugin Check plugin (latest stable, installed; not yet run —
  first run is a Milestone 2+ gate once there's frontend output to check)
- macOS host, Docker Desktop, `@wordpress/env` ^10.0.0

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

`plugins/contactlift-pro` doesn't exist yet, so only the free plugin is
built; `scripts/build.sh` reports this explicitly rather than failing.

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
