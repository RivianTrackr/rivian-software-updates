<?php
/**
 * Platform registry for vehicle generations.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Platforms {

	const OPTION_KEY = 'rsu_platforms';

	/**
	 * Built-in platforms used as fallback when no custom platforms are saved.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'gen1' => array(
				'label'       => 'Gen 1 R1',
				'description' => 'R1T & R1S (2021–2024)',
				'sort'        => 10,
			),
			'gen2' => array(
				'label'       => 'Gen 2 R1',
				'description' => 'R1T & R1S (2025+)',
				'sort'        => 20,
			),
			'r2' => array(
				'label'       => 'R2',
				'description' => 'R2 (2026+)',
				'sort'        => 30,
			),
		);
	}

	/**
	 * Get all registered platforms.
	 *
	 * @return array Keyed by slug, each with label, description, meta_key, sort.
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, null );

		if ( is_array( $saved ) && ! empty( $saved ) ) {
			$platforms = $saved;
		} else {
			$platforms = self::get_defaults();
		}

		// Ensure meta_key is set for each platform and sort.
		foreach ( $platforms as $slug => &$platform ) {
			$platform['meta_key'] = '_rsu_content_' . $slug;
		}
		unset( $platform );

		// Sort by sort value.
		uasort( $platforms, function ( $a, $b ) {
			return ( isset( $a['sort'] ) ? $a['sort'] : 99 ) - ( isset( $b['sort'] ) ? $b['sort'] : 99 );
		} );

		return apply_filters( 'rsu_platforms', $platforms );
	}

	/**
	 * Get the default platform slug.
	 *
	 * @return string
	 */
	public static function get_default() {
		$default = RSU_Settings::get( 'default_tab', 'gen2' );
		$all     = self::get_all();

		// Ensure the default is a valid platform.
		if ( ! isset( $all[ $default ] ) ) {
			$slugs = array_keys( $all );
			$default = ! empty( $slugs ) ? $slugs[0] : 'gen2';
		}

		return $default;
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
