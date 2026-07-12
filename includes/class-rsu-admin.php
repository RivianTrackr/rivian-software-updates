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
		add_filter( 'display_post_states', array( $this, 'add_hotfix_post_state' ), 10, 2 );
	}

	/**
	 * Flag hotfix updates in the Posts list table with a "Hotfix" state label,
	 * the same way core marks "Draft" or "Sticky" next to a title. Without it,
	 * a hotfix and its base release share an identical title and are
	 * indistinguishable in the admin list.
	 *
	 * @param array   $states Existing post state labels.
	 * @param WP_Post $post   The post being listed.
	 * @return array
	 */
	public function add_hotfix_post_state( $states, $post ) {
		if ( $post && get_post_meta( $post->ID, '_rsu_is_hotfix', true ) ) {
			$states['rsu_hotfix'] = __( 'Hotfix', 'rivian-software-updates' );
		}
		return $states;
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
		$valid_slugs   = array_keys( RSU_Platforms::get_all() );
		$active_slugs  = array();
		if ( isset( $_POST['rsu_vehicles'] ) && is_array( $_POST['rsu_vehicles'] ) ) {
			$active_slugs = array_values( array_intersect(
				array_map( 'sanitize_text_field', wp_unslash( $_POST['rsu_vehicles'] ) ),
				$valid_slugs
			) );
			update_post_meta( $post_id, '_rsu_vehicles', $active_slugs );
		} else {
			delete_post_meta( $post_id, '_rsu_vehicles' );
		}

		// Vehicle content via section builder.
		foreach ( RSU_Platforms::get_all() as $slug => $vehicle ) {
			// Deselected vehicles never persist content — clear any stale meta.
			if ( ! in_array( $slug, $active_slugs, true ) ) {
				delete_post_meta( $post_id, '_rsu_sections_' . $slug );
				delete_post_meta( $post_id, $vehicle['meta_key'] );
				continue;
			}

			$json_field = 'rsu_sections_' . $slug;
			if ( isset( $_POST[ $json_field ] ) ) {
				$raw_json = wp_unslash( $_POST[ $json_field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded and sanitized below.
				$sections = json_decode( $raw_json, true );

				if ( is_array( $sections ) && ! empty( $sections ) ) {
					// Sanitize section data.
					$sections = self::sanitize_sections( $sections, $slug );

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

		// Hotfix details: flag, parent release, and per-generation build numbers.
		if ( ! empty( $_POST['rsu_is_hotfix'] ) ) {
			update_post_meta( $post_id, '_rsu_is_hotfix', '1' );

			// Parent release must be another published update post (not a hotfix, not self).
			$parent = isset( $_POST['rsu_parent_release'] ) ? intval( wp_unslash( $_POST['rsu_parent_release'] ) ) : 0;
			if ( $parent > 0 && $parent !== (int) $post_id
				&& get_post_meta( $parent, '_rsu_is_update', true )
				&& ! get_post_meta( $parent, '_rsu_is_hotfix', true ) ) {
				update_post_meta( $post_id, '_rsu_parent_release', $parent );
			} else {
				delete_post_meta( $post_id, '_rsu_parent_release' );
			}

			// Per-vehicle, per-generation build numbers (e.g. 2026.15.01 for R1 Gen 1).
			$builds = array();
			if ( isset( $_POST['rsu_hotfix_builds'] ) && is_array( $_POST['rsu_hotfix_builds'] ) ) {
				$all = RSU_Platforms::get_all();
				foreach ( wp_unslash( $_POST['rsu_hotfix_builds'] ) as $v_slug => $gens ) {
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
						$build = sanitize_text_field( $build );
						if ( '' !== $build ) {
							$builds[ $v_slug ][ $g_slug ] = $build;
						}
					}
				}
			}

			if ( ! empty( $builds ) ) {
				update_post_meta( $post_id, '_rsu_hotfix_builds', $builds );
			} else {
				delete_post_meta( $post_id, '_rsu_hotfix_builds' );
			}
		} else {
			delete_post_meta( $post_id, '_rsu_is_hotfix' );
			delete_post_meta( $post_id, '_rsu_parent_release' );
			delete_post_meta( $post_id, '_rsu_hotfix_builds' );
		}
	}

	/**
	 * Sanitize a list's items array (text, optional level 0|1, optional generation).
	 *
	 * @param mixed $raw_items         Raw items array.
	 * @param array $valid_generations Allowed generation slugs.
	 * @param array $gen_labels        Map of generation slug => label, for stripping
	 *                                 stray "{Label} Only" pollution from item text.
	 * @return array
	 */
	private static function sanitize_list_items( $raw_items, $valid_generations, $gen_labels = array() ) {
		if ( ! is_array( $raw_items ) ) {
			return array();
		}

		$items = array();
		foreach ( $raw_items as $item ) {
			if ( is_array( $item ) ) {
				$text = isset( $item['text'] ) ? sanitize_text_field( $item['text'] ) : '';
				$item_gen = '';
				if ( ! empty( $item['generation'] ) ) {
					$candidate = sanitize_text_field( $item['generation'] );
					if ( in_array( $candidate, $valid_generations, true ) ) {
						$item_gen = $candidate;
					}
				}
				$text = self::strip_pill_pollution( $text, $item_gen, $gen_labels );

				$clean_item = array( 'text' => $text );
				if ( isset( $item['level'] ) ) {
					$clean_item['level'] = max( 0, min( 1, intval( $item['level'] ) ) );
				}
				if ( $item_gen ) {
					$clean_item['generation'] = $item_gen;
				}
				$items[] = $clean_item;
			} else {
				$items[] = array( 'text' => sanitize_text_field( $item ) );
			}
		}
		return $items;
	}

	/**
	 * Strip a trailing "{Label} Only" suffix from text when it was concatenated
	 * by the pre-fix parse_html_to_sections (which read pill text into
	 * surrounding content). Only strips when the block/item already carries a
	 * generation tag whose label matches the suffix, so user-authored text is
	 * untouched.
	 *
	 * @param string $text       Sanitized text.
	 * @param string $generation Generation slug on the item, or empty.
	 * @param array  $gen_labels Map of generation slug => label.
	 * @return string
	 */
	private static function strip_pill_pollution( $text, $generation, $gen_labels ) {
		if ( '' === $text || '' === $generation || empty( $gen_labels[ $generation ] ) ) {
			return $text;
		}
		$label = $gen_labels[ $generation ];
		// Match optional whitespace + "{Label} Only" (case-insensitive) at end.
		$pattern = '/\s*' . preg_quote( $label, '/' ) . '\s+Only\s*$/i';
		$cleaned = preg_replace( $pattern, '', $text );
		return null === $cleaned ? $text : rtrim( $cleaned );
	}

	/**
	 * Walk a sections array and strip pre-fix "{Label} Only" pollution from
	 * every text-bearing field whose owning item/block has a matching
	 * generation tag. Read-time companion to the save-time cleanup so the
	 * editor displays clean text without requiring an immediate re-save.
	 *
	 * @param array  $sections     Sections array (already JSON-decoded).
	 * @param string $vehicle_slug Vehicle slug used to resolve generation labels.
	 * @return array Sections with polluted text fields cleaned.
	 */
	public static function clean_pill_pollution( $sections, $vehicle_slug ) {
		if ( ! is_array( $sections ) || empty( $sections ) || empty( $vehicle_slug ) ) {
			return $sections;
		}
		$gen_labels = RSU_Platforms::get_generations( $vehicle_slug );
		if ( empty( $gen_labels ) ) {
			return $sections;
		}

		foreach ( $sections as &$section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$section_gen = isset( $section['generation'] ) ? $section['generation'] : '';
			if ( isset( $section['heading'] ) ) {
				$section['heading'] = self::strip_pill_pollution( $section['heading'], $section_gen, $gen_labels );
			}
			if ( ! empty( $section['blocks'] ) && is_array( $section['blocks'] ) ) {
				foreach ( $section['blocks'] as &$block ) {
					self::clean_block_pollution( $block, $gen_labels );
				}
				unset( $block );
			}
		}
		unset( $section );

		return $sections;
	}

	/**
	 * Clean pollution on a single block (paragraph, list, or note).
	 *
	 * @param array $block      Block reference.
	 * @param array $gen_labels Map of generation slug => label.
	 */
	private static function clean_block_pollution( &$block, $gen_labels ) {
		if ( ! is_array( $block ) ) {
			return;
		}
		$type  = isset( $block['type'] ) ? $block['type'] : 'paragraph';
		$gen   = isset( $block['generation'] ) ? $block['generation'] : '';

		if ( 'list' === $type ) {
			if ( ! empty( $block['items'] ) && is_array( $block['items'] ) ) {
				foreach ( $block['items'] as &$item ) {
					if ( is_array( $item ) && isset( $item['text'] ) ) {
						$item_gen   = isset( $item['generation'] ) ? $item['generation'] : '';
						$item['text'] = self::strip_pill_pollution( $item['text'], $item_gen, $gen_labels );
					}
				}
				unset( $item );
			}
			return;
		}

		if ( 'note' === $type ) {
			if ( isset( $block['content'] ) ) {
				$block['content'] = self::strip_pill_pollution( $block['content'], $gen, $gen_labels );
			}
			if ( ! empty( $block['blocks'] ) && is_array( $block['blocks'] ) ) {
				foreach ( $block['blocks'] as &$nb ) {
					self::clean_block_pollution( $nb, $gen_labels );
				}
				unset( $nb );
			}
			return;
		}

		if ( isset( $block['content'] ) ) {
			$block['content'] = self::strip_pill_pollution( $block['content'], $gen, $gen_labels );
		}
	}

	/**
	 * Sanitize sections data from user input.
	 *
	 * Supports generation tags on blocks and list items. The $vehicle_slug
	 * is used to look up generation labels so trailing "{Label} Only" text
	 * left over from the pre-fix HTML parse can be cleaned out.
	 *
	 * @param array  $sections     Raw sections array.
	 * @param string $vehicle_slug Vehicle slug for generation label lookup.
	 * @return array Sanitized sections.
	 */
	private static function sanitize_sections( $sections, $vehicle_slug = '' ) {
		$clean = array();
		$valid_generations = RSU_Platforms::get_all_generation_slugs();
		$gen_labels        = $vehicle_slug ? RSU_Platforms::get_generations( $vehicle_slug ) : array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$section_gen = '';
			if ( ! empty( $section['generation'] ) ) {
				$candidate = sanitize_text_field( $section['generation'] );
				if ( in_array( $candidate, $valid_generations, true ) ) {
					$section_gen = $candidate;
				}
			}

			$heading = isset( $section['heading'] ) ? sanitize_text_field( $section['heading'] ) : '';
			$heading = self::strip_pill_pollution( $heading, $section_gen, $gen_labels );

			$clean_section = array(
				'heading' => $heading,
				'blocks'  => array(),
			);
			if ( $section_gen ) {
				$clean_section['generation'] = $section_gen;
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
						$items = self::sanitize_list_items(
							isset( $block['items'] ) ? $block['items'] : array(),
							$valid_generations,
							$gen_labels
						);

						$clean_block = array(
							'type'  => 'list',
							'items' => $items,
						);
						if ( $generation ) {
							$clean_block['generation'] = $generation;
						}
						$clean_section['blocks'][] = $clean_block;
					} elseif ( 'note' === $type ) {
						// Notes hold nested blocks (paragraphs and lists).
						$note_blocks = array();

						if ( ! empty( $block['blocks'] ) && is_array( $block['blocks'] ) ) {
							foreach ( $block['blocks'] as $nb ) {
								if ( ! is_array( $nb ) ) {
									continue;
								}
								$nb_type = isset( $nb['type'] ) ? sanitize_text_field( $nb['type'] ) : 'paragraph';

								if ( 'list' === $nb_type ) {
									$nb_items = self::sanitize_list_items(
										isset( $nb['items'] ) ? $nb['items'] : array(),
										$valid_generations,
										$gen_labels
									);
									$note_blocks[] = array( 'type' => 'list', 'items' => $nb_items );
								} else {
									$nb_content = isset( $nb['content'] ) ? sanitize_textarea_field( $nb['content'] ) : '';
									$nb_content = self::strip_pill_pollution( $nb_content, $generation, $gen_labels );
									$note_blocks[] = array(
										'type'    => 'paragraph',
										'content' => $nb_content,
									);
								}
							}
						} elseif ( isset( $block['content'] ) && '' !== trim( $block['content'] ) ) {
							// Legacy single-content note: convert to a single paragraph block.
							$legacy = sanitize_textarea_field( $block['content'] );
							$legacy = self::strip_pill_pollution( $legacy, $generation, $gen_labels );
							$note_blocks[] = array(
								'type'    => 'paragraph',
								'content' => $legacy,
							);
						}

						$clean_block = array(
							'type'   => 'note',
							'blocks' => $note_blocks,
						);
						if ( $generation ) {
							$clean_block['generation'] = $generation;
						}
						$clean_section['blocks'][] = $clean_block;
					} else {
						$content = isset( $block['content'] ) ? sanitize_textarea_field( $block['content'] ) : '';
						$content = self::strip_pill_pollution( $content, $generation, $gen_labels );
						$clean_block = array(
							'type'    => $type,
							'content' => $content,
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
						$items    = isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : array();
						$list_html = self::render_list_html( $items, $gen_labels );
						if ( '' !== $list_html ) {
							$html .= $list_html;
							// Block-level pill goes after the list.
							if ( $block_pill ) {
								$html .= '<p class="rsu-list-pill">' . $block_pill . '</p>' . "\n";
							}
						}
						break;

					case 'note':
						$note_blocks = isset( $block['blocks'] ) && is_array( $block['blocks'] ) ? $block['blocks'] : array();

						// Legacy single-content note compatibility.
						if ( empty( $note_blocks ) && isset( $block['content'] ) ) {
							$legacy = trim( $block['content'] );
							if ( '' !== $legacy ) {
								$note_blocks = array( array( 'type' => 'paragraph', 'content' => $legacy ) );
							}
						}

						$note_inner = '';
						foreach ( $note_blocks as $nb ) {
							$nb_type = isset( $nb['type'] ) ? $nb['type'] : 'paragraph';
							if ( 'list' === $nb_type ) {
								$nb_items = isset( $nb['items'] ) && is_array( $nb['items'] ) ? $nb['items'] : array();
								$note_inner .= self::render_list_html( $nb_items, $gen_labels );
							} else {
								$nb_content = isset( $nb['content'] ) ? trim( $nb['content'] ) : '';
								if ( '' !== $nb_content ) {
									$note_inner .= '<p>' . nl2br( esc_html( $nb_content ) ) . '</p>' . "\n";
								}
							}
						}

						if ( '' !== $note_inner ) {
							$html .= '<blockquote><p><strong>' . esc_html( $note_label ) . '</strong>' . $block_pill . '</p>' . "\n";
							$html .= $note_inner;
							$html .= "</blockquote>\n";
						}
						break;
				}
			}
		}

		return $html;
	}

	/**
	 * Render a flat list of items (with optional level 0|1) into nested <ul> HTML.
	 *
	 * Items are walked in order; transitions between levels open/close inner <ul>s.
	 * Orphan first item with level > 0 is promoted to level 0.
	 *
	 * @param array $items      List items.
	 * @param array $gen_labels Map of generation slug => label.
	 * @return string
	 */
	private static function render_list_html( $items, $gen_labels ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return '';
		}

		$html = '';
		$prev = -1;

		foreach ( $items as $item ) {
			$text  = '';
			$gen   = '';
			$level = 0;

			if ( is_array( $item ) ) {
				$text  = isset( $item['text'] ) ? trim( $item['text'] ) : '';
				$gen   = isset( $item['generation'] ) ? $item['generation'] : '';
				$level = isset( $item['level'] ) ? max( 0, min( 1, intval( $item['level'] ) ) ) : 0;
			} else {
				$text = trim( (string) $item );
			}

			if ( '' === $text ) {
				continue;
			}

			// First emitted item must be top-level.
			if ( -1 === $prev ) {
				$level = 0;
			}

			if ( $level > $prev ) {
				for ( $i = $prev; $i < $level; $i++ ) {
					$html .= "<ul>\n";
				}
			} elseif ( $level < $prev ) {
				$html .= "</li>\n";
				for ( $i = $prev; $i > $level; $i-- ) {
					$html .= "</ul></li>\n";
				}
			} elseif ( $prev >= 0 ) {
				$html .= "</li>\n";
			}

			$item_pill = self::render_generation_pill( $gen, $gen_labels );
			$html     .= '<li>' . esc_html( $text ) . $item_pill;

			$prev = $level;
		}

		if ( $prev >= 0 ) {
			$html .= "</li>\n";
			for ( $i = $prev; $i > 0; $i-- ) {
				$html .= "</ul></li>\n";
			}
			$html .= "</ul>\n";
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

		// Sanitize HTML before parsing to prevent XSS.
		$html = wp_kses_post( $html );
		if ( empty( $html ) ) {
			return array();
		}

		// Wrap in a root element for DOMDocument parsing.
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
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

			// Skip non-content elements (ToC nav, video/image embeds, etc.).
			if ( in_array( $tag, array( 'nav', 'figure', 'style', 'script', 'iframe', 'form' ), true ) ) {
				continue;
			}

			if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				if ( null !== $current ) {
					$sections[] = $current;
				}
				$gen = self::extract_generation_from_node( $node );
				$text = self::text_without_pills( $node );
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
				$blocks = self::parse_list_to_blocks( $node );
				foreach ( $blocks as $block ) {
					$current['blocks'][] = $block;
				}
			} elseif ( 'blockquote' === $tag ) {
				$block = array( 'type' => 'note' );
				$gen = self::extract_generation_from_node( $node );
				if ( $gen ) {
					$block['generation'] = $gen;
				}
				$note_blocks = self::parse_note_inner_blocks( $node );
				if ( ! empty( $note_blocks ) ) {
					$block['blocks']     = $note_blocks;
					$current['blocks'][] = $block;
				}
			} elseif ( 'p' === $tag ) {
				$block = array( 'type' => 'paragraph' );
				$gen = self::extract_generation_from_node( $node );
				if ( $gen ) {
					$block['generation'] = $gen;
				}
				$block['content'] = self::text_without_pills( $node );
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
	 * Parse a UL/OL node into an array of blocks.
	 *
	 * Simple items are collected into list blocks. When a <li> contains a nested
	 * <ul>/<ol>, the current list is flushed, the parent text becomes a paragraph
	 * block (sub-heading), and the nested items become a separate list block.
	 *
	 * @param DOMNode $list_node The UL or OL element.
	 * @return array Array of block arrays (list and paragraph blocks).
	 */
	private static function parse_list_to_blocks( $list_node ) {
		$blocks        = array();
		$pending_items = array();

		foreach ( $list_node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$li  = $child;
			$gen = self::extract_generation_from_node( $li );

			// Check if this <li> has a nested <ul> or <ol>.
			$nested_list = null;
			foreach ( $li->childNodes as $li_child ) {
				if ( XML_ELEMENT_NODE === $li_child->nodeType ) {
					$child_tag = strtolower( $li_child->nodeName );
					if ( 'ul' === $child_tag || 'ol' === $child_tag ) {
						$nested_list = $li_child;
						break;
					}
				}
			}

			if ( $nested_list ) {
				// Flush pending simple items as a list block.
				if ( ! empty( $pending_items ) ) {
					$blocks[]      = array( 'type' => 'list', 'items' => $pending_items );
					$pending_items = array();
				}

				// Get the parent's own text: clone the <li>, drop the nested list and
				// any pill spans, then read textContent. This avoids both pill-text
				// leaks and walking children manually.
				$li_clone = $li->cloneNode( true );
				foreach ( iterator_to_array( $li_clone->childNodes ) as $clone_child ) {
					if ( XML_ELEMENT_NODE !== $clone_child->nodeType ) {
						continue;
					}
					$clone_tag = strtolower( $clone_child->nodeName );
					if ( 'ul' === $clone_tag || 'ol' === $clone_tag ) {
						$li_clone->removeChild( $clone_child );
					}
				}
				$parent_text = self::text_without_pills( $li_clone );

				// Emit parent text as a paragraph block (acts as a sub-heading).
				if ( '' !== $parent_text ) {
					$para = array( 'type' => 'paragraph', 'content' => $parent_text );
					if ( $gen ) {
						$para['generation'] = $gen;
					}
					$blocks[] = $para;
				}

				// Recursively parse nested list into blocks.
				$nested_blocks = self::parse_list_to_blocks( $nested_list );
				foreach ( $nested_blocks as $nb ) {
					$blocks[] = $nb;
				}
			} else {
				// Simple <li>: collect into pending items.
				$text = self::text_without_pills( $li );
				if ( '' !== $text ) {
					$item = array( 'text' => $text );
					if ( $gen ) {
						$item['generation'] = $gen;
					}
					$pending_items[] = $item;
				}
			}
		}

		// Flush remaining items.
		if ( ! empty( $pending_items ) ) {
			$blocks[] = array( 'type' => 'list', 'items' => $pending_items );
		}

		return $blocks;
	}

	/**
	 * Extract generation slug from a node's own rsu-gen-pill.
	 *
	 * Searches direct child spans first (where pills sit on headings, paragraphs,
	 * and list items). For blockquotes, falls back to the pill on the first inner
	 * paragraph (the NOTE header) so a block-level pill is found without picking
	 * up generation tags from inner list items.
	 *
	 * @param DOMNode $node DOM node to check.
	 * @return string Generation slug or empty string.
	 */
	private static function extract_generation_from_node( $node ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType
				&& 'span' === strtolower( $child->nodeName )
				&& $child->hasAttribute( 'data-generation' ) ) {
				return $child->getAttribute( 'data-generation' );
			}
		}

		// Blockquote: pill is rendered inside the first <p> (NOTE header).
		if ( 'blockquote' === strtolower( $node->nodeName ) ) {
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				if ( 'p' !== strtolower( $child->nodeName ) ) {
					continue;
				}
				foreach ( $child->childNodes as $grand ) {
					if ( XML_ELEMENT_NODE === $grand->nodeType
						&& 'span' === strtolower( $grand->nodeName )
						&& $grand->hasAttribute( 'data-generation' ) ) {
						return $grand->getAttribute( 'data-generation' );
					}
				}
				return '';
			}
		}

		return '';
	}

	/**
	 * Read a node's text content with all rsu-gen-pill spans removed.
	 *
	 * Pills render as "{Label} Only" (e.g. "Gen 2 Only"), and a naive
	 * textContent read would concatenate that label into the surrounding
	 * bullet/paragraph text. Cloning the node and dropping pill spans first
	 * yields the user-authored text only.
	 *
	 * @param DOMNode $node DOM node.
	 * @return string Trimmed text without pill labels.
	 */
	private static function text_without_pills( $node ) {
		$clone     = $node->cloneNode( true );
		$to_remove = array();
		$spans     = $clone->getElementsByTagName( 'span' );
		foreach ( $spans as $span ) {
			$class = $span->getAttribute( 'class' );
			if ( $span->hasAttribute( 'data-generation' )
				|| ( '' !== $class && false !== strpos( $class, 'rsu-gen-pill' ) ) ) {
				$to_remove[] = $span;
			}
		}
		foreach ( $to_remove as $span ) {
			if ( $span->parentNode ) {
				$span->parentNode->removeChild( $span );
			}
		}
		return trim( $clone->textContent );
	}

	/**
	 * Parse a blockquote (note) into an array of inner blocks.
	 *
	 * The note's header paragraph (containing <strong>NOTE</strong>) is skipped;
	 * remaining <p> become paragraph blocks and <ul>/<ol> are expanded via
	 * parse_list_to_blocks() so multi-block notes round-trip cleanly.
	 *
	 * @param DOMNode $blockquote Blockquote element.
	 * @return array Inner block arrays (paragraph and list).
	 */
	private static function parse_note_inner_blocks( $blockquote ) {
		$blocks         = array();
		$skipped_header = false;

		foreach ( $blockquote->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$tag = strtolower( $child->nodeName );

			// Skip the NOTE header: first <p> containing a <strong>.
			if ( ! $skipped_header && 'p' === $tag ) {
				$strongs = $child->getElementsByTagName( 'strong' );
				if ( $strongs->length > 0 ) {
					$skipped_header = true;
					continue;
				}
			}

			if ( 'p' === $tag ) {
				$skipped_header = true;
				$text = self::text_without_pills( $child );
				$text = preg_replace( '/^\s*NOTE\s*:?\s*/i', '', $text );
				$text = trim( $text );
				if ( '' !== $text ) {
					$blocks[] = array( 'type' => 'paragraph', 'content' => $text );
				}
			} elseif ( 'ul' === $tag || 'ol' === $tag ) {
				$skipped_header = true;
				$list_blocks = self::parse_list_to_blocks( $child );
				foreach ( $list_blocks as $lb ) {
					$blocks[] = $lb;
				}
			}
		}

		return $blocks;
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

		$css_suffix = $suffix;
		$css_file   = RSU_PLUGIN_DIR . 'admin/css/rsu-admin' . $css_suffix . '.css';
		if ( ! file_exists( $css_file ) ) {
			$css_suffix = '';
		}

		wp_enqueue_style(
			'rsu-admin',
			RSU_PLUGIN_URL . 'admin/css/rsu-admin' . $css_suffix . '.css',
			array(),
			RSU_VERSION
		);

		$js_suffix = $suffix;
		$js_file   = RSU_PLUGIN_DIR . 'admin/js/rsu-admin' . $js_suffix . '.js';
		if ( ! file_exists( $js_file ) ) {
			$js_suffix = '';
		}

		wp_enqueue_script(
			'rsu-admin',
			RSU_PLUGIN_URL . 'admin/js/rsu-admin' . $js_suffix . '.js',
			array(),
			RSU_VERSION,
			true
		);
	}
}
