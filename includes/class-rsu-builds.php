<?php
/**
 * Per-generation build numbers for an update post.
 *
 * Rivian ships one release train under several version strings — a
 * 2026.31 release reaches Gen 1 R1 as 2026.31.00, Gen 2 R1 as 2026.31.30
 * and R2 as 2026.31.40. The post title carries the release family
 * ("2026.31") and this meta carries the exact build each vehicle
 * generation received, keyed `[vehicle][generation] => build`.
 *
 * Builds used to live under a hotfix-only key; that key is still read so
 * nothing published before this existed changes on the front end.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Builds {

	/** Post meta holding `[vehicle][generation] => build`. */
	const META_KEY = '_rsu_builds';

	/** Pre-2.31 key, written only when the post was flagged as a hotfix. */
	const LEGACY_META_KEY = '_rsu_hotfix_builds';

	/**
	 * Read a post's builds, falling back to the legacy hotfix key.
	 *
	 * Only registered vehicles and their generations are returned, and
	 * blank slots are dropped, so callers can treat the result as clean.
	 *
	 * @param int $post_id Post ID.
	 * @return array `[vehicle][generation] => build`.
	 */
	public static function get( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = get_post_meta( $post_id, self::LEGACY_META_KEY, true );
		}

		return self::sanitize( $raw );
	}

	/**
	 * Persist a post's builds under the current key.
	 *
	 * The legacy key is removed once the new one is written so a post never
	 * carries two diverging copies. An empty set clears both.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $builds  `[vehicle][generation] => build`.
	 * @return void
	 */
	public static function save( $post_id, $builds ) {
		$builds = self::sanitize( $builds );

		if ( ! empty( $builds ) ) {
			update_post_meta( $post_id, self::META_KEY, $builds );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}

		delete_post_meta( $post_id, self::LEGACY_META_KEY );
	}

	/**
	 * Reduce raw input to registered vehicle/generation slots with non-empty values.
	 *
	 * @param mixed $raw Anything shaped like `[vehicle][generation] => build`.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$all    = RSU_Platforms::get_all();
		$builds = array();

		foreach ( $raw as $v_slug => $gens ) {
			$v_slug = sanitize_key( $v_slug );

			if ( ! isset( $all[ $v_slug ] ) || ! is_array( $gens ) ) {
				continue;
			}

			$valid_gens = ! empty( $all[ $v_slug ]['generations'] )
				? array_keys( $all[ $v_slug ]['generations'] )
				: array();

			foreach ( $gens as $g_slug => $build ) {
				$g_slug = sanitize_key( $g_slug );

				if ( ! in_array( $g_slug, $valid_gens, true ) ) {
					continue;
				}

				$build = sanitize_text_field( (string) $build );

				if ( '' !== $build ) {
					$builds[ $v_slug ][ $g_slug ] = $build;
				}
			}
		}

		return $builds;
	}

	/**
	 * Flatten builds into display rows in registry order.
	 *
	 * The vehicle name is prefixed only when more than one vehicle carries
	 * builds — otherwise the surrounding UI already names the vehicle. The
	 * generation label appears only for vehicles with several generations.
	 *
	 * @param array $builds `[vehicle][generation] => build`.
	 * @return array List of `array{ label, value, vehicle, generation }`.
	 */
	public static function describe( $builds ) {
		if ( ! is_array( $builds ) || empty( $builds ) ) {
			return array();
		}

		$all  = RSU_Platforms::get_all();
		$rows = array();

		$vehicles_with_builds = 0;
		foreach ( $all as $v_slug => $vehicle ) {
			if ( ! empty( $builds[ $v_slug ] ) && is_array( $builds[ $v_slug ] ) && array_filter( $builds[ $v_slug ], 'strlen' ) ) {
				$vehicles_with_builds++;
			}
		}
		$prefix_vehicle = $vehicles_with_builds > 1;

		foreach ( $all as $v_slug => $vehicle ) {
			if ( empty( $builds[ $v_slug ] ) || ! is_array( $builds[ $v_slug ] ) ) {
				continue;
			}

			$gen_defs = ! empty( $vehicle['generations'] ) ? $vehicle['generations'] : array();
			$multi    = count( $gen_defs ) > 1;

			// Walk generations in registry order so Gen 1 precedes Gen 2.
			foreach ( $gen_defs as $g_slug => $gen ) {
				if ( ! isset( $builds[ $v_slug ][ $g_slug ] ) ) {
					continue;
				}

				$build = trim( (string) $builds[ $v_slug ][ $g_slug ] );

				if ( '' === $build ) {
					continue;
				}

				$parts = array();
				if ( $prefix_vehicle ) {
					$parts[] = $vehicle['label'];
				}
				if ( $multi && ! empty( $gen['label'] ) ) {
					$parts[] = $gen['label'];
				}

				$rows[] = array(
					'label'      => implode( ' ', $parts ),
					'value'      => $build,
					'vehicle'    => $v_slug,
					'generation' => $g_slug,
				);
			}
		}

		return $rows;
	}

	/**
	 * Published hotfix posts that name the given post as their base release.
	 *
	 * @param int   $parent_id Base release post ID.
	 * @param array $statuses  Post statuses to include.
	 * @return WP_Post[] Oldest first.
	 */
	public static function get_patches( $parent_id, $statuses = array( 'publish' ) ) {
		$parent_id = (int) $parent_id;

		if ( ! $parent_id ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => $statuses,
				'numberposts'      => 20,
				'orderby'          => 'date',
				'order'            => 'ASC',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, one base release at a time.
					'relation' => 'AND',
					array(
						'key'   => '_rsu_is_hotfix',
						'value' => '1',
					),
					array(
						'key'   => '_rsu_parent_release',
						'value' => $parent_id,
					),
				),
			)
		);
	}
}
