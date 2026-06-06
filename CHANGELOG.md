# Nexus by Therum — Changelog

## [1.9.1] — 2026-06-04

Three operational hardenings on top of 1.9.0. No new connectors, no new tabs — security + UX polish only.

### Added — AES-256-GCM credential encryption at rest
- **`includes/crypto.php`** — encrypts every password-typed field + OAuth tokens (access_token / refresh_token / client_id / client_secret) with AES-256-GCM keyed on `SECURE_AUTH_KEY` via HMAC derivation. Per-install unique cipher key, no extra config required.
- **Transparent decryption** on read — `nexus_get_connector()` decrypts in-place; consumers never see ciphertext.
- **Backward compatible** — envelope marker `nx1.` distinguishes encrypted from pre-1.9.1 plaintext. Existing plaintext values pass through on first read and get re-encrypted on next save. **No migration script required.** Drop in, edit any connector to trigger re-save, done.
- **Plaintext-readable fields preserved** — only secret-shaped fields are encrypted. Store IDs, URLs, regions stay grep-able in the DB.
- **Rotation impact documented** — rotating `SECURE_AUTH_KEY` invalidates every stored secret. Same trade-off WordPress makes for application passwords and auth cookies. After rotation, users disconnect + reconnect each connector to re-encrypt with the new key.

### Added — webhook URL inline on cards
- Connectors with built-in signature verifiers (Stripe, Shopify, Slack, GitHub, PayPal, Coinbase Commerce, AnyPay, Klarna) now show their dedicated webhook URL inline at the top of the credential form — click-to-copy. Saves the hunt through the API & Webhooks tab.
- Per-connector URL with the connector ID baked in (e.g. `…/wp-json/nexus/v1/webhook/stripe`).

### Changed — `uninstall.php` actually cleans up
Was only deleting `nexus_connector_*` options. Now drops:
- All `nexus_connector_*`, `nexus_feed_*`, `nexus_custom_connectors`, schema-version trackers, GitHub release caches
- All `_transient_nexus_*` cached lookups
- Custom tables `wp_nexus_audit_log` and `wp_nexus_webhook_log`
- Backup zips in `wp-content/uploads/nexus-backups/` + the folder itself
- Action Scheduler jobs in the `nexus` group + wp_cron fallback schedules

Deactivation still leaves everything intact (non-destructive). Only "Delete plugin" triggers the cleanup.

### Notes
- Skipped Action Scheduler bundling (would add ~3 MB of vendor files). For high-volume sites without WooCommerce installed, the standalone Action Scheduler plugin from wp.org delivers the same benefit with zero code change.
- Encryption applies on next save. Already-saved connectors stay plaintext in the DB until they're re-saved. To force re-encrypt the whole catalog at once: open each card → Save (form posts back the masked bullets which restore from prior, then re-encrypt). Or just hit Disconnect + Connect.

## [1.9.0] — 2026-06-04

Finishing pass — everything that was queued lands here.

### Added — validators for ~50 more connectors (coverage now 76 / 85)
**CMS:** Drupal, Ghost, Webflow, Contentful, Strapi, Sanity, Storyblok, HubSpot CMS.
**Ecommerce:** Amazon SP-API, Etsy, Square, BigCommerce, Magento, Lemon Squeezy, EDD.
**APIs (email / SMS / search):** Mailgun, Postmark, Brevo, Algolia.
**AI:** Google AI, xAI, Mistral, DeepSeek, Perplexity, Cohere, Groq, ElevenLabs, Hugging Face, Ollama, Together AI, Replicate, Stability AI, AssemblyAI, OpenRouter, Pinecone.
**Payment gateways:** Plaid, Braintree, Adyen, Mollie, Authorize.Net, Razorpay, Coinbase Commerce, Klarna, Affirm, Afterpay, Sezzle, Zip, AnyPay, NOWPayments, BTCPay Server, Cash App Pay.
**Apps:** Dropbox, Google Drive, Trello, Zapier (paste-only — no auth), Discord (Bot), Flow Desk (no public API, accepts without validation).
**Bridge / no-API:** Pod Partner.

Validator pattern is now codified via `_nv_get` / `_nv_post` / `_nv_ok` / `_nv_fail` helpers so adding a 77th is a 5-line copy of any of the above.

