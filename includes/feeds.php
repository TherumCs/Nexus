<?php
/**
 * Nexus by Therum — product-feed channels (Google Shopping, Meta,
 * Pinterest, TikTok, Bing).
 *
 * Bundles the core of CTX Feed / Product Feed PRO so the user doesn't
 * need a separate plugin. The mapping difference: every channel has
 * sensible fallbacks built in (brand → site name, condition → "new",
 * availability → derived from WC stock, image_link → featured image,
 * gtin/mpn → custom field if present and silently dropped otherwise),
 * so a product with bare-minimum WC data still generates a valid feed
 * entry instead of being rejected by the channel.
 *
 * Architecture:
 *   - nexus_feed_channels()        — channel registry (id, format, fields, …)
 *   - nexus_feed_config( $id )     — saved per-channel config
 *   - nexus_feed_save( $id, … )    — store config + (re)generate access token
 *   - nexus_feed_url( $id )        — public feed URL submitted to the channel
 *   - REST: GET /wp-json/nexus/v1/feed/<channel>?token=<secret>
 *           Serves the feed in the channel's required format (XML / CSV).
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ═════════════════════════════════════════════════════════════════════════════
//  CHANNEL REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

function nexus_feed_channels(): array {
	$base_currency_fallback = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

	$shared_fields = [
		[ 'key' => 'currency',             'label' => 'Currency',              'type' => 'text',     'placeholder' => $base_currency_fallback,  'required' => true ],
		[ 'key' => 'brand_fallback',       'label' => 'Default Brand',         'type' => 'text',     'placeholder' => 'Falls back to site name if blank', 'required' => false ],
		[ 'key' => 'condition',            'label' => 'Default Condition',     'type' => 'select',
			'options' => [ 'new' => 'New', 'used' => 'Used', 'refurbished' => 'Refurbished' ],
			'required' => false ],
		[ 'key' => 'include_out_of_stock', 'label' => 'Include out-of-stock products', 'type' => 'checkbox', 'placeholder' => '',                  'required' => false ],
		[ 'key' => 'brand_field',          'label' => 'Per-product brand custom field key', 'type' => 'text', 'placeholder' => 'e.g. _brand — leave blank for the fallback', 'required' => false ],
		[ 'key' => 'gtin_field',           'label' => 'Per-product GTIN custom field key',  'type' => 'text', 'placeholder' => 'e.g. _gtin',         'required' => false ],
		[ 'key' => 'mpn_field',            'label' => 'Per-product MPN custom field key',   'type' => 'text', 'placeholder' => 'e.g. _mpn',          'required' => false ],
	];

	$channels = [
		'google-shopping' => [
			'id'     => 'google-shopping',
			'name'   => 'Google Shopping',
			'color'  => '#4285f4',
			'initial'=> 'G',
			'desc'   => 'Submit to Google Merchant Center for Shopping ads + free product listings.',
			'format' => 'xml',
			'docs'   => 'https://support.google.com/merchants/answer/7052112',
			'fields' => array_merge( $shared_fields, [
				[ 'key' => 'google_product_category', 'label' => 'Default Google Product Category', 'type' => 'text', 'placeholder' => 'e.g. Apparel & Accessories > Clothing', 'required' => false ],
			] ),
		],
		'meta-catalog' => [
			'id'     => 'meta-catalog',
			'name'   => 'Meta Catalog (Facebook + Instagram)',
			'color'  => '#1877f2',
			'initial'=> 'M',
			'desc'   => 'Facebook Shops, Instagram Shopping, dynamic product ads. Emits Meta-canonical field names — quantity_to_sell_on_facebook, "in stock" with the space, fb_product_category.',
			'format' => 'csv',
			'docs'   => 'https://www.facebook.com/business/help/120325381656392',
			'fields' => array_merge( $shared_fields, [
				[ 'key' => 'fb_product_category',     'label' => 'Default Meta Product Category', 'type' => 'text', 'placeholder' => 'Meta\'s taxonomy ID or path (optional — google_product_category is enough for most)', 'required' => false ],
				[ 'key' => 'google_product_category', 'label' => 'Default Google Product Category', 'type' => 'text', 'placeholder' => 'Meta accepts this too — e.g. Apparel & Accessories > Clothing', 'required' => false ],
			] ),
		],
		'pinterest-catalog' => [
			'id'     => 'pinterest-catalog',
			'name'   => 'Pinterest Catalog',
			'color'  => '#e60023',
			'initial'=> 'P',
			'desc'   => 'Pinterest Shopping — Product Pins, dynamic retargeting.',
			'format' => 'csv',
			'docs'   => 'https://help.pinterest.com/en/business/article/data-source-ingestion',
			'fields' => $shared_fields,
		],
		'tiktok-shop' => [
			'id'     => 'tiktok-shop',
			'name'   => 'TikTok Shop',
			'color'  => '#000000',
			'initial'=> 'T',
			'desc'   => 'TikTok Shop catalog · Spark Ads · Shopping Center.',
			'format' => 'csv',
			'docs'   => 'https://ads.tiktok.com/help/article/uploading-a-catalog-feed',
			'fields' => $shared_fields,
		],
		'bing-merchant' => [
			'id'     => 'bing-merchant',
			'name'   => 'Microsoft (Bing) Merchant',
			'color'  => '#0078d4',
			'initial'=> 'B',
			'desc'   => 'Microsoft Shopping Campaigns. Uses the Google Shopping spec almost verbatim.',
			'format' => 'xml',
			'docs'   => 'https://help.ads.microsoft.com/apex/index/3/en/56825',
			'fields' => array_merge( $shared_fields, [
				[ 'key' => 'google_product_category', 'label' => 'Default Google Product Category', 'type' => 'text', 'placeholder' => 'Bing accepts Google taxonomy', 'required' => false ],
			] ),
		],
	];

	return apply_filters( 'nexus_feed_channels', $channels );
}


// ═════════════════════════════════════════════════════════════════════════════
//  PERSISTENCE
// ═════════════════════════════════════════════════════════════════════════════

function nexus_feed_config( string $id ): array {
	$raw  = get_option( 'nexus_feed_' . sanitize_key( $id ), '' );
	$data = $raw ? json_decode( $raw, true ) : [];
	return is_array( $data ) ? $data : [];
}

function nexus_feed_save( string $id, array $config ): array {
	$id   = sanitize_key( $id );
	$prev = nexus_feed_config( $id );
	// Persist the access token across saves; mint one the first time.
	$token = $prev['token'] ?? wp_generate_password( 32, false, false );
	$row = [
		'enabled' => true,
		'config'  => $config,
		'token'   => $token,
		'updated' => time(),
	];
	update_option( 'nexus_feed_' . $id, wp_json_encode( $row ), false );
	return $row;
}

function nexus_feed_delete( string $id ): void {
	delete_option( 'nexus_feed_' . sanitize_key( $id ) );
}

function nexus_feed_is_configured( string $id ): bool {
	$row = nexus_feed_config( $id );
	return ! empty( $row['enabled'] ) && ! empty( $row['token'] );
}

function nexus_feed_url( string $id ): string {
	$row = nexus_feed_config( $id );
	if ( empty( $row['token'] ) ) return '';
	return rest_url( 'nexus/v1/feed/' . $id ) . '?token=' . rawurlencode( $row['token'] );
}


// ═════════════════════════════════════════════════════════════════════════════
//  REST ENDPOINT — public, token-gated
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {
	register_rest_route( 'nexus/v1', '/feed/(?P<channel>[a-z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'nexus_feed_serve',
		'permission_callback' => '__return_true',
		'args'                => [
			'channel' => [ 'validate_callback' => fn( $v ) => is_string( $v ) && $v !== '' ],
			'token'   => [ 'required' => true ],
		],
	] );
} );

function nexus_feed_serve( WP_REST_Request $req ) {
	$channel_id = sanitize_key( $req['channel'] );
	$token_in   = (string) $req->get_param( 'token' );

	$channels = nexus_feed_channels();
	if ( ! isset( $channels[ $channel_id ] ) ) {
		return new WP_Error( 'nexus_feed_unknown', 'Unknown channel', [ 'status' => 404 ] );
	}
	$row = nexus_feed_config( $channel_id );
	if ( empty( $row['token'] ) || ! hash_equals( (string) $row['token'], $token_in ) ) {
		return new WP_Error( 'nexus_feed_forbidden', 'Invalid token', [ 'status' => 403 ] );
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		return new WP_Error( 'nexus_feed_no_wc', 'WooCommerce is required to generate this feed.', [ 'status' => 500 ] );
	}

	$channel = $channels[ $channel_id ];
	$config  = $row['config'] ?? [];
	$items   = nexus_feed_collect_products( $config );

	// Render in the channel's native format. Bypass REST serialization —
	// we want raw XML/CSV with the right Content-Type, not a JSON envelope.
	if ( $channel['format'] === 'xml' ) {
		header( 'Content-Type: application/xml; charset=utf-8' );
		echo nexus_feed_render_google_xml( $items, $config );
	} else {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="nexus-' . $channel_id . '.csv"' );
		echo nexus_feed_render_csv( $items, $channel_id, $config );
	}
	exit;
}


// ═════════════════════════════════════════════════════════════════════════════
//  PRODUCT COLLECTION + FIELD MAPPING
//
//  This is where Nexus does better than CTX: every channel-required
//  field has a sensible fallback chain. A product with bare-bones
//  WC data still produces a valid feed entry.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Collect WC products into an array of normalized feed items. One row
 * per simple product; variable products expand into one row per variation
 * (each variation is what marketplaces actually need).
 */
