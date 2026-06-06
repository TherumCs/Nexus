<?php
/**
 * Nexus by Therum — OAuth 2.0 framework + provider dispatch.
 *
 * Lets the user click "Sign in with X" on a connector card instead of
 * pasting tokens manually. Supports the standard authorization-code
 * flow + PKCE for providers that require it (Etsy, Airtable). Handles
 * state/CSRF, tenant-specific URLs (Shopify), region-specific endpoints
 * (PayPal sandbox vs live, Amazon SP regional hosts), and refresh tokens.
 *
 * Model: BYOA (Bring Your Own App). The user creates an OAuth app on
 * the provider's developer console, registers our redirect URI, and
 * pastes the client_id + client_secret into Nexus. After that, "Sign
 * in" is one click. (True "Sign in with Therum" branding requires
 * Therum-hosted OAuth proxy infra — out of scope here.)
 *
 * Storage shape (saved alongside the connector's existing config):
 *   oauth_client_id, oauth_client_secret      — the app credentials
 *   oauth_access_token, oauth_refresh_token   — the user's tokens
 *   oauth_expires_at                          — UNIX ts when access_token dies
 *   oauth_meta                                — JSON blob (workspace IDs, etc.)
 *
 * Flow:
 *   1. UI click → /wp-admin/admin-ajax.php?action=nexus_oauth_start&connector=X
 *      → returns the provider's authorize URL (with state cookie set)
 *      → JS does window.location = that URL
 *   2. Provider redirects back to /wp-json/nexus/v1/oauth-callback/X?code=…&state=…
 *      → we validate state, exchange code for tokens, store, redirect to admin
 *
 * Adding a new provider: just append to nexus_oauth_providers().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_OAUTH_STATE_TTL = 600;       // 10 minutes
const NEXUS_OAUTH_REQUEST_TIMEOUT = 20;


// ═════════════════════════════════════════════════════════════════════════════
//  PROVIDER REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Per-connector OAuth config. Keyed by connector ID.
 *
 * Each entry shape:
 *   authorize_url        — absolute, or a callable( $cfg ) for tenant-specific
 *   token_url            — absolute, or callable
 *   scopes               — string, joined by `scope_sep` (default ' ')
 *   scope_sep            — ' ' (default) or ','
 *   pkce                 — bool, default false
 *   client_auth          — 'basic' (Authorization: Basic base64) or 'body' (default)
 *   extra_auth_params    — additional query params on the authorize URL
 *   extra_token_params   — additional body params on the token exchange
 *   refresh_supported    — bool
 *   tenant_field         — config key whose value is needed to build per-tenant URLs (Shopify: store_domain)
 *   user_meta_extractor  — callable( $token_response ): array — pulls non-token bits to stash
 *   docs_register_uri    — provider URL to register redirect URI; surfaced in UI
 */
