<?php
/**
 * Nexus by Therum — uninstall cleanup.
 *
 * Drops everything Nexus created when the user uninstalls the plugin:
 *   - Connector config options (nexus_connector_*)
 *   - Custom feed options (nexus_feed_*)
 *   - Custom connector registry (nexus_custom_connectors)
 *   - Schema-version trackers (nexus_audit_db_version, nexus_webhook_db_version)
 *   - Cached release lookups (nexus_latest_release, nexus_all_releases)
 *   - Audit + webhook custom tables
 *   - Local backup zips under wp-content/uploads/nexus-backups/
 *   - Any scheduled background jobs registered under our group
 *
 * Deactivating the plugin does NOT trigger uninstall — only "Delete"
 * from the Plugins admin screen does. Reactivating is non-destructive.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// ─── 1. Options ──────────────────────────────────────────────────────────

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nexus_connector_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nexus_feed_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN (
	'nexus_custom_connectors',
	'nexus_audit_db_version',
	'nexus_webhook_db_version',
	'nexus_latest_release',
	'nexus_all_releases'
)" );

// Transients
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nexus_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_nexus_%'" );

// ─── 2. Custom tables ────────────────────────────────────────────────────

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nexus_audit_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nexus_webhook_log" );

// ─── 3. Local backup files ───────────────────────────────────────────────

$uploads = wp_upload_dir();
if ( ! empty( $uploads['basedir'] ) ) {
	$backups_dir = trailingslashit( $uploads['basedir'] ) . 'nexus-backups';
	if ( is_dir( $backups_dir ) ) {
		// Recursive delete — careful, restricted to our subdir.
		$files = glob( $backups_dir . '/*' ) ?: [];
		foreach ( $files as $f ) {
			if ( is_file( $f ) ) @unlink( $f );
		}
		@rmdir( $backups_dir );
	}
}

// ─── 4. Scheduled background jobs ────────────────────────────────────────

// Action Scheduler (if present) — unschedule everything in the nexus group.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( [ 'nexus_audit_prune', 'nexus_webhook_prune', 'nexus_health_check_all', 'nexus_webhook_process' ] as $hook ) {
		as_unschedule_all_actions( $hook, [], 'nexus' );
	}
}
// wp_cron fallback.
foreach ( [ 'nexus_audit_prune', 'nexus_webhook_prune', 'nexus_health_check_all' ] as $hook ) {
	$ts = wp_next_scheduled( $hook );
	while ( $ts ) {
		wp_unschedule_event( $ts, $hook );
		$ts = wp_next_scheduled( $hook );
	}
}
