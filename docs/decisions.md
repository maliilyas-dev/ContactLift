# Implementation decisions

Routine choices the spec left to the implementer, recorded per its "Working
rules" instruction. Newest at the bottom of each section.

## Milestone 1 — Foundation

- **Repo tooling mirrors FormBridge for ActiveCampaign** (`AC-Plugin` /
  `formbridge-for-activecampaign-pro`, the sibling product in this line):
  `@wordpress/env` for local dev, Composer for dev tooling only (PHPCS
  WordPress-Extra + PHPCompatibilityWP, PHPUnit + yoast/phpunit-polyfills).
- **No production Composer dependency.** Both plugins ship a small
  hand-written `spl_autoload_register` PSR-4 loader instead of a bundled
  `vendor/autoload.php`, keeping the release ZIP small and avoiding
  autoloader collisions with other plugins. The one exception, when Pro
  lands, is the Freemius SDK — vendored manually per Freemius's own
  distribution model, exactly as FormBridge Pro does it.
- **Two PHPUnit suites, not one**: `phpunit.xml.dist` (fast, no WordPress
  boot — domain validators/channels/token resolver) and
  `phpunit-integration.xml.dist` (real WP test suite, runs only inside
  wp-env). Domain classes therefore avoid `WP_Error`, `wp_parse_url()`,
  `esc_url_raw()`, etc. — plain PHP and a `ValidationException` value object
  instead, so `composer run test` can run instantly with no Docker
  dependency. A minimal `__()` polyfill in `tests/unit/bootstrap.php` covers
  the one WP function domain code still calls, for translatable error
  strings.
- **wp-env `mappings`**: the spec's repo layout puts `tests/`, `docs/`,
  `phpunit*.xml.dist` at the repo root, one level above the actual plugin
  folders (`plugins/contactlift/`, `plugins/contactlift-pro/`). wp-env only
  mounts the plugin folders under `wp-content/plugins/`, so the whole repo
  root is additionally mapped to `wp-content/contactlift-src` via
  `.wp-env.json`'s `mappings` key — that's where the integration PHPUnit
  config and its bootstrap run from inside the container.
- **wp-env ports**: `AC-Plugin`'s own wp-env instance already occupies the
  default 8888/8889. ContactLift's `.wp-env.json` pins `port: 8890` /
  `testsPort: 8891` so both projects' environments can run side by side
  without a collision.
- **Method naming**: WordPress-Extra's `ValidFunctionName` sniff requires
  snake_case for class methods too (not just top-level functions) — matches
  FormBridge's own convention (e.g. `render_plan_usage()`,
  `toggle()`). Followed throughout rather than excluding the sniff.
- **Channel-specific action fields**: the spec's example config JSON shows
  only a `call` action, with the single field `message_template`. Email
  needs both a subject and a body, which the "Required inputs" table asks
  for explicitly ("Valid email, optional subject and body") but the generic
  example doesn't model. Each channel therefore owns extra keys on top of
  the common action envelope (id/type/label/enabled/closed_behavior/
  replacement) via its own `validate()` — email adds `subject_template` /
  `body_template`, link adds `open_in_new_tab`. This matches the spec's
  explicit instruction to use "an allowlisted channel registry so each
  channel validates its own fields."
- **Business name cap**: not specified by the spec. Chose 150 characters
  (bar names are capped at 80; a business name plausibly runs longer, e.g.
  "Downtown Family Dental & Orthodontic Associates").
- **Email subject/body caps**: not specified. Chose 200 chars for subject,
  1000 for body, matching the general message-template cap used elsewhere.
- **Default bar appearance**: background `#ffffff`, text `#0b1d33`
  (dark navy), accent `#ff5c8a` — the accent is the one "funky" default per
  the earlier design-scope decision; background/text stay close to the
  spec's own suggested neutral frontend default. All three are
  merchant-editable via the (Milestone 2) bar editor.
- **Free edition's `EditionLimits`**: 1 bar, exclude-only targeting capped
  at 5 content IDs (0 product-category IDs, no include list), no schedule,
  channel types `call`/`whatsapp`/`email`/`link` only — read directly off
  the spec's "Scope and editions" capability table. Both editions validate
  through the same `BarValidator`; only the `EditionLimits` passed in
  differs, matching the spec's "one shared configuration model."
