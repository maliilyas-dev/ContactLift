=== ContactLift Pro ===
Contributors: muhammadali
Tags: contact bar, whatsapp, click to call, callback request, click reports
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Page and product targeting, business hours, callback requests and local click reports for ContactLift.

== Description ==

ContactLift Pro extends the free ContactLift Smart Contact Bar with the targeting, scheduling and lead-capture tools a growing site needs — while the free bar keeps working on its own, with or without Pro installed.

= What it adds =

* **Targeted bars.** Up to ten bars total, shown on exact pages, posts, products or product categories you choose — highest priority wins when more than one matches.
* **Business hours.** A weekly schedule per bar with date exceptions, evaluated correctly across daylight-saving changes. Each action chooses what happens to it while closed: keep it, hide it, or replace it with another channel.
* **WooCommerce product tokens.** `{product_name}`, `{product_sku}` and `{product_url}` join the free tokens in WhatsApp and email messages on product pages.
* **Callback requests.** An accessible request dialog, a signed same-origin submission flow, an inbox with statuses and private notes, and email notification with a manual retry if it fails.
* **Local click reports.** Approximate daily counts by bar, channel, page and device — off by default, with Consent-required and Local-aggregate modes, and Global Privacy Control respected automatically. CSV export included.
* **Configuration export/import.** Move your business settings and bars between sites as JSON, with a dry-run summary before anything changes.
* **Retention and cleanup.** Automatic daily pruning of old callback requests and report data, with a manual "run cleanup now."

This add-on requires the free ContactLift plugin to be installed and active — it does not work standalone.

== Installation ==

1. Install and activate the free ContactLift plugin first.
2. Install and activate ContactLift Pro.
3. Go to **ContactLift → Bars** to add targeted bars, or edit the default bar to add business hours.
4. If you're using the callback request channel, set a notification email and privacy policy URL under **ContactLift → Settings** first — the action won't go live without them.

== Changelog ==

= 1.0.0 =
* Initial release: page/post/product/category targeting with priority resolution, weekly business hours with date exceptions and closed-action behaviour, WooCommerce product tokens, the callback request system with an inbox, local click reports with consent modes, retention cleanup, and JSON configuration export/import.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
