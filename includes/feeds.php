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
//  PER-PRODUCT OVERRIDES (Product → Nexus feed panel)
//
//  Adds a meta box to the WooCommerce product edit screen so a single
//  product can override any feed-relevant field: brand, GTIN, MPN,
//  google_product_category, condition, or "exclude from feeds entirely."
//  Saved as individual `_nexus_feed_*` post-meta keys. The normalizer
//  reads them first before falling back to global config.
// ═════════════════════════════════════════════════════════════════════════════

const NEXUS_FEED_OVERRIDE_KEYS = [
	// Core identifiers
	'brand'                   => '_nexus_feed_brand',
	'gtin'                    => '_nexus_feed_gtin',
	'mpn'                     => '_nexus_feed_mpn',
	'google_product_category' => '_nexus_feed_google_category',
	'condition'               => '_nexus_feed_condition',
	'excluded'                => '_nexus_feed_exclude',
	// Meta-spec extensions (2.3.0)
	'size_type'               => '_nexus_feed_size_type',     // regular | petite | plus | big and tall | maternity
	'size_system'             => '_nexus_feed_size_system',   // US | UK | EU | DE | FR | JP | CN | IT | BR | MEX | AU
	'multipack'               => '_nexus_feed_multipack',     // integer
	'is_bundle'               => '_nexus_feed_is_bundle',     // yes | no
	'availability_date'       => '_nexus_feed_avail_date',    // ISO 8601 for preorder release
	'cost_of_goods_sold'      => '_nexus_feed_cogs',          // for ROAS reporting
	'adult'                   => '_nexus_feed_adult',         // yes | no
	'video_link'              => '_nexus_feed_video',         // single URL
	'additional_video_link'   => '_nexus_feed_videos_extra',  // comma-separated URLs
	'custom_label_0'          => '_nexus_feed_cl0',
	'custom_label_1'          => '_nexus_feed_cl1',
	'custom_label_2'          => '_nexus_feed_cl2',
	'custom_label_3'          => '_nexus_feed_cl3',
	'custom_label_4'          => '_nexus_feed_cl4',
];

add_action( 'add_meta_boxes', function() {
	if ( ! class_exists( 'WooCommerce' ) ) return;
	add_meta_box(
		'nexus_feed_overrides',
		__( 'Nexus product feed', 'nexus' ),
		'nexus_feed_render_product_meta_box',
		'product',
		'side',
		'default'
	);
} );

function nexus_feed_render_product_meta_box( $post ): void {
	$get = function( $key ) use ( $post ) {
		return get_post_meta( $post->ID, NEXUS_FEED_OVERRIDE_KEYS[ $key ], true );
	};
	$excluded = $get( 'excluded' ) === '1';
	wp_nonce_field( 'nexus_feed_meta', 'nexus_feed_nonce' );
	?>
	<p style="font-size:11px;color:#666;margin:0 0 10px">Overrides feed defaults for this product. Blank = use channel-level fallback.</p>

	<p>
		<label style="display:flex;align-items:center;gap:6px;font-weight:600">
			<input type="checkbox" name="nexus_feed_exclude" value="1" <?php checked( $excluded ); ?>>
			Exclude from all feeds
		</label>
	</p>

	<details open><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Identifiers</summary>
		<p><label>Brand<br><input type="text" name="nexus_feed_brand" value="<?php echo esc_attr( $get( 'brand' ) ); ?>" style="width:100%"></label></p>
		<p><label>GTIN<br><input type="text" name="nexus_feed_gtin" value="<?php echo esc_attr( $get( 'gtin' ) ); ?>" style="width:100%" placeholder="8/12/13/14-digit UPC/EAN/ISBN"></label></p>
		<p><label>MPN<br><input type="text" name="nexus_feed_mpn" value="<?php echo esc_attr( $get( 'mpn' ) ); ?>" style="width:100%"></label></p>
	</details>

	<details><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Category + condition</summary>
		<p><label>Google product category<br><input type="text" name="nexus_feed_google_category" value="<?php echo esc_attr( $get( 'google_product_category' ) ); ?>" style="width:100%" placeholder="e.g. Apparel > Tops > T-Shirts"></label></p>
		<p>
			<label>Condition<br>
				<select name="nexus_feed_condition" style="width:100%">
					<option value="" <?php selected( $get( 'condition' ), '' ); ?>>— default —</option>
					<option value="new" <?php selected( $get( 'condition' ), 'new' ); ?>>New</option>
					<option value="used" <?php selected( $get( 'condition' ), 'used' ); ?>>Used</option>
					<option value="refurbished" <?php selected( $get( 'condition' ), 'refurbished' ); ?>>Refurbished</option>
				</select>
			</label>
		</p>
	</details>

	<details><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Apparel sizing (Meta)</summary>
		<p>
			<label>Size type<br>
				<select name="nexus_feed_size_type" style="width:100%">
					<option value="">— default —</option>
					<?php foreach ( [ 'regular', 'petite', 'plus', 'big and tall', 'maternity' ] as $opt ): ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $get( 'size_type' ), $opt ); ?>><?php echo esc_html( $opt ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
		<p>
			<label>Size system<br>
				<select name="nexus_feed_size_system" style="width:100%">
					<option value="">— default —</option>
					<?php foreach ( [ 'US', 'UK', 'EU', 'DE', 'FR', 'JP', 'CN', 'IT', 'BR', 'MEX', 'AU' ] as $opt ): ?>
						<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $get( 'size_system' ), $opt ); ?>><?php echo esc_html( $opt ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>
	</details>

	<details><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Bundle + multipack</summary>
		<p><label>Multipack<br><input type="number" min="0" name="nexus_feed_multipack" value="<?php echo esc_attr( $get( 'multipack' ) ); ?>" style="width:100%" placeholder="e.g. 6 (pack of 6)"></label></p>
		<p>
			<label>Is bundle?<br>
				<select name="nexus_feed_is_bundle" style="width:100%">
					<option value="" <?php selected( $get( 'is_bundle' ), '' ); ?>>— default —</option>
					<option value="yes" <?php selected( $get( 'is_bundle' ), 'yes' ); ?>>Yes</option>
					<option value="no"  <?php selected( $get( 'is_bundle' ), 'no'  ); ?>>No</option>
				</select>
			</label>
		</p>
	</details>

	<details><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Availability + COGS</summary>
		<p><label>Availability date (preorder release)<br><input type="text" name="nexus_feed_avail_date" value="<?php echo esc_attr( $get( 'availability_date' ) ); ?>" style="width:100%" placeholder="ISO 8601 e.g. 2026-08-01T00:00-0800"></label></p>
		<p><label>Cost of goods sold<br><input type="text" name="nexus_feed_cogs" value="<?php echo esc_attr( $get( 'cost_of_goods_sold' ) ); ?>" style="width:100%" placeholder="e.g. 12.50 USD (for ROAS)"></label></p>
		<p>
			<label>Adult content<br>
				<select name="nexus_feed_adult" style="width:100%">
					<option value="" <?php selected( $get( 'adult' ), '' ); ?>>— default (no) —</option>
					<option value="yes" <?php selected( $get( 'adult' ), 'yes' ); ?>>Yes (18+)</option>
					<option value="no"  <?php selected( $get( 'adult' ), 'no'  ); ?>>No</option>
				</select>
			</label>
		</p>
	</details>

	<details><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Video</summary>
		<p><label>Video link<br><input type="url" name="nexus_feed_video" value="<?php echo esc_attr( $get( 'video_link' ) ); ?>" style="width:100%" placeholder="https://…"></label></p>
		<p><label>Additional video links<br><input type="text" name="nexus_feed_videos_extra" value="<?php echo esc_attr( $get( 'additional_video_link' ) ); ?>" style="width:100%" placeholder="Comma-separated URLs"></label></p>
	</details>

	<details><summary style="cursor:pointer;font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px">Custom labels (ad set targeting)</summary>
		<p style="font-size:10px;color:#999;margin:0 0 6px">Free-form. Meta uses these for dynamic ad set segmentation (top-sellers, seasonal, margin tier, etc.).</p>
		<?php for ( $i = 0; $i <= 4; $i++ ): ?>
			<p><label>custom_label_<?php echo $i; ?><br><input type="text" name="nexus_feed_cl<?php echo $i; ?>" value="<?php echo esc_attr( $get( 'custom_label_' . $i ) ); ?>" style="width:100%"></label></p>
		<?php endfor; ?>
	</details>
	<?php
}

