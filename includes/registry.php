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
			'desc'     => 'Amazon SP-API via LWA refresh-token grant. AWS SigV4 / IAM is no longer required (removed 2023). Note: the refresh_token only exists after the seller authorizes your app via Seller Central — paste it here once obtained.',
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
			'desc'     => 'Etsy Open API v3 — OAuth 2.0 + PKCE. Bootstrap tokens via Etsy\'s authorization redirect; once obtained, paste them here. Every call sends x-api-key + Bearer.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'keystring',           'label' => 'API Keystring (x-api-key)', 'type' => 'password', 'placeholder' => 'App keystring from Developer Portal',         'required' => true ],
				[ 'key' => 'oauth_access_token',  'label' => 'OAuth Access Token',        'type' => 'password', 'placeholder' => '12345.O1z… — expires in 1 hour',              'required' => true ],
				[ 'key' => 'oauth_refresh_token', 'label' => 'OAuth Refresh Token',       'type' => 'password', 'placeholder' => 'Used to renew access token (rotates on use)', 'required' => true ],
				[ 'key' => 'shop_id',             'label' => 'Shop ID',                   'type' => 'text',     'placeholder' => 'Numeric shop ID (optional)',                  'required' => false ],
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
			'desc'     => 'Print-on-demand fulfillment. Use a Private Token (simplest) OR OAuth Consumer Key + Secret. Provide one auth pair — not both.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'private_token',   'label' => 'Private Token',   'type' => 'password', 'placeholder' => 'Single-token Bearer — Developer Portal → API Tokens',                'required' => false ],
				[ 'key' => 'consumer_key',    'label' => 'Consumer Key',    'type' => 'password', 'placeholder' => 'ck_… — only if using OAuth client credentials instead of a token',   'required' => false ],
				[ 'key' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password', 'placeholder' => 'cs_… — pair with Consumer Key',                                       'required' => false ],
				[ 'key' => 'store_id',        'label' => 'Store ID',        'type' => 'text',     'placeholder' => 'Numeric ID, required when account has multiple stores',              'required' => false ],
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
			'desc'     => 'Pod Partner — print-on-demand fulfillment + catalog sync. API Key + API Secret pair issued from your Pod Partner account.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'placeholder' => 'From your Pod Partner dashboard', 'required' => true ],
				[ 'key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'placeholder' => 'Paired with the API Key',          'required' => true ],
				[ 'key' => 'store_url',  'label' => 'Store URL',  'type' => 'url',      'placeholder' => 'https://yourstore.podpartner.com — optional, only if multi-store', 'required' => false ],
			],
			'docs' => '',
		],
		'podpluser' => [
			'id'          => 'podpluser',
			'name'        => 'PODpluser',
			'category'    => 'apis',
			'color'       => '#5e35b1',
			'initial'     => 'Pl',
			'desc'        => 'Print-on-demand fulfillment. No public developer API — connect via a native 1-click integration on Shopify, Etsy, or another supported marketplace.',
			'built_in'    => false,
			'bridge_only' => true,
			'bridge_via'  => [
				[ 'name' => 'Shopify App',          'url' => 'https://apps.shopify.com/podpluser' ],
				[ 'name' => 'Etsy integration',     'url' => 'https://podpluser.com/integrations/etsy' ],
				[ 'name' => 'All integrations',     'url' => 'https://podpluser.com/integrations' ],
			],
			'fields'      => [],
			'docs'        => 'https://podpluser.com/',
		],
		'tapstitch' => [
			'id'          => 'tapstitch',
			'name'        => 'Tapstitch',
			'category'    => 'apis',
			'color'       => '#111111',
			'initial'     => 'Ts',
			'desc'        => 'Print-on-demand · apparel. No standalone API — Tapstitch connects directly to your Shopify, WooCommerce, Etsy, Squarespace, or Wix store and uses that platform\'s API as the bridge.',
			'built_in'    => false,
			'bridge_only' => true,
			'bridge_via'  => [
				[ 'name' => 'Shopify App',      'url' => 'https://apps.shopify.com/tapstitch' ],
				[ 'name' => 'WooCommerce',      'url' => 'https://tapstitch.com/integrations/woocommerce' ],
				[ 'name' => 'Etsy',             'url' => 'https://tapstitch.com/integrations/etsy' ],
				[ 'name' => 'Squarespace',      'url' => 'https://tapstitch.com/integrations/squarespace' ],
				[ 'name' => 'Wix',              'url' => 'https://tapstitch.com/integrations/wix' ],
			],
			'fields'      => [],
			'docs'        => 'https://tapstitch.com/',
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
				[ 'key' => 'secret_key',      'label' => 'Secret or Restricted Key', 'type' => 'password', 'placeholder' => 'sk_live_… / sk_test_… / rk_live_… (rk_ recommended)',  'required' => true ],
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
			'desc'     => 'Sync subscribers and trigger automations. Datacenter prefix is normally the suffix of the API key (auto-parsed); the override field is only needed for OAuth tokens that don\'t embed it.',
			'built_in' => false,
			'fields'   => [
				[ 'key' => 'api_key',       'label' => 'API Key',                     'type' => 'password', 'placeholder' => '0123456789abcdef0123456789abcde-us6', 'required' => true ],
				[ 'key' => 'server_prefix', 'label' => 'Server Prefix (datacenter)',  'type' => 'text',     'placeholder' => 'us6 — only set to override / OAuth',  'required' => false ],
				[ 'key' => 'list_id',       'label' => 'Audience ID',                 'type' => 'text',     'placeholder' => 'Optional default audience',            'required' => false ],
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


		// ── AI Tools ─────────────────────────────────────────────────────────
		'anthropic' => [
			'id' => 'anthropic', 'name' => 'Anthropic · Claude', 'category' => 'ai',
			'color' => '#cc785c', 'initial' => 'A',
			'desc'  => 'claude-sonnet-4.5 · 200k context · function calling. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'sk-ant-…', 'required' => true ],
			],
			'docs' => 'https://docs.anthropic.com/en/api/getting-started',
		],
		'openai' => [
			'id' => 'openai', 'name' => 'OpenAI · ChatGPT', 'category' => 'ai',
			'color' => '#10a37f', 'initial' => 'O',
			'desc'  => 'gpt-5 · 128k context · vision · code interpreter. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',         'label' => 'API Key',          'type' => 'password', 'placeholder' => 'sk-…',                                              'required' => true ],
				[ 'key' => 'organization_id', 'label' => 'Organization ID',  'type' => 'text',     'placeholder' => 'org-… — optional, only if using a specific org',     'required' => false ],
				[ 'key' => 'project_id',      'label' => 'Project ID',       'type' => 'text',     'placeholder' => 'proj_… — optional, scopes the key to one project',  'required' => false ],
			],
			'docs' => 'https://platform.openai.com/docs/api-reference/authentication',
		],
		'google-ai' => [
			'id' => 'google-ai', 'name' => 'Google AI · Gemini', 'category' => 'ai',
			'color' => '#4285f4', 'initial' => 'G',
			'desc'  => 'gemini-2.5-pro · 2M context · multimodal native. Get a key in AI Studio.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'AIza…', 'required' => true ],
			],
			'docs' => 'https://ai.google.dev/gemini-api/docs/api-key',
		],
		'xai' => [
			'id' => 'xai', 'name' => 'xAI · Grok', 'category' => 'ai',
			'color' => '#000000', 'initial' => 'X',
			'desc'  => 'grok-4 · 256k context · real-time X integration. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'xai-…', 'required' => true ],
			],
			'docs' => 'https://docs.x.ai/docs/overview',
		],
		'mistral' => [
			'id' => 'mistral', 'name' => 'Mistral AI', 'category' => 'ai',
			'color' => '#ff7000', 'initial' => 'M',
			'desc'  => 'mistral-large · open-weight EU host · function calling.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'From console.mistral.ai', 'required' => true ],
			],
			'docs' => 'https://docs.mistral.ai/getting-started/quickstart/',
		],
		'deepseek' => [
			'id' => 'deepseek', 'name' => 'DeepSeek', 'category' => 'ai',
			'color' => '#4d6bfe', 'initial' => 'D',
			'desc'  => 'deepseek-v3 · 671B MoE · strong reasoning at low cost. OpenAI-compatible API.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'sk-…', 'required' => true ],
			],
			'docs' => 'https://api-docs.deepseek.com/',
		],
		'perplexity' => [
			'id' => 'perplexity', 'name' => 'Perplexity', 'category' => 'ai',
			'color' => '#20b8cd', 'initial' => 'P',
			'desc'  => 'Online-grounded answers · citations · search-aware LLM.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'pplx-…', 'required' => true ],
			],
			'docs' => 'https://docs.perplexity.ai/api-reference/getting-started',
		],
		'cohere' => [
			'id' => 'cohere', 'name' => 'Cohere', 'category' => 'ai',
			'color' => '#ff7759', 'initial' => 'C',
			'desc'  => 'command-r-plus · enterprise RAG · embedding · rerank.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'From dashboard.cohere.com', 'required' => true ],
			],
			'docs' => 'https://docs.cohere.com/reference/about',
		],
		'groq' => [
			'id' => 'groq', 'name' => 'Groq', 'category' => 'ai',
			'color' => '#f55036', 'initial' => 'Q',
			'desc'  => 'LPU inference · 500+ tok/s · llama / mixtral / qwen.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'gsk_…', 'required' => true ],
			],
			'docs' => 'https://console.groq.com/docs/quickstart',
		],
		'elevenlabs' => [
			'id' => 'elevenlabs', 'name' => 'ElevenLabs · Voice', 'category' => 'ai',
			'color' => '#0a0a0a', 'initial' => 'V',
			'desc'  => 'TTS · voice clones · multilingual · audio for posts. xi-api-key header.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key (xi-api-key)', 'type' => 'password', 'placeholder' => 'sk_…', 'required' => true ],
			],
			'docs' => 'https://elevenlabs.io/docs/api-reference/authentication',
		],
		'huggingface' => [
			'id' => 'huggingface', 'name' => 'Hugging Face', 'category' => 'ai',
			'color' => '#ffd21e', 'initial' => 'H',
			'desc'  => 'Inference API · custom model endpoints · datasets. Bearer access token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'placeholder' => 'hf_…', 'required' => true ],
			],
			'docs' => 'https://huggingface.co/docs/hub/security-tokens',
		],
		'ollama' => [
			'id' => 'ollama', 'name' => 'Local · Ollama', 'category' => 'ai',
			'color' => '#7c3aed', 'initial' => 'L',
			'desc'  => 'llama · mistral · qwen — runs on the same host as WordPress. No API key; just point at the daemon.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'placeholder' => 'http://localhost:11434', 'required' => true ],
			],
			'docs' => 'https://github.com/ollama/ollama/blob/main/docs/api.md',
		],


		// ── Payment Gateways ────────────────────────────────────────────────
		'paypal' => [
			'id' => 'paypal', 'name' => 'PayPal', 'category' => 'payments',
			'color' => '#0070ba', 'initial' => 'P',
			'desc'  => 'Standard checkout · subscriptions · payouts. OAuth 2.0 client credentials.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'client_id',     'label' => 'Client ID',     'type' => 'password', 'placeholder' => 'From developer.paypal.com',                'required' => true ],
				[ 'key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'placeholder' => 'Pair with Client ID',                       'required' => true ],
				[ 'key' => 'sandbox',       'label' => 'Use Sandbox',   'type' => 'checkbox', 'placeholder' => 'Routes to api-m.sandbox.paypal.com',         'required' => false ],
			],
			'docs' => 'https://developer.paypal.com/api/rest/authentication/',
		],
		'plaid' => [
			'id' => 'plaid', 'name' => 'Plaid', 'category' => 'payments',
			'color' => '#000000', 'initial' => 'P',
			'desc'  => 'Bank account linking · ACH · balance lookups. Client ID + Secret per environment.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'client_id',   'label' => 'Client ID',   'type' => 'password', 'placeholder' => 'From dashboard.plaid.com',                'required' => true ],
				[ 'key' => 'secret',      'label' => 'Secret',      'type' => 'password', 'placeholder' => 'Environment-specific (sandbox/dev/prod)', 'required' => true ],
				[ 'key' => 'environment', 'label' => 'Environment', 'type' => 'select',
					'options' => [ 'sandbox' => 'Sandbox', 'development' => 'Development', 'production' => 'Production' ],
					'required' => true ],
			],
			'docs' => 'https://plaid.com/docs/api/',
		],
		'braintree' => [
			'id' => 'braintree', 'name' => 'Braintree', 'category' => 'payments',
			'color' => '#0070ba', 'initial' => 'B',
			'desc'  => 'Full-stack payments by PayPal · cards · Venmo · wallets. Three-part auth.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'password', 'placeholder' => 'From Braintree control panel',         'required' => true ],
				[ 'key' => 'public_key',  'label' => 'Public Key',  'type' => 'password', 'placeholder' => 'Public side of API key pair',           'required' => true ],
				[ 'key' => 'private_key', 'label' => 'Private Key', 'type' => 'password', 'placeholder' => 'Private side of API key pair',          'required' => true ],
				[ 'key' => 'sandbox',     'label' => 'Use Sandbox', 'type' => 'checkbox', 'placeholder' => 'Routes to api.sandbox.braintreegateway.com', 'required' => false ],
			],
			'docs' => 'https://developer.paypal.com/braintree/docs/start/hello-server/php',
		],
		'adyen' => [
			'id' => 'adyen', 'name' => 'Adyen', 'category' => 'payments',
			'color' => '#0abf53', 'initial' => 'A',
			'desc'  => 'Global enterprise gateway · 250+ payment methods. X-API-Key header.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',          'label' => 'API Key',          'type' => 'password', 'placeholder' => 'AQE… — from Customer Area',          'required' => true ],
				[ 'key' => 'merchant_account', 'label' => 'Merchant Account', 'type' => 'text',     'placeholder' => 'YourMerchantAccount',                  'required' => true ],
				[ 'key' => 'client_key',       'label' => 'Client Key',       'type' => 'text',     'placeholder' => 'For Drop-in / Components on the front',  'required' => false ],
				[ 'key' => 'live_url_prefix',  'label' => 'Live URL Prefix',  'type' => 'text',     'placeholder' => 'Provided by Adyen for live mode',         'required' => false ],
			],
			'docs' => 'https://docs.adyen.com/development-resources/api-credentials/',
		],
		'mollie' => [
			'id' => 'mollie', 'name' => 'Mollie', 'category' => 'payments',
			'color' => '#0e1c2b', 'initial' => 'M',
			'desc'  => 'EU-first · iDEAL · SEPA · Bancontact · Klarna handoff. Single Bearer key (test/live prefix).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'live_… or test_… — encoded in prefix', 'required' => true ],
			],
			'docs' => 'https://docs.mollie.com/reference/authentication',
		],
		'authorize-net' => [
			'id' => 'authorize-net', 'name' => 'Authorize.Net', 'category' => 'payments',
			'color' => '#1b3a6b', 'initial' => 'AN',
			'desc'  => 'Legacy US gateway · ACH · recurring billing. API Login ID + Transaction Key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_login_id',     'label' => 'API Login ID',     'type' => 'password', 'placeholder' => 'From Account → Security Settings → General Security Settings', 'required' => true ],
				[ 'key' => 'transaction_key',  'label' => 'Transaction Key',  'type' => 'password', 'placeholder' => 'Generated alongside API Login ID',                              'required' => true ],
				[ 'key' => 'sandbox',          'label' => 'Use Sandbox',      'type' => 'checkbox', 'placeholder' => 'Routes to apitest.authorize.net',                                  'required' => false ],
			],
			'docs' => 'https://developer.authorize.net/api/reference/index.html',
		],


		// ── External Apps ───────────────────────────────────────────────────
		'notion' => [
			'id' => 'notion', 'name' => 'Notion', 'category' => 'apps',
			'color' => '#000000', 'initial' => 'N',
			'desc'  => 'Workspaces · databases · pages. Internal Integration Token (recommended for self-hosted use).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'integration_token', 'label' => 'Integration Token', 'type' => 'password', 'placeholder' => 'secret_… or ntn_… — from Settings → Integrations', 'required' => true ],
				[ 'key' => 'notion_version',    'label' => 'Notion-Version',    'type' => 'text',     'placeholder' => '2022-06-28 (current stable)',                       'required' => false ],
			],
			'docs' => 'https://developers.notion.com/docs/authorization',
		],
		'airtable' => [
			'id' => 'airtable', 'name' => 'Airtable', 'category' => 'apps',
			'color' => '#fcb400', 'initial' => 'A',
			'desc'  => 'Bases · tables · views. Personal Access Token (legacy API keys deprecated Feb 2024).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => 'pat… — from airtable.com/create/tokens', 'required' => true ],
				[ 'key' => 'base_id',      'label' => 'Default Base ID',       'type' => 'text',     'placeholder' => 'app… — optional, scopes downstream calls', 'required' => false ],
			],
			'docs' => 'https://airtable.com/developers/web/api/authentication',
		],
		'slack' => [
			'id' => 'slack', 'name' => 'Slack', 'category' => 'apps',
			'color' => '#4a154b', 'initial' => 'S',
			'desc'  => 'Notifications · channel posting · slash commands. Bot Token + Signing Secret.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'bot_token',       'label' => 'Bot User OAuth Token', 'type' => 'password', 'placeholder' => 'xoxb-… — from OAuth & Permissions',     'required' => true ],
				[ 'key' => 'signing_secret',  'label' => 'Signing Secret',       'type' => 'password', 'placeholder' => 'Verifies inbound webhook payloads',      'required' => false ],
				[ 'key' => 'default_channel', 'label' => 'Default channel',      'type' => 'text',     'placeholder' => '#general — optional fallback target',     'required' => false ],
			],
			'docs' => 'https://api.slack.com/authentication/token-types',
		],
		'linear' => [
			'id' => 'linear', 'name' => 'Linear', 'category' => 'apps',
			'color' => '#5e6ad2', 'initial' => 'L',
			'desc'  => 'Issues · projects · cycles. Personal API key (recommended) or OAuth.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'Personal API Key', 'type' => 'password', 'placeholder' => 'lin_api_… — from Settings → API', 'required' => true ],
			],
			'docs' => 'https://developers.linear.app/docs/graphql/working-with-the-graphql-api/authentication',
		],
		'asana' => [
			'id' => 'asana', 'name' => 'Asana', 'category' => 'apps',
			'color' => '#f06a6a', 'initial' => 'As',
			'desc'  => 'Projects · tasks · custom fields. Personal Access Token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token',   'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => '1/… — from app.asana.com/0/my-apps',     'required' => true ],
				[ 'key' => 'workspace_id',   'label' => 'Workspace ID',          'type' => 'text',     'placeholder' => 'Optional default workspace',              'required' => false ],
			],
			'docs' => 'https://developers.asana.com/docs/personal-access-token',
		],
		'monday' => [
			'id' => 'monday', 'name' => 'monday.com', 'category' => 'apps',
			'color' => '#ff3d57', 'initial' => 'Mn',
			'desc'  => 'Boards · items · automations. GraphQL API with personal API token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_token',  'label' => 'API Token',  'type' => 'password', 'placeholder' => 'From Admin → API',                            'required' => true ],
				[ 'key' => 'api_version','label' => 'API Version', 'type' => 'text',     'placeholder' => '2024-10 (default — bump quarterly)',         'required' => false ],
			],
			'docs' => 'https://developer.monday.com/api-reference/docs/authentication',
		],
		'trello' => [
			'id' => 'trello', 'name' => 'Trello', 'category' => 'apps',
			'color' => '#0079bf', 'initial' => 'T',
			'desc'  => 'Boards · cards · lists. App Key + User Token (Atlassian Marketplace flow).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key',    'type' => 'password', 'placeholder' => 'From trello.com/power-ups/admin',         'required' => true ],
				[ 'key' => 'token',   'label' => 'User Token', 'type' => 'password', 'placeholder' => 'Generated when a user authorizes your app', 'required' => true ],
			],
			'docs' => 'https://developer.atlassian.com/cloud/trello/guides/rest-api/authorization/',
		],
		'zapier' => [
			'id' => 'zapier', 'name' => 'Zapier', 'category' => 'apps',
			'color' => '#ff4a00', 'initial' => 'Z',
			'desc'  => 'Trigger Zaps on site events · 6,000+ apps downstream. Inbound webhook URL — no auth, treat the URL as a secret.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'webhook_url', 'label' => 'Catch Hook URL', 'type' => 'url', 'placeholder' => 'https://hooks.zapier.com/hooks/catch/…', 'required' => true ],
			],
			'docs' => 'https://help.zapier.com/hc/en-us/articles/8496288690317-Trigger-Zaps-from-webhooks',
		],


		// ── More CMS ─────────────────────────────────────────────────────────
		'strapi' => [
			'id' => 'strapi', 'name' => 'Strapi', 'category' => 'cms',
			'color' => '#4945ff', 'initial' => 'St',
			'desc'  => 'Self-hostable headless CMS · REST + GraphQL. Bearer API token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'base_url',  'label' => 'Base URL',  'type' => 'url',      'placeholder' => 'https://cms.example.com', 'required' => true ],
				[ 'key' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'placeholder' => 'Settings → API Tokens',    'required' => true ],
			],
			'docs' => 'https://docs.strapi.io/dev-docs/api/rest',
		],
		'sanity' => [
			'id' => 'sanity', 'name' => 'Sanity', 'category' => 'cms',
			'color' => '#f03e2f', 'initial' => 'Sn',
			'desc'  => 'Structured content platform · GROQ queries. Project ID + Dataset + (optional) token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'project_id',  'label' => 'Project ID',  'type' => 'text',     'placeholder' => 'From sanity.io/manage',                  'required' => true ],
				[ 'key' => 'dataset',     'label' => 'Dataset',     'type' => 'text',     'placeholder' => 'production',                              'required' => true ],
				[ 'key' => 'api_version', 'label' => 'API Version', 'type' => 'text',     'placeholder' => 'v2024-01-01',                             'required' => false ],
				[ 'key' => 'token',       'label' => 'API Token',   'type' => 'password', 'placeholder' => 'Required for private datasets or writes', 'required' => false ],
			],
			'docs' => 'https://www.sanity.io/docs/http-api',
		],
		'storyblok' => [
			'id' => 'storyblok', 'name' => 'Storyblok', 'category' => 'cms',
			'color' => '#00b3b0', 'initial' => 'Sb',
			'desc'  => 'Visual headless CMS · component-based. Public vs Preview tokens differ.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'public_token',  'label' => 'Public Access Token',  'type' => 'password', 'placeholder' => 'For published content',         'required' => true ],
				[ 'key' => 'preview_token', 'label' => 'Preview Access Token', 'type' => 'password', 'placeholder' => 'For draft content (optional)',  'required' => false ],
				[ 'key' => 'space_id',      'label' => 'Space ID',             'type' => 'text',     'placeholder' => 'Numeric — optional',            'required' => false ],
			],
			'docs' => 'https://www.storyblok.com/docs/api/content-delivery/v2',
		],
		'hubspot-cms' => [
			'id' => 'hubspot-cms', 'name' => 'HubSpot CMS', 'category' => 'cms',
			'color' => '#ff7a59', 'initial' => 'Hs',
			'desc'  => 'HubSpot CMS Hub · pages, blog, HubDB. Private App access token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Private App Token', 'type' => 'password', 'placeholder' => 'pat-… — Settings → Integrations → Private Apps', 'required' => true ],
			],
			'docs' => 'https://developers.hubspot.com/docs/api/private-apps',
		],


		// ── More Ecommerce ───────────────────────────────────────────────────
		'bigcommerce' => [
			'id' => 'bigcommerce', 'name' => 'BigCommerce', 'category' => 'ecommerce',
			'color' => '#34313f', 'initial' => 'Bc',
			'desc'  => 'Headless or hosted store · catalog + orders + webhooks. Store hash + API token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'store_hash',   'label' => 'Store Hash',           'type' => 'text',     'placeholder' => 'Found in your store URL',         'required' => true ],
				[ 'key' => 'access_token', 'label' => 'API Access Token',     'type' => 'password', 'placeholder' => 'From Store-level API Account',     'required' => true ],
				[ 'key' => 'client_id',    'label' => 'Client ID (optional)', 'type' => 'text',     'placeholder' => 'Pair with the access token',       'required' => false ],
			],
			'docs' => 'https://developer.bigcommerce.com/docs/start/authentication',
		],
		'magento' => [
			'id' => 'magento', 'name' => 'Magento · Adobe Commerce', 'category' => 'ecommerce',
			'color' => '#ee672f', 'initial' => 'Mg',
			'desc'  => 'Enterprise commerce · headless · multi-store. Integration token (REST).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'base_url',     'label' => 'Base URL',                  'type' => 'url',      'placeholder' => 'https://yourstore.com',                   'required' => true ],
				[ 'key' => 'access_token', 'label' => 'Integration Access Token',  'type' => 'password', 'placeholder' => 'System → Integrations → New Integration',  'required' => true ],
			],
			'docs' => 'https://developer.adobe.com/commerce/webapi/rest/use-rest/gs-authentication/',
		],
		'lemon-squeezy' => [
			'id' => 'lemon-squeezy', 'name' => 'Lemon Squeezy', 'category' => 'ecommerce',
			'color' => '#ffc232', 'initial' => 'Lm',
			'desc'  => 'Merchant-of-record SaaS billing · digital goods + subscriptions. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',  'label' => 'API Key',   'type' => 'password', 'placeholder' => 'eyJ0… — from Settings → API', 'required' => true ],
				[ 'key' => 'store_id', 'label' => 'Store ID',  'type' => 'text',     'placeholder' => 'Optional default store',        'required' => false ],
			],
			'docs' => 'https://docs.lemonsqueezy.com/api',
		],
		'edd' => [
			'id' => 'edd', 'name' => 'Easy Digital Downloads', 'category' => 'ecommerce',
			'color' => '#35495c', 'initial' => 'ED',
			'desc'  => 'Digital product sales for WP · licensing · subscriptions. REST API key + token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'site_url',   'label' => 'Site URL',   'type' => 'url',      'placeholder' => 'https://yourstore.com',                      'required' => true ],
				[ 'key' => 'public_key', 'label' => 'Public Key', 'type' => 'text',     'placeholder' => 'From Users → user profile → EDD section',     'required' => true ],
				[ 'key' => 'token',      'label' => 'Token',      'type' => 'password', 'placeholder' => 'Pair with public key',                        'required' => true ],
			],
			'docs' => 'https://easydigitaldownloads.com/docs/edd-rest-api-introduction/',
		],


		// ── More APIs (email / SMS / comms / search) ─────────────────────────
		'twilio' => [
			'id' => 'twilio', 'name' => 'Twilio', 'category' => 'apis',
			'color' => '#f22f46', 'initial' => 'Tw',
			'desc'  => 'SMS · voice · WhatsApp · 2FA. Account SID + Auth Token (Basic Auth).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'account_sid', 'label' => 'Account SID', 'type' => 'password', 'placeholder' => 'AC… — from console.twilio.com',          'required' => true ],
				[ 'key' => 'auth_token',  'label' => 'Auth Token',  'type' => 'password', 'placeholder' => 'Pair with Account SID',                    'required' => true ],
				[ 'key' => 'from_number', 'label' => 'From Number', 'type' => 'text',     'placeholder' => '+15555555555 — optional default sender',   'required' => false ],
			],
			'docs' => 'https://www.twilio.com/docs/iam/api-keys',
		],
		'sendgrid' => [
			'id' => 'sendgrid', 'name' => 'SendGrid', 'category' => 'apis',
			'color' => '#1a82e2', 'initial' => 'Sg',
			'desc'  => 'Transactional email · templates · delivery analytics. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'placeholder' => 'SG.… — from Settings → API Keys',                'required' => true ],
				[ 'key' => 'from_email', 'label' => 'From Email', 'type' => 'text',     'placeholder' => 'Optional default sender (must be verified)',     'required' => false ],
			],
			'docs' => 'https://www.twilio.com/docs/sendgrid/api-reference/how-to-use-the-sendgrid-v3-api/authentication',
		],
		'resend' => [
			'id' => 'resend', 'name' => 'Resend', 'category' => 'apis',
			'color' => '#000000', 'initial' => 'Rs',
			'desc'  => 'Developer-first email · React templates · webhooks. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'placeholder' => 're_… — from resend.com/api-keys',                          'required' => true ],
				[ 'key' => 'from_email', 'label' => 'From Email', 'type' => 'text',     'placeholder' => 'Optional default sender (must be from a verified domain)','required' => false ],
			],
			'docs' => 'https://resend.com/docs/api-reference/introduction',
		],
		'mailgun' => [
			'id' => 'mailgun', 'name' => 'Mailgun', 'category' => 'apis',
			'color' => '#f06b66', 'initial' => 'Mn',
			'desc'  => 'Sending + receiving email · routing · validation. Domain-scoped API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'key-… — from Account → Security',  'required' => true ],
				[ 'key' => 'domain',  'label' => 'Domain',  'type' => 'text',     'placeholder' => 'mg.yoursite.com',                    'required' => true ],
				[ 'key' => 'region',  'label' => 'Region',  'type' => 'select',
					'options' => [ 'us' => 'US', 'eu' => 'EU' ],
					'required' => false ],
			],
			'docs' => 'https://documentation.mailgun.com/docs/mailgun/api-reference/authentication/',
		],
		'postmark' => [
			'id' => 'postmark', 'name' => 'Postmark', 'category' => 'apis',
			'color' => '#ffde00', 'initial' => 'Pm',
			'desc'  => 'Fastest transactional delivery · separate streams. Server-level API token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'server_token', 'label' => 'Server Token', 'type' => 'password', 'placeholder' => 'From Server → API Tokens',                  'required' => true ],
				[ 'key' => 'from_email',   'label' => 'From Email',   'type' => 'text',     'placeholder' => 'Optional default sender (must be verified)', 'required' => false ],
			],
			'docs' => 'https://postmarkapp.com/developer/api/overview',
		],
		'brevo' => [
			'id' => 'brevo', 'name' => 'Brevo (Sendinblue)', 'category' => 'apis',
			'color' => '#0b996e', 'initial' => 'Bv',
			'desc'  => 'Email + SMS + chat · CRM-aware campaigns. api-key header.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'xkeysib-… — from SMTP & API', 'required' => true ],
			],
			'docs' => 'https://developers.brevo.com/docs/getting-started',
		],
		'mapbox' => [
			'id' => 'mapbox', 'name' => 'Mapbox', 'category' => 'apis',
			'color' => '#1da1f2', 'initial' => 'Mb',
			'desc'  => 'Maps · geocoding · directions API. Public Access Token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'placeholder' => 'pk.… — from account.mapbox.com', 'required' => true ],
			],
			'docs' => 'https://docs.mapbox.com/api/overview/',
		],
		'algolia' => [
			'id' => 'algolia', 'name' => 'Algolia', 'category' => 'apis',
			'color' => '#003dff', 'initial' => 'Al',
			'desc'  => 'Search-as-a-service · instant search · faceting. Application ID + API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'app_id',         'label' => 'Application ID',        'type' => 'text',     'placeholder' => 'From dashboard.algolia.com',  'required' => true ],
				[ 'key' => 'admin_api_key',  'label' => 'Admin API Key',         'type' => 'password', 'placeholder' => 'For writes — server-side only', 'required' => true ],
				[ 'key' => 'search_api_key', 'label' => 'Search-Only API Key',   'type' => 'password', 'placeholder' => 'Safe for client use',           'required' => false ],
			],
			'docs' => 'https://www.algolia.com/doc/guides/security/api-keys/',
		],
		'discord-webhook' => [
			'id' => 'discord-webhook', 'name' => 'Discord (Webhook)', 'category' => 'apis',
			'color' => '#5865f2', 'initial' => 'Dw',
			'desc'  => 'Post to a Discord channel from server events. Just a webhook URL.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'webhook_url', 'label' => 'Webhook URL', 'type' => 'url', 'placeholder' => 'https://discord.com/api/webhooks/…', 'required' => true ],
			],
			'docs' => 'https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks',
		],


		// ── More AI ──────────────────────────────────────────────────────────
		'togetherai' => [
			'id' => 'togetherai', 'name' => 'Together AI', 'category' => 'ai',
			'color' => '#0f6fff', 'initial' => 'Tg',
			'desc'  => 'Open-weight models · cheap inference · fine-tuning. OpenAI-compatible API.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'From api.together.ai/settings/api-keys', 'required' => true ],
			],
			'docs' => 'https://docs.together.ai/reference/authentication-1',
		],
		'replicate' => [
			'id' => 'replicate', 'name' => 'Replicate', 'category' => 'ai',
			'color' => '#000000', 'initial' => 'Rp',
			'desc'  => 'Run open-source models in the cloud. Bearer API token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'placeholder' => 'r8_… — from replicate.com/account/api-tokens', 'required' => true ],
			],
			'docs' => 'https://replicate.com/docs/reference/http',
		],
		'stability' => [
			'id' => 'stability', 'name' => 'Stability AI', 'category' => 'ai',
			'color' => '#000000', 'initial' => 'Sa',
			'desc'  => 'Stable Diffusion 3, SDXL, image edits. Bearer API key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'sk-… — from platform.stability.ai/account/keys', 'required' => true ],
			],
			'docs' => 'https://platform.stability.ai/docs/getting-started/authentication',
		],
		'assemblyai' => [
			'id' => 'assemblyai', 'name' => 'AssemblyAI', 'category' => 'ai',
			'color' => '#2a2c40', 'initial' => 'Ay',
			'desc'  => 'Speech-to-text · speaker labels · LeMUR (LLM over audio).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'From assemblyai.com/app/account', 'required' => true ],
			],
			'docs' => 'https://www.assemblyai.com/docs/api-reference/overview',
		],
		'openrouter' => [
			'id' => 'openrouter', 'name' => 'OpenRouter', 'category' => 'ai',
			'color' => '#6467f2', 'initial' => 'Or',
			'desc'  => 'Unified API across 100+ LLMs (Anthropic, OpenAI, Mistral, Llama, …). OpenAI-compatible.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',      'label' => 'API Key',      'type' => 'password', 'placeholder' => 'sk-or-… — from openrouter.ai/keys',          'required' => true ],
				[ 'key' => 'http_referer', 'label' => 'HTTP-Referer', 'type' => 'text',     'placeholder' => 'Optional — improves ranking on openrouter.ai', 'required' => false ],
			],
			'docs' => 'https://openrouter.ai/docs/api-reference/authentication',
		],
		'pinecone' => [
			'id' => 'pinecone', 'name' => 'Pinecone', 'category' => 'ai',
			'color' => '#000000', 'initial' => 'Pc',
			'desc'  => 'Managed vector DB for RAG / embeddings.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',     'label' => 'API Key',     'type' => 'password', 'placeholder' => 'pcsk_… — from app.pinecone.io',         'required' => true ],
				[ 'key' => 'environment', 'label' => 'Environment', 'type' => 'text',     'placeholder' => 'e.g. us-east-1-aws (serverless ignores)', 'required' => false ],
			],
			'docs' => 'https://docs.pinecone.io/guides/get-started/quickstart',
		],


		// ── More Payments ────────────────────────────────────────────────────
		'razorpay' => [
			'id' => 'razorpay', 'name' => 'Razorpay', 'category' => 'payments',
			'color' => '#0c2451', 'initial' => 'Rz',
			'desc'  => 'India-first payments · UPI · netbanking · cards. Key ID + Key Secret.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'key_id',     'label' => 'Key ID',     'type' => 'password', 'placeholder' => 'rzp_live_… or rzp_test_…', 'required' => true ],
				[ 'key' => 'key_secret', 'label' => 'Key Secret', 'type' => 'password', 'placeholder' => 'Pair with Key ID',          'required' => true ],
			],
			'docs' => 'https://razorpay.com/docs/api/authentication/',
		],
		'coinbase-commerce' => [
			'id' => 'coinbase-commerce', 'name' => 'Coinbase Commerce', 'category' => 'payments',
			'color' => '#0052ff', 'initial' => 'Cc',
			'desc'  => 'Accept crypto · BTC / ETH / USDC / SOL. X-CC-Api-Key header.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',        'label' => 'API Key',                 'type' => 'password', 'placeholder' => 'From beta.commerce.coinbase.com/settings', 'required' => true ],
				[ 'key' => 'webhook_secret', 'label' => 'Webhook Shared Secret',   'type' => 'password', 'placeholder' => 'Verifies inbound payment events',           'required' => false ],
			],
			'docs' => 'https://docs.cdp.coinbase.com/commerce-onchain/docs/welcome',
		],
		'anypay' => [
			'id' => 'anypay', 'name' => 'AnyPay', 'category' => 'payments',
			'color' => '#f7931a', 'initial' => 'Ay',
			'desc'  => 'Straight crypto — settles direct to your wallet (non-custodial). 50+ coins supported: BTC, ETH, USDC, USDT, SOL, XRP, BCH, LTC, DOGE, MATIC, ADA, …',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',    'label' => 'API Key',           'type' => 'password', 'placeholder' => 'From anypayx.com → Settings → API',     'required' => true ],
				[ 'key' => 'account_id', 'label' => 'Account ID',        'type' => 'text',     'placeholder' => 'Your AnyPay account / merchant ID',      'required' => false ],
				[ 'key' => 'webhook_secret', 'label' => 'Webhook Secret','type' => 'password', 'placeholder' => 'Verifies inbound settlement callbacks',   'required' => false ],
			],
			'docs' => 'https://docs.anypayx.com/',
		],
		'nowpayments' => [
			'id' => 'nowpayments', 'name' => 'NOWPayments', 'category' => 'payments',
			'color' => '#13b18f', 'initial' => 'NP',
			'desc'  => 'Alternative crypto gateway · 200+ coins · auto-conversion to stablecoin available.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',     'label' => 'API Key',     'type' => 'password', 'placeholder' => 'From account.nowpayments.io',     'required' => true ],
				[ 'key' => 'ipn_secret',  'label' => 'IPN Secret',  'type' => 'password', 'placeholder' => 'For verifying payment callbacks',  'required' => false ],
			],
			'docs' => 'https://documenter.getpostman.com/view/7907941/S1a32n38',
		],
		'btcpay-server' => [
			'id' => 'btcpay-server', 'name' => 'BTCPay Server', 'category' => 'payments',
			'color' => '#0f3057', 'initial' => 'Bp',
			'desc'  => 'Self-hosted crypto gateway. Zero fees, full custody. Point at your own BTCPay instance.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'server_url', 'label' => 'Server URL', 'type' => 'url',      'placeholder' => 'https://btcpay.yourserver.com',  'required' => true ],
				[ 'key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'placeholder' => 'From Account → Manage Account → API Keys', 'required' => true ],
				[ 'key' => 'store_id',   'label' => 'Store ID',   'type' => 'text',     'placeholder' => 'From your BTCPay store URL',     'required' => true ],
			],
			'docs' => 'https://docs.btcpayserver.org/API/Greenfield/v1/',
		],
		'cashapp' => [
			'id' => 'cashapp', 'name' => 'Cash App Pay', 'category' => 'payments',
			'color' => '#00d632', 'initial' => '$',
			'desc'  => 'Block-owned. Cash App users tap-to-pay via QR or in-app. API piggy-backs on Square.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Square Access Token', 'type' => 'password', 'placeholder' => 'EAAA… — same token as your Square connector', 'required' => true ],
				[ 'key' => 'location_id',  'label' => 'Square Location ID',  'type' => 'text',     'placeholder' => 'L1ABC123 — required for Cash App Pay charges', 'required' => true ],
				[ 'key' => 'sandbox',      'label' => 'Use Sandbox',         'type' => 'checkbox', 'placeholder' => 'Routes to connect.squareupsandbox.com',         'required' => false ],
			],
			'docs' => 'https://developer.squareup.com/docs/cash-app-pay/overview',
		],
		'klarna' => [
			'id' => 'klarna', 'name' => 'Klarna', 'category' => 'payments',
			'color' => '#ffa8cd', 'initial' => 'Kl',
			'desc'  => 'Buy now, pay later · split payments. Username + Password (Basic Auth).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'username',   'label' => 'API Username',   'type' => 'password', 'placeholder' => 'From Merchant Portal → API credentials', 'required' => true ],
				[ 'key' => 'password',   'label' => 'API Password',   'type' => 'password', 'placeholder' => 'Pair with API Username',                  'required' => true ],
				[ 'key' => 'region',     'label' => 'Region',         'type' => 'select',
					'options' => [ 'eu' => 'Europe', 'na' => 'North America', 'oc' => 'Oceania' ],
					'required' => true ],
				[ 'key' => 'playground', 'label' => 'Use Playground', 'type' => 'checkbox', 'placeholder' => 'Routes to api.playground.klarna.com',     'required' => false ],
			],
			'docs' => 'https://docs.klarna.com/api/authentication/',
		],
		'affirm' => [
			'id' => 'affirm', 'name' => 'Affirm', 'category' => 'payments',
			'color' => '#0fa0ea', 'initial' => 'Af',
			'desc'  => 'Pay later · 3/6/12-month plans, 0–36% APR. Public Key + Private Key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'public_key',  'label' => 'Public API Key',  'type' => 'password', 'placeholder' => 'From dashboard.affirm.com — API Settings', 'required' => true ],
				[ 'key' => 'private_key', 'label' => 'Private API Key', 'type' => 'password', 'placeholder' => 'Pair with Public Key',                       'required' => true ],
				[ 'key' => 'sandbox',     'label' => 'Use Sandbox',     'type' => 'checkbox', 'placeholder' => 'Routes to sandbox.affirm.com',               'required' => false ],
			],
			'docs' => 'https://docs.affirm.com/payments/docs/getting-started',
		],
		'afterpay' => [
			'id' => 'afterpay', 'name' => 'Afterpay (Clearpay)', 'category' => 'payments',
			'color' => '#b2fce4', 'initial' => 'Ap',
			'desc'  => 'Pay in 4 · 0% interest · bi-weekly. Block-owned. Merchant ID + Secret.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'password', 'placeholder' => 'From Afterpay Business Hub', 'required' => true ],
				[ 'key' => 'secret_key',  'label' => 'Secret Key',  'type' => 'password', 'placeholder' => 'Pair with Merchant ID',      'required' => true ],
				[ 'key' => 'region',      'label' => 'Region',      'type' => 'select',
					'options' => [ 'us' => 'US (Afterpay)', 'ca' => 'Canada', 'au' => 'Australia / NZ', 'uk' => 'UK (Clearpay)' ],
					'required' => true ],
				[ 'key' => 'sandbox',     'label' => 'Use Sandbox', 'type' => 'checkbox', 'placeholder' => 'Routes to api.us-sandbox.afterpay.com', 'required' => false ],
			],
			'docs' => 'https://developers.afterpay.com/afterpay-online/reference/authentication',
		],
		'sezzle' => [
			'id' => 'sezzle', 'name' => 'Sezzle', 'category' => 'payments',
			'color' => '#fffd6d', 'initial' => 'Sz',
			'desc'  => 'Pay in 4 · 0% interest · soft credit check. Public + Private key pair.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'public_key',  'label' => 'Public Key',  'type' => 'password', 'placeholder' => 'sz_pub_… — from dashboard.sezzle.com', 'required' => true ],
				[ 'key' => 'private_key', 'label' => 'Private Key', 'type' => 'password', 'placeholder' => 'sz_prv_… — pair with Public Key',      'required' => true ],
				[ 'key' => 'sandbox',     'label' => 'Use Sandbox', 'type' => 'checkbox', 'placeholder' => 'Routes to sandbox.gateway.sezzle.com',  'required' => false ],
			],
			'docs' => 'https://docs.sezzle.com/docs/api/authentication',
		],
		'zip' => [
			'id' => 'zip', 'name' => 'Zip (formerly Quadpay)', 'category' => 'payments',
			'color' => '#aa8fff', 'initial' => 'Zp',
			'desc'  => 'Pay in 4 · bi-weekly · $1 fee per installment. Merchant ID + API Key.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'password', 'placeholder' => 'From merchant.zip.co', 'required' => true ],
				[ 'key' => 'api_key',     'label' => 'API Key',     'type' => 'password', 'placeholder' => 'Pair with Merchant ID', 'required' => true ],
				[ 'key' => 'region',      'label' => 'Region',      'type' => 'select',
					'options' => [ 'us' => 'United States', 'au' => 'Australia / NZ', 'uk' => 'UK', 'mx' => 'Mexico' ],
					'required' => true ],
				[ 'key' => 'sandbox',     'label' => 'Use Sandbox', 'type' => 'checkbox', 'placeholder' => 'Routes to api.sandbox.zip.co', 'required' => false ],
			],
			'docs' => 'https://developers.zip.co/',
		],


		// ── More External Apps ───────────────────────────────────────────────
		'discord' => [
			'id' => 'discord', 'name' => 'Discord (Bot)', 'category' => 'apps',
			'color' => '#5865f2', 'initial' => 'Db',
			'desc'  => 'Bot for channel automation · slash commands · roles. Bot Token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'bot_token',      'label' => 'Bot Token',      'type' => 'password', 'placeholder' => 'From discord.com/developers/applications', 'required' => true ],
				[ 'key' => 'application_id', 'label' => 'Application ID', 'type' => 'text',     'placeholder' => 'Required for registering slash commands',   'required' => false ],
			],
			'docs' => 'https://discord.com/developers/docs/topics/oauth2',
		],
		'google-drive' => [
			'id' => 'google-drive', 'name' => 'Google Drive', 'category' => 'apps',
			'color' => '#1fa463', 'initial' => 'GD',
			'desc'  => 'Files · folders · sharing. OAuth 2.0 only (no static API key).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'client_id',     'label' => 'OAuth Client ID',     'type' => 'password', 'placeholder' => 'From console.cloud.google.com',  'required' => true ],
				[ 'key' => 'client_secret', 'label' => 'OAuth Client Secret', 'type' => 'password', 'placeholder' => 'Pair with Client ID',            'required' => true ],
				[ 'key' => 'refresh_token', 'label' => 'Refresh Token',       'type' => 'password', 'placeholder' => 'Obtained via the consent flow',  'required' => true ],
			],
			'docs' => 'https://developers.google.com/drive/api/guides/about-auth',
		],
		'dropbox' => [
			'id' => 'dropbox', 'name' => 'Dropbox', 'category' => 'apps',
			'color' => '#0061ff', 'initial' => 'Dx',
			'desc'  => 'File storage · sharing · paper. Bearer access token (OAuth 2.0).',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token',  'label' => 'Access Token',  'type' => 'password', 'placeholder' => 'sl.… — from App Console',          'required' => true ],
				[ 'key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'placeholder' => 'For long-lived sessions',           'required' => false ],
				[ 'key' => 'app_key',       'label' => 'App Key',       'type' => 'text',     'placeholder' => 'Required to refresh',                'required' => false ],
				[ 'key' => 'app_secret',    'label' => 'App Secret',    'type' => 'password', 'placeholder' => 'Required to refresh',                'required' => false ],
			],
			'docs' => 'https://developers.dropbox.com/oauth-guide',
		],
		'github' => [
			'id' => 'github', 'name' => 'GitHub', 'category' => 'apps',
			'color' => '#000000', 'initial' => 'Gh',
			'desc'  => 'Repos · issues · PRs · Actions. Fine-grained Personal Access Token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'token',         'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => 'github_pat_… — fine-grained recommended', 'required' => true ],
				[ 'key' => 'default_owner', 'label' => 'Default Owner',         'type' => 'text',     'placeholder' => 'org or username — scopes downstream calls', 'required' => false ],
			],
			'docs' => 'https://docs.github.com/en/rest/authentication/authenticating-to-the-rest-api',
		],
		'calendly' => [
			'id' => 'calendly', 'name' => 'Calendly', 'category' => 'apps',
			'color' => '#006bff', 'initial' => 'Cl',
			'desc'  => 'Scheduling · event types · webhook on book. Personal Access Token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => 'eyJ… — from Integrations → API & Webhooks', 'required' => true ],
			],
			'docs' => 'https://developer.calendly.com/api-docs/ZG9jOjMzNjEyMDQ2-getting-started',
		],
		'flow-desk' => [
			'id' => 'flow-desk', 'name' => 'Flow Desk', 'category' => 'apps',
			'color' => '#0066ff', 'initial' => 'Fd',
			'desc'  => 'Unified workspace · email · calendar · messaging · CRM in one app. API key from account settings.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'api_key',      'label' => 'API Key',      'type' => 'password', 'placeholder' => 'From Flow Desk → Settings → Developer / API', 'required' => true ],
				[ 'key' => 'workspace_id', 'label' => 'Workspace ID', 'type' => 'text',     'placeholder' => 'Optional — scopes calls to one workspace',  'required' => false ],
			],
			'docs' => 'https://www.flowdesk.co/developers',
		],
		'figma' => [
			'id' => 'figma', 'name' => 'Figma', 'category' => 'apps',
			'color' => '#000000', 'initial' => 'Fg',
			'desc'  => 'Files · comments · components · variables. Personal Access Token.',
			'built_in' => false,
			'fields' => [
				[ 'key' => 'access_token', 'label' => 'Personal Access Token', 'type' => 'password', 'placeholder' => 'figd_… — from Settings → Account → PATs', 'required' => true ],
			],
			'docs' => 'https://www.figma.com/developers/api#authentication',
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

const NEXUS_CUSTOM_CATEGORIES = [ 'cms', 'ecommerce', 'apis', 'ai', 'payments', 'apps' ];

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
	if ( ! is_array( $data ) ) return [];
	// Decrypt any envelope-wrapped secrets transparently. Pre-1.9.1
	// plaintext passes through unchanged via nexus_crypto_is_envelope check.
	if ( function_exists( 'nexus_crypto_decrypt_config' ) && ! empty( $data['config'] ) && is_array( $data['config'] ) ) {
		$data['config'] = nexus_crypto_decrypt_config( $data['config'] );
	}
	return $data;
}

