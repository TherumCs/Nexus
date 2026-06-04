<?php
/**
 * Nexus by Therum — admin page bootstrap, tab routing, registry-driven cards.
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN MENU + ASSETS
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function() {
	add_menu_page(
		__( 'Connections', 'nexus' ),
		__( 'Connections', 'nexus' ),
		'manage_options',
		'nexus',
		[ 'Nexus_Connections_Page', 'render' ],
		'dashicons-admin-links',
		76
	);
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'nexus' ) return;

	$css = NEXUS_DIR . 'assets/admin.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'nexus-admin', NEXUS_URL . 'assets/admin.css', [], filemtime( $css ) );
	}

	$js = NEXUS_DIR . 'assets/admin.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'nexus-admin', NEXUS_URL . 'assets/admin.js', [], filemtime( $js ), true );
		wp_localize_script( 'nexus-admin', 'NexusAdmin', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nexus_connector' ),
		] );
	}
} );


// ═════════════════════════════════════════════════════════════════════════════
//  PAGE CLASS — tab registry + router + render (mirrors Therum_Connections_Page)
// ═════════════════════════════════════════════════════════════════════════════

if ( ! class_exists( 'Nexus_Connections_Page' ) ) :

final class Nexus_Connections_Page {

	/** @var array<string,array> */
	private static $tabs = [];

	public static function register( string $id, array $args ): void {
		self::$tabs[ $id ] = wp_parse_args( $args, [
			'label'    => ucfirst( $id ),
			'section'  => 'connectors',
			'priority' => 100,
			'render'   => null,
			'desc'     => '',
			'count'    => '',
		] );
	}

	public static function tabs(): array {
		$tabs = apply_filters( 'nexus_connections_page_tabs', self::$tabs );
		uasort( $tabs, fn( $a, $b ) => (int)($a['priority'] ?? 100) <=> (int)($b['priority'] ?? 100) );
		return $tabs;
	}

	public static function current_tab_id(): string {
		$tabs = self::tabs();
		$want = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		if ( $want && isset( $tabs[ $want ] ) ) return $want;
		return $want ?: ( array_key_first( $tabs ) ?: '' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nexus' ) );
		}
		$tabs = self::tabs();
		$cur  = self::current_tab_id();

		// Status summary + per-tab counts (used by the sidebar nav). We compute
		// configured/total per category so the "1 / 4" pills reflect reality
		// instead of stale hardcoded strings the registration step used to set.
		$connected   = 0;
		$total       = 0;
		$by_category = [];
		foreach ( nexus_connector_registry() as $c ) {
			if ( ! empty( $c['built_in'] ) ) continue;

			// Bridge-only connectors count in the totals only when one of
			// their bridge platforms is live on this site — otherwise they
			// shouldn't drag the X/Y ratio down (no API to configure).
			if ( ! empty( $c['bridge_only'] ) ) {
				if ( ! nexus_bridge_active_for( $c ) ) continue;
				$total++;
				$connected++;
				$cat = $c['category'] ?? '';
				if ( $cat ) {
					$by_category[ $cat ]['total'] = ( $by_category[ $cat ]['total'] ?? 0 ) + 1;
					$by_category[ $cat ]['done']  = ( $by_category[ $cat ]['done']  ?? 0 ) + 1;
				}
				continue;
			}

			$total++;
			$cat = $c['category'] ?? '';
			if ( $cat ) {
				$by_category[ $cat ]['total'] = ( $by_category[ $cat ]['total'] ?? 0 ) + 1;
				$by_category[ $cat ]['done']  = $by_category[ $cat ]['done']  ?? 0;
			}
			if ( nexus_connector_is_configured( $c['id'] ) ) {
				$connected++;
				if ( $cat ) $by_category[ $cat ]['done']++;
			}
		}
		// Overwrite the static `count` on each tab whose id matches a category.
		foreach ( $tabs as $tab_id => &$tab_row ) {
			if ( isset( $by_category[ $tab_id ] ) ) {
				$bc = $by_category[ $tab_id ];
				$tab_row['count'] = $bc['done'] . ' / ' . $bc['total'];
			}
		}
		unset( $tab_row );
		?>
		<div class="wrap"><div class="th-cx" data-nexus>
			<div class="th-cx-head">
				<div>
					<div class="th-cx-eyebrow"><?php esc_html_e( 'Admin · Connections', 'nexus' ); ?></div>
					<h1 class="th-cx-title"><?php esc_html_e( 'Connections', 'nexus' ); ?></h1>
					<p class="th-cx-sub"><?php esc_html_e( 'Wire WordPress to the outside world. AI models, APIs, payment gateways, and external apps — all behind one canonical surface with saved credentials per connector.', 'nexus' ); ?></p>
				</div>
				<div class="th-cn-status-strip">
					<strong><?php echo (int) $connected; ?></strong> <?php esc_html_e( 'connected', 'nexus' ); ?>
					<span style="opacity:.4">·</span>
					<strong><?php echo (int) $total; ?></strong> <?php esc_html_e( 'available', 'nexus' ); ?>
				</div>
			</div>

			<div class="th-cx-grid">
				<?php self::render_nav( $tabs, $cur ); ?>

				<main class="th-cx-main">
					<?php
					$tab = $tabs[ $cur ] ?? null;
					if ( $tab && is_callable( $tab['render'] ) ) {
						call_user_func( $tab['render'], $cur, $tab );
					} else {
						self::render_stub( $cur, $tab );
					}
					?>
				</main>
			</div>
		</div></div>

		<?php
		// Inject the Add-custom modal once per render. JS open/close lives
		// in assets/admin.js; data attributes wire the trigger buttons in
		// nexus_page_head() and the card foot actions to this single modal.
		self::render_add_custom_modal();
		?>
		<?php
	}

	/**
	 * Add / Edit a user-defined custom connector. Single modal handles both
	 * flows — `data-mode="add"` / `data-mode="edit"` toggles the header text
	 * and the hidden `editing_slug` field. Custom-card data is bridged into
	 * JS via the existing card markup (data-connector + a small inline blob
	 * the JS reads via window.NexusCustomConnectors).
	 */
	private static function render_add_custom_modal(): void {
		// Inline catalog of all custom connectors so the JS can pre-fill the
		// edit form without round-tripping to the server.
		$customs = nexus_get_custom_connectors();
		$nonce   = wp_create_nonce( 'nexus_connector' );
		?>
		<div id="nexus-modal" class="nexus-modal" hidden role="dialog" aria-modal="true" aria-labelledby="nexus-modal-title">
			<div class="nexus-modal-backdrop" data-nexus-modal-close></div>
			<div class="nexus-modal-card">
				<div class="nexus-modal-head">
					<div>
						<div class="nexus-modal-eyebrow"><?php esc_html_e( 'Custom connector', 'nexus' ); ?></div>
						<h2 id="nexus-modal-title" class="nexus-modal-title"><?php esc_html_e( 'Add custom connector', 'nexus' ); ?></h2>
					</div>
					<button type="button" class="nexus-modal-close" data-nexus-modal-close aria-label="<?php esc_attr_e( 'Close', 'nexus' ); ?>">×</button>
				</div>
				<form class="nexus-modal-form" data-nexus-custom-form>
					<input type="hidden" name="action" value="nexus_custom_add">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<input type="hidden" name="category" value="">
					<input type="hidden" name="editing_slug" value="">

					<label class="nexus-field">
						<span><?php esc_html_e( 'Display name', 'nexus' ); ?></span>
						<input name="name" type="text" required placeholder="<?php esc_attr_e( 'e.g. Internal CRM', 'nexus' ); ?>" autocomplete="off">
					</label>
					<div class="nexus-field-row">
						<label class="nexus-field">
							<span><?php esc_html_e( 'Slug', 'nexus' ); ?> <small><?php esc_html_e( '(lowercase, hyphens)', 'nexus' ); ?></small></span>
							<input name="slug" type="text" placeholder="<?php esc_attr_e( 'internal-crm', 'nexus' ); ?>" autocomplete="off">
						</label>
						<label class="nexus-field">
							<span><?php esc_html_e( 'Brand color', 'nexus' ); ?></span>
							<input name="color" type="color" value="#6366f1">
						</label>
					</div>
					<label class="nexus-field">
						<span><?php esc_html_e( 'Short description', 'nexus' ); ?> <small><?php esc_html_e( '(optional)', 'nexus' ); ?></small></span>
						<input name="desc" type="text" placeholder="<?php esc_attr_e( 'What this connector talks to', 'nexus' ); ?>" autocomplete="off">
					</label>

					<div class="nexus-field">
						<span><?php esc_html_e( 'Credentials', 'nexus' ); ?> <small><?php esc_html_e( '(1–4 fields · row 1 required · leave label blank to skip)', 'nexus' ); ?></small></span>
						<div class="nexus-cred-rows">
							<?php
							$cred_defaults = [
								[ 'label' => 'API Key', 'type' => 'password' ],
								[ 'label' => '',        'type' => 'password' ],
								[ 'label' => '',        'type' => 'password' ],
								[ 'label' => '',        'type' => 'text'     ],
							];
							foreach ( $cred_defaults as $i => $cd ):
								$idx = $i + 1;
								$placeholders = [ 'API Key', 'Client Secret', 'Auth Secret', 'Workspace ID' ];
							?>
							<div class="nexus-cred-row" data-cred-row="<?php echo (int) $idx; ?>">
								<input
									name="cred_label_<?php echo (int) $idx; ?>"
									type="text"
									class="nexus-cred-label"
									placeholder="<?php echo esc_attr( $placeholders[ $i ] ?? 'Label' ); ?>"
									value="<?php echo esc_attr( $cd['label'] ); ?>"
									autocomplete="off"
									<?php echo $idx === 1 ? 'required' : ''; ?>>
								<select name="cred_type_<?php echo (int) $idx; ?>" class="nexus-cred-type">
									<option value="password" <?php selected( $cd['type'], 'password' ); ?>><?php esc_html_e( 'Secret', 'nexus' ); ?></option>
									<option value="text"     <?php selected( $cd['type'], 'text' );     ?>><?php esc_html_e( 'Plain', 'nexus' ); ?></option>
								</select>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<label class="nexus-field">
						<span><?php esc_html_e( 'Base URL', 'nexus' ); ?> <small><?php esc_html_e( '(optional — adds a URL input on the card)', 'nexus' ); ?></small></span>
						<input name="base_url" type="url" placeholder="https://api.example.com" autocomplete="off">
					</label>
					<label class="nexus-field">
						<span><?php esc_html_e( 'Docs URL', 'nexus' ); ?> <small><?php esc_html_e( '(optional)', 'nexus' ); ?></small></span>
						<input name="docs" type="url" placeholder="https://…" autocomplete="off">
					</label>

					<div class="nexus-modal-err" data-nexus-err hidden></div>
					<div class="nexus-modal-actions">
						<button type="button" class="th-button" data-nexus-modal-close><?php esc_html_e( 'Cancel', 'nexus' ); ?></button>
						<button type="submit" class="th-button th-button-primary" data-nexus-custom-save><?php esc_html_e( 'Add connector', 'nexus' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<script>
		window.NexusCustomConnectors = <?php echo wp_json_encode( $customs ); ?>;
		window.NexusAjaxNonce = <?php echo wp_json_encode( $nonce ); ?>;
		</script>
		<?php
	}

	private static function render_nav( array $tabs, string $cur ): void {
		$sections = [
			'connectors' => __( 'Connections', 'nexus' ),
			'channels'   => __( 'Channels', 'nexus' ),
			'checkout'   => __( 'Checkout', 'nexus' ),
			'manage'     => __( 'Manage', 'nexus' ),
		];
		$grouped = [];
		foreach ( $tabs as $id => $t ) {
			$grouped[ $t['section'] ?? 'connectors' ][ $id ] = $t;
		}
		?>
		<aside class="th-cx-nav">
			<?php foreach ( $sections as $sect_id => $sect_label ):
				if ( empty( $grouped[ $sect_id ] ) ) continue; ?>
				<div class="th-cx-nav-section"><?php echo esc_html( $sect_label ); ?></div>
				<?php foreach ( $grouped[ $sect_id ] as $id => $t ):
					$href = add_query_arg( [ 'page' => 'nexus', 'tab' => $id ], admin_url( 'admin.php' ) );
					$cls  = 'th-cx-nav-item' . ( $cur === $id ? ' is-active' : '' );
					$dot  = $t['dot'] ?? '';
					?>
					<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $href ); ?>">
						<span class="th-cx-nav-item-dot"<?php if ( $dot ): ?> style="background:<?php echo esc_attr( $dot ); ?>;opacity:1"<?php endif; ?>></span>
						<?php echo esc_html( $t['label'] ); ?>
						<?php if ( ! empty( $t['count'] ) ): ?>
							<span class="th-cx-nav-item-count"><?php echo esc_html( $t['count'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
				<?php if ( $sect_id !== array_key_last( $sections ) && ! empty( $grouped[ $sect_id ] ) ): ?>
					<div class="th-cx-nav-divider"></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</aside>
		<?php
	}

	private static function render_stub( string $id, ?array $t ): void {
		$label = $t['label'] ?? ucfirst( $id );
		?>
		<div class="th-cx-page-head">
			<div>
				<div class="th-cx-page-eyebrow"><?php esc_html_e( 'Connections', 'nexus' ); ?> · <?php echo esc_html( $label ); ?></div>
				<h2 class="th-cx-page-title"><?php echo esc_html( $label ); ?></h2>
				<p class="th-cx-page-sub"><?php echo esc_html( $t['desc'] ?? __( 'Coming soon.', 'nexus' ) ); ?></p>
			</div>
		</div>
		<div class="th-cx-stub">
			<div class="th-cx-stub-mark"><?php esc_html_e( 'Scaffold', 'nexus' ); ?></div>
			<h4 class="th-cx-stub-title"><?php echo esc_html( $label ); ?>.</h4>
			<p class="th-cx-stub-sub"><?php esc_html_e( 'This surface is registered but not yet wired up.', 'nexus' ); ?></p>
		</div>
		<?php
	}
}

endif;


// ═════════════════════════════════════════════════════════════════════════════
//  TAB REGISTRATIONS
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'init', function() {
	if ( ! class_exists( 'Nexus_Connections_Page' ) ) return;

	// Connectors section — registry-driven (real working save/delete UI)
	Nexus_Connections_Page::register( 'cms', [
		'label'    => __( 'Connect CMS', 'nexus' ),
		'section'  => 'connectors',
		'priority' => 5,
		'dot'      => '#0ea5e9',
		'desc'     => __( 'WordPress, Drupal, Ghost, Webflow, Contentful — read + write content through these adapters.', 'nexus' ),
		'render'   => 'nexus_render_cms_tab',
	] );

	Nexus_Connections_Page::register( 'ai', [
		'label'    => __( 'AI Tools', 'nexus' ),
		'section'  => 'connectors',
		'priority' => 10,
		'count'    => '2 / 4',
		'dot'      => '#10a37f',
		'desc'     => __( 'Language models you can query from anywhere on the site. Bring your own API key or OAuth credentials.', 'nexus' ),
		'render'   => 'nexus_render_ai_tab',
	] );

	Nexus_Connections_Page::register( 'apis', [
		'label'    => __( 'APIs', 'nexus' ),
		'section'  => 'connectors',
		'priority' => 20,
		'count'    => '1 / 4',
		'dot'      => '#1a82e2',
		'desc'     => __( 'Print-on-demand, payments, email, and custom REST endpoints.', 'nexus' ),
		'render'   => 'nexus_render_apis_tab',
	] );

	Nexus_Connections_Page::register( 'ecommerce', [
		'label'    => __( 'Connect Ecommerce', 'nexus' ),
		'section'  => 'connectors',
		'priority' => 25,
		'dot'      => '#f59e0b',
		'desc'     => __( 'WooCommerce, Shopify, Amazon, Etsy, Square — pull orders, products, customers.', 'nexus' ),
		'render'   => 'nexus_render_ecommerce_tab',
	] );

	Nexus_Connections_Page::register( 'payments', [
		'label'    => __( 'Payment Gateways', 'nexus' ),
		'section'  => 'connectors',
		'priority' => 30,
		'count'    => '1 / 4',
		'dot'      => '#635bff',
		'desc'     => __( 'Money in / money out. Account dashboards embed directly inside the admin once OAuth completes.', 'nexus' ),
		'render'   => 'nexus_render_payments_tab',
	] );

	Nexus_Connections_Page::register( 'apps', [
		'label'    => __( 'External Apps', 'nexus' ),
		'section'  => 'connectors',
		'priority' => 40,
		'count'    => '0 / 6',
		'dot'      => '#a855f7',
		'desc'     => __( 'Productivity + collaboration. Read + render their data inside the admin via OAuth.', 'nexus' ),
		'render'   => 'nexus_render_apps_tab',
	] );

	// Manage section
	Nexus_Connections_Page::register( 'rest', [
		'label'    => __( 'API & Webhooks', 'nexus' ),
		'section'  => 'manage',
		'priority' => 50,
		'desc'     => __( 'REST API surface, headless mode, CORS origins, outbound webhooks.', 'nexus' ),
	] );

	Nexus_Connections_Page::register( 'keys', [
		'label'    => __( 'API keys vault', 'nexus' ),
		'section'  => 'manage',
		'priority' => 60,
		'count'    => '11',
		'desc'     => __( 'Encrypted credential vault — masked, audited, rotatable.', 'nexus' ),
		'render'   => 'nexus_render_keys_tab',
	] );

	Nexus_Connections_Page::register( 'webhooks', [
		'label'    => __( 'Webhooks log', 'nexus' ),
		'section'  => 'manage',
		'priority' => 70,
		'count'    => '2.3k',
		'desc'     => __( 'Inbound + outbound webhook event stream with replay.', 'nexus' ),
		'render'   => 'nexus_render_webhooks_tab',
	] );

	Nexus_Connections_Page::register( 'audit', [
		'label'    => __( 'Audit log', 'nexus' ),
		'section'  => 'manage',
		'priority' => 80,
		'count'    => '488',
		'desc'     => __( 'Tamper-evident connector + credential lifecycle log.', 'nexus' ),
		'render'   => 'nexus_render_audit_tab',
	] );

	Nexus_Connections_Page::register( 'updates', [
		'label'    => __( 'Updates', 'nexus' ),
		'section'  => 'manage',
		'priority' => 90,
		'desc'     => __( 'Pull the latest release from GitHub or install a plugin zip.', 'nexus' ),
		'render'   => 'nexus_render_updates_tab',
	] );

	// Channels — product-feed generation for marketplaces / social commerce
	// (Google Shopping, Meta Catalog, Pinterest, TikTok Shop, Bing).
	// Replaces the need for a separate plugin like CTX Feed.
	Nexus_Connections_Page::register( 'feeds', [
		'label'    => __( 'Product feeds', 'nexus' ),
		'section'  => 'channels',
		'priority' => 10,
		'dot'      => '#1877f2',
		'desc'     => __( 'Generate the product feeds that Google Merchant Center, Meta Catalog, Pinterest, TikTok Shop, and Bing subscribe to. Built-in mapping with sensible fallbacks so products without GTIN/MPN/brand still validate.', 'nexus' ),
		'render'   => 'nexus_render_channels_tab',
	] );

	// Checkout — bundled multi-method checkout (Card / Wallets / BNPL /
	// Bank / Crypto / P2P). Methods light up as their backing Nexus
	// connectors are configured. Replaces what WooPayments does, with
	// way more rails.
	Nexus_Connections_Page::register( 'checkout-experience', [
		'label'    => __( 'Checkout experience', 'nexus' ),
		'section'  => 'checkout',
		'priority' => 10,
		'dot'      => '#e83b3b',
		'desc'     => __( 'Multi-rail checkout. Card, wallets, BNPL, bank transfer, crypto, P2P — all in one form. Available as the [nexus_checkout] shortcode.', 'nexus' ),
		'render'   => 'nexus_render_checkout_tab',
	] );
}, 20 );