function nexus_oauth_providers(): array {
	return [

		// ─── Google (Drive + AI both ride Google OAuth) ─────────────────────
		'google-drive' => [
			'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token_url'     => 'https://oauth2.googleapis.com/token',
			'scopes'        => 'https://www.googleapis.com/auth/drive openid email',
			'pkce'          => false,
			'extra_auth_params' => [ 'access_type' => 'offline', 'prompt' => 'consent' ],
			'refresh_supported' => true,
			'docs_register_uri' => 'https://console.cloud.google.com/apis/credentials',
		],
		'google-ai' => [
			'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token_url'     => 'https://oauth2.googleapis.com/token',
			'scopes'        => 'https://www.googleapis.com/auth/generative-language.tuning openid email',
			'extra_auth_params' => [ 'access_type' => 'offline', 'prompt' => 'consent' ],
			'refresh_supported' => true,
			'docs_register_uri' => 'https://console.cloud.google.com/apis/credentials',
		],

		// ─── GitHub ─────────────────────────────────────────────────────────
		'github' => [
			'authorize_url' => 'https://github.com/login/oauth/authorize',
			'token_url'     => 'https://github.com/login/oauth/access_token',
			'scopes'        => 'repo read:org user',
			'refresh_supported' => false, // GitHub PATs/OAuth tokens don't expire by default
			'docs_register_uri' => 'https://github.com/settings/developers',
		],

		// ─── Slack ──────────────────────────────────────────────────────────
		'slack' => [
			'authorize_url' => 'https://slack.com/oauth/v2/authorize',
			'token_url'     => 'https://slack.com/api/oauth.v2.access',
			'scopes'        => 'chat:write,channels:read,channels:history,groups:read,im:read',
			'scope_sep'     => ',',
			'extra_auth_params' => [], // user_scope can be added per integration
			'refresh_supported' => true,
			'user_meta_extractor' => 'nexus_oauth_slack_meta',
			'docs_register_uri' => 'https://api.slack.com/apps',
		],

		// ─── Notion ─────────────────────────────────────────────────────────
		'notion' => [
			'authorize_url' => 'https://api.notion.com/v1/oauth/authorize',
			'token_url'     => 'https://api.notion.com/v1/oauth/token',
			'scopes'        => '', // Notion ignores scopes; capabilities are set on the integration page
			'client_auth'   => 'basic',
			'extra_auth_params' => [ 'owner' => 'user' ],
			'refresh_supported' => false,
			'user_meta_extractor' => 'nexus_oauth_notion_meta',
			'docs_register_uri' => 'https://www.notion.so/profile/integrations',
		],

		// ─── Linear ─────────────────────────────────────────────────────────
		'linear' => [
			'authorize_url' => 'https://linear.app/oauth/authorize',
			'token_url'     => 'https://api.linear.app/oauth/token',
			'scopes'        => 'read,write',
			'scope_sep'     => ',',
			'refresh_supported' => false,
			'docs_register_uri' => 'https://linear.app/settings/api/applications',
		],

		// ─── Asana ──────────────────────────────────────────────────────────
		'asana' => [
			'authorize_url' => 'https://app.asana.com/-/oauth_authorize',
			'token_url'     => 'https://app.asana.com/-/oauth_token',
			'scopes'        => 'default',
			'refresh_supported' => true,
			'docs_register_uri' => 'https://app.asana.com/0/my-apps',
		],

		// ─── Calendly ───────────────────────────────────────────────────────
		'calendly' => [
			'authorize_url' => 'https://auth.calendly.com/oauth/authorize',
			'token_url'     => 'https://auth.calendly.com/oauth/token',
			'scopes'        => 'default',
			'refresh_supported' => true,
			'docs_register_uri' => 'https://calendly.com/integrations/api_webhooks',
		],

		// ─── HubSpot ────────────────────────────────────────────────────────
		'hubspot-cms' => [
			'authorize_url' => 'https://app.hubspot.com/oauth/authorize',
			'token_url'     => 'https://api.hubapi.com/oauth/v1/token',
			'scopes'        => 'content forms',
			'refresh_supported' => true,
			'docs_register_uri' => 'https://app.hubspot.com/developer/',
		],

		// ─── Stripe Connect ─────────────────────────────────────────────────
		'stripe' => [
			'authorize_url' => 'https://connect.stripe.com/oauth/authorize',
			'token_url'     => 'https://connect.stripe.com/oauth/token',
			'scopes'        => 'read_write',
			'refresh_supported' => false, // Stripe Connect returns long-lived access tokens
			'extra_auth_params' => [ 'response_type' => 'code', 'stripe_landing' => 'login' ],
			'user_meta_extractor' => 'nexus_oauth_stripe_meta',
			'docs_register_uri' => 'https://dashboard.stripe.com/settings/connect',
		],

		// ─── Shopify ────────────────────────────────────────────────────────
		// Tenant-specific — store_domain is part of every URL.
		'shopify' => [
			'authorize_url' => 'nexus_oauth_shopify_authorize_url',
			'token_url'     => 'nexus_oauth_shopify_token_url',
			'scopes'        => 'read_products,write_products,read_orders,write_orders,read_customers',
			'scope_sep'     => ',',
			'tenant_field'  => 'store_domain',
			'refresh_supported' => false,
			'docs_register_uri' => 'https://shopify.dev/docs/apps/build/authentication-authorization',
		],

		// ─── PayPal ─────────────────────────────────────────────────────────
		// Sandbox vs live picked from connector config[sandbox].
		'paypal' => [
			'authorize_url' => 'nexus_oauth_paypal_authorize_url',
			'token_url'     => 'nexus_oauth_paypal_token_url',
			'scopes'        => 'openid profile email https://uri.paypal.com/services/paypalattributes',
			'client_auth'   => 'basic',
			'refresh_supported' => false,
			'docs_register_uri' => 'https://developer.paypal.com/dashboard/applications/sandbox',
		],

		// ─── Square ─────────────────────────────────────────────────────────
		'square' => [
			'authorize_url' => 'https://connect.squareup.com/oauth2/authorize',
			'token_url'     => 'https://connect.squareup.com/oauth2/token',
			'scopes'        => 'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE ORDERS_READ ORDERS_WRITE ITEMS_READ ITEMS_WRITE',
			'refresh_supported' => true,
			'docs_register_uri' => 'https://developer.squareup.com/apps',
		],

		// ─── Dropbox ────────────────────────────────────────────────────────
		'dropbox' => [
			'authorize_url' => 'https://www.dropbox.com/oauth2/authorize',
			'token_url'     => 'https://api.dropbox.com/oauth2/token',
			'scopes'        => 'files.content.read files.content.write files.metadata.read account_info.read',
			'extra_auth_params' => [ 'token_access_type' => 'offline' ],
			'refresh_supported' => true,
			'docs_register_uri' => 'https://www.dropbox.com/developers/apps',
		],

		// ─── Figma ──────────────────────────────────────────────────────────
		'figma' => [
			'authorize_url' => 'https://www.figma.com/oauth',
			'token_url'     => 'https://www.figma.com/api/oauth/token',
			'scopes'        => 'files:read file_comments:write',
			'refresh_supported' => true,
			'docs_register_uri' => 'https://www.figma.com/developers/apps',
		],

		// ─── Airtable ───────────────────────────────────────────────────────
		// PKCE required.
		'airtable' => [
			'authorize_url' => 'https://airtable.com/oauth2/v1/authorize',
			'token_url'     => 'https://airtable.com/oauth2/v1/token',
			'scopes'        => 'data.records:read data.records:write schema.bases:read',
			'pkce'          => true,
			'client_auth'   => 'basic',
			'refresh_supported' => true,
			'docs_register_uri' => 'https://airtable.com/create/oauth',
		],

		// ─── Mailchimp ──────────────────────────────────────────────────────
		'mailchimp' => [
			'authorize_url' => 'https://login.mailchimp.com/oauth2/authorize',
			'token_url'     => 'https://login.mailchimp.com/oauth2/token',
			'scopes'        => '',
			'refresh_supported' => false,
			'user_meta_extractor' => 'nexus_oauth_mailchimp_meta',
			'docs_register_uri' => 'https://us1.admin.mailchimp.com/account/oauth2/',
		],

		// ─── Etsy ───────────────────────────────────────────────────────────
		// PKCE required.
		'etsy' => [
			'authorize_url' => 'https://www.etsy.com/oauth/connect',
			'token_url'     => 'https://api.etsy.com/v3/public/oauth/token',
			'scopes'        => 'listings_r listings_w shops_r shops_w transactions_r',
			'pkce'          => true,
			'refresh_supported' => true,
			'docs_register_uri' => 'https://www.etsy.com/developers/your-apps',
		],

		// ─── Amazon SP-API (LWA) ────────────────────────────────────────────
		'amazon' => [
			'authorize_url' => 'https://sellercentral.amazon.com/apps/authorize/consent',
			'token_url'     => 'https://api.amazon.com/auth/o2/token',
			'scopes'        => '',
			'extra_auth_params' => [], // application_id is appended dynamically
			'refresh_supported' => true,
			'docs_register_uri' => 'https://developer.amazonservices.com/',
		],

		// ─── monday.com ─────────────────────────────────────────────────────
		'monday' => [
			'authorize_url' => 'https://auth.monday.com/oauth2/authorize',
			'token_url'     => 'https://auth.monday.com/oauth2/token',
			'scopes'        => 'me:read boards:read boards:write workspaces:read',
			'refresh_supported' => false,
			'docs_register_uri' => 'https://monday.com/developers/apps',
		],
	];
}

