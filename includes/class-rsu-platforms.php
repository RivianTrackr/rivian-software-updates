<?php
/**
 * Platform registry for vehicles and their generations.
 *
 * Vehicles (R1, R2, etc.) are the top-level grouping shown as tabs.
 * Generations (Gen 1, Gen 2, etc.) are nested within each vehicle
 * and used as pill badges on individual release note items.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Platforms {

	const OPTION_KEY = 'rsu_platforms';

	/**
	 * Built-in vehicles used as fallback when no custom config is saved.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'r1' => array(
				'label'       => 'R1',
				'description' => 'R1T & R1S',
				'sort'        => 10,
				'generations' => array(
					'gen1' => array(
						'label'       => 'Gen 1',
						'description' => '2021–2024',
						'sort'        => 10,
					),
					'gen2' => array(
						'label'       => 'Gen 2',
						'description' => '2025+',
						'sort'        => 20,
					),
				),
			),
			'r2' => array(
				'label'       => 'R2',
				'description' => 'R2',
				'sort'        => 20,
				'generations' => array(
					'gen1' => array(
						'label'       => 'Gen 1',
						'description' => '2026+',
						'sort'        => 10,
					),
				),
			),
		);
	}

	/**
	 * Get all registered vehicles with their generations.
	 *
	 * @return array Keyed by vehicle slug, each with label, description, meta_key, sort, generations.
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, null );

		if ( is_array( $saved ) && ! empty( $saved ) && self::is_vehicle_format( $saved ) ) {
			$vehicles = $saved;
		} else {
			$vehicles = self::get_defaults();
		}

		// Ensure meta_key is set for each vehicle.
		foreach ( $vehicles as $slug => &$vehicle ) {
			$vehicle['meta_key'] = '_rsu_content_' . $slug;

			// Ensure generations exist.
			if ( empty( $vehicle['generations'] ) || ! is_array( $vehicle['generations'] ) ) {
				$vehicle['generations'] = array();
			}
		}
		unset( $vehicle );

		// Sort vehicles by sort value.
		uasort( $vehicles, function ( $a, $b ) {
			return ( isset( $a['sort'] ) ? $a['sort'] : 99 ) - ( isset( $b['sort'] ) ? $b['sort'] : 99 );
		} );

		// Sort generations within each vehicle.
		foreach ( $vehicles as &$vehicle ) {
			if ( ! empty( $vehicle['generations'] ) ) {
				uasort( $vehicle['generations'], function ( $a, $b ) {
					return ( isset( $a['sort'] ) ? $a['sort'] : 99 ) - ( isset( $b['sort'] ) ? $b['sort'] : 99 );
				} );
			}
		}
		unset( $vehicle );

		return apply_filters( 'rsu_platforms', $vehicles );
	}

	/**
	 * Check if saved data uses the new vehicle format (has generations key).
	 *
	 * @param array $data Saved platform data.
	 * @return bool
	 */
	private static function is_vehicle_format( $data ) {
		$first = reset( $data );
		return is_array( $first ) && isset( $first['generations'] );
	}

	/**
	 * Get the default vehicle slug for the frontend tab.
	 *
	 * @return string
	 */
	public static function get_default() {
		$default = RSU_Settings::get( 'default_tab', 'r1' );
		$all     = self::get_all();

		if ( ! isset( $all[ $default ] ) ) {
			$slugs   = array_keys( $all );
			$default = ! empty( $slugs ) ? $slugs[0] : 'r1';
		}

		return $default;
	}

	/**
	 * Get active vehicles for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Vehicle slugs.
	 */
	public static function get_active( $post_id ) {
		$vehicles = get_post_meta( $post_id, '_rsu_vehicles', true );

		// Backward compat: check old _rsu_platforms key.
		if ( ! is_array( $vehicles ) || empty( $vehicles ) ) {
			$vehicles = get_post_meta( $post_id, '_rsu_platforms', true );
		}

		if ( ! is_array( $vehicles ) ) {
			return array();
		}

		return array_values( array_intersect( $vehicles, array_keys( self::get_all() ) ) );
	}

	/**
	 * Get generation labels for a specific vehicle.
	 *
	 * @param string $vehicle_slug Vehicle slug.
	 * @return array Keyed by generation slug => label.
	 */
	public static function get_generations( $vehicle_slug ) {
		$all = self::get_all();

		if ( ! isset( $all[ $vehicle_slug ] ) || empty( $all[ $vehicle_slug ]['generations'] ) ) {
			return array();
		}

		$generations = array();
		foreach ( $all[ $vehicle_slug ]['generations'] as $slug => $gen ) {
			$generations[ $slug ] = $gen['label'];
		}

		return $generations;
	}

	/**
	 * Get all generation slugs across all vehicles.
	 *
	 * @return array
	 */
	public static function get_all_generation_slugs() {
		$slugs = array();
		foreach ( self::get_all() as $vehicle ) {
			if ( ! empty( $vehicle['generations'] ) ) {
				foreach ( array_keys( $vehicle['generations'] ) as $gen_slug ) {
					$slugs[] = $gen_slug;
				}
			}
		}
		return array_unique( $slugs );
	}
}
