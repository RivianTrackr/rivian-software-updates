<?php
/**
 * Plugin Name: Rivian Software Updates
 * Description: Structured release notes for Rivian vehicle software updates with vehicle tabs, generation pills, and SEO schema.
 * Version: 2.4.9
 * Author: RivianTrackr
 * Text Domain: rivian-software-updates
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSU_VERSION', '2.4.9' );
define( 'RSU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RSU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RSU_PLUGIN_FILE', __FILE__ );

// Autoload RSU_ prefixed classes from includes/.
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'RSU_' ) !== 0 ) {
		return;
	}

	$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	$path = RSU_PLUGIN_DIR . 'includes/' . $file;

	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

// Initialize plugin.
add_action( 'plugins_loaded', 'rsu_init' );

function rsu_init() {
	if ( is_admin() ) {
		new RSU_Admin();
		new RSU_Settings();
	}

	new RSU_Frontend();
	new RSU_Schema();
}