- **Out-of-range numeric fields are rejected, not silently clamped**
  (priority outside 0-1000, bottom_offset outside 0-300, an invalid hex
  colour, an invalid device/layout enum): consistent with the spec's
  "Reject overlong templates on save" instruction for a structurally
  analogous case. The validators still compute a safe fallback value
  defensively, but the overall save throws whenever any field errors,
  so the fallback is never actually persisted in normal operation.

## Milestone 2 — Free product / Plugin Check pass

- **`defined( 'ABSPATH' ) || exit;` on every PHP file**, not just the ones
  Plugin Check happened to flag directly — WordPress.org requires it on any
  file that could conceivably be requested directly over the web, and
  Plugin Check's own scan only sampled a subset (files that *execute*
  top-level code, like `autoload.php`, vs. files that just declare a class).
  Applied uniformly rather than reactively.
- **Gotcha this caused**: the fast unit suite (`Domain`/`Support` classes,
  deliberately tested without booting WordPress — see Milestone 1) went
  completely silent — `composer run test` printed nothing and exited 0 —
  because every one of those classes now calls bare `exit;` the instant the
  autoloader touches it outside of WordPress, where `ABSPATH` is never
  defined. Fixed by defining a dummy `ABSPATH` at the top of
  `tests/unit/bootstrap.php`, before the autoloader is required. Caught
  immediately because silent/empty test output is itself suspicious — a
  real 0-test or 0-assertion run still prints a banner; true silence meant
  the process died before PHPUnit could report anything.
- **`WordPress.Security.EscapeOutput.ExceptionNotEscaped`** is excluded
  project-wide in `phpcs.xml.dist` (matching FormBridge's own ruleset,
  reasoning: `ValidationException` messages are never echoed directly, only
  caught and their `->errors()` array `esc_html()`'d by the admin screen
  that catches them) — but Plugin Check runs its own fixed ruleset and does
  not read a plugin's `phpcs.xml.dist`, so it still flags every
  `throw new ValidationException(...)` site. Suppressed with inline
  `phpcs:ignore` comments at each site instead (PHPCS's inline-comment
  suppression is engine-level and works regardless of which ruleset
  invoked it), each carrying the same justification.