// ═════════════════════════════════════════════════════════════════════════════
//  REGISTRY-DRIVEN TAB RENDERERS (CMS / Ecommerce / APIs)
// ═════════════════════════════════════════════════════════════════════════════

function nexus_render_cms_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'Connect CMS', 'nexus' ), $tab['desc'], __( '＋ Add custom CMS', 'nexus' ), 'cms' );
	nexus_render_conn_cards( nexus_connectors_by_category( 'cms' ) );
}

function nexus_render_ecommerce_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'Connect Ecommerce', 'nexus' ), $tab['desc'], __( '＋ Add custom store', 'nexus' ), 'ecommerce' );
	nexus_render_conn_cards( nexus_connectors_by_category( 'ecommerce' ) );
}

function nexus_render_apis_tab( string $tab_id, array $tab ): void {
	nexus_page_head( __( 'APIs', 'nexus' ), $tab['desc'], __( '＋ Add custom API', 'nexus' ), 'apis' );
	nexus_render_conn_cards( nexus_connectors_by_category( 'apis' ) );
}

/**
 * Page head + toolbar. The optional $add_category, when set, wires the
 * action button to the Add-custom modal via `data-nexus-add-custom`.
 * When $action_label is set without $add_category, the button is a
 * cosmetic shell (legacy demo tabs that haven't been wired up yet).
 */
