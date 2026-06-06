<?php
/**
 * Nexus by Therum — preview-data tab renderers (AI / Payments / Apps / Vault / Webhooks / Audit).
 *
 * Demo data fixtures live at the bottom of this file — swap to real readers
 * (custom tables, encrypted options, etc.) when the underlying systems land.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  DEMO CARD GRIDS — AI / Payments / External Apps
// ═════════════════════════════════════════════════════════════════════════════

// AI / Payments / Apps — registry-driven, same card grid + Connect/Disconnect
// flow as CMS / Ecommerce / APIs. The hardcoded demo grids these replaced
// are preserved in git history; nexus_render_demo_cards() stays available
// for any future demo-only surface but isn't called from any built-in tab.

function nexus_render_ai_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'AI Tools', 'nexus' ), $tab['desc'], __( '＋ Add AI provider', 'nexus' ), 'ai' );
	nexus_render_conn_cards( nexus_connectors_by_category( 'ai' ) );
}

function nexus_render_payments_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'Payment Gateways', 'nexus' ), $tab['desc'], __( '＋ Add gateway', 'nexus' ), 'payments' );
	nexus_render_conn_cards( nexus_connectors_by_category( 'payments' ) );
}

function nexus_render_apps_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'External Apps', 'nexus' ), $tab['desc'], __( '＋ Add custom app', 'nexus' ), 'apps' );
	nexus_render_conn_cards( nexus_connectors_by_category( 'apps' ) );
}

function nexus_render_demo_cards( string $bucket, array $cards ): void {
	?>
	<div class="th-cn-grid" data-bucket="<?php echo esc_attr( $bucket ); ?>">
		<?php foreach ( $cards as $c ):
			$state = $c['state'] ?? 'not_connected';
			$cls   = 'th-cn-card';
			if ( $state === 'connected' ) $cls .= ' is-connected';
			if ( $state === 'reauth' )    $cls .= ' has-error';
			$icon_style = 'background:' . ( $c['bg'] ?? '#444' ) . ';';
			if ( ! empty( $c['color'] ) ) $icon_style .= 'color:' . $c['color'] . ';';
			if ( ! empty( $c['border'] ) ) $icon_style .= 'border:1px dashed rgba(0,0,0,.18);color:var(--tx3);';
		?>
		<div class="<?php echo esc_attr( $cls ); ?>" data-name="<?php echo esc_attr( strtolower( $c['name'] ) ); ?>">
			<span class="th-cn-card-status">
				<span class="th-cn-card-status-dot"></span>
				<?php
					switch ( $state ) {
						case 'connected':    esc_html_e( 'Connected', 'nexus' ); break;
						case 'reauth':       esc_html_e( 'Reauth needed', 'nexus' ); break;
						default:             esc_html_e( 'Not connected', 'nexus' );
					}
				?>
			</span>
			<div class="th-cn-card-icon" style="<?php echo esc_attr( $icon_style ); ?>"><?php echo esc_html( $c['icon'] ); ?></div>
			<h3 class="th-cn-card-name"><?php echo esc_html( $c['name'] ); ?></h3>
			<p class="th-cn-card-desc"><?php echo esc_html( $c['desc'] ?? '' ); ?></p>
			<div class="th-cn-card-foot">
				<?php
					$cta = $state === 'connected' ? __( 'Manage →', 'nexus' )
						: ( $state === 'reauth' ? __( 'Reconnect →', 'nexus' )
						: ( ( $c['name'] ?? '' ) === 'Request a connector' ? __( 'Request →', 'nexus' ) : __( 'Connect →', 'nexus' ) ) );
				?>
				<span class="th-cn-card-cta"><?php echo esc_html( $cta ); ?></span>
				<span class="th-cn-card-meta"><?php echo esc_html( ! empty( $c['meta'] ) ? $c['meta'] : ( $c['auth'] ?? '' ) ); ?></span>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php
}



