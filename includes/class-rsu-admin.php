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
			RSU_Post_Type::SLUG,
			'normal',
			'high'
		);

		add_meta_box(
			'rsu_update_details',
			'Update Details',
			array( $this, 'render_details_meta_box' ),
			RSU_Post_Type::SLUG,
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

		if ( RSU_Post_Type::SLUG !== $post->post_type ) {
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

		$heading_tag = RSU_Settings::get( 'heading_level', 'h3' );
		$note_label  = RSU_Settings::get( 'note_label', 'NOTE' );
		$html        = '';

		foreach ( $sections as $section ) {
			$heading = isset( $section['heading'] ) ? trim( $section['heading'] ) : '';
			if ( $heading ) {
				$html .= '<' . $heading_tag . '>' . esc_html( $heading ) . '</' . $heading_tag . '>' . "\n";
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
							$html .= '<blockquote><p><strong>' . esc_html( $note_label ) . '</strong></p>' . "\n";
							$html .= '<p>' . nl2br( esc_html( $content ) ) . '</p></blockquote>' . "\n";
						}
						break;
				}
			}
		}

		return $html;
	}

	/**
	 * Parse existing HTML content into structured sections array.
	 *
	 * Converts HTML with h3/h4 headings, p, ul/li, and blockquote elements
	 * into the sections JSON format used by the section builder.
	 *
	 * @param string $html HTML content to parse.
	 * @return array Sections array.
	 */
	public static function parse_html_to_sections( $html ) {
		$html = trim( $html );
		if ( empty( $html ) ) {
			return array();
		}

		// Wrap in a root element for DOMDocument parsing.
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<html><body>' . mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$body     = $doc->getElementsByTagName( 'body' )->item( 0 );
		$sections = array();
		$current  = null;

		if ( ! $body ) {
			return array();
		}

		foreach ( $body->childNodes as $node ) {
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				// Skip whitespace text nodes.
				if ( XML_TEXT_NODE === $node->nodeType && '' !== trim( $node->textContent ) ) {
					// Stray text — treat as paragraph.
					self::ensure_section( $sections, $current );
					$current['blocks'][] = array(
						'type'    => 'paragraph',
						'content' => trim( $node->textContent ),
					);
				}
				continue;
			}

			$tag = strtolower( $node->nodeName );

			// Heading starts a new section.
			if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				// Save previous section if it exists.
				if ( null !== $current ) {
					$sections[] = $current;
				}
				$current = array(
					'heading' => trim( $node->textContent ),
					'blocks'  => array(),
				);
				continue;
			}

			// Everything else goes into the current section.
			self::ensure_section( $sections, $current );

			if ( 'ul' === $tag || 'ol' === $tag ) {
				$items = array();
				foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
					$text = trim( $li->textContent );
					if ( '' !== $text ) {
						$items[] = $text;
					}
				}
				if ( ! empty( $items ) ) {
					$current['blocks'][] = array(
						'type'  => 'list',
						'items' => $items,
					);
				}
			} elseif ( 'blockquote' === $tag ) {
				// Extract text, stripping "NOTE" prefix if present.
				$text = trim( $node->textContent );
				$text = preg_replace( '/^\s*NOTE\s*/i', '', $text );
				if ( '' !== $text ) {
					$current['blocks'][] = array(
						'type'    => 'note',
						'content' => $text,
					);
				}
			} elseif ( 'p' === $tag ) {
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$current['blocks'][] = array(
						'type'    => 'paragraph',
						'content' => $text,
					);
				}
			} elseif ( 'div' === $tag ) {
				// Divs may wrap inner content (e.g. from Essential Blocks).
				// Recursively parse inner HTML.
				$inner_html = '';
				foreach ( $node->childNodes as $child ) {
					$inner_html .= $doc->saveHTML( $child );
				}
				$inner_sections = self::parse_html_to_sections( $inner_html );
				if ( ! empty( $inner_sections ) ) {
					// Merge: if current section has no heading and inner starts with one, just append.
					if ( null !== $current && ! empty( $current['blocks'] ) ) {
						$sections[] = $current;
						$current = null;
					}
					foreach ( $inner_sections as $is ) {
						$sections[] = $is;
					}
					$current = null;
				}
			} else {
				// Unknown element — treat text content as paragraph.
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$current['blocks'][] = array(
						'type'    => 'paragraph',
						'content' => $text,
					);
				}
			}
		}

		// Don't forget the last section.
		if ( null !== $current ) {
			$sections[] = $current;
		}

		return $sections;
	}

	/**
	 * Ensure a current section exists. Creates one with empty heading if needed.
	 *
	 * @param array      $sections Sections array (passed by reference).
	 * @param array|null $current  Current section (passed by reference).
	 */
	private static function ensure_section( &$sections, &$current ) {
		if ( null === $current ) {
			$current = array(
				'heading' => '',
				'blocks'  => array(),
			);
		}
	}

	/**
	 * Enqueue admin assets on post editor screens.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || RSU_Post_Type::SLUG !== $screen->post_type ) {
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

		// Section builder JS is inline in meta-box-content.php (no external JS needed).
	}
}