add_action( 'save_post_product', function( $post_id ) {
	if ( ! isset( $_POST['nexus_feed_nonce'] ) || ! wp_verify_nonce( $_POST['nexus_feed_nonce'], 'nexus_feed_meta' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	// Form key → override-keys map. Saves any non-blank, deletes blanks
	// so each row stays small. Mirrors the meta box exactly.
	$post_map = [
		'brand'                   => 'nexus_feed_brand',
		'gtin'                    => 'nexus_feed_gtin',
		'mpn'                     => 'nexus_feed_mpn',
		'google_product_category' => 'nexus_feed_google_category',
		'condition'               => 'nexus_feed_condition',
		'size_type'               => 'nexus_feed_size_type',
		'size_system'             => 'nexus_feed_size_system',
		'multipack'               => 'nexus_feed_multipack',
		'is_bundle'               => 'nexus_feed_is_bundle',
		'availability_date'       => 'nexus_feed_avail_date',
		'cost_of_goods_sold'      => 'nexus_feed_cogs',
		'adult'                   => 'nexus_feed_adult',
		'video_link'              => 'nexus_feed_video',
		'additional_video_link'   => 'nexus_feed_videos_extra',
		'custom_label_0'          => 'nexus_feed_cl0',
		'custom_label_1'          => 'nexus_feed_cl1',
		'custom_label_2'          => 'nexus_feed_cl2',
		'custom_label_3'          => 'nexus_feed_cl3',
		'custom_label_4'          => 'nexus_feed_cl4',
	];
	foreach ( $post_map as $field => $post_key ) {
		$v = isset( $_POST[ $post_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) : '';
		if ( $v === '' ) {
			delete_post_meta( $post_id, NEXUS_FEED_OVERRIDE_KEYS[ $field ] );
		} else {
			update_post_meta( $post_id, NEXUS_FEED_OVERRIDE_KEYS[ $field ], $v );
		}
	}

	$excluded = isset( $_POST['nexus_feed_exclude'] ) && $_POST['nexus_feed_exclude'] === '1';
	if ( $excluded ) {
		update_post_meta( $post_id, NEXUS_FEED_OVERRIDE_KEYS['excluded'], '1' );
	} else {
		delete_post_meta( $post_id, NEXUS_FEED_OVERRIDE_KEYS['excluded'] );
	}

	nexus_feed_invalidate_cache();
}, 10, 1 );


// ═════════════════════════════════════════════════════════════════════════════
//  CACHE — filesystem-backed, auto-invalidated on product change
// ═════════════════════════════════════════════════════════════════════════════

const NEXUS_FEED_CACHE_TTL = 300;  // 5 minutes — channels fetch on schedule, not realtime


// ═════════════════════════════════════════════════════════════════════════════
//  WC CATEGORY → GOOGLE PRODUCT TAXONOMY MAPPING
//
//  Stored as one option keyed by WC product_cat term ID → Google
//  taxonomy ID or path. The normalize step looks up each product's
//  primary category, finds a mapping, and uses it for the item's
//  google_product_category — overriding the global default.
// ═════════════════════════════════════════════════════════════════════════════

const NEXUS_FEED_CATMAP_OPTION = 'nexus_feed_category_map';

function nexus_feed_get_catmap(): array {
	$raw = get_option( NEXUS_FEED_CATMAP_OPTION, '' );
	if ( ! $raw ) return [];
	$d = json_decode( $raw, true );
	return is_array( $d ) ? $d : [];
}

function nexus_feed_save_catmap( array $map ): void {
	// Strip blanks so the option doesn't bloat over time.
	$clean = [];
	foreach ( $map as $term_id => $taxonomy ) {
		$term_id  = (int) $term_id;
		$taxonomy = trim( (string) $taxonomy );
		if ( $term_id > 0 && $taxonomy !== '' ) $clean[ $term_id ] = $taxonomy;
	}
	update_option( NEXUS_FEED_CATMAP_OPTION, wp_json_encode( $clean ), false );
	nexus_feed_invalidate_cache();
}

/**
 * Resolve the best Google taxonomy for a product by walking its
 * categories (deepest first) and returning the first mapped value.
 */
function nexus_feed_taxonomy_for_product( int $product_id ): string {
	$map = nexus_feed_get_catmap();
	if ( ! $map ) return '';
	$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'all' ] );
	if ( is_wp_error( $terms ) || ! $terms ) return '';
	// Sort deepest-first by term depth — child categories beat parents.
	usort( $terms, function( $a, $b ) {
		$da = count( get_ancestors( $a->term_id, 'product_cat' ) );
		$db = count( get_ancestors( $b->term_id, 'product_cat' ) );
		return $db <=> $da;
	} );
	foreach ( $terms as $t ) {
		if ( ! empty( $map[ $t->term_id ] ) ) {
			$val = (string) $map[ $t->term_id ];
			// If it's a numeric ID, resolve to the path label.
			if ( ctype_digit( $val ) ) {
				$label = nexus_google_taxonomy_label( $val );
				return $label ?: $val;
			}
			return $val;
		}
	}
	return '';
}


// ═════════════════════════════════════════════════════════════════════════════
//  PER-CHANNEL RULES ENGINE
//
//  Stored as JSON on the channel config row: an array of
//    [ when => { field => 'category', op => 'is|contains|not_in', value => '...' },
//      then => { field => 'condition', value => 'used' } ]
//  Applied in the normalize step after override resolution.
// ═════════════════════════════════════════════════════════════════════════════

function nexus_feed_apply_rules( array $item, array $rules, $product, $parent ): array {
	foreach ( $rules as $rule ) {
		$when = $rule['when'] ?? [];
		$then = $rule['then'] ?? [];
		if ( empty( $when['field'] ) || empty( $then['field'] ) ) continue;

		$test_val = (string) ( $item[ $when['field'] ] ?? '' );
		$expected = (string) ( $when['value']  ?? '' );
		$op       = (string) ( $when['op']     ?? 'is' );

		$match = false;
		switch ( $op ) {
			case 'is':       $match = strcasecmp( $test_val, $expected ) === 0; break;
			case 'contains': $match = $expected !== '' && stripos( $test_val, $expected ) !== false; break;
			case 'not_in':   $match = stripos( $test_val, $expected ) === false; break;
			case 'empty':    $match = $test_val === ''; break;
			case 'not_empty':$match = $test_val !== ''; break;
		}
		if ( $match ) {
			$item[ $then['field'] ] = (string) ( $then['value'] ?? '' );
		}
	}
	return $item;
}


// ═════════════════════════════════════════════════════════════════════════════
//  DESCRIPTION TEMPLATES — placeholder substitution
//
//  Replaces {{ field }} with the item's value. Supports: title,
//  short_description, description, price, brand, sku, category.
//  Empty template → use the existing description as-is.
// ═════════════════════════════════════════════════════════════════════════════

function nexus_feed_render_description_template( string $tpl, array $item, $product ): string {
	if ( $tpl === '' ) return (string) ( $item['description'] ?? '' );
	$repl = [
		'{{ title }}'             => $item['title']        ?? '',
		'{{ short_description }}' => $product && method_exists( $product, 'get_short_description' ) ? wp_strip_all_tags( $product->get_short_description() ) : '',
		'{{ description }}'       => $item['description']  ?? '',
		'{{ price }}'             => $item['price']        ?? '',
		'{{ brand }}'             => $item['brand']        ?? '',
		'{{ sku }}'               => $item['id']           ?? '',
		'{{ category }}'          => $item['google_product_category'] ?? '',
	];
	return strtr( $tpl, $repl );
}


// ═════════════════════════════════════════════════════════════════════════════
//  FEED FETCH ANALYTICS
//
//  Tracks how often each feed URL gets polled, by which user agent.
//  Stored on the feed config row — `fetch_count` + `last_fetched_at`
//  + `last_user_agent`. No PII (UA only).
// ═════════════════════════════════════════════════════════════════════════════

function nexus_feed_record_fetch( string $channel_id, string $user_agent ): void {
	$row = nexus_feed_config( $channel_id );
	if ( empty( $row ) ) return;
	$row['fetch_count']     = (int) ( $row['fetch_count'] ?? 0 ) + 1;
	$row['last_fetched_at'] = time();
	$row['last_user_agent'] = substr( (string) $user_agent, 0, 240 );
	update_option( 'nexus_feed_' . sanitize_key( $channel_id ), wp_json_encode( $row ), false );
}


// ═════════════════════════════════════════════════════════════════════════════
//  SCHEDULED PRE-WARM — keep the cache hot
//
//  Daily cron action that pre-renders every configured channel into
//  the cache so the channel's scheduled fetch never hits a cold cache.
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'nexus_feed_prewarm', 'nexus_feed_prewarm_all' );
add_action( 'init', function() {
	if ( function_exists( 'nexus_queue_recurring' ) ) {
		nexus_queue_recurring( 'nexus_feed_prewarm', [], HOUR_IN_SECONDS );
	}
} );