function nexus_oauth_config_for( string $id ): ?array {
	$all = nexus_oauth_providers();
	return $all[ $id ] ?? null;
}

function nexus_connector_supports_oauth( string $id ): bool {
	return nexus_oauth_config_for( $id ) !== null;
}

function nexus_oauth_redirect_uri( string $id ): string {
	// All providers callback to the same URL — connector ID is the path segment.
	return rest_url( 'nexus/v1/oauth-callback/' . sanitize_key( $id ) );
}

/**
 * Hosted-proxy mode toggle. When NEXUS_OAUTH_PROXY_URL is defined in
 * wp-config.php, the Sign-in flow bypasses BYOA (per-install OAuth
 * apps) and routes through the Therum-hosted proxy at that URL. The
 * proxy holds the OAuth app credentials, runs the dance, and sends
 * HMAC-signed tokens back to this site.
 *
 * Required when hosted mode is on:
 *   NEXUS_OAUTH_PROXY_URL            — e.g. 'https://oauth.therum.studio'
 *   NEXUS_OAUTH_PROXY_SHARED_SECRET  — same value as proxy's HMAC_SECRET
 */
function nexus_oauth_hosted_mode(): bool {
	return defined( 'NEXUS_OAUTH_PROXY_URL' )
		&& defined( 'NEXUS_OAUTH_PROXY_SHARED_SECRET' )
		&& NEXUS_OAUTH_PROXY_URL !== ''
		&& NEXUS_OAUTH_PROXY_SHARED_SECRET !== '';
}

function nexus_oauth_proxy_url(): string {
	return rtrim( (string) ( defined( 'NEXUS_OAUTH_PROXY_URL' ) ? NEXUS_OAUTH_PROXY_URL : '' ), '/' );
}

function nexus_oauth_proxy_secret(): string {
	return (string) ( defined( 'NEXUS_OAUTH_PROXY_SHARED_SECRET' ) ? NEXUS_OAUTH_PROXY_SHARED_SECRET : '' );
}

/**
 * Verify the HMAC signature on a payload received from the proxy.
 * Payload format: base64url(JSON) + "." + base64url(HMAC).
 */
function nexus_oauth_verify_proxy_payload( string $payload ): ?array {
	$secret = nexus_oauth_proxy_secret();
	if ( ! $secret ) return null;
	$dot = strrpos( $payload, '.' );
	if ( $dot === false ) return null;
	$b64 = substr( $payload, 0, $dot );
	$sig = substr( $payload, $dot + 1 );

	$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $b64, $secret, true ) ), '+/', '-_' ), '=' );
	if ( ! hash_equals( $expected, $sig ) ) return null;

	$json = base64_decode( strtr( $b64, '-_', '+/' ) );
	if ( ! $json ) return null;
	$decoded = json_decode( $json, true );
	return is_array( $decoded ) ? $decoded : null;
}


// ═════════════════════════════════════════════════════════════════════════════
//  TENANT-SPECIFIC URL BUILDERS (Shopify, PayPal sandbox/live)
// ═════════════════════════════════════════════════════════════════════════════

