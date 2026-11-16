=== ContactLift Smart Contact Bar ===
Contributors: rocket008008
Tags: contact bar, whatsapp, click to call, floating button, mobile contact
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A floating contact bar so visitors can call, WhatsApp, email or follow a link — through the channel that suits them, without leaving the page.

== Description ==

ContactLift adds one lightweight, colourful contact bar to your site: a bottom action bar on mobile, a floating launcher on desktop. Visitors get up to four ways to reach you — call, WhatsApp, email or a link of your choice — without a plugin bloating your page or a chat widget nagging them to subscribe to something.

= What it does =

* **Four channels.** Click-to-call, WhatsApp click-to-chat with an optional pre-filled message, email with subject/body, or a plain HTTPS link (booking page, menu, anything).
* **Two layouts, one bar.** A bottom action bar on mobile; a floating launcher that expands into a compact list on desktop.
* **Dynamic messages.** WhatsApp and email messages can include `{site_name}`, `{page_title}` and `{page_url}` — resolved per page, encoded once, never left showing a raw `{token}` if a value is missing.
* **Sitewide by default, with exceptions.** Show the bar everywhere except up to five pages you pick — your cart, checkout or account pages, for instance.
* **`[contactlift]` shortcode** for placing it inline in a page instead of floating, with automatic de-duplication if it appears more than once.
* **Make it yours.** Background, text and accent colours, desktop position, and an offset for the bottom edge — all from the bar editor's live preview, no code.
* **Accessible by design.** 44px touch targets, visible keyboard focus, `aria-expanded` on the launcher, Escape to close, reduced-motion respected, RTL-aware.
* **No tracking, no external requests.** Every icon and asset ships with the plugin. Nothing calls home.

= Optional: ContactLift Pro =

A separate Pro add-on (sold independently) builds on this same free base with page/post/product/category targeting, business hours with automatic after-hours behaviour, WooCommerce product-aware messages, a callback-request form with an inbox, and local click reports. The free bar keeps working on its own either way — Pro is optional, not a requirement.

== Installation ==

1. Install and activate the plugin.
2. Go to **ContactLift → Bars** and open the default bar.
3. Add at least one action (for example, Call or WhatsApp) with a real destination, and turn it on.
4. Turn the bar itself on, from either the bar editor or **ContactLift → Settings**.
5. Check the live preview, then visit your site to confirm it on a real page.

== Frequently Asked Questions ==

= Does this send WhatsApp messages automatically? =

No. It opens WhatsApp's own click-to-chat page with a pre-filled message the visitor can still edit or cancel before sending. ContactLift never sends anything on its own.

= Will it slow my site down? =

The free bar adds no background requests — it's a static render plus a small CSS/JS bundle, loaded only on pages where a bar will actually show.

= Can I use it without WhatsApp or WooCommerce? =

Yes. Every channel is optional, and WooCommerce is not required for any free feature.

= Does it work without JavaScript? =

Every contact link (call, WhatsApp, email, link) is an ordinary anchor tag and works with JavaScript disabled. Only the desktop launcher's expand/collapse animation needs JavaScript.

== Screenshots ==

1. The mobile contact bar, live on a page.
2. The desktop floating launcher, expanded.
3. The bar editor with its live preview panel.
4. The Bars screen.

== Changelog ==

= 1.0.0 =
* Initial release: call, WhatsApp, email and link channels; mobile bar and desktop launcher; bar editor with live preview; sitewide targeting with up to five exclusions; `[contactlift]` shortcode.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
