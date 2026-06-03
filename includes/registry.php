<?php
/**
 * Nexus by Therum — Connector registry + persistence + status.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  CONNECTOR REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

function nexus_connector_registry(): array {
	$registry = nexus_connector_registry_builtin();
	// Fold in user-added custom connectors. Each carries `custom => true`
	// so the UI can show Edit/Delete affordances and route the right AJAX.
	foreach ( nexus_get_custom_connectors() as $slug => $row ) {
		if ( ! is_array( $row ) ) continue;
		$cat = $row['category'] ?? '';
		if ( ! in_array( $cat, NEXUS_CUSTOM_CATEGORIES, true ) ) continue;
		$row['id']       = $slug;
		$row['custom']   = true;
		$row['built_in'] = false;
		$registry[ $slug ] = $row;
	}
	return $registry;
}

function nexus_connector_registry_builtin(): array {
	$registry = [

		// ── CMS ──────────────────────────────────────────────────────────────
		'wordpress' => [
			'id'       => 'wordpress',
			'name'     => 'WordPress',
			'category' => 'cms',
			'color'    => '#21759B',
			'initial'  => 'WP',
			'desc'     => 'This site. Access the REST API and manage application passwords.',
			'built_in' => true,
			'fields'   => [],
			'docs'     => 'https://developer.wordpress.org/rest-api/',
		],
		'drupal' => [
			'id'       => 'drupal',
			'name'     => 'Drupal',
			'category' => 'cms',
			'color'    => '#0678BE',
			'initial'  => 'Dr',
			'desc'     => 'Connect a Drupal 9/10 site via JSON:API. Basic Auth (core) or OAuth bearer (Simple OAuth module).',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'site_url',      'label' => 'Site URL',          'type' => 'url',      'placeholder' => 'https://yoursite.com',                            'required' => true ],
				[ 'key' => 'username',      'label' => 'Username',          'type' => 'text',     'placeholder' => 'For Basic Auth — leave blank if using token',     'required' => false ],
				[ 'key' => 'password',      'label' => 'Password',          'type' => 'password', 'placeholder' => 'Basic Auth password or app password',             'required' => false ],
				[ 'key' => 'bearer_token',  'label' => 'OAuth Bearer Token','type' => 'password', 'placeholder' => 'eyJ… — from Simple OAuth module (alternative)',   'required' => false ],
				[ 'key' => 'api_path',      'label' => 'JSON:API base path','type' => 'text',     'placeholder' => '/jsonapi',                                        'required' => false ],
			],
			'docs' => 'https://www.drupal.org/docs/core-modules-and-themes/core-modules/jsonapi-module',
		],
		'ghost' => [
			'id'       => 'ghost',
			'name'     => 'Ghost',
			'category' => 'cms',
			'color'    => '#15171A',
			'initial'  => 'Gh',
			'desc'     => 'Pull or push Ghost content. Use Content API for reads, Admin API for writes — provide at least one.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'admin_url',   'label' => 'Ghost URL',         'type' => 'url',      'placeholder' => 'https://yourblog.ghost.io',                  'required' => true ],
				[ 'key' => 'content_key', 'label' => 'Content API Key',   'type' => 'password', 'placeholder' => 'Public read key from a Custom Integration',  'required' => false ],
				[ 'key' => 'admin_key',   'label' => 'Admin API Key',     'type' => 'password', 'placeholder' => 'id:secret — used to sign JWTs server-side',  'required' => false ],
				[ 'key' => 'api_version', 'label' => 'API Version',       'type' => 'text',     'placeholder' => 'v5.0',                                       'required' => false ],
			],
			'docs' => 'https://ghost.org/docs/admin-api/#token-authentication',
		],
		'webflow' => [
			'id'       => 'webflow',
			'name'     => 'Webflow',
			'category' => 'cms',
			'color'    => '#4353FF',
			'initial'  => 'Wf',
			'desc'     => 'Read Webflow CMS collections and publish via the Data API v2.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_token', 'label' => 'Site API Token', 'type' => 'password', 'placeholder' => 'Site Settings → Apps & integrations → API access', 'required' => true ],
				[ 'key' => 'site_id',   'label' => 'Site ID',        'type' => 'text',     'placeholder' => 'Auto-discoverable via GET /v2/sites',             'required' => false ],
			],
			'docs' => 'https://developers.webflow.com/data/reference/authentication',
		],
		'contentful' => [
			'id'       => 'contentful',
			'name'     => 'Contentful',
			'category' => 'cms',
			'color'    => '#2478CC',
			'initial'  => 'Cf',
			'desc'     => 'Pull structured content from Contentful. Delivery (read) + optional Management (write) + Preview (drafts) tokens.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'space_id',         'label' => 'Space ID',              'type' => 'text',     'placeholder' => 'e.g. cfexampleapi',          'required' => true ],
				[ 'key' => 'delivery_token',   'label' => 'Delivery API Token',    'type' => 'password', 'placeholder' => 'CDA — read published',       'required' => true ],
				[ 'key' => 'environment',      'label' => 'Environment ID',        'type' => 'text',     'placeholder' => 'master (default)',           'required' => false ],
				[ 'key' => 'preview_token',    'label' => 'Preview API Token',     'type' => 'password', 'placeholder' => 'CPA — read drafts',          'required' => false ],
				[ 'key' => 'management_token', 'label' => 'Management API Token',  'type' => 'password', 'placeholder' => 'CFPAT-… — write/structure',  'required' => false ],
			],
			'docs' => 'https://www.contentful.com/developers/docs/references/authentication/',
		],

		// ── Ecommerce ────────────────────────────────────────────────────────
		'woocommerce' => [
			'id'       => 'woocommerce',
			'name'     => 'WooCommerce',
			'category' => 'ecommerce',
			'color'    => '#7F54B3',
			'initial'  => 'WC',
			'desc'     => 'Active on this install. Manage products, orders, and settings.',
			'built_in' => true,
			'fields'   => [],
			'docs'     => 'https://woocommerce.com/document/woocommerce-rest-api/',
		],
		'shopify' => [
			'id'       => 'shopify',
			'name'     => 'Shopify',
			'category' => 'ecommerce',
			'color'    => '#96BF48',
			'initial'  => 'Sh',
			'desc'     => 'Sync products, orders, and customers. New custom apps go through the Dev Dashboard (legacy in-Admin path deprecated Jan 2026).',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'store_domain', 'label' => 'Store Domain',            'type' => 'text',     'placeholder' => 'my-store.myshopify.com',                  'required' => true ],
				[ 'key' => 'access_token', 'label' => 'Admin API Access Token',  'type' => 'password', 'placeholder' => 'shpat_… — from Dev Dashboard custom app',  'required' => true ],
				[ 'key' => 'api_version',  'label' => 'API Version',             'type' => 'text',     'placeholder' => '2026-04 (bump quarterly)',                'required' => false ],
			],
			'docs' => 'https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/generate-app-access-tokens-admin',
		],
		'amazon' => [
			'id'       => 'amazon',
			'name'     => 'Amazon Seller',
			'category' => 'ecommerce',
			'color'    => '#FF9900',
			'initial'  => 'Az',
			'desc'     => 'Amazon SP-API via LWA refresh-token grant. AWS SigV4 / IAM is no longer required (removed 2023).',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'client_id',      'label' => 'LWA Client ID',      'type' => 'password', 'placeholder' => 'amzn1.application-oa2-client.…',          'required' => true ],
				[ 'key' => 'client_secret',  'label' => 'LWA Client Secret',  'type' => 'password', 'placeholder' => 'amzn1.oa2-cs.… — rotate every 180 days',  'required' => true ],
				[ 'key' => 'refresh_token',  'label' => 'Refresh Token',      'type' => 'password', 'placeholder' => 'Atzr|…',                                  'required' => true ],
				[ 'key' => 'marketplace_id', 'label' => 'Marketplace ID',     'type' => 'text',     'placeholder' => 'ATVPDKIKX0DER (US)',                      'required' => true ],
				[ 'key' => 'region',         'label' => 'SP-API Region',      'type' => 'select',
					'options' => [ 'na' => 'North America', 'eu' => 'Europe', 'fe' => 'Far East' ],
					'required' => false ],
			],
			'docs' => 'https://developer-docs.amazon.com/sp-api/docs/connecting-to-the-selling-partner-api',
		],
		'etsy' => [
			'id'       => 'etsy',
			'name'     => 'Etsy',
			'category' => 'ecommerce',
			'color'    => '#F56400',
			'initial'  => 'Et',
			'desc'     => 'Etsy Open API v3 — OAuth 2.0 with PKCE. Every authenticated call needs x-api-key + bearer access token.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'keystring',           'label' => 'App API Keystring',      'type' => 'password', 'placeholder' => 'x-api-key — app key from Developer Portal',  'required' => true ],
				[ 'key' => 'shared_secret',       'label' => 'Shared Secret',          'type' => 'password', 'placeholder' => 'Used during the OAuth token exchange',       'required' => true ],
				[ 'key' => 'oauth_access_token',  'label' => 'OAuth Access Token',     'type' => 'password', 'placeholder' => 'From PKCE flow — expires in 1 hour',         'required' => true ],
				[ 'key' => 'oauth_refresh_token', 'label' => 'OAuth Refresh Token',    'type' => 'password', 'placeholder' => 'Used to renew access token (rotates on use)','required' => true ],
				[ 'key' => 'shop_id',             'label' => 'Shop ID',                'type' => 'text',     'placeholder' => 'Numeric shop ID',                            'required' => false ],
			],
			'docs' => 'https://developer.etsy.com/documentation/essentials/authentication/',
		],
		'square' => [
			'id'       => 'square',
			'name'     => 'Square',
			'category' => 'ecommerce',
			'color'    => '#3E4348',
			'initial'  => 'Sq',
			'desc'     => 'Access Square catalog, orders, and payments via the Connect API. Sandbox uses a separate base URL and a separate access token.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'access_token', 'label' => 'Access Token',         'type' => 'password', 'placeholder' => 'EAAA… (production) or EAAAEO… (sandbox)',  'required' => true ],
				[ 'key' => 'location_id',  'label' => 'Location ID',          'type' => 'text',     'placeholder' => 'Required by Payments/Orders endpoints',     'required' => false ],
				[ 'key' => 'api_version',  'label' => 'Square-Version',       'type' => 'text',     'placeholder' => '2026-05-21 (pin for forward-compat)',       'required' => false ],
				[ 'key' => 'sandbox',      'label' => 'Use Sandbox',          'type' => 'checkbox', 'placeholder' => 'Routes calls to connect.squareupsandbox.com', 'required' => false ],
			],
			'docs' => 'https://developer.squareup.com/docs/build-basics/access-tokens',
		],

		// ── APIs ─────────────────────────────────────────────────────────────
		'printful' => [
			'id'       => 'printful',
			'name'     => 'Printful',
			'category' => 'apis',
			'color'    => '#FFD100',
			'initial'  => 'Pf',
			'desc'     => 'Print-on-demand fulfillment. Sync products and orders automatically.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'consumer_key',    'label' => 'Consumer Key',    'type' => 'password', 'placeholder' => 'ck_…',                          'required' => true ],
				[ 'key' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password', 'placeholder' => 'cs_…',                          'required' => true ],
				[ 'key' => 'store_id',        'label' => 'Store ID',        'type' => 'text',     'placeholder' => 'Optional — Printful store ID',  'required' => false ],
			],
			'docs' => 'https://developers.printful.com/docs/',
		],
		'printify' => [
			'id'       => 'printify',
			'name'     => 'Printify',
			'category' => 'apis',
			'color'    => '#28A9E1',
			'initial'  => 'Py',
			'desc'     => 'Print-on-demand via Printify. Single Personal Access Token (1-year validity, shown only once on creation).',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'access_token', 'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => 'My account → Connections — bearer token',  'required' => true ],
				[ 'key' => 'shop_id',      'label' => 'Shop ID',               'type' => 'text',     'placeholder' => 'Auto-discoverable via GET /v1/shops.json', 'required' => false ],
			],
			'docs' => 'https://developers.printify.com/#authentication',
		],
		'pod-partner' => [
			'id'       => 'pod-partner',
			'name'     => 'Pod Partner',
			'category' => 'apis',
			'color'    => '#E63946',
			'initial'  => 'PP',
			'desc'     => 'Connect your Pod Partner store for fulfillment and catalog sync.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'store_url', 'label' => 'Store URL', 'type' => 'url',      'placeholder' => 'https://yourstore.podpartner.com', 'required' => true ],
				[ 'key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'placeholder' => '••••••••',                         'required' => true ],
			],
			'docs' => '',
		],
		'stripe' => [
			'id'       => 'stripe',
			'name'     => 'Stripe',
			'category' => 'apis',
			'color'    => '#635BFF',
			'initial'  => 'St',
			'desc'     => 'Payments, subscriptions, invoicing. Only the secret key is needed for API calls — publishable / webhook secrets are situational.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'secret_key',      'label' => 'Secret Key',         'type' => 'password', 'placeholder' => 'sk_live_… or sk_test_… (test/live encoded in prefix)', 'required' => true ],
				[ 'key' => 'publishable_key', 'label' => 'Publishable Key',    'type' => 'text',     'placeholder' => 'pk_… — only needed for Stripe.js / Elements / Checkout', 'required' => false ],
				[ 'key' => 'webhook_secret',  'label' => 'Webhook Signing Secret','type' => 'password', 'placeholder' => 'whsec_… — only needed if receiving webhooks',          'required' => false ],
				[ 'key' => 'stripe_account',  'label' => 'Connect Account ID', 'type' => 'text',     'placeholder' => 'acct_… — only for Stripe Connect platforms',           'required' => false ],
			],
			'docs' => 'https://docs.stripe.com/keys',
		],
		'mailchimp' => [
			'id'       => 'mailchimp',
			'name'     => 'Mailchimp',
			'category' => 'apis',
			'color'    => '#FFE01B',
			'initial'  => 'Mc',
			'desc'     => 'Sync subscribers and trigger automations. The datacenter prefix (e.g. us6) is the suffix of the API key — parsed automatically.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_key', 'label' => 'API Key',     'type' => 'password', 'placeholder' => '0123456789abcdef0123456789abcde-us6', 'required' => true ],
				[ 'key' => 'list_id', 'label' => 'Audience ID', 'type' => 'text',     'placeholder' => 'Optional default audience',            'required' => false ],
			],
			'docs' => 'https://mailchimp.com/developer/marketing/docs/fundamentals/',
		],
		'custom-api' => [
			'id'       => 'custom-api',
			'name'     => 'Custom API',
			'category' => 'apis',
			'color'    => '#6B7280',
			'initial'  => '+',
			'desc'     => 'Any REST endpoint — add a base URL, auth method, and optional headers.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'name',         'label' => 'Connection name', 'type' => 'text',     'placeholder' => 'My API',                     'required' => true ],
				[ 'key' => 'base_url',     'label' => 'Base URL',        'type' => 'url',      'placeholder' => 'https://api.example.com/v1', 'required' => true ],
				[ 'key' => 'auth_type',    'label' => 'Auth type',       'type' => 'select',
					'options' => [ 'bearer' => 'Bearer Token', 'api-key' => 'API Key Header', 'basic' => 'Basic Auth', 'none' => 'None' ],
					'required' => false ],
				[ 'key' => 'auth_value',   'label' => 'Auth value',      'type' => 'password', 'placeholder' => 'Token or key',               'required' => false ],
				[ 'key' => 'extra_header', 'label' => 'Extra header',    'type' => 'text',     'placeholder' => 'X-Header: value',            'required' => false ],
			],
			'docs' => '',
		],
	];

	return apply_filters( 'nexus_connector_registry', $registry );
}

function nexus_connectors_by_category( string $cat ): array {
	return array_filter( nexus_connector_registry(), fn( $c ) => $c['category'] === $cat );
}


// ═════════════════════════════════════════════════════════════════════════════
//  CUSTOM (USER-DEFINED) CONNECTORS
//
//  Stored in a separate option keyed by slug; merged into the registry above
//  when nexus_connector_registry() runs. Custom connectors carry the same
//  shape as built-ins (id/name/category/color/initial/desc/fields) plus a
//  `custom => true` flag so the UI can route Edit + Delete actions.
//
//  Only the registry-backed categories (cms/ecommerce/apis) accept customs
//  in 1.1.0 — the AI/Payments/External Apps tabs are still demo-data.
// ═════════════════════════════════════════════════════════════════════════════

const NEXUS_CUSTOM_CATEGORIES = [ 'cms', 'ecommerce', 'apis' ];

function nexus_get_custom_connectors(): array {
	return (array) get_option( 'nexus_custom_connectors', [] );
}

function nexus_save_custom_connector( string $slug, array $row ): void {
	$all = nexus_get_custom_connectors();
	$all[ $slug ] = $row;
	update_option( 'nexus_custom_connectors', $all, false );
}

function nexus_delete_custom_connector( string $slug ): void {
	$all = nexus_get_custom_connectors();
	unset( $all[ $slug ] );
	update_option( 'nexus_custom_connectors', $all, false );
	// Provider gone → orphan credential goes with it.
	nexus_delete_connector( $slug );
}


// ═════════════════════════════════════════════════════════════════════════════
//  PERSISTENCE
// ═════════════════════════════════════════════════════════════════════════════

function nexus_get_connector( string $id ): array {
	$raw = get_option( 'nexus_connector_' . sanitize_key( $id ), '' );
	if ( ! $raw ) return [];
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : [];
}

function nexus_save_connector( string $id, array $data ): void {
	update_option( 'nexus_connector_' . sanitize_key( $id ), wp_json_encode( $data ), false );
}

function nexus_delete_connector( string $id ): void {
	delete_option( 'nexus_connector_' . sanitize_key( $id ) );
}

function nexus_connector_is_configured( string $id ): bool {
	$data = nexus_get_connector( $id );
	if ( empty( $data['config'] ) ) return false;
	foreach ( $data['config'] as $v ) {
		if ( $v !== '' ) return true;
	}
	return false;
}

function nexus_connector_status( array $connector ): array {
	$id = $connector['id'];

	if ( ! empty( $connector['built_in'] ) ) {
		if ( $id === 'woocommerce' && ! class_exists( 'WooCommerce' ) ) {
			return [ 'label' => 'Not installed', 'class' => 'nexus-status-off' ];
		}
		return [ 'label' => 'Active', 'class' => 'nexus-status-active' ];
	}

	if ( nexus_connector_is_configured( $id ) ) {
		return [ 'label' => 'Connected', 'class' => 'nexus-status-connected' ];
	}
	return [ 'label' => 'Not configured', 'class' => 'nexus-status-off' ];
}