function nexus_oauth_shopify_authorize_url( array $config ): string {
	$shop = trim( (string) ( $config['store_domain'] ?? '' ) );
	if ( ! $shop ) return '';
	return 'https://' . $shop . '/admin/oauth/authorize';
}
function nexus_oauth_shopify_token_url( array $config ): string {
	$shop = trim( (string) ( $config['store_domain'] ?? '' ) );
	if ( ! $shop ) return '';
	return 'https://' . $shop . '/admin/oauth/access_token';
}
function nexus_oauth_paypal_authorize_url( array $config ): string {
	return ! empty( $config['sandbox'] )
		? 'https://www.sandbox.paypal.com/connect'
		: 'https://www.paypal.com/connect';
}
function nexus_oauth_paypal_token_url( array $config ): string {
	return ! empty( $config['sandbox'] )
		? 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
		: 'https://api-m.paypal.com/v1/oauth2/token';
}


// ═════════════════════════════════════════════════════════════════════════════
//  AUTHORIZE URL BUILDER
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Builds the authorize URL the user is redirected to. Stores a state
 * token (and PKCE verifier if applicable) in a transient.
 *
 * @return string|WP_Error  URL on success; WP_Error if config is missing.
 */
function nexus_oauth_authorize_url( string $connector_id ) {
	$cfg = nexus_oauth_config_for( $connector_id );
	if ( ! $cfg ) return new WP_Error( 'nexus_oauth_unsupported', 'This connector does not support OAuth.' );

	$saved = nexus_get_connector( $connector_id );
	$conn  = $saved['config'] ?? [];

	// Hosted mode — skip BYOA entirely. Hit the Therum proxy with the
	// site origin + return URL; proxy will use its own OAuth app secret
	// to run the dance and POST tokens back HMAC-signed.
	if ( nexus_oauth_hosted_mode() ) {
		$site_origin = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST );
		$return_url  = admin_url( 'admin.php?page=nexus&nexus_oauth_done=' . sanitize_key( $connector_id ) );

		$start_url = nexus_oauth_proxy_url() . '/v1/start/' . sanitize_key( $connector_id );
		$params = [
			'return' => $return_url,
			'site'   => $site_origin,
		];

		// Pass tenant config (Shopify store_domain, PayPal sandbox flag)
		// so the proxy can build per-tenant URLs.
		if ( $connector_id === 'shopify' && ! empty( $conn['store_domain'] ) ) {
			$params['tenant'] = (string) $conn['store_domain'];
		}
		if ( $connector_id === 'paypal' && ! empty( $conn['sandbox'] ) ) {
			$params['tenant'] = 'sandbox';
		}

		return $start_url . '?' . http_build_query( $params );
	}

	// BYOA mode — Nexus install holds the OAuth app credentials.
	$client_id = (string) ( $conn['oauth_client_id'] ?? '' );
	if ( ! $client_id ) return new WP_Error( 'nexus_oauth_missing_app', 'Set your OAuth Client ID + Secret first.' );

	$authorize_url = is_callable( $cfg['authorize_url'] )
		? call_user_func( $cfg['authorize_url'], $conn )
		: $cfg['authorize_url'];
	if ( ! $authorize_url ) return new WP_Error( 'nexus_oauth_tenant_missing', 'A tenant-specific field is missing (e.g. store_domain for Shopify).' );

	$state = wp_generate_password( 32, false, false );
	$transient_payload = [
		'connector' => $connector_id,
		'user'      => get_current_user_id(),
	];

	$params = [
		'response_type' => 'code',
		'client_id'     => $client_id,
		'redirect_uri'  => nexus_oauth_redirect_uri( $connector_id ),
		'state'         => $state,
	];

	$sep = $cfg['scope_sep'] ?? ' ';
	if ( ! empty( $cfg['scopes'] ) ) {
		$params['scope'] = $cfg['scopes'];
		if ( $sep !== ' ' ) $params['scope'] = str_replace( ' ', $sep, $params['scope'] );
	}

	if ( ! empty( $cfg['pkce'] ) ) {
		$verifier  = nexus_oauth_pkce_verifier();
		$challenge = nexus_oauth_pkce_challenge( $verifier );
		$params['code_challenge']        = $challenge;
		$params['code_challenge_method'] = 'S256';
		$transient_payload['pkce_verifier'] = $verifier;
	}

	foreach ( $cfg['extra_auth_params'] ?? [] as $k => $v ) $params[ $k ] = $v;

	set_transient( 'nexus_oauth_state_' . $state, $transient_payload, NEXUS_OAUTH_STATE_TTL );

	$qs = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	return $authorize_url . ( strpos( $authorize_url, '?' ) === false ? '?' : '&' ) . $qs;
}


// ═════════════════════════════════════════════════════════════════════════════
//  PKCE
// ═════════════════════════════════════════════════════════════════════════════

function nexus_oauth_pkce_verifier(): string {
	// 43–128 chars, URL-safe.
	$raw = bin2hex( random_bytes( 32 ) );
	return substr( $raw, 0, 64 );
}
function nexus_oauth_pkce_challenge( string $verifier ): string {
	return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
}