function nexus_page_head( string $title, string $desc, ?string $action_label = null, ?string $add_category = null ): void {
	?>
	<div class="th-cx-page-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px">
		<div>
			<div class="th-cx-page-eyebrow"><?php esc_html_e( 'Connections', 'nexus' ); ?> · <?php echo esc_html( $title ); ?></div>
			<h2 class="th-cx-page-title"><?php echo esc_html( $title ); ?></h2>
			<p class="th-cx-page-sub"><?php echo esc_html( $desc ); ?></p>
		</div>
		<?php if ( $action_label ): ?>
		<div style="display:flex;gap:8px;flex-shrink:0">
			<label class="th-cx-search">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
				<input type="search" placeholder="<?php esc_attr_e( 'Search…', 'nexus' ); ?>">
			</label>
			<button type="button" class="th-cx-btn is-primary"<?php if ( $add_category ): ?> data-nexus-add-custom="<?php echo esc_attr( $add_category ); ?>"<?php endif; ?>>
				<?php echo esc_html( $action_label ); ?>
			</button>
		</div>
		<?php endif; ?>
	</div>
	<?php
}


// ═════════════════════════════════════════════════════════════════════════════
//  CONNECTOR CARD GRID — registry-driven (real save/delete UI)
// ═════════════════════════════════════════════════════════════════════════════

