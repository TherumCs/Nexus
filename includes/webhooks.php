<?php
/**
 * Nexus by Therum — inbound webhook receiver.
 *
 * One REST endpoint per connector at /wp-json/nexus/v1/webhook/<connector>.
 * Stripe, Shopify, Slack, GitHub, etc. POST events here; we verify per-
 * provider signature, log, fire `nexus_webhook_received` action, and
 * queue any heavy processing on the background queue.
 *
 * Adding a verifier:
 *   add_filter( 'nexus_webhook_verify_<connector>', function( $ok, $request, $config ) { … } );
 *
 * Built-in verifiers: stripe, shopify, slack, github, paypal,
 * coinbase-commerce, anypay, klarna. Generic webhook URL (no
 * verification) is also accepted for low-stakes use.
 *
 * Log lives in a custom table. Tab renderer surfaces last 500 with
 * status badges + replay button (Phase 2 — replay actually re-fires
 * the action; for now button is shell).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_WEBHOOK_DB_VERSION   = '1';
const NEXUS_WEBHOOK_RETAIN_DAYS  = 30;
const NEXUS_WEBHOOK_MAX_LOG_SIZE = 4096;  // bytes of payload stored per event

function nexus_webhook_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'nexus_webhook_log';
}

function nexus_webhook_ensure_table(): void {
	$installed = get_option( 'nexus_webhook_db_version', '' );
	if ( $installed === NEXUS_WEBHOOK_DB_VERSION ) return;

	global $wpdb;
	$table   = nexus_webhook_table();
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		ts BIGINT(20) UNSIGNED NOT NULL,
		connector VARCHAR(64) NOT NULL,
		event VARCHAR(96) NOT NULL DEFAULT '',
		verified TINYINT(1) NOT NULL DEFAULT 0,
		response_code SMALLINT(6) NOT NULL DEFAULT 0,
		payload MEDIUMTEXT NULL,
		PRIMARY KEY  (id),
		KEY ts (ts),
		KEY connector (connector)
	) {$charset};" );

	update_option( 'nexus_webhook_db_version', NEXUS_WEBHOOK_DB_VERSION, false );
}

function nexus_webhook_url( string $connector_id ): string {
	return rest_url( 'nexus/v1/webhook/' . sanitize_key( $connector_id ) );
}


// ─── REST receiver ───────────────────────────────────────────────────────

add_action( 'rest_api_init', function() {
	register_rest_route( 'nexus/v1', '/webhook/(?P<connector>[a-z0-9_-]+)', [
		'methods'             => [ 'POST', 'PUT' ],
		'callback'            => 'nexus_webhook_receive',
		'permission_callback' => '__return_true',
	] );
} );

function nexus_webhook_receive( WP_REST_Request $request ) {
	nexus_webhook_ensure_table();

	$connector_id = sanitize_key( $request['connector'] );
	$body         = $request->get_body();
	$json         = json_decode( $body, true );
	$saved        = nexus_get_connector( $connector_id );
	$config       = $saved['config'] ?? [];

	// Verify signature when the connector has a built-in verifier.
	// Verifiers return a string 'ok' (verified) or false (no good).
	$verifier = "nexus_webhook_verify_" . str_replace( '-', '_', $connector_id );
	$verified = false;
	if ( function_exists( $verifier ) ) {
		$verified = (bool) call_user_func( $verifier, $request, $config );
	} else {
		// No verifier — accept but flag as unverified. Useful for generic
		// catch-alls, Zapier-style webhooks, etc. Reject only when an
		// `auth_token` query param is required but absent (poor-man's auth).
		$expected_token = (string) ( $config['webhook_token'] ?? '' );
		if ( $expected_token !== '' ) {
			$got = (string) $request->get_param( 'token' );
			$verified = $got !== '' && hash_equals( $expected_token, $got );
		} else {
			$verified = true; // open mode — no token, no verifier
		}
	}

	// Best-effort event name pull (each provider names it differently).
	$event = '';
	if ( is_array( $json ) ) {
		$event = (string) ( $json['type']
			?? $json['event']
			?? $json['event_type']
			?? $json['action']
			?? '' );
	}
	if ( ! $event ) {
		$event = (string) $request->get_header( 'x-shopify-topic' )
			?: (string) $request->get_header( 'x-github-event' )
			?: '';
	}

	$response_code = $verified ? 200 : 401;

	global $wpdb;
	$wpdb->insert( nexus_webhook_table(), [
		'ts'            => time(),
		'connector'     => $connector_id,
		'event'         => substr( $event, 0, 96 ),
		'verified'      => $verified ? 1 : 0,
		'response_code' => $response_code,
		'payload'       => substr( $body, 0, NEXUS_WEBHOOK_MAX_LOG_SIZE ),
	], [ '%d', '%s', '%s', '%d', '%d', '%s' ] );

	if ( ! $verified ) {
		return new WP_REST_Response( [ 'error' => 'signature invalid' ], 401 );
	}

	// Surface to other plugins. Shop / fulfillment / notifications hook here.
	do_action( 'nexus_webhook_received', $connector_id, $event, $json, $body );

	// Queue any registered async processors.
	nexus_queue_async( 'nexus_webhook_process', [
		'connector' => $connector_id,
		'event'     => $event,
		'payload'   => $json,
	] );

	return new WP_REST_Response( [ 'received' => true, 'event' => $event ], 200 );
}

function nexus_webhook_recent( int $limit = 500 ): array {
	nexus_webhook_ensure_table();
	global $wpdb;
	$table = nexus_webhook_table();
	$limit = max( 1, min( 2000, $limit ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, ts, connector, event, verified, response_code FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	return is_array( $rows ) ? $rows : [];
}

function nexus_webhook_count(): int {
	nexus_webhook_ensure_table();
	global $wpdb;
	$table = nexus_webhook_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}


// ─── Per-provider verifiers ──────────────────────────────────────────────
//
// Each returns true if the request signature matches what the provider
// would have signed. False = reject.

function nexus_webhook_verify_stripe( WP_REST_Request $req, array $config ): bool {
	$secret = (string) ( $config['webhook_secret'] ?? '' );
	if ( ! $secret ) return false;
	$header = (string) $req->get_header( 'stripe-signature' );
	if ( ! $header ) return false;
	// Stripe sig format: t=<ts>,v1=<hex>
	$parts = [];
	foreach ( explode( ',', $header ) as $kv ) {
		[ $k, $v ] = array_pad( explode( '=', $kv, 2 ), 2, '' );
		$parts[ trim( $k ) ] = trim( $v );
	}
	if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) return false;
	$signed_payload = $parts['t'] . '.' . $req->get_body();
	$expected = hash_hmac( 'sha256', $signed_payload, $secret );
	return hash_equals( $expected, $parts['v1'] );
}

function nexus_webhook_verify_shopify( WP_REST_Request $req, array $config ): bool {
	$secret = (string) ( $config['webhook_secret'] ?? $config['access_token'] ?? '' );
	if ( ! $secret ) return false;
	$header = (string) $req->get_header( 'x-shopify-hmac-sha256' );
	if ( ! $header ) return false;
	$expected = base64_encode( hash_hmac( 'sha256', $req->get_body(), $secret, true ) );
	return hash_equals( $expected, $header );
}

function nexus_webhook_verify_slack( WP_REST_Request $req, array $config ): bool {
	$secret = (string) ( $config['signing_secret'] ?? '' );
	if ( ! $secret ) return false;
	$ts  = (string) $req->get_header( 'x-slack-request-timestamp' );
	$sig = (string) $req->get_header( 'x-slack-signature' );
	if ( ! $ts || ! $sig ) return false;
	// Reject events older than 5 minutes (replay defense).
	if ( abs( time() - (int) $ts ) > 300 ) return false;
	$basestring = 'v0:' . $ts . ':' . $req->get_body();
	$expected   = 'v0=' . hash_hmac( 'sha256', $basestring, $secret );
	return hash_equals( $expected, $sig );
}

function nexus_webhook_verify_github( WP_REST_Request $req, array $config ): bool {
	$secret = (string) ( $config['webhook_secret'] ?? '' );
	if ( ! $secret ) return false;
	$sig = (string) $req->get_header( 'x-hub-signature-256' );
	if ( ! $sig ) return false;
	$expected = 'sha256=' . hash_hmac( 'sha256', $req->get_body(), $secret );
	return hash_equals( $expected, $sig );
}

function nexus_webhook_verify_paypal( WP_REST_Request $req, array $config ): bool {
	// PayPal webhooks require an API call back to PayPal to verify (no
	// inline HMAC). The full verification is in their docs; for Phase 1
	// we accept if the connector has a webhook_id configured and trust
	// the source. Hardening lands in Phase 2.
	return ! empty( $config['webhook_id'] );
}

function nexus_webhook_verify_coinbase_commerce( WP_REST_Request $req, array $config ): bool {
	$secret = (string) ( $config['webhook_secret'] ?? '' );
	if ( ! $secret ) return false;
	$sig = (string) $req->get_header( 'x-cc-webhook-signature' );
	if ( ! $sig ) return false;
	$expected = hash_hmac( 'sha256', $req->get_body(), $secret );
	return hash_equals( $expected, $sig );
}

function nexus_webhook_verify_anypay( WP_REST_Request $req, array $config ): bool {
	$secret = (string) ( $config['webhook_secret'] ?? '' );
	if ( ! $secret ) return false;
	$sig = (string) $req->get_header( 'x-anypay-signature' );
	if ( ! $sig ) return false;
	$expected = hash_hmac( 'sha256', $req->get_body(), $secret );
	return hash_equals( $expected, $sig );
}

function nexus_webhook_verify_klarna( WP_REST_Request $req, array $config ): bool {
	// Klarna webhooks ride a shared "Webhook Authorization Header" set up
	// at the merchant portal. Verify it matches.
	$expected = (string) ( $config['webhook_token'] ?? '' );
	if ( ! $expected ) return false;
	$got = (string) $req->get_header( 'authorization' );
	return $got !== '' && hash_equals( 'Bearer ' . $expected, $got );
}


// ─── Webhooks log tab ────────────────────────────────────────────────────

function nexus_render_webhooks_tab( string $tab_id, array $tab ): void {
	nexus_page_head(
		__( 'Webhooks log', 'nexus' ),
		__( 'Inbound webhook event stream. Each row is one delivery — signature verified per provider, payload kept for 30 days.', 'nexus' )
	);
	$rows  = nexus_webhook_recent( 500 );
	$total = nexus_webhook_count();
	$verified = array_filter( $rows, fn( $r ) => (int) $r['verified'] === 1 );
	$by_connector = [];
	foreach ( $rows as $r ) $by_connector[ $r['connector'] ] = ( $by_connector[ $r['connector'] ] ?? 0 ) + 1;
	arsort( $by_connector );
	?>
	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-bottom:16px;display:flex;gap:28px;flex-wrap:wrap">
		<div>
			<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3)"><?php esc_html_e( 'Total events', 'nexus' ); ?></div>
			<div style="font-size:24px;font-weight:700"><?php echo number_format( $total ); ?></div>
		</div>
		<div>
			<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3)"><?php esc_html_e( 'Verified (last 500)', 'nexus' ); ?></div>
			<div style="font-size:24px;font-weight:700;color:var(--ok)"><?php echo number_format( count( $verified ) ); ?> <small style="font-size:13px;color:var(--tx3);font-weight:500">/ <?php echo number_format( count( $rows ) ); ?></small></div>
		</div>
		<div style="flex:1;min-width:200px">
			<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:6px"><?php esc_html_e( 'Top sources', 'nexus' ); ?></div>
			<?php foreach ( array_slice( $by_connector, 0, 5, true ) as $cid => $count ): ?>
				<div style="display:flex;justify-content:space-between;font-size:12px;padding:2px 0"><code style="background:var(--sf2);padding:1px 5px;border-radius:3px;border:1px solid var(--bd);font-size:11px"><?php echo esc_html( $cid ); ?></code> <span style="color:var(--tx3)"><?php echo $count; ?></span></div>
			<?php endforeach; ?>
			<?php if ( empty( $by_connector ) ): ?><div style="font-size:12px;color:var(--tx3)"><?php esc_html_e( 'No events yet.', 'nexus' ); ?></div><?php endif; ?>
		</div>
	</div>

	<table class="nexus-update-table" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:0 14px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Source', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Event', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Status', 'nexus' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ): ?>
				<tr><td colspan="5" style="padding:20px;color:var(--tx3);text-align:center">
					<?php esc_html_e( 'No webhook events yet. Point your providers at:', 'nexus' ); ?><br>
					<code style="margin-top:6px;display:inline-block"><?php echo esc_html( rest_url( 'nexus/v1/webhook/<connector>' ) ); ?></code>
				</td></tr>
			<?php else: foreach ( $rows as $row ):
				$ok = (int) $row['verified'] === 1;
			?>
			<tr>
				<td><?php echo esc_html( human_time_diff( (int) $row['ts'] ) . ' ago' ); ?></td>
				<td><code><?php echo esc_html( $row['connector'] ); ?></code></td>
				<td style="font-size:12px"><?php echo esc_html( $row['event'] ?: '—' ); ?></td>
				<td>
					<?php if ( $ok ): ?>
						<span style="color:var(--ok);font-weight:600;font-size:11px">✓ <?php echo (int) $row['response_code']; ?></span>
					<?php else: ?>
						<span style="color:var(--err);font-weight:600;font-size:11px">✗ <?php echo (int) $row['response_code']; ?></span>
					<?php endif; ?>
				</td>
				<td class="nexus-update-table-actions">
					<button type="button" class="th-button" style="font-size:11px;padding:4px 9px" data-nexus-webhook-replay="<?php echo (int) $row['id']; ?>"><?php esc_html_e( 'Replay', 'nexus' ); ?></button>
				</td>
			</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>
	<?php
}


// ─── Replay ──────────────────────────────────────────────────────────────
//
// Re-fires the nexus_webhook_received action with the stored payload.
// Useful when downstream processing failed the first time and the
// failure has been fixed. Replays are themselves logged as new rows
// with the same connector/event but a "replay" tag.

add_action( 'wp_ajax_nexus_webhook_replay', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );
	nexus_webhook_ensure_table();

	$id = (int) ( $_POST['id'] ?? 0 );
	if ( ! $id ) wp_send_json_error( [ 'message' => 'Missing id.' ] );
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . nexus_webhook_table() . " WHERE id = %d", $id ), ARRAY_A );
	if ( ! $row ) wp_send_json_error( [ 'message' => 'Webhook not found.' ] );

	$json = json_decode( (string) $row['payload'], true );
	do_action( 'nexus_webhook_received', $row['connector'], $row['event'] ?: '', $json, $row['payload'] );

	// Log the replay so it's visible in the audit + webhook trail.
	$wpdb->insert( nexus_webhook_table(), [
		'ts'            => time(),
		'connector'     => $row['connector'],
		'event'         => 'replay · ' . substr( (string) $row['event'], 0, 88 ),
		'verified'      => 1,
		'response_code' => 200,
		'payload'       => $row['payload'],
	], [ '%d', '%s', '%s', '%d', '%d', '%s' ] );
	if ( function_exists( 'nexus_audit_log' ) ) nexus_audit_log( 'webhook.replayed', $row['connector'] . ( $row['event'] ? ' · ' . $row['event'] : '' ) );

	wp_send_json_success( [ 'message' => 'Replayed.' ] );
} );


// Background purge — once a day, drop old events.
add_action( 'nexus_webhook_prune', function() {
	nexus_webhook_ensure_table();
	global $wpdb;
	$cutoff = time() - ( NEXUS_WEBHOOK_RETAIN_DAYS * DAY_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( "DELETE FROM " . nexus_webhook_table() . " WHERE ts < %d", $cutoff ) );
} );
add_action( 'init', function() {
	nexus_queue_recurring( 'nexus_webhook_prune', [], DAY_IN_SECONDS );
} );
