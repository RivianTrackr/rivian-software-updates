<?php
/**
 * Migration tool for converting Essential Blocks toggle content to RSU sections.
 *
 * Parses the old Gen 1 / Gen 2 toggle HTML from post_content, diffs the two
 * generation contents, and writes a merged _rsu_sections_r1 JSON with
 * generation pills where the content differs.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Migrate {

	/**
	 * Migrate a single post from Essential Blocks toggle format to RSU sections.
	 *
	 * @param int  $post_id  Post ID.
	 * @param bool $dry_run  If true, return result without saving.
	 * @return array|WP_Error Migration result or error.
	 */
	/**
	 * Migrate a single post from Essential Blocks toggle format to RSU sections.
	 *
	 * @param int  $post_id  Post ID.
	 * @param bool $dry_run  If true, return result without saving.
	 * @param bool $force    If true, overwrite existing sections data.
	 * @return array|WP_Error Migration result or error.
	 */
	public static function migrate_post( $post_id, $dry_run = false, $force = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		// Check if already migrated (skip unless forced).
		if ( ! $force ) {
			$existing = get_post_meta( $post_id, '_rsu_sections_r1', true );
			if ( $existing && ! empty( json_decode( $existing, true ) ) ) {
				return new WP_Error( 'already_migrated', 'Post already has _rsu_sections_r1 data. Use force to overwrite.' );
			}
		}

		$content = $post->post_content;

		// Extract the two toggle wrapper contents (Gen 1 = primary, Gen 2 = secondary).
		$gen1_html = self::extract_toggle_content( $content, 'primary' );
		$gen2_html = self::extract_toggle_content( $content, 'secondary' );

		if ( empty( $gen1_html ) && empty( $gen2_html ) ) {
			return new WP_Error( 'no_toggle', 'No Essential Blocks toggle content found.' );
		}

		// Parse both into section arrays.
		$gen1_sections = ! empty( $gen1_html ) ? RSU_Admin::parse_html_to_sections( $gen1_html ) : array();
		$gen2_sections = ! empty( $gen2_html ) ? RSU_Admin::parse_html_to_sections( $gen2_html ) : array();

		// Merge with intelligent generation tagging.
		$merged = self::merge_generations( $gen1_sections, $gen2_sections );

		if ( empty( $merged ) ) {
			return new WP_Error( 'empty_result', 'Merged sections are empty.' );
		}

		$result = array(
			'post_id'  => $post_id,
			'title'    => $post->post_title,
			'sections' => $merged,
			'stats'    => array(
				'gen1_sections' => count( $gen1_sections ),
				'gen2_sections' => count( $gen2_sections ),
				'merged'        => count( $merged ),
			),
		);

		if ( ! $dry_run ) {
			// Save sections JSON.
			update_post_meta( $post_id, '_rsu_sections_r1', wp_json_encode( $merged ) );

			// Render and save HTML fallback.
			$html = RSU_Admin::render_sections_to_html( $merged, 'r1' );
			update_post_meta( $post_id, '_rsu_content_r1', wp_kses_post( $html ) );

			// Ensure the post is marked as an update with R1 active.
			update_post_meta( $post_id, '_rsu_is_update', '1' );
			$vehicles = get_post_meta( $post_id, '_rsu_vehicles', true );
			if ( ! is_array( $vehicles ) || ! in_array( 'r1', $vehicles, true ) ) {
				update_post_meta( $post_id, '_rsu_vehicles', array( 'r1' ) );
			}

			$result['saved'] = true;
		}

		return $result;
	}

	/**
	 * Extract the inner content HTML for a toggle side (primary or secondary).
	 *
	 * Primary = first eb-wrapper (Gen 1), Secondary = second eb-wrapper (Gen 2).
	 *
	 * @param string $html       Full post_content HTML.
	 * @param string $side       'primary' or 'secondary'.
	 * @return string Inner HTML or empty string.
	 */
	public static function extract_toggle_content( $html, $side ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<html><body>' . mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$xpath    = new DOMXPath( $doc );
		// Find all eb-wrapper-inner-blocks containers.
		$wrappers = $xpath->query( "//div[contains(@class, 'eb-wrapper-inner-blocks')]" );

		if ( ! $wrappers || $wrappers->length === 0 ) {
			return '';
		}

		$index = ( 'secondary' === $side ) ? 1 : 0;
		if ( $index >= $wrappers->length ) {
			return '';
		}

		$wrapper    = $wrappers->item( $index );
		$inner_html = '';
		foreach ( $wrapper->childNodes as $child ) {
			$inner_html .= $doc->saveHTML( $child );
		}

		return $inner_html;
	}

	/**
	 * Merge Gen 1 and Gen 2 section arrays into a single array with generation tags.
	 *
	 * Strategy:
	 * - Match sections by heading (normalized).
	 * - Sections present in both with identical content: no generation tag (shared).
	 * - Sections present in both with different content: merge blocks with per-block/item tagging.
	 * - Sections only in Gen 1: tag entire section as gen1.
	 * - Sections only in Gen 2: tag entire section as gen2.
	 *
	 * @param array $gen1 Sections from Gen 1.
	 * @param array $gen2 Sections from Gen 2.
	 * @return array Merged sections.
	 */
	public static function merge_generations( $gen1, $gen2 ) {
		// Build lookup maps keyed by normalized heading.
		$gen1_map = array();
		$gen2_map = array();
		$gen1_order = array();
		$gen2_order = array();

		foreach ( $gen1 as $section ) {
			$key = self::normalize_heading( $section['heading'] );
			$gen1_map[ $key ] = $section;
			$gen1_order[] = $key;
		}
		foreach ( $gen2 as $section ) {
			$key = self::normalize_heading( $section['heading'] );
			$gen2_map[ $key ] = $section;
			$gen2_order[] = $key;
		}

		// Build ordered output — use Gen 1 order as base, inserting Gen 2 only sections
		// at their relative position.
		$merged    = array();
		$processed = array();

		// Process in Gen 1 order first.
		foreach ( $gen1_order as $key ) {
			$processed[ $key ] = true;
			$s1 = $gen1_map[ $key ];

			if ( isset( $gen2_map[ $key ] ) ) {
				$s2 = $gen2_map[ $key ];
				// Both generations have this section — merge.
				$merged[] = self::merge_section( $s1, $s2 );
			} else {
				// Gen 1 only.
				$s1['generation'] = 'gen1';
				$merged[] = $s1;
			}
		}

		// Append Gen 2 only sections at the end.
		foreach ( $gen2_order as $key ) {
			if ( ! isset( $processed[ $key ] ) ) {
				$s2 = $gen2_map[ $key ];
				$s2['generation'] = 'gen2';
				$merged[] = $s2;
			}
		}

		return $merged;
	}

	/**
	 * Merge two sections (same heading) with generation tagging on differing content.
	 *
	 * @param array $s1 Gen 1 section.
	 * @param array $s2 Gen 2 section.
	 * @return array Merged section.
	 */
	private static function merge_section( $s1, $s2 ) {
		$merged = array(
			'heading' => $s2['heading'],
			'blocks'  => array(),
		);

		$blocks1 = isset( $s1['blocks'] ) ? $s1['blocks'] : array();
		$blocks2 = isset( $s2['blocks'] ) ? $s2['blocks'] : array();

		// Try to match blocks by position and type.
		$max = max( count( $blocks1 ), count( $blocks2 ) );

		$i1 = 0;
		$i2 = 0;

		while ( $i1 < count( $blocks1 ) || $i2 < count( $blocks2 ) ) {
			$b1 = isset( $blocks1[ $i1 ] ) ? $blocks1[ $i1 ] : null;
			$b2 = isset( $blocks2[ $i2 ] ) ? $blocks2[ $i2 ] : null;

			// Both blocks exist at this position.
			if ( $b1 && $b2 && $b1['type'] === $b2['type'] ) {
				if ( self::blocks_equal( $b1, $b2 ) ) {
					// Identical — no generation tag.
					$merged['blocks'][] = $b1;
				} elseif ( 'list' === $b1['type'] ) {
					// Merge list items with per-item generation tagging.
					$merged['blocks'][] = self::merge_list_blocks( $b1, $b2 );
				} else {
					// Different paragraph or note — include both tagged.
					$b1['generation'] = 'gen1';
					$b2['generation'] = 'gen2';
					$merged['blocks'][] = $b1;
					$merged['blocks'][] = $b2;
				}
				$i1++;
				$i2++;
			} elseif ( $b1 && ! $b2 ) {
				// Gen 1 only block.
				$b1['generation'] = 'gen1';
				$merged['blocks'][] = $b1;
				$i1++;
			} elseif ( $b2 && ! $b1 ) {
				// Gen 2 only block.
				$b2['generation'] = 'gen2';
				$merged['blocks'][] = $b2;
				$i2++;
			} else {
				// Type mismatch — include both tagged.
				if ( $b1 ) {
					$b1['generation'] = 'gen1';
					$merged['blocks'][] = $b1;
				}
				if ( $b2 ) {
					$b2['generation'] = 'gen2';
					$merged['blocks'][] = $b2;
				}
				$i1++;
				$i2++;
			}
		}

		return $merged;
	}

	/**
	 * Merge two list blocks with per-item generation tagging.
	 *
	 * Items that appear in both lists (by normalized text similarity) are shared.
	 * Items unique to one list get that generation's tag.
	 *
	 * @param array $list1 Gen 1 list block.
	 * @param array $list2 Gen 2 list block.
	 * @return array Merged list block.
	 */
	private static function merge_list_blocks( $list1, $list2 ) {
		$items1 = isset( $list1['items'] ) ? $list1['items'] : array();
		$items2 = isset( $list2['items'] ) ? $list2['items'] : array();

		$merged_items = array();
		$used2        = array();

		foreach ( $items1 as $item1 ) {
			$text1  = self::item_text( $item1 );
			$norm1  = self::normalize_text( $text1 );
			$match  = null;
			$best_similarity = 0;

			foreach ( $items2 as $j => $item2 ) {
				if ( isset( $used2[ $j ] ) ) {
					continue;
				}
				$text2 = self::item_text( $item2 );
				$norm2 = self::normalize_text( $text2 );

				// Exact match.
				if ( $norm1 === $norm2 ) {
					$match = $j;
					$best_similarity = 1.0;
					break;
				}

				// Fuzzy match — check if texts are similar enough.
				similar_text( $norm1, $norm2, $percent );
				if ( $percent > 80 && $percent > $best_similarity ) {
					$match = $j;
					$best_similarity = $percent;
				}
			}

			if ( null !== $match ) {
				$used2[ $match ] = true;
				$text2 = self::item_text( $items2[ $match ] );

				if ( self::normalize_text( $text1 ) === self::normalize_text( $text2 ) ) {
					// Identical — shared item, no tag.
					$merged_items[] = array( 'text' => $text1 );
				} else {
					// Similar but different wording — include both tagged.
					$merged_items[] = array( 'text' => $text1, 'generation' => 'gen1' );
					$merged_items[] = array( 'text' => $text2, 'generation' => 'gen2' );
				}
			} else {
				// Gen 1 only item.
				$merged_items[] = array( 'text' => $text1, 'generation' => 'gen1' );
			}
		}

		// Remaining Gen 2 only items.
		foreach ( $items2 as $j => $item2 ) {
			if ( ! isset( $used2[ $j ] ) ) {
				$merged_items[] = array( 'text' => self::item_text( $item2 ), 'generation' => 'gen2' );
			}
		}

		return array(
			'type'  => 'list',
			'items' => $merged_items,
		);
	}

	/**
	 * Check if two blocks are content-equal (ignoring generation tags).
	 */
	private static function blocks_equal( $b1, $b2 ) {
		if ( $b1['type'] !== $b2['type'] ) {
			return false;
		}

		if ( 'list' === $b1['type'] ) {
			$items1 = isset( $b1['items'] ) ? $b1['items'] : array();
			$items2 = isset( $b2['items'] ) ? $b2['items'] : array();
			if ( count( $items1 ) !== count( $items2 ) ) {
				return false;
			}
			foreach ( $items1 as $i => $item ) {
				if ( self::normalize_text( self::item_text( $item ) ) !== self::normalize_text( self::item_text( $items2[ $i ] ) ) ) {
					return false;
				}
			}
			return true;
		}

		$c1 = isset( $b1['content'] ) ? $b1['content'] : '';
		$c2 = isset( $b2['content'] ) ? $b2['content'] : '';
		return self::normalize_text( $c1 ) === self::normalize_text( $c2 );
	}

	/**
	 * Get text from a list item (handles both string and object format).
	 */
	private static function item_text( $item ) {
		return is_array( $item ) ? ( isset( $item['text'] ) ? $item['text'] : '' ) : (string) $item;
	}

	/**
	 * Normalize heading for matching.
	 */
	private static function normalize_heading( $heading ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $heading ) ) );
	}

	/**
	 * Normalize text for comparison (lowercase, collapse whitespace).
	 */
	private static function normalize_text( $text ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $text ) ) );
	}

	/**
	 * Get all posts that have Essential Blocks toggle content but no RSU sections.
	 *
	 * @return array Array of post objects.
	 */
	/**
	 * Get all posts that have Essential Blocks toggle content.
	 *
	 * @param bool $force If true, include already-migrated posts.
	 * @return array Array of post objects.
	 */
	public static function get_migratable_posts( $force = false ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_content
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'post'
			   AND p.post_status IN ('publish', 'draft')
			   AND p.post_content LIKE '%eb-toggle%'
			 ORDER BY p.post_date DESC"
		);

		if ( $force ) {
			return $posts;
		}

		$migratable = array();
		foreach ( $posts as $post ) {
			// Skip posts that already have sections.
			$existing = get_post_meta( $post->ID, '_rsu_sections_r1', true );
			if ( $existing && ! empty( json_decode( $existing, true ) ) ) {
				continue;
			}
			$migratable[] = $post;
		}

		return $migratable;
	}

	/**
	 * Migrate all eligible posts.
	 *
	 * @param bool $dry_run If true, don't save anything.
	 * @return array Results for each post.
	 */
	/**
	 * Migrate all eligible posts.
	 *
	 * @param bool $dry_run If true, don't save anything.
	 * @param bool $force   If true, re-migrate already-migrated posts.
	 * @return array Results for each post.
	 */
	public static function migrate_all( $dry_run = false, $force = false ) {
		$posts   = self::get_migratable_posts( $force );
		$results = array();

		foreach ( $posts as $post ) {
			$results[] = self::migrate_post( $post->ID, $dry_run, $force );
		}

		return $results;
	}
}