function nexus_feed_collect_products( array $config ): array {
	$include_oos = ! empty( $config['include_out_of_stock'] );
	$args = [
		'status' => 'publish',
		'limit'  => -1,
		'type'   => [ 'simple', 'variable' ],
		'return' => 'objects',
	];
	if ( ! $include_oos ) $args['stock_status'] = 'instock';

	$products = wc_get_products( $args );
	$items    = [];

	foreach ( $products as $product ) {
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_available_variations( 'objects' ) as $var ) {
				if ( ! $include_oos && ! $var->is_in_stock() ) continue;
				$items[] = nexus_feed_normalize( $var, $product, $config );
			}
		} else {
			$items[] = nexus_feed_normalize( $product, null, $config );
		}
	}
	return array_values( array_filter( $items ) );
}

/**
 * Normalize one WC product (or variation) into a generic item shape.
 * Per-channel renderers then map this shape into channel-specific field
 * names (title vs. name, image_link vs. image_url, etc.).
 *
 * The set of fields surfaced here intentionally covers what Google
 * Merchant Center, Meta Catalog (Facebook + Instagram Shopping),
 * Pinterest, TikTok, and Bing all accept — including the IG-critical
 * `quantity` / `inventory` field that's the usual culprit when "stock
 * is missing" on Instagram product tags. CTX Feed often emits these
 * empty; here they're derived from WC's real stock quantity.
 */
