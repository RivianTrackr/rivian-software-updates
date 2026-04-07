<?php
/**
 * Migration tool for converting existing posts to use RSU meta fields.
 *
 * Handles:
 * - Fresh migration from Essential Blocks toggle content or plain HTML
 * - Converting legacy platform data (gen1/gen2/r2) to new vehicle model (r1/r2)
 * - Backfilling sections JSON from stored HTML
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Migration {

	/**
	 * Map old platform slugs to vehicle slugs.
	 */
	const LEGACY_MAP = array(
		'gen1' => 'r1',
		'gen2' => 'r1',
		'r2'   => 'r2',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_rsu_migrate_post', array( $this, 'ajax_migrate_post' ) );
		add_action( 'wp_ajax_rsu_scan_posts', array( $this, 'ajax_scan_posts' ) );
		add_action( 'wp_ajax_rsu_backfill_sections', array( $this, 'ajax_backfill_sections' ) );
		add_action( 'wp_ajax_rsu_convert_legacy', array( $this, 'ajax_convert_legacy' ) );
	}

	/**
	 * Enqueue settings CSS on migration page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_rsu-migration' !== $hook ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$css_file = RSU_PLUGIN_DIR . 'admin/css/rsu-settings' . $suffix . '.css';
		if ( ! file_exists( $css_file ) ) {
			$suffix = '';
		}

		wp_enqueue_style(
			'rsu-settings',
			RSU_PLUGIN_URL . 'admin/css/rsu-settings' . $suffix . '.css',
			array(),
			RSU_VERSION
		);
	}

	/**
	 * Add migration page under Tools.
	 */
	public function add_menu_page() {
		add_management_page(
			'RSU Migration',
			'RSU Migration',
			'manage_options',
			'rsu-migration',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the migration admin page.
	 */
	public function render_page() {
		include RSU_PLUGIN_DIR . 'admin/views/migration-page.php';
	}

	/**
	 * AJAX: Scan posts and return candidates.
	 */
	public function ajax_scan_posts() {
		check_ajax_referer( 'rsu_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );

		$results = array();
		foreach ( $posts as $post_id ) {
			$post        = get_post( $post_id );
			$is_update   = get_post_meta( $post_id, '_rsu_is_update', true );
			$has_toggle  = $this->detect_toggle_block( $post->post_content );
			$has_legacy  = $this->has_legacy_data( $post_id );
			$has_vehicles = ! empty( get_post_meta( $post_id, '_rsu_vehicles', true ) );

			$results[] = array(
				'id'           => $post_id,
				'title'        => get_the_title( $post_id ),
				'date'         => get_the_date( 'Y-m-d', $post_id ),
				'url'          => get_permalink( $post_id ),
				'migrated'     => ! empty( $is_update ),
				'has_toggle'   => $has_toggle,
				'has_legacy'   => $has_legacy,
				'has_vehicles' => $has_vehicles,
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: Migrate a single post (fresh migration from post content).
	 */
	public function ajax_migrate_post() {
		check_ajax_referer( 'rsu_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$vehicles = isset( $_POST['vehicles'] ) && is_array( $_POST['vehicles'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['vehicles'] ) )
			: array( 'r1' );

		$date_noticed = isset( $_POST['date_noticed'] )
			? sanitize_text_field( wp_unslash( $_POST['date_noticed'] ) )
			: '';

		$date_released = isset( $_POST['date_released'] )
			? sanitize_text_field( wp_unslash( $_POST['date_released'] ) )
			: '';

		$post    = get_post( $post_id );
		$content = $post->post_content;

		$all_vehicles = RSU_Platforms::get_all();

		// Try to parse Essential Blocks Toggle Content.
		$parsed = $this->parse_toggle_block( $content );

		if ( $parsed ) {
			// Toggle blocks found — merge gen1/gen2 content into R1 vehicle.
			if ( in_array( 'r1', $vehicles, true ) && isset( $all_vehicles['r1'] ) ) {
				// Use gen2 content as base (newer), fall back to gen1.
				$r1_html = ! empty( $parsed['gen2'] ) ? $parsed['gen2'] : ( ! empty( $parsed['gen1'] ) ? $parsed['gen1'] : '' );
				if ( $r1_html ) {
					$r1_html = wp_kses_post( $r1_html );
					update_post_meta( $post_id, '_rsu_content_r1', $r1_html );
					$sections = RSU_Admin::parse_html_to_sections( $r1_html );
					if ( ! empty( $sections ) ) {
						update_post_meta( $post_id, '_rsu_sections_r1', wp_json_encode( $sections ) );
					}
				}
			}
		} else {
			// No toggle block: copy full content to all selected vehicles.
			foreach ( $vehicles as $slug ) {
				if ( isset( $all_vehicles[ $slug ] ) ) {
					$vehicle_html = wp_kses_post( $content );
					update_post_meta( $post_id, $all_vehicles[ $slug ]['meta_key'], $vehicle_html );
					$sections = RSU_Admin::parse_html_to_sections( $vehicle_html );
					if ( ! empty( $sections ) ) {
						update_post_meta( $post_id, '_rsu_sections_' . $slug, wp_json_encode( $sections ) );
					}
				}
			}
		}

		// Set meta fields.
		$valid_slugs = array_keys( $all_vehicles );
		$vehicles    = array_intersect( $vehicles, $valid_slugs );

		update_post_meta( $post_id, '_rsu_is_update', '1' );
		update_post_meta( $post_id, '_rsu_vehicles', $vehicles );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_noticed ) ) {
			update_post_meta( $post_id, '_rsu_date_noticed', $date_noticed );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_released ) ) {
			update_post_meta( $post_id, '_rsu_date_released', $date_released );
		}

		wp_send_json_success( array(
			'post_id' => $post_id,
			'parsed'  => ! empty( $parsed ),
		) );
	}

	/**
	 * AJAX: Convert legacy platform data to vehicle model.
	 *
	 * Finds posts with _rsu_platforms (old key) and converts:
	 * - gen1/gen2 content → r1 (uses gen2 as base)
	 * - r2 content → r2
	 * - _rsu_platforms → _rsu_vehicles
	 */
	public function ajax_convert_legacy() {
		check_ajax_referer( 'rsu_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$post_ids = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_rsu_platforms',
					'compare' => 'EXISTS',
				),
			),
		) );

		$converted = 0;
		$skipped   = 0;

		foreach ( $post_ids as $post_id ) {
			// Skip if already has _rsu_vehicles.
			$existing_vehicles = get_post_meta( $post_id, '_rsu_vehicles', true );
			if ( ! empty( $existing_vehicles ) && is_array( $existing_vehicles ) ) {
				$skipped++;
				continue;
			}

			$old_platforms = get_post_meta( $post_id, '_rsu_platforms', true );
			if ( ! is_array( $old_platforms ) || empty( $old_platforms ) ) {
				$skipped++;
				continue;
			}

			// Determine which vehicles this post should have.
			$new_vehicles = array();
			foreach ( $old_platforms as $old_slug ) {
				if ( isset( self::LEGACY_MAP[ $old_slug ] ) ) {
					$vehicle = self::LEGACY_MAP[ $old_slug ];
					if ( ! in_array( $vehicle, $new_vehicles, true ) ) {
						$new_vehicles[] = $vehicle;
					}
				}
			}

			if ( empty( $new_vehicles ) ) {
				$skipped++;
				continue;
			}

			// Migrate R1 content: prefer gen2 sections, fall back to gen1.
			if ( in_array( 'r1', $new_vehicles, true ) ) {
				$r1_sections = null;
				$r1_html     = '';

				// Try gen2 first (newer/default).
				$gen2_sections = get_post_meta( $post_id, '_rsu_sections_gen2', true );
				$gen2_html     = get_post_meta( $post_id, '_rsu_content_gen2', true );

				if ( ! empty( $gen2_sections ) ) {
					$r1_sections = $gen2_sections;
					$r1_html     = $gen2_html;
				} else {
					// Fall back to gen1.
					$gen1_sections = get_post_meta( $post_id, '_rsu_sections_gen1', true );
					$gen1_html     = get_post_meta( $post_id, '_rsu_content_gen1', true );

					if ( ! empty( $gen1_sections ) ) {
						$r1_sections = $gen1_sections;
						$r1_html     = $gen1_html;
					} elseif ( ! empty( $gen1_html ) ) {
						$r1_html = $gen1_html;
					}
				}

				if ( $r1_sections ) {
					update_post_meta( $post_id, '_rsu_sections_r1', $r1_sections );
				}
				if ( $r1_html ) {
					update_post_meta( $post_id, '_rsu_content_r1', $r1_html );
				}

				// If we only had HTML but no sections, parse them.
				if ( ! $r1_sections && $r1_html ) {
					$parsed = RSU_Admin::parse_html_to_sections( $r1_html );
					if ( ! empty( $parsed ) ) {
						update_post_meta( $post_id, '_rsu_sections_r1', wp_json_encode( $parsed ) );
					}
				}
			}

			// Migrate R2 content (direct copy if it existed).
			if ( in_array( 'r2', $new_vehicles, true ) ) {
				$r2_sections = get_post_meta( $post_id, '_rsu_sections_r2', true );
				$r2_html     = get_post_meta( $post_id, '_rsu_content_r2', true );

				// R2 key is the same in old and new model, so just ensure sections exist.
				if ( ! $r2_sections && $r2_html ) {
					$parsed = RSU_Admin::parse_html_to_sections( $r2_html );
					if ( ! empty( $parsed ) ) {
						update_post_meta( $post_id, '_rsu_sections_r2', wp_json_encode( $parsed ) );
					}
				}
			}

			// Save new vehicle list.
			update_post_meta( $post_id, '_rsu_vehicles', $new_vehicles );

			$converted++;
		}

		wp_send_json_success( array(
			'converted' => $converted,
			'skipped'   => $skipped,
			'total'     => count( $post_ids ),
		) );
	}

	/**
	 * AJAX: Backfill sections JSON for already-migrated posts.
	 */
	public function ajax_backfill_sections() {
		check_ajax_referer( 'rsu_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$all_vehicles = RSU_Platforms::get_all();

		$post_ids = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_rsu_is_update',
					'value' => '1',
				),
			),
		) );

		$updated = 0;
		$skipped = 0;

		foreach ( $post_ids as $post_id ) {
			foreach ( $all_vehicles as $slug => $vehicle ) {
				$existing_sections = get_post_meta( $post_id, '_rsu_sections_' . $slug, true );

				if ( ! empty( $existing_sections ) ) {
					$skipped++;
					continue;
				}

				$html = get_post_meta( $post_id, $vehicle['meta_key'], true );
				if ( empty( $html ) ) {
					continue;
				}

				$sections = RSU_Admin::parse_html_to_sections( $html );
				if ( ! empty( $sections ) ) {
					update_post_meta( $post_id, '_rsu_sections_' . $slug, wp_json_encode( $sections ) );
					$updated++;
				}
			}
		}

		wp_send_json_success( array(
			'total_posts' => count( $post_ids ),
			'updated'     => $updated,
			'skipped'     => $skipped,
		) );
	}

	/**
	 * Check if a post has legacy platform data (old _rsu_platforms key).
	 */
	private function has_legacy_data( $post_id ) {
		$platforms = get_post_meta( $post_id, '_rsu_platforms', true );
		if ( ! is_array( $platforms ) || empty( $platforms ) ) {
			return false;
		}
		// It's legacy if any of the old platform slugs are present.
		$old_slugs = array_keys( self::LEGACY_MAP );
		return ! empty( array_intersect( $platforms, $old_slugs ) );
	}

	/**
	 * Detect if post content contains an Essential Blocks toggle block.
	 */
	private function detect_toggle_block( $content ) {
		return (
			false !== strpos( $content, 'wp:essential-blocks/toggle-content' ) ||
			false !== strpos( $content, 'eb-toggle-content' )
		);
	}

	/**
	 * Parse Essential Blocks Toggle Content block to extract Gen 1 and Gen 2 content.
	 */
	private function parse_toggle_block( $content ) {
		if ( ! $this->detect_toggle_block( $content ) ) {
			return false;
		}

		$parsed = array(
			'gen1' => '',
			'gen2' => '',
		);

		$blocks = parse_blocks( $content );

		foreach ( $blocks as $block ) {
			if ( false === strpos( $block['blockName'] ?? '', 'essential-blocks' ) ) {
				continue;
			}

			$inner_html    = $block['innerHTML'] ?? '';
			$inner_content = '';

			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( $block['innerBlocks'] as $inner_block ) {
					$inner_content .= render_block( $inner_block );
				}
			}

			if ( ! $inner_content ) {
				$inner_content = $inner_html;
			}

			$full_text = strtolower( $inner_html . ( $block['attrs']['title'] ?? '' ) );

			if ( false !== strpos( $full_text, 'gen 1' ) || false !== strpos( $full_text, 'gen1' ) ||
				 false !== strpos( $full_text, '2021' ) ) {
				$parsed['gen1'] = $inner_content;
			} elseif ( false !== strpos( $full_text, 'gen 2' ) || false !== strpos( $full_text, 'gen2' ) ||
					   false !== strpos( $full_text, '2025' ) ) {
				$parsed['gen2'] = $inner_content;
			}
		}

		if ( ! $parsed['gen1'] && ! $parsed['gen2'] ) {
			return false;
		}

		return $parsed;
	}
}
