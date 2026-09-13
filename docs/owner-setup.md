# Owner setup

Values only Muhammad Ali Ilyas can supply — none of these block the
functional build (see the spec's "External setup boundaries" and "Launch
handoff and owner setup"). Both plugins are engineering-complete, fully
tested (147 tests green) and pass the official Plugin Check; everything
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

`plugins/contactlift-pro/contactlift-pro.php` currently runs with
licensing disabled: two constants near the top of the file hold
placeholder values, and every Freemius-dependent code path is skipped
until they're replaced. Local Pro features (targeting, schedules,
callbacks, reports, export/import) all work normally in this state — only
the Account/Upgrade/licensing screens are inactive.

1. Create a Freemius account and register ContactLift Pro as a product in
   their dashboard (slug: `contactlift-pro`).
2. From the Freemius dashboard, download the PHP SDK and place it at
   `plugins/contactlift-pro/vendor/freemius/start.php` (this path is
   already in `.gitignore` — it's fetched fresh, never committed, exactly
   like FormBridge Pro's own SDK).
3. In `contactlift-pro.php`, replace the two placeholder constants:
   - `FREEMIUS_PRODUCT_ID` — the numeric product ID from the dashboard.
   - `FREEMIUS_PUBLIC_KEY` — the public key from the dashboard.
4. Configure pricing plans in the Freemius dashboard (the spec's pricing
   tiers, if not already decided, need to be settled at this point).
5. Reactivate Pro in a test site and confirm the Account/Upgrade menu
   appears under ContactLift and licensing actually verifies against a
   real (test-mode) Freemius account.

No other code change is needed — `clift_fs()` in `contactlift-pro.php`
already contains the full `fs_dynamic_init()` call with the right menu/
slug configuration; it just returns `null` (inert) until step 3 above.