function nexus_feed_normalize( $product, $parent, array $config ): ?array {
	if ( ! $product ) return null;

	$id    = $product->get_id();
	$title = $product->get_title();
	if ( $product->is_type( 'variation' ) && $parent ) {
		// Variation titles come back as "ParentName - attr: val" — that's
		// the right shape for marketplaces ("T-Shirt - Color: Red, Size: M").
		// If the variation title is blank (rare), fall back to parent name.
		if ( ! $title ) $title = $parent->get_title();
	}

	$desc = wp_strip_all_tags( $product->get_description() );
	if ( ! $desc && $parent ) $desc = wp_strip_all_tags( $parent->get_description() );
	if ( ! $desc ) $desc = wp_strip_all_tags( $product->get_short_description() );
	if ( ! $desc && $parent ) $desc = wp_strip_all_tags( $parent->get_short_description() );
	$desc = trim( $desc );
	if ( strlen( $desc ) > 4500 ) $desc = substr( $desc, 0, 4497 ) . '…';

	$price = (float) $product->get_price();
	if ( ! $price && $parent ) $price = (float) $parent->get_price();
	if ( $price <= 0 ) return null; // marketplaces reject zero-price items

	// Sale price + window. WC stores prices as strings; Meta wants the
	// effective_date range as ISO-8601 with a `/` separator.
	$sale_price  = '';
	$sale_window = '';
	$sale_raw    = $product->get_sale_price();
	if ( $sale_raw !== '' && (float) $sale_raw > 0 && (float) $sale_raw < $price ) {
		$sale_price = number_format( (float) $sale_raw, 2, '.', '' );
		$from = $product->get_date_on_sale_from();
		$to   = $product->get_date_on_sale_to();
		if ( $from && $to ) {
			$sale_window = $from->date( 'c' ) . '/' . $to->date( 'c' );
		}
	}

	// Image — main + up to 10 additional (Meta caps at 10, Google at 10).
	$image_id  = $product->get_image_id() ?: ( $parent ? $parent->get_image_id() : 0 );
	$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	if ( ! $image_url ) return null; // image_link is universally required

	$gallery_src = $product;
	$gallery_ids = $product->get_gallery_image_ids();
	if ( empty( $gallery_ids ) && $parent ) {
		$gallery_ids = $parent->get_gallery_image_ids();
		$gallery_src = $parent;
	}
	$additional_images = [];
	foreach ( array_slice( (array) $gallery_ids, 0, 10 ) as $gid ) {
		$u = wp_get_attachment_image_url( $gid, 'full' );
		if ( $u && $u !== $image_url ) $additional_images[] = $u;
	}

	// Brand — auto-detect chain. Tries every common storage location before
	// the user-configured fallback, so a typical WC store doesn't need to
	// touch brand at all (the bit that drove people crazy with CTX Feed).
	//
	// Sources, in order:
	//   1. Configured custom field key
	//   2. _brand meta (most plugins use this)
	//   3. product_brand taxonomy (Yoast WC SEO, Perfect Brands for WC)
	//   4. pa_brand product attribute
	//   5. _yoast_wpseo_brand
	//   6. Configured brand_fallback
	//   7. Site name (always non-empty)
	$brand_field = (string) ( $config['brand_field'] ?? '' );
	$parent_id   = $parent ? $parent->get_id() : $id;
	$brand = '';
	if ( $brand_field ) {
		$brand = (string) $product->get_meta( $brand_field );
		if ( ! $brand && $parent ) $brand = (string) $parent->get_meta( $brand_field );
	}
	if ( ! $brand ) $brand = (string) $product->get_meta( '_brand' );
	if ( ! $brand && $parent ) $brand = (string) $parent->get_meta( '_brand' );
	foreach ( [ 'product_brand', 'pa_brand', 'pwb-brand' ] as $tax ) {
		if ( $brand ) break;
		$terms = get_the_terms( $parent_id, $tax );
		if ( $terms && ! is_wp_error( $terms ) ) $brand = $terms[0]->name;
	}
	if ( ! $brand ) $brand = (string) $product->get_meta( '_yoast_wpseo_brand' );
	if ( ! $brand ) $brand = (string) ( $config['brand_fallback'] ?? '' );
	if ( ! $brand ) $brand = get_bloginfo( 'name' );

	// GTIN auto-detect. WC 8.3+ ships a first-class GTIN field under the
	// '_global_unique_id' meta key — that's the canonical one. Older
	// installs use `_gtin`, `_ean`, `_upc`, or `_isbn`.
	$gtin_field = (string) ( $config['gtin_field'] ?? '' );
	$gtin = '';
	if ( $gtin_field ) {
		$gtin = (string) $product->get_meta( $gtin_field );
		if ( ! $gtin && $parent ) $gtin = (string) $parent->get_meta( $gtin_field );
	}
	foreach ( [ '_global_unique_id', '_gtin', '_ean', '_upc', '_isbn' ] as $k ) {
		if ( $gtin ) break;
		$gtin = (string) $product->get_meta( $k );
		if ( ! $gtin && $parent ) $gtin = (string) $parent->get_meta( $k );
	}

	// MPN auto-detect. Less standardized — try the common keys.
	$mpn_field  = (string) ( $config['mpn_field']  ?? '' );
	$mpn = '';
	if ( $mpn_field ) {
		$mpn = (string) $product->get_meta( $mpn_field );
		if ( ! $mpn && $parent ) $mpn = (string) $parent->get_meta( $mpn_field );
	}
	foreach ( [ '_mpn', '_manufacturer_part_number', '_part_number' ] as $k ) {
		if ( $mpn ) break;
		$mpn = (string) $product->get_meta( $k );
		if ( ! $mpn && $parent ) $mpn = (string) $parent->get_meta( $k );
	}

	// Identifier fallback: SKU > variation id > product id. Stable across regenerations.
	$sku = $product->get_sku();
	if ( ! $sku ) $sku = (string) $id;

	$link = $product->get_permalink();
	if ( ! $link && $parent ) $link = $parent->get_permalink();

	// Variant attribute extraction. For variations, get_variation_attributes()
	// returns the chosen attribute → value map. We pluck the well-known
	// apparel attributes Meta and Google look for as separate columns so
	// IG/FB show variant pickers correctly instead of just one card.
	$attrs = [];
	if ( $product->is_type( 'variation' ) && method_exists( $product, 'get_variation_attributes' ) ) {
		foreach ( $product->get_variation_attributes() as $raw_name => $raw_val ) {
			$name = strtolower( str_replace( [ 'attribute_pa_', 'attribute_' ], '', $raw_name ) );
			$attrs[ $name ] = $raw_val;
		}
	}
	$pluck = function( array $candidates ) use ( $attrs ): string {
		foreach ( $attrs as $name => $val ) {
			foreach ( $candidates as $needle ) {
				if ( strpos( $name, $needle ) !== false ) return (string) $val;
			}
		}
		return '';
	};
	$color      = $pluck( [ 'color', 'colour' ] );
	$size       = $pluck( [ 'size' ] );
	$material   = $pluck( [ 'material', 'fabric' ] );
	$pattern    = $pluck( [ 'pattern', 'print' ] );
	$gender     = $pluck( [ 'gender' ] );
	$age_group  = $pluck( [ 'age', 'age_group' ] );

	// Inventory — the field IG drops if you don't supply it. WC's
	// "stock quantity" is only set when stock-management is on at the
	// product (or variation) level; falls back to parent if needed.
	$qty = null;
	if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
		$q = $product->get_stock_quantity();
		if ( $q !== null && $q !== '' ) $qty = (int) $q;
	}
	if ( $qty === null && $parent && $parent->managing_stock() ) {
		$q = $parent->get_stock_quantity();
		if ( $q !== null && $q !== '' ) $qty = (int) $q;
	}
	// If stock-managed but in-stock with no quantity (uncommon edge case),
	// default to a positive number so IG shows it as available rather than
	// silently hiding it.
	$availability = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';
	if ( $qty === null ) {
		$qty = $availability === 'in_stock' ? 99 : 0;
	}

	// Shipping weight (Meta + Google use this for shipping cost calc).
	$weight = '';
	if ( $product->has_weight() ) {
		$weight = $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
	} elseif ( $parent && $parent->has_weight() ) {
		$weight = $parent->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
	}

	return [
		'id'           => $sku,
		'title'        => $title,
		'description'  => $desc ?: $title,
		'link'         => $link,
		'image_link'   => $image_url,
		'additional_image_link' => implode( ',', $additional_images ),
		'price'        => number_format( $price, 2, '.', '' ),
		'sale_price'   => $sale_price,
		'sale_price_effective_date' => $sale_window,
		'currency'     => (string) ( $config['currency'] ?? get_woocommerce_currency() ),
		// Raw machine-friendly value. Renderers convert this per-channel
		// to the format each platform actually requires.
		'availability' => $availability,    // 'in_stock' | 'out_of_stock'
		'inventory'    => (int) $qty,       // Meta / IG — quantity_to_sell_on_facebook
		'condition'    => (string) ( $config['condition'] ?? 'new' ),
		'brand'        => $brand,
		'gtin'         => $gtin,
		'mpn'          => $mpn,
		'google_product_category' => (string) ( $config['google_product_category'] ?? '' ),
		'fb_product_category'     => (string) ( $config['fb_product_category']     ?? '' ),
		'item_group_id'           => $parent ? (string) $parent->get_id() : '',
		'color'        => $color,
		'size'         => $size,
		'material'     => $material,
		'pattern'      => $pattern,
		'gender'       => $gender,
		'age_group'    => $age_group,
		'weight'       => $weight,
	];
}

