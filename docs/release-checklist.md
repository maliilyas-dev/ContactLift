# Release checklist — free plugin (ContactLift Smart Contact Bar)

Status as of this writing. "Done" means actually run and verified — see
[verification-report.md](verification-report.md) for the evidence behind
each line. Nothing here is checked off on the strength of the code simply
existing.

## Build quality

- [x] PHPCS clean (WordPress-Extra + PHPCompatibilityWP), `plugins/contactlift`
      and `tests/` both scanned
- [x] Unit suite green — 36 tests, 43 assertions, no WordPress boot required
- [x] Integration suite green — 23 tests, 43 assertions, against the real
      WP test suite via wp-env
- [x] Official Plugin Check: **0 errors, 0 warnings** — run twice: once
      against the dev source tree (browser UI), once against the actual
      built zip (`scripts/check-build.sh`, CLI)
- [x] `scripts/build.sh` produces a clean zip: one top-level `contactlift/`
      folder, no dev tooling, SHA-256 checksum written alongside it
- [x] Clean-install activation from the built zip, in an environment that
      never had the dev copy bind-mounted — options created correctly, no
      PHP errors
- [x] Frontend rendering verified from the packaged zip itself (not just
      the dev source), via a real front-end render call

## Product verification

- [x] All 4 channels (call/WhatsApp/email/link) — unit-tested validation +
      URL building; browser-verified real links
- [x] Bar editor: live preview, action repeater, colour pickers, device
      targeting, sitewide exclusion list
- [x] `[contactlift]` shortcode dedupe — single and repeated, both
      integration-tested and browser-verified
- [x] Global exclusions (admin, feed, 404, password-protected content,
      WooCommerce cart) — integration-tested against real WP query context
- [x] AT16 (capability gates) — every admin screen and both save handlers
      reject logged-out/subscriber/editor, even with a stolen valid nonce;
      administrator succeeds — integration-tested
- [x] Activation defaults, non-autoloaded storage, repeated-activation
      doesn't overwrite — integration-tested
- [x] Uninstall preserve-by-default / delete-when-opted-in — integration-tested
- [x] Performance budget: 2,575 bytes gzip combined CSS+JS (budget: 35,000)
- [x] Accessibility: ARIA structure, visible focus, tab order, Escape
      close+focus-return, 44px targets, RTL mirroring — see
      verification-report.md for exactly what was and wasn't directly
      demonstrable through this session's tools
- [x] Default block theme, classic theme, Gutenberg page, Elementor page,
      360px/768px-boundary widths

## Deliverables present

- [x] `readme.txt` (WordPress.org format)
- [x] `LICENSE.txt` (GPLv2 full text)
- [x] `uninstall.php`
- [x] `languages/contactlift.pot` (132 translatable strings)
- [x] `docs/architecture.md`, `decisions.md`, `progress.md`,
      `owner-setup.md`, `verification-report.md`, this file
- [ ] Screenshots for the WordPress.org assets folder (mobile bar, desktop
      launcher, bar editor, Bars screen) — not yet captured as final,
      labelled asset files
- [ ] WordPress.org directory-asset brief (icon/banner/screenshot captions)
- [ ] `dist/contactlift-1.0.0.zip` — built; **not yet hand-verified by a
      human on a separate real WordPress installation outside this
      session's Docker environment**

## Explicitly not done (out of this build's scope)

- WebKit/Safari testing — this session only has one Chromium-based browser
  pane available
- A checked-in, re-runnable Playwright/Chromium automation suite — this
  session's browser-driven checks were real but manual/scripted in the
  moment, not saved as a repeatable test suite
- Screen-reader software smoke test (VoiceOver/NVDA) — no such software
  available through these tools
- `ContactLift Pro` — does not exist. Nothing in this checklist covers it.

## Before public release (owner-only — see owner-setup.md)

- [ ] WordPress.org account created, slug availability confirmed
- [ ] Final author/support/product URLs (currently placeholders)
- [ ] Owner reviews screenshots, readme copy and this report before
      submitting to the directory

## Bottom line

The free plugin is functionally complete, automated-test-covered, and
passes Plugin Check clean against its actual packaged zip. What's missing
before a WordPress.org submission is entirely on the "owner deliverables"
and "presentation assets" side (screenshots, directory brief, real account)
— not further engineering work on the plugin itself.
