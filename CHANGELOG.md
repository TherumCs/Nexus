# Nexus by Therum — Changelog

## [1.5.1] — 2026-06-04

### Removed
- **Checkout experience moved out of Nexus.** The bundled multi-rail checkout (added in 1.5.0) lived in the wrong layer — Nexus is the connector / integration plane, not a commerce surface. Removed `includes/checkout.php`, `templates/checkout-shell.php`, the `[nexus_checkout]` shortcode, the Checkout section in the sidebar, and the methods-status admin tab. Commerce surfaces (Shop plugin 0.2.0+, or a custom WC gateway) should now consume `nexus_connector_registry()` + `nexus_connector_is_configured()` to build their own checkouts on top of whatever payment connectors are configured here.

### Notes
- Nothing else changed — all 84 connectors, the Channels (product feed) generator, the Updates tab, validation, bridge_only support are intact.

## [1.5.0] — 2026-06-04

### Added
- **Checkout experience.** New top-level Checkout section + tab. Bundled multi-rail checkout (Card · Wallets · BNPL · Bank · Crypto · P2P) that replaces what WooPayments does — with way more rails. Available as the `[nexus_checkout]` shortcode; drop onto any page, set as the WC checkout page. Right-hand summary pulls real WC cart data (line items with images, variant attributes, totals, shipping methods). Method picker auto-filters: each method's pill lights up only when at least one of its backing Nexus connectors is configured. Disabled methods show a contextual "Connect X in Nexus to enable" prompt with a link to the right Connections tab. New files: `includes/checkout.php`, `templates/checkout-shell.php`. The admin "Checkout experience" tab shows a live preview + a methods-status table (which method, what backs it, configured or not).
  - **Method ↔ connector map** lives in a single PHP constant (`NEXUS_CHECKOUT_METHODS`); adding a new payment provider + the method it backs is a one-line change.
  - Phase 1 wires the SHELL only — submit currently posts to WC's standard checkout endpoint, so payment actually goes through Woo's existing gateway. Phase 2 will land real SDK wiring per method (Stripe Elements, Plaid Link, Coinbase Commerce Charge, etc.).
- **Crypto rails.** Four new payment connectors covering the spectrum: **AnyPay** (non-custodial direct-to-wallet, 50+ coins, preferred routing per the design spec), **NOWPayments** (custodial, broadest coin coverage), **BTCPay Server** (self-hosted), plus the existing Coinbase Commerce.
- **BNPL rails.** Four new payment connectors completing the Pay-in-4 lineup: **Affirm**, **Afterpay (Clearpay)**, **Sezzle**, **Zip**. Plus PayPal Credit through the existing PayPal connector. The Checkout BNPL panel filters per-provider so customers only see the BNPLs you've actually wired.
- **Cash App Pay.** New payment connector backed by Square's API (Cash App is Block-owned). Wires into the Checkout P2P method.
- **Bridge-only connector type.** Some services (PODpluser, Tapstitch) have no public developer API — they connect via 1-click integrations into Shopify / WooCommerce / Etsy / etc. New `bridge_only => true` schema on connectors. Bridge-only cards render with a platform picker instead of a credential form. When the user has a backing bridge live on this site (WooCommerce active, Shopify configured in Nexus, …) the status pill flips to "Connected via X" with evidence (e.g. matching keys found in `wp_woocommerce_api_keys`). Added PODpluser + Tapstitch with this shape.

