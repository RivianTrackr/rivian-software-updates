<?php
/**
 * Migration tool for converting existing posts to use RSU meta fields.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Migration {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'wp_ajax_rsu_migrate_post', array( $this, 'ajax_migrate_post' ) );
		add_action( 'wp_ajax_rsu_scan_posts', array( $this, 'ajax_scan_posts' ) );
		add_action( 'wp_ajax_rsu_backfill_sections', array( $this, 'ajax_backfill_sections' ) );
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
			'post_type'      => array( 'post', RSU_Post_Type::SLUG ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );

		$results = array();
		foreach ( $posts as $post_id ) {
			$post    = get_post( $post_id );
			$is_update = get_post_meta( $post_id, '_rsu_is_update', true );
			$has_toggle = $this->detect_toggle_block( $post->post_content );

			$results[] = array(
				'id'          => $post_id,
				'title'       => get_the_title( $post_id ),
				'date'        => get_the_date( 'Y-m-d', $post_id ),
				'url'         => get_permalink( $post_id ),
				'migrated'    => ! empty( $is_update ),
				'has_toggle'  => $has_toggle,
				'version'     => get_post_meta( $post_id, '_rsu_version', true ),
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: Migrate a single post.
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

		$platforms = isset( $_POST['platforms'] ) && is_array( $_POST['platforms'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['platforms'] ) )
			: array( 'gen1', 'gen2' );

		$version = isset( $_POST['version'] )
			? sanitize_text_field( wp_unslash( $_POST['version'] ) )
			: '';

		$date_noticed = isset( $_POST['date_noticed'] )
			? sanitize_text_field( wp_unslash( $_POST['date_noticed'] ) )
			: '';

		$date_released = isset( $_POST['date_released'] )
			? sanitize_text_field( wp_unslash( $_POST['date_released'] ) )
			: '';

		$post = get_post( $post_id );
		$content = $post->post_content;

		// Try to parse Essential Blocks Toggle Content.
		$parsed = $this->parse_toggle_block( $content );

		$all_platforms = RSU_Platforms::get_all();

		if ( $parsed ) {
			// Parsed Gen 1 and Gen 2 content from toggle blocks.
			if ( ! empty( $parsed['gen1'] ) && in_array( 'gen1', $platforms, true ) ) {
				$gen1_html = wp_kses_post( $parsed['gen1'] );
				update_post_meta( $post_id, $all_platforms['gen1']['meta_key'], $gen1_html );
				$gen1_sections = RSU_Admin::parse_html_to_sections( $gen1_html );
				if ( ! empty( $gen1_sections ) ) {
					update_post_meta( $post_id, '_rsu_sections_gen1', wp_json_encode( $gen1_sections ) );
				}
			}
			if ( ! empty( $parsed['gen2'] ) && in_array( 'gen2', $platforms, true ) ) {
				$gen2_html = wp_kses_post( $parsed['gen2'] );
				update_post_meta( $post_id, $all_platforms['gen2']['meta_key'], $gen2_html );
				$gen2_sections = RSU_Admin::parse_html_to_sections( $gen2_html );
				if ( ! empty( $gen2_sections ) ) {
					update_post_meta( $post_id, '_rsu_sections_gen2', wp_json_encode( $gen2_sections ) );
				}
			}
		} else {
			// No toggle block found: copy full content to all selected platforms.
			foreach ( $platforms as $slug ) {
				if ( isset( $all_platforms[ $slug ] ) ) {
					$platform_html = wp_kses_post( $content );
					update_post_meta( $post_id, $all_platforms[ $slug ]['meta_key'], $platform_html );
					$platform_sections = RSU_Admin::parse_html_to_sections( $platform_html );
					if ( ! empty( $platform_sections ) ) {
						update_post_meta( $post_id, '_rsu_sections_' . $slug, wp_json_encode( $platform_sections ) );
					}
				}
			}
		}

		// Set meta fields.
		$valid_slugs = array_keys( $all_platforms );
		$platforms = array_intersect( $platforms, $valid_slugs );

		update_post_meta( $post_id, '_rsu_is_update', '1' );
		update_post_meta( $post_id, '_rsu_platforms', $platforms );

		if ( $version ) {
			update_post_meta( $post_id, '_rsu_version', $version );
		}

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
	 * AJAX: Backfill sections JSON for already-migrated posts.
	 *
	 * Finds posts that have _rsu_is_update but no _rsu_sections_* meta,
	 * parses their HTML content into structured sections.
	 */
	public function ajax_backfill_sections() {
		check_ajax_referer( 'rsu_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$all_platforms = RSU_Platforms::get_all();

		// Find all posts flagged as software updates.
		$post_ids = get_posts( array(
			'post_type'      => array( 'post', RSU_Post_Type::SLUG ),
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
			foreach ( $all_platforms as $slug => $platform ) {
				$existing_sections = get_post_meta( $post_id, '_rsu_sections_' . $slug, true );

				// Skip if sections JSON already exists.
				if ( ! empty( $existing_sections ) ) {
					$skipped++;
					continue;
				}

				$html = get_post_meta( $post_id, $platform['meta_key'], true );
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
	 *
	 * Attempts to split toggle sections by looking for common patterns.
	 */
	private function parse_toggle_block( $content ) {
		if ( ! $this->detect_toggle_block( $content ) ) {
			return false;
		}

		$parsed = array(
			'gen1' => '',
			'gen2' => '',
		);

		// Parse WordPress blocks.
		$blocks = parse_blocks( $content );

		foreach ( $blocks as $block ) {
			if ( false === strpos( $block['blockName'] ?? '', 'essential-blocks' ) ) {
				continue;
			}

			$inner_html = $block['innerHTML'] ?? '';
			$inner_content = '';

			// Collect inner blocks' rendered content.
			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( $block['innerBlocks'] as $inner_block ) {
					$inner_content .= render_block( $inner_block );
				}
			}

			if ( ! $inner_content ) {
				$inner_content = $inner_html;
			}

			// Determine which generation this toggle belongs to.
			$full_text = strtolower( $inner_html . ( $block['attrs']['title'] ?? '' ) );

			if ( false !== strpos( $full_text, 'gen 1' ) || false !== strpos( $full_text, 'gen1' ) ||
				 false !== strpos( $full_text, '2021' ) ) {
				$parsed['gen1'] = $inner_content;
			} elseif ( false !== strpos( $full_text, 'gen 2' ) || false !== strpos( $full_text, 'gen2' ) ||
					   false !== strpos( $full_text, '2025' ) ) {
				$parsed['gen2'] = $inner_content;
			}
		}

		// If we couldn't identify either, return false.
		if ( ! $parsed['gen1'] && ! $parsed['gen2'] ) {
			return false;
		}

		return $parsed;
	}
}
