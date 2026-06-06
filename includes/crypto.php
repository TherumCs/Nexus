<?php
/**
 * Nexus by Therum — credential encryption at rest.
 *
 * Wraps every secret-shaped field in `nexus_save_connector()` with
 * AES-256-GCM before it touches `wp_options`. Key is derived from
 * WordPress's `SECURE_AUTH_KEY` so each install has a unique cipher
 * key without storing anything extra. Decryption is transparent on
 * `nexus_get_connector()`.
 *
 * Field selection: only `password`-typed fields per the connector's
 * registry shape, plus a hardcoded list of OAuth bits (access_token,
 * refresh_token, client_id, client_secret). Plain text fields (URLs,
 * store IDs, regions) stay readable so DB admins can still grep for
 * them.
 *
 * Backward compatibility: an envelope marker `__nexus_enc` tells
 * the decoder whether a value is encrypted. Pre-1.9.1 plaintext
 * values pass through unchanged on first read, get re-encrypted on
 * next save. No migration script required.
 *
 * Rotating SECURE_AUTH_KEY invalidates every stored secret — same
 * trade-off WordPress makes for application-passwords, auth cookies,
 * etc. Document the rotation impact and have the user re-paste creds
 * (or hit Disconnect + Connect) afterward.
 *
 * Public API:
 *   nexus_crypto_encrypt( string $plaintext ): string|false
 *   nexus_crypto_decrypt( string $envelope ): string|false
 *   nexus_crypto_encrypt_config( string $id, array $config ): array
 *   nexus_crypto_decrypt_config( array $config ): array
 *   nexus_crypto_is_envelope( string $v ): bool
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_CRYPTO_ENVELOPE_PREFIX = 'nx1.';   // v1 envelope. Bump if format changes.

function nexus_crypto_available(): bool {
	return function_exists( 'openssl_encrypt' )
		&& function_exists( 'openssl_decrypt' )
		&& defined( 'SECURE_AUTH_KEY' )
		&& SECURE_AUTH_KEY !== '';
}

/**
 * Derive the per-install AES-256 key. 32 bytes, deterministic.
 */
function nexus_crypto_key(): string {
	return hash_hmac( 'sha256', 'nexus-creds-v1', SECURE_AUTH_KEY, true );
}

/**
 * AES-256-GCM encrypt → base64-url envelope:
 *   nx1.<base64(iv)>.<base64(tag)>.<base64(ciphertext)>
 */
function nexus_crypto_encrypt( string $plaintext ) {
	if ( ! nexus_crypto_available() ) return false;
	if ( $plaintext === '' ) return ''; // empty stays empty — don't waste bytes

	$iv  = random_bytes( 12 ); // GCM nonce
	$tag = '';
	$ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', nexus_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
	if ( $ct === false ) return false;

	return NEXUS_CRYPTO_ENVELOPE_PREFIX
		. _nexus_b64u( $iv )  . '.'
		. _nexus_b64u( $tag ) . '.'
		. _nexus_b64u( $ct );
}

/**
 * Decrypt an envelope back to plaintext. Returns the original input
 * if it isn't an envelope (pre-1.9.1 plaintext passes through).
 */
function nexus_crypto_decrypt( string $envelope ) {
	if ( ! nexus_crypto_is_envelope( $envelope ) ) return $envelope;
	if ( ! nexus_crypto_available() ) return $envelope;

	$parts = explode( '.', substr( $envelope, strlen( NEXUS_CRYPTO_ENVELOPE_PREFIX ) ) );
	if ( count( $parts ) !== 3 ) return $envelope; // corrupted — return as-is rather than data-loss

	$iv  = _nexus_b64u_decode( $parts[0] );
	$tag = _nexus_b64u_decode( $parts[1] );
	$ct  = _nexus_b64u_decode( $parts[2] );
	if ( $iv === false || $tag === false || $ct === false ) return $envelope;

	$pt = openssl_decrypt( $ct, 'aes-256-gcm', nexus_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
	return $pt === false ? '' : $pt;
}

function nexus_crypto_is_envelope( $v ): bool {
	return is_string( $v ) && strpos( $v, NEXUS_CRYPTO_ENVELOPE_PREFIX ) === 0;
}

/**
 * Which keys in the saved config blob count as secrets and get
 * encrypted. Derived from the connector's registry-declared field
 * types (anything `password`) PLUS a hardcoded list of OAuth bits
 * (those aren't in `fields` — they're framework-level).
 */
function nexus_crypto_secret_keys_for( string $id ): array {
	$registry = nexus_connector_registry();
	$keys = [
		'oauth_client_id',
		'oauth_client_secret',
		'oauth_access_token',
		'oauth_refresh_token',
	];
	$c = $registry[ $id ] ?? null;
	if ( is_array( $c ) ) {
		foreach ( $c['fields'] ?? [] as $f ) {
			if ( ( $f['type'] ?? '' ) === 'password' ) $keys[] = (string) $f['key'];
		}
	}
	return array_values( array_unique( $keys ) );
}

function nexus_crypto_encrypt_config( string $id, array $config ): array {
	if ( ! nexus_crypto_available() ) return $config;
	foreach ( nexus_crypto_secret_keys_for( $id ) as $k ) {
		if ( isset( $config[ $k ] ) && is_string( $config[ $k ] ) && $config[ $k ] !== '' && ! nexus_crypto_is_envelope( $config[ $k ] ) ) {
			$enc = nexus_crypto_encrypt( $config[ $k ] );
			if ( $enc !== false ) $config[ $k ] = $enc;
		}
	}
	return $config;
}

function nexus_crypto_decrypt_config( array $config ): array {
	if ( ! nexus_crypto_available() ) return $config;
	foreach ( $config as $k => $v ) {
		if ( is_string( $v ) && nexus_crypto_is_envelope( $v ) ) {
			$config[ $k ] = nexus_crypto_decrypt( $v );
		}
	}
	return $config;
}

// ─── base64-url helpers (URL-safe, no padding) ───────────────────────────

function _nexus_b64u( string $bin ): string {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}
function _nexus_b64u_decode( string $b64u ) {
	$pad = strlen( $b64u ) % 4;
	if ( $pad ) $b64u .= str_repeat( '=', 4 - $pad );
	return base64_decode( strtr( $b64u, '-_', '+/' ), true );
}
