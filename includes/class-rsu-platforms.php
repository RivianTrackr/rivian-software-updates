<?php
/**
 * Platform registry for vehicle generations.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Platforms {

	/**
	 * Get all registered platforms.
	 *
	 * @return array Keyed by slug.
	 */
	public static function get_all() {
		$platforms = array(
			'gen1' => array(
				'label'       => 'Gen 1 R1',
				'description' => 'R1T & R1S (2021\u20132024)',
				'meta_key'    => '_rsu_content_gen1',
				'sort'        => 10,
			),
			'gen2' => array(
				'label'       => 'Gen 2 R1',
				'description' => 'R1T & R1S (2025+)',
				'meta_key'    => '_rsu_content_gen2',
				'sort'        => 20,
				'default'     => true,
			),
			'r2' => array(
				'label'       => 'R2',
				'description' => 'R2 (2026+)',
				'meta_key'    => '_rsu_content_r2',
				'sort'        => 30,
			),
		);

		return apply_filters( 'rsu_platforms', $platforms );
	}

	/**
	 * Get the default platform slug.
	 *
	 * @return string
	 */
	public static function get_default() {
		return RSU_Settings::get( 'default_tab', 'gen2' );
	}

	/**
	 * Get active platforms for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Platform slugs.
	 */
	public static function get_active( $post_id ) {
		$platforms = get_post_meta( $post_id, '_rsu_platforms', true );
		if ( ! is_array( $platforms ) ) {
			return array();
		}
		return array_intersect( $platforms, array_keys( self::get_all() ) );
	}
}