### Added — daily background credential health check
- **`nexus_health_check_all_connectors()`** re-runs validation on every configured connector. Failures land in the audit log + flag the connector's saved state (`last_health_check` / `last_health_ok` / `last_health_msg`).
- **Vault tab surfaces health status** next to each row — green "✓ healthy" or red "✗ failed health check Xh ago" with the provider's error in a tooltip.
- **"Run health check now" button** on the vault tab for on-demand sweeps.
- **AJAX endpoint** `nexus_health_check_now` fires the sweep synchronously.
- **Scheduled job** fires once a day via `nexus_health_check_all` action (Action Scheduler when available, wp_cron fallback).

### Added — webhook replay
- **Replay button** on each row in the Webhooks log. Click → re-fires `nexus_webhook_received` with the stored payload + logs the replay as a new row tagged `replay · <original event>`.
- **AJAX endpoint** `nexus_webhook_replay`.

### Added — hosted-OAuth token refresh through the proxy
- `nexus_oauth_refresh()` now checks hosted mode. If `NEXUS_OAUTH_PROXY_URL` is defined, refresh requests go to `<proxy>/v1/refresh/<connector>?site=&refresh_token=&sig=<HMAC>` — proxy holds the app secret and signs the response. Falls back to BYOA refresh otherwise.
- Closes the loop on the hosted OAuth story shipped in 1.6.1: full lifecycle (initial sign-in → token refresh → re-validation) all routes through the proxy when configured.

### Notes
- Validator coverage: **76 of 85 connectors** have live validators. The 9 unvalidated are: bridge-only entries (PODpluser, Tapstitch), the generic Custom API connector, and connectors without a documented auth-check endpoint (Flow Desk, Pod Partner). Each of these returns `unvalidated: true` honestly instead of pretending.
- Replay reuses the original payload verbatim, so receivers must be idempotent. The replay row is marked in the log so it's distinguishable from a fresh inbound.
- Health check default cadence is daily; tune with a filter on `nexus_health_check_interval` if needed (future tunable).

## [1.8.0] — 2026-06-04

Massive turn — finishes the four Manage tabs that have been advertised in the sidebar (with stub data) since v1.0, lands real inbound webhook receivers with per-provider signature verification, adds an audit log + background job queue, and brings the live-validator coverage from 5 connectors up to 22.

### Added — finishing the Manage tabs (the sidebar stops lying)
- **API keys vault** (real). Lists every configured connector with category, auth type (OAuth vs key), updated time, age-based rotation flag (>6 months → warn), jump-to-card link. Three summary tiles up top: total credentials / OAuth-connected count / older-than-6-months count. New file: `includes/vault.php`.
- **Webhooks log** (real). Inbound event stream backed by a custom `wp_nexus_webhook_log` table. Each row: when / source / event / verify status. Three summary tiles: total events / verified-of-last-500 / top sources. Auto-pruned after 30 days. New file: `includes/webhooks.php`.
- **Audit log** (real). Tamper-evident lifecycle log in custom `wp_nexus_audit_log` table. Captures connector.connected / disconnected / failed_validation, backup.created / restored, update.installed / rolled_back, webhook.received, oauth.exchanged / refresh_failed. Filterable by event substring. Auto-pruned after 90 days. New file: `includes/audit.php`.
- **API & Webhooks** (real). Documents the four public REST endpoints Nexus exposes (`/v1/feed/<channel>`, `/v1/webhook/<connector>`, `/v1/oauth-callback/<connector>`, `/v1/oauth-proxy-callback`) plus the per-provider webhook URLs to register at providers' dashboards.

### Added — inbound webhook receiver
- **REST endpoint** at `POST /wp-json/nexus/v1/webhook/<connector>`. Accepts payloads, verifies per-provider signatures, logs the event, fires `nexus_webhook_received` action, queues async processing.
- **Per-provider signature verifiers:** Stripe (`Stripe-Signature` HMAC), Shopify (`X-Shopify-Hmac-Sha256`), Slack (`X-Slack-Signature` with 5-min replay window), GitHub (`X-Hub-Signature-256`), PayPal (webhook_id presence — full verification deferred), Coinbase Commerce (`X-CC-Webhook-Signature`), AnyPay (`X-Anypay-Signature`), Klarna (bearer auth token). Generic webhook URLs without a built-in verifier accept an optional `?token=<shared_secret>` query string for poor-man's auth.
- **Best-effort event name extraction** from the payload (`type` / `event` / `event_type` / `action` JSON fields, or `x-shopify-topic` / `x-github-event` headers).

