<?php
/**
 * Nexus by Therum — checkout experience.
 *
 * Bundles the multi-method checkout design from
 * previews/checkout-experience.html as a real WooCommerce-compatible
 * checkout, driven by which payment connectors are configured in Nexus.
 *
 * Three surfaces:
 *   1. Admin tab — Nexus → Checkout → Checkout experience. Renders the
 *      preview with demo data + a connector-readiness checklist so the
 *      user can see which methods will light up in production.
 *   2. Shortcode [nexus_checkout] — drop into any page; renders the
 *      same UI populated with WC cart data + live method availability.
 *   3. (Phase 2) WC checkout page override — opt-in toggle to replace
 *      Woo's stock checkout template with this one.
 *
 * Method ↔ connector mapping is data-driven via NEXUS_CHECKOUT_METHODS
 * so adding a new payment connector + the method it backs is a one-line
 * change. Each method becomes "available" the moment at least one of
 * its backing Nexus connectors is configured.
 *
 * Actual payment SDK wiring per method is Phase 2 (Stripe Elements,
 * Plaid Link, Coinbase Commerce Charge, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  METHOD ↔ CONNECTOR MAP
// ═════════════════════════════════════════════════════════════════════════════
//
// Each method lights up when any of its `connectors` are configured.
// The `providers` array on BNPL drives the rendered options list.

const NEXUS_CHECKOUT_METHODS = [
	'card' => [
		'id'         => 'card',
		'connectors' => [ 'stripe', 'square', 'braintree', 'adyen', 'authorize-net', 'mollie' ],
	],
	'wallets' => [
		'id'         => 'wallets',
		// Stripe powers Apple Pay + Google Pay natively; PayPal stands alone;
		// Shop Pay needs the Shopify connector.
		'connectors' => [ 'stripe', 'paypal', 'shopify' ],
	],
	'bnpl' => [
		'id'         => 'bnpl',
		'connectors' => [ 'klarna', 'affirm', 'afterpay', 'sezzle', 'zip', 'paypal' ],
		'providers'  => [
			[ 'name' => 'Klarna',        'plan' => '4 payments',                       'terms' => '0% interest · every 2 weeks',          'requires' => 'klarna'   ],
			[ 'name' => 'Affirm',        'plan' => '3, 6, or 12 monthly payments',     'terms' => 'Rates from 0% to 36% APR',             'requires' => 'affirm'   ],
			[ 'name' => 'Afterpay',      'plan' => '4 payments',                       'terms' => '0% interest · every 2 weeks',          'requires' => 'afterpay' ],
			[ 'name' => 'Sezzle',        'plan' => '4 payments',                       'terms' => '0% interest · soft credit check',      'requires' => 'sezzle'   ],
			[ 'name' => 'Zip',           'plan' => '4 payments',                       'terms' => 'Bi-weekly · $1 fee per installment',   'requires' => 'zip'      ],
			[ 'name' => 'PayPal Credit', 'plan' => '6 months no interest on $99+',     'terms' => 'Backed by Synchrony',                  'requires' => 'paypal'   ],
		],
	],
	'bank' => [
		'id'         => 'bank',
		'connectors' => [ 'plaid' ],
	],
	'crypto' => [
		'id'         => 'crypto',
		// AnyPay is the preferred routing per the preview spec — direct,
		// non-custodial, 50+ coins. Coinbase Commerce stays as an
		// alternative (custodial, fiat-settled); NOWPayments is a third
		// option (custodial, broadest coin coverage); BTCPay Server is
		// the self-hosted route for max control.
		'connectors' => [ 'anypay', 'coinbase-commerce', 'nowpayments', 'btcpay-server' ],
	],
	'p2p' => [
		'id'         => 'p2p',
		// Cash App lights up when its connector is configured (which
		// piggy-backs on Square). Venmo rides PayPal. Zelle has no
		// public merchant API — no way to wire it in code; users would
		// transact off-platform.
		'connectors' => [ 'cashapp', 'paypal' ],
	],
];

/**
 * For each method, return { id, available, connected_via, providers }.
 * `available` = at least one of the method's backing connectors is
 * configured in Nexus. `connected_via` lists the human names of the
 * connectors that turned it on.
 */
function nexus_checkout_methods_status(): array {
	$out = [];
	$registry = nexus_connector_registry();
	foreach ( NEXUS_CHECKOUT_METHODS as $m ) {
		$connected_via = [];
		foreach ( $m['connectors'] as $cid ) {
			if ( nexus_connector_is_configured( $cid ) ) {
				$connected_via[] = $registry[ $cid ]['name'] ?? $cid;
			}
		}
		$row = [
			'id'             => $m['id'],
			'available'      => ! empty( $connected_via ),
			'connected_via'  => $connected_via,
			'needs'          => array_diff( $m['connectors'], array_keys( array_filter( $registry, function( $c ) { return nexus_connector_is_configured( $c['id'] ?? '' ); } ) ) ),
		];
		if ( isset( $m['providers'] ) ) $row['providers'] = $m['providers'];
		$out[] = $row;
	}
	return $out;
}

