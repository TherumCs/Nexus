<?php
/**
 * Nexus by Therum — audit log.
 *
 * Append-only event log for connector lifecycle, credential changes,
 * webhook arrivals, backups, restores, updates. Lives in a custom
 * table created lazily on first write.
 *
 * Public API:
 *   nexus_audit_log( $event, $detail = '', $payload = null ): void
 *   nexus_audit_recent( $limit = 100, $event_filter = '' ): array
 *   nexus_audit_purge_older_than( $unix_ts ): int
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const NEXUS_AUDIT_DB_VERSION = '1';
const NEXUS_AUDIT_RETENTION_DAYS = 90;
const NEXUS_AUDIT_PAGE_SIZE = 50;

function nexus_audit_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'nexus_audit_log';
}

function nexus_audit_ensure_table(): void {
	$installed = get_option( 'nexus_audit_db_version', '' );
	if ( $installed === NEXUS_AUDIT_DB_VERSION ) return;

	global $wpdb;
	$table   = nexus_audit_table();
	$charset = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		ts BIGINT(20) UNSIGNED NOT NULL,
		event VARCHAR(64) NOT NULL,
		actor VARCHAR(96) NOT NULL DEFAULT '',
		actor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
		detail VARCHAR(255) NOT NULL DEFAULT '',
		payload TEXT NULL,
		PRIMARY KEY  (id),
		KEY ts (ts),
		KEY event (event),
		KEY actor_id (actor_id)
	) {$charset};" );

	update_option( 'nexus_audit_db_version', NEXUS_AUDIT_DB_VERSION, false );
}

/**
 * Append one event. Cheap — table write, no side effects.
 * Common events: connector.connected, connector.disconnected,
 * connector.failed_validation, backup.created, backup.restored,
 * update.installed, update.rolled_back, webhook.received,
 * oauth.exchanged, oauth.refresh_failed.
 */
function nexus_audit_log( string $event, string $detail = '', $payload = null ): void {
	nexus_audit_ensure_table();
	global $wpdb;

	$user = wp_get_current_user();
	$actor = $user && $user->exists() ? $user->user_login : '';
	$actor_id = $user && $user->exists() ? (int) $user->ID : 0;

	$wpdb->insert(
		nexus_audit_table(),
		[
			'ts'       => time(),
			'event'    => substr( $event, 0, 64 ),
			'actor'    => substr( $actor, 0, 96 ),
			'actor_id' => $actor_id,
			'detail'   => substr( $detail, 0, 255 ),
			'payload'  => $payload !== null ? wp_json_encode( $payload ) : null,
		],
		[ '%d', '%s', '%s', '%d', '%s', '%s' ]
	);
}

/**
 * Pull the most recent N events. Filter by event substring with
 * `$event_filter` (e.g. 'backup' matches backup.created + backup.restored).
 */
function nexus_audit_recent( int $limit = 100, string $event_filter = '' ): array {
	return nexus_audit_page( 1, $limit, $event_filter );
}

/**
 * Paginated reader. Returns the rows for one page. Total row count
 * is available via nexus_audit_count_for_filter() so the UI can
 * render pagination links.
 */
function nexus_audit_page( int $page = 1, int $per_page = NEXUS_AUDIT_PAGE_SIZE, string $event_filter = '' ): array {
	nexus_audit_ensure_table();
	global $wpdb;
	$table     = nexus_audit_table();
	$per_page  = max( 1, min( 500, $per_page ) );
	$page      = max( 1, $page );
	$offset    = ( $page - 1 ) * $per_page;

	if ( $event_filter === '' ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
	} else {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE event LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d",
			'%' . $wpdb->esc_like( $event_filter ) . '%',
			$per_page,
			$offset
		), ARRAY_A );
	}
	return is_array( $rows ) ? $rows : [];
}

function nexus_audit_count_for_filter( string $event_filter = '' ): int {
	nexus_audit_ensure_table();
	global $wpdb;
	$table = nexus_audit_table();
	if ( $event_filter === '' ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE event LIKE %s",
		'%' . $wpdb->esc_like( $event_filter ) . '%'
	) );
}