function nexus_render_conn_cards( array $connectors ): void {
	?>
	<div class="th-conn-grid">
	<?php foreach ( $connectors as $connector ):
		$id            = $connector['id'];
		$status        = nexus_connector_status( $connector );
		$saved         = nexus_get_connector( $id );
		$config        = $saved['config'] ?? [];
		$is_configured = nexus_connector_is_configured( $id );
		$is_builtin    = ! empty( $connector['built_in'] );
		$updated       = ! empty( $saved['updated'] ) ? human_time_diff( $saved['updated'] ) . ' ago' : null;
	?>
	<div class="th-conn-card" data-connector="<?php echo esc_attr( $id ); ?>">

		<div class="th-conn-card-head">
			<div class="th-conn-badge" style="background:<?php echo esc_attr( $connector['color'] ); ?>">
				<?php echo esc_html( $connector['initial'] ); ?>
			</div>
			<div class="th-conn-info">
				<div class="th-conn-name">
					<?php echo esc_html( $connector['name'] ); ?>
					<span class="th-conn-status <?php echo esc_attr( $status['class'] ); ?>" data-conn-status>
						<?php echo esc_html( $status['label'] ); ?>
					</span>
				</div>
				<div class="th-conn-desc"><?php echo esc_html( $connector['desc'] ); ?></div>
			</div>
		</div>

		<?php if ( $is_builtin ): ?>

		<div class="th-conn-built-in">
			<?php if ( $id === 'wordpress' ): ?>
				<?php esc_html_e( 'REST API:', 'nexus' ); ?> <code><?php echo esc_html( rest_url() ); ?></code><br>
				<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>" style="color:var(--ac)">
					<?php esc_html_e( 'Manage application passwords →', 'nexus' ); ?>
				</a>
			<?php elseif ( $id === 'woocommerce' && class_exists( 'WooCommerce' ) ): ?>
				<?php esc_html_e( 'Version:', 'nexus' ); ?> <code><?php echo esc_html( WC()->version ); ?></code> &nbsp;|&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' ) ); ?>" style="color:var(--ac)">
					<?php esc_html_e( 'REST API keys →', 'nexus' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php elseif ( ! empty( $connector['bridge_only'] ) ): ?>

		<?php
			$active_bridge = nexus_bridge_active_for( $connector );
			$active_name   = $active_bridge['name'] ?? '';
			$woo_keys      = $active_bridge['evidence']['keys'] ?? [];
		?>
		<div class="th-conn-foot">
			<span class="th-conn-foot-meta">
				<?php if ( $active_bridge ): ?>
					<?php
						printf(
							/* translators: %s = bridge platform name (e.g. WooCommerce) */
							esc_html__( 'Connected via %s', 'nexus' ),
							esc_html( $active_name )
						);
					?>
				<?php endif; ?>
				<?php if ( ! empty( $connector['docs'] ) ): ?>
					<a href="<?php echo esc_url( $connector['docs'] ); ?>" target="_blank" rel="noopener" class="th-conn-docs"<?php if ( $active_bridge ): ?> style="margin-left:8px"<?php endif; ?>>
						<?php esc_html_e( 'Docs ↗', 'nexus' ); ?>
					</a>
				<?php endif; ?>
			</span>
			<div class="th-conn-foot-actions">
				<button type="button" class="th-button <?php echo $active_bridge ? '' : 'th-button-primary'; ?>" data-conn-bridge-toggle>
					<?php echo $active_bridge ? esc_html__( 'Manage', 'nexus' ) : esc_html__( 'Connect', 'nexus' ); ?>
				</button>
			</div>
		</div>

		<?php // Picker — collapsed until Connect/Manage is clicked. The links go
		      // OUT to the connector's own site, where the user is presumably
		      // logged in and can authorize that platform on their side. The
		      // actual auth happens off-site; this is just a launcher. ?>
		<div class="th-conn-bridge" data-conn-bridge-picker hidden>
			<p class="th-conn-bridge-note">
				<?php
					printf(
						/* translators: %s = connector name (e.g. PODpluser) */
						esc_html__( 'Pick the platform you use %s on. Opens that integration\'s setup screen on the connector\'s site — you\'ll need to be signed in there.', 'nexus' ),
						'<strong>' . esc_html( $connector['name'] ) . '</strong>'
					);
				?>
			</p>
			<?php if ( ! empty( $connector['bridge_via'] ) ): ?>
			<div class="th-conn-bridge-links">
				<?php foreach ( $connector['bridge_via'] as $bridge ):
					$is_active = $active_name && $bridge['name'] === $active_name;
				?>
					<a href="<?php echo esc_url( $bridge['url'] ); ?>" target="_blank" rel="noopener" class="th-button<?php echo $is_active ? ' is-bridge-active' : ''; ?>">
						<?php if ( $is_active ): ?>✓ <?php endif; ?><?php echo esc_html( $bridge['name'] ); ?> ↗
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php if ( ! empty( $woo_keys ) ): ?>
				<div class="th-conn-bridge-keys">
					<?php foreach ( $woo_keys as $k ):
						$last = ! empty( $k['last_access'] ) ? human_time_diff( strtotime( $k['last_access'] ) ) . ' ago' : 'never';
					?>
						<div class="th-conn-bridge-key">
							<code><?php echo esc_html( $k['truncated_key'] ); ?></code>
							<span><?php echo esc_html( $k['description'] ); ?> · <?php echo esc_html( $k['permissions'] ); ?> · used <?php echo esc_html( $last ); ?></span>
						</div>
					<?php endforeach; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' ) ); ?>" class="th-conn-docs">
						<?php esc_html_e( 'Manage REST API keys in WooCommerce →', 'nexus' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<?php else: ?>

		<div class="th-conn-foot">
			<span class="th-conn-foot-meta">
				<?php if ( $updated ): ?><?php esc_html_e( 'Updated', 'nexus' ); ?> <?php echo esc_html( $updated ); ?><?php endif; ?>
				<?php if ( ! empty( $connector['docs'] ) ): ?>
					<a href="<?php echo esc_url( $connector['docs'] ); ?>" target="_blank" rel="noopener" class="th-conn-docs"<?php if ( $updated ): ?> style="margin-left:8px"<?php endif; ?>>
						<?php esc_html_e( 'Docs ↗', 'nexus' ); ?>
					</a>
				<?php endif; ?>
			</span>
			<div class="th-conn-foot-actions">
				<?php if ( ! empty( $connector['custom'] ) ): ?>
					<button type="button" class="th-button" data-nexus-edit-custom="<?php echo esc_attr( $id ); ?>">
						<?php esc_html_e( 'Edit definition', 'nexus' ); ?>
					</button>
					<button type="button" class="th-button" style="color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)" data-nexus-delete-custom="<?php echo esc_attr( $id ); ?>">
						<?php esc_html_e( 'Remove', 'nexus' ); ?>
					</button>
				<?php endif; ?>

				<?php // ONE credential button. Configured → Disconnect (destructive). Not configured → Connect (primary, opens form). JS swaps after save/disconnect. ?>
				<?php if ( $is_configured ): ?>
					<button type="button" class="th-button" style="color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)" data-conn-disconnect>
						<?php esc_html_e( 'Disconnect', 'nexus' ); ?>
					</button>
				<?php else: ?>
					<button type="button" class="th-button th-button-primary" data-conn-toggle data-label-open="<?php esc_attr_e( 'Connect', 'nexus' ); ?>">
						<?php esc_html_e( 'Connect', 'nexus' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="th-conn-form" data-conn-form hidden>
			<?php foreach ( $connector['fields'] as $field ):
				$val = $config[ $field['key'] ] ?? '';
			?>
			<div class="th-conn-field">
				<label>
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( ! empty( $field['required'] ) ): ?> <span style="color:var(--err)">*</span><?php endif; ?>
				</label>
				<?php if ( $field['type'] === 'select' ): ?>
					<select class="th-input" data-field="<?php echo esc_attr( $field['key'] ); ?>">
						<?php foreach ( $field['options'] as $opt_val => $opt_label ): ?>
							<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $val, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( $field['type'] === 'checkbox' ): ?>
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
						<input type="checkbox" data-field="<?php echo esc_attr( $field['key'] ); ?>" <?php checked( $val, '1' ); ?>>
						<?php echo esc_html( $field['placeholder'] ?: $field['label'] ); ?>
					</label>
				<?php else: ?>
					<input
						type="<?php echo esc_attr( $field['type'] ); ?>"
						class="th-input"
						data-field="<?php echo esc_attr( $field['key'] ); ?>"
						value="<?php echo $field['type'] === 'password' ? ( $val ? '••••••••' : '' ) : esc_attr( $val ); ?>"
						placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
						autocomplete="off"
					>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>

			<div class="th-conn-form-actions">
				<span class="th-conn-result" data-conn-result></span>
				<div style="display:flex;gap:6px">
					<button type="button" class="th-button" data-conn-toggle data-label-open="<?php echo $is_configured ? esc_attr__( 'Edit', 'nexus' ) : esc_attr__( 'Connect', 'nexus' ); ?>"><?php esc_html_e( 'Cancel', 'nexus' ); ?></button>
					<button type="button" class="th-button th-button-primary" data-conn-save><?php esc_html_e( 'Save', 'nexus' ); ?></button>
				</div>
			</div>
		</div>

		<?php endif; ?>
	</div>
	<?php endforeach; ?>
	</div>
	<?php
}
