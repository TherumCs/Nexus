<?php
/**
 * Nexus by Therum — silent Plugin_Upgrader skin.
 *
 * Captures feedback/error messages instead of echoing them, so AJAX
 * upgrade responses stay clean JSON instead of mixing in upgrader
 * progress chrome.
 *
 * IMPORTANT: this file MUST only be required AFTER WP's upgrader
 * classes have been loaded (see nexus_load_upgrader_classes() in
 * includes/updater.php). `WP_Upgrader_Skin` lives in an admin-only
 * file that isn't autoloaded on regular requests — parsing
 * `extends WP_Upgrader_Skin` before that file is included fatals.
 *
 * Hence: this class lives in its own file, and updater.php
 * require_once's it lazily inside nexus_install_from_package(). Do
 * not include it from the plugin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
	// Defensive — should never happen if the caller obeyed the contract above.
	return;
}

if ( ! class_exists( 'Nexus_Silent_Upgrader_Skin' ) ) :

class Nexus_Silent_Upgrader_Skin extends WP_Upgrader_Skin {

	/** @var string[] */
	public array $messages = [];

	public function header() {}
	public function footer() {}

	public function feedback( $string, ...$args ) {
		if ( is_string( $string ) && $string !== '' ) {
			$this->messages[] = vsprintf( $string, $args );
		}
	}

	public function error( $errors ) {
		if ( is_wp_error( $errors ) ) {
			foreach ( $errors->get_error_messages() as $m ) $this->messages[] = $m;
		} elseif ( is_string( $errors ) && $errors !== '' ) {
			$this->messages[] = $errors;
		}
	}
}

endif;