// ═════════════════════════════════════════════════════════════════════════════
//  REST CALLBACK — provider redirects here with ?code & ?state
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {
	register_rest_route( 'nexus/v1', '/oauth-callback/(?P<connector>[a-z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'nexus_oauth_handle_callback',
		'permission_callback' => '__return_true', // state token is the CSRF guard
		'args'                => [
			'connector' => [ 'validate_callback' => fn( $v ) => is_string( $v ) && $v !== '' ],
		],
	] );

	// Hosted-mode callback. Proxy sends ?payload=base64.hmac&return=<admin_url>.
	register_rest_route( 'nexus/v1', '/oauth-proxy-callback', [
		'methods'             => 'GET',
		'callback'            => 'nexus_oauth_handle_proxy_callback',
		'permission_callback' => '__return_true', // HMAC signature is the auth
	] );
} );

function nexus_oauth_handle_proxy_callback( WP_REST_Request $req ) {
	$provider = sanitize_key( (string) $req->get_param( 'provider' ) );
	$payload  = (string) $req->get_param( 'payload' );
	$return   = (string) $req->get_param( 'return' );
	$return_safe = $return ?: admin_url( 'admin.php?page=nexus' );

	if ( ! nexus_oauth_hosted_mode() ) {
		nexus_oauth_redirect_with_msg( $return_safe, $provider, 'hosted_off', 'Hosted OAuth mode not enabled' );
	}

	$decoded = nexus_oauth_verify_proxy_payload( $payload );
	if ( ! $decoded ) {
		nexus_oauth_redirect_with_msg( $return_safe, $provider, 'bad_signature', 'Proxy payload signature did not verify' );
	}
	if ( ( $decoded['provider'] ?? '' ) !== $provider ) {
		nexus_oauth_redirect_with_msg( $return_safe, $provider, 'provider_mismatch', 'Payload provider does not match URL' );
	}
	$tokens = $decoded['tokens'] ?? [];
	if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
		nexus_oauth_redirect_with_msg( $return_safe, $provider, 'no_token', 'Payload missing access_token' );
	}

	// Reject very old payloads (replay protection — 5-min window).
	$ts = (int) ( $decoded['ts'] ?? 0 );
	if ( $ts > 0 && abs( time() * 1000 - $ts ) > 5 * 60 * 1000 ) {
		nexus_oauth_redirect_with_msg( $return_safe, $provider, 'stale', 'Payload too old' );
	}

	nexus_oauth_persist_tokens( $provider, $tokens );
	nexus_oauth_redirect_with_msg( $return_safe, $provider, 'connected', '' );
}

function nexus_oauth_handle_callback( WP_REST_Request $req ) {
	$connector_id = sanitize_key( $req['connector'] );
	$code         = (string) $req->get_param( 'code' );
	$state        = (string) $req->get_param( 'state' );
	$error        = (string) $req->get_param( 'error' );

	$return_to = admin_url( 'admin.php?page=nexus' );

	if ( $error ) {
		nexus_oauth_redirect_with_msg( $return_to, $connector_id, 'denied', $error );
	}
	if ( ! $code || ! $state ) {
		nexus_oauth_redirect_with_msg( $return_to, $connector_id, 'invalid', 'Missing code or state' );
	}

	$state_key = 'nexus_oauth_state_' . $state;
	$payload   = get_transient( $state_key );
	delete_transient( $state_key );
	if ( ! is_array( $payload ) || ( $payload['connector'] ?? '' ) !== $connector_id ) {
		nexus_oauth_redirect_with_msg( $return_to, $connector_id, 'state_mismatch', 'State token expired or invalid' );
	}

	// Re-establish the user context (REST runs without a cookie session
	// for the callback — we restore from the state payload).
	if ( ! empty( $payload['user'] ) ) wp_set_current_user( (int) $payload['user'] );
	if ( ! current_user_can( 'manage_options' ) ) {
		nexus_oauth_redirect_with_msg( $return_to, $connector_id, 'forbidden', 'Insufficient permissions' );
	}

	$result = nexus_oauth_exchange_code( $connector_id, $code, $payload['pkce_verifier'] ?? '' );
	if ( is_wp_error( $result ) ) {
		nexus_oauth_redirect_with_msg( $return_to, $connector_id, 'exchange_failed', $result->get_error_message() );
	}

	nexus_oauth_persist_tokens( $connector_id, $result );
	nexus_oauth_redirect_with_msg( $return_to, $connector_id, 'connected', '' );
}

function nexus_oauth_redirect_with_msg( string $base, string $connector_id, string $status, string $msg ): void {
	$url = add_query_arg( [
		'tab'              => '', // resolved client-side
		'nexus_oauth_done' => $connector_id,
		'oauth_status'     => $status,
		'oauth_msg'        => rawurlencode( substr( $msg, 0, 240 ) ),
	], $base );
	wp_safe_redirect( $url );
	exit;
}


// ═════════════════════════════════════════════════════════════════════════════
//  CODE → TOKEN EXCHANGE
// ═════════════════════════════════════════════════════════════════════════════

