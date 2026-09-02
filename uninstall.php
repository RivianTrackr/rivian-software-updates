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
	'_rsu_is_hotfix',
	'_rsu_parent_release',
	'_rsu_builds',
	'_rsu_hotfix_builds',
	// Legacy keys.
	'_rsu_version',
	'_rsu_platforms',
);

// Dynamic vehicle content/sections keys.
$dynamic = $wpdb->get_col(
	"SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
	 WHERE meta_key LIKE '_rsu_content_%'
	    OR meta_key LIKE '_rsu_sections_%'
	    OR meta_key LIKE '_rsu_notes_url_%'
	    OR meta_key LIKE '_rsu_notes_file_%'
	    OR meta_key LIKE '_rsu_notes_revisions_%'
	    OR meta_key LIKE '_rsu_notes_revised_%'"
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
delete_option( 'rsu_cache_last_purge' );
delete_option( 'rsu_rivian_session' );
delete_option( 'rsu_rivian_vehicle_map' );
delete_option( 'rsu_rivian_state' );
delete_transient( 'rsu_rivian_vehicles' );

// Remove cached release-notes documents and their directory.
$uploads = wp_upload_dir();
if ( empty( $uploads['error'] ) ) {
	$notes_dir = trailingslashit( $uploads['basedir'] ) . 'rsu-release-notes';
	if ( is_dir( $notes_dir ) ) {
		foreach ( (array) glob( $notes_dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		wp_delete_file( $notes_dir . '/.htaccess' );
		@rmdir( $notes_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort; a non-empty dir is left alone.
	}
}

// Drop the OTA poll event so it cannot fire after the plugin is gone.
$timestamp = wp_next_scheduled( 'rsu_rivian_poll' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'rsu_rivian_poll' );
}

// Remove the version-scoped widget HTML transients.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_rsu_latest_update_widget%'
	    OR option_name LIKE '_transient_timeout_rsu_latest_update_widget%'"
);
