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

		// Vehicles (replaces old platforms).
		$valid_slugs = array_keys( RSU_Platforms::get_all() );
		if ( isset( $_POST['rsu_vehicles'] ) && is_array( $_POST['rsu_vehicles'] ) ) {
			$vehicles = array_intersect(
				array_map( 'sanitize_text_field', wp_unslash( $_POST['rsu_vehicles'] ) ),
				$valid_slugs
			);
			update_post_meta( $post_id, '_rsu_vehicles', $vehicles );
		} else {
			delete_post_meta( $post_id, '_rsu_vehicles' );
		}

		// Vehicle content via section builder.
		foreach ( RSU_Platforms::get_all() as $slug => $vehicle ) {
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
					$html = self::render_sections_to_html( $sections, $slug );
					if ( $html ) {
						update_post_meta( $post_id, $vehicle['meta_key'], wp_kses_post( $html ) );
					} else {
						delete_post_meta( $post_id, $vehicle['meta_key'] );
					}
				} else {
					delete_post_meta( $post_id, '_rsu_sections_' . $slug );
					delete_post_meta( $post_id, $vehicle['meta_key'] );
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
	 * Supports generation tags on blocks and list items.
	 *
	 * @param array $sections Raw sections array.
	 * @return array Sanitized sections.
	 */
	private static function sanitize_sections( $sections ) {
		$clean = array();
		$valid_generations = RSU_Platforms::get_all_generation_slugs();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$clean_section = array(
				'heading' => isset( $section['heading'] ) ? sanitize_text_field( $section['heading'] ) : '',
				'blocks'  => array(),
			);

			// Section-level generation tag (for headings).
			if ( ! empty( $section['generation'] ) ) {
				$section_gen = sanitize_text_field( $section['generation'] );
				if ( in_array( $section_gen, $valid_generations, true ) ) {
					$clean_section['generation'] = $section_gen;
				}
			}

			if ( ! empty( $section['blocks'] ) && is_array( $section['blocks'] ) ) {
				foreach ( $section['blocks'] as $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}

					$type = isset( $block['type'] ) ? sanitize_text_field( $block['type'] ) : 'paragraph';
					$generation = null;

					// Block-level generation tag (for paragraph and note).
					if ( ! empty( $block['generation'] ) ) {
						$gen = sanitize_text_field( $block['generation'] );
						if ( in_array( $gen, $valid_generations, true ) ) {
							$generation = $gen;
						}
					}

					if ( 'list' === $type ) {
						$raw_items = isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : array();
						$items = array();
						foreach ( $raw_items as $item ) {
							if ( is_array( $item ) ) {
								// New format: { text: "...", generation: "gen1" }
								$clean_item = array(
									'text' => isset( $item['text'] ) ? sanitize_text_field( $item['text'] ) : '',
								);
								if ( ! empty( $item['generation'] ) ) {
									$item_gen = sanitize_text_field( $item['generation'] );
									if ( in_array( $item_gen, $valid_generations, true ) ) {
										$clean_item['generation'] = $item_gen;
									}
								}
								$items[] = $clean_item;
							} else {
								// Backward compat: plain string items.
								$items[] = array(
									'text' => sanitize_text_field( $item ),
								);
							}
						}

						$clean_block = array(
							'type'  => 'list',
							'items' => $items,
						);
						if ( $generation ) {
							$clean_block['generation'] = $generation;
						}
						$clean_section['blocks'][] = $clean_block;
					} else {
						$clean_block = array(
							'type'    => $type,
							'content' => isset( $block['content'] ) ? sanitize_textarea_field( $block['content'] ) : '',
						);
						if ( $generation ) {
							$clean_block['generation'] = $generation;
						}
						$clean_section['blocks'][] = $clean_block;
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
	 * Includes generation pill badges for generation-specific content.
	 *
	 * @param array  $sections      Sections array.
	 * @param string $vehicle_slug  Vehicle slug for generation lookup.
	 * @return string HTML content.
	 */
	public static function render_sections_to_html( $sections, $vehicle_slug = '' ) {
		if ( ! is_array( $sections ) ) {
			return '';
		}

		$heading_tag = RSU_Settings::get( 'heading_level', 'h3' );
		$note_label  = RSU_Settings::get( 'note_label', 'NOTE' );
		$html        = '';

		// Get generation labels for pill rendering.
		$gen_labels = array();
		if ( $vehicle_slug ) {
			$all_vehicles = RSU_Platforms::get_all();
			if ( isset( $all_vehicles[ $vehicle_slug ]['generations'] ) ) {
				foreach ( $all_vehicles[ $vehicle_slug ]['generations'] as $gen_slug => $gen ) {
					$gen_labels[ $gen_slug ] = $gen['label'];
				}
			}
		}

		foreach ( $sections as $section ) {
			$heading     = isset( $section['heading'] ) ? trim( $section['heading'] ) : '';
			$section_gen = isset( $section['generation'] ) ? $section['generation'] : '';
			if ( $heading ) {
				$heading_pill = self::render_generation_pill( $section_gen, $gen_labels );
				$html .= '<' . $heading_tag . '>' . esc_html( $heading ) . $heading_pill . '</' . $heading_tag . '>' . "\n";
			}

			if ( empty( $section['blocks'] ) || ! is_array( $section['blocks'] ) ) {
				continue;
			}

			foreach ( $section['blocks'] as $block ) {
				$type       = isset( $block['type'] ) ? $block['type'] : 'paragraph';
				$block_gen  = isset( $block['generation'] ) ? $block['generation'] : '';
				$block_pill = self::render_generation_pill( $block_gen, $gen_labels );

				switch ( $type ) {
					case 'paragraph':
						$content = isset( $block['content'] ) ? trim( $block['content'] ) : '';
						if ( $content ) {
							$html .= '<p>' . nl2br( esc_html( $content ) ) . $block_pill . '</p>' . "\n";
						}
						break;

					case 'list':
						$items = isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : array();
						if ( ! empty( $items ) ) {
							$html .= "<ul>\n";
							foreach ( $items as $item ) {
								$item_text = '';
								$item_gen  = '';

								if ( is_array( $item ) ) {
									$item_text = isset( $item['text'] ) ? trim( $item['text'] ) : '';
									$item_gen  = isset( $item['generation'] ) ? $item['generation'] : '';
								} else {
									$item_text = trim( $item );
								}

								if ( '' !== $item_text ) {
									$item_pill = self::render_generation_pill( $item_gen, $gen_labels );
									$html .= '<li>' . esc_html( $item_text ) . $item_pill . '</li>' . "\n";
								}
							}
							$html .= "</ul>\n";
							// Block-level pill goes after the list.
							if ( $block_pill ) {
								$html .= '<p class="rsu-list-pill">' . $block_pill . '</p>' . "\n";
							}
						}
						break;

					case 'note':
						$content = isset( $block['content'] ) ? trim( $block['content'] ) : '';
						if ( $content ) {
							$html .= '<blockquote><p><strong>' . esc_html( $note_label ) . '</strong></p>' . "\n";
							$html .= '<p>' . nl2br( esc_html( $content ) ) . $block_pill . '</p></blockquote>' . "\n";
						}
						break;
				}
			}
		}

		return $html;
	}

	/**
	 * Render a generation pill badge HTML.
	 *
	 * @param string $generation Generation slug (empty = all generations).
	 * @param array  $gen_labels Map of generation slug => label.
	 * @return string HTML for pill badge, or empty string.
	 */
	private static function render_generation_pill( $generation, $gen_labels ) {
		if ( empty( $generation ) || empty( $gen_labels ) ) {
			return '';
		}

		// Only show pill if the vehicle has multiple generations.
		if ( count( $gen_labels ) < 2 ) {
			return '';
		}

		$label = isset( $gen_labels[ $generation ] ) ? $gen_labels[ $generation ] : $generation;

		return '<span class="rsu-gen-pill" data-generation="' . esc_attr( $generation ) . '">'
			. esc_html( $label ) . ' Only'
			. '</span>';
	}

	/**
	 * Parse existing HTML content into structured sections array.
	 *
	 * Converts HTML with h3/h4 headings, p, ul/li, and blockquote elements
	 * into the sections JSON format used by the section builder.
	 * Also parses generation pill spans back into generation tags.
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
				if ( XML_TEXT_NODE === $node->nodeType && '' !== trim( $node->textContent ) ) {
					self::ensure_section( $sections, $current );
					$current['blocks'][] = array(
						'type'    => 'paragraph',
						'content' => trim( $node->textContent ),
					);
				}
				continue;
			}

			$tag = strtolower( $node->nodeName );

			if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				if ( null !== $current ) {
					$sections[] = $current;
				}
				$gen = self::extract_generation_from_node( $node );
				$text = trim( $node->textContent );
				if ( $gen ) {
					$text = preg_replace( '/\s*' . preg_quote( $gen, '/' ) . '\s*Only\s*/i', '', $text );
					$text = trim( $text );
				}
				$current = array(
					'heading' => $text,
					'blocks'  => array(),
				);
				if ( $gen ) {
					$current['generation'] = $gen;
				}
				continue;
			}

			self::ensure_section( $sections, $current );

			if ( 'ul' === $tag || 'ol' === $tag ) {
				$items = array();
				foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
					$item = array( 'text' => '' );
					// Check for generation pill.
					$pill = $li->getElementsByTagName( 'span' );
					$gen  = '';
					if ( $pill->length > 0 ) {
						$span = $pill->item( 0 );
						if ( $span->hasAttribute( 'data-generation' ) ) {
							$gen = $span->getAttribute( 'data-generation' );
						}
					}
					// Get text without pill.
					$text = trim( $li->textContent );
					if ( $gen ) {
						// Remove the pill text from item text.
						$text = preg_replace( '/\s*' . preg_quote( $gen, '/' ) . '\s*Only\s*/i', '', $text );
						$text = trim( $text );
						$item['generation'] = $gen;
					}
					$item['text'] = $text;
					if ( '' !== $item['text'] ) {
						$items[] = $item;
					}
				}
				if ( ! empty( $items ) ) {
					$current['blocks'][] = array(
						'type'  => 'list',
						'items' => $items,
					);
				}
			} elseif ( 'blockquote' === $tag ) {
				$block = array( 'type' => 'note' );
				$gen = self::extract_generation_from_node( $node );
				if ( $gen ) {
					$block['generation'] = $gen;
				}
				$text = trim( $node->textContent );
				$text = preg_replace( '/^\s*NOTE\s*/i', '', $text );
				// Remove pill text.
				if ( $gen ) {
					$text = preg_replace( '/\s*' . preg_quote( $gen, '/' ) . '\s*Only\s*/i', '', $text );
				}
				$block['content'] = trim( $text );
				if ( '' !== $block['content'] ) {
					$current['blocks'][] = $block;
				}
			} elseif ( 'p' === $tag ) {
				$block = array( 'type' => 'paragraph' );
				$gen = self::extract_generation_from_node( $node );
				if ( $gen ) {
					$block['generation'] = $gen;
				}
				$text = trim( $node->textContent );
				// Remove pill text.
				if ( $gen ) {
					$text = preg_replace( '/\s*' . preg_quote( $gen, '/' ) . '\s*Only\s*/i', '', $text );
				}
				$block['content'] = trim( $text );
				if ( '' !== $block['content'] ) {
					$current['blocks'][] = $block;
				}
			} elseif ( 'div' === $tag ) {
				$inner_html = '';
				foreach ( $node->childNodes as $child ) {
					$inner_html .= $doc->saveHTML( $child );
				}
				$inner_sections = self::parse_html_to_sections( $inner_html );
				if ( ! empty( $inner_sections ) ) {
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
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$current['blocks'][] = array(
						'type'    => 'paragraph',
						'content' => $text,
					);
				}
			}
		}

		if ( null !== $current ) {
			$sections[] = $current;
		}

		return $sections;
	}

	/**
	 * Extract generation slug from a node's child rsu-gen-pill span.
	 *
	 * @param DOMNode $node DOM node to check.
	 * @return string Generation slug or empty string.
	 */
	private static function extract_generation_from_node( $node ) {
		$spans = $node->getElementsByTagName( 'span' );
		if ( $spans->length > 0 ) {
			foreach ( $spans as $span ) {
				if ( $span->hasAttribute( 'data-generation' ) ) {
					return $span->getAttribute( 'data-generation' );
				}
			}
		}
		return '';
	}

	/**
	 * Ensure a current section exists.
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
	}
}