function nexus_oauth_exchange_code( string $connector_id, string $code, string $pkce_verifier = '' ) {
	$cfg   = nexus_oauth_config_for( $connector_id );
	$saved = nexus_get_connector( $connector_id );
	$conn  = $saved['config'] ?? [];

	$client_id     = (string) ( $conn['oauth_client_id']     ?? '' );
	$client_secret = (string) ( $conn['oauth_client_secret'] ?? '' );
	if ( ! $client_id || ! $client_secret ) return new WP_Error( 'nexus_oauth_missing_app', 'Missing OAuth Client ID/Secret.' );

	$token_url = is_callable( $cfg['token_url'] )
		? call_user_func( $cfg['token_url'], $conn )
		: $cfg['token_url'];
	if ( ! $token_url ) return new WP_Error( 'nexus_oauth_tenant_missing', 'Token URL could not be built (missing tenant config).' );

	$body = [
		'grant_type'   => 'authorization_code',
		'code'         => $code,
		'redirect_uri' => nexus_oauth_redirect_uri( $connector_id ),
	];
	foreach ( $cfg['extra_token_params'] ?? [] as $k => $v ) $body[ $k ] = $v;
	if ( $pkce_verifier ) $body['code_verifier'] = $pkce_verifier;

	$headers = [
		'Accept'     => 'application/json',
		'User-Agent' => 'Nexus-WP-OAuth/' . NEXUS_VERSION,
	];

	if ( ( $cfg['client_auth'] ?? 'body' ) === 'basic' ) {
		$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
	} else {
		$body['client_id']     = $client_id;
		$body['client_secret'] = $client_secret;
	}

	$res = wp_remote_post( $token_url, [
		'timeout' => NEXUS_OAUTH_REQUEST_TIMEOUT,
		'headers' => $headers,
		'body'    => $body,
	] );
	if ( is_wp_error( $res ) ) return $res;

	$code_http = (int) wp_remote_retrieve_response_code( $res );
	$raw       = wp_remote_retrieve_body( $res );
	$json      = json_decode( $raw, true );
	if ( ! is_array( $json ) ) {
		// Some providers (GitHub default) return form-encoded.
		parse_str( $raw, $json );
	}
	if ( $code_http >= 400 || empty( $json['access_token'] ) ) {
		$msg = $json['error_description'] ?? $json['error'] ?? 'HTTP ' . $code_http;
		return new WP_Error( 'nexus_oauth_exchange_failed', is_string( $msg ) ? $msg : wp_json_encode( $msg ) );
	}

	// Optional per-provider extractor for non-standard metadata (Slack
	// returns workspace info; Notion returns workspace_name; Mailchimp
	// embeds dc; Stripe returns the connected account ID).
	if ( ! empty( $cfg['user_meta_extractor'] ) && is_callable( $cfg['user_meta_extractor'] ) ) {
		$json['_nexus_meta'] = call_user_func( $cfg['user_meta_extractor'], $json );
	}

	return $json;
}

function nexus_oauth_persist_tokens( string $connector_id, array $token_response ): void {
	$saved = nexus_get_connector( $connector_id );
	$conn  = $saved['config'] ?? [];

	$conn['oauth_access_token']  = (string) ( $token_response['access_token'] ?? '' );
	$conn['oauth_refresh_token'] = (string) ( $token_response['refresh_token'] ?? ( $conn['oauth_refresh_token'] ?? '' ) );
	$conn['oauth_token_type']    = (string) ( $token_response['token_type']    ?? 'Bearer' );
	if ( isset( $token_response['expires_in'] ) ) {
		$conn['oauth_expires_at'] = time() + (int) $token_response['expires_in'];
	}
	if ( isset( $token_response['_nexus_meta'] ) ) {
		$conn['oauth_meta'] = wp_json_encode( $token_response['_nexus_meta'] );
	}

	nexus_save_connector( $connector_id, [
		'enabled' => true,
		'config'  => $conn,
		'updated' => time(),
	] );
}


// ═════════════════════════════════════════════════════════════════════════════
//  PER-PROVIDER METADATA EXTRACTORS
// ═════════════════════════════════════════════════════════════════════════════

function nexus_oauth_slack_meta( array $r ): array {
	return [
		'team_id'   => $r['team']['id']   ?? '',
		'team_name' => $r['team']['name'] ?? '',
		'bot_user_id' => $r['bot_user_id'] ?? '',
	];
}
function nexus_oauth_notion_meta( array $r ): array {
	return [
		'workspace_id'    => $r['workspace_id']    ?? '',
		'workspace_name'  => $r['workspace_name']  ?? '',
		'workspace_icon'  => $r['workspace_icon']  ?? '',
		'bot_id'          => $r['bot_id']          ?? '',
		'duplicated_template_id' => $r['duplicated_template_id'] ?? '',
	];
}
function nexus_oauth_stripe_meta( array $r ): array {
	return [
		'stripe_user_id'      => $r['stripe_user_id']      ?? '', // acct_...
		'stripe_publishable_key' => $r['stripe_publishable_key'] ?? '',
		'livemode'            => $r['livemode']            ?? false,
	];
}
function nexus_oauth_mailchimp_meta( array $r ): array {
	// Mailchimp returns dc in a separate metadata endpoint; we surface
	// the token here. The dc lookup is done lazily on first use.
	return [];
}


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — initiate sign-in (returns the URL for the JS to redirect to)
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_nexus_oauth_start', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$connector_id = sanitize_key( $_POST['connector'] ?? '' );
	$url = nexus_oauth_authorize_url( $connector_id );
	if ( is_wp_error( $url ) ) {
		wp_send_json_error( [ 'message' => $url->get_error_message() ] );
	}
	wp_send_json_success( [ 'url' => $url ] );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  TOKEN REFRESH — call before using a stored access_token
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Returns a usable access_token, refreshing if expired and refresh
 * is supported. Returns '' if no token is configured.
 */
