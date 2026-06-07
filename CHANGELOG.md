# Nexus by Therum — Changelog

## [2.4.1] — 2026-06-07

Ripped out the OAuth Hub tab and nudge banner. Sign-in is two paths only, no settings ceremony:

1. **Click "Sign in with X"** → popup opens to the provider's login (notion.com, google.com, dropbox.com, …) → authorize → connected. Default path. Zero setup.
2. **"Use your own OAuth app (advanced)"** — collapsed section on each card. Open it only if you want to use your own developer app instead of Therum's hosted one. Paste Client ID + Secret. Done.

### Changed
- Hosted mode is the unconditional default. Proxy URL hardcoded to `https://oauth.therum.studio`; per-site HMAC secret auto-generated and stored silently on first OAuth attempt. No toggle, no settings tab.
- Connector card OAuth section rewritten: big primary "Sign in with X" button on top, collapsed "Use your own OAuth app" `<details>` underneath that auto-expands if BYOA creds are already saved.
- BYOA takes runtime precedence over hosted — if Client ID + Secret are filled on the card, the popup uses those; otherwise it uses the hosted proxy. User never picks which.

### Removed
- `Manage → OAuth Hub` tab and its renderer/AJAX handlers.
- Top-of-admin nudge banner.
- `data-nexus-oauth-needs-setup` gating (the button always opens the popup now).

### For self-hosters
- `NEXUS_OAUTH_PROXY_URL` and `NEXUS_OAUTH_PROXY_SHARED_SECRET` constants in wp-config.php still override the defaults if you're running your own proxy.

## [2.4.0] — 2026-06-07

You no longer need to register a developer OAuth app at Google/Notion/Slack/Stripe/etc. to use "Sign in with X" — flip one toggle in the new OAuth Hub tab and every OAuth connector signs in with one click through Therum's hosted proxy.

