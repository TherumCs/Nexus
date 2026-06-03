=== Nexus by Therum ===
Contributors: therumstudios
Tags: connections, api, integrations, cms, ecommerce
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect CMS platforms, ecommerce stores, and third-party APIs from a single admin surface.

== Description ==

**Nexus** gives WordPress a single place to wire up the other systems it talks to — CMS platforms, ecommerce stores, and third-party APIs — with saved credentials per connector and a tile-grid UI organized by category.

Built and maintained by **Therum Creative Studios**.

= What it does =

* Adds a top-level **Connections** menu in wp-admin.
* Three categories out of the box — **CMS Platforms**, **Ecommerce**, **APIs** — each with its own tab.
* 17 connectors pre-wired: WordPress, Drupal, Ghost, Webflow, Contentful, WooCommerce, Shopify, Amazon Seller, Etsy, Square, Printful, Printify, Pod Partner, Stripe, Mailchimp, plus a generic **Custom API** for any REST endpoint.
* Per-connector configuration forms with sensible field types (URL, password, text, select, checkbox).
* Saved credentials are stored as a separate WordPress option per connector, with `autoload=false` so they don't bloat your front-end page loads.
* Masked password fields — values display as `••••••••` once saved, and the placeholder is preserved on round-trip so you don't accidentally overwrite the real key.
* No external dependencies. No bundled services. No phone-home.

= What it doesn't do (yet) =

Nexus stores credentials and presents a unified surface. It does **not** by itself fetch data from those services — that's the job of integration code you (or another plugin) write against the stored credentials. Use `nexus_get_connector( 'shopify' )` to retrieve a saved config inside your own code.

= Filters =

* `nexus_connector_registry` — modify or extend the built-in connector list.
* `nexus_connections_page_tabs` — add or reorder admin-page tabs.

== Installation ==

1. Upload `nexus-by-therum.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate.
3. Find **Connections** in the wp-admin sidebar.

== Frequently Asked Questions ==

= Where are credentials stored? =

In the `wp_options` table, one option per connector with the name `nexus_connector_{slug}` (e.g. `nexus_connector_shopify`). The value is a JSON-encoded blob containing the saved field values. Options are saved with `autoload=false`.

= Does this plugin transmit my credentials anywhere? =

No. Credentials are written to your own database and never sent off-site by this plugin.

= Does uninstalling remove my saved credentials? =

Yes. The bundled `uninstall.php` deletes every `nexus_connector_*` option when the plugin is deleted via the WordPress admin (not just deactivated).

== Changelog ==

= 1.0.0 =
* Initial release. Extracted from the Therum OS `therum-connections` module.