### Added — background job queue
- **Action Scheduler wrapper** (`includes/queue.php`). Uses Action Scheduler when available (bundled with WooCommerce and most modern WP installs), falls back to `wp_cron` otherwise. Same `nexus_queue_async()` / `nexus_queue_recurring()` API regardless.
- **Auto-pruning jobs** for audit log + webhook log fire once a day.

### Added — 17 new live validators
Bringing the count from **5 → 22** (out of 85 connectors). Each hits the provider's lightest authenticated endpoint and reports a precise error from the provider on failure:
- **AI:** Anthropic, OpenAI
- **Apps:** GitHub, Slack, Notion, Linear, Asana, Calendly, Airtable, Figma, monday.com
- **Payments:** PayPal
- **APIs:** Twilio, SendGrid, Resend, Mapbox, Discord (Webhook)

Validators that accept OAuth tokens (`oauth_access_token` from the OAuth flow) check that field first, so OAuth-connected providers also get validated automatically.

### Changed
- **Connector save / disconnect** now fire `nexus_connector_connected` / `nexus_connector_disconnected` actions. Audit log auto-listens.
- **Stub renderers** in `tab-renderers.php` removed (keys / webhooks / audit) — they were demo fixtures; the new real implementations supersede them.

### Notes
- Custom tables are created lazily on first write via `dbDelta`. No activation hook needed; works for existing installs that upgrade without reactivating.
- ZipArchive PHP extension still required for backup creation (unchanged from 1.7.0); the new audit + webhook log tables only need core MySQL.
- Phase 2 deferred: webhook **replay** from the log (button is currently shell), full PayPal webhook signature verification (currently relies on `webhook_id` presence + secrecy of the URL), and validators for the remaining ~60 connectors.

## [1.7.0] — 2026-06-04

### Added
- **Version history with rollback.** Updates tab now lists the last 30 GitHub releases of `TherumCs/Nexus` with version, publish date, title, link to release notes, and an Install button. The currently-running version is highlighted. Click **Install** on any newer version to upgrade, or **Roll back** on any older version to downgrade. Same Plugin_Upgrader flow; works in either direction.
- **Local backups (auto-snapshot before every install).** Before any install — from GitHub latest, GitHub-specific version, or uploaded zip — Nexus zips the current `nexus/` directory to `wp-content/uploads/nexus-backups/v<version>-<timestamp>.zip`. Keeps the 5 most recent backups, prunes older. Folder is .htaccess-protected from direct browsing.
- **Restore from backup.** New "Local backups" section lists every snapshot with size + age, and a one-click Restore button. Restore is itself reversible — it snapshots the current state first, so you can undo a botched rollback.
- **Manual snapshot button.** "＋ Take snapshot now" for the case "I want to muck around before something risky."
- **New AJAX endpoints**: `nexus_update_install_version`, `nexus_update_restore_backup`, `nexus_update_delete_backup`, `nexus_update_create_backup`.
- **New REST-adjacent helper** `nexus_fetch_releases()` — fetches the full release list with 6h transient cache; complements the existing `nexus_fetch_latest_release()`.

### Notes
- ZipArchive PHP extension is required for backup creation. If missing, the "Take snapshot" button surfaces a clear error and auto-snapshots are skipped (installs still proceed without a safety net — same as previous versions). Standard on every host that supports modern WP.
- Backup files persist across plugin updates (they live in `uploads/`, not `plugins/`), so reinstalling Nexus does NOT wipe your rollback history.
- Backup retention can be tuned via `NEXUS_UPDATE_BACKUP_KEEP_N` constant (default 5). The existing transient cache constants gained a friend: `NEXUS_UPDATE_CACHE_KEY_ALL` for the full-history cache.

## [1.6.1] — 2026-06-04

### Added
- **Hosted-mode OAuth via the Therum proxy.** When `NEXUS_OAUTH_PROXY_URL` + `NEXUS_OAUTH_PROXY_SHARED_SECRET` are defined in `wp-config.php`, the Sign-in flow bypasses BYOA entirely — no per-install OAuth app needed. Sign-in button hits the proxy at `<proxy>/v1/start/<connector>`, proxy runs the dance using its own OAuth app credentials, returns HMAC-signed tokens to a new REST endpoint `/wp-json/nexus/v1/oauth-proxy-callback`. Nexus verifies the HMAC, persists the tokens.
- **OAuth section UI auto-adapts.** In hosted mode, the Client ID/Secret fields and redirect URI panel disappear; the section shows a "hosted by Therum" badge instead. The OAuth Section's call-to-action becomes one click: Sign in.
- **Replay protection.** Proxy-callback rejects payloads older than 5 minutes — even with a valid signature.

