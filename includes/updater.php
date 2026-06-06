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

const NEXUS_UPDATE_REPO            = 'TherumCs/Nexus';
const NEXUS_UPDATE_CACHE_KEY       = 'nexus_latest_release';
const NEXUS_UPDATE_CACHE_KEY_ALL   = 'nexus_all_releases';
const NEXUS_UPDATE_CACHE_TTL       = 6 * HOUR_IN_SECONDS;
const NEXUS_UPDATE_USER_AGENT      = 'Nexus-WP-Updater';
const NEXUS_UPDATE_ZIP_MAX_BYTES   = 16 * 1024 * 1024; // 16 MB hard ceiling.
const NEXUS_UPDATE_BACKUP_KEEP_N   = 5;                // prune to this many local backups


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
//  ALL RELEASES (version history) — used for the rollback list
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Fetch up to 30 most recent releases from GitHub. Cached. Returns
 * the same normalized shape per item as nexus_fetch_latest_release().
 *
 * @return array|WP_Error
 */
function nexus_fetch_releases( bool $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( NEXUS_UPDATE_CACHE_KEY_ALL );
		if ( is_array( $cached ) ) return $cached;
	}

	$repo = nexus_update_repo();
	$url  = sprintf( 'https://api.github.com/repos/%s/releases?per_page=30', rawurlencode( $repo ) );

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
	if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
		$msg = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'GitHub returned HTTP ' . $code;
		return new WP_Error( 'github_http', $msg );
	}

	$out = [];
	foreach ( $body as $r ) {
		if ( ! is_array( $r ) ) continue;
		// Match the latest-release normalization so installers can use either.
		$asset_url = '';
		if ( ! empty( $r['assets'] ) && is_array( $r['assets'] ) ) {
			foreach ( $r['assets'] as $a ) {
				$name = $a['name'] ?? '';
				if ( substr( $name, -4 ) === '.zip' ) { $asset_url = $a['browser_download_url'] ?? ''; break; }
			}
		}
		$out[] = [
			'tag'          => (string) ( $r['tag_name']     ?? '' ),
			'name'         => (string) ( $r['name']         ?? '' ),
			'body'         => (string) ( $r['body']         ?? '' ),
			'html_url'     => (string) ( $r['html_url']     ?? '' ),
			'zipball_url'  => (string) ( $r['zipball_url']  ?? '' ),
			'asset_url'    => $asset_url,
			'published_at' => (string) ( $r['published_at'] ?? '' ),
			'prerelease'   => (bool)   ( $r['prerelease']   ?? false ),
		];
	}

	set_transient( NEXUS_UPDATE_CACHE_KEY_ALL, $out, NEXUS_UPDATE_CACHE_TTL );
	return $out;
}

/** Find a release by tag in the cached list (no extra network call). */
function nexus_release_by_tag( string $tag ): ?array {
	$all = nexus_fetch_releases( false );
	if ( is_wp_error( $all ) ) return null;
	foreach ( $all as $r ) {
		if ( ( $r['tag'] ?? '' ) === $tag ) return $r;
	}
	return null;
}


// ═════════════════════════════════════════════════════════════════════════════
//  LOCAL BACKUPS — snapshot the current plugin before every install
//
//  Lives in wp-content/uploads/nexus-backups/. Each backup is a single
//  .zip containing the entire nexus/ directory at the moment-of-snapshot.
//  Rollback path: pick a backup, install it via the same Plugin_Upgrader
//  flow. Always works even when GitHub is unreachable or the target
//  version has been pulled from the repo.
// ═════════════════════════════════════════════════════════════════════════════

function nexus_backups_dir(): string {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'nexus-backups';
	if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
	// Block direct browsing.
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	return $dir;
}

/**
 * Create a zip backup of the live plugin directory. Returns the
 * backup file path or WP_Error.
 */