function nexus_feed_prewarm_all(): void {
	if ( ! class_exists( 'WooCommerce' ) ) return;
	$channels = nexus_feed_channels();
	foreach ( $channels as $cid => $ch ) {
		$row = nexus_feed_config( $cid );
		if ( empty( $row['enabled'] ) ) continue;
		$config = $row['config'] ?? [];
		$items  = nexus_feed_collect_products( $config );
		$body   = $ch['format'] === 'xml'
			? nexus_feed_render_google_xml( $items, $config )
			: nexus_feed_render_csv( $items, $cid, $config );
		nexus_feed_cache_put( $cid, $body );
	}
	if ( function_exists( 'nexus_audit_log' ) ) {
		nexus_audit_log( 'feed.prewarmed', count( $channels ) . ' channels' );
	}
}

function nexus_feed_cache_dir(): string {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'nexus-feeds-cache';
	if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
	}
	return $dir;
}

function nexus_feed_cache_path( string $channel_id ): string {
	return nexus_feed_cache_dir() . '/' . sanitize_file_name( $channel_id ) . '.cache';
}

function nexus_feed_cache_get( string $channel_id ): ?string {
	$path = nexus_feed_cache_path( $channel_id );
	if ( ! is_file( $path ) ) return null;
	if ( ( time() - filemtime( $path ) ) > NEXUS_FEED_CACHE_TTL ) return null;
	$content = file_get_contents( $path );
	return $content === false ? null : $content;
}

function nexus_feed_cache_put( string $channel_id, string $content ): void {
	@file_put_contents( nexus_feed_cache_path( $channel_id ), $content );
}

function nexus_feed_invalidate_cache(): void {
	$dir = nexus_feed_cache_dir();
	foreach ( (array) glob( $dir . '/*.cache' ) as $f ) {
		if ( is_file( $f ) ) @unlink( $f );
	}
}

// Auto-invalidate on any product write (publish, update, delete, stock change).
add_action( 'save_post_product', 'nexus_feed_invalidate_cache' );
add_action( 'delete_post',       function( $id ) { if ( get_post_type( $id ) === 'product' ) nexus_feed_invalidate_cache(); } );
add_action( 'woocommerce_product_set_stock', 'nexus_feed_invalidate_cache' );
add_action( 'woocommerce_variation_set_stock', 'nexus_feed_invalidate_cache' );


// ═════════════════════════════════════════════════════════════════════════════
//  WP ADMIN PRODUCTS LIST — feed column + bulk actions
//
//  Adds a "In feeds" column to the WC products list page so an admin
//  can see at-a-glance which products are excluded. Bulk actions let
//  you flip exclude on/off for many products at once.
// ═════════════════════════════════════════════════════════════════════════════

add_filter( 'manage_edit-product_columns', function( $cols ) {
	$cols['nexus_feed_status'] = __( 'In feeds', 'nexus' );
	return $cols;
} );

add_action( 'manage_product_posts_custom_column', function( $col, $post_id ) {
	if ( $col !== 'nexus_feed_status' ) return;
	$excluded = get_post_meta( $post_id, NEXUS_FEED_OVERRIDE_KEYS['excluded'], true ) === '1';
	if ( $excluded ) {
		echo '<span style="color:#a00;font-weight:600">✗ excluded</span>';
	} else {
		echo '<span style="color:#10b981">✓ included</span>';
	}
}, 10, 2 );

