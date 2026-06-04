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


// ═════════════════════════════════════════════════════════════════════════════
//  MANAGE — Vault · Webhooks · Audit
// ═════════════════════════════════════════════════════════════════════════════

function nexus_render_keys_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'API keys vault', 'nexus' ), __( "Every credential held — masked on display, never logged. Encrypted with WordPress's SECURE_AUTH_KEY at rest.", 'nexus' ), __( '＋ Add key', 'nexus' ) );

	?>
	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Total credentials', 'nexus' ); ?></div><div class="th-cn-stat-n">11</div><div class="th-cn-stat-d"><?php esc_html_e( 'all encrypted at rest', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Rotated this month', 'nexus' ); ?></div><div class="th-cn-stat-n">3</div><div class="th-cn-stat-d is-ok"><?php esc_html_e( '↻ healthy cadence', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Expiring soon', 'nexus' ); ?></div><div class="th-cn-stat-n">2</div><div class="th-cn-stat-d is-wrn"><?php esc_html_e( 'within 30 days', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Failed reads', 'nexus' ); ?></div><div class="th-cn-stat-n">0</div><div class="th-cn-stat-d is-ok"><?php esc_html_e( 'last 7 days', 'nexus' ); ?></div></div>
	</div>

	<table class="th-cn-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Provider', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Type', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Key', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Created', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Expires', 'nexus' ); ?></th>
				<th style="text-align:right"><?php esc_html_e( 'Actions', 'nexus' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( nexus_demo_keys() as $k ):
				$exp_style = '';
				if ( $k['expires_state'] === 'wrn' ) $exp_style = 'color:var(--wrn,#f59e0b)';
				if ( $k['expires_state'] === 'err' ) $exp_style = 'color:#ef4444';
			?>
			<tr>
				<td><span class="th-cn-tbl-prov"><span class="th-cn-tbl-ico" style="background:<?php echo esc_attr( $k['bg'] ); ?><?php if ( ! empty( $k['color'] ) ) echo ';color:' . esc_attr( $k['color'] ); ?>"><?php echo esc_html( $k['icon'] ); ?></span><?php echo esc_html( $k['provider'] ); ?></span></td>
				<td><span class="th-cn-pill is-<?php echo esc_attr( $k['type_class'] ); ?>"><?php echo esc_html( $k['type'] ); ?></span></td>
				<td class="th-cn-tbl-key"><?php echo esc_html( $k['key'] ); ?></td>
				<td><?php echo esc_html( $k['created'] ); ?></td>
				<td><?php echo esc_html( $k['last_used'] ); ?></td>
				<td<?php if ( $exp_style ): ?> style="<?php echo esc_attr( $exp_style ); ?>"<?php endif; ?>><?php echo esc_html( $k['expires'] ); ?></td>
				<td class="th-cn-tbl-actions">
					<button class="th-cn-iconbtn" title="<?php esc_attr_e( 'Copy', 'nexus' ); ?>">⎘</button>
					<button class="th-cn-iconbtn" title="<?php esc_attr_e( 'Rotate', 'nexus' ); ?>">↻</button>
					<button class="th-cn-iconbtn is-danger" title="<?php esc_attr_e( 'Revoke', 'nexus' ); ?>">✕</button>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function nexus_render_webhooks_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'Webhooks log', 'nexus' ), __( 'Every webhook fired or received. Replay any 4xx, search by payload, inspect signatures.', 'nexus' ), __( '⬇ Export CSV', 'nexus' ) );

	?>
	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Events · 30d', 'nexus' ); ?></div><div class="th-cn-stat-n">2,341</div><div class="th-cn-stat-d"><?php esc_html_e( '~78 / day', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Success rate', 'nexus' ); ?></div><div class="th-cn-stat-n">98.4%</div><div class="th-cn-stat-d is-ok"><?php esc_html_e( '↑ 0.6%', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Failures · 24h', 'nexus' ); ?></div><div class="th-cn-stat-n">4</div><div class="th-cn-stat-d is-wrn"><?php esc_html_e( '2 retrying', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Avg latency', 'nexus' ); ?></div><div class="th-cn-stat-n">124ms</div><div class="th-cn-stat-d"><?php esc_html_e( 'p95: 380ms', 'nexus' ); ?></div></div>
	</div>

	<table class="th-cn-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Status', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Direction', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Provider · Event', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Endpoint', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Latency', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Time', 'nexus' ); ?></th>
				<th style="text-align:right"><?php esc_html_e( 'Actions', 'nexus' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( nexus_demo_webhooks() as $e ): ?>
			<tr>
				<td><span class="th-cn-pill is-<?php echo esc_attr( $e['status_class'] ); ?>"><?php echo esc_html( $e['status'] ); ?></span></td>
				<td><?php echo $e['direction'] === 'in' ? esc_html__( '← in', 'nexus' ) : esc_html__( '→ out', 'nexus' ); ?></td>
				<td><span class="th-cn-tbl-prov"><span class="th-cn-tbl-ico" style="background:<?php echo esc_attr( $e['bg'] ); ?><?php if ( ! empty( $e['color'] ) ) echo ';color:' . esc_attr( $e['color'] ); ?>"><?php echo esc_html( $e['icon'] ); ?></span><?php echo esc_html( $e['event'] ); ?></span></td>
				<td class="th-cn-tbl-key"><?php echo esc_html( $e['endpoint'] ); ?></td>
				<td><?php echo esc_html( $e['latency'] ); ?></td>
				<td><?php echo esc_html( $e['time'] ); ?></td>
				<td class="th-cn-tbl-actions">
					<button class="th-cn-iconbtn" title="<?php esc_attr_e( 'Inspect', 'nexus' ); ?>">👁</button>
					<button class="th-cn-iconbtn" title="<?php esc_attr_e( 'Replay', 'nexus' ); ?>">↻</button>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function nexus_render_audit_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'Audit log', 'nexus' ), __( 'Tamper-evident chronological log of every connector + credential lifecycle event.', 'nexus' ), __( '⬇ Export', 'nexus' ) );

	?>
	<div class="th-cn-stats">
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Total entries', 'nexus' ); ?></div><div class="th-cn-stat-n">488</div><div class="th-cn-stat-d"><?php esc_html_e( '7-day window', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Unique users', 'nexus' ); ?></div><div class="th-cn-stat-n">3</div><div class="th-cn-stat-d"><?php esc_html_e( '3 distinct accounts', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Connector changes', 'nexus' ); ?></div><div class="th-cn-stat-n">12</div><div class="th-cn-stat-d"><?php esc_html_e( '5 add · 4 rotate · 3 revoke', 'nexus' ); ?></div></div>
		<div class="th-cn-stat"><div class="th-cn-stat-l"><?php esc_html_e( 'Failed actions', 'nexus' ); ?></div><div class="th-cn-stat-n">2</div><div class="th-cn-stat-d is-wrn"><?php esc_html_e( 'permission denied', 'nexus' ); ?></div></div>
	</div>

	<table class="th-cn-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'User', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Action', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Resource', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'IP', 'nexus' ); ?></th>
				<th style="text-align:right"><?php esc_html_e( 'Detail', 'nexus' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( nexus_demo_audit() as $a ): ?>
			<tr>
				<td><?php echo esc_html( $a['when'] ); ?></td>
				<td><span class="th-cn-tbl-prov"><span class="th-cn-tbl-ico" style="background:<?php echo esc_attr( $a['user_bg'] ); ?>"><?php echo esc_html( $a['user_initial'] ); ?></span><?php echo esc_html( $a['user'] ); ?></span></td>
				<td><span class="th-cn-pill is-<?php echo esc_attr( $a['action_class'] ); ?>"><?php echo esc_html( $a['action'] ); ?></span></td>
				<td><?php echo esc_html( $a['resource'] ); ?></td>
				<td class="th-cn-tbl-key"><?php echo esc_html( $a['ip'] ); ?></td>
				<td class="th-cn-tbl-actions"><button class="th-cn-iconbtn">👁</button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}


// ═════════════════════════════════════════════════════════════════════════════
//  DEMO DATA — replace with real readers once the backing systems land
// ═════════════════════════════════════════════════════════════════════════════

function nexus_demo_keys(): array {
	return [
		[ 'provider' => 'Anthropic · Claude',      'icon' => 'A', 'bg' => '#cc785c', 'type' => 'API KEY', 'type_class' => '2xx', 'key' => 'sk-ant-•••••••••••••••a9F2',  'created' => 'Apr 28', 'last_used' => '2m ago',  'expires' => '—',              'expires_state' => '' ],
		[ 'provider' => 'OpenAI · ChatGPT',         'icon' => 'O', 'bg' => '#10a37f', 'type' => 'API KEY', 'type_class' => '2xx', 'key' => 'sk-proj-•••••••••••••••8x12', 'created' => 'Apr 26', 'last_used' => '14m ago', 'expires' => '—',              'expires_state' => '' ],
		[ 'provider' => 'Stripe · live',            'icon' => 'S', 'bg' => '#635bff', 'type' => 'OAUTH',   'type_class' => '3xx', 'key' => 'acct_•••••••••••••••2K7p',     'created' => 'Mar 14', 'last_used' => '8m ago',  'expires' => 'Refresh: never', 'expires_state' => '' ],
		[ 'provider' => 'Stripe · webhook secret',  'icon' => 'S', 'bg' => '#635bff', 'type' => 'HMAC',    'type_class' => '4xx', 'key' => 'whsec_•••••••••••••••B3vQ',    'created' => 'Mar 14', 'last_used' => '1h ago',  'expires' => 'Jun 11 · 29d',   'expires_state' => 'wrn' ],
		[ 'provider' => 'Plaid · sandbox',          'icon' => 'P', 'bg' => '#000',    'color' => '#fff', 'type' => 'OAUTH', 'type_class' => '3xx', 'key' => 'access-•••••••••••••••EXPIRED', 'created' => 'Feb 02', 'last_used' => '14d ago', 'expires' => 'Expired May 11', 'expires_state' => 'err' ],
		[ 'provider' => 'Mailchimp',                'icon' => 'M', 'bg' => '#ffe01b', 'color' => '#000', 'type' => 'API KEY', 'type_class' => '2xx', 'key' => '•••••••••••••••us21-9K',         'created' => 'Jan 18', 'last_used' => '32m ago', 'expires' => '—',              'expires_state' => '' ],
		[ 'provider' => 'Custom · Internal API',    'icon' => '＋','bg' => 'transparent', 'color' => 'var(--tx3)', 'type' => 'HMAC', 'type_class' => '4xx', 'key' => 'th_secret_•••••••••••••••', 'created' => 'Apr 03', 'last_used' => '3h ago',  'expires' => 'Jun 03 · 21d',   'expires_state' => 'wrn' ],
	];
}

function nexus_demo_webhooks(): array {
	return [
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'in',  'event' => 'Stripe · charge.succeeded',    'icon' => 'S', 'bg' => '#635bff',  'endpoint' => '/wp-json/nexus/v1/stripe',  'latency' => '89ms',         'time' => '2m ago' ],
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'out', 'event' => 'Slack · post.published',       'icon' => 'S', 'bg' => '#4a154b',  'endpoint' => 'hooks.slack.com/T7K…',      'latency' => '142ms',        'time' => '14m ago' ],
		[ 'status' => '201', 'status_class' => '2xx', 'direction' => 'in',  'event' => 'Mailchimp · subscribe',        'icon' => 'M', 'bg' => '#ffe01b',  'color' => '#000', 'endpoint' => '/wp-json/nexus/v1/mc',       'latency' => '67ms', 'time' => '38m ago' ],
		[ 'status' => '422', 'status_class' => '4xx', 'direction' => 'out', 'event' => 'Zapier · order.completed',     'icon' => 'Z', 'bg' => '#ff4a00',  'endpoint' => 'hooks.zapier.com/9k…',      'latency' => '2.1s',         'time' => '1h ago' ],
		[ 'status' => '503', 'status_class' => '5xx', 'direction' => 'out', 'event' => 'Custom · user.registered',     'icon' => '＋', 'bg' => 'transparent', 'color' => 'var(--tx3)', 'endpoint' => 'api.internal.com/u…', 'latency' => '10s · timeout', 'time' => '2h ago' ],
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'in',  'event' => 'Plaid · transaction.updated',  'icon' => 'P', 'bg' => '#000',     'color' => '#fff', 'endpoint' => '/wp-json/nexus/v1/plaid',    'latency' => '104ms', 'time' => '3h ago' ],
		[ 'status' => '200', 'status_class' => '2xx', 'direction' => 'out', 'event' => 'Anthropic · prompt.completed', 'icon' => 'A', 'bg' => '#cc785c',  'endpoint' => 'webhook.therum.local',      'latency' => '32ms',         'time' => '4h ago' ],
	];
}