function nexus_create_backup( string $reason = '' ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'no_zip', 'PHP ZipArchive extension is required to take a backup.' );
	}

	$src = rtrim( NEXUS_DIR, '/' );
	if ( ! is_dir( $src ) ) return new WP_Error( 'no_src', 'Plugin source dir not found: ' . $src );

	$dir   = nexus_backups_dir();
	$slug  = sanitize_file_name( 'v' . NEXUS_VERSION . ( $reason ? '-' . $reason : '' ) );
	$file  = $dir . '/' . $slug . '-' . time() . '.zip';

	$zip = new ZipArchive();
	if ( $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
		return new WP_Error( 'zip_open', 'Could not open backup zip for writing.' );
	}

	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	$base = dirname( $src );
	foreach ( $iter as $f ) {
		if ( ! $f->isFile() ) continue;
		$path     = $f->getPathname();
		$rel      = ltrim( str_replace( $base, '', $path ), '/\\' );
		// Skip backup files if any accidentally landed in the plugin dir.
		if ( strpos( $rel, '.git/' ) !== false || strpos( $rel, '.DS_Store' ) !== false ) continue;
		$zip->addFile( $path, $rel );
	}
	$zip->close();

	nexus_prune_backups();
	return $file;
}

function nexus_list_backups(): array {
	$dir = nexus_backups_dir();
	$files = glob( $dir . '/*.zip' ) ?: [];
	usort( $files, fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );
	return array_map( function( $f ) {
		return [
			'file'    => basename( $f ),
			'path'    => $f,
			'size'    => filesize( $f ),
			'created' => filemtime( $f ),
		];
	}, $files );
}

function nexus_prune_backups(): void {
	$all = nexus_list_backups();
	$old = array_slice( $all, NEXUS_UPDATE_BACKUP_KEEP_N );
	foreach ( $old as $b ) {
		// Use the (non-silenced) deleter so failures land in the audit log
		// rather than disappearing into the ether.
		nexus_delete_backup( $b['file'] );
	}
}

function nexus_delete_backup( string $filename ): bool {
	$filename = basename( $filename ); // strip any path traversal
	$path     = nexus_backups_dir() . '/' . $filename;
	if ( ! is_file( $path ) ) return false;
	$ok = unlink( $path );
	if ( ! $ok ) {
		$err = error_get_last()['message'] ?? 'unlink returned false';
		error_log( 'Nexus: failed to delete backup ' . $filename . ' — ' . $err );
		if ( function_exists( 'nexus_audit_log' ) ) {
			nexus_audit_log( 'backup.delete_failed', $filename . ' — ' . $err );
		}
	}
	return $ok;
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

	// Auto-snapshot the current install before swapping in the new one.
	nexus_create_backup( 'pre-' . sanitize_file_name( $release['tag'] ?: 'latest' ) );

	$result = nexus_install_from_package( $package );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	delete_transient( NEXUS_UPDATE_CACHE_KEY );
	delete_transient( NEXUS_UPDATE_CACHE_KEY_ALL );
	wp_send_json_success( [
		'message' => sprintf( 'Installed %s.', $release['tag'] ?: 'latest release' ),
		'tag'     => $release['tag'],
	] );
} );

/**
 * Install any specific release by tag (rollback or roll-forward).
 * POST: tag=v1.5.0
 */
add_action( 'wp_ajax_nexus_update_install_version', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$tag = sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) );
	if ( ! $tag ) wp_send_json_error( [ 'message' => 'Missing tag.' ] );

	$release = nexus_release_by_tag( $tag );
	if ( ! $release ) {
		// Cache miss — force re-fetch and try again.
		$all = nexus_fetch_releases( true );
		if ( is_wp_error( $all ) ) wp_send_json_error( [ 'message' => $all->get_error_message() ] );
		$release = nexus_release_by_tag( $tag );
	}
	if ( ! $release ) wp_send_json_error( [ 'message' => 'Release ' . $tag . ' not found in repository.' ] );

	$package = $release['asset_url'] ?: $release['zipball_url'];
	if ( ! $package ) wp_send_json_error( [ 'message' => 'Release ' . $tag . ' has no downloadable package.' ] );

	nexus_create_backup( 'pre-' . sanitize_file_name( $tag ) );

	$result = nexus_install_from_package( $package );
	if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );

	delete_transient( NEXUS_UPDATE_CACHE_KEY );
	delete_transient( NEXUS_UPDATE_CACHE_KEY_ALL );
	wp_send_json_success( [
		'message' => 'Installed ' . $tag . '.',
		'tag'     => $tag,
	] );
} );

