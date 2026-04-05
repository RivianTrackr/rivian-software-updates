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

// Remove all RSU meta from posts.
$meta_keys = array(
	'_rsu_is_update',
	'_rsu_version',
	'_rsu_platforms',
	'_rsu_content_gen1',
	'_rsu_content_gen2',
	'_rsu_content_r2',
	'_rsu_date_noticed',
	'_rsu_date_released',
);

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
}
