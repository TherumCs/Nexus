# Nexus by Therum — Changelog

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
