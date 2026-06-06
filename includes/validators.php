<?php
/**
 * Nexus by Therum — live credential validators.
 *
 * Each validator hits the connector's real API with the entered credentials
 * and returns a verdict before ajax_save persists anything. Saving without
 * validation is the worst possible UX — "Connected" should mean "we just
 * proved it works," not "we wrote the bytes to wp_options and hoped."
 *
 * Contract for every validator:
 *
 *     function nexus_validate_<connector_id>( array $config ): array
 *
 * Return shape:
 *
 *     [
 *       'ok'          => bool,        // true = credentials work
 *       'message'     => string,      // shown in the card after Save
 *       'unvalidated' => bool|null,   // set true when we skipped the network
 *     ]
 *
 * Connectors without a registered validator return ok=true with
 * `unvalidated => true` so the existing save-only flow still works for
 * everything we haven't written a test for yet.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_VALIDATOR_TIMEOUT = 15;

function nexus_validator_ua(): string {
	return 'Nexus-WP-Validator/' . NEXUS_VERSION;
}

/**
 * Dispatch to the per-connector validator. Hyphenated ids are mapped
 * (pod-partner → nexus_validate_pod_partner). Unknown ids skip the
 * network and return a benign "saved, not validated" result.
 */
function nexus_validate_connector( string $id, array $config ): array {
	$fn = 'nexus_validate_' . str_replace( '-', '_', $id );
	if ( function_exists( $fn ) ) {
		$out = call_user_func( $fn, $config );
		if ( is_array( $out ) && isset( $out['ok'] ) ) return $out;
		// Defensive: bad validator shape — treat as unvalidated to avoid blocking save.
		return [ 'ok' => true, 'message' => 'Credentials saved.', 'unvalidated' => true ];
	}
	return [
		'ok'          => true,
		'message'     => 'Credentials saved. No live validation for this connector yet.',
		'unvalidated' => true,
	];
}


// ═════════════════════════════════════════════════════════════════════════════
//  PER-CONNECTOR VALIDATORS
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Printful — accepts either a Private Token (Bearer, modern path) OR
 * a Consumer Key + Consumer Secret pair (OAuth client_credentials).
 * Provide ONE auth shape; we pick the right validation path.
 */
function nexus_validate_printful( array $config ): array {
	$private = trim( (string) ( $config['private_token']   ?? '' ) );
	$key     = trim( (string) ( $config['consumer_key']    ?? '' ) );
	$sec     = trim( (string) ( $config['consumer_secret'] ?? '' ) );

	// Private Token path — call /store directly with the bearer.
	if ( $private !== '' ) {
		$store_id = trim( (string) ( $config['store_id'] ?? '' ) );
		$headers = [
			'Authorization' => 'Bearer ' . $private,
			'User-Agent'    => nexus_validator_ua(),
			'Accept'        => 'application/json',
		];
		if ( $store_id !== '' ) $headers['X-PF-Store-Id'] = $store_id;

		$res = wp_remote_get( 'https://api.printful.com/store', [
			'timeout' => NEXUS_VALIDATOR_TIMEOUT,
			'headers' => $headers,
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => 'Network error contacting Printful: ' . $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Printful rejected the Private Token (401). Generate a new one in Developer Portal → API Tokens.' ];
		if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Printful returned HTTP ' . $code . '.' ];
		return [ 'ok' => true, 'message' => 'Validated with Printful (Private Token).' ];
	}

	// OAuth client_credentials path — exchange for an access token.
	if ( $key !== '' && $sec !== '' ) {
		$res = wp_remote_post( 'https://www.printful.com/oauth/token', [
			'timeout' => NEXUS_VALIDATOR_TIMEOUT,
			'headers' => [ 'User-Agent' => nexus_validator_ua() ],
			'body'    => [
				'grant_type'    => 'client_credentials',
				'client_id'     => $key,
				'client_secret' => $sec,
			],
		] );
		if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => 'Network error contacting Printful: ' . $res->get_error_message() ];
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code !== 200 || empty( $body['access_token'] ) ) {
			$detail = is_array( $body ) ? ( $body['error_description'] ?? $body['error'] ?? '' ) : '';
			return [ 'ok' => false, 'message' => 'Printful rejected the OAuth credentials' . ( $detail ? ': ' . $detail : ' (HTTP ' . $code . ').' ) ];
		}
		return [ 'ok' => true, 'message' => 'Validated with Printful (OAuth client credentials).' ];
	}

	return [ 'ok' => false, 'message' => 'Provide either a Private Token OR Consumer Key + Consumer Secret.' ];
}

/**
 * Printify — bearer Personal Access Token. /v1/shops.json is the
 * cheapest authenticated call.
 */
function nexus_validate_printify( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Personal Access Token is required.' ];

	$res = wp_remote_get( 'https://api.printify.com/v1/shops.json', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [
			'Authorization' => 'Bearer ' . $tok,
			'User-Agent'    => nexus_validator_ua(),
			'Accept'        => 'application/json',
		],
	] );
	if ( is_wp_error( $res ) ) {
		return [ 'ok' => false, 'message' => 'Network error contacting Printify: ' . $res->get_error_message() ];
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Printify rejected the token (401). Generate a new PAT in My account → Connections.' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Printify returned HTTP ' . $code . '.' ];
	$shops = json_decode( wp_remote_retrieve_body( $res ), true );
	$n = is_array( $shops ) ? count( $shops ) : 0;
	return [ 'ok' => true, 'message' => sprintf( 'Validated with Printify (%d shop%s visible).', $n, $n === 1 ? '' : 's' ) ];
}

/**
 * Stripe — HTTP Basic with secret key as username. /v1/balance is
 * authenticated, side-effect-free, and cheap.
 */
function nexus_validate_stripe( array $config ): array {
	$sk = trim( (string) ( $config['secret_key'] ?? '' ) );
	if ( $sk === '' ) return [ 'ok' => false, 'message' => 'Secret Key is required.' ];
	if ( strpos( $sk, 'sk_' ) !== 0 ) {
		return [ 'ok' => false, 'message' => 'Secret Key should start with sk_test_ or sk_live_.' ];
	}

	$res = wp_remote_get( 'https://api.stripe.com/v1/balance', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [
			'Authorization' => 'Basic ' . base64_encode( $sk . ':' ),
			'User-Agent'    => nexus_validator_ua(),
		],
	] );
	if ( is_wp_error( $res ) ) {
		return [ 'ok' => false, 'message' => 'Network error contacting Stripe: ' . $res->get_error_message() ];
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Stripe rejected the Secret Key (401). It may be revoked.' ];
	if ( $code !== 200 ) {
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$msg  = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return [ 'ok' => false, 'message' => 'Stripe error: ' . $msg ];
	}
	$mode = strpos( $sk, 'sk_test_' ) === 0 ? 'test' : 'live';
	return [ 'ok' => true, 'message' => 'Validated with Stripe (' . $mode . ' mode).' ];
}