function nexus_save_connector( string $id, array $data ): void {
	// Encrypt every password-typed field + OAuth tokens before they hit
	// the database. Plaintext fields (URLs, store IDs, regions) stay
	// readable so DB admins can still inspect them.
	if ( function_exists( 'nexus_crypto_encrypt_config' ) && ! empty( $data['config'] ) && is_array( $data['config'] ) ) {
		$data['config'] = nexus_crypto_encrypt_config( $id, $data['config'] );
	}
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

/**
 * Bulk-fetch every saved connector row in one query. Returns
 * [ connector_id => decoded_config_row ]. Used by surfaces that
 * iterate the whole registry (Vault tab, sidebar count summary)
 * to avoid an N+1 pattern of get_option() per connector.
 */
function nexus_get_all_connectors(): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'nexus_connector_%'",
		ARRAY_A
	);
	$out = [];
	foreach ( (array) $rows as $r ) {
		$id = substr( $r['option_name'], strlen( 'nexus_connector_' ) );
		$data = json_decode( $r['option_value'], true );
		if ( ! is_array( $data ) ) continue;
		if ( function_exists( 'nexus_crypto_decrypt_config' ) && ! empty( $data['config'] ) && is_array( $data['config'] ) ) {
			$data['config'] = nexus_crypto_decrypt_config( $data['config'] );
		}
		$out[ $id ] = $data;
	}
	return $out;
}

