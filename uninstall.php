<?php
/**
 * Nexus by Therum — uninstall cleanup.
 * Removes every saved connector configuration option.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Delete every option prefixed with our connector key namespace.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nexus_connector_%'"
);