### Added — OAuth Hub settings tab (Manage → OAuth Hub)
- **One toggle** flips hosted OAuth on across all 20 OAuth-capable connectors. No per-provider Client ID / Secret required.
- **Proxy URL pre-filled** with `https://oauth.therum.studio` (Therum's hosted Cloudflare Worker endpoint). Override only if you self-host.
- **Shared secret auto-generated** on first enable — no user-invented secret, no copy-paste from a password manager. Rotate button on the form.
- **Source indicator** shows whether the active config comes from `wp-config.php` constants or this UI. Constants always win (deployment-locked).
- **How-it-works panel** explaining the popup → provider → proxy → callback flow.

### Added — one-time nudge banner
- Top of the Nexus admin shows a soft callout — "Want Sign in with X to just work?" → links to the OAuth Hub. Hides for 30 days when dismissed; hides permanently once hosted mode is enabled or constants are detected.

### Changed
- `nexus_oauth_hosted_mode()` now reads from the new `nexus_oauth_hub` option as a fallback when wp-config.php constants aren't defined. Existing constants behavior is unchanged.
- Connector card's OAuth section automatically hides the Client ID / Secret fields when hosted mode is active.

## [2.3.1] — 2026-06-07

Hotfix — "Sign in with X" now opens the provider's sign-in page in a popup, the way Sign in with Google does it. Previously the button either redirected the whole admin page or silently expanded an inline credentials form.

### Changed — OAuth sign-in UX
- **Popup window flow.** Clicking `Sign in with [Provider]` opens a 600×720 centered popup window immediately (in the click handler, so popup-blockers don't fire). The popup shows a "Connecting to [Provider]…" holding page while the server builds the authorize URL, then navigates to the provider's actual sign-in page (e.g. `notion.com/oauth/authorize`).
- **Auto-close on completion.** When the OAuth callback lands back on the Nexus admin page inside the popup, it detects `window.opener` and closes itself after ~600ms. The parent window is polling `window.closed` and reloads to reflect the new connected state.
- **Inline form no longer hijacks the click.** Removed the short-circuit that quietly expanded the credentials form instead of opening the OAuth flow when client_id wasn't yet saved. Instead we always try the OAuth start endpoint — if the server reports missing app credentials, the popup closes and the inline form expands with focus on the OAuth client_id field plus an alert telling the user what to fill in.

## [2.3.0] — 2026-06-06

Meta catalog field completeness pass. Audited Meta Commerce Manager's canonical CSV header against what we were emitting and closed every gap so a Nexus → Meta sync now maps 1:1 instead of leaving 10+ fields blank.

### Added — full Meta catalog field coverage
- **Per-product overrides expanded from 6 to 19 fields.** New fields: `size_type`, `size_system`, `multipack`, `is_bundle`, `availability_date`, `cost_of_goods_sold`, `adult`, `video_link`, `additional_video_link`, `custom_label_0` through `custom_label_4`.
- **Product meta box rebuilt** as collapsible sections: Identifiers · Category + Condition · Apparel Sizing (Meta) · Bundle + Multipack · Availability + COGS · Video · Custom Labels (ad-set targeting).
- **`product_type` auto-derivation** from the WC category breadcrumb. Walks the deepest term's ancestor chain ("Apparel > Tops > T-Shirts"). Meta/Google use this as the merchant taxonomy for ad-set targeting and on-platform browse, separately from `google_product_category`.
- **`identifier_exists` auto-detection** — emitted as `"no"` when neither GTIN nor MPN is present (handmade/custom/vintage). Now lands in both CSV and XML; previously XML-only.
- **`cost_of_goods_sold` auto-detect** from the WC Cost of Goods Sold plugin meta (`_wc_cog_cost`) plus common fallbacks (`_cogs_cost`, `_cost`, `_cost_price`). Per-product override always wins. Drives Meta + Google profit-based bidding.

### Added — preorder + backorder availability
- New `availability` states: `preorder` (out of stock + explicit `availability_date` set) and `backorder` (out of stock + WC backorders enabled).
- Per-channel wire format: Meta/Pinterest/TikTok use `"available for order"`; Google/Bing use `"backorder"`. Meta accepts `"preorder"` directly.
- `availability_date` (ISO-8601) emitted whenever set so Meta shows the release-date badge.

### Added — Meta CSV header expansion
- `meta-catalog` CSV now includes: `availability_date`, `identifier_exists`, `product_type`, `size_type`, `size_system`, `multipack`, `is_bundle`, `cost_of_goods_sold`, `adult`, `video[url]`, `additional_video_link`, `custom_label_0`–`custom_label_4`.
- Pinterest, TikTok, Bing CSVs each get the subset they support (size_type/size_system/multipack/is_bundle/adult/video_link as applicable).

### Added — Google Shopping XML field expansion
- New `<g:>` elements emitted when present: `availability_date`, `product_type`, `size_type`, `size_system`, `multipack`, `is_bundle`, `cost_of_goods_sold`, `adult`, `video_link`, `additional_video_link` (split on comma → one element per URL), `custom_label_0`–`custom_label_4`.
- `<g:availability>` now goes through the per-channel formatter (was previously emitting the raw `in_stock`/`out_of_stock` token — Google accepts both forms, but this now picks up `preorder` and `backorder` correctly).

### Changed
- `save_post_product` handler now walks the full `NEXUS_FEED_OVERRIDE_KEYS` map by table instead of a hardcoded 6-key list, so any future override field automatically persists without code changes.

## [2.2.0] — 2026-06-04

Round 3 on Channels. This is the round meant to put us decisively past CTX Feed Pro on the things merchants actually hit pain on.

### Added — WC category → Google taxonomy mapping
- **Collapsible mapping editor** above the channel cards. Every WC `product_cat` term gets a row. Pick from the bundled curated Google Product Taxonomy (~200 of the most common entries — apparel, electronics, home & garden, beauty, food, sports, baby, toys, media, office, pets, arts) OR paste a custom path / numeric ID for anything obscure.
- **Resolution order** for each product's `google_product_category` is now: per-product override → WC category → Google taxonomy mapping → channel-level default. Most specific wins.
- **Walks ancestor chain deepest-first** — if a product has both "Apparel" and "Apparel > Tops > T-Shirts" mapped, the T-Shirts mapping wins.
- **New file:** `includes/google-taxonomy.php` ships the curated taxonomy.

### Added — per-channel rules engine
- **Conditional field overrides** per channel. Rules shape: `{ when: { field, op, value }, then: { field, value } }`. Operators: `is`, `contains`, `not_in`, `empty`, `not_empty`.
- Example: "If `google_product_category` contains 'Apparel', set `gender` to 'unisex'."
- Applied in the normalize pipeline after per-product overrides.

### Added — description templates
- **Placeholder substitution** for the description field per channel. Available: `{{ title }}`, `{{ short_description }}`, `{{ description }}`, `{{ price }}`, `{{ brand }}`, `{{ sku }}`, `{{ category }}`.
- Empty template = current behavior (raw description). Set a template like `{{ title }} — {{ short_description }}` to compose richer descriptions without per-product editing.

### Added — feed fetch analytics
- **Tracks every poll** of each feed URL: `fetch_count`, `last_fetched_at`, `last_user_agent`. Surfaced on each channel card.
- Catches silent failure modes ("Google hasn't fetched in 3 days — why?") and lets you confirm submissions are working.

### Added — bulk feed control on Products list
- **"In feeds" column** on `wp-admin/edit.php?post_type=product`. Shows ✓ included or ✗ excluded per product, at a glance.
- **Bulk actions** "Exclude from Nexus feeds" / "Include in Nexus feeds" — flip many products at once.
- Cache auto-invalidates on bulk update.

### Added — scheduled pre-warm cron
- **Hourly cron** pre-renders every enabled channel into the cache so the channel's scheduled fetch never hits a cold cache. Audit-logged as `feed.prewarmed`.

### Added — Walmart Marketplace channel
- CSV format, shared schema with Google Shopping plus Walmart-specific extensions accepted. Total channels: 7 → 8.

### Notes
- Bundled taxonomy is ~200 entries vs Google's 5,500 — covers ~80% of common stores. The custom-paste fallback handles the rest. Re-sync the bundled list annually.
- Rules engine intentionally simple — no nested conditions, no AND/OR groups. If you need that, write a custom hook on `nexus_feed_apply_rules` filter (Phase 4).
- Pre-warm runs hourly by default; can be changed via the `nexus_queue_recurring` interval if it's too aggressive for very large catalogs.

## [2.1.0] — 2026-06-04

Round 2 on the Channels (product feed) system. Five additions targeting the real pain — products getting rejected, no per-product control, expensive regeneration, missing channels.

### Added — per-product overrides
- **Nexus product feed meta box** on every WC product edit screen (sidebar, "default" priority). Override `brand`, `gtin`, `mpn`, `google_product_category`, `condition`, or set "exclude from all feeds" per product. Overrides win over every auto-detect chain in the normalizer.
- **Storage:** individual `_nexus_feed_*` post-meta keys (single-row reads, no JSON blobs).
- **Uninstall** cleans up `_nexus_feed_%` post-meta so a reinstall starts fresh.

### Added — channel-level product filters
Every channel now supports:
- `featured_only` — ship only WC's featured-flagged products
- `require_image` — drop items without a featured image (channels reject these anyway)
- `min_price` — drop items under a floor (useful for sale-only catalogs, free-shipping minimums)
- `exclude_categories` — comma-separated WC `product_cat` IDs to skip

All apply BEFORE the renderer runs, so they don't bloat the validation output or the cached feed.

### Added — pre-flight feed validation
- **"Validate" button** on each channel card. Runs the full collection pipeline server-side, then checks every item against the channel's required-field list (id, title, description, link, image_link, price, availability, brand). Reports counts of valid / invalid items + lists the first 50 failures with which fields each is missing.
- **Catches rejected listings BEFORE submitting to Google/Meta.** Surfaces what to fix.
- **New AJAX endpoint** `nexus_feed_validate`.

### Added — filesystem cache with auto-invalidation
- **5-minute disk cache** on rendered feeds (`wp-content/uploads/nexus-feeds-cache/<channel>.cache`). REST serve checks cache first, regenerates on miss. Hit/miss surfaced via `X-Nexus-Cache: hit|miss` header.
- **Auto-invalidates** on every `save_post_product`, product `delete_post`, `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`. So a product update propagates to the next feed fetch within seconds — no stale data.
- **Also invalidated** by the per-product meta box on save.
- **Folder htaccess-protected** from direct browsing.
- **Uninstall** cleans up the cache directory.

### Added — two more channels
- **Snapchat Catalog** (CSV) — Snapchat Ads dynamic product catalog, same schema family as Meta/Pinterest.
- **Klaviyo Catalog** (CSV) — Klaviyo product catalog, powers product recommendations + abandoned-cart emails. High value for email marketing.

Total channels: 5 → 7.

### Notes
- Per-product overrides apply to BOTH simple products and variations. For variations, the override is read off the parent product (variations don't have their own post-meta UI in WC).
- Cache is per-channel, not per-token — if multiple channels share the same data shape, each still gets its own cache entry. Could be optimized in a future pass.
- Validation is "what would the channel reject" — not "is everything optimal." A product can pass validation and still underperform if descriptions are weak, images low-res, etc.

## [2.0.0] — 2026-06-04

Hardening pass against the team coding standards. No new features — every change is performance, correctness, or security on existing code. Major version bump because three observable behaviors changed (pagination cuts default page size, HTTPS now enforced on secret-bearing URL fields, webhook receiver rate-limits at 240/min/connector).

### Performance
- **Vault tab N+1 → 1 query.** Was iterating `nexus_connector_registry()` and calling `nexus_get_connector()` per id — ~85 separate `wp_options` reads on a full registry. Now uses a new `nexus_get_all_connectors()` bulk fetch (single SQL).
- **Sidebar count summary** uses the same bulk fetch — was the second-biggest N+1 in admin page render.
- **Audit log paginated** at 50 rows/page (was up to 200 in one render). New `nexus_audit_page()` + `nexus_audit_count_for_filter()` helpers; prev/next links in the tab footer.
- **Webhook log paginated** at 100 rows/page (was up to 500 in one render). Same pattern.

### Correctness — silent failures now logged
- **`@unlink` on backup pruning** dropped — uses non-silenced `unlink()` and routes failures through `error_log()` + `nexus_audit_log('backup.delete_failed')` so we actually find out when the disk's full or a file's locked.
- **Webhook log inserts** wrapped in `nexus_webhook_insert_logged()` — `$wpdb->insert` returning false now writes an `webhook.log_insert_failed` event instead of swallowing the row.
- **Replay path** uses the same wrapper.

### Security
- **HTTPS enforcement on secret-bearing URL fields.** Save handler refuses `http://` URLs for `webhook_url`, `server_url`, `base_url`, `site_url`, `store_url`, `admin_url` fields when the host isn't local (`localhost`, `*.local`, `*.test`). Refusal returns a precise message about the field + host. Local sites still work.
- **Inbound webhook rate limit.** New `nexus_webhook_under_rate_limit()` drops bursts at `NEXUS_WEBHOOK_RATE_LIMIT_PER_MIN` (default 240) per connector per minute. Returns HTTP 429. Defends against URL discovery / misbehaving providers / malicious spam.

### Maintainability — single source of truth
- **Webhook-providers list deduplicated.** The hardcoded `[ 'stripe', 'shopify', 'slack', 'github', 'paypal', 'coinbase-commerce', 'anypay', 'klarna' ]` was repeated in three places. Now centralized in `nexus_webhook_providers()`. The verifier dispatch, the admin docs tab, and the inline card URL hint all read from the same source.

### Notes
- **Major version bump** reflects observable behavior change (pagination + HTTPS gate + rate limit), not breaking API change for downstream consumers. The public API surface (`nexus_connector_registry()` / `nexus_connector_is_configured()` / `nexus_get_connector()` / etc.) is unchanged.
- New constants worth knowing: `NEXUS_AUDIT_PAGE_SIZE` (50), `NEXUS_WEBHOOK_LOG_PAGE_SIZE` (100), `NEXUS_WEBHOOK_RATE_LIMIT_PER_MIN` (240).
- **Not in this pass** (flagged but deferred — would need test coverage to do safely): splitting `validators.php` into per-category files, splitting `admin-page.php` card renderer, PHPUnit harness, CI workflow. All standards-aligned debt, all low-risk to defer.

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
