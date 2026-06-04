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
