<?php
/**
 * Admin meta boxes for software update posts.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Admin {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register meta boxes on the post editor.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'rsu_release_notes',
			'Release Notes',
			array( $this, 'render_content_meta_box' ),
			'post',
			'normal',
			'high'
		);

		add_meta_box(
			'rsu_update_details',
			'Update Details',
			array( $this, 'render_details_meta_box' ),
			'post',
			'side',
			'high'
		);
	}

	/**
	 * Render the release notes content meta box.
	 */
	public function render_content_meta_box( $post ) {
		include RSU_PLUGIN_DIR . 'admin/views/meta-box-content.php';
	}

	/**
	 * Render the update details side meta box.
	 */
	public function render_details_meta_box( $post ) {
		include RSU_PLUGIN_DIR . 'admin/views/meta-box-dates.php';
	}

	/**
	 * Save meta box data.
	 */
	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['rsu_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rsu_meta_nonce'] ) ), 'rsu_meta_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'post' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Software update flag.
		if ( ! empty( $_POST['rsu_is_update'] ) ) {
			update_post_meta( $post_id, '_rsu_is_update', '1' );
		} else {
			delete_post_meta( $post_id, '_rsu_is_update' );
		}

		// Version.
		if ( isset( $_POST['rsu_version'] ) ) {
			$version = sanitize_text_field( wp_unslash( $_POST['rsu_version'] ) );
			if ( $version ) {
				update_post_meta( $post_id, '_rsu_version', $version );
			} else {
				delete_post_meta( $post_id, '_rsu_version' );
			}
		}

		// Platforms.
		$valid_slugs = array_keys( RSU_Platforms::get_all() );
		if ( isset( $_POST['rsu_platforms'] ) && is_array( $_POST['rsu_platforms'] ) ) {
			$platforms = array_intersect(
				array_map( 'sanitize_text_field', wp_unslash( $_POST['rsu_platforms'] ) ),
				$valid_slugs
			);
			update_post_meta( $post_id, '_rsu_platforms', $platforms );
		} else {
			delete_post_meta( $post_id, '_rsu_platforms' );
		}

		// Platform content via section builder.
		foreach ( RSU_Platforms::get_all() as $slug => $platform ) {
			$json_field = 'rsu_sections_' . $slug;
			if ( isset( $_POST[ $json_field ] ) ) {
				$raw_json = wp_unslash( $_POST[ $json_field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized below.
				$sections = json_decode( $raw_json, true );

				if ( is_array( $sections ) && ! empty( $sections ) ) {
					// Sanitize section data.
					$sections = self::sanitize_sections( $sections );

					// Store structured JSON.
					update_post_meta( $post_id, '_rsu_sections_' . $slug, wp_json_encode( $sections ) );

					// Render to HTML for frontend display.
					$html = self::render_sections_to_html( $sections );
					if ( $html ) {
						update_post_meta( $post_id, $platform['meta_key'], wp_kses_post( $html ) );
					} else {
						delete_post_meta( $post_id, $platform['meta_key'] );
					}
				} else {
					delete_post_meta( $post_id, '_rsu_sections_' . $slug );
					delete_post_meta( $post_id, $platform['meta_key'] );
				}
			}
		}

		// Dates.
		$date_fields = array(
			'rsu_date_noticed'  => '_rsu_date_noticed',
			'rsu_date_released' => '_rsu_date_released',
		);

		foreach ( $date_fields as $post_field => $meta_key ) {
			if ( isset( $_POST[ $post_field ] ) ) {
				$date = sanitize_text_field( wp_unslash( $_POST[ $post_field ] ) );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					update_post_meta( $post_id, $meta_key, $date );
				} else {
					delete_post_meta( $post_id, $meta_key );
				}
			}
		}
	}

	/**
	 * Sanitize sections data from user input.
	 *
	 * @param array $sections Raw sections array.
	 * @return array Sanitized sections.
	 */
	private static function sanitize_sections( $sections ) {
		$clean = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$clean_section = array(
				'heading' => isset( $section['heading'] ) ? sanitize_text_field( $section['heading'] ) : '',
				'blocks'  => array(),
			);

			if ( ! empty( $section['blocks'] ) && is_array( $section['blocks'] ) ) {
				foreach ( $section['blocks'] as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}

					$type = isset( $block['type'] ) ? sanitize_text_field( $block['type'] ) : 'paragraph';

					if ( 'list' === $type ) {
						$items = isset( $block['items'] ) && is_array( $block['items'] )
							? array_map( 'sanitize_text_field', $block['items'] )
							: array();
						$clean_section['blocks'][] = array(
							'type'  => 'list',
							'items' => $items,
						);
					} else {
						$clean_section['blocks'][] = array(
							'type'    => $type,
							'content' => isset( $block['content'] ) ? sanitize_textarea_field( $block['content'] ) : '',
						);
					}
				}
			}

			$clean[] = $clean_section;
		}

		return $clean;
	}

	/**
	 * Render structured sections array to HTML for frontend display.
	 *
	 * @param array $sections Sections array.
	 * @return string HTML content.
	 */
	public static function render_sections_to_html( $sections ) {
		if ( ! is_array( $sections ) ) {
			return '';
		}

		$html = '';

		foreach ( $sections as $section ) {
			$heading = isset( $section['heading'] ) ? trim( $section['heading'] ) : '';
			if ( $heading ) {
				$html .= '<h3>' . esc_html( $heading ) . '</h3>' . "\n";
			}

			if ( empty( $section['blocks'] ) || ! is_array( $section['blocks'] ) ) {
				continue;
			}

			foreach ( $section['blocks'] as $block ) {
				$type = isset( $block['type'] ) ? $block['type'] : 'paragraph';

				switch ( $type ) {
					case 'paragraph':
						$content = isset( $block['content'] ) ? trim( $block['content'] ) : '';
						if ( $content ) {
							// Convert line breaks to <br> within a paragraph.
							$html .= '<p>' . nl2br( esc_html( $content ) ) . '</p>' . "\n";
						}
						break;

					case 'list':
						$items = isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : array();
						if ( ! empty( $items ) ) {
							$html .= "<ul>\n";
							foreach ( $items as $item ) {
								$item = trim( $item );
								if ( '' !== $item ) {
									$html .= '<li>' . esc_html( $item ) . '</li>' . "\n";
								}
							}
							$html .= "</ul>\n";
						}
						break;

					case 'note':
						$content = isset( $block['content'] ) ? trim( $block['content'] ) : '';
						if ( $content ) {
							$html .= '<blockquote><p><strong>NOTE</strong></p>' . "\n";
							$html .= '<p>' . nl2br( esc_html( $content ) ) . '</p></blockquote>' . "\n";
						}
						break;
				}
			}
		}

		return $html;
	}

	/**
	 * Enqueue admin assets on post editor screens.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$css_file = RSU_PLUGIN_DIR . 'admin/css/rsu-admin' . $suffix . '.css';
		if ( ! file_exists( $css_file ) ) {
			$suffix = '';
		}

		wp_enqueue_style(
			'rsu-admin',
			RSU_PLUGIN_URL . 'admin/css/rsu-admin' . $suffix . '.css',
			array(),
			RSU_VERSION
		);

		wp_enqueue_script(
			'rsu-admin',
			RSU_PLUGIN_URL . 'admin/js/rsu-admin' . $suffix . '.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			RSU_VERSION,
			true
		);
	}
}