/**
 * Helper for the template — render the "needs configuration" placeholder
 * inside a method panel when the method has no backing connector.
 */
function nexus_checkout_render_disabled( string $method_id, array $meta ): void {
	$names = [
		'card'    => 'Stripe, Square, Braintree, Adyen, Authorize.Net, or Mollie',
		'wallets' => 'Stripe (for Apple/Google Pay), PayPal, or Shopify (for Shop Pay)',
		'bnpl'    => 'Klarna, Affirm, Afterpay, Sezzle, Zip, or PayPal (PayPal Credit)',
		'bank'    => 'Plaid',
		'crypto'  => 'AnyPay (recommended — direct to wallet), Coinbase Commerce, NOWPayments, or BTCPay Server',
		'p2p'     => 'Cash App (via Square) or PayPal (for Venmo). Zelle has no merchant API.',
	];
	$tab_url = admin_url( 'admin.php?page=nexus&tab=payments' );
	?>
	<div class="method-disabled">
		<?php
			printf(
				/* translators: 1 = method label, 2 = connector name(s) */
				esc_html__( 'Connect %2$s in Nexus to enable this method.', 'nexus' ),
				esc_html( $method_id ),
				esc_html( $names[ $method_id ] ?? 'a payment connector' )
			);
		?><br>
		<a href="<?php echo esc_url( $tab_url ); ?>"><?php esc_html_e( 'Go to Connections → Payment Gateways →', 'nexus' ); ?></a>
	</div>
	<?php
}


// ═════════════════════════════════════════════════════════════════════════════
//  DATA SOURCES — admin demo vs frontend WC cart
// ═════════════════════════════════════════════════════════════════════════════

function nexus_checkout_demo_data(): array {
	return [
		'line_items' => [
			[ 'name' => 'Therum Studio Tee', 'meta' => 'Red · Large',    'qty' => 2, 'price' => 58.00, 'emoji' => '🟥' ],
			[ 'name' => 'Therum Studio Tee', 'meta' => 'Blue · Medium',  'qty' => 1, 'price' => 29.00, 'emoji' => '🟦' ],
			[ 'name' => 'Patch Cap',         'meta' => 'Black · One size','qty' => 1, 'price' => 12.00, 'emoji' => '⬛' ],
		],
		'subtotal'         => 99.00,
		'shipping_options' => [
			[ 'id' => 'standard',  'name' => 'Standard',  'sub' => '5–7 business days · USPS Priority', 'cost' => 0 ],
			[ 'id' => 'express',   'name' => 'Express',   'sub' => '2–3 business days · UPS',           'cost' => 9.99 ],
			[ 'id' => 'overnight', 'name' => 'Overnight', 'sub' => 'Next business day · FedEx',         'cost' => 24.99 ],
		],
	];
}

function nexus_checkout_wc_data(): array {
	if ( ! class_exists( 'WooCommerce' ) || ! WC()->cart ) return nexus_checkout_demo_data();

	$cart  = WC()->cart;
	$items = [];
	foreach ( $cart->get_cart() as $item ) {
		$product = $item['data'] ?? null;
		if ( ! $product ) continue;
		$image_id = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		// Variant attributes → "Red · Large"
		$meta_bits = [];
		if ( ! empty( $item['variation'] ) ) {
			foreach ( $item['variation'] as $val ) {
				if ( $val ) $meta_bits[] = ucfirst( $val );
			}
		}
		$items[] = [
			'name'  => $product->get_name(),
			'meta'  => implode( ' · ', $meta_bits ),
			'qty'   => (int) $item['quantity'],
			'price' => (float) ( $item['line_total'] ?? ( $product->get_price() * $item['quantity'] ) ),
			'image' => $image_url,
		];
	}

	// Shipping options from WC's session — fallback to a simple flat list
	// if the user hasn't entered an address yet (so the preview still renders).
	$ship = [];
	if ( class_exists( 'WC_Shipping' ) ) {
		$packages = $cart->get_shipping_packages();
		WC()->shipping()->calculate_shipping( $packages );
		foreach ( WC()->shipping()->get_packages() as $package ) {
			foreach ( $package['rates'] ?? [] as $rate ) {
				$ship[] = [
					'id'   => $rate->get_id(),
					'name' => $rate->get_label(),
					'sub'  => $rate->get_method_id(),
					'cost' => (float) $rate->get_cost(),
				];
			}
		}
	}
	if ( empty( $ship ) ) {
		$ship = [
			[ 'id' => 'standard', 'name' => 'Standard', 'sub' => 'Calculated at confirmation', 'cost' => 0 ],
		];
	}

	return [
		'line_items'       => $items,
		'subtotal'         => (float) $cart->get_subtotal(),
		'shipping_options' => $ship,
	];
}


