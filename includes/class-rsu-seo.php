<?php
/**
 * SEO title and heading enhancements for software update posts.
 *
 * When enabled, update posts get a keyword-rich, templated indexable <title>
 * (across the common SEO plugins plus a core fallback) and a descriptive
 * on-page H1, while the stored post title stays a clean version string
 * (e.g. "2026.20") for the widget, timeline, and structured data.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_SEO {

	public function __construct() {
		// 301 the software-update category archive to the canonical updates page.
		if ( RSU_Settings::get( 'redirect_category_enabled', false ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_category_archive' ) );
		}

		if ( RSU_Settings::get( 'seo_titles_enabled', false ) ) {
			// On-page H1 (the theme renders it via the_title()).
			add_filter( 'the_title', array( $this, 'filter_h1' ), 10, 2 );

			// Indexable <title> across the common SEO plugins, with a core fallback.
			add_filter( 'aioseo_title', array( $this, 'filter_seo_title' ) );
			add_filter( 'wpseo_title', array( $this, 'filter_seo_title' ) );
			add_filter( 'rank_math/frontend/title', array( $this, 'filter_seo_title' ) );
			add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ) );
		}
	}

	/**
	 * Permanently redirect the software-update category archive (and its
	 * pagination) to the canonical updates page, set by the Updates Archive
	 * Slug. Individual posts are untouched — only the category listing.
	 */
	public function redirect_category_archive() {
		if ( is_admin() ) {
			return;
		}

		$slug = trim( (string) RSU_Settings::get( 'redirect_category_slug', 'software-update' ) );
		if ( '' === $slug || ! is_category( $slug ) ) {
			return;
		}

		$dest = trim( (string) RSU_Settings::get( 'archive_slug', '/software-updates/' ) );
		if ( '' === $dest ) {
			return;
		}

		$target = home_url( '/' === $dest[0] ? $dest : '/' . $dest );

		// Guard against redirecting onto the same URL (avoids a loop).
		if ( untrailingslashit( $target ) === untrailingslashit( home_url( add_query_arg( array() ) ) ) ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Format the on-page H1 for the current update post only.
	 *
	 * Scoped to the main query's singular post heading in the loop, and to the
	 * queried post itself, so it never touches menus, widgets, the document
	 * title, or the plugin's own internal title reads (which use the raw title).
	 *
	 * @param string $title   The post title.
	 * @param int    $post_id The post ID (passed by the the_title filter).
	 * @return string
	 */
	public function filter_h1( $title, $post_id = 0 ) {
		if ( is_admin() || ! in_the_loop() || ! is_main_query() || ! is_singular( 'post' ) ) {
			return $title;
		}

		if ( (int) $post_id !== get_queried_object_id() || ! $this->is_update( $post_id ) ) {
			return $title;
		}

		$format = RSU_Settings::get( 'seo_h1_format', '' );
		if ( '' === trim( (string) $format ) ) {
			return $title;
		}

		return $this->apply_format( $format, $post_id );
	}

	/**
	 * Replace the indexable <title> for update posts (SEO-plugin filters).
	 *
	 * @param string $title Title from the SEO plugin.
	 * @return string
	 */
	public function filter_seo_title( $title ) {
		$post_id = $this->current_update_id();
		if ( ! $post_id ) {
			return $title;
		}

		$format = RSU_Settings::get( 'seo_title_format', '' );
		if ( '' === trim( (string) $format ) ) {
			return $title;
		}

		return $this->apply_format( $format, $post_id );
	}

	/**
	 * Core document-title fallback for themes without an SEO plugin managing
	 * the title. Replaces only the title part so the site's separator and name
	 * are preserved.
	 *
	 * @param array $parts Document title parts.
	 * @return array
	 */
	public function filter_document_title_parts( $parts ) {
		$post_id = $this->current_update_id();
		if ( ! $post_id ) {
			return $parts;
		}

		$format = RSU_Settings::get( 'seo_title_format', '' );
		if ( '' === trim( (string) $format ) ) {
			return $parts;
		}

		$parts['title'] = $this->apply_format( $format, $post_id );
		return $parts;
	}

	/**
	 * The queried post ID when viewing a single software-update post, else 0.
	 *
	 * @return int
	 */
	private function current_update_id() {
		if ( is_admin() || ! is_singular( 'post' ) ) {
			return 0;
		}

		$post_id = get_queried_object_id();
		return ( $post_id && $this->is_update( $post_id ) ) ? $post_id : 0;
	}

	/**
	 * Whether a post is a software-update post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_update( $post_id ) {
		return (bool) get_post_meta( $post_id, '_rsu_is_update', true );
	}

	/**
	 * Resolve %version% (the raw stored title) and %sitename% in a format string.
	 *
	 * Reads the raw post_title rather than get_the_title() so it is immune to
	 * the H1 filter and always yields the clean version (e.g. "2026.20").
	 *
	 * @param string $format  Format template.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private function apply_format( $format, $post_id ) {
		$replacements = array(
			'%version%'  => get_post_field( 'post_title', $post_id ),
			'%sitename%' => get_bloginfo( 'name' ),
		);

		return trim( strtr( $format, $replacements ) );
	}
}
