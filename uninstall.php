<?php
/**
 * Uninstall handler.
 *
 * @package Rivian_Software_Updates
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all RSU post meta.
$static_keys = array(
	'_rsu_is_update',
	'_rsu_vehicles',
	'_rsu_date_noticed',
	'_rsu_date_released',
	// Legacy keys.
	'_rsu_version',
	'_rsu_platforms',
);

// Dynamic vehicle content/sections keys.
$dynamic = $wpdb->get_col(
	"SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
	 WHERE meta_key LIKE '_rsu_content_%' OR meta_key LIKE '_rsu_sections_%'"
);

$meta_keys = array_merge( $static_keys, $dynamic );

if ( ! empty( $meta_keys ) ) {
	$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are generated above.
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
		$meta_keys
	) );
}

// Remove plugin options.
delete_option( 'rsu_settings' );
delete_option( 'rsu_platforms' );