/**
 * Convert the normalized in_stock/out_of_stock value to whatever a
 * specific channel actually expects on the wire. Meta + Pinterest +
 * TikTok use spaces ("in stock"); Google + Bing use underscores
 * ("in_stock"). Submitting the wrong one isn't strictly fatal but
 * it does suppress IG Shopping listings silently.
 */
function nexus_feed_availability_for( string $channel_id, string $value ): string {
	$map = [
		'in_stock'     => [
			'meta-catalog'      => 'in stock',
			'pinterest-catalog' => 'in stock',
			'tiktok-shop'       => 'in stock',
			'google-shopping'   => 'in_stock',
			'bing-merchant'     => 'in_stock',
		],
		'out_of_stock' => [
			'meta-catalog'      => 'out of stock',
			'pinterest-catalog' => 'out of stock',
			'tiktok-shop'       => 'out of stock',
			'google-shopping'   => 'out_of_stock',
			'bing-merchant'     => 'out_of_stock',
		],
	];
	return $map[ $value ][ $channel_id ] ?? $value;
}

/**
 * Truncate a title to the channel's max length without cutting in the
 * middle of a word. Meta recommends ≤150; Google enforces ≤150; the
 * rest are similar. We use 150 across the board — safe everywhere.
 */
function nexus_feed_title_for( string $channel_id, string $title ): string {
	$max = 150;
	if ( strlen( $title ) <= $max ) return $title;
	$cut = substr( $title, 0, $max );
	$lastSpace = strrpos( $cut, ' ' );
	if ( $lastSpace !== false && $lastSpace > 100 ) $cut = substr( $cut, 0, $lastSpace );
	return $cut . '…';
}


