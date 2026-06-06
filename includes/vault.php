<?php
/**
 * Nexus by Therum — credential vault + REST surface docs.
 *
 * Two read-only Manage tabs:
 *   - API keys vault — every configured connector with last-saved
 *     time, validation status, rotate link, re-verify button.
 *   - API & Webhooks  — documents the REST endpoints Nexus exposes,
 *     for integrators wiring their own services on top.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ─── API keys vault tab ──────────────────────────────────────────────────

function nexus_render_keys_tab( string $tab_id, array $tab ): void {
	nexus_page_head(
		__( 'API keys vault', 'nexus' ),
		__( "Every credential Nexus is holding. Secrets are masked; click a connector to jump to its card. Rotate via the provider's dashboard.", 'nexus' )
	);
	?>
	<div style="margin-bottom:14px;display:flex;justify-content:flex-end">
		<button type="button" class="th-button" data-nexus-health-sweep><?php esc_html_e( 'Run health check now →', 'nexus' ); ?></button>
	</div>
	<?php

	$registry  = nexus_connector_registry();
	$installed = 0;
	$validated = 0;
	$rows      = [];

	// Bulk-fetch every saved row in one query — was N+1 (one get_option
	// per registry entry) before 2.0.0.
	$all_saved = nexus_get_all_connectors();

	foreach ( $registry as $id => $c ) {
		if ( ! empty( $c['built_in'] ) ) continue;
		if ( ! empty( $c['bridge_only'] ) ) continue;
		$saved   = $all_saved[ $id ] ?? null;
		if ( ! $saved || empty( $saved['config'] ) ) continue;
		// Inline is_configured check — saved rows always have at least one non-empty value.
		$has_value = false;
		foreach ( (array) $saved['config'] as $v ) { if ( $v !== '' ) { $has_value = true; break; } }
		if ( ! $has_value ) continue;

		$installed++;
		$config  = $saved['config'];
		$updated = (int) ( $saved['updated'] ?? 0 );

		// Best-effort field summary — pull the FIRST password/text field
		// so we have something to mask. Don't reveal anything.
		$primary_field = '';
		$is_oauth      = ! empty( $config['oauth_access_token'] );
		foreach ( $c['fields'] ?? [] as $f ) {
			if ( in_array( $f['type'] ?? '', [ 'password', 'text' ], true ) ) {
				$primary_field = $f['label'] ?? $f['key'];
				break;
			}
		}
		$rows[] = [
			'id'        => $id,
			'name'      => $c['name'],
			'category'  => $c['category'],
			'updated'   => $updated,
			'oauth'     => $is_oauth,
			'fieldset'  => $primary_field,
			'docs'      => $c['docs'] ?? '',
			'health_ts'  => (int) ( $saved['last_health_check'] ?? 0 ),
			'health_ok'  => isset( $saved['last_health_ok'] ) ? (bool) $saved['last_health_ok'] : null,
			'health_msg' => (string) ( $saved['last_health_msg'] ?? '' ),
		];
		$validated++;  // currently every is_configured connector counts as "stored" — live validation isn't tracked yet
	}
	usort( $rows, fn( $a, $b ) => $b['updated'] <=> $a['updated'] );
	$expiring = array_filter( $rows, function( $r ) {
		// Simple heuristic — flag if older than 6 months.
		return $r['updated'] && ( time() - $r['updated'] ) > ( 180 * DAY_IN_SECONDS );
	} );
	?>
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px">
		<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px">
			<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:4px"><?php esc_html_e( 'Total credentials', 'nexus' ); ?></div>
			<div style="font-size:28px;font-weight:700"><?php echo (int) $installed; ?></div>
			<div style="font-size:11px;color:var(--tx3);margin-top:4px"><?php esc_html_e( 'encrypted-when-supported at rest', 'nexus' ); ?></div>
		</div>
		<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px">
			<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:4px"><?php esc_html_e( 'OAuth-connected', 'nexus' ); ?></div>
			<div style="font-size:28px;font-weight:700"><?php echo count( array_filter( $rows, fn( $r ) => $r['oauth'] ) ); ?></div>
			<div style="font-size:11px;color:var(--tx3);margin-top:4px"><?php esc_html_e( 'auto-refresh where supported', 'nexus' ); ?></div>
		</div>
		<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px">
			<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:4px"><?php esc_html_e( 'Older than 6 months', 'nexus' ); ?></div>
			<div style="font-size:28px;font-weight:700;color:<?php echo $expiring ? 'var(--wrn)' : 'var(--ok)'; ?>"><?php echo count( $expiring ); ?></div>
			<div style="font-size:11px;color:var(--tx3);margin-top:4px"><?php esc_html_e( 'consider rotating', 'nexus' ); ?></div>
		</div>
	</div>

	<table class="nexus-update-table" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:0 14px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Connector', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Category', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Auth', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Stored', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Updated', 'nexus' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ): ?>
				<tr><td colspan="6" style="padding:24px;text-align:center;color:var(--tx3)"><?php esc_html_e( 'No connectors configured yet. Wire one up under Connections.', 'nexus' ); ?></td></tr>
			<?php else: foreach ( $rows as $r ):
				$age_days = $r['updated'] ? floor( ( time() - $r['updated'] ) / DAY_IN_SECONDS ) : null;
				$is_old   = $age_days !== null && $age_days > 180;
				$jump     = add_query_arg( [ 'page' => 'nexus', 'tab' => $r['category'] ], admin_url( 'admin.php' ) );
			?>
			<tr>
				<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
				<td><code style="font-size:11px"><?php echo esc_html( $r['category'] ); ?></code></td>
				<td>
					<?php if ( $r['oauth'] ): ?>
						<span style="font-size:11px;color:var(--ac);font-weight:600">OAuth</span>
					<?php else: ?>
						<span style="font-size:11px;color:var(--tx3)"><?php echo esc_html( $r['fieldset'] ?: 'Key' ); ?></span>
					<?php endif; ?>
				</td>
				<td><span style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;color:var(--tx3)">••••••••</span></td>
				<td style="font-size:12px;color:<?php echo $is_old ? 'var(--wrn)' : 'var(--tx2)'; ?>">
					<?php echo $r['updated'] ? esc_html( human_time_diff( $r['updated'] ) . ' ago' ) : '—'; ?>
					<?php if ( $r['health_ts'] && $r['health_ok'] === false ): ?>
						<div style="font-size:10px;color:var(--err);margin-top:2px" title="<?php echo esc_attr( $r['health_msg'] ); ?>">✗ failed health check <?php echo esc_html( human_time_diff( $r['health_ts'] ) ); ?> ago</div>
					<?php elseif ( $r['health_ts'] && $r['health_ok'] === true ): ?>
						<div style="font-size:10px;color:var(--ok);margin-top:2px">✓ healthy</div>
					<?php endif; ?>
				</td>
				<td class="nexus-update-table-actions">
					<a href="<?php echo esc_url( $jump ); ?>" class="th-button" style="font-size:11px;padding:4px 10px"><?php esc_html_e( 'Open', 'nexus' ); ?></a>
					<?php if ( $r['docs'] ): ?>
						<a href="<?php echo esc_url( $r['docs'] ); ?>" target="_blank" rel="noopener" class="nexus-update-link"><?php esc_html_e( 'rotate ↗', 'nexus' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>
	<?php
}


// ─── API & Webhooks tab ──────────────────────────────────────────────────

function nexus_render_rest_tab( string $tab_id, array $tab ): void {
	nexus_page_head(
		__( 'API & Webhooks', 'nexus' ),
		__( 'REST endpoints Nexus exposes. Use these to wire your own services on top — webhook ingestion, feed delivery, OAuth callbacks.', 'nexus' )
	);
	$endpoints = [
		[
			'method' => 'GET',
			'path'   => 'nexus/v1/feed/<channel>?token=<auto>',
			'desc'   => 'Product feed in the channel\'s native format (XML / CSV). Token is per-feed; auto-minted on first save in the Channels tab.',
			'public' => true,
		],
		[
			'method' => 'POST',
			'path'   => 'nexus/v1/webhook/<connector>',
			'desc'   => 'Inbound webhook receiver. Per-provider signature verification (Stripe, Shopify, Slack, GitHub, PayPal, Coinbase Commerce, AnyPay, Klarna). Generic webhooks accepted with optional ?token=<shared_secret>.',
			'public' => true,
		],
		[
			'method' => 'GET',
			'path'   => 'nexus/v1/oauth-callback/<connector>',
			'desc'   => 'BYOA OAuth callback. State-token CSRF guard. Exchanges code for tokens, persists, redirects to admin.',
			'public' => true,
		],
		[
			'method' => 'GET',
			'path'   => 'nexus/v1/oauth-proxy-callback?payload=<signed>',
			'desc'   => 'Hosted-mode OAuth callback. HMAC-verified payload from the Therum proxy. Only active when NEXUS_OAUTH_PROXY_URL is defined.',
			'public' => true,
		],
	];
	?>

	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-bottom:18px">
		<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:8px"><?php esc_html_e( 'Base URL', 'nexus' ); ?></div>
		<code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;background:var(--sf2);padding:6px 10px;border-radius:6px;border:1px solid var(--bd)"><?php echo esc_html( rest_url() ); ?></code>
	</div>

	<?php foreach ( $endpoints as $ep ): ?>
	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:14px 18px;margin-bottom:10px">
		<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
			<span style="font-size:10px;font-weight:700;letter-spacing:.05em;background:var(--ac-s);color:var(--ac);padding:3px 8px;border-radius:4px"><?php echo esc_html( $ep['method'] ); ?></span>
			<code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px"><?php echo esc_html( $ep['path'] ); ?></code>
		</div>
		<p style="margin:0;font-size:12px;color:var(--tx2);line-height:1.55"><?php echo esc_html( $ep['desc'] ); ?></p>
	</div>
	<?php endforeach; ?>

	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-top:24px">
		<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:10px"><?php esc_html_e( 'Webhook URLs to register at providers', 'nexus' ); ?></div>
		<table style="width:100%;font-size:12px;border-collapse:collapse">
			<thead>
				<tr style="text-align:left;color:var(--tx3);font-size:11px">
					<th style="padding:6px 0;font-weight:600"><?php esc_html_e( 'Provider', 'nexus' ); ?></th>
					<th style="padding:6px 0;font-weight:600"><?php esc_html_e( 'URL', 'nexus' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( nexus_webhook_providers() as $c ): ?>
				<tr style="border-top:1px solid var(--bd)">
					<td style="padding:8px 0;font-weight:600"><?php echo esc_html( $c ); ?></td>
					<td style="padding:8px 0"><code style="font-size:11px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"><?php echo esc_html( nexus_webhook_url( $c ) ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