function nexus_connector_status( array $connector ): array {
	$id = $connector['id'];

	if ( ! empty( $connector['built_in'] ) ) {
		if ( $id === 'woocommerce' && ! class_exists( 'WooCommerce' ) ) {
			return [ 'label' => 'Not installed', 'class' => 'nexus-status-off' ];
		}
		return [ 'label' => 'Active', 'class' => 'nexus-status-active' ];
	}

	// Bridge-only connectors (PODpluser, Tapstitch, …) have no API of
	// their own. We infer "Connected via <bridge>" when one of their
	// bridge platforms is live on this site (e.g. WooCommerce active,
	// or Shopify configured in Nexus). The inference assumes the user
	// has actually installed the bridge connector on that platform —
	// we can't confirm that from WordPress without an API to ping.
	if ( ! empty( $connector['bridge_only'] ) ) {
		$active = nexus_bridge_active_for( $connector );
		if ( $active ) {
			return [
				'label' => 'Connected via ' . $active['name'],
				'class' => 'nexus-status-connected',
			];
		}
		return [ 'label' => 'External', 'class' => 'nexus-status-off' ];
	}

	if ( nexus_connector_is_configured( $id ) ) {
		return [ 'label' => 'Connected', 'class' => 'nexus-status-connected' ];
	}
	return [ 'label' => 'Not configured', 'class' => 'nexus-status-off' ];
}