### Changed
- **`nexus_render_conn_cards` special-cases bridge_only connectors.** They render a Connect/Manage button that opens a platform picker (links out to the connector's own site for the auth flow); the standard credential form path is unchanged for everyone else.
- **Sidebar nav adds two sections** between Connections and Manage: **Channels** (1.4.0) and **Checkout** (1.5.0).
- **Sidebar nav counts (`X / Y`)** are now computed live from the registry instead of being static hardcoded strings per tab. Bridge-only connectors are counted in the X numerator when a backing bridge is detected.

### Notes
- `includes/woo-auth.php` was added in an intermediate iteration (a Nexus-side WooCommerce REST-API auth flow) and then removed when the user clarified that the bridge model should be a launcher only (open the service's own auth screen in a new tab), not Nexus initiating WC auth itself. The remaining bridge_only flow is launcher-only.
- The card-brand sniff (Visa/MC/Amex/Discover auto-detect on input) and the section focus glow from `previews/checkout-experience.html` are not yet ported — purely cosmetic; flagged for a follow-up.

## [1.4.0] — 2026-06-03

### Added
- **Channels — bundled product-feed generator.** New top-level Channels section + tab. Replaces what CTX Feed / Product Feed PRO does, with **smarter mapping** so feeds actually validate without per-product manual entry. Five channels ship: **Google Shopping** (XML), **Meta Catalog** for Facebook + Instagram Shopping (CSV with Meta's quirky field names), **Pinterest Catalog** (CSV), **TikTok Shop** (CSV), **Microsoft / Bing Merchant** (XML, reuses Google spec).
- **Public feed endpoint** at `GET /wp-json/nexus/v1/feed/<channel>?token=<auto-generated>`. Token auto-minted on first save, persists. Channels poll it unauthenticated. New file: `includes/feeds.php`.
- **Smart field auto-detection.** Brand, GTIN, and MPN auto-detect from every common WC source before falling back to the user-configured override or site name. Sources for **brand**: configured custom field → `_brand` meta → `product_brand` taxonomy (Yoast WC SEO) → `pa_brand` attribute → `pwb-brand` (Perfect Brands plugin) → `_yoast_wpseo_brand` → configured fallback → site name. Sources for **GTIN**: configured field → `_global_unique_id` (WC 8.3+ official) → `_gtin` → `_ean` → `_upc` → `_isbn`. Sources for **MPN**: configured field → `_mpn` → `_manufacturer_part_number` → `_part_number`.
- **Per-channel field formatting.** Meta's `availability` is `in stock` (with space); Google's is `in_stock` (with underscore). Submitting the wrong one silently suppresses IG Shopping listings. Nexus now formats correctly per channel.
- **Variant-aware feeds.** Variable products expand to one feed row per variation with `item_group_id` pointing at the parent. Variant attributes (`color`, `size`, `material`, `pattern`, `gender`, `age_group`) auto-extract from WC variation attributes by name-matching, so IG/FB show variant pickers instead of N separate listings.
- **Inventory / `quantity_to_sell_on_facebook`.** Meta-specific field that controls whether IG Shopping shows the stock count. Auto-derived from WC's stock quantity, with a sensible default of 99 for in-stock products without explicit stock management. This was the main thing missing from prior tooling.
- **Sale prices** + ISO-8601 `sale_price_effective_date` windows.
- **`additional_image_link`** — up to 10 gallery images per item (Meta caps at 10; Google emits one element per image).
- **`fb_product_category`** — Meta-specific category field (alongside `google_product_category`).
- **Shipping weight** with WC's configured unit.
- **Preview Feed button** on each channel card — opens the raw feed bytes in a new tab so you can verify mapping before submitting to the channel. Plus a **Copy URL** button.

### Changed
- **Sidebar nav adds a Channels section** between Connections and Manage.
- **`nexus_render_ai_tab` / `_payments_tab` / `_apps_tab`** converted from demo fixture cards to registry-driven cards using `nexus_render_conn_cards()` — same Connect/Disconnect flow as CMS/Ecommerce/APIs.
- **`NEXUS_CUSTOM_CATEGORIES`** expanded from `[cms, ecommerce, apis]` to all 6 categories so custom connectors can be added under AI / Payments / Apps too.

### Added — connector registry expansion
- **+32 built-in connectors** across all 6 categories. CMS: Strapi, Sanity, Storyblok, HubSpot CMS. Ecommerce: BigCommerce, Magento / Adobe Commerce, Lemon Squeezy, Easy Digital Downloads. APIs: Twilio, SendGrid, Resend, Mailgun, Postmark, Brevo, Mapbox, Algolia, Discord (Webhook). AI: Together AI, Replicate, Stability AI, AssemblyAI, OpenRouter, Pinecone. Payments: Razorpay, Coinbase Commerce, Klarna. External Apps: Discord (Bot), Google Drive, Dropbox, GitHub, Calendly, Figma. Each entry's `docs` URL points at the authoritative auth page; fields match what those docs say is needed for a working authenticated API call.
- **AI Tools section** populated with all the providers from the prior demo grid: Anthropic, OpenAI, Google AI, xAI, Mistral, DeepSeek, Perplexity, Cohere, Groq, ElevenLabs, Hugging Face, Ollama.
- **Payment Gateways section** populated with PayPal, Plaid, Braintree, Adyen, Mollie, Authorize.Net.
- **External Apps section** populated with Notion, Airtable, Slack, Linear, Asana, monday.com, Trello, Zapier.

## [1.3.0] — 2026-06-03

### Added
- **Live credential validation on Save.** Saving a connector now hits the real API with the entered credentials before persisting. "Connected" actually means "we just proved it works," not "we wrote it to wp_options." Validation failures keep the form open and surface the real error from the provider (e.g. "Stripe rejected the Secret Key (401). It may be revoked."). Built-in validators ship for Printful (OAuth client_credentials), Printify (`GET /v1/shops.json`), Stripe (`GET /v1/balance`), Mailchimp (`/3.0/ping` against the auto-parsed datacenter), and Shopify (`GET /admin/api/{ver}/shop.json`). Connectors without a registered validator fall through to "Saved, no live validation yet" — same as before, just labelled honestly. New file: `includes/validators.php`. Dispatch via `nexus_validate_connector( $id, $config )`.

### Changed
- **Connector card foot — single button.** The Edit/Disconnect/Connect three-way is gone. Cards now show ONE button: `Connect` (primary) when not configured, `Disconnect` (destructive) when configured. To re-enter credentials, disconnect and reconnect. JS swaps the button in place after Save / Disconnect so the page doesn't have to reload.
- **Custom connectors** retain their `Edit definition` + `Remove` affordances (those are about the field shape, not the saved creds) alongside the new Connect/Disconnect.
- **Status pill** distinguishes live-validated from unvalidated. Connectors with a validator show "Connected" (green) on success. Connectors without one show "Saved" (gray) so the green pill never lies.

### Fixed
- (Carried from 1.2.2) Save no longer leaves the form stuck open with the toggle still labelled "Cancel"; password fields are re-masked; Disconnect button is injected without a reload.

## [1.2.2] — 2026-06-03

### Fixed
- **Connector save left the card stuck in edit mode.** After a successful Save on a connector card, the JS only updated the status pill — it didn't close the form, reset the Connect/Edit toggle (still labelled "Cancel"), re-mask the password inputs (the typed secret stayed in DOM plaintext until reload), or reveal the Disconnect button (the server only renders it when the connector was already configured at page-render time). The save handler in `assets/admin.js` now does all five:
  - Hides the form
  - Re-masks every `input[type="password"]` to `••••••••`
  - Flips both `[data-conn-toggle]` buttons' `data-label-open` from "Connect" → "Edit", drops the `th-button-primary` class on the foot button
  - Injects a Disconnect button into the foot if one wasn't rendered server-side

## [1.2.1] — 2026-06-03

### Fixed
- **Fatal on every cold page load in 1.2.0.** `includes/updater.php` declared `class Nexus_Silent_Upgrader_Skin extends WP_Upgrader_Skin` at file scope, but `WP_Upgrader_Skin` lives in `wp-admin/includes/class-wp-upgrader.php` — an admin-only file that isn't autoloaded on regular requests. Parsing the `extends` clause crashed every front-end hit. Moved the skin into its own file (`includes/class-silent-upgrader-skin.php`) and `require_once`'d it lazily from inside `nexus_install_from_package()` AFTER `nexus_load_upgrader_classes()` runs. Upgrade from 1.2.0 strongly recommended.

## [1.2.0] — 2026-06-03

### Added
- **Self-update.** New **Updates** tab under Manage. Pulls the latest release from the configured GitHub repo (`TherumCs/Nexus` by default, filterable via `nexus_update_repo`) or accepts a hand-uploaded plugin zip. Both paths route through WP's `Plugin_Upgrader` with `overwrite_package=true` so saved connectors + credentials survive the swap. Capability-gated on `update_plugins`.
- **1–4 credential fields on custom connectors.** The Add-custom modal now exposes up to four labeled credential rows (label + Secret/Plain type) instead of a hardcoded single API-key field. Row 1 is required; rows 2–4 are skipped when the label is blank. Keys are derived from slugged labels with numeric-suffix dedup so two "Token" rows can coexist. Mirrors real provider auth shapes (OAuth Consumer Key + Secret, SID + Token + Auth Secret + Workspace ID, etc.).

### Changed
- **Built-in connector field defs audited against current vendor docs** and corrected:
  - **Etsy** — full OAuth 2.0 / PKCE tuple: keystring + shared_secret + oauth_access_token + oauth_refresh_token. The previous keystring-only schema would have silently failed every authenticated v3 call.
  - **Printful** — Consumer Key + Consumer Secret (the legacy single API key is deprecated).
  - **Mailchimp** — dropped the redundant `server` field. The datacenter prefix is the suffix of the API key after the dash — parsed, not asked twice.
  - **Stripe** — `publishable_key` demoted from required to optional (only needed for client-side Stripe.js / Elements). Added `stripe_account` for Connect platforms.
  - **Amazon SP-API** — `marketplace_id` promoted to required. Added `region` select (na/eu/fe). Noted the 2023 AWS SigV4 / IAM removal.
  - **Shopify** — updated placeholder to a current API version (2026-04). Doc link points to the Dev Dashboard custom-app flow (legacy in-Admin path deprecated Jan 2026).
  - **Drupal** — split the ambiguous "Password / Token" field into `password` (Basic Auth) + `bearer_token` (Simple OAuth) so users know which auth mode they're in.
  - **Ghost** — added `api_version`; clarified the Content vs Admin key roles in placeholders.
  - **Webflow / Contentful / Square / Printify** — updated placeholders, doc links, and added missing optional fields (Contentful's `preview_token`, Square's `api_version`, etc.).

### Notes
- The `multi` auth shape (1–4 fields) is symmetric with the addition shipped to Therum OS 1.9.16's `Therum_Connections_Page` mu-plugin — same modal idea, same storage shape under `auth_label{2..4}` / `key{3,4}`.

## [1.1.0] — 2026-06-02

### Added
- **Custom connectors.** New "＋ Add custom" button in the CMS, Ecommerce, and APIs tabs (the registry-backed categories) opens a modal that captures name, slug, brand color, optional URL, and a typed credential. Custom connectors are stored in the `nexus_custom_connectors` option (separate from built-ins) and merged into `nexus_connector_registry()` at read time with a `custom => true` flag.
- Edit + Delete affordances on every custom connector card. Edit reuses the modal pre-filled; Delete removes the registry entry plus any saved credential (`nexus_connector_{slug}` option).
- Built-in shadow guard — slugs that collide with a built-in connector id are rejected with a friendly error.
- Rename support — changing a slug while editing moves the saved credential to the new id.
- AJAX surface: `wp_ajax_nexus_custom_add`, `wp_ajax_nexus_custom_delete`. Both `manage_options` + nonce-checked.

### Notes
- The AI / Payments / External Apps demo tabs still show fixture data; only the registry-backed categories (CMS / Ecommerce / APIs) accept custom additions in 1.1.0.
- Mirrors the Add-custom flow that shipped in Therum OS 1.9.15's Connections page so the standalone-plugin variant stays at feature parity.

## [1.0.0] — 2026-05-22

Initial release.

### Connectors
- **CMS:** WordPress (built-in), Drupal, Ghost, Webflow, Contentful
- **Ecommerce:** WooCommerce (built-in), Shopify, Square, BigCommerce
- **APIs:** REST endpoint, GraphQL endpoint, custom webhook
- **AI Tools / Payments / External Apps:** demo card grids (fixtures pending real backends)

### Surface
- Tile-grid admin UI per category with per-connector config and saved credentials
- Password fields treat the `••••••••` placeholder as "no change" on save so re-saving a card without re-entering the secret preserves it
