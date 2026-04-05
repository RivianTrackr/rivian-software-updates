<?php
/**
 * Custom post type registration for software updates.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Post_Type {

	/**
	 * Post type slug.
	 */
	const SLUG = 'rsu_update';

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the Software Update custom post type.
	 */
	public function register() {
		$labels = array(
			'name'                  => 'Software Updates',
			'singular_name'        => 'Software Update',
			'add_new'              => 'Add New',
			'add_new_item'         => 'Add New Software Update',
			'edit_item'            => 'Edit Software Update',
			'new_item'             => 'New Software Update',
			'view_item'            => 'View Software Update',
			'view_items'           => 'View Software Updates',
			'search_items'         => 'Search Software Updates',
			'not_found'            => 'No software updates found.',
			'not_found_in_trash'   => 'No software updates found in Trash.',
			'all_items'            => 'All Updates',
			'archives'             => 'Software Updates Archive',
			'menu_name'            => 'Software Updates',
		);

		$slug = RSU_Settings::get( 'archive_slug', '/software-updates/' );
		$slug = trim( $slug, '/' );
		if ( empty( $slug ) ) {
			$slug = 'software-updates';
		}

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'menu_position'      => 5,
			'menu_icon'          => 'dashicons-update',
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
			'rewrite'            => array(
				'slug'       => $slug,
				'with_front' => false,
			),
		);

		register_post_type( self::SLUG, $args );
	}
}