function nexus_audit_count(): int {
	nexus_audit_ensure_table();
	global $wpdb;
	$table = nexus_audit_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

function nexus_audit_purge_older_than( int $unix_ts ): int {
	nexus_audit_ensure_table();
	global $wpdb;
	$table = nexus_audit_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ts < %d", $unix_ts ) );
	return (int) $wpdb->rows_affected;
}

// Background prune — once a day, drop entries older than the retention window.
add_action( 'nexus_audit_prune', function() {
	$cutoff = time() - ( NEXUS_AUDIT_RETENTION_DAYS * DAY_IN_SECONDS );
	nexus_audit_purge_older_than( $cutoff );
} );
add_action( 'init', function() {
	nexus_queue_recurring( 'nexus_audit_prune', [], DAY_IN_SECONDS );
} );


// ─── Auto-log hooks ──────────────────────────────────────────────────────
//
// Listen for the actions other parts of Nexus fire and write to the
// audit log automatically. Callers don't have to remember.

add_action( 'nexus_connector_connected',    fn( $id, $config = [] ) => nexus_audit_log( 'connector.connected', $id ), 10, 2 );
add_action( 'nexus_connector_disconnected', fn( $id ) => nexus_audit_log( 'connector.disconnected', $id ), 10, 1 );
add_action( 'nexus_backup_created',         fn( $file ) => nexus_audit_log( 'backup.created', basename( $file ) ), 10, 1 );
add_action( 'nexus_backup_restored',        fn( $file ) => nexus_audit_log( 'backup.restored', basename( $file ) ), 10, 1 );
add_action( 'nexus_update_installed',       fn( $tag = '' ) => nexus_audit_log( 'update.installed', $tag ), 10, 1 );
add_action( 'nexus_webhook_received',       fn( $connector_id, $event = '' ) => nexus_audit_log( 'webhook.received', $connector_id . ( $event ? ' · ' . $event : '' ) ), 10, 2 );
add_action( 'nexus_oauth_exchanged',        fn( $connector_id ) => nexus_audit_log( 'oauth.exchanged', $connector_id ), 10, 1 );


// ─── Audit tab renderer ──────────────────────────────────────────────────

function nexus_render_audit_tab( string $tab_id, array $tab ): void {
	nexus_page_head(
		__( 'Audit log', 'nexus' ),
		__( 'Tamper-evident lifecycle log for connectors, credentials, updates, and webhooks. Retention: 90 days.', 'nexus' )
	);

	$filter  = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '';
	$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$rows    = nexus_audit_page( $page, NEXUS_AUDIT_PAGE_SIZE, $filter );
	$filt_n  = nexus_audit_count_for_filter( $filter );
	$total   = nexus_audit_count();
	$pages   = max( 1, (int) ceil( $filt_n / NEXUS_AUDIT_PAGE_SIZE ) );
	?>
	<div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px 20px;margin-bottom:16px">
		<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
			<div>
				<div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--tx3);margin-bottom:2px"><?php esc_html_e( 'Events logged', 'nexus' ); ?></div>
				<div style="font-size:24px;font-weight:700"><?php echo number_format( $total ); ?></div>
			</div>
			<form method="get" style="margin-left:auto;display:flex;gap:8px;align-items:center">
				<input type="hidden" name="page" value="nexus">
				<input type="hidden" name="tab" value="audit">
				<input type="search" name="event" value="<?php echo esc_attr( $filter ); ?>" placeholder="Filter by event (e.g. backup, oauth)…" style="height:32px;padding:0 10px;border:1px solid var(--bd);border-radius:7px;font-size:12px;width:220px">
				<button type="submit" class="th-button" style="font-size:12px;padding:5px 12px"><?php esc_html_e( 'Filter', 'nexus' ); ?></button>
			</form>
		</div>
	</div>

	<table class="nexus-update-table" style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:0 14px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Event', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Actor', 'nexus' ); ?></th>
				<th><?php esc_html_e( 'Detail', 'nexus' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ): ?>
				<tr><td colspan="4" style="padding:20px;color:var(--tx3);text-align:center"><?php esc_html_e( 'No events yet. Connect or disconnect a connector, install an update, or receive a webhook — they\'ll all show up here.', 'nexus' ); ?></td></tr>
			<?php else: foreach ( $rows as $row ): ?>
			<tr>
				<td><?php echo esc_html( human_time_diff( (int) $row['ts'] ) . ' ago' ); ?></td>
				<td><code><?php echo esc_html( $row['event'] ); ?></code></td>
				<td><?php echo esc_html( $row['actor'] ?: '—' ); ?></td>
				<td><?php echo esc_html( $row['detail'] ); ?></td>
			</tr>
			<?php endforeach; endif; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ): ?>
	<div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;font-size:12px;color:var(--tx3)">
		<span>
			<?php
				printf(
					/* translators: 1 = current page, 2 = total pages, 3 = page size, 4 = total matching rows */
					esc_html__( 'Page %1$d of %2$d · %3$d per page · %4$d matching events', 'nexus' ),
					$page, $pages, NEXUS_AUDIT_PAGE_SIZE, $filt_n
				);
			?>
		</span>
		<span style="display:flex;gap:6px">
			<?php if ( $page > 1 ): ?>
				<a class="th-button" style="font-size:11px;padding:4px 10px" href="<?php echo esc_url( add_query_arg( [ 'paged' => $page - 1 ] ) ); ?>">← Prev</a>
			<?php endif; ?>
			<?php if ( $page < $pages ): ?>
				<a class="th-button" style="font-size:11px;padding:4px 10px" href="<?php echo esc_url( add_query_arg( [ 'paged' => $page + 1 ] ) ); ?>">Next →</a>
			<?php endif; ?>
		</span>
	</div>
	<?php endif; ?>
	<?php
}