- **`WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude`**
  false-positived on the bar schema's own `'exclude' => array(...)` field
  (the spec's own JSON field name) in two places — the sniff appears to
  pattern-match the literal array key `exclude` rather than confirming it's
  actually inside a `WP_Query`/`get_posts()` call. Neither site is a query
  parameter. Annotated with inline `phpcs:ignore` rather than renamed,
  since the field name is spec-defined.
- **Removed `load_plugin_textdomain()`** from the bootstrap — discouraged
  since WP 4.6; WordPress.org auto-loads translations for a directory-hosted
  plugin whose text domain matches its slug, which `contactlift` does.
- **`bottom_offset` sanitization**: a bare `(int)` cast around
  `wp_unslash( $_POST['bottom_offset'] ?? 16 )` didn't satisfy
  `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` even
  though the value genuinely is sanitized — the sniff wants a recognized
  sanitizing *function*, not a cast operator, in that position. Switched to
  `absint()`, which is both correct for a 0-300px offset and on WPCS's
  recognized-sanitizer list.
- **Result**: Plugin Check (all categories, Error + Warning) went from 12
  errors / 6 warnings to 0 / 0. See docs/verification-report.md.

## Milestone 3 — Pro dependency layer, targeting and schedules

- **Schedule affects actions, not bar selection.** Re-read the spec's
  "Closed actions" section carefully before wiring anything: a bar's
  schedule does not remove the bar from targeting/priority resolution — it
  only changes what each *action* inside the winning bar renders as
  (`keep`/`hide`/`replace`, spec-defined per action). `BarTargetingResolver`
  (already unit-tested pre-Pro-wiring) is therefore untouched by schedule
  entirely; `ClosedActionResolver` (new) transforms `actions` on whichever
  bar targeting already picked. Getting this composition order backwards
  (schedule filtering the bar list before targeting runs) was the first
  design considered and rejected once "Replace" turned out to mean
  substituting one action for another, not excluding a whole bar.
- **`clift_resolve_bar` gained a third `$device` filter argument**
  (`'mobile'|'desktop'|null`), and `BarResolver`/`Renderer` now resolve the
  floating wp_footer output *twice* — once per device — instead of once and
  splitting mobile/desktop purely with CSS. This was already flagged as a
  deliberate gap in `BarTargetingResolver`'s own docblock from when the
  targeting engine was first written; closing it was required for Pro's
  per-device targeting to have any real effect. The `[contactlift]`
  shortcode's single inline placement still resolves with `$device = null`
  and no device gate, unchanged from before — device targeting is a
  floating-output concept only.
- **New extension points, symmetric with the existing `clift_resolve_bar`/
  `clift_loaded` pattern**: `clift_validate_schedule` (free's
  `BarValidator` delegates full weekly/exception validation to Pro's
  `ScheduleValidator` through this filter, rather than free importing a
  `ContactLiftPro\*` class by name — free must have zero compile-time
  knowledge of Pro), `clift_edition_limits` (which `EditionLimits` the
  shared bar editor validates against), `clift_bar_editor_fields`
  (Pro renders its Include-targeting and Business-hours cards into free's
  existing editor screen) and `clift_bar_editor_submission` (Pro merges its
  own POSTed fields into the raw bar array before validation).
- **Pro reuses free's `BarEditorPage`/`BarsPage` rather than forking a
  parallel admin UI.** The alternative (a separate Pro-only editor) would
  have duplicated ~500 lines of already-tested, already pixel-corrected
  markup. `BarEditorPage` now takes an optional `?bar_id` query arg (falls
  back to the default bar; `bar_id=new` starts blank) and Pro adds its rows
  via `clift_admin_bars_list_extra`, which already existed for exactly this.
- **Found and fixed a latent data-loss bug while wiring this reuse**:
  `BarEditorPage::handle_save()` called
  `save_bars( array( $raw ), ... )` — always a *single-bar array* — which
  `SettingsRepository::save_bars()` treats as the complete replacement
  document. Harmless while free only ever had one bar, but reusing this
  save path for a second (Pro) bar would have silently deleted every other
  bar on each save. Fixed with an explicit upsert-by-id against the full
  existing bars list before saving, and the `default_bar_id` passed to
  `save_bars()` now always comes from the *existing* stored document
  instead of `(string) $raw['id']` — editing a targeted bar must never
  promote it to be the sitewide fallback.
- **Product-category targeting has full engine and validator support
  (`BarMatcher`, `EditionLimits::pro()`) but no admin UI and no real data
  yet** — `Plugin::resolve_bar()`'s `product_cat_ids()` returns `array()`
  with a comment explaining why: no WooCommerce category adapter exists
  until Milestone 4. This correctly makes category-scoped rules never
  match rather than guessing at a value; it is not a bug, it's an honest
  reflection of what's built so far.
- **"Replace" is validated as a plain channel action with no
  `closed_behavior`/`replacement` of its own** — this is what "prevent
  replacement loops" (spec) means structurally: there is no field a chain
  could continue through, so no runtime loop-detection is needed either.
- **Verification**: full round trip run for real in wp-env, not just
  asserted in unit tests — activated Pro alongside free (dependency guard,
  `ProInstaller`'s three dbDelta tables + `clift_pro_settings` + cron all
  confirmed created), created a real targeted bar through the actual admin
  UI, confirmed the default bar's data was untouched after saving a second
  bar (the bug above, caught by watching for exactly this), confirmed two
  different pages resolve to two different bars' phone numbers, confirmed
  an always-closed schedule with `hide` suppresses the bar's only action
  (and therefore the whole bar, via the existing `has_renderable_action`
  gate), and confirmed delete preserves everything else. Converted into a
  permanent `tests/integration-pro/` suite (`npm run test:integration:pro`)
  against real WP hooks with both plugins loaded, so this stays regression
  -tested rather than only manually checked once.

## Milestone 4 — Product tokens and the callback request system

- **Two real bugs found only by testing the actual admin UI/browser flow,
  neither of which any unit or integration test caught on its own** —
  recorded in detail because both are the kind of timing/ordering mistake
  that's easy to reintroduce elsewhere in this codebase's hook-based
  extension architecture:
  1. **`ChannelRegistry` queried `clift_register_channels` in its own
     constructor.** Every call site (`BarValidator`, `ActionLinkBuilder`,
     `BarEditorPage`) is itself constructed synchronously inside
     `\ContactLift\Plugin`'s constructor or `boot()` body, both of which
     complete *before* `boot()` fires `clift_loaded` at its very end — the
     one moment Pro's listener actually attaches. So "Callback" silently
     never appeared as a selectable action type, and a callback action
     could never validate, through the exact same long-lived
     `Plugin::instance()` singleton a real request uses — not a
     stale-per-request issue, a wrong-order-within-every-request one. Fixed
     by deferring the filter query from the constructor to first use
     (`has()`/`get()`/`types()`), since nothing calls those during that same
     early construction. Regression-tested against the real singleton in
     `tests/integration-pro/ProWiringTest.php`, not a fresh throwaway
     instance, since a fresh instance built later wouldn't have reproduced
     the bug at all.
  2. **A click anywhere inside the callback dialog silently closed the
     desktop launcher panel underneath it**, breaking `close()`'s
     focus-restoration (`lastFocused.focus()` silently no-ops on an element
     inside a now-`[hidden]` panel). Root cause: the dialog renders as a
     sibling of `.clift-desktop`, not a descendant — correct, since nesting
     a full-viewport modal inside the bar's own small fixed-position
     context would be wrong — but free's `frontend.js` "click outside
     closes the panel" check only ever tested `.clift-desktop.contains()`,
     so every click inside the (structurally outside) dialog counted as
     "outside." Fixed with a generic `[data-clift-overlay]` marker free's
     JS checks for, rather than hard-coding a Pro-specific class name into
     free's script — the same free-defines-the-seam, Pro-opts-in shape as
     every PHP extension point.
- **`clift_register_channels` and `clift_token_values` filters** added,
  symmetric with the session's existing pattern — Pro registers
  `CallbackChannel` and merges `{product_name}/{product_sku}/{product_url}/
  {variation}` into free's `PageContext::free_tokens()` this way. A callback
  action's `destination` is a fixed, meaningless-but-required sentinel
  string — the shared action schema demands a non-empty destination before
  an action can be enabled, a rule written for link-shaped channels; a
  callback has no per-action destination of its own by spec design.
- **Callback readiness (recipient + privacy URL configured) is a render
  -time check, not a save-time requirement.** An admin can save one before
  the other; `Plugin::resolve_bar()` drops an unconfigured callback action
  from the winning bar's actions exactly the way a closed+hidden action is
  dropped, and the REST layer checks it again independently before issuing
  a challenge — belt-and-suspenders, since the button not rendering
  client-side is not itself an access control.
- **The challenge token is self-contained and stateless** (HMAC-signed
  payload: uuid, context, expiry — verified with only the secret and the
  current time, no DB round trip), so issuing one is cheap enough to
  rate-limit on its own without extra storage cost. `wp_salt('auth')`
  supplies the real secret at the REST layer; `ChallengeSigner` itself takes
  an injected secret and uses plain `json_encode()`, not `wp_json_encode()`,
  keeping it unit-testable without WordPress — same reasoning as
  `LinkChannel`'s plain `parse_url()` from Milestone 1.
- **Found by a failing integration test, not by inspection**: the rate
  -limit bucket key was built as `"{scope}:" . $hmac_hex` — 64 hex chars
  plus a prefix, silently exceeding `clift_rate_limits.bucket_key
  VARCHAR(64)`. The resulting failed INSERT correctly triggered the
  rate limiter's own "fail closed" design (spec: "fail closed for
  submissions if the abuse-control store cannot safely accept a new
  bucket") — which looked exactly like every request being rate-limited,
  because it was, just not for the reason it appeared to be. Fixed by
  folding the scope into the HMAC's input instead of concatenating it onto
  the output, keeping the stored key a clean fixed-length digest.
- **Idempotency (AT12/AT13) is payload-hash comparison on a UNIQUE
  request_uuid collision**, not a separate lookup-then-insert step — insert
  first, and only inspect the existing row when the unique constraint
  itself rejects the write. `$wpdb->suppress_errors()` wraps that insert,
  since a duplicate-uuid hit is an expected, fully handled outcome here,
  not a surprise worth logging as one.
- **Notification claiming uses only the spec's three named
  `notification_status` values** (pending/handed_to_mailer/failed) as the
  atomicity mechanism itself — `UPDATE ... SET notification_status =
  'handed_to_mailer' WHERE notification_status IN ('pending','failed')`
  attempts the state transition and the send in one step; a `false` return
  from `wp_mail()` flips it back to `failed` afterward. No separate
  "claimed"/"sending" state was needed.
- **Product-category targeting's data source is now real** (Pro's
  `Plugin::product_cat_ids()` reads `get_the_terms()`/the current
  `product_cat` archive term), closing the gap Milestone 3 left
  deliberately open and documented.
