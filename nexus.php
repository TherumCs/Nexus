<?php
/**
 * Plugin Name:       Nexus by Therum
 * Plugin URI:        https://therum.studio/plugins/nexus
 * Description:       Connect CMS platforms, ecommerce stores, and third-party APIs from a single admin surface. Tile-grid UI per category with per-connector config and saved credentials.
 * Version:           2.5.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Therum Creative Studios
 * Author URI:        https://therum.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexus
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NEXUS_VERSION', '2.5.1' );
define( 'NEXUS_FILE', __FILE__ );
define( 'NEXUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEXUS_URL', plugin_dir_url( __FILE__ ) );

require_once NEXUS_DIR . 'includes/registry.php';
require_once NEXUS_DIR . 'includes/crypto.php';
require_once NEXUS_DIR . 'includes/queue.php';
require_once NEXUS_DIR . 'includes/audit.php';
require_once NEXUS_DIR . 'includes/webhooks.php';
require_once NEXUS_DIR . 'includes/vault.php';
require_once NEXUS_DIR . 'includes/validators.php';
require_once NEXUS_DIR . 'includes/oauth.php';
require_once NEXUS_DIR . 'includes/ajax.php';
require_once NEXUS_DIR . 'includes/admin-page.php';
require_once NEXUS_DIR . 'includes/tab-renderers.php';
require_once NEXUS_DIR . 'includes/google-taxonomy.php';
require_once NEXUS_DIR . 'includes/feeds.php';
require_once NEXUS_DIR . 'includes/updater.php';