add_filter( 'bulk_actions-edit-product', function( $actions ) {
	$actions['nexus_feed_exclude'] = __( 'Exclude from Nexus feeds', 'nexus' );
	$actions['nexus_feed_include'] = __( 'Include in Nexus feeds', 'nexus' );
	return $actions;
} );

add_filter( 'handle_bulk_actions-edit-product', function( $redirect, $action, $post_ids ) {
	if ( ! in_array( $action, [ 'nexus_feed_exclude', 'nexus_feed_include' ], true ) ) return $redirect;
	$flag = $action === 'nexus_feed_exclude';
	foreach ( $post_ids as $pid ) {
		if ( $flag ) {
			update_post_meta( $pid, NEXUS_FEED_OVERRIDE_KEYS['excluded'], '1' );
		} else {
			delete_post_meta( $pid, NEXUS_FEED_OVERRIDE_KEYS['excluded'] );
		}
	}
	nexus_feed_invalidate_cache();
	return add_query_arg( 'nexus_feed_updated', count( $post_ids ), $redirect );
}, 10, 3 );

add_action( 'admin_notices', function() {
	if ( empty( $_GET['nexus_feed_updated'] ) ) return;
	$n = (int) $_GET['nexus_feed_updated'];
	echo '<div class="notice notice-success is-dismissible"><p>Updated Nexus feed status on ' . $n . ' product' . ( $n === 1 ? '' : 's' ) . '.</p></div>';
} );


// ═════════════════════════════════════════════════════════════════════════════
//  CATEGORY MAPPING — admin AJAX (save / reset)
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_nexus_feed_catmap_save', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$raw = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : [];
	$clean = [];
	foreach ( $raw as $term_id => $taxonomy ) {
		$clean[ (int) $term_id ] = sanitize_text_field( $taxonomy );
	}
	nexus_feed_save_catmap( $clean );
	wp_send_json_success( [ 'count' => count( array_filter( $clean ) ) ] );
} );


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

		// ── Inclusion / exclusion filters ────────────────────────────────
		[ 'key' => 'include_out_of_stock', 'label' => 'Include out-of-stock products', 'type' => 'checkbox', 'placeholder' => '', 'required' => false ],
		[ 'key' => 'featured_only',        'label' => 'Featured products only',         'type' => 'checkbox', 'placeholder' => 'Only ship products with WC\'s featured flag set', 'required' => false ],
		[ 'key' => 'require_image',        'label' => 'Skip products without an image', 'type' => 'checkbox', 'placeholder' => 'Most channels reject imageless rows anyway', 'required' => false ],
		[ 'key' => 'min_price',            'label' => 'Minimum price',                   'type' => 'text',     'placeholder' => 'e.g. 5.00 — products under this are dropped', 'required' => false ],
		[ 'key' => 'exclude_categories',   'label' => 'Exclude category IDs',            'type' => 'text',     'placeholder' => 'Comma-separated WC product_cat IDs',           'required' => false ],

		// ── Per-product field auto-detect overrides ──────────────────────
		[ 'key' => 'brand_field',          'label' => 'Per-product brand custom field key', 'type' => 'text', 'placeholder' => 'e.g. _brand — leave blank for the fallback', 'required' => false ],
		[ 'key' => 'gtin_field',           'label' => 'Per-product GTIN custom field key',  'type' => 'text', 'placeholder' => 'e.g. _gtin',         'required' => false ],
		[ 'key' => 'mpn_field',            'label' => 'Per-product MPN custom field key',   'type' => 'text', 'placeholder' => 'e.g. _mpn',          'required' => false ],

		// ── Description template (placeholder substitution) ──────────────
		[ 'key' => 'description_template', 'label' => 'Description template',  'type' => 'text', 'placeholder' => 'e.g. {{ title }} — {{ short_description }} (leave blank for default)', 'required' => false ],
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
		'snapchat-catalog' => [
			'id'     => 'snapchat-catalog',
			'name'   => 'Snapchat Catalog',
			'color'  => '#fffc00',
			'initial'=> 'Sc',
			'desc'   => 'Snapchat Ads dynamic product catalog. CSV — same schema family as Meta/Pinterest.',
			'format' => 'csv',
			'docs'   => 'https://businesshelp.snapchat.com/s/article/catalogs-overview',
			'fields' => $shared_fields,
		],
		'klaviyo-catalog' => [
			'id'     => 'klaviyo-catalog',
			'name'   => 'Klaviyo Catalog',
			'color'  => '#000000',
			'initial'=> 'Kl',
			'desc'   => 'Klaviyo product catalog feed — powers product recommendations + abandoned cart emails.',
			'format' => 'csv',
			'docs'   => 'https://help.klaviyo.com/hc/en-us/articles/115005268747',
			'fields' => $shared_fields,
		],
		'walmart-marketplace' => [
			'id'     => 'walmart-marketplace',
			'name'   => 'Walmart Marketplace',
			'color'  => '#0071dc',
			'initial'=> 'W',
			'desc'   => 'Walmart Marketplace seller-feed format. CSV-based, similar schema to Google Shopping with Walmart-specific extensions.',
			'format' => 'csv',
			'docs'   => 'https://sellerhelp.walmart.com/seller/s/article/Creating-or-Updating-Items-via-Bulk-Item-Setup',
			'fields' => $shared_fields,
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

	// Track who polls us + how often. Useful for spotting silent feed
	// drift (Google stopped fetching — why?) and for catching scrapers.
	nexus_feed_record_fetch( $channel_id, (string) $req->get_header( 'user_agent' ) );

	// Cache layer — feed body cached on disk for NEXUS_FEED_CACHE_TTL
	// (5 min default). Auto-invalidated on any WC product save/delete
	// or stock change via the hooks at the top of this file.
	$cached = nexus_feed_cache_get( $channel_id );

	if ( $channel['format'] === 'xml' ) {
		header( 'Content-Type: application/xml; charset=utf-8' );
		if ( $cached !== null ) {
			header( 'X-Nexus-Cache: hit' );
			echo $cached;
		} else {
			header( 'X-Nexus-Cache: miss' );
			$items  = nexus_feed_collect_products( $config );
			$body   = nexus_feed_render_google_xml( $items, $config );
			nexus_feed_cache_put( $channel_id, $body );
			echo $body;
		}
	} else {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="nexus-' . $channel_id . '.csv"' );
		if ( $cached !== null ) {
			header( 'X-Nexus-Cache: hit' );
			echo $cached;
		} else {
			header( 'X-Nexus-Cache: miss' );
			$items  = nexus_feed_collect_products( $config );
			$body   = nexus_feed_render_csv( $items, $channel_id, $config );
			nexus_feed_cache_put( $channel_id, $body );
			echo $body;
		}
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
	$include_oos    = ! empty( $config['include_out_of_stock'] );
	$featured_only  = ! empty( $config['featured_only'] );
	$require_image  = ! empty( $config['require_image'] );
	$min_price      = (float) ( $config['min_price'] ?? 0 );
	$exclude_cats   = nexus_feed_parse_id_list( (string) ( $config['exclude_categories'] ?? '' ) );

	$args = [
		'status' => 'publish',
		'limit'  => -1,
		'type'   => [ 'simple', 'variable' ],
		'return' => 'objects',
	];
	if ( ! $include_oos )   $args['stock_status'] = 'instock';
	if ( $featured_only )   $args['featured']     = true;
	if ( $exclude_cats )    $args['category']     = []; // we filter manually below since 'exclude_category' isn't supported

	$products = wc_get_products( $args );
	$items    = [];

	foreach ( $products as $product ) {
		// Per-product opt-out via the meta box.
		if ( get_post_meta( $product->get_id(), NEXUS_FEED_OVERRIDE_KEYS['excluded'], true ) === '1' ) continue;

		// Category exclusion — manual since WC's category arg has no "NOT IN" form.
		if ( $exclude_cats ) {
			$product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
			if ( is_array( $product_cats ) && array_intersect( $exclude_cats, $product_cats ) ) continue;
		}

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_available_variations( 'objects' ) as $var ) {
				if ( ! $include_oos && ! $var->is_in_stock() ) continue;
				if ( $min_price > 0 && (float) $var->get_price() < $min_price ) continue;
				$norm = nexus_feed_normalize( $var, $product, $config );
				if ( ! $norm ) continue;
				if ( $require_image && empty( $norm['image_link'] ) ) continue;
				$items[] = $norm;
			}
		} else {
			if ( $min_price > 0 && (float) $product->get_price() < $min_price ) continue;
			$norm = nexus_feed_normalize( $product, null, $config );
			if ( ! $norm ) continue;
			if ( $require_image && empty( $norm['image_link'] ) ) continue;
			$items[] = $norm;
		}
	}
	// Apply per-channel rules + description template AFTER collection so
	// they're tested once per item (not per WC variation expansion).
	$rules    = is_array( $config['rules'] ?? null ) ? $config['rules'] : [];
	$tpl      = (string) ( $config['description_template'] ?? '' );
	if ( $rules || $tpl !== '' ) {
		$items = array_map( function( $i ) use ( $rules, $tpl ) {
			if ( $rules ) $i = nexus_feed_apply_rules( $i, $rules, null, null );
			if ( $tpl !== '' ) $i['description'] = nexus_feed_render_description_template( $tpl, $i, null );
			return $i;
		}, $items );
	}

	return array_values( array_filter( $items ) );
}