/**
 * Detect whether any bridge listed by a bridge_only connector is "live"
 * on this WordPress install. Returns the matching bridge entry (with an
 * `evidence` payload describing how we knew) or null.
 *
 * "Live" means one of:
 *   - WooCommerce: the WooCommerce plugin is active AND there's a
 *     wp_woocommerce_api_keys row whose description matches the
 *     connector name (real proof the service has authed). Falls back
 *     to "plugin active but unauthed" so the trigger button shows up.
 *   - Shopify / Etsy / BigCommerce: the corresponding Nexus connector
 *     is configured.
 *   - Squarespace / Wix / others: not detectable from inside WP.
 */
function nexus_bridge_active_for( array $connector ): ?array {
	$bridges = $connector['bridge_via'] ?? [];
	foreach ( $bridges as $b ) {
		$name = strtolower( $b['name'] ?? '' );
		if ( $name === '' ) continue;

		if ( strpos( $name, 'woocommerce' ) !== false ) {
			if ( ! class_exists( 'WooCommerce' ) ) continue;
			$keys = nexus_woo_keys_for_app( $connector['name'] ?? '' );
			if ( ! empty( $keys ) ) {
				$b['evidence'] = [ 'kind' => 'woo_keys', 'keys' => $keys, 'count' => count( $keys ) ];
				return $b;
			}
			// WooCommerce is here but no matching API key exists yet — leave
			// $b unreturned so the trigger button gets a chance to render.
			// We DON'T mark it active until a real key is found.
			continue;
		}
		if ( strpos( $name, 'shopify' ) !== false ) {
			if ( nexus_connector_is_configured( 'shopify' ) ) return $b;
			continue;
		}
		if ( strpos( $name, 'etsy' ) !== false ) {
			if ( nexus_connector_is_configured( 'etsy' ) ) return $b;
			continue;
		}
		if ( strpos( $name, 'bigcommerce' ) !== false ) {
			if ( nexus_connector_is_configured( 'bigcommerce' ) ) return $b;
			continue;
		}
	}
	return null;
}

/**
 * Look up existing WooCommerce REST API keys whose description matches
 * the given app name. Returns rows with key_id, description, permissions,
 * truncated_key, last_access. Empty array if WC is missing or no match.
 */
function nexus_woo_keys_for_app( string $app_name ): array {
	if ( $app_name === '' || ! class_exists( 'WooCommerce' ) ) return [];
	global $wpdb;
	$table = $wpdb->prefix . 'woocommerce_api_keys';
	$like  = '%' . $wpdb->esc_like( $app_name ) . '%';
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT key_id, description, permissions, truncated_key, last_access
			 FROM {$table}
			 WHERE description LIKE %s
			 ORDER BY key_id DESC",
			$like
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : [];
}

