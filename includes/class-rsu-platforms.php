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
	 * Static cache for get_all() to avoid repeated option lookups and sorting.
	 *
	 * @var array|null
	 */
	private static $cache = null;

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
						// Platform segment in Rivian's release-notes paths.
						'platform_code' => 'r1x',
					),
					'gen2' => array(
						'label'       => 'Gen 2',
						'description' => '2025+',
						'sort'        => 20,
						'platform_code' => 'r1x_1_6',
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
		if ( null !== self::$cache ) {
			return self::$cache;
		}

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

		self::$cache = apply_filters( 'rsu_platforms', $vehicles );

		return self::$cache;
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
	 * Match a release-notes platform/model pair to a generation slug.
	 *
	 * Rivian ships a separate document per hardware platform and body style —
	 * "r1x" + "R1T" is a Gen 1 R1T, "r1x_1_6" + "R1S" a Gen 2 R1S. A generation
	 * claims documents by declaring a platform_code, and optionally a
	 * model_code when body styles are tracked as separate generations.
	 *
	 * The most specific match wins: a generation naming both platform and model
	 * beats one naming only the platform.
	 *
	 * @param string $vehicle_slug Vehicle to search within.
	 * @param string $platform     Platform segment from the document path.
	 * @param string $model        Model segment from the document path.
	 * @return string Generation slug, or '' when nothing claims it.
	 */
	public static function resolve_generation( $vehicle_slug, $platform, $model ) {
		$all = self::get_all();

		if ( empty( $all[ $vehicle_slug ]['generations'] ) ) {
			return '';
		}

		$platform = strtolower( trim( (string) $platform ) );
		$model    = strtolower( trim( (string) $model ) );

		$generations = $all[ $vehicle_slug ]['generations'];

		// A platform registry saved before these codes existed declares none of
		// them, which would silently leave every document untagged. Fall back to
		// the built-in codes for whichever generation slugs still exist.
		$declared = false;
		foreach ( $generations as $generation ) {
			if ( ! empty( $generation['platform_code'] ) || ! empty( $generation['model_code'] ) ) {
				$declared = true;
				break;
			}
		}

		if ( ! $declared ) {
			$defaults = self::get_defaults();

			if ( ! empty( $defaults[ $vehicle_slug ]['generations'] ) ) {
				foreach ( $defaults[ $vehicle_slug ]['generations'] as $slug => $default ) {
					if ( isset( $generations[ $slug ] ) && ! empty( $default['platform_code'] ) ) {
						$generations[ $slug ]['platform_code'] = $default['platform_code'];
					}
				}
			}
		}

		$best  = '';
		$score = 0;

		foreach ( $generations as $slug => $generation ) {
			$want_platform = isset( $generation['platform_code'] ) ? strtolower( trim( $generation['platform_code'] ) ) : '';
			$want_model    = isset( $generation['model_code'] ) ? strtolower( trim( $generation['model_code'] ) ) : '';

			if ( '' === $want_platform && '' === $want_model ) {
				continue;
			}

			// A declared code that does not match disqualifies the generation.
			if ( '' !== $want_platform && $want_platform !== $platform ) {
				continue;
			}

			if ( '' !== $want_model && $want_model !== $model ) {
				continue;
			}

			$candidate = ( '' !== $want_platform ? 1 : 0 ) + ( '' !== $want_model ? 2 : 0 );

			if ( $candidate > $score ) {
				$best  = $slug;
				$score = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Find which vehicle and generation a document's platform/model belongs to.
	 *
	 * @param string $platform Platform segment from the document path.
	 * @param string $model    Model segment from the document path.
	 * @return array|null array{ vehicle, generation } or null when unclaimed.
	 */
	public static function resolve_vehicle_generation( $platform, $model ) {
		$best = null;

		foreach ( array_keys( self::get_all() ) as $slug ) {
			$generation = self::resolve_generation( $slug, $platform, $model );

			if ( ! $generation ) {
				continue;
			}

			$generations = self::get_all();
			$config      = $generations[ $slug ]['generations'][ $generation ];
			$specificity = ( ! empty( $config['model_code'] ) ? 2 : 0 ) + ( ! empty( $config['platform_code'] ) ? 1 : 0 );

			if ( null === $best || $specificity > $best['score'] ) {
				$best = array(
					'vehicle'    => $slug,
					'generation' => $generation,
					'score'      => $specificity,
				);
			}
		}

		if ( null === $best ) {
			return null;
		}

		unset( $best['score'] );

		return $best;
	}

	/**
	 * Get all generation slugs across all vehicles.
	 *
	 * @return array
	 */
	private static $gen_slugs_cache = null;

	public static function get_all_generation_slugs() {
		if ( null !== self::$gen_slugs_cache ) {
			return self::$gen_slugs_cache;
		}

		$slugs = array();
		foreach ( self::get_all() as $vehicle ) {
			if ( ! empty( $vehicle['generations'] ) ) {
				foreach ( array_keys( $vehicle['generations'] ) as $gen_slug ) {
					$slugs[] = $gen_slug;
				}
			}
		}
		self::$gen_slugs_cache = array_unique( $slugs );
		return self::$gen_slugs_cache;
	}
}
