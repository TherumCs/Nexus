<?php
/**
 * Nexus by Therum — self-update from a GitHub release or an uploaded zip.
 *
 * The Updates tab lets an admin pull the latest release from the configured
 * GitHub repo or hand-upload a plugin zip. Both paths route through WP's
 * Plugin_Upgrader with overwrite_package=true so the existing install is
 * swapped atomically.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_UPDATE_REPO          = 'TherumCs/Nexus';
const NEXUS_UPDATE_CACHE_KEY     = 'nexus_latest_release';
const NEXUS_UPDATE_CACHE_TTL     = 6 * HOUR_IN_SECONDS;
const NEXUS_UPDATE_USER_AGENT    = 'Nexus-WP-Updater';
const NEXUS_UPDATE_ZIP_MAX_BYTES = 16 * 1024 * 1024; // 16 MB hard ceiling.


// ═════════════════════════════════════════════════════════════════════════════
//  GITHUB RELEASE LOOKUP
// ═════════════════════════════════════════════════════════════════════════════

function nexus_update_repo(): string {
	return (string) apply_filters( 'nexus_update_repo', NEXUS_UPDATE_REPO );
}

/**
 * Fetch the latest release from the GitHub API. Cached in a transient for
 * NEXUS_UPDATE_CACHE_TTL — pass $force=true to bypass.
 *
 * @return array|WP_Error { tag, name, body, html_url, zipball_url, asset_url, published_at }
 */
function nexus_fetch_latest_release( bool $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( NEXUS_UPDATE_CACHE_KEY );
		if ( is_array( $cached ) ) return $cached;
	}

	$repo = nexus_update_repo();
	$url  = sprintf( 'https://api.github.com/repos/%s/releases/latest', rawurlencode( $repo ) );

	$res = wp_remote_get( $url, [
		'timeout' => 15,
		'headers' => [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => NEXUS_UPDATE_USER_AGENT,
		],
	] );

	if ( is_wp_error( $res ) ) return $res;

	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( $code === 404 ) {
		return new WP_Error( 'no_release', sprintf( 'No releases found for %s.', $repo ) );
	}
	if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
		$msg = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'GitHub returned HTTP ' . $code;
		return new WP_Error( 'github_http', $msg );
	}

	$asset_url = '';
	if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
		foreach ( $body['assets'] as $a ) {
			$name = $a['name'] ?? '';
			if ( substr( $name, -4 ) === '.zip' ) { $asset_url = $a['browser_download_url'] ?? ''; break; }
		}
	}

	$release = [
		'tag'          => (string) ( $body['tag_name'] ?? '' ),
		'name'         => (string) ( $body['name'] ?? '' ),
		'body'         => (string) ( $body['body'] ?? '' ),
		'html_url'     => (string) ( $body['html_url'] ?? '' ),
		'zipball_url'  => (string) ( $body['zipball_url'] ?? '' ),
		'asset_url'    => $asset_url,
		'published_at' => (string) ( $body['published_at'] ?? '' ),
	];

	set_transient( NEXUS_UPDATE_CACHE_KEY, $release, NEXUS_UPDATE_CACHE_TTL );
	return $release;
}

/**
 * Strip leading 'v' / 'V' and compare with version_compare. Returns true when
 * the release is strictly newer than the installed NEXUS_VERSION.
 */
function nexus_release_is_newer( array $release ): bool {
	$tag = ltrim( (string) ( $release['tag'] ?? '' ), 'vV' );
	if ( $tag === '' ) return false;
	return version_compare( $tag, NEXUS_VERSION, '>' );
}


// ═════════════════════════════════════════════════════════════════════════════
//  INSTALLER — wraps Plugin_Upgrader for both flows
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Lazy-load WP's upgrader classes. Wrapped because they only exist in
 * admin context — `wp-admin/includes/class-wp-upgrader.php` and friends
 * are NOT autoloaded on regular requests. Anything that extends or
 * `new`s these classes must call this function first.
 */
function nexus_load_upgrader_classes(): void {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
}

/**
 * Run Plugin_Upgrader::install on a local or remote zip path with
 * overwrite_package=true, so the existing Nexus install is replaced.
 *
 * @return true|WP_Error
 */