// ═════════════════════════════════════════════════════════════════════════════
//  RENDERERS
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Google Shopping XML — the de-facto standard. Bing uses the same spec.
 */
function nexus_feed_render_google_xml( array $items, array $config ): string {
	$site_name = htmlspecialchars( get_bloginfo( 'name' ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	$site_url  = htmlspecialchars( home_url(), ENT_XML1 | ENT_QUOTES, 'UTF-8' );

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$xml .= '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n";
	$xml .= "<channel>\n";
	$xml .= "<title>{$site_name}</title>\n";
	$xml .= "<link>{$site_url}</link>\n";
	$xml .= "<description>Product feed from {$site_name}</description>\n";

	foreach ( $items as $i ) {
		$xml .= "<item>\n";
		$xml .= '  <g:id>'           . nexus_feed_xml( $i['id'] ) . "</g:id>\n";
		$xml .= '  <g:title>'        . nexus_feed_xml( $i['title'] ) . "</g:title>\n";
		$xml .= '  <g:description>'  . nexus_feed_xml( $i['description'] ) . "</g:description>\n";
		$xml .= '  <g:link>'         . nexus_feed_xml( $i['link'] ) . "</g:link>\n";
		$xml .= '  <g:image_link>'   . nexus_feed_xml( $i['image_link'] ) . "</g:image_link>\n";
		// Each additional image is its own <g:additional_image_link> element
		// (Google's quirk — Meta/Pinterest accept comma-joined in CSV).
		if ( $i['additional_image_link'] ) {
			foreach ( explode( ',', $i['additional_image_link'] ) as $extra ) {
				$extra = trim( $extra );
				if ( $extra ) $xml .= '  <g:additional_image_link>' . nexus_feed_xml( $extra ) . "</g:additional_image_link>\n";
			}
		}
		$xml .= '  <g:price>' . nexus_feed_xml( $i['price'] . ' ' . $i['currency'] ) . "</g:price>\n";
		if ( $i['sale_price'] ) {
			$xml .= '  <g:sale_price>' . nexus_feed_xml( $i['sale_price'] . ' ' . $i['currency'] ) . "</g:sale_price>\n";
			if ( $i['sale_price_effective_date'] ) {
				$xml .= '  <g:sale_price_effective_date>' . nexus_feed_xml( $i['sale_price_effective_date'] ) . "</g:sale_price_effective_date>\n";
			}
		}
		$xml .= '  <g:availability>' . nexus_feed_xml( $i['availability'] ) . "</g:availability>\n";
		// Quantity. Google's field is `quantity_to_sell_on_facebook` (yes,
		// even in Google's spec — Meta historically piggy-backed). Also
		// IG/FB pull this when Google is the sync source.
		if ( isset( $i['inventory'] ) ) {
			$xml .= '  <g:quantity_to_sell_on_facebook>' . (int) $i['inventory'] . "</g:quantity_to_sell_on_facebook>\n";
		}
		$xml .= '  <g:condition>' . nexus_feed_xml( $i['condition'] ) . "</g:condition>\n";
		$xml .= '  <g:brand>'     . nexus_feed_xml( $i['brand'] )     . "</g:brand>\n";
		if ( $i['gtin'] )                    $xml .= '  <g:gtin>' . nexus_feed_xml( $i['gtin'] ) . "</g:gtin>\n";
		if ( $i['mpn'] )                     $xml .= '  <g:mpn>'  . nexus_feed_xml( $i['mpn'] )  . "</g:mpn>\n";
		if ( ! $i['gtin'] && ! $i['mpn'] )   $xml .= "  <g:identifier_exists>no</g:identifier_exists>\n";
		if ( $i['google_product_category'] ) $xml .= '  <g:google_product_category>' . nexus_feed_xml( $i['google_product_category'] ) . "</g:google_product_category>\n";
		if ( $i['item_group_id'] )           $xml .= '  <g:item_group_id>' . nexus_feed_xml( $i['item_group_id'] ) . "</g:item_group_id>\n";
		// Variant attributes — Google's apparel feed requires color/size/etc.
		foreach ( [ 'color', 'size', 'material', 'pattern', 'gender', 'age_group' ] as $attr ) {
			if ( ! empty( $i[ $attr ] ) ) {
				$xml .= '  <g:' . $attr . '>' . nexus_feed_xml( $i[ $attr ] ) . '</g:' . $attr . ">\n";
			}
		}
		if ( $i['weight'] ) $xml .= '  <g:shipping_weight>' . nexus_feed_xml( $i['weight'] ) . "</g:shipping_weight>\n";
		$xml .= "</item>\n";
	}

	$xml .= "</channel>\n</rss>\n";
	return $xml;
}

function nexus_feed_xml( $s ): string {
	return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * CSV — used by Meta Catalog, Pinterest, TikTok, Bing.
 *
 * Column set is the union of what these channels accept. Each channel
 * ignores unknown columns, but the headers matter — Meta in particular
 * looks for `quantity_to_sell_on_facebook` and `additional_image_link`
 * by name. Per-channel header overrides handled below for the small
 * number of cases where names actually diverge.
 */
function nexus_feed_render_csv( array $items, string $channel_id, array $config ): string {
	// Base column set. Header → item key. A few sentinel keys
	// (`_price_with_currency`, etc.) trigger inline transforms below.
	$columns = [
		'id'                          => 'id',
		'title'                       => '_title_truncated',
		'description'                 => 'description',
		'link'                        => 'link',
		'image_link'                  => 'image_link',
		'additional_image_link'       => 'additional_image_link',
		'availability'                => '_availability_channel',
		'condition'                   => 'condition',
		'price'                       => '_price_with_currency',
		'sale_price'                  => '_sale_price_with_currency',
		'sale_price_effective_date'   => 'sale_price_effective_date',
		'brand'                       => 'brand',
		'gtin'                        => 'gtin',
		'mpn'                         => 'mpn',
		'google_product_category'     => 'google_product_category',
		'item_group_id'               => 'item_group_id',
		'color'                       => 'color',
		'size'                        => 'size',
		'material'                    => 'material',
		'pattern'                     => 'pattern',
		'gender'                      => 'gender',
		'age_group'                   => 'age_group',
		'shipping_weight'             => 'weight',
	];

	// Channel-specific tweaks — header NAMES differ across channels for
	// what's logically the same data. Meta in particular has its own
	// names that IG/FB look for; if you don't use those exact strings,
	// Instagram Shopping silently drops the inventory count.
	switch ( $channel_id ) {
		case 'meta-catalog':
			$columns['quantity_to_sell_on_facebook'] = 'inventory';
			$columns['fb_product_category']          = 'fb_product_category';
			break;
		case 'pinterest-catalog':
			$columns['inventory'] = 'inventory';
			break;
		case 'tiktok-shop':
			$columns['quantity']  = 'inventory';
			break;
		case 'bing-merchant':
			$columns['inventory'] = 'inventory';
			break;
	}

	$fh = fopen( 'php://temp', 'w+' );
	fputcsv( $fh, array_keys( $columns ) );

	foreach ( $items as $i ) {
		$row = [];
		foreach ( $columns as $header => $item_key ) {
			switch ( $item_key ) {
				case '_price_with_currency':
					$row[] = $i['price'] . ' ' . $i['currency'];
					break;
				case '_sale_price_with_currency':
					$row[] = $i['sale_price'] ? ( $i['sale_price'] . ' ' . $i['currency'] ) : '';
					break;
				case '_availability_channel':
					$row[] = nexus_feed_availability_for( $channel_id, $i['availability'] );
					break;
				case '_title_truncated':
					$row[] = nexus_feed_title_for( $channel_id, $i['title'] );
					break;
				default:
					$row[] = $i[ $item_key ] ?? '';
			}
		}
		fputcsv( $fh, $row );
	}

	rewind( $fh );
	$out = stream_get_contents( $fh );
	fclose( $fh );
	return $out;
}


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — save / reset feed config
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_nexus_feed_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$id = sanitize_key( $_POST['channel'] ?? '' );
	$channels = nexus_feed_channels();
	if ( ! isset( $channels[ $id ] ) ) wp_send_json_error( [ 'message' => 'Unknown channel.' ] );

	$posted = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : [];
	$clean  = [];
	foreach ( $channels[ $id ]['fields'] as $field ) {
		$k = $field['key'];
		$v = $posted[ $k ] ?? '';
		if ( $field['type'] === 'checkbox' ) {
			$clean[ $k ] = ! empty( $v ) ? '1' : '';
		} elseif ( $field['type'] === 'select' ) {
			$opts = array_keys( $field['options'] ?? [] );
			$clean[ $k ] = in_array( $v, $opts, true ) ? $v : ( $opts[0] ?? '' );
		} else {
			$clean[ $k ] = sanitize_text_field( $v );
		}
	}

	$row = nexus_feed_save( $id, $clean );
	wp_send_json_success( [
		'message' => 'Feed configured. Submit the feed URL to ' . $channels[ $id ]['name'] . '.',
		'url'     => nexus_feed_url( $id ),
	] );
} );

add_action( 'wp_ajax_nexus_feed_delete', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );
	$id = sanitize_key( $_POST['channel'] ?? '' );
	if ( $id ) nexus_feed_delete( $id );
	wp_send_json_success( [ 'message' => 'Feed disabled.' ] );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  TAB RENDERER — one card per channel, similar shape to connector cards
// ═════════════════════════════════════════════════════════════════════════════

function nexus_render_channels_tab( string $tab_id, array $tab ): void {
	$channels = nexus_feed_channels();
	$desc = $tab['desc'] ?? '';
	nexus_page_head( __( 'Channels', 'nexus' ), $desc );

	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="th-cx-stub"><div class="th-cx-stub-mark">Heads up</div><h4 class="th-cx-stub-title">WooCommerce required</h4><p class="th-cx-stub-sub">Channels generate product feeds from WooCommerce. Install + activate WC, then come back.</p></div>';
		return;
	}
	?>
	<div class="th-conn-grid">
	<?php foreach ( $channels as $ch ):
		$row           = nexus_feed_config( $ch['id'] );
		$config        = $row['config'] ?? [];
		$is_configured = nexus_feed_is_configured( $ch['id'] );
		$feed_url      = $is_configured ? nexus_feed_url( $ch['id'] ) : '';
		$updated       = ! empty( $row['updated'] ) ? human_time_diff( $row['updated'] ) . ' ago' : null;
	?>
		<div class="th-conn-card" data-feed-channel="<?php echo esc_attr( $ch['id'] ); ?>">
			<div class="th-conn-card-head">
				<div class="th-conn-badge" style="background:<?php echo esc_attr( $ch['color'] ); ?>">
					<?php echo esc_html( $ch['initial'] ); ?>
				</div>
				<div class="th-conn-info">
					<div class="th-conn-name">
						<?php echo esc_html( $ch['name'] ); ?>
						<span class="th-conn-status <?php echo $is_configured ? 'th-conn-status-connected' : 'th-conn-status-off'; ?>" data-feed-status>
							<?php echo $is_configured ? esc_html__( 'Feed live', 'nexus' ) : esc_html__( 'Not configured', 'nexus' ); ?>
						</span>
					</div>
					<div class="th-conn-desc"><?php echo esc_html( $ch['desc'] ); ?></div>
				</div>
			</div>

			<?php if ( $is_configured ): ?>
				<div class="th-conn-bridge-fresh" data-feed-url-block>
					<strong><?php esc_html_e( 'Feed URL', 'nexus' ); ?></strong>
					<p><?php
						printf(
							/* translators: %s = channel name (e.g. Google Merchant Center) */
							esc_html__( 'Paste this into %s as a scheduled fetch source.', 'nexus' ),
							esc_html( $ch['name'] )
						);
					?></p>
					<input type="text" readonly value="<?php echo esc_attr( $feed_url ); ?>" onclick="this.select()" data-feed-url-input>
					<div style="display:flex;gap:8px;margin-top:4px">
						<a href="<?php echo esc_url( $feed_url ); ?>" target="_blank" rel="noopener" class="th-button">
							<?php esc_html_e( 'Preview feed →', 'nexus' ); ?>
						</a>
						<button type="button" class="th-button" data-feed-copy data-copy-target="<?php echo esc_attr( $feed_url ); ?>">
							<?php esc_html_e( 'Copy URL', 'nexus' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<div class="th-conn-foot">
				<span class="th-conn-foot-meta">
					<?php if ( $updated ): ?><?php esc_html_e( 'Updated', 'nexus' ); ?> <?php echo esc_html( $updated ); ?><?php endif; ?>
					<?php if ( ! empty( $ch['docs'] ) ): ?>
						<a href="<?php echo esc_url( $ch['docs'] ); ?>" target="_blank" rel="noopener" class="th-conn-docs"<?php if ( $updated ): ?> style="margin-left:8px"<?php endif; ?>>
							<?php esc_html_e( 'Docs ↗', 'nexus' ); ?>
						</a>
					<?php endif; ?>
				</span>
				<div class="th-conn-foot-actions">
					<?php if ( $is_configured ): ?>
						<button type="button" class="th-button" style="color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)" data-feed-disable>
							<?php esc_html_e( 'Disable feed', 'nexus' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" class="th-button <?php echo $is_configured ? '' : 'th-button-primary'; ?>" data-feed-toggle>
						<?php echo $is_configured ? esc_html__( 'Edit', 'nexus' ) : esc_html__( 'Configure', 'nexus' ); ?>
					</button>
				</div>
			</div>

			<div class="th-conn-form" data-feed-form hidden>
				<?php foreach ( $ch['fields'] as $field ):
					$val = $config[ $field['key'] ] ?? '';
				?>
				<div class="th-conn-field">
					<label>
						<?php echo esc_html( $field['label'] ); ?>
						<?php if ( ! empty( $field['required'] ) ): ?> <span style="color:var(--err)">*</span><?php endif; ?>
					</label>
					<?php if ( $field['type'] === 'select' ): ?>
						<select class="th-input" data-feed-field="<?php echo esc_attr( $field['key'] ); ?>">
							<?php foreach ( $field['options'] as $opt_val => $opt_label ): ?>
								<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $val, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php elseif ( $field['type'] === 'checkbox' ): ?>
						<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
							<input type="checkbox" data-feed-field="<?php echo esc_attr( $field['key'] ); ?>" <?php checked( $val, '1' ); ?>>
							<?php echo esc_html( $field['placeholder'] ?: $field['label'] ); ?>
						</label>
					<?php else: ?>
						<input type="text" class="th-input" data-feed-field="<?php echo esc_attr( $field['key'] ); ?>"
							value="<?php echo esc_attr( $val ); ?>"
							placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" autocomplete="off">
					<?php endif; ?>
				</div>
				<?php endforeach; ?>

				<div class="th-conn-form-actions">
					<span class="th-conn-result" data-feed-result></span>
					<div style="display:flex;gap:6px">
						<button type="button" class="th-button" data-feed-toggle><?php esc_html_e( 'Cancel', 'nexus' ); ?></button>
						<button type="button" class="th-button th-button-primary" data-feed-save><?php esc_html_e( 'Save & generate URL', 'nexus' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
	</div>
	<?php
}