/**
 * Restore a local backup (rollback to whatever was running at the
 * moment that backup was taken).
 * POST: file=<backup-filename.zip>
 */
add_action( 'wp_ajax_nexus_update_restore_backup', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$file = basename( sanitize_text_field( wp_unslash( $_POST['file'] ?? '' ) ) );
	if ( ! $file ) wp_send_json_error( [ 'message' => 'Missing backup file.' ] );

	$path = nexus_backups_dir() . '/' . $file;
	if ( ! is_file( $path ) ) wp_send_json_error( [ 'message' => 'Backup file not found.' ] );

	// Snapshot the *current* state before restoring — so rollback is reversible.
	nexus_create_backup( 'pre-restore-' . sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) ) );

	$result = nexus_install_from_package( $path );
	if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );

	wp_send_json_success( [ 'message' => 'Restored from ' . $file . '.' ] );
} );

/**
 * Delete a local backup file.
 * POST: file=<backup-filename.zip>
 */
add_action( 'wp_ajax_nexus_update_delete_backup', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$file = basename( sanitize_text_field( wp_unslash( $_POST['file'] ?? '' ) ) );
	if ( ! $file ) wp_send_json_error( [ 'message' => 'Missing backup file.' ] );

	if ( nexus_delete_backup( $file ) ) {
		wp_send_json_success( [ 'message' => 'Deleted ' . $file . '.' ] );
	}
	wp_send_json_error( [ 'message' => 'Could not delete ' . $file . '.' ] );
} );

/**
 * Manual backup of the current install (without doing an upgrade).
 * Useful for "take a snapshot before I muck around."
 */