function nexus_install_from_package( string $package ) {
	nexus_load_upgrader_classes();
	// Skin subclass lives in its own file because `extends WP_Upgrader_Skin`
	// would fatal if parsed before the parent class file is loaded — and
	// that parent file is admin-only, never autoloaded on front-end hits.
	// 1.2.0 had this class declared at file scope, which broke every cold
	// page load. Require it ONLY after nexus_load_upgrader_classes() ran.
	require_once NEXUS_DIR . 'includes/class-silent-upgrader-skin.php';

	$skin     = new Nexus_Silent_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );

	// Filter ensures the unpacked folder lands as `nexus/` regardless of what
	// the archive root is named (GitHub tarball roots are `repo-sha/`).
	$rename = function( $source, $remote_source, $upgrader_instance ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) return $source;

		$target = trailingslashit( dirname( $source ) ) . 'nexus/';

		// Look for nexus.php — if it's directly in $source, just rename.
		if ( $wp_filesystem->exists( trailingslashit( $source ) . 'nexus.php' ) ) {
			if ( $source === $target ) return $source;
			if ( $wp_filesystem->exists( $target ) ) $wp_filesystem->delete( $target, true );
			return $wp_filesystem->move( $source, $target ) ? $target : new WP_Error( 'nexus_rename_failed', 'Could not normalize plugin folder name.' );
		}

		// Otherwise look one level deep for the dir containing nexus.php.
		$entries = $wp_filesystem->dirlist( $source );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( ( $entry['type'] ?? '' ) !== 'd' ) continue;
				$candidate = trailingslashit( $source ) . $entry['name'] . '/';
				if ( $wp_filesystem->exists( $candidate . 'nexus.php' ) ) {
					if ( $wp_filesystem->exists( $target ) ) $wp_filesystem->delete( $target, true );
					return $wp_filesystem->move( $candidate, $target ) ? $target : new WP_Error( 'nexus_rename_failed', 'Could not normalize plugin folder name.' );
				}
			}
		}

		return new WP_Error( 'nexus_not_a_plugin', 'Uploaded archive does not contain nexus.php.' );
	};

	add_filter( 'upgrader_source_selection', $rename, 10, 3 );
	$result = $upgrader->install( $package, [ 'overwrite_package' => true ] );
	remove_filter( 'upgrader_source_selection', $rename, 10 );

	if ( is_wp_error( $result ) ) return $result;
	if ( $result === false ) {
		$msgs = $skin->messages ? implode( ' · ', $skin->messages ) : 'Install failed.';
		return new WP_Error( 'install_failed', $msgs );
	}
	return true;
}


// ═════════════════════════════════════════════════════════════════════════════
//  AJAX — check / install from GitHub / install from zip
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_nexus_update_check', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$release = nexus_fetch_latest_release( true );
	if ( is_wp_error( $release ) ) {
		wp_send_json_error( [ 'message' => $release->get_error_message() ] );
	}

	wp_send_json_success( [
		'release'   => $release,
		'installed' => NEXUS_VERSION,
		'is_newer'  => nexus_release_is_newer( $release ),
	] );
} );

add_action( 'wp_ajax_nexus_update_install_github', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$release = nexus_fetch_latest_release( true );
	if ( is_wp_error( $release ) ) {
		wp_send_json_error( [ 'message' => $release->get_error_message() ] );
	}

	$package = $release['asset_url'] ?: $release['zipball_url'];
	if ( ! $package ) {
		wp_send_json_error( [ 'message' => 'Release has no downloadable package.' ] );
	}

	$result = nexus_install_from_package( $package );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	delete_transient( NEXUS_UPDATE_CACHE_KEY );
	wp_send_json_success( [
		'message' => sprintf( 'Installed %s.', $release['tag'] ?: 'latest release' ),
		'tag'     => $release['tag'],
	] );
} );

add_action( 'wp_ajax_nexus_update_install_zip', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	if ( empty( $_FILES['package'] ) || ! is_array( $_FILES['package'] ) ) {
		wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
	}
	$file = $_FILES['package'];

	if ( ! empty( $file['error'] ) ) {
		wp_send_json_error( [ 'message' => 'Upload error code ' . (int) $file['error'] . '.' ] );
	}
	if ( (int) ( $file['size'] ?? 0 ) > NEXUS_UPDATE_ZIP_MAX_BYTES ) {
		wp_send_json_error( [ 'message' => 'Zip is larger than the 16 MB limit.' ] );
	}
	$name = strtolower( (string) ( $file['name'] ?? '' ) );
	if ( substr( $name, -4 ) !== '.zip' ) {
		wp_send_json_error( [ 'message' => 'Only .zip uploads are accepted.' ] );
	}

	// Park the upload in the WP uploads dir so Plugin_Upgrader can read it.
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$overrides = [ 'test_form' => false, 'mimes' => [ 'zip' => 'application/zip' ] ];
	$moved = wp_handle_upload( $file, $overrides );
	if ( ! is_array( $moved ) || empty( $moved['file'] ) ) {
		wp_send_json_error( [ 'message' => $moved['error'] ?? 'Could not store uploaded file.' ] );
	}

	$result = nexus_install_from_package( $moved['file'] );
	@unlink( $moved['file'] );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	delete_transient( NEXUS_UPDATE_CACHE_KEY );
	wp_send_json_success( [ 'message' => 'Installed from uploaded zip.' ] );
} );


// ═════════════════════════════════════════════════════════════════════════════
//  UPDATES TAB RENDERER
// ═════════════════════════════════════════════════════════════════════════════