- **Deferred, honestly, not silently**: `{variation}` resolves to the
  parent product's own tokens on first render and starts empty — the spec's
  live, no-reload variation-switching interaction is a real, separate
  frontend feature (binding to WooCommerce's own variation-form JS) that
  this pass didn't build. The exceptions UI in `BarEditorExtension` supports
  one interval per exception day and 5 new rows per save rather than the
  spec's full 100-cap dynamic list — both are admin-UI scope cuts, not
  engine limitations; the validators and evaluators underneath already
  handle the spec's full cases.
- **Verification**: live in wp-env end to end — settings saved through the
  real admin screen, a callback action added through the real bar editor
  (which is exactly where bug #1 above was first noticed: "Callback" wasn't
  in the type dropdown), a real submission through the real dialog
  producing a 201 and a stored record, the admin inbox showing it with a
  working status change and a genuine failed-notification retry (wp-env has
  no mail transport, so "Failed" here is real, not simulated). Locked into
  `tests/integration-pro/CallbackRestTest.php` (11 tests covering
  AT12-AT16 directly against the real REST server) alongside the two
  regression tests above.

## Milestone 5 — Reporting, retention, export/import, privacy

- **`source_id` is stored as `0`, never SQL `NULL`**, for a click event with
  no specific content ID (home/archive pages). MySQL's unique index treats
  every `NULL` as distinct from every other `NULL`, which would silently
  defeat the composite unique key `DailyStatsRepository::increment()`
  depends on for its atomic upsert — every home-page click would `INSERT` a
  new row instead of incrementing the existing one. `0` is never a real
  WordPress post ID, so it's a safe, unambiguous sentinel; the display side
  (`ReportsPage::source_label()`) special-cases it back to a human label.
- **`AnalyticsGate::is_recording_allowed(mode, gpc, is_admin)` is pure
  decision logic**, deliberately taking its inputs as plain values rather
  than reading `$_SERVER`/`is_user_logged_in()` itself, so all three
  `analytics_mode` states plus the GPC and administrator overrides are
  unit-testable without booting WordPress. GPC and administrator status
  both unconditionally block recording regardless of mode.
- **Retention is a strict enum, not a clamped range.**
  `callback_retention_days` accepts exactly {30, 90, 180} and
  `stats_retention_days` exactly {30, 90} — `ProSettingsValidator::
  retention_choice()` rejects anything else outright rather than rounding
  to the nearest permitted value, matching the spec's literal "choose one
  of" wording rather than treating it as a bounded numeric field.
- **Found while writing an integration test, not by inspection**:
  `analytics_mode` had no admin control at all — the setting could be
  changed only by hand-editing the option, because
  `SettingsExtension::handle_save()` never read it from `$_POST`. Fixed
  with a "Click reports" card (Off / Consent required / Local aggregate)
  and the corresponding `$_POST` handling.
- **Import/export is dry-run-then-confirm, not a single-step upload.** The
  first submit only validates the uploaded JSON and stores the normalized
  result in a transient keyed to the current user; nothing touches
  `clift_settings`/`clift_bars` until a second, explicit "Replace
  configuration now" submit reads that same transient back.
- **CSV export neutralizes formula injection** by prefixing any cell whose
  value starts with `=`, `+`, `-`, or `@` before it's written, so a
  callback message or name containing one can't execute when the export is
  opened in Excel/Sheets/Numbers.
- **Privacy Tools integration is email-matched only; phone-only requests
  get an authenticated admin workflow instead of a public endpoint** — a
  public phone-lookup route would itself be a privacy hole (anyone who
  knows a phone number could probe for whether it submitted a request).

## Admin UI — searchable picker, both editions

- **Native `<select multiple>` listboxes replaced with a custom
  `Fields::picker()` component**, in response to direct feedback that the
  existing Exclusions (free) / "Show only on" (Pro) fields looked and
  behaved poorly — a bordered card with search-as-you-type filtering, a
  scrollable checkbox list, and a live "N selected" count. The wire format
  was deliberately left untouched (`name="field[]"`, same array-of-IDs
  submission), so every validator and repository that already handled this
  field needed zero changes.
- **Built once in the free plugin, reused as-is by Pro** via the existing
  `use ContactLift\Admin\Fields;` cross-plugin import — no duplicated
  component, no Pro-specific fork of the picker markup.
- **The live-count label is computed as PHP variables before the HTML
  block, not inlined as `esc_attr_e('%d selected', ...)`.** The inline form
  triggered PHPCS's `MissingTranslatorsComment` sniff even with a preceding
  `<?php /* translators: ... */ ?>` block, because the sniff doesn't
  associate a translators comment that sits in a separate PHP tag from the
  `__()` call it documents. Computing the label ahead of the HTML, with the
  translators comment directly above the `__()` call in the same statement,
  satisfies the sniff.
- **Verified live, not just unit-tested**: search filtering, checkbox
  highlighted-row state, selection surviving a filter change, and a full
  save/reload round trip confirmed against the actual `clift_bars` option
  content — the same component renders correctly for both free's
  Exclusions field and Pro's Include field with no edition-specific
  branching.

## Milestone 6 — Freemius guarded adapter, packaging, GitHub

- **The Freemius adapter is copied from FormBridge's proven shape, not
  designed fresh**: a `clift_fs()` singleton accessor wrapping
  `fs_dynamic_init()`, gated by two placeholder constants
  (`FREEMIUS_PRODUCT_ID`, `FREEMIUS_PUBLIC_KEY`). While they hold their
  placeholder values, `clift_fs()` returns `null` immediately — no attempt
  to call `fs_dynamic_init()` with fake credentials, no SDK `require`, just
  a plain admin notice explaining that licensing isn't configured yet. This
  repo has no real Freemius product, so the code must be provably inert
  until the owner supplies one.
- **The bare-menu-slug redirect is copied verbatim from FormBridge too**:
  Freemius's own post-activation/account links point at the bare product
  slug (`contactlift-pro`), which WordPress rejects with a capability error
  since only the real `-account` page is actually registered. Fixed by
  registering the bare slug as a real-but-hidden submenu page, redirecting
  on `admin_init` (not the page callback — headers are already sent by the
  time a submenu callback runs) to the real account page, and hiding the
  bare entry from the visible nav via `admin_head` CSS.
- **Plugin Check runs its own fixed ruleset, independent of
  `phpcs.xml.dist`** — project-level sniff exclusions don't apply; every
  site needs its own inline `phpcs:ignore`. Running it against the actual
  built `contactlift-pro-1.0.0.zip` surfaced a systematic bug in several
  existing ignore comments: a trailing
  `// phpcs:ignore ... InterpolatedNotPrepared` comment placed at the end
  of a multi-line SQL string doesn't suppress the violation, because PHPCS
  attributes the violation to the line the flagged `{$table}` token sits
  on, not the line the comment sits on. Several warnings that looked
  suppressed in the source were never actually suppressed.
- **Fixed at the root with `%i`, not by relocating comments.** Both
  plugins already require WP 6.8 (the `Requires at least` header), well
  past the WP 6.2 minimum for `$wpdb->prepare()`'s `%i` identifier
  placeholder, and the pinned `wp-coding-standards/wpcs` (3.4.1) recognizes
  it. Every raw-SQL table-name interpolation across `RateLimiter`,
  `DailyStatsRepository`, `CallbackRepository` and `PersonalData` was
  rewritten so the table name flows through `prepare()` itself as a `%i`
  placeholder instead of being interpolated into the query string first —
  closing the whole class of finding structurally rather than asserting
  line-by-line that each one is safe.
- **One WPCS false positive remains, documented rather than restructured
  away.** `DailyStatsRepository::query()` builds its `WHERE` clause from an
  array of conditionally-pushed string fragments (e.g. `'bar_id = %s'`) —
  every fragment is a fixed literal from this method's own source, never
  derived from the filter *values* (those go through separate `%s`
  placeholders), but WPCS's static analysis can't verify that and flags the
  variable as unescaped regardless. This is the standard, safe WordPress
  pattern for an optional-filter `WHERE` clause, so it's suppressed with a
  comment spelling out exactly why every possible value of that variable is
  safe, rather than chasing a sniff that can't be satisfied by
  restructuring.
- **Verification order matters here**: every fix was confirmed against a
  real MySQL database via the integration suites before re-running Plugin
  Check, since a `%i`-based rewrite changes actual query construction, not
  just comment placement. 147 tests green, then a clean Plugin Check pass,
  in that order.