function nexus_demo_audit(): array {
	return [
		[ 'when' => '2m ago',  'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'connect',  'action_class' => '2xx', 'resource' => 'Anthropic · Claude',        'ip' => '10.0.1.4' ],
		[ 'when' => '14m ago', 'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'rotate',   'action_class' => '3xx', 'resource' => 'Stripe · webhook secret',   'ip' => '10.0.1.4' ],
		[ 'when' => '1h ago',  'user' => 'editor',   'user_initial' => 'E', 'user_bg' => '#10b981', 'action' => 'view',     'action_class' => '2xx', 'resource' => 'Mailchimp audiences',       'ip' => '192.168.5.21' ],
		[ 'when' => '2h ago',  'user' => 'editor',   'user_initial' => 'E', 'user_bg' => '#10b981', 'action' => 'denied',   'action_class' => '4xx', 'resource' => 'API keys vault · raw read', 'ip' => '192.168.5.21' ],
		[ 'when' => '5h ago',  'user' => 'ops-bot',  'user_initial' => '⚙', 'user_bg' => '#888',    'action' => 'refresh',  'action_class' => '2xx', 'resource' => 'Plaid · OAuth token',       'ip' => 'cron' ],
		[ 'when' => '1d ago',  'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'revoke',   'action_class' => '5xx', 'resource' => 'Old Stripe key · sk_••E1',  'ip' => '10.0.1.4' ],
		[ 'when' => '2d ago',  'user' => 'bam',      'user_initial' => 'B', 'user_bg' => '#3b82f6', 'action' => 'connect',  'action_class' => '2xx', 'resource' => 'OpenAI · ChatGPT',          'ip' => '10.0.1.4' ],
	];
}
