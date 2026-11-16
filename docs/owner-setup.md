# Owner setup

Values only Muhammad Ali Ilyas can supply — none of these block the
functional build (see the spec's "External setup boundaries" and "Launch
handoff and owner setup"). Both plugins are engineering-complete, fully
tested (235 automated tests green) and pass the official Plugin Check; everything
below is what's left before public release.

| Item | Used for | Status |
|---|---|---|
| GitHub repo | Source hosting | Done — [github.com/maliilyas-dev/ContactLift](https://github.com/maliilyas-dev/ContactLift), pushed |
| WordPress.org account + accepted slug | Free directory submission/updates | Not started |
| Final author, support and product URLs | Plugin metadata, readmes, support links | Placeholder (`https://m-ali.dev/contactlift`) in bootstrap headers |
| Freemius Pro product configuration (product id, public key, secret key) | Genuine SDK integration | Guarded adapter code is done and committed — see below for exactly what to replace |
| Freemius plan IDs + permitted test credentials | Licence, site-limit and update verification | Not started |
| Real business contact examples | Owner-operated staging smoke tests | Not started |
| Support and renewal terms | Store checkout and product documentation | Not started |

**Licensing/commercial-release status: unverified.** Per the build
instructions, this stays true — and public release does not happen — until
real Freemius values above are supplied and exercised with real test
credentials.

## Activating Freemius (exact steps)

`plugins/contactlift-pro/contactlift-pro.php` currently runs with licensing
disabled: the two Freemius values near the top of the file point at
placeholders, and every Freemius-dependent code path is skipped until both are
replaced. Local Pro features (targeting, schedules, callbacks, reports,
export/import) all work normally in this state — only the
Account/Upgrade/licensing screens are inactive, and an admin notice says so.

1. Create a Freemius account and register ContactLift Pro as a product in
   their dashboard (slug: `contactlift-pro`).
2. From the Freemius dashboard, download the PHP SDK and unzip it so that
   `plugins/contactlift-pro/vendor/freemius/start.php` exists. (`vendor/` is
   gitignored — the SDK is fetched, never committed, exactly like FormBridge
   Pro's. The build script ships `vendor/freemius/` and nothing else from
   `vendor/`.)
3. In `contactlift-pro.php`, change the **right-hand side** of these two lines
   (leave the two `…_PLACEHOLDER` constants above them alone):

   ```php
   const FREEMIUS_PRODUCT_ID = FREEMIUS_PLACEHOLDER;      // -> '12345' (your numeric product ID)
   const FREEMIUS_PUBLIC_KEY = FREEMIUS_KEY_PLACEHOLDER;  // -> 'pk_...' (your public key)
   ```

   Both must be replaced. With only one changed, Pro still treats licensing as
   not configured rather than handing Freemius a credential it can only reject.
4. Configure the pricing plans in the Freemius dashboard.
5. Build the releasable zip:

   ```bash
   CLIFT_RELEASE=1 npm run build
   ```

   `CLIFT_RELEASE=1` makes the build **refuse** a Pro zip that still has
   placeholder credentials. It also always refuses credentials without the SDK
   (the zip would silently run unlicensed). Without the flag, a placeholder build
   still succeeds — that's what the verification runs use — but prints a warning
   that it is not releasable.
6. Run `npm run check-build`. Plugin Check will now scan the bundled SDK for the
   first time, so expect findings you haven't seen before and review them.
7. Install the zip on a throwaway site, activate it, and confirm the
   Account/Upgrade menu appears under ContactLift and licensing verifies against
   a Freemius test-mode account.

If the SDK is ever missing from an install that has real credentials, Pro shows
an admin-only error notice ("the licensing component is missing…") instead of
failing silently — the local features keep working either way.