add_action( 'wp_ajax_nexus_update_create_backup', function() {
	if ( ! current_user_can( 'update_plugins' ) ) wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
	check_ajax_referer( 'nexus_connector', 'nonce' );

	$result = nexus_create_backup( 'manual' );
	if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	wp_send_json_success( [ 'message' => 'Backup created.', 'file' => basename( $result ) ] );
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

	// Auto-snapshot the current install before swapping in the uploaded zip.
	nexus_create_backup( 'pre-upload' );

	$result = nexus_install_from_package( $moved['file'] );
	@unlink( $moved['file'] );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	delete_transient( NEXUS_UPDATE_CACHE_KEY );
	delete_transient( NEXUS_UPDATE_CACHE_KEY_ALL );
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

	<?php // ─── Version history (rollback to any GitHub release) ─────────────
	      $all_releases = nexus_fetch_releases( false );
	      $installed_v  = NEXUS_VERSION;
	?>
	<section class="nexus-update-card nexus-update-history">
		<header class="nexus-update-card-head">
			<div>
				<div class="nexus-update-card-eyebrow"><?php esc_html_e( 'Rollback', 'nexus' ); ?></div>
				<h3 class="nexus-update-card-title"><?php esc_html_e( 'Version history', 'nexus' ); ?></h3>
			</div>
			<span class="nexus-update-repo"><?php
				printf(
					/* translators: %s = repo slug */
					esc_html__( 'Last 30 releases from %s', 'nexus' ),
					esc_html( $repo )
				);
			?></span>
		</header>

		<?php if ( is_wp_error( $all_releases ) ): ?>
			<div class="nexus-update-alert is-err">
				<strong><?php esc_html_e( 'Could not load release list.', 'nexus' ); ?></strong>
				<span><?php echo esc_html( $all_releases->get_error_message() ); ?></span>
			</div>
		<?php elseif ( empty( $all_releases ) ): ?>
			<p class="nexus-update-help"><?php esc_html_e( 'No releases found in this repository.', 'nexus' ); ?></p>
		<?php else: ?>
			<table class="nexus-update-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Version', 'nexus' ); ?></th>
						<th><?php esc_html_e( 'Published', 'nexus' ); ?></th>
						<th><?php esc_html_e( 'Title', 'nexus' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_releases as $r ):
						$tag_clean = ltrim( $r['tag'], 'vV' );
						$is_installed = $tag_clean === $installed_v;
						$is_older     = $tag_clean && version_compare( $tag_clean, $installed_v, '<' );
					?>
					<tr<?php if ( $is_installed ): ?> class="is-installed"<?php endif; ?>>
						<td>
							<code><?php echo esc_html( $r['tag'] ); ?></code>
							<?php if ( $r['prerelease'] ): ?><span class="nexus-update-pre">pre-release</span><?php endif; ?>
							<?php if ( $is_installed ): ?><span class="nexus-update-current"><?php esc_html_e( 'installed', 'nexus' ); ?></span><?php endif; ?>
						</td>
						<td><?php echo $r['published_at'] ? esc_html( mysql2date( 'M j, Y', $r['published_at'] ) ) : '—'; ?></td>
						<td><?php echo esc_html( $r['name'] ?: '—' ); ?></td>
						<td class="nexus-update-table-actions">
							<?php if ( $r['html_url'] ): ?>
								<a href="<?php echo esc_url( $r['html_url'] ); ?>" target="_blank" rel="noopener" class="nexus-update-link"><?php esc_html_e( 'notes ↗', 'nexus' ); ?></a>
							<?php endif; ?>
							<?php if ( ! $is_installed ): ?>
								<button type="button" class="th-button"
									data-nexus-update-install-version="<?php echo esc_attr( $r['tag'] ); ?>">
									<?php echo $is_older ? esc_html__( 'Roll back', 'nexus' ) : esc_html__( 'Install', 'nexus' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<?php // ─── Local backups (auto-snapshotted before each install) ────────
	      $backups = nexus_list_backups();
	?>
	<section class="nexus-update-card nexus-update-backups">
		<header class="nexus-update-card-head">
			<div>
				<div class="nexus-update-card-eyebrow"><?php esc_html_e( 'Safety net', 'nexus' ); ?></div>
				<h3 class="nexus-update-card-title"><?php esc_html_e( 'Local backups', 'nexus' ); ?></h3>
			</div>
			<button type="button" class="th-button" data-nexus-backup-create>
				<?php esc_html_e( '＋ Take snapshot now', 'nexus' ); ?>
			</button>
		</header>
		<p class="nexus-update-help">
			<?php
				printf(
					/* translators: %d = number of backups kept */
					esc_html__( "Snapshots of the running plugin are taken automatically before every install. The %d most recent are kept; older ones are pruned. Restore from one of these when GitHub is down or the target version isn't published.", 'nexus' ),
					(int) NEXUS_UPDATE_BACKUP_KEEP_N
				);
			?>
		</p>

		<?php if ( empty( $backups ) ): ?>
			<p class="nexus-update-help" style="opacity:.6"><?php esc_html_e( 'No backups yet — one will be created automatically the next time you install an update.', 'nexus' ); ?></p>
		<?php else: ?>
			<table class="nexus-update-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Backup', 'nexus' ); ?></th>
						<th><?php esc_html_e( 'Created', 'nexus' ); ?></th>
						<th><?php esc_html_e( 'Size', 'nexus' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups as $b ): ?>
					<tr>
						<td><code><?php echo esc_html( $b['file'] ); ?></code></td>
						<td><?php echo esc_html( human_time_diff( $b['created'] ) ); ?> ago</td>
						<td><?php echo esc_html( size_format( $b['size'] ) ); ?></td>
						<td class="nexus-update-table-actions">
							<button type="button" class="th-button" data-nexus-backup-restore="<?php echo esc_attr( $b['file'] ); ?>">
								<?php esc_html_e( 'Restore', 'nexus' ); ?>
							</button>
							<button type="button" class="th-button" style="color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)" data-nexus-backup-delete="<?php echo esc_attr( $b['file'] ); ?>">
								<?php esc_html_e( 'Delete', 'nexus' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php
}