function nexus_render_updates_tab( string $tab_id, array $tab ): void {
	$repo    = nexus_update_repo();
	$release = nexus_fetch_latest_release( false );
	$err     = is_wp_error( $release ) ? $release->get_error_message() : null;
	$newer   = ! $err && is_array( $release ) ? nexus_release_is_newer( $release ) : false;
	$max_mb  = (int) ( NEXUS_UPDATE_ZIP_MAX_BYTES / 1024 / 1024 );

	nexus_page_head(
		__( 'Updates', 'nexus' ),
		__( "Pull the latest Nexus release from GitHub, or hand-upload a plugin zip. Both paths replace this install atomically using WordPress's built-in upgrader.", 'nexus' )
	);
	?>

	<div class="nexus-update-grid">

		<section class="nexus-update-card">
			<header class="nexus-update-card-head">
				<div>
					<div class="nexus-update-card-eyebrow"><?php esc_html_e( 'Installed', 'nexus' ); ?></div>
					<h3 class="nexus-update-card-title">v<?php echo esc_html( NEXUS_VERSION ); ?></h3>
				</div>
				<a class="nexus-update-repo" href="<?php echo esc_url( 'https://github.com/' . $repo ); ?>" target="_blank" rel="noopener">
					<?php echo esc_html( $repo ); ?> ↗
				</a>
			</header>

			<?php if ( $err ): ?>
				<div class="nexus-update-alert is-err">
					<strong><?php esc_html_e( 'Could not reach GitHub.', 'nexus' ); ?></strong>
					<span><?php echo esc_html( $err ); ?></span>
				</div>
			<?php elseif ( is_array( $release ) ): ?>
				<div class="nexus-update-latest">
					<div class="nexus-update-row">
						<span class="nexus-update-label"><?php esc_html_e( 'Latest release', 'nexus' ); ?></span>
						<span class="nexus-update-tag <?php echo $newer ? 'is-newer' : ''; ?>">
							<?php echo esc_html( $release['tag'] ?: '—' ); ?>
							<?php if ( $newer ): ?><em><?php esc_html_e( 'Update available', 'nexus' ); ?></em><?php endif; ?>
						</span>
					</div>
					<?php if ( ! empty( $release['name'] ) && $release['name'] !== $release['tag'] ): ?>
					<div class="nexus-update-row">
						<span class="nexus-update-label"><?php esc_html_e( 'Title', 'nexus' ); ?></span>
						<span><?php echo esc_html( $release['name'] ); ?></span>
					</div>
					<?php endif; ?>
					<?php if ( ! empty( $release['published_at'] ) ): ?>
					<div class="nexus-update-row">
						<span class="nexus-update-label"><?php esc_html_e( 'Published', 'nexus' ); ?></span>
						<span><?php echo esc_html( mysql2date( 'M j, Y', $release['published_at'] ) ); ?></span>
					</div>
					<?php endif; ?>
					<?php if ( ! empty( $release['body'] ) ): ?>
					<details class="nexus-update-notes">
						<summary><?php esc_html_e( 'Release notes', 'nexus' ); ?></summary>
						<pre><?php echo esc_html( $release['body'] ); ?></pre>
					</details>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="nexus-update-actions">
				<button type="button" class="th-button" data-nexus-update-check>
					<?php esc_html_e( 'Check for updates', 'nexus' ); ?>
				</button>
				<button
					type="button"
					class="th-button th-button-primary"
					data-nexus-update-github
					<?php disabled( ! is_array( $release ) || empty( $release['zipball_url'] ) ); ?>>
					<?php
						echo $newer
							? esc_html( sprintf( __( 'Install %s', 'nexus' ), $release['tag'] ) )
							: esc_html__( 'Reinstall latest', 'nexus' );
					?>
				</button>
				<span class="nexus-update-result" data-nexus-update-result></span>
			</div>
		</section>

		<section class="nexus-update-card">
			<header class="nexus-update-card-head">
				<div>
					<div class="nexus-update-card-eyebrow"><?php esc_html_e( 'Manual', 'nexus' ); ?></div>
					<h3 class="nexus-update-card-title"><?php esc_html_e( 'Upload a zip', 'nexus' ); ?></h3>
				</div>
			</header>
			<p class="nexus-update-help">
				<?php
					printf(
						esc_html__( 'Drop a Nexus build (the zip must contain %s at its root or one level deep). Max %dMB.', 'nexus' ),
						'<code>nexus.php</code>',
						$max_mb
					);
				?>
			</p>
			<form class="nexus-update-upload" data-nexus-update-zip enctype="multipart/form-data">
				<label class="nexus-update-drop">
					<input type="file" name="package" accept=".zip,application/zip" required>
					<span data-nexus-zip-label><?php esc_html_e( 'Choose a .zip file…', 'nexus' ); ?></span>
				</label>
				<div class="nexus-update-actions">
					<button type="submit" class="th-button th-button-primary">
						<?php esc_html_e( 'Install from zip', 'nexus' ); ?>
					</button>
					<span class="nexus-update-result" data-nexus-zip-result></span>
				</div>
			</form>
		</section>

	</div>
	<?php
}