### Fixed
- **Sign-in-without-save bug.** Clicking "Sign in with X" inside the form now auto-saves the form first (in the BYOA path) before kicking off OAuth. Previously, if you pasted Client ID + Secret then clicked Sign in without clicking Save, the AJAX endpoint read empty values from the DB and alerted "Set your OAuth Client ID + Secret first." The JS now POSTs `nexus_connector_save` and waits for it before POSTing `nexus_oauth_start`.

### Notes
- The Cloudflare Worker proxy code + deployment docs live in a new sibling project: `therum-oauth-proxy/`. Not part of the Nexus plugin repo; deploy independently. See its README for setup.
- BYOA mode is still fully supported and is the default if `NEXUS_OAUTH_PROXY_URL` is not defined. Existing 1.6.0 installs aren't affected.

## [1.6.0] — 2026-06-04

### Added
- **Sign in with X — OAuth 2.0 for 20 providers.** Every connector that supports OAuth now has a primary "Sign in with [Provider] →" button alongside the existing paste-credentials path. Tokens come back automatically via a REST callback, get stored, and refresh on their own when the provider supports it.
- **Providers wired:** Google Drive · Google AI · GitHub · Slack · Notion · Linear · Asana · Calendly · HubSpot · Stripe (Connect) · Shopify · PayPal · Square · Dropbox · Figma · Airtable (PKCE) · Mailchimp · Etsy (PKCE) · Amazon SP-API · monday.com.
- **Bring-Your-Own-App model.** User creates an OAuth app on the provider's developer console once, registers the Nexus redirect URI (surfaced prominently in the OAuth section, click-to-copy), pastes Client ID + Secret into Nexus. After that, "Sign in" is one click. (Hosted-OAuth-proxy model that would skip the BYOA step is separate infra; not in this release.)
- **PKCE handled** where required (Etsy, Airtable) — verifier generated, S256 challenge sent, verifier round-tripped via transient.
- **Token refresh** — `nexus_oauth_get_token($id)` returns a usable access_token, refreshing automatically when expired (for providers that support refresh — Google, Asana, Calendly, HubSpot, Square, Dropbox, Figma, Airtable, Etsy, Amazon, monday).
- **State / CSRF** protection — random 32-char state token stored in a 10-min transient with the connector ID + user. Callback validates before exchange.
- **Tenant-specific URLs** handled — Shopify (`{store_domain}.myshopify.com`), PayPal (sandbox vs live endpoints) build their authorize/token URLs from the connector's existing config.
- **Per-provider metadata extractors** — Slack returns team info, Notion returns workspace, Stripe returns the connected `acct_…`. Stored in `oauth_meta` for downstream consumers.
- **Callback result banner** — when the user returns from the provider's authorize screen, a one-shot banner at the top of the Nexus admin shows ✓ Connected or ✗ + reason.
- **REST endpoint** `GET /wp-json/nexus/v1/oauth-callback/<connector>` — provider redirects here; we validate state, exchange code for tokens, redirect back to admin.

### Changed
- **Save handler** preserves OAuth tokens across manual edits — editing the paste-creds fields no longer wipes `oauth_access_token` / `oauth_refresh_token` / `oauth_expires_at` / `oauth_meta`.
- **Card foot** — for OAuth-capable connectors, primary button is now "Sign in with X →"; "Configure" expands the form (which now includes the OAuth app fields + the credential paste fallback).
- **Connector status** — OAuth-saved connectors show the normal green "Connected" pill since they have a real token.

### Notes
- Validators (Stripe, Printful, etc.) currently target the paste-creds fields (e.g. `secret_key`). OAuth-saved connectors get marked configured via the callback path, which bypasses validation. Phase 2: teach each validator to accept `oauth_access_token` as an alternative.
- For Therum-hosted OAuth ("Sign in with Google" without each user creating an app), a separate proxy service would need to register one app per provider and forward callbacks. Out of scope here.

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