function nexus_oauth_get_token( string $connector_id ): string {
	$saved = nexus_get_connector( $connector_id );
	$conn  = $saved['config'] ?? [];
	$tok   = (string) ( $conn['oauth_access_token'] ?? '' );
	if ( ! $tok ) return '';

	$exp = (int) ( $conn['oauth_expires_at'] ?? 0 );
	if ( $exp && time() > $exp - 60 ) {
		$cfg = nexus_oauth_config_for( $connector_id );
		if ( ! empty( $cfg['refresh_supported'] ) && ! empty( $conn['oauth_refresh_token'] ) ) {
			$refreshed = nexus_oauth_refresh( $connector_id );
			if ( ! is_wp_error( $refreshed ) ) {
				$saved = nexus_get_connector( $connector_id );
				$tok = (string) ( ( $saved['config']['oauth_access_token'] ?? '' ) );
			}
		}
	}
	return $tok;
}

// ═════════════════════════════════════════════════════════════════════════════
//  UI HELPERS — rendered from admin-page.php into the connector card
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Sign-in button block. Rendered in the card foot for OAuth-capable
 * connectors. If the user hasn't entered client_id/secret yet, the
 * button is gated — it opens the form so they can set the app up first.
 */
function nexus_render_oauth_signin_button( array $connector ): void {
	$saved = nexus_get_connector( $connector['id'] );
	$conf  = $saved['config'] ?? [];
	$ready = ! empty( $conf['oauth_client_id'] ) && ! empty( $conf['oauth_client_secret'] );
	$label = sprintf(
		/* translators: %s = provider name like Google Drive */
		__( 'Sign in with %s →', 'nexus' ),
		$connector['name']
	);
	?>
	<button type="button"
		class="th-button th-button-primary"
		data-nexus-oauth-start="<?php echo esc_attr( $connector['id'] ); ?>"
		<?php if ( ! $ready ): ?>data-nexus-oauth-needs-setup="1"<?php endif; ?>>
		<?php echo esc_html( $label ); ?>
	</button>
	<?php
}

/**
 * OAuth app setup fields — prepended to the credential form for any
 * OAuth-capable connector. Shows the redirect URI prominently so the
 * user can register it with the provider's OAuth app.
 */
function nexus_render_oauth_app_fields( array $connector ): void {
	$cfg      = nexus_oauth_config_for( $connector['id'] );
	if ( ! $cfg ) return;
	$saved    = nexus_get_connector( $connector['id'] );
	$conf     = $saved['config'] ?? [];
	$has_tok  = ! empty( $conf['oauth_access_token'] );
	$hosted   = nexus_oauth_hosted_mode();
	?>
	<div class="nexus-oauth-section">
		<div class="nexus-oauth-head">
			<?php
				printf(
					/* translators: %s = provider name */
					esc_html__( 'Sign in with %s', 'nexus' ),
					'<strong>' . esc_html( $connector['name'] ) . '</strong>'
				);
			?>
			<?php if ( $hosted ): ?>
				<span class="nexus-oauth-badge" style="background:color-mix(in srgb,var(--ac) 12%,transparent);color:var(--ac)"><?php esc_html_e( 'hosted by Therum', 'nexus' ); ?></span>
			<?php endif; ?>
			<?php if ( $has_tok ): ?>
				<span class="nexus-oauth-badge"><?php esc_html_e( '✓ token on file', 'nexus' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $hosted ): ?>
			<p class="nexus-oauth-help">
				<?php esc_html_e( 'One-click sign-in via the Therum OAuth proxy — no app setup required on your side. Click below and authorize on the provider\'s screen.', 'nexus' ); ?>
			</p>
			<div class="nexus-oauth-signin-row">
				<button type="button" class="th-button th-button-primary"
					data-nexus-oauth-start="<?php echo esc_attr( $connector['id'] ); ?>">
					<?php
						printf(
							/* translators: %s = provider name */
							esc_html__( 'Sign in with %s →', 'nexus' ),
							esc_html( $connector['name'] )
						);
					?>
				</button>
			</div>
		<?php else: ?>
			<?php
				$register = $cfg['docs_register_uri'] ?? '';
				$redirect = nexus_oauth_redirect_uri( $connector['id'] );
				$has_cid  = ! empty( $conf['oauth_client_id'] );
				$has_sec  = ! empty( $conf['oauth_client_secret'] );
			?>
			<p class="nexus-oauth-help">
				<?php esc_html_e( 'Create an OAuth app on the provider\'s developer console, register the redirect URI below, then paste the Client ID + Secret here. After saving, click "Sign in" to authorize.', 'nexus' ); ?>
			</p>
			<div class="nexus-oauth-redirect">
				<label><?php esc_html_e( 'Redirect URI (register this with your OAuth app)', 'nexus' ); ?></label>
				<input type="text" readonly value="<?php echo esc_attr( $redirect ); ?>" onclick="this.select()">
				<?php if ( $register ): ?>
					<a href="<?php echo esc_url( $register ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open developer console ↗', 'nexus' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="th-conn-field">
				<label><?php esc_html_e( 'OAuth Client ID', 'nexus' ); ?></label>
				<input type="password" class="th-input" data-field="oauth_client_id"
					value="<?php echo $has_cid ? '••••••••' : ''; ?>"
					autocomplete="off">
			</div>
			<div class="th-conn-field">
				<label><?php esc_html_e( 'OAuth Client Secret', 'nexus' ); ?></label>
				<input type="password" class="th-input" data-field="oauth_client_secret"
					value="<?php echo $has_sec ? '••••••••' : ''; ?>"
					autocomplete="off">
			</div>
			<div class="nexus-oauth-signin-row">
				<button type="button" class="th-button th-button-primary"
					data-nexus-oauth-start="<?php echo esc_attr( $connector['id'] ); ?>">
					<?php
						printf(
							/* translators: %s = provider name */
							esc_html__( 'Sign in with %s →', 'nexus' ),
							esc_html( $connector['name'] )
						);
					?>
				</button>
				<small><?php esc_html_e( 'Click Sign in to save creds + start OAuth in one step.', 'nexus' ); ?></small>
			</div>
		<?php endif; ?>

		<hr class="nexus-oauth-divider">
		<p class="nexus-oauth-fallback-note">
			<?php esc_html_e( 'Or paste credentials manually below (token / API key path).', 'nexus' ); ?>
		</p>
	</div>
	<?php
}

