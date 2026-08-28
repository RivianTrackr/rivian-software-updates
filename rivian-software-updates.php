<?php
/**
 * Plugin Name: Rivian Software Updates
 * Description: Structured release notes for Rivian vehicle software updates with vehicle tabs, generation pills, and SEO schema.
 * Version: 2.28.1
 * Author: RivianTrackr
 * Text Domain: rivian-software-updates
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RSU_VERSION', '2.28.1' );
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

// Stop polling Rivian when the plugin is switched off; RSU_Rivian_Poller
// reschedules itself on the next init if an account is still connected.
register_deactivation_hook( __FILE__, function () {
	require_once RSU_PLUGIN_DIR . 'includes/class-rsu-rivian-poller.php';
	RSU_Rivian_Poller::unschedule();
} );

function rsu_init() {
	if ( is_admin() ) {
		new RSU_Admin();
		new RSU_Settings();
		new RSU_Rivian_Admin();
	}

	new RSU_Cache();
	new RSU_Rivian_Poller();
	new RSU_Frontend();
	new RSU_Schema();
	new RSU_SEO();
	new RSU_Shortcode();

	// Register the sidebar widget.
	add_action( 'widgets_init', function () {
		register_widget( 'RSU_Widget' );
	} );
}