/**
 * Mailchimp — Basic auth, "anystring:apikey". The datacenter is the
 * suffix of the key after the last hyphen.
 */
function nexus_validate_mailchimp( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( $key === '' ) return [ 'ok' => false, 'message' => 'API Key is required.' ];
	$pos = strrpos( $key, '-' );
	if ( $pos === false || $pos === strlen( $key ) - 1 ) {
		return [ 'ok' => false, 'message' => 'API Key must end with a -datacenter suffix (e.g. …-us6).' ];
	}
	$dc = substr( $key, $pos + 1 );
	if ( ! preg_match( '/^[a-z]{2}\d+$/', $dc ) ) {
		return [ 'ok' => false, 'message' => 'Could not parse datacenter from API Key suffix (got "' . $dc . '").' ];
	}

	$res = wp_remote_get( 'https://' . $dc . '.api.mailchimp.com/3.0/ping', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [
			'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $key ),
			'User-Agent'    => nexus_validator_ua(),
		],
	] );
	if ( is_wp_error( $res ) ) {
		return [ 'ok' => false, 'message' => 'Network error contacting Mailchimp: ' . $res->get_error_message() ];
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Mailchimp rejected the API Key (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Mailchimp returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Mailchimp (datacenter ' . $dc . ').' ];
}

/**
 * Shopify — X-Shopify-Access-Token header. /shop.json is the canonical
 * "is my token good" call. Confirms domain + token + scope at once.
 */
function nexus_validate_shopify( array $config ): array {
	$domain = trim( (string) ( $config['store_domain'] ?? '' ) );
	$tok    = trim( (string) ( $config['access_token'] ?? '' ) );
	$ver    = trim( (string) ( $config['api_version']  ?? '' ) );
	if ( $ver === '' ) $ver = '2024-01';
	if ( $domain === '' || $tok === '' ) return [ 'ok' => false, 'message' => 'Store Domain + Access Token are required.' ];
	if ( strpos( $domain, '.myshopify.com' ) === false ) {
		return [ 'ok' => false, 'message' => 'Store Domain should look like yourstore.myshopify.com.' ];
	}

	$url = 'https://' . $domain . '/admin/api/' . rawurlencode( $ver ) . '/shop.json';
	$res = wp_remote_get( $url, [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [
			'X-Shopify-Access-Token' => $tok,
			'User-Agent'             => nexus_validator_ua(),
			'Accept'                 => 'application/json',
		],
	] );
	if ( is_wp_error( $res ) ) {
		return [ 'ok' => false, 'message' => 'Network error contacting Shopify: ' . $res->get_error_message() ];
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 || $code === 403 ) return [ 'ok' => false, 'message' => 'Shopify rejected the Access Token (HTTP ' . $code . '). Confirm it is current and the custom app has the needed scopes.' ];
	if ( $code === 404 ) return [ 'ok' => false, 'message' => 'Shopify returned 404 — check the Store Domain.' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Shopify returned HTTP ' . $code . '.' ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	$name = $body['shop']['name'] ?? 'shop';
	return [ 'ok' => true, 'message' => 'Validated with Shopify (' . $name . ').' ];
}


// ═════════════════════════════════════════════════════════════════════════════
//  ADDITIONAL VALIDATORS (1.8.0) — common AI / email / comms / OAuth providers
// ═════════════════════════════════════════════════════════════════════════════

function nexus_validate_anthropic( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( $key === '' ) return [ 'ok' => false, 'message' => 'API Key is required.' ];
	$res = wp_remote_get( 'https://api.anthropic.com/v1/models', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Anthropic rejected the API Key (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Anthropic returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Anthropic.' ];
}

function nexus_validate_openai( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( $key === '' ) return [ 'ok' => false, 'message' => 'API Key is required.' ];
	$headers = [ 'Authorization' => 'Bearer ' . $key, 'User-Agent' => nexus_validator_ua() ];
	if ( ! empty( $config['organization_id'] ) ) $headers['OpenAI-Organization'] = $config['organization_id'];
	$res = wp_remote_get( 'https://api.openai.com/v1/models', [ 'timeout' => NEXUS_VALIDATOR_TIMEOUT, 'headers' => $headers ] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'OpenAI rejected the API Key (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'OpenAI returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with OpenAI.' ];
}

function nexus_validate_github( array $config ): array {
	$tok = trim( (string) ( $config['token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Personal Access Token or OAuth token is required.' ];
	$res = wp_remote_get( 'https://api.github.com/user', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $tok, 'User-Agent' => nexus_validator_ua(), 'Accept' => 'application/vnd.github+json' ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'GitHub rejected the token (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'GitHub returned HTTP ' . $code . '.' ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return [ 'ok' => true, 'message' => 'Validated as @' . ( $body['login'] ?? 'user' ) . '.' ];
}

function nexus_validate_slack( array $config ): array {
	$tok = trim( (string) ( $config['bot_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Bot Token is required.' ];
	$res = wp_remote_get( 'https://slack.com/api/auth.test', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $tok, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['ok'] ) ) return [ 'ok' => false, 'message' => 'Slack: ' . ( $body['error'] ?? 'auth_test failed' ) ];
	return [ 'ok' => true, 'message' => 'Validated in workspace "' . ( $body['team'] ?? '?' ) . '".' ];
}

function nexus_validate_notion( array $config ): array {
	$tok = trim( (string) ( $config['integration_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Integration Token is required.' ];
	$res = wp_remote_get( 'https://api.notion.com/v1/users/me', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $tok, 'Notion-Version' => $config['notion_version'] ?? '2022-06-28', 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Notion rejected the token (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Notion returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Notion.' ];
}

function nexus_validate_linear( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $key === '' ) return [ 'ok' => false, 'message' => 'API Key is required.' ];
	$res = wp_remote_post( 'https://api.linear.app/graphql', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => $key, 'Content-Type' => 'application/json', 'User-Agent' => nexus_validator_ua() ],
		'body'    => wp_json_encode( [ 'query' => '{ viewer { id name } }' ] ),
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['data']['viewer']['id'] ) ) return [ 'ok' => false, 'message' => 'Linear: ' . ( $body['errors'][0]['message'] ?? 'GraphQL viewer query failed' ) ];
	return [ 'ok' => true, 'message' => 'Validated as ' . ( $body['data']['viewer']['name'] ?? '?' ) . '.' ];
}

function nexus_validate_asana( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Access Token is required.' ];
	$res = wp_remote_get( 'https://app.asana.com/api/1.0/users/me', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $tok, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Asana rejected the token (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Asana returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Asana.' ];
}

function nexus_validate_calendly( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Access Token is required.' ];
	$res = wp_remote_get( 'https://api.calendly.com/users/me', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $tok, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Calendly rejected the token (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Calendly returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Calendly.' ];
}

function nexus_validate_airtable( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Personal Access Token is required.' ];
	$res = wp_remote_get( 'https://api.airtable.com/v0/meta/whoami', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $tok, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Airtable rejected the PAT (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Airtable returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Airtable.' ];
}

function nexus_validate_figma( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Personal Access Token is required.' ];
	$res = wp_remote_get( 'https://api.figma.com/v1/me', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'X-Figma-Token' => $tok, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 || $code === 403 ) return [ 'ok' => false, 'message' => 'Figma rejected the token (' . $code . ').' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Figma returned HTTP ' . $code . '.' ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return [ 'ok' => true, 'message' => 'Validated as ' . ( $body['handle'] ?? $body['email'] ?? '?' ) . '.' ];
}

function nexus_validate_monday( array $config ): array {
	$tok = trim( (string) ( $config['api_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'API Token is required.' ];
	$res = wp_remote_post( 'https://api.monday.com/v2', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => $tok, 'Content-Type' => 'application/json', 'User-Agent' => nexus_validator_ua() ],
		'body'    => wp_json_encode( [ 'query' => '{ me { id name } }' ] ),
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['data']['me']['id'] ) ) return [ 'ok' => false, 'message' => 'monday.com: ' . ( $body['errors'][0]['message'] ?? 'me query failed' ) ];
	return [ 'ok' => true, 'message' => 'Validated as ' . ( $body['data']['me']['name'] ?? '?' ) . '.' ];
}

function nexus_validate_paypal( array $config ): array {
	$id  = trim( (string) ( $config['client_id']     ?? '' ) );
	$sec = trim( (string) ( $config['client_secret'] ?? '' ) );
	if ( $id === '' || $sec === '' ) return [ 'ok' => false, 'message' => 'Client ID + Secret are required.' ];
	$host = ! empty( $config['sandbox'] ) ? 'api-m.sandbox.paypal.com' : 'api-m.paypal.com';
	$res = wp_remote_post( "https://{$host}/v1/oauth2/token", [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [
			'Authorization' => 'Basic ' . base64_encode( $id . ':' . $sec ),
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/x-www-form-urlencoded',
			'User-Agent'    => nexus_validator_ua(),
		],
		'body'    => 'grant_type=client_credentials',
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['access_token'] ) ) return [ 'ok' => false, 'message' => 'PayPal: ' . ( $body['error_description'] ?? 'token exchange failed' ) ];
	$mode = ! empty( $config['sandbox'] ) ? 'sandbox' : 'live';
	return [ 'ok' => true, 'message' => 'Validated with PayPal (' . $mode . ').' ];
}

function nexus_validate_twilio( array $config ): array {
	$sid = trim( (string) ( $config['account_sid'] ?? '' ) );
	$tok = trim( (string) ( $config['auth_token']  ?? '' ) );
	if ( $sid === '' || $tok === '' ) return [ 'ok' => false, 'message' => 'Account SID + Auth Token are required.' ];
	$res = wp_remote_get( "https://api.twilio.com/2010-04-01/Accounts/{$sid}.json", [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $tok ), 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Twilio rejected the credentials (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Twilio returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Twilio.' ];
}

function nexus_validate_sendgrid( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( $key === '' ) return [ 'ok' => false, 'message' => 'API Key is required.' ];
	$res = wp_remote_get( 'https://api.sendgrid.com/v3/scopes', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $key, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'SendGrid rejected the API Key (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'SendGrid returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with SendGrid.' ];
}

function nexus_validate_resend( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( $key === '' ) return [ 'ok' => false, 'message' => 'API Key is required.' ];
	$res = wp_remote_get( 'https://api.resend.com/domains', [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'Authorization' => 'Bearer ' . $key, 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 ) return [ 'ok' => false, 'message' => 'Resend rejected the API Key (401).' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Resend returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Resend.' ];
}

function nexus_validate_mapbox( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? '' ) );
	if ( $tok === '' ) return [ 'ok' => false, 'message' => 'Access Token is required.' ];
	// /tokens/v2 lists tokens for the requesting user — cheapest authenticated call.
	$res = wp_remote_get( 'https://api.mapbox.com/tokens/v2?access_token=' . rawurlencode( $tok ), [
		'timeout' => NEXUS_VALIDATOR_TIMEOUT,
		'headers' => [ 'User-Agent' => nexus_validator_ua() ],
	] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 401 || $code === 403 ) return [ 'ok' => false, 'message' => 'Mapbox rejected the token (' . $code . ').' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Mapbox returned HTTP ' . $code . '.' ];
	return [ 'ok' => true, 'message' => 'Validated with Mapbox.' ];
}

function nexus_validate_discord_webhook( array $config ): array {
	$url = trim( (string) ( $config['webhook_url'] ?? '' ) );
	if ( $url === '' ) return [ 'ok' => false, 'message' => 'Webhook URL is required.' ];
	if ( strpos( $url, 'discord.com/api/webhooks/' ) === false ) {
		return [ 'ok' => false, 'message' => 'Doesn\'t look like a Discord webhook URL.' ];
	}
	// HEAD the URL — Discord returns 200 with metadata when the webhook exists.
	$res = wp_remote_get( $url, [ 'timeout' => NEXUS_VALIDATOR_TIMEOUT, 'headers' => [ 'User-Agent' => nexus_validator_ua() ] ] );
	if ( is_wp_error( $res ) ) return [ 'ok' => false, 'message' => $res->get_error_message() ];
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code === 404 ) return [ 'ok' => false, 'message' => 'Discord webhook not found (404). It may have been deleted.' ];
	if ( $code !== 200 ) return [ 'ok' => false, 'message' => 'Discord returned HTTP ' . $code . '.' ];
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	return [ 'ok' => true, 'message' => 'Validated Discord webhook for "' . ( $body['name'] ?? $body['channel_id'] ?? 'channel' ) . '".' ];
}


// ═════════════════════════════════════════════════════════════════════════════
//  VALIDATORS — full coverage (1.9.0)
//  Each hits the provider's cheapest authenticated endpoint and reports the
//  real provider error on failure. Pattern is intentionally repetitive so
//  adding a 60th is the same shape as adding the 23rd.
// ═════════════════════════════════════════════════════════════════════════════

// Tiny helpers to keep the per-validator code short.
function _nv_get( string $url, array $headers = [] ): array {
	$res = wp_remote_get( $url, [ 'timeout' => NEXUS_VALIDATOR_TIMEOUT, 'headers' => array_merge( [ 'User-Agent' => nexus_validator_ua() ], $headers ) ] );
	if ( is_wp_error( $res ) ) return [ 'code' => 0, 'body' => null, 'err' => $res->get_error_message() ];
	return [ 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => json_decode( wp_remote_retrieve_body( $res ), true ), 'err' => '' ];
}
function _nv_post( string $url, array $headers, $body ): array {
	$args = [ 'timeout' => NEXUS_VALIDATOR_TIMEOUT, 'headers' => array_merge( [ 'User-Agent' => nexus_validator_ua() ], $headers ) ];
	if ( is_string( $body ) ) { $args['body'] = $body; } else { $args['body'] = $body; }
	$res = wp_remote_post( $url, $args );
	if ( is_wp_error( $res ) ) return [ 'code' => 0, 'body' => null, 'err' => $res->get_error_message() ];
	return [ 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => json_decode( wp_remote_retrieve_body( $res ), true ), 'err' => '' ];
}
function _nv_ok( string $name ): array { return [ 'ok' => true, 'message' => "Validated with {$name}." ]; }
function _nv_fail( string $name, int $code, string $extra = '' ): array {
	if ( $code === 401 || $code === 403 ) return [ 'ok' => false, 'message' => "{$name} rejected the credentials ({$code})." ];
	return [ 'ok' => false, 'message' => "{$name} returned HTTP {$code}." . ( $extra ? ' ' . $extra : '' ) ];
}
function _nv_required( string $msg ): array { return [ 'ok' => false, 'message' => $msg ]; }

// ─── CMS ────────────────────────────────────────────────────────────────

function nexus_validate_drupal( array $config ): array {
	$url = trim( (string) ( $config['site_url'] ?? '' ) );
	if ( ! $url ) return _nv_required( 'Site URL is required.' );
	$path    = trim( (string) ( $config['api_path'] ?? '/jsonapi' ) );
	$bearer  = trim( (string) ( $config['bearer_token'] ?? '' ) );
	$user    = trim( (string) ( $config['username'] ?? '' ) );
	$pass    = trim( (string) ( $config['password'] ?? '' ) );
	$headers = [];
	if ( $bearer )       $headers['Authorization'] = 'Bearer ' . $bearer;
	elseif ( $user )     $headers['Authorization'] = 'Basic ' . base64_encode( $user . ':' . $pass );
	$r = _nv_get( rtrim( $url, '/' ) . $path, $headers );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 200 ) return _nv_ok( 'Drupal' );
	return _nv_fail( 'Drupal', $r['code'] );
}
function nexus_validate_ghost( array $config ): array {
	$url = trim( (string) ( $config['admin_url'] ?? '' ) );
	if ( ! $url ) return _nv_required( 'Admin URL is required.' );
	$ck  = trim( (string) ( $config['content_key'] ?? '' ) );
	if ( $ck === '' ) return [ 'ok' => true, 'message' => 'Saved. Admin API validation requires JWT signing — deferred.', 'unvalidated' => true ];
	$r = _nv_get( rtrim( $url, '/' ) . '/ghost/api/content/settings/?key=' . rawurlencode( $ck ) );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Ghost' ) : _nv_fail( 'Ghost', $r['code'] );
}
function nexus_validate_webflow( array $config ): array {
	$tok = trim( (string) ( $config['api_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'API Token is required.' );
	$r = _nv_get( 'https://api.webflow.com/v2/token/authorized_by', [ 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Webflow' ) : _nv_fail( 'Webflow', $r['code'] );
}
function nexus_validate_contentful( array $config ): array {
	$space = trim( (string) ( $config['space_id'] ?? '' ) );
	$dt    = trim( (string) ( $config['delivery_token'] ?? '' ) );
	if ( ! $space || ! $dt ) return _nv_required( 'Space ID + Delivery Token are required.' );
	$r = _nv_get( "https://cdn.contentful.com/spaces/{$space}/?access_token=" . rawurlencode( $dt ) );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Contentful' ) : _nv_fail( 'Contentful', $r['code'] );
}
function nexus_validate_strapi( array $config ): array {
	$base = rtrim( (string) ( $config['base_url'] ?? '' ), '/' );
	$tok  = trim( (string) ( $config['api_token'] ?? '' ) );
	if ( ! $base || ! $tok ) return _nv_required( 'Base URL + API Token are required.' );
	$r = _nv_get( $base . '/api/users-permissions/users/me', [ 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	// 404 on /users/me is fine if endpoint isn't enabled; treat 401/403 as the only auth failure.
	if ( $r['code'] === 401 || $r['code'] === 403 ) return _nv_fail( 'Strapi', $r['code'] );
	return _nv_ok( 'Strapi' );
}
function nexus_validate_sanity( array $config ): array {
	$pid = trim( (string) ( $config['project_id'] ?? '' ) );
	$ds  = trim( (string) ( $config['dataset'] ?? 'production' ) );
	$tok = trim( (string) ( $config['token'] ?? '' ) );
	if ( ! $pid ) return _nv_required( 'Project ID is required.' );
	$headers = $tok ? [ 'Authorization' => 'Bearer ' . $tok ] : [];
	$r = _nv_get( "https://{$pid}.api.sanity.io/v2024-01-01/data/query/{$ds}?query=" . rawurlencode( '*[_type=="sanity.imageAsset"][0]._id' ), $headers );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return ( $r['code'] === 200 ) ? _nv_ok( 'Sanity' ) : _nv_fail( 'Sanity', $r['code'] );
}
function nexus_validate_storyblok( array $config ): array {
	$tok = trim( (string) ( $config['public_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Public Access Token is required.' );
	$r = _nv_get( 'https://api.storyblok.com/v2/cdn/spaces/me?token=' . rawurlencode( $tok ) );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Storyblok' ) : _nv_fail( 'Storyblok', $r['code'] );
}
function nexus_validate_hubspot_cms( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Private App Token is required.' );
	$r = _nv_get( 'https://api.hubapi.com/account-info/v3/details', [ 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'HubSpot' ) : _nv_fail( 'HubSpot', $r['code'] );
}

// ─── Ecommerce ──────────────────────────────────────────────────────────

function nexus_validate_amazon( array $config ): array {
	$cid = trim( (string) ( $config['client_id'] ?? '' ) );
	$sec = trim( (string) ( $config['client_secret'] ?? '' ) );
	$rt  = trim( (string) ( $config['refresh_token'] ?? '' ) );
	if ( ! $cid || ! $sec || ! $rt ) return _nv_required( 'LWA Client ID + Secret + Refresh Token are required.' );
	$r = _nv_post( 'https://api.amazon.com/auth/o2/token',
		[ 'Content-Type' => 'application/x-www-form-urlencoded' ],
		http_build_query( [ 'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $cid, 'client_secret' => $sec ] )
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 200 && ! empty( $r['body']['access_token'] ) ) return _nv_ok( 'Amazon SP-API (LWA)' );
	return _nv_fail( 'Amazon SP-API', $r['code'], $r['body']['error_description'] ?? '' );
}
function nexus_validate_etsy( array $config ): array {
	$ks  = trim( (string) ( $config['keystring'] ?? '' ) );
	$tok = trim( (string) ( $config['oauth_access_token'] ?? '' ) );
	if ( ! $ks || ! $tok ) return _nv_required( 'Keystring + OAuth Access Token are required.' );
	$r = _nv_get( 'https://openapi.etsy.com/v3/application/users/me', [ 'x-api-key' => $ks, 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Etsy' ) : _nv_fail( 'Etsy', $r['code'] );
}
function nexus_validate_square( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Access Token is required.' );
	$host = ! empty( $config['sandbox'] ) ? 'connect.squareupsandbox.com' : 'connect.squareup.com';
	$r = _nv_get( "https://{$host}/v2/locations", [ 'Authorization' => 'Bearer ' . $tok, 'Square-Version' => $config['api_version'] ?? '2024-01-18' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Square' ) : _nv_fail( 'Square', $r['code'] );
}
function nexus_validate_bigcommerce( array $config ): array {
	$hash = trim( (string) ( $config['store_hash'] ?? '' ) );
	$tok  = trim( (string) ( $config['access_token'] ?? '' ) );
	if ( ! $hash || ! $tok ) return _nv_required( 'Store Hash + Access Token are required.' );
	$r = _nv_get( "https://api.bigcommerce.com/stores/{$hash}/v2/store", [ 'X-Auth-Token' => $tok, 'Accept' => 'application/json' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'BigCommerce' ) : _nv_fail( 'BigCommerce', $r['code'] );
}
function nexus_validate_magento( array $config ): array {
	$base = rtrim( (string) ( $config['base_url'] ?? '' ), '/' );
	$tok  = trim( (string) ( $config['access_token'] ?? '' ) );
	if ( ! $base || ! $tok ) return _nv_required( 'Base URL + Integration Token are required.' );
	$r = _nv_get( $base . '/rest/V1/store/storeConfigs', [ 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Magento' ) : _nv_fail( 'Magento', $r['code'] );
}
function nexus_validate_lemon_squeezy( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.lemonsqueezy.com/v1/users/me', [ 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/vnd.api+json' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Lemon Squeezy' ) : _nv_fail( 'Lemon Squeezy', $r['code'] );
}
function nexus_validate_edd( array $config ): array {
	$url = rtrim( (string) ( $config['site_url'] ?? '' ), '/' );
	$pk  = trim( (string) ( $config['public_key'] ?? '' ) );
	$tk  = trim( (string) ( $config['token'] ?? '' ) );
	if ( ! $url || ! $pk || ! $tk ) return _nv_required( 'Site URL + Public Key + Token are required.' );
	$r = _nv_get( "{$url}/?edd-api=products&key=" . rawurlencode( $pk ) . '&token=' . rawurlencode( $tk ) . '&number=1' );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'EDD' ) : _nv_fail( 'EDD', $r['code'] );
}

// ─── APIs (email / SMS / search / misc) ─────────────────────────────────

function nexus_validate_mailgun( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	$dom = trim( (string) ( $config['domain'] ?? '' ) );
	$region = (string) ( $config['region'] ?? 'us' );
	if ( ! $key || ! $dom ) return _nv_required( 'API Key + Domain are required.' );
	$host = $region === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
	$r = _nv_get( "https://{$host}/v3/{$dom}", [ 'Authorization' => 'Basic ' . base64_encode( 'api:' . $key ) ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Mailgun' ) : _nv_fail( 'Mailgun', $r['code'] );
}
function nexus_validate_postmark( array $config ): array {
	$tok = trim( (string) ( $config['server_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Server Token is required.' );
	$r = _nv_get( 'https://api.postmarkapp.com/server', [ 'X-Postmark-Server-Token' => $tok, 'Accept' => 'application/json' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Postmark' ) : _nv_fail( 'Postmark', $r['code'] );
}
function nexus_validate_brevo( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.brevo.com/v3/account', [ 'api-key' => $key, 'Accept' => 'application/json' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Brevo' ) : _nv_fail( 'Brevo', $r['code'] );
}
function nexus_validate_algolia( array $config ): array {
	$app   = trim( (string) ( $config['app_id'] ?? '' ) );
	$admin = trim( (string) ( $config['admin_api_key'] ?? '' ) );
	if ( ! $app || ! $admin ) return _nv_required( 'Application ID + Admin API Key are required.' );
	$r = _nv_get( "https://{$app}-dsn.algolia.net/1/keys", [ 'X-Algolia-Application-Id' => $app, 'X-Algolia-API-Key' => $admin ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Algolia' ) : _nv_fail( 'Algolia', $r['code'] );
}

// ─── AI providers ───────────────────────────────────────────────────────

function nexus_validate_google_ai( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://generativelanguage.googleapis.com/v1/models?key=' . rawurlencode( $key ) );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Google AI' ) : _nv_fail( 'Google AI', $r['code'] );
}
function nexus_validate_xai( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.x.ai/v1/api-key', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'xAI' ) : _nv_fail( 'xAI', $r['code'] );
}
function nexus_validate_mistral( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.mistral.ai/v1/models', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Mistral' ) : _nv_fail( 'Mistral', $r['code'] );
}
function nexus_validate_deepseek( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.deepseek.com/models', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'DeepSeek' ) : _nv_fail( 'DeepSeek', $r['code'] );
}
function nexus_validate_perplexity( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	// Perplexity doesn't have a cheap whoami; minimal completion request.
	$r = _nv_post( 'https://api.perplexity.ai/chat/completions',
		[ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
		wp_json_encode( [ 'model' => 'sonar', 'messages' => [ [ 'role' => 'user', 'content' => 'hi' ] ], 'max_tokens' => 1 ] )
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 200 ) return _nv_ok( 'Perplexity' );
	if ( $r['code'] === 401 || $r['code'] === 403 ) return _nv_fail( 'Perplexity', $r['code'] );
	return [ 'ok' => true, 'message' => 'Saved. Perplexity returned ' . $r['code'] . ' on test call.', 'unvalidated' => true ];
}
function nexus_validate_cohere( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.cohere.com/v1/models', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Cohere' ) : _nv_fail( 'Cohere', $r['code'] );
}
function nexus_validate_groq( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.groq.com/openai/v1/models', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Groq' ) : _nv_fail( 'Groq', $r['code'] );
}
function nexus_validate_elevenlabs( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.elevenlabs.io/v1/user', [ 'xi-api-key' => $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'ElevenLabs' ) : _nv_fail( 'ElevenLabs', $r['code'] );
}
function nexus_validate_huggingface( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Access Token is required.' );
	$r = _nv_get( 'https://huggingface.co/api/whoami-v2', [ 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Hugging Face' ) : _nv_fail( 'Hugging Face', $r['code'] );
}
function nexus_validate_ollama( array $config ): array {
	$base = rtrim( (string) ( $config['base_url'] ?? '' ), '/' );
	if ( ! $base ) return _nv_required( 'Base URL is required.' );
	$r = _nv_get( $base . '/api/tags' );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Ollama' ) : _nv_fail( 'Ollama', $r['code'] );
}
function nexus_validate_togetherai( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.together.xyz/v1/models', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Together AI' ) : _nv_fail( 'Together AI', $r['code'] );
}
function nexus_validate_replicate( array $config ): array {
	$tok = trim( (string) ( $config['api_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'API Token is required.' );
	$r = _nv_get( 'https://api.replicate.com/v1/account', [ 'Authorization' => 'Token ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Replicate' ) : _nv_fail( 'Replicate', $r['code'] );
}
function nexus_validate_stability( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.stability.ai/v1/user/account', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Stability AI' ) : _nv_fail( 'Stability AI', $r['code'] );
}
function nexus_validate_assemblyai( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.assemblyai.com/v2/transcript?limit=1', [ 'Authorization' => $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'AssemblyAI' ) : _nv_fail( 'AssemblyAI', $r['code'] );
}
function nexus_validate_openrouter( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://openrouter.ai/api/v1/auth/key', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'OpenRouter' ) : _nv_fail( 'OpenRouter', $r['code'] );
}
function nexus_validate_pinecone( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.pinecone.io/indexes', [ 'Api-Key' => $key, 'X-Pinecone-API-Version' => '2024-07' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Pinecone' ) : _nv_fail( 'Pinecone', $r['code'] );
}

// ─── Payment gateways ──────────────────────────────────────────────────

function nexus_validate_plaid( array $config ): array {
	$id  = trim( (string) ( $config['client_id'] ?? '' ) );
	$sec = trim( (string) ( $config['secret'] ?? '' ) );
	$env = (string) ( $config['environment'] ?? 'sandbox' );
	if ( ! $id || ! $sec ) return _nv_required( 'Client ID + Secret are required.' );
	$r = _nv_post( "https://{$env}.plaid.com/categories/get",
		[ 'Content-Type' => 'application/json' ],
		wp_json_encode( [ 'client_id' => $id, 'secret' => $sec ] )
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( "Plaid ({$env})" ) : _nv_fail( 'Plaid', $r['code'], $r['body']['error_message'] ?? '' );
}
function nexus_validate_braintree( array $config ): array {
	$mid = trim( (string) ( $config['merchant_id'] ?? '' ) );
	$pub = trim( (string) ( $config['public_key'] ?? '' ) );
	$priv = trim( (string) ( $config['private_key'] ?? '' ) );
	if ( ! $mid || ! $pub || ! $priv ) return _nv_required( 'Merchant ID + Public + Private keys are required.' );
	$host = ! empty( $config['sandbox'] ) ? 'api.sandbox.braintreegateway.com' : 'api.braintreegateway.com';
	$r = _nv_get( "https://{$host}/merchants/{$mid}/customers/__nonexistent__", [
		'Authorization' => 'Basic ' . base64_encode( $pub . ':' . $priv ),
		'X-ApiVersion' => '6',
	] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	// 404 means auth succeeded but customer doesn't exist — that's what we want.
	if ( $r['code'] === 404 ) return _nv_ok( 'Braintree' );
	if ( $r['code'] === 401 || $r['code'] === 403 ) return _nv_fail( 'Braintree', $r['code'] );
	return _nv_ok( 'Braintree' );
}
function nexus_validate_adyen( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	$ma  = trim( (string) ( $config['merchant_account'] ?? '' ) );
	if ( ! $key || ! $ma ) return _nv_required( 'API Key + Merchant Account are required.' );
	$r = _nv_post( 'https://checkout-test.adyen.com/v71/paymentMethods',
		[ 'X-API-Key' => $key, 'Content-Type' => 'application/json' ],
		wp_json_encode( [ 'merchantAccount' => $ma ] )
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Adyen' ) : _nv_fail( 'Adyen', $r['code'] );
}
function nexus_validate_mollie( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.mollie.com/v2/methods', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Mollie' ) : _nv_fail( 'Mollie', $r['code'] );
}
function nexus_validate_authorize_net( array $config ): array {
	$lid = trim( (string) ( $config['api_login_id'] ?? '' ) );
	$key = trim( (string) ( $config['transaction_key'] ?? '' ) );
	if ( ! $lid || ! $key ) return _nv_required( 'API Login ID + Transaction Key are required.' );
	$endpoint = ! empty( $config['sandbox'] ) ? 'https://apitest.authorize.net/xml/v1/request.api' : 'https://api.authorize.net/xml/v1/request.api';
	$body = wp_json_encode( [ 'authenticateTestRequest' => [ 'merchantAuthentication' => [ 'name' => $lid, 'transactionKey' => $key ] ] ] );
	$r = _nv_post( $endpoint, [ 'Content-Type' => 'application/json' ], $body );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] !== 200 ) return _nv_fail( 'Authorize.Net', $r['code'] );
	$ok = ( $r['body']['messages']['resultCode'] ?? '' ) === 'Ok';
	return $ok ? _nv_ok( 'Authorize.Net' ) : [ 'ok' => false, 'message' => 'Authorize.Net: ' . ( $r['body']['messages']['message'][0]['text'] ?? 'authentication failed' ) ];
}
function nexus_validate_razorpay( array $config ): array {
	$id  = trim( (string) ( $config['key_id'] ?? '' ) );
	$sec = trim( (string) ( $config['key_secret'] ?? '' ) );
	if ( ! $id || ! $sec ) return _nv_required( 'Key ID + Key Secret are required.' );
	$r = _nv_get( 'https://api.razorpay.com/v1/payments?count=1', [ 'Authorization' => 'Basic ' . base64_encode( $id . ':' . $sec ) ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Razorpay' ) : _nv_fail( 'Razorpay', $r['code'] );
}
function nexus_validate_coinbase_commerce( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.commerce.coinbase.com/checkouts?limit=1', [ 'X-CC-Api-Key' => $key, 'X-CC-Version' => '2018-03-22' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Coinbase Commerce' ) : _nv_fail( 'Coinbase Commerce', $r['code'] );
}
function nexus_validate_klarna( array $config ): array {
	$u = trim( (string) ( $config['username'] ?? '' ) );
	$p = trim( (string) ( $config['password'] ?? '' ) );
	$region = (string) ( $config['region'] ?? 'na' );
	$playground = ! empty( $config['playground'] );
	if ( ! $u || ! $p ) return _nv_required( 'API Username + Password are required.' );
	$host_map = [
		'eu' => $playground ? 'api.playground.klarna.com' : 'api.klarna.com',
		'na' => $playground ? 'api-na.playground.klarna.com' : 'api-na.klarna.com',
		'oc' => $playground ? 'api-oc.playground.klarna.com' : 'api-oc.klarna.com',
	];
	$host = $host_map[ $region ] ?? $host_map['na'];
	$r = _nv_post( "https://{$host}/payments/v1/sessions",
		[ 'Authorization' => 'Basic ' . base64_encode( $u . ':' . $p ), 'Content-Type' => 'application/json' ],
		wp_json_encode( [ 'purchase_country' => 'US', 'purchase_currency' => 'USD', 'locale' => 'en-US', 'order_amount' => 100, 'order_lines' => [ [ 'name' => 'test', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100 ] ] ] )
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 200 || $r['code'] === 201 ) return _nv_ok( 'Klarna' );
	return _nv_fail( 'Klarna', $r['code'] );
}
function nexus_validate_affirm( array $config ): array {
	$pub = trim( (string) ( $config['public_key'] ?? '' ) );
	$priv = trim( (string) ( $config['private_key'] ?? '' ) );
	if ( ! $pub || ! $priv ) return _nv_required( 'Public + Private API keys are required.' );
	$host = ! empty( $config['sandbox'] ) ? 'sandbox.affirm.com' : 'api.affirm.com';
	// Hit a known-404 endpoint with auth — 404 means auth passed.
	$r = _nv_get( "https://{$host}/api/v1/charges/__nonexistent__", [ 'Authorization' => 'Basic ' . base64_encode( $pub . ':' . $priv ) ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 401 || $r['code'] === 403 ) return _nv_fail( 'Affirm', $r['code'] );
	return _nv_ok( 'Affirm' );
}
function nexus_validate_afterpay( array $config ): array {
	$mid = trim( (string) ( $config['merchant_id'] ?? '' ) );
	$sec = trim( (string) ( $config['secret_key'] ?? '' ) );
	if ( ! $mid || ! $sec ) return _nv_required( 'Merchant ID + Secret Key are required.' );
	$region = (string) ( $config['region'] ?? 'us' );
	$sandbox = ! empty( $config['sandbox'] );
	$host_map = [
		'us' => $sandbox ? 'api.us-sandbox.afterpay.com' : 'api.us.afterpay.com',
		'au' => $sandbox ? 'api-sandbox.afterpay.com'    : 'api.afterpay.com',
		'uk' => $sandbox ? 'api-sandbox.clearpay.co.uk'  : 'api.clearpay.co.uk',
		'ca' => $sandbox ? 'api.us-sandbox.afterpay.com' : 'api.us.afterpay.com',
	];
	$host = $host_map[ $region ] ?? $host_map['us'];
	$r = _nv_get( "https://{$host}/v2/configuration", [ 'Authorization' => 'Basic ' . base64_encode( $mid . ':' . $sec ) ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Afterpay' ) : _nv_fail( 'Afterpay', $r['code'] );
}
function nexus_validate_sezzle( array $config ): array {
	$pub = trim( (string) ( $config['public_key'] ?? '' ) );
	$priv = trim( (string) ( $config['private_key'] ?? '' ) );
	if ( ! $pub || ! $priv ) return _nv_required( 'Public + Private keys are required.' );
	$host = ! empty( $config['sandbox'] ) ? 'sandbox.gateway.sezzle.com' : 'gateway.sezzle.com';
	$r = _nv_post( "https://{$host}/v2/authentication",
		[ 'Content-Type' => 'application/json' ],
		wp_json_encode( [ 'public_key' => $pub, 'private_key' => $priv ] )
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 200 && ! empty( $r['body']['token'] ) ) return _nv_ok( 'Sezzle' );
	return _nv_fail( 'Sezzle', $r['code'] );
}
function nexus_validate_zip( array $config ): array {
	$mid = trim( (string) ( $config['merchant_id'] ?? '' ) );
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $mid || ! $key ) return _nv_required( 'Merchant ID + API Key are required.' );
	$host = ! empty( $config['sandbox'] ) ? 'api.sandbox.zip.co' : 'api.zip.co';
	$r = _nv_get( "https://{$host}/v3/merchant/{$mid}", [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	if ( $r['code'] === 401 || $r['code'] === 403 ) return _nv_fail( 'Zip', $r['code'] );
	return _nv_ok( 'Zip' );
}
function nexus_validate_anypay( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.anypayx.com/api/v1/accounts', [ 'Authorization' => 'Bearer ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'AnyPay' ) : _nv_fail( 'AnyPay', $r['code'] );
}
function nexus_validate_nowpayments( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	$r = _nv_get( 'https://api.nowpayments.io/v1/status', [ 'x-api-key' => $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'NOWPayments' ) : _nv_fail( 'NOWPayments', $r['code'] );
}
function nexus_validate_btcpay_server( array $config ): array {
	$url = rtrim( (string) ( $config['server_url'] ?? '' ), '/' );
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	$store = trim( (string) ( $config['store_id'] ?? '' ) );
	if ( ! $url || ! $key || ! $store ) return _nv_required( 'Server URL + API Key + Store ID are required.' );
	$r = _nv_get( "{$url}/api/v1/stores/{$store}", [ 'Authorization' => 'token ' . $key ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'BTCPay Server' ) : _nv_fail( 'BTCPay Server', $r['code'] );
}
function nexus_validate_cashapp( array $config ): array {
	// Cash App Pay rides Square's API — same call as nexus_validate_square.
	$tok = trim( (string) ( $config['access_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Access Token is required.' );
	$host = ! empty( $config['sandbox'] ) ? 'connect.squareupsandbox.com' : 'connect.squareup.com';
	$r = _nv_get( "https://{$host}/v2/locations", [ 'Authorization' => 'Bearer ' . $tok, 'Square-Version' => '2024-01-18' ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Cash App Pay (Square)' ) : _nv_fail( 'Cash App Pay', $r['code'] );
}

// ─── External Apps ─────────────────────────────────────────────────────

function nexus_validate_dropbox( array $config ): array {
	$tok = trim( (string) ( $config['access_token'] ?? $config['oauth_access_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Access Token is required.' );
	$r = _nv_post( 'https://api.dropboxapi.com/2/users/get_current_account',
		[ 'Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json' ],
		'null'
	);
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Dropbox' ) : _nv_fail( 'Dropbox', $r['code'] );
}
function nexus_validate_google_drive( array $config ): array {
	$tok = trim( (string) ( $config['oauth_access_token'] ?? '' ) );
	if ( ! $tok ) return [ 'ok' => true, 'message' => 'Saved. Sign in with Google to validate.', 'unvalidated' => true ];
	$r = _nv_get( 'https://www.googleapis.com/oauth2/v3/userinfo', [ 'Authorization' => 'Bearer ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Google Drive' ) : _nv_fail( 'Google Drive', $r['code'] );
}
function nexus_validate_trello( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	$tok = trim( (string) ( $config['token'] ?? '' ) );
	if ( ! $key || ! $tok ) return _nv_required( 'API Key + User Token are required.' );
	$r = _nv_get( "https://api.trello.com/1/members/me?key=" . rawurlencode( $key ) . '&token=' . rawurlencode( $tok ) );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Trello' ) : _nv_fail( 'Trello', $r['code'] );
}
function nexus_validate_zapier( array $config ): array {
	$url = trim( (string) ( $config['webhook_url'] ?? '' ) );
	if ( ! $url ) return _nv_required( 'Catch Hook URL is required.' );
	if ( strpos( $url, 'hooks.zapier.com' ) === false ) return [ 'ok' => false, 'message' => 'Doesn\'t look like a Zapier Catch Hook URL.' ];
	return [ 'ok' => true, 'message' => 'Saved. Zapier accepts any POST to the hook URL — no auth to validate.', 'unvalidated' => true ];
}
function nexus_validate_discord( array $config ): array {
	$tok = trim( (string) ( $config['bot_token'] ?? '' ) );
	if ( ! $tok ) return _nv_required( 'Bot Token is required.' );
	$r = _nv_get( 'https://discord.com/api/v10/users/@me', [ 'Authorization' => 'Bot ' . $tok ] );
	if ( $r['err'] ) return [ 'ok' => false, 'message' => $r['err'] ];
	return $r['code'] === 200 ? _nv_ok( 'Discord' ) : _nv_fail( 'Discord', $r['code'] );
}
function nexus_validate_flow_desk( array $config ): array {
	// No public Flow Desk API as of this writing — accept without live validation.
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	if ( ! $key ) return _nv_required( 'API Key is required.' );
	return [ 'ok' => true, 'message' => 'Saved. No public Flow Desk API documented — live validation deferred.', 'unvalidated' => true ];
}

// ─── Other (no public validation possible / pod-partner) ───────────────

function nexus_validate_pod_partner( array $config ): array {
	$key = trim( (string) ( $config['api_key'] ?? '' ) );
	$sec = trim( (string) ( $config['api_secret'] ?? '' ) );
	if ( ! $key || ! $sec ) return _nv_required( 'API Key + Secret are required.' );
	return [ 'ok' => true, 'message' => 'Saved. Pod Partner does not publish an open auth-check endpoint.', 'unvalidated' => true ];
}


// ═════════════════════════════════════════════════════════════════════════════
//  BACKGROUND HEALTH CHECK (1.9.0)
//
//  Daily job that re-runs nexus_validate_connector on every configured
//  connector. Failures land in the audit log so revoked tokens / expired
//  secrets surface before downstream code tries to use them. Quick wins
//  for trust: green pill always reflects reality, not just "was reachable
//  at the moment the user clicked Save."
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'nexus_health_check_all', 'nexus_health_check_all_connectors' );

function nexus_health_check_all_connectors(): void {
	$registry = nexus_connector_registry();
	$checked  = 0;
	$failed   = 0;
	foreach ( $registry as $id => $c ) {
		if ( ! empty( $c['built_in'] ) ) continue;
		if ( ! empty( $c['bridge_only'] ) ) continue;
		if ( ! nexus_connector_is_configured( $id ) ) continue;

		$saved  = nexus_get_connector( $id );
		$config = $saved['config'] ?? [];
		$verdict = nexus_validate_connector( $id, $config );
		$checked++;

		if ( empty( $verdict['ok'] ) ) {
			$failed++;
			if ( function_exists( 'nexus_audit_log' ) ) {
				nexus_audit_log( 'connector.failed_validation', $id . ' — ' . ( $verdict['message'] ?? '?' ) );
			}
			// Flag in the saved row so the card UI can show a warning pill.
			$saved['last_health_check'] = time();
			$saved['last_health_ok']    = false;
			$saved['last_health_msg']   = (string) ( $verdict['message'] ?? '' );
			nexus_save_connector( $id, $saved );
		} else {
			$saved['last_health_check'] = time();
			$saved['last_health_ok']    = true;
			$saved['last_health_msg']   = '';
			nexus_save_connector( $id, $saved );
		}
	}
	if ( function_exists( 'nexus_audit_log' ) ) {
		nexus_audit_log( 'health.swept', "checked={$checked} · failed={$failed}" );
	}
}

// Schedule the daily health check.
add_action( 'init', function() {
	nexus_queue_recurring( 'nexus_health_check_all', [], DAY_IN_SECONDS );
} );

// AJAX trigger so users can run it on demand from the audit/keys vault tab.
add_action( 'wp_ajax_nexus_health_check_now', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );
	nexus_health_check_all_connectors();
	wp_send_json_success( [ 'message' => 'Swept all connectors. Check the audit log for any failures.' ] );
} );