// ═════════════════════════════════════════════════════════════════════════════
//  RENDER — shared template invocation
// ═════════════════════════════════════════════════════════════════════════════

function nexus_checkout_render( string $ctx ): string {
	$data    = $ctx === 'admin' ? nexus_checkout_demo_data() : nexus_checkout_wc_data();
	$methods = nexus_checkout_methods_status();

	// Build by-id map for the template's panel rendering.
	$methods_by_id = [];
	foreach ( $methods as $m ) $methods_by_id[ $m['id'] ] = $m;

	$line_items       = $data['line_items'];
	$subtotal         = $data['subtotal'];
	$shipping_options = $data['shipping_options'];
	$action_url       = $ctx === 'admin' ? '' : ( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '' );

	ob_start();
	include NEXUS_DIR . 'templates/checkout-shell.php';
	return ob_get_clean();
}


// ═════════════════════════════════════════════════════════════════════════════
//  SHORTCODE — [nexus_checkout]
// ═════════════════════════════════════════════════════════════════════════════

add_shortcode( 'nexus_checkout', function() {
	return nexus_checkout_render( 'frontend' );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN TAB
// ═════════════════════════════════════════════════════════════════════════════

function nexus_render_checkout_tab( string $tab_id, array $tab ): void {
	nexus_page_head(
		__( 'Checkout experience', 'nexus' ),
		__( 'Bundled multi-method checkout that replaces WooPayments. Methods appear automatically when their backing connectors are configured in Nexus.', 'nexus' )
	);

	$methods = nexus_checkout_methods_status();
	$registry = nexus_connector_registry();

	$labels = [
		'card'    => 'Card',
		'wallets' => 'Wallets',
		'bnpl'    => 'Pay later (BNPL)',
		'bank'    => 'Bank transfer',
		'crypto'  => 'Crypto',
		'p2p'     => 'P2P',
	];

	?>
	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-bottom:18px">
		<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:12px"><?php esc_html_e( 'Methods status', 'nexus' ); ?></div>
		<table style="width:100%;border-collapse:collapse;font-size:13px">
			<thead>
				<tr style="text-align:left;color:var(--tx3);font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase">
					<th style="padding:4px 0">Method</th>
					<th style="padding:4px 0">Backed by</th>
					<th style="padding:4px 0">Status</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $methods as $m ):
					$method_id = $m['id'];
					$method    = NEXUS_CHECKOUT_METHODS[ $method_id ];
					$backing   = array_map( fn( $cid ) => $registry[ $cid ]['name'] ?? $cid, $method['connectors'] );
				?>
				<tr style="border-top:1px solid var(--bd)">
					<td style="padding:10px 0;font-weight:600"><?php echo esc_html( $labels[ $method_id ] ?? $method_id ); ?></td>
					<td style="padding:10px 0;color:var(--tx2)"><?php echo esc_html( implode( ', ', $backing ) ); ?></td>
					<td style="padding:10px 0">
						<?php if ( $m['available'] ): ?>
							<span style="color:var(--ok);font-weight:600">✓ Available</span>
							<span style="color:var(--tx3);font-size:11px;margin-left:6px">via <?php echo esc_html( implode( ', ', $m['connected_via'] ) ); ?></span>
						<?php else: ?>
							<span style="color:var(--tx3)"><?php esc_html_e( 'Configure a backing connector to enable', 'nexus' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-bottom:18px">
		<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:12px"><?php esc_html_e( 'Use it', 'nexus' ); ?></div>
		<p style="margin:0 0 8px;font-size:13px;color:var(--tx)"><?php
			printf(
				/* translators: %s = shortcode */
				esc_html__( 'Drop %s onto any page. Set that page as your WooCommerce checkout under WooCommerce → Settings → Advanced → Page setup.', 'nexus' ),
				'<code style="background:var(--sf2);padding:1px 6px;border-radius:4px;font-family:ui-monospace,Menlo,monospace">[nexus_checkout]</code>'
			);
		?></p>
		<p style="margin:0;font-size:12px;color:var(--tx3)"><?php esc_html_e( 'Phase 2 will add an opt-in toggle to replace WC\'s default checkout template directly — no shortcode needed. Phase 2 also wires the actual payment SDKs (Stripe Elements, Plaid Link, Coinbase Commerce Charge, …) so the methods process payments instead of redirecting to WC\'s standard flow.', 'nexus' ); ?></p>
	</div>

	<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:12px"><?php esc_html_e( 'Preview', 'nexus' ); ?></div>
	<div style="border:1px solid var(--bd);border-radius:14px;overflow:hidden">
		<?php echo nexus_checkout_render( 'admin' ); ?>
	</div>
	<?php
}
