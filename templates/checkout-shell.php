<?php
/**
 * Nexus by Therum — checkout shell template.
 *
 * Rendered by either:
 *   - the [nexus_checkout] shortcode on the frontend (with real WC data)
 *   - the Nexus admin "Checkout experience" tab (with demo data)
 *
 * Expects these locals to be set by the caller:
 *   $ctx       = 'admin' | 'frontend'
 *   $line_items = [ [ 'name', 'meta', 'qty', 'price', 'emoji'? ], … ]
 *   $subtotal   = float
 *   $shipping_options = [ [ 'id', 'name', 'sub', 'cost' ], … ]  (frontend only)
 *   $methods    = nexus_checkout_methods_status()  — see includes/checkout.php
 *   $action_url = where the form posts (frontend) or '' (admin)
 *
 * CSS + JS are inline (mirrors previews/checkout-experience.html). When the
 * design stabilizes we'll split into assets/checkout.css / .js.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$subtotal_n = isset( $subtotal ) ? (float) $subtotal : 0.0;
$money = function( float $n ): string { return '$' . number_format( $n, 2 ); };
?>
<style>
.nx-co{
	--ac:#e83b3b; --ac-h:#c92e2e; --ac-s:rgba(232,59,59,.08);
	--sf:#fff; --sf2:#f7f7f6; --sf3:#fafaf9;
	--bd:rgba(0,0,0,.08); --bd2:rgba(0,0,0,.16);
	--bg:#f4f3f0;
	--tx:#0a0a0a; --tx2:#666; --tx3:#999;
	--ok:#10b981; --wrn:#f59e0b; --err:#ef4444;
	--f:-apple-system,BlinkMacSystemFont,"Inter","Helvetica Neue",Arial,sans-serif;
	--mono:'JetBrains Mono',ui-monospace,Menlo,Consolas,monospace;
	--r:10px; --r-lg:14px;
	--e:.22s cubic-bezier(.22,.61,.36,1);
	font:14px/1.5 var(--f);
	color:var(--tx);
	background:var(--bg);
	background-image:radial-gradient(ellipse 1200px 600px at 100% 0%, rgba(232,59,59,.04), transparent 60%);
	min-height:100vh;
	padding:32px 32px 80px;
}
.nx-co *{box-sizing:border-box}
.nx-co .page{max-width:1200px;margin:0 auto}
.nx-co .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;letter-spacing:-.01em;margin-bottom:32px}
.nx-co .brand-mark{width:28px;height:28px;border-radius:7px;background:var(--ac);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px}
.nx-co .brand small{color:var(--tx3);font-weight:500;margin-left:6px}
.nx-co .layout{display:grid;grid-template-columns:1fr 360px;gap:32px;align-items:flex-start}
@media (max-width:920px){.nx-co .layout{grid-template-columns:1fr}.nx-co .summary{order:-1;position:relative!important;top:0!important}}
.nx-co .section{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r-lg);padding:24px 28px;margin-bottom:14px;transition:border-color var(--e),box-shadow var(--e)}
.nx-co .section.is-focus{border-color:rgba(232,59,59,.4);box-shadow:0 8px 28px rgba(232,59,59,.07)}
.nx-co .section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.nx-co .section-head h2{margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:10px}
.nx-co .section-num{width:22px;height:22px;border-radius:50%;background:var(--sf2);color:var(--tx3);display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;transition:background var(--e),color var(--e)}
.nx-co .section.is-focus .section-num,.nx-co .section.is-done .section-num{background:var(--ac);color:#fff}
.nx-co .section-status{font-size:11px;color:var(--tx3);font-family:var(--mono);letter-spacing:.04em;text-transform:uppercase}
.nx-co .section.is-done .section-status{color:var(--ok)}
.nx-co .row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
.nx-co .row.row-3{grid-template-columns:1fr 1fr 1fr}
.nx-co .row.row-1{grid-template-columns:1fr}
.nx-co .field{display:flex;flex-direction:column;gap:5px}
.nx-co .field label{font-size:11px;font-weight:600;color:var(--tx2);letter-spacing:.02em}
.nx-co .input{height:40px;padding:0 12px;background:var(--sf2);border:1px solid transparent;border-radius:var(--r);font:14px var(--f);color:var(--tx);transition:border-color var(--e),background var(--e),box-shadow var(--e)}
.nx-co .input:focus{outline:0;border-color:var(--ac);background:var(--sf);box-shadow:0 0 0 4px var(--ac-s)}
.nx-co .input::placeholder{color:var(--tx3)}
.nx-co .ship-list{display:flex;flex-direction:column;gap:8px}
.nx-co .ship-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;background:var(--sf2);border:1.5px solid transparent;border-radius:var(--r);cursor:pointer;transition:all var(--e)}
.nx-co .ship-row:hover{background:var(--sf3);border-color:var(--bd2)}
.nx-co .ship-row.active{border-color:var(--ac);background:var(--ac-s)}
.nx-co .ship-name{font-size:13px;font-weight:600;color:var(--tx)}
.nx-co .ship-sub{font-size:11px;color:var(--tx3)}
.nx-co .ship-price{font-size:13px;font-weight:700;color:var(--tx);font-variant-numeric:tabular-nums}
.nx-co .method-strip{display:flex;gap:6px;margin:0 -2px 18px;padding:4px;background:var(--sf2);border-radius:var(--r);overflow-x:auto;scrollbar-width:none}
.nx-co .method-strip::-webkit-scrollbar{display:none}
.nx-co .method-pill{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;background:transparent;border:0;border-radius:8px;font:600 12px var(--f);color:var(--tx2);cursor:pointer;white-space:nowrap;transition:all var(--e);position:relative}
.nx-co .method-pill:hover{background:rgba(255,255,255,.6);color:var(--tx)}
.nx-co .method-pill.active{background:var(--sf);color:var(--tx);box-shadow:0 1px 3px rgba(0,0,0,.06),0 0 0 1px var(--bd)}
.nx-co .method-pill.is-disabled{opacity:.4;cursor:not-allowed}
.nx-co .method-pill.is-disabled:hover{background:transparent;color:var(--tx2)}
.nx-co .pill-ico{width:18px;height:18px;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;letter-spacing:-.02em;flex-shrink:0}
.nx-co .method-pill[data-method="card"]    .pill-ico{background:#1a1a1a}
.nx-co .method-pill[data-method="wallets"] .pill-ico{background:#000}
.nx-co .method-pill[data-method="bnpl"]    .pill-ico{background:#ffa8b8;color:#000}
.nx-co .method-pill[data-method="bank"]    .pill-ico{background:#10b981}
.nx-co .method-pill[data-method="crypto"]  .pill-ico{background:#f7931a}
.nx-co .method-pill[data-method="p2p"]     .pill-ico{background:#00d632}
.nx-co .method-panel{display:none}
.nx-co .method-panel.active{display:block;animation:nxPanelIn .28s var(--e)}
@keyframes nxPanelIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.nx-co .method-disabled{padding:16px;background:var(--sf2);border-radius:var(--r);text-align:center;font-size:13px;color:var(--tx2)}
.nx-co .method-disabled a{color:var(--ac);font-weight:600;text-decoration:none}
.nx-co .card-mock{background:linear-gradient(135deg,#1a1a1a,#2a2a2a 60%,var(--ac));color:#fff;border-radius:var(--r-lg);padding:20px 22px;margin-bottom:14px;font-family:var(--mono);position:relative;overflow:hidden}
.nx-co .card-mock::before{content:'';position:absolute;right:-30px;top:-30px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.04)}
.nx-co .card-mock-num{font-size:18px;letter-spacing:.12em;margin:10px 0 18px}
.nx-co .card-mock-row{display:flex;justify-content:space-between;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.6)}
.nx-co .card-mock-vals{display:flex;justify-content:space-between;font-size:13px;margin-top:2px}
.nx-co .card-mock-brand{position:absolute;top:18px;right:22px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.7);font-family:var(--f);font-weight:600}
.nx-co .wallet-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.nx-co .wallet-btn{display:flex;align-items:center;justify-content:center;gap:8px;height:48px;border-radius:var(--r);font:600 14px var(--f);cursor:pointer;transition:transform var(--e),box-shadow var(--e);border:1px solid transparent}
.nx-co .wallet-btn:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.08)}
.nx-co .wallet-apple{background:#000;color:#fff}
.nx-co .wallet-google{background:#fff;color:#1a1a1a;border-color:var(--bd2)}
.nx-co .wallet-paypal{background:#fff;color:#003087;border-color:var(--bd2);font-style:italic;font-weight:800;grid-column:span 2}
.nx-co .wallet-shop{background:#5a31f4;color:#fff;grid-column:span 2}
.nx-co .bnpl-list{display:flex;flex-direction:column;gap:8px}
.nx-co .bnpl-card{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;background:var(--sf2);border:1.5px solid transparent;border-radius:var(--r);cursor:pointer;transition:all var(--e)}
.nx-co .bnpl-card:hover{background:var(--sf3);border-color:var(--bd2)}
.nx-co .bnpl-card.active{border-color:var(--ac);background:var(--ac-s)}
.nx-co .bnpl-l{display:flex;align-items:center;gap:12px}
.nx-co .bnpl-logo{width:46px;height:28px;border-radius:5px;display:flex;align-items:center;justify-content:center;font:700 11px var(--f);letter-spacing:-.02em}
.nx-co .bnpl-klarna{background:#ffa8b8;color:#000}
.nx-co .bnpl-affirm{background:#060809;color:#fff}
.nx-co .bnpl-afterpay{background:#b2fce4;color:#000}
.nx-co .bnpl-sezzle{background:#fffd6d;color:#000}
.nx-co .bnpl-zip{background:#aa8fff;color:#fff}
.nx-co .bnpl-paypalcredit{background:#003087;color:#fff;font-size:9px}
.nx-co .bnpl-name{font-size:13px;font-weight:700}
.nx-co .bnpl-sub{font-size:11px;color:var(--tx3);margin-top:1px}
.nx-co .bnpl-chev{color:var(--tx3);font-size:16px}
.nx-co .crypto-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:12px}
@media (max-width:540px){.nx-co .crypto-grid{grid-template-columns:repeat(3,1fr)}}
.nx-co .crypto-chip{display:flex;flex-direction:column;align-items:center;gap:4px;padding:12px 8px;background:var(--sf2);border:1.5px solid transparent;border-radius:var(--r);cursor:pointer;transition:all var(--e)}
.nx-co .crypto-chip:hover{background:var(--sf3);border-color:var(--bd2)}
.nx-co .crypto-chip.active{border-color:var(--ac);background:var(--ac-s)}
.nx-co .crypto-sym{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font:700 11px var(--f);color:#fff}
.nx-co .crypto-btc{background:#f7931a}.nx-co .crypto-eth{background:#627eea}.nx-co .crypto-usdc{background:#2775ca}.nx-co .crypto-usdt{background:#26a17b}.nx-co .crypto-sol{background:linear-gradient(135deg,#9945ff,#14f195)}.nx-co .crypto-xrp{background:#23292f}
.nx-co .crypto-label{font-size:11px;font-weight:600;color:var(--tx2)}
.nx-co .crypto-note{font-size:11px;color:var(--tx3);text-align:center;line-height:1.5;padding:10px;background:var(--sf2);border-radius:var(--r)}
.nx-co .bank-card{display:flex;align-items:center;gap:14px;padding:14px;background:var(--sf2);border-radius:var(--r);cursor:pointer;transition:all var(--e);border:1.5px solid transparent}
.nx-co .bank-card:hover{background:var(--sf3);border-color:var(--bd2)}
.nx-co .bank-card.active{border-color:var(--ac);background:var(--ac-s)}
.nx-co .bank-ico{width:40px;height:40px;border-radius:8px;background:#10b981;color:#fff;display:flex;align-items:center;justify-content:center;font:700 14px var(--f)}
.nx-co .bank-name{font-size:13px;font-weight:700}
.nx-co .bank-sub{font-size:11px;color:var(--tx3);margin-top:2px}
.nx-co .p2p-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
@media (max-width:540px){.nx-co .p2p-grid{grid-template-columns:1fr 1fr}}
.nx-co .p2p-card{display:flex;align-items:center;justify-content:center;gap:10px;height:56px;border-radius:var(--r);font:700 14px var(--f);cursor:pointer;transition:transform var(--e),box-shadow var(--e);border:1.5px solid transparent}
.nx-co .p2p-card:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.08)}
.nx-co .p2p-cashapp{background:#00d632;color:#000}.nx-co .p2p-venmo{background:#3d95ce;color:#fff}.nx-co .p2p-zelle{background:#6d1ed4;color:#fff}
.nx-co .summary{background:var(--sf);border:1px solid var(--bd);border-radius:var(--r-lg);padding:24px 26px;position:sticky;top:24px}
.nx-co .summary h3{margin:0 0 16px;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--tx3)}
.nx-co .line-items{display:flex;flex-direction:column;gap:12px;margin-bottom:14px}
.nx-co .line-item{display:flex;gap:12px;align-items:center}
.nx-co .li-img{width:48px;height:48px;border-radius:8px;background:var(--sf2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:var(--tx2);flex-shrink:0;position:relative;overflow:hidden}
.nx-co .li-img img{width:100%;height:100%;object-fit:cover}
.nx-co .li-qty{position:absolute;top:-5px;right:-5px;width:18px;height:18px;border-radius:50%;background:var(--tx);color:#fff;display:flex;align-items:center;justify-content:center;font:700 10px var(--f);z-index:1}
.nx-co .li-name{font-size:13px;color:var(--tx);font-weight:500;line-height:1.3}
.nx-co .li-meta{font-size:11px;color:var(--tx3);margin-top:1px}
.nx-co .li-price{font-size:13px;font-weight:700;font-variant-numeric:tabular-nums}
.nx-co .totals{border-top:1px solid var(--bd);padding-top:14px;margin-top:4px}
.nx-co .totals-row{display:flex;justify-content:space-between;font-size:13px;color:var(--tx2);margin-bottom:8px;font-variant-numeric:tabular-nums}
.nx-co .totals-row.is-total{color:var(--tx);font-weight:700;font-size:16px;margin-top:8px;padding-top:12px;border-top:1px solid var(--bd)}
.nx-co .pay-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:52px;background:var(--ac);color:#fff;border:0;border-radius:var(--r);font:700 15px var(--f);cursor:pointer;margin-top:18px;transition:background var(--e),transform var(--e),box-shadow var(--e);font-variant-numeric:tabular-nums}
.nx-co .pay-btn:hover{background:var(--ac-h);transform:translateY(-1px);box-shadow:0 8px 20px rgba(232,59,59,.22)}
.nx-co .pay-btn:disabled{opacity:.5;cursor:not-allowed;background:var(--tx3)}
.nx-co .security-note{text-align:center;font-size:11px;color:var(--tx3);margin-top:10px;display:flex;align-items:center;justify-content:center;gap:6px}
</style>

<form class="nx-co" data-nexus-checkout method="post" action="<?php echo esc_url( $action_url ?? '' ); ?>">
	<div class="page">
		<div class="brand">
			<div class="brand-mark"><?php echo esc_html( strtoupper( substr( get_bloginfo( 'name' ), 0, 1 ) ?: 'T' ) ); ?></div>
			<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			<small>· <?php echo $ctx === 'admin' ? esc_html__( 'Checkout preview', 'nexus' ) : esc_html__( 'Secure checkout', 'nexus' ); ?></small>
		</div>

		<div class="layout">
			<div>
				<!-- Section 1 — your info -->
				<section class="section is-focus">
					<div class="section-head">
						<h2><span class="section-num">1</span> <?php esc_html_e( 'Your info', 'nexus' ); ?></h2>
						<span class="section-status">Ready</span>
					</div>
					<div class="row row-1"><div class="field"><label>Email</label><input class="input" type="email" placeholder="you@example.com" name="billing_email" autofocus></div></div>
					<div class="row row-1"><div class="field"><label>Ship to</label><input class="input" placeholder="Street address" name="shipping_address_1"></div></div>
					<div class="row">
						<input class="input" placeholder="Apt, suite (optional)" name="shipping_address_2">
						<input class="input" placeholder="City" name="shipping_city">
					</div>
					<div class="row row-3">
						<input class="input" placeholder="State" name="shipping_state">
						<input class="input" placeholder="ZIP" name="shipping_postcode" maxlength="10">
						<input class="input" placeholder="Country" name="shipping_country" value="United States">
					</div>
				</section>

				<!-- Section 2 — shipping -->
				<section class="section">
					<div class="section-head">
						<h2><span class="section-num">2</span> <?php esc_html_e( 'Shipping', 'nexus' ); ?></h2>
						<span class="section-status">Auto-calculated</span>
					</div>
					<div class="ship-list">
						<?php foreach ( ( $shipping_options ?? [] ) as $i => $opt ):
							$cost  = (float) ( $opt['cost'] ?? 0 );
							$price = $cost <= 0 ? esc_html__( 'Free', 'nexus' ) : $money( $cost );
						?>
						<label class="ship-row<?php echo $i === 0 ? ' active' : ''; ?>" data-ship="<?php echo esc_attr( $opt['id'] ); ?>" data-cost="<?php echo esc_attr( $cost ); ?>">
							<div class="ship-l">
								<div class="ship-name"><?php echo esc_html( $opt['name'] ); ?></div>
								<div class="ship-sub"><?php echo esc_html( $opt['sub'] ?? '' ); ?></div>
							</div>
							<div class="ship-price"><?php echo wp_kses_post( $price ); ?></div>
						</label>
						<?php endforeach; ?>
					</div>
				</section>

				<!-- Section 3 — payment -->
				<section class="section">
					<div class="section-head">
						<h2><span class="section-num">3</span> <?php esc_html_e( 'Payment', 'nexus' ); ?></h2>
						<span class="section-status">Pick a method</span>
					</div>

					<div class="method-strip" role="tablist">
						<?php
						$methods = $methods ?? [];
						$first_enabled = null;
						foreach ( $methods as $m ) {
							if ( ! empty( $m['available'] ) ) { $first_enabled = $m['id']; break; }
						}
						$method_meta = [
							'card'    => [ 'label' => 'Card',      'ico' => 'CC' ],
							'wallets' => [ 'label' => 'Wallets',   'ico' => '⌘' ],
							'bnpl'    => [ 'label' => 'Pay later', 'ico' => '4×' ],
							'bank'    => [ 'label' => 'Bank',      'ico' => '⏧' ],
							'crypto'  => [ 'label' => 'Crypto',    'ico' => '₿' ],
							'p2p'     => [ 'label' => 'P2P',       'ico' => '$' ],
						];
						foreach ( $methods as $m ):
							$meta = $method_meta[ $m['id'] ] ?? [ 'label' => $m['id'], 'ico' => '·' ];
							$cls  = 'method-pill';
							if ( $m['id'] === $first_enabled )       $cls .= ' active';
							if ( empty( $m['available'] ) )          $cls .= ' is-disabled';
						?>
						<button class="<?php echo esc_attr( $cls ); ?>" data-method="<?php echo esc_attr( $m['id'] ); ?>" type="button"<?php if ( empty( $m['available'] ) ): ?> disabled<?php endif; ?>>
							<span class="pill-ico"><?php echo esc_html( $meta['ico'] ); ?></span>
							<?php echo esc_html( $meta['label'] ); ?>
						</button>
						<?php endforeach; ?>
					</div>

					<input type="hidden" name="payment_method" value="<?php echo esc_attr( $first_enabled ?? '' ); ?>" data-method-input>

					<!-- Card panel -->
					<div class="method-panel<?php echo $first_enabled === 'card' ? ' active' : ''; ?>" data-panel="card">
						<?php if ( ! empty( $methods_by_id['card']['available'] ) ): ?>
							<div class="card-mock">
								<div class="card-mock-brand">VISA</div>
								<div class="card-mock-num">•••• •••• •••• ••••</div>
								<div class="card-mock-row"><span>Card holder</span><span>Expires</span></div>
								<div class="card-mock-vals"><span>YOUR NAME</span><span>MM / YY</span></div>
							</div>
							<div class="row row-1"><div class="field"><label>Card number</label><input class="input" name="card_num" placeholder="1234 1234 1234 1234" inputmode="numeric" maxlength="19"></div></div>
							<div class="row">
								<div class="field"><label>Expiry</label><input class="input" name="card_exp" placeholder="MM / YY" maxlength="7"></div>
								<div class="field"><label>CVC</label><input class="input" name="card_cvc" placeholder="•••" maxlength="4" inputmode="numeric"></div>
							</div>
							<div class="row row-1"><div class="field"><label>Name on card</label><input class="input" name="card_name" placeholder="As shown on card"></div></div>
						<?php else: ?>
							<?php nexus_checkout_render_disabled( 'card', $methods_by_id['card'] ?? [] ); ?>
						<?php endif; ?>
					</div>

					<!-- Wallets -->
					<div class="method-panel<?php echo $first_enabled === 'wallets' ? ' active' : ''; ?>" data-panel="wallets">
						<?php if ( ! empty( $methods_by_id['wallets']['available'] ) ): ?>
							<div class="wallet-grid">
								<div class="wallet-btn wallet-apple"> Pay</div>
								<div class="wallet-btn wallet-google">G Pay</div>
								<div class="wallet-btn wallet-paypal">PayPal</div>
								<div class="wallet-btn wallet-shop">Shop Pay</div>
							</div>
						<?php else: ?>
							<?php nexus_checkout_render_disabled( 'wallets', $methods_by_id['wallets'] ?? [] ); ?>
						<?php endif; ?>
					</div>

					<!-- BNPL — render only providers whose connector is configured.
					     The full list is in NEXUS_CHECKOUT_METHODS['bnpl']['providers'];
					     filter here so the customer sees only what they can actually pay with. -->
					<div class="method-panel<?php echo $first_enabled === 'bnpl' ? ' active' : ''; ?>" data-panel="bnpl">
						<?php if ( ! empty( $methods_by_id['bnpl']['available'] ) ): ?>
							<div class="bnpl-list">
								<?php
								$bnpl_first = true;
								foreach ( $methods_by_id['bnpl']['providers'] ?? [] as $p ):
									$req = $p['requires'] ?? null;
									if ( $req && ! nexus_connector_is_configured( $req ) ) continue;
									$logo_cls = 'bnpl-' . preg_replace( '/[^a-z]/', '', strtolower( $p['name'] ) );
								?>
								<label class="bnpl-card<?php echo $bnpl_first ? ' active' : ''; ?>">
									<div class="bnpl-l">
										<div class="bnpl-logo <?php echo esc_attr( $logo_cls ); ?>"><?php echo esc_html( $p['name'] ); ?></div>
										<div>
											<div class="bnpl-name"><?php echo esc_html( $p['plan'] ?? '4 payments' ); ?></div>
											<div class="bnpl-sub"><?php echo esc_html( $p['terms'] ?? '0% interest · every 2 weeks' ); ?></div>
										</div>
									</div>
									<span class="bnpl-chev">→</span>
								</label>
								<?php $bnpl_first = false; endforeach; ?>
							</div>
						<?php else: ?>
							<?php nexus_checkout_render_disabled( 'bnpl', $methods_by_id['bnpl'] ?? [] ); ?>
						<?php endif; ?>
					</div>

					<!-- Bank -->
					<div class="method-panel<?php echo $first_enabled === 'bank' ? ' active' : ''; ?>" data-panel="bank">
						<?php if ( ! empty( $methods_by_id['bank']['available'] ) ): ?>
							<label class="bank-card active">
								<div class="bank-ico">⏧</div>
								<div style="flex:1">
									<div class="bank-name"><?php esc_html_e( 'Connect with Plaid', 'nexus' ); ?></div>
									<div class="bank-sub"><?php esc_html_e( 'Pay directly from your bank account. Saves ~2% in card fees.', 'nexus' ); ?></div>
								</div>
								<span class="bnpl-chev">→</span>
							</label>
						<?php else: ?>
							<?php nexus_checkout_render_disabled( 'bank', $methods_by_id['bank'] ?? [] ); ?>
						<?php endif; ?>
					</div>

					<!-- Crypto -->
					<div class="method-panel<?php echo $first_enabled === 'crypto' ? ' active' : ''; ?>" data-panel="crypto">
						<?php if ( ! empty( $methods_by_id['crypto']['available'] ) ): ?>
							<div class="crypto-grid">
								<label class="crypto-chip active"><div class="crypto-sym crypto-btc">₿</div><div class="crypto-label">BTC</div></label>
								<label class="crypto-chip"><div class="crypto-sym crypto-eth">Ξ</div><div class="crypto-label">ETH</div></label>
								<label class="crypto-chip"><div class="crypto-sym crypto-usdc">$</div><div class="crypto-label">USDC</div></label>
								<label class="crypto-chip"><div class="crypto-sym crypto-usdt">₮</div><div class="crypto-label">USDT</div></label>
								<label class="crypto-chip"><div class="crypto-sym crypto-sol">◎</div><div class="crypto-label">SOL</div></label>
								<label class="crypto-chip"><div class="crypto-sym crypto-xrp">✕</div><div class="crypto-label">XRP</div></label>
							</div>
							<div class="crypto-note">
								<?php esc_html_e( 'Generates a QR code after confirmation. Approx. 10–15 min for network settlement.', 'nexus' ); ?><br>
								<small style="opacity:.7"><?php esc_html_e( 'Routed through AnyPay · 50+ coins supported under the hood', 'nexus' ); ?></small>
							</div>
						<?php else: ?>
							<?php nexus_checkout_render_disabled( 'crypto', $methods_by_id['crypto'] ?? [] ); ?>
						<?php endif; ?>
					</div>

					<!-- P2P -->
					<div class="method-panel<?php echo $first_enabled === 'p2p' ? ' active' : ''; ?>" data-panel="p2p">
						<?php if ( ! empty( $methods_by_id['p2p']['available'] ) ): ?>
							<div class="p2p-grid">
								<div class="p2p-card p2p-cashapp">$ Cash App</div>
								<div class="p2p-card p2p-venmo">Venmo</div>
								<div class="p2p-card p2p-zelle">Zelle</div>
							</div>
						<?php else: ?>
							<?php nexus_checkout_render_disabled( 'p2p', $methods_by_id['p2p'] ?? [] ); ?>
						<?php endif; ?>
					</div>
				</section>
			</div>

			<aside class="summary">
				<h3><?php esc_html_e( 'Order summary', 'nexus' ); ?></h3>
				<div class="line-items">
					<?php foreach ( ( $line_items ?? [] ) as $item ): ?>
					<div class="line-item">
						<div class="li-img">
							<?php if ( ! empty( $item['image'] ) ): ?>
								<img src="<?php echo esc_url( $item['image'] ); ?>" alt="">
							<?php else: ?>
								<?php echo esc_html( $item['emoji'] ?? '⬛' ); ?>
							<?php endif; ?>
							<span class="li-qty"><?php echo (int) ( $item['qty'] ?? 1 ); ?></span>
						</div>
						<div style="flex:1">
							<div class="li-name"><?php echo esc_html( $item['name'] ); ?></div>
							<?php if ( ! empty( $item['meta'] ) ): ?><div class="li-meta"><?php echo esc_html( $item['meta'] ); ?></div><?php endif; ?>
						</div>
						<div class="li-price"><?php echo esc_html( $money( (float) ( $item['price'] ?? 0 ) ) ); ?></div>
					</div>
					<?php endforeach; ?>
				</div>

				<?php
					$ship0 = (float) ( $shipping_options[0]['cost'] ?? 0 );
					$tax   = (float) ( ( $subtotal_n + $ship0 ) * 0.0875 );
					$total = $subtotal_n + $ship0 + $tax;
				?>
				<div class="totals">
					<div class="totals-row"><span><?php esc_html_e( 'Subtotal', 'nexus' ); ?></span><span data-co-subtotal><?php echo esc_html( $money( $subtotal_n ) ); ?></span></div>
					<div class="totals-row"><span><?php esc_html_e( 'Shipping', 'nexus' ); ?></span><span data-co-shipping><?php echo $ship0 > 0 ? esc_html( $money( $ship0 ) ) : esc_html__( 'Free', 'nexus' ); ?></span></div>
					<div class="totals-row"><span><?php esc_html_e( 'Tax (est.)', 'nexus' ); ?></span><span data-co-tax><?php echo esc_html( $money( $tax ) ); ?></span></div>
					<div class="totals-row is-total"><span><?php esc_html_e( 'Total', 'nexus' ); ?></span><span data-co-total><?php echo esc_html( $money( $total ) ); ?></span></div>
				</div>

				<button class="pay-btn" type="submit" data-co-pay>
					<?php
						printf(
							/* translators: %s = total like $99.00 */
							esc_html__( 'Pay %s', 'nexus' ),
							esc_html( $money( $total ) )
						);
					?>
				</button>
				<div class="security-note">
					🔒 <?php esc_html_e( 'Encrypted end-to-end · No card data stored', 'nexus' ); ?>
				</div>
				<?php if ( $ctx === 'admin' ): ?>
					<div class="security-note" style="margin-top:6px">
						<?php esc_html_e( 'Preview only — not connected to live cart data.', 'nexus' ); ?>
					</div>
				<?php endif; ?>
			</aside>
		</div>
	</div>
