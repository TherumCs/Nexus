# Nexus by Therum — Changelog

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