function nexus_oauth_refresh( string $connector_id ) {
	$cfg   = nexus_oauth_config_for( $connector_id );
	$saved = nexus_get_connector( $connector_id );
	$conn  = $saved['config'] ?? [];

	// Hosted-mode refresh — proxy holds the app secret, signs the request.
	if ( nexus_oauth_hosted_mode() ) {
		$refresh_tok = (string) ( $conn['oauth_refresh_token'] ?? '' );
		if ( ! $refresh_tok ) return new WP_Error( 'nexus_oauth_no_refresh', 'No refresh_token on file.' );

		$site = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST );
		$sig  = rtrim( strtr( base64_encode( hash_hmac( 'sha256', $site . "\n" . $refresh_tok, nexus_oauth_proxy_secret(), true ) ), '+/', '-_' ), '=' );
		$url  = nexus_oauth_proxy_url() . '/v1/refresh/' . sanitize_key( $connector_id )
				. '?site=' . rawurlencode( $site )
				. '&refresh_token=' . rawurlencode( $refresh_tok )
				. '&sig=' . rawurlencode( $sig );

		$res = wp_remote_get( $url, [ 'timeout' => NEXUS_OAUTH_REQUEST_TIMEOUT ] );
		if ( is_wp_error( $res ) ) return $res;
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['payload'] ) ) return new WP_Error( 'nexus_oauth_refresh_failed', 'Proxy refresh returned no payload.' );
		$decoded = nexus_oauth_verify_proxy_payload( $body['payload'] );
		if ( ! $decoded || empty( $decoded['tokens']['access_token'] ) ) return new WP_Error( 'nexus_oauth_refresh_failed', 'Proxy payload did not verify or had no access_token.' );
		nexus_oauth_persist_tokens( $connector_id, $decoded['tokens'] );
		return $decoded['tokens'];
	}

	$client_id     = (string) ( $conn['oauth_client_id']     ?? '' );
	$client_secret = (string) ( $conn['oauth_client_secret'] ?? '' );
	$refresh_tok   = (string) ( $conn['oauth_refresh_token'] ?? '' );
	if ( ! $client_id || ! $client_secret || ! $refresh_tok ) return new WP_Error( 'nexus_oauth_no_refresh', 'Missing refresh requirements.' );

	$token_url = is_callable( $cfg['token_url'] ) ? call_user_func( $cfg['token_url'], $conn ) : $cfg['token_url'];

	$headers = [ 'Accept' => 'application/json', 'User-Agent' => 'Nexus-WP-OAuth/' . NEXUS_VERSION ];
	$body    = [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_tok ];
	if ( ( $cfg['client_auth'] ?? 'body' ) === 'basic' ) {
		$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
	} else {
		$body['client_id']     = $client_id;
		$body['client_secret'] = $client_secret;
	}

	$res = wp_remote_post( $token_url, [
		'timeout' => NEXUS_OAUTH_REQUEST_TIMEOUT,
		'headers' => $headers,
		'body'    => $body,
	] );
	if ( is_wp_error( $res ) ) return $res;
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $json ) || empty( $json['access_token'] ) ) {
		return new WP_Error( 'nexus_oauth_refresh_failed', 'Refresh did not return an access_token.' );
	}
	nexus_oauth_persist_tokens( $connector_id, $json );
	return $json;
}