</form>

<script>
(function(){
	'use strict';
	var roots = document.querySelectorAll('[data-nexus-checkout]');
	roots.forEach(function(root){
		var SUBTOTAL = <?php echo wp_json_encode( $subtotal_n ); ?>;
		var TAX_RATE = 0.0875;
		var state = { ship: <?php echo wp_json_encode( (float) ( $shipping_options[0]['cost'] ?? 0 ) ); ?>, method: <?php echo wp_json_encode( $first_enabled ?? '' ); ?> };

		function money(n){ return '$' + n.toFixed(2); }

		function recalc(){
			var ship  = state.ship;
			var base  = SUBTOTAL + ship;
			var tax   = +(base * TAX_RATE).toFixed(2);
			var total = +(base + tax).toFixed(2);
			var $sub = root.querySelector('[data-co-subtotal]');
			var $shp = root.querySelector('[data-co-shipping]');
			var $tax = root.querySelector('[data-co-tax]');
			var $tot = root.querySelector('[data-co-total]');
			if ($sub) $sub.textContent = money(SUBTOTAL);
			if ($shp) $shp.textContent = ship === 0 ? 'Free' : money(ship);
			if ($tax) $tax.textContent = money(tax);
			if ($tot) $tot.textContent = money(total);

			var labels = { card:'Pay '+money(total), wallets:'Continue with wallet', bnpl:'Continue to Klarna', bank:'Connect bank account', crypto:'Generate QR · '+money(total), p2p:'Open Cash App' };
			var btn = root.querySelector('[data-co-pay]');
			if (btn) btn.textContent = labels[state.method] || ('Pay ' + money(total));
		}

		root.querySelectorAll('.ship-row').forEach(function(row){
			row.addEventListener('click', function(){
				root.querySelectorAll('.ship-row').forEach(function(r){ r.classList.remove('active'); });
				row.classList.add('active');
				state.ship = parseFloat(row.getAttribute('data-cost')) || 0;
				recalc();
			});
		});

		root.querySelectorAll('.method-pill').forEach(function(p){
			p.addEventListener('click', function(){
				if (p.classList.contains('is-disabled')) return;
				root.querySelectorAll('.method-pill').forEach(function(x){ x.classList.remove('active'); });
				root.querySelectorAll('.method-panel').forEach(function(x){ x.classList.remove('active'); });
				p.classList.add('active');
				state.method = p.getAttribute('data-method');
				var panel = root.querySelector('[data-panel="' + state.method + '"]');
				if (panel) panel.classList.add('active');
				var mi = root.querySelector('[data-method-input]');
				if (mi) mi.value = state.method;
				recalc();
			});
		});

		['.bnpl-card','.crypto-chip','.bank-card','.p2p-card'].forEach(function(sel){
			root.querySelectorAll(sel).forEach(function(c){
				c.addEventListener('click', function(){
					root.querySelectorAll(sel).forEach(function(x){ x.classList.remove('active'); });
					c.classList.add('active');
				});
			});
		});

		recalc();
	});
})();
</script>