/** Parse "12, 14,17" → [12, 14, 17]. */
function nexus_feed_parse_id_list( string $s ): array {
	if ( $s === '' ) return [];
	$ids = array_filter( array_map( 'intval', preg_split( '/[,\s]+/', $s ) ) );
	return array_values( array_unique( $ids ) );
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

	// Per-product overrides (from the Nexus product meta box) take
	// precedence over every auto-detect chain below. The product editor
	// is the one place where a human said "this product specifically
	// needs X" — respect that without fallback.
	$parent_or_self_id  = $parent ? $parent->get_id() : $id;
	$ov = function( string $key ) use ( $parent_or_self_id ): string {
		return (string) get_post_meta( $parent_or_self_id, NEXUS_FEED_OVERRIDE_KEYS[ $key ], true );
	};
	$override_brand        = $ov( 'brand' );
	$override_gtin         = $ov( 'gtin' );
	$override_mpn          = $ov( 'mpn' );
	$override_gcat         = $ov( 'google_product_category' );
	$override_condition    = $ov( 'condition' );
	$override_size_type    = $ov( 'size_type' );
	$override_size_system  = $ov( 'size_system' );
	$override_multipack    = $ov( 'multipack' );
	$override_is_bundle    = $ov( 'is_bundle' );
	$override_avail_date   = $ov( 'availability_date' );
	$override_cogs         = $ov( 'cost_of_goods_sold' );
	$override_adult        = $ov( 'adult' );
	$override_video        = $ov( 'video_link' );
	$override_videos_extra = $ov( 'additional_video_link' );
	$override_cl0          = $ov( 'custom_label_0' );
	$override_cl1          = $ov( 'custom_label_1' );
	$override_cl2          = $ov( 'custom_label_2' );
	$override_cl3          = $ov( 'custom_label_3' );
	$override_cl4          = $ov( 'custom_label_4' );

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
	$brand = $override_brand; // per-product override wins
	if ( ! $brand && $brand_field ) {
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
	// installs use `_gtin`, `_ean`, `_upc`, or `_isbn`. Per-product
	// override (from the meta box) wins over all auto-detect.
	$gtin_field = (string) ( $config['gtin_field'] ?? '' );
	$gtin = $override_gtin;
	if ( ! $gtin && $gtin_field ) {
		$gtin = (string) $product->get_meta( $gtin_field );
		if ( ! $gtin && $parent ) $gtin = (string) $parent->get_meta( $gtin_field );
	}
	foreach ( [ '_global_unique_id', '_gtin', '_ean', '_upc', '_isbn' ] as $k ) {
		if ( $gtin ) break;
		$gtin = (string) $product->get_meta( $k );
		if ( ! $gtin && $parent ) $gtin = (string) $parent->get_meta( $k );
	}

	// MPN auto-detect. Less standardized — try the common keys.
	$mpn_field = (string) ( $config['mpn_field'] ?? '' );
	$mpn = $override_mpn;
	if ( ! $mpn && $mpn_field ) {
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
	// Preorder detection. If the user set an explicit availability_date
	// override AND we're not currently in stock, treat as "preorder" — Meta
	// shows a preorder badge and accepts orders ahead of the release date.
	// Also catches WC backorder products that are out of stock but allow
	// backorders ("preorder" semantically matches Meta/Google's backorder).
	$avail_date = $override_avail_date;
	if ( $availability === 'out_of_stock' ) {
		$backorders = $product->get_backorders();
		if ( $backorders === 'yes' || $backorders === 'notify' ) {
			$availability = 'backorder';
		}
		if ( $avail_date ) {
			$availability = 'preorder';
		}
	}

	// Shipping weight (Meta + Google use this for shipping cost calc).
	$weight = '';
	if ( $product->has_weight() ) {
		$weight = $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
	} elseif ( $parent && $parent->has_weight() ) {
		$weight = $parent->get_weight() . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
	}

	// product_type — derived from the WC category breadcrumb of the parent
	// (or self if simple). Meta/Google use this as the merchant's own
	// taxonomy ("Apparel > Tops > T-Shirts") which they prefer over the
	// google_product_category for ad-set targeting and on-platform browse.
	$product_type = '';
	$cat_terms = get_the_terms( $parent_or_self_id, 'product_cat' );
	if ( $cat_terms && ! is_wp_error( $cat_terms ) ) {
		// Pick the deepest term (most specific) — that's the one with the
		// longest ancestor chain. Tiebreak: first one returned.
		$deepest = null;
		$deepest_depth = -1;
		foreach ( $cat_terms as $t ) {
			$ancestors = get_ancestors( $t->term_id, 'product_cat' );
			$depth = count( $ancestors );
			if ( $depth > $deepest_depth ) {
				$deepest = $t;
				$deepest_depth = $depth;
			}
		}
		if ( $deepest ) {
			$chain = array_reverse( get_ancestors( $deepest->term_id, 'product_cat' ) );
			$names = [];
			foreach ( $chain as $aid ) {
				$at = get_term( $aid, 'product_cat' );
				if ( $at && ! is_wp_error( $at ) ) $names[] = $at->name;
			}
			$names[]      = $deepest->name;
			$product_type = implode( ' > ', $names );
		}
	}

	// Cost of goods sold — auto-detect. WC's Cost of Goods Sold plugin
	// (and several third-party variants) all standardize on `_wc_cog_cost`.
	// Per-product meta-box override wins. Numeric only.
	$cogs = $override_cogs;
	if ( ! $cogs ) {
		foreach ( [ '_wc_cog_cost', '_cogs_cost', '_cost', '_cost_price' ] as $k ) {
			$v = (string) $product->get_meta( $k );
			if ( ! $v && $parent ) $v = (string) $parent->get_meta( $k );
			if ( $v !== '' && is_numeric( $v ) ) { $cogs = number_format( (float) $v, 2, '.', '' ); break; }
		}
	}

	// identifier_exists — Meta/Google flag for items without a real GTIN/MPN.
	// "yes" (or omit) for branded products with valid IDs, "no" for custom /
	// handmade / vintage where no UPC exists. Auto-derive from presence.
	$identifier_exists = ( $gtin || ( $brand && $mpn ) ) ? 'yes' : 'no';

	// Apparel sizing — overrides only (no sane way to auto-derive). Default
	// blank; Meta tolerates absence on most categories.
	$size_type   = $override_size_type;
	$size_system = $override_size_system;

	// Bundle / multipack — overrides only. is_bundle is a yes/no flag in
	// Meta; multipack is an integer (number of units in the SKU).
	$is_bundle = $override_is_bundle === '1' ? 'yes' : '';
	$multipack = ctype_digit( $override_multipack ) ? (int) $override_multipack : '';

	// Adult flag. WC's "adult content" mark could live in several places —
	// for now only honor the explicit override + the post's `_adult_only`
	// meta which a few erotica plugins use.
	$adult = $override_adult;
	if ( ! $adult ) {
		$am = (string) $product->get_meta( '_adult_only' );
		if ( $am === '1' || $am === 'yes' ) $adult = 'yes';
	}

	// Custom labels 0-4 — Google + Meta accept these as opaque ad-set
	// segmentation tags. Override-only.
	$custom_labels = [
		$override_cl0,
		$override_cl1,
		$override_cl2,
		$override_cl3,
		$override_cl4,
	];

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
		'availability' => $availability,    // 'in_stock' | 'out_of_stock' | 'preorder' | 'backorder'
		'availability_date' => $avail_date, // ISO-8601, only set for preorder/backorder
		'inventory'    => (int) $qty,       // Meta / IG — quantity_to_sell_on_facebook
		'condition'    => $override_condition ?: (string) ( $config['condition'] ?? 'new' ),
		'brand'        => $brand,
		'gtin'         => $gtin,
		'mpn'          => $mpn,
		'identifier_exists' => $identifier_exists,
		// Resolution order for google_product_category:
		//   1. Per-product meta-box override (most specific)
		//   2. WC category → Google taxonomy mapping (next-most specific)
		//   3. Channel-level default (broadest)
		'google_product_category' => $override_gcat
			?: nexus_feed_taxonomy_for_product( $parent_or_self_id )
			?: (string) ( $config['google_product_category'] ?? '' ),
		'fb_product_category'     => (string) ( $config['fb_product_category']     ?? '' ),
		'product_type'            => $product_type, // merchant taxonomy from WC category chain
		'item_group_id'           => $parent ? (string) $parent->get_id() : '',
		'color'        => $color,
		'size'         => $size,
		'size_type'    => $size_type,    // 'regular' | 'petite' | 'plus' | 'big and tall' | 'maternity'
		'size_system'  => $size_system,  // 'US' | 'UK' | 'EU' | 'AU' | 'BR' | 'CN' | 'FR' | 'DE' | 'IT' | 'JP' | 'MEX'
		'material'     => $material,
		'pattern'      => $pattern,
		'gender'       => $gender,
		'age_group'    => $age_group,
		'weight'       => $weight,
		'multipack'    => $multipack,
		'is_bundle'    => $is_bundle,
		'cost_of_goods_sold' => $cogs,
		'adult'        => $adult,
		'video_link'   => $override_video,
		'additional_video_link' => $override_videos_extra,
		'custom_label_0' => $custom_labels[0],
		'custom_label_1' => $custom_labels[1],
		'custom_label_2' => $custom_labels[2],
		'custom_label_3' => $custom_labels[3],
		'custom_label_4' => $custom_labels[4],
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
		'preorder'     => [
			'meta-catalog'      => 'preorder',
			'pinterest-catalog' => 'preorder',
			'tiktok-shop'       => 'preorder',
			'google-shopping'   => 'preorder',
			'bing-merchant'     => 'preorder',
		],
		'backorder'    => [
			// Meta/Pinterest use 'available for order'; Google + Bing use 'backorder'.
			'meta-catalog'      => 'available for order',
			'pinterest-catalog' => 'available for order',
			'tiktok-shop'       => 'available for order',
			'google-shopping'   => 'backorder',
			'bing-merchant'     => 'backorder',
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
		// availability — convert the normalized value to Google's wire format.
		$xml .= '  <g:availability>' . nexus_feed_xml( nexus_feed_availability_for( 'google-shopping', $i['availability'] ) ) . "</g:availability>\n";
		if ( ! empty( $i['availability_date'] ) ) {
			$xml .= '  <g:availability_date>' . nexus_feed_xml( $i['availability_date'] ) . "</g:availability_date>\n";
		}
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
		// identifier_exists — explicit "no" when neither GTIN nor MPN is present.
		// Otherwise honor the per-item value (handmade/custom = "no" even if
		// brand+something else are present).
		if ( ! empty( $i['identifier_exists'] ) && $i['identifier_exists'] === 'no' ) {
			$xml .= "  <g:identifier_exists>no</g:identifier_exists>\n";
		} elseif ( ! $i['gtin'] && ! $i['mpn'] ) {
			$xml .= "  <g:identifier_exists>no</g:identifier_exists>\n";
		}
		if ( $i['google_product_category'] ) $xml .= '  <g:google_product_category>' . nexus_feed_xml( $i['google_product_category'] ) . "</g:google_product_category>\n";
		if ( ! empty( $i['product_type'] ) ) $xml .= '  <g:product_type>' . nexus_feed_xml( $i['product_type'] ) . "</g:product_type>\n";
		if ( $i['item_group_id'] )           $xml .= '  <g:item_group_id>' . nexus_feed_xml( $i['item_group_id'] ) . "</g:item_group_id>\n";
		// Variant attributes — Google's apparel feed requires color/size/etc.
		foreach ( [ 'color', 'size', 'size_type', 'size_system', 'material', 'pattern', 'gender', 'age_group' ] as $attr ) {
			if ( ! empty( $i[ $attr ] ) ) {
				$xml .= '  <g:' . $attr . '>' . nexus_feed_xml( $i[ $attr ] ) . '</g:' . $attr . ">\n";
			}
		}
		if ( $i['weight'] ) $xml .= '  <g:shipping_weight>' . nexus_feed_xml( $i['weight'] ) . "</g:shipping_weight>\n";
		// Bundle / multipack — Google accepts both at the item level for
		// SKU disambiguation (a 4-pack of a single-pack base SKU).
		if ( ! empty( $i['multipack'] ) ) $xml .= '  <g:multipack>' . (int) $i['multipack'] . "</g:multipack>\n";
		if ( ! empty( $i['is_bundle'] ) ) $xml .= '  <g:is_bundle>' . nexus_feed_xml( $i['is_bundle'] ) . "</g:is_bundle>\n";
		// COGS — Google calls this cost_of_goods_sold; used in profit-based
		// bidding optimizations.
		if ( ! empty( $i['cost_of_goods_sold'] ) ) {
			$xml .= '  <g:cost_of_goods_sold>' . nexus_feed_xml( $i['cost_of_goods_sold'] . ' ' . $i['currency'] ) . "</g:cost_of_goods_sold>\n";
		}
		if ( ! empty( $i['adult'] ) )      $xml .= '  <g:adult>' . nexus_feed_xml( $i['adult'] ) . "</g:adult>\n";
		if ( ! empty( $i['video_link'] ) ) $xml .= '  <g:video_link>' . nexus_feed_xml( $i['video_link'] ) . "</g:video_link>\n";
		if ( ! empty( $i['additional_video_link'] ) ) {
			foreach ( array_filter( array_map( 'trim', explode( ',', (string) $i['additional_video_link'] ) ) ) as $vurl ) {
				$xml .= '  <g:additional_video_link>' . nexus_feed_xml( $vurl ) . "</g:additional_video_link>\n";
			}
		}
		// Custom labels 0-4 — Google ad-set segmentation tags.
		for ( $cli = 0; $cli < 5; $cli++ ) {
			$ck = 'custom_label_' . $cli;
			if ( ! empty( $i[ $ck ] ) ) $xml .= '  <g:' . $ck . '>' . nexus_feed_xml( $i[ $ck ] ) . '</g:' . $ck . ">\n";
		}
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
		'availability_date'           => 'availability_date',
		'condition'                   => 'condition',
		'price'                       => '_price_with_currency',
		'sale_price'                  => '_sale_price_with_currency',
		'sale_price_effective_date'   => 'sale_price_effective_date',
		'brand'                       => 'brand',
		'gtin'                        => 'gtin',
		'mpn'                         => 'mpn',
		'identifier_exists'           => 'identifier_exists',
		'google_product_category'     => 'google_product_category',
		'product_type'                => 'product_type',
		'item_group_id'               => 'item_group_id',
		'color'                       => 'color',
		'size'                        => 'size',
		'material'                    => 'material',
		'pattern'                     => 'pattern',
		'gender'                      => 'gender',
		'age_group'                   => 'age_group',
		'shipping_weight'             => 'weight',
		'custom_label_0'              => 'custom_label_0',
		'custom_label_1'              => 'custom_label_1',
		'custom_label_2'              => 'custom_label_2',
		'custom_label_3'              => 'custom_label_3',
		'custom_label_4'              => 'custom_label_4',
	];

	// Channel-specific tweaks — header NAMES differ across channels for
	// what's logically the same data. Meta in particular has its own
	// names that IG/FB look for; if you don't use those exact strings,
	// Instagram Shopping silently drops the inventory count.
	switch ( $channel_id ) {
		case 'meta-catalog':
			// Meta Commerce Manager canonical extras — these are the columns
			// IG/FB ad teams actually use for catalog optimization. Order
			// here matters for human readability of the export but Meta
			// matches on header name so it's tolerant.
			$columns['quantity_to_sell_on_facebook'] = 'inventory';
			$columns['fb_product_category']          = 'fb_product_category';
			$columns['size_type']                    = 'size_type';
			$columns['size_system']                  = 'size_system';
			$columns['multipack']                    = 'multipack';
			$columns['is_bundle']                    = 'is_bundle';
			$columns['cost_of_goods_sold']           = 'cost_of_goods_sold';
			$columns['adult']                        = 'adult';
			$columns['video[url]']                   = 'video_link';
			$columns['additional_video_link']        = 'additional_video_link';
			break;
		case 'pinterest-catalog':
			$columns['inventory']            = 'inventory';
			$columns['size_type']            = 'size_type';
			$columns['size_system']          = 'size_system';
			$columns['adult']                = 'adult';
			$columns['video_link']           = 'video_link';
			break;
		case 'tiktok-shop':
			$columns['quantity']             = 'inventory';
			$columns['video_link']           = 'video_link';
			break;
		case 'bing-merchant':
			$columns['inventory']            = 'inventory';
			$columns['size_type']            = 'size_type';
			$columns['size_system']          = 'size_system';
			$columns['multipack']            = 'multipack';
			$columns['is_bundle']            = 'is_bundle';
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

/**
 * Pre-flight validator. Runs the full collection pipeline + checks
 * each item against the channel's required-field list. Returns
 * counts + a sample of failures so the user can fix products before
 * Google / Meta starts disapproving listings.
 */
add_action( 'wp_ajax_nexus_feed_validate', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$id = sanitize_key( $_POST['channel'] ?? '' );
	$channels = nexus_feed_channels();
	if ( ! isset( $channels[ $id ] ) ) wp_send_json_error( [ 'message' => 'Unknown channel.' ] );
	if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( [ 'message' => 'WooCommerce is required.' ] );

	$row    = nexus_feed_config( $id );
	$config = $row['config'] ?? [];
	$items  = nexus_feed_collect_products( $config );

	// Required fields per channel — what gets rejected if missing.
	$required = [
		'id', 'title', 'description', 'link', 'image_link', 'price', 'availability', 'brand',
	];
	$failures = [];
	$pass     = 0;
	foreach ( $items as $i ) {
		$missing = [];
		foreach ( $required as $r ) {
			if ( empty( $i[ $r ] ) ) $missing[] = $r;
		}
		if ( $missing ) {
			$failures[] = [
				'id'      => (string) ( $i['id'] ?? '?' ),
				'title'   => (string) ( $i['title'] ?? '' ),
				'missing' => $missing,
			];
		} else {
			$pass++;
		}
	}

	wp_send_json_success( [
		'channel'      => $id,
		'channel_name' => $channels[ $id ]['name'],
		'total'        => count( $items ),
		'valid'        => $pass,
		'invalid'      => count( $failures ),
		'failures'     => array_slice( $failures, 0, 50 ), // cap UI payload
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

/**
 * Renders the WC category → Google taxonomy mapping panel above
 * the channel cards. Each WC product_cat term gets a row; user picks
 * a Google taxonomy from the bundled curated list OR pastes a
 * custom path / ID. Save flows through nexus_feed_catmap_save AJAX.
 */
function nexus_render_catmap_editor(): void {
	$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
	if ( is_wp_error( $terms ) || empty( $terms ) ) return;

	$map      = nexus_feed_get_catmap();
	$taxonomy = nexus_google_taxonomy();
	$mapped_n = count( array_filter( $map ) );
	?>
	<details class="nexus-catmap" style="margin-bottom:18px;border:1px solid var(--bd);border-radius:14px;background:var(--sf);padding:14px 18px">
		<summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;list-style:none">
			<div>
				<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3)"><?php esc_html_e( 'WC category → Google taxonomy mapping', 'nexus' ); ?></div>
				<div style="font-size:13px;font-weight:600;margin-top:2px">
					<?php
						printf(
							/* translators: 1 = mapped count, 2 = total WC categories */
							esc_html__( '%1$d / %2$d categories mapped', 'nexus' ),
							$mapped_n, count( $terms )
						);
					?>
				</div>
			</div>
			<span style="font-size:11px;color:var(--tx3)"><?php esc_html_e( '↓ expand', 'nexus' ); ?></span>
		</summary>
		<p style="font-size:12px;color:var(--tx2);margin:12px 0">
			<?php esc_html_e( "Map each WC product category to a Google Product Taxonomy entry. Per-product overrides win; this is the next-most-specific source. Channel-level google_product_category is the broadest fallback.", 'nexus' ); ?>
		</p>
		<form data-nexus-catmap-form>
			<table style="width:100%;border-collapse:collapse;font-size:13px">
				<thead>
					<tr style="text-align:left;color:var(--tx3);font-size:11px">
						<th style="padding:6px 4px"><?php esc_html_e( 'WC Category', 'nexus' ); ?></th>
						<th style="padding:6px 4px"><?php esc_html_e( 'Maps to Google Taxonomy', 'nexus' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $terms as $t ):
						$current = (string) ( $map[ $t->term_id ] ?? '' );
					?>
					<tr style="border-top:1px solid var(--bd)">
						<td style="padding:8px 4px;font-weight:600"><?php echo esc_html( $t->name ); ?> <span style="color:var(--tx3);font-weight:400;font-size:11px">· id <?php echo (int) $t->term_id; ?></span></td>
						<td style="padding:8px 4px">
							<select name="map[<?php echo (int) $t->term_id; ?>]" style="width:100%;font-size:12px;padding:6px 8px;border:1px solid var(--bd);border-radius:6px;background:var(--sf)">
								<option value=""><?php esc_html_e( '— no mapping (use fallback) —', 'nexus' ); ?></option>
								<?php foreach ( $taxonomy as $tid => $path ): ?>
									<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( $current, (string) $tid ); ?> <?php selected( $current, $path ); ?>><?php echo esc_html( $path ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $current && ! isset( $taxonomy[ (int) $current ] ) && ! in_array( $current, $taxonomy, true ) ): ?>
								<input type="text" name="map[<?php echo (int) $t->term_id; ?>]" value="<?php echo esc_attr( $current ); ?>" style="margin-top:4px;width:100%;font-size:11px;padding:5px 8px;border:1px solid var(--bd);border-radius:6px" placeholder="<?php esc_attr_e( 'Custom taxonomy path or ID', 'nexus' ); ?>">
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div style="display:flex;justify-content:flex-end;margin-top:12px">
				<button type="button" class="th-button th-button-primary" data-nexus-catmap-save><?php esc_html_e( 'Save category mapping', 'nexus' ); ?></button>
				<span style="margin-left:10px;font-size:12px" data-nexus-catmap-result></span>
			</div>
		</form>
	</details>
	<?php
}

function nexus_render_channels_tab( string $tab_id, array $tab ): void {
	$channels = nexus_feed_channels();
	$desc = $tab['desc'] ?? '';
	nexus_page_head( __( 'Channels', 'nexus' ), $desc );

	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="th-cx-stub"><div class="th-cx-stub-mark">Heads up</div><h4 class="th-cx-stub-title">WooCommerce required</h4><p class="th-cx-stub-sub">Channels generate product feeds from WooCommerce. Install + activate WC, then come back.</p></div>';
		return;
	}

	// Category mapping editor — collapsible, lives above the channel cards.
	nexus_render_catmap_editor();
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

					<?php
						// Analytics — surface fetch count + last-fetch age + UA snippet
						// so the user can confirm Google/Meta are actually polling.
						$fetches  = (int) ( $row['fetch_count'] ?? 0 );
						$last_at  = (int) ( $row['last_fetched_at'] ?? 0 );
						$last_ua  = (string) ( $row['last_user_agent'] ?? '' );
					?>
					<?php if ( $fetches > 0 ): ?>
						<div style="font-size:11px;color:var(--tx3);margin-top:6px;line-height:1.6">
							<strong style="color:var(--tx2)"><?php echo (int) $fetches; ?></strong> fetches ·
							last: <strong style="color:var(--tx2)"><?php echo $last_at ? esc_html( human_time_diff( $last_at ) . ' ago' ) : '—'; ?></strong>
							<?php if ( $last_ua ): ?><br><span style="opacity:.7">UA: <?php echo esc_html( substr( $last_ua, 0, 90 ) ); ?></span><?php endif; ?>
						</div>
					<?php else: ?>
						<div style="font-size:11px;color:var(--tx3);margin-top:6px"><?php esc_html_e( 'No fetches yet — submit the URL to the channel\'s dashboard to start.', 'nexus' ); ?></div>
					<?php endif; ?>
					<div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap">
						<a href="<?php echo esc_url( $feed_url ); ?>" target="_blank" rel="noopener" class="th-button">
							<?php esc_html_e( 'Preview feed →', 'nexus' ); ?>
						</a>
						<button type="button" class="th-button" data-feed-copy data-copy-target="<?php echo esc_attr( $feed_url ); ?>">
							<?php esc_html_e( 'Copy URL', 'nexus' ); ?>
						</button>
						<button type="button" class="th-button" data-feed-validate="<?php echo esc_attr( $ch['id'] ); ?>">
							<?php esc_html_e( 'Validate', 'nexus' ); ?>
						</button>
					</div>
					<div data-feed-validate-result style="font-size:11px;color:var(--tx2);margin-top:6px"></div>
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
