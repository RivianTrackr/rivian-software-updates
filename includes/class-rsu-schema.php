<?php
/**
 * Schema.org JSON-LD structured data for software update posts.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Schema {

	public function __construct() {
		add_action( 'wp_footer', array( $this, 'output_structured_data' ) );
	}

	/**
	 * Output JSON-LD for software update posts.
	 */
	public function output_structured_data() {
		if ( ! RSU_Settings::get( 'schema_enabled', true ) ) {
			return;
		}

		if ( ! is_singular( RSU_Post_Type::SLUG ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return;
		}

		$post    = get_post( $post_id );
		$version = get_post_meta( $post_id, '_rsu_version', true );
		$date_released = get_post_meta( $post_id, '_rsu_date_released', true );
		$active_platforms = RSU_Platforms::get_active( $post_id );
		$all_platforms    = RSU_Platforms::get_all();

		// Build article sections from h3 headings in the content.
		$sections = $this->extract_sections( $post_id, $active_platforms, $all_platforms );

		// Build platform description.
		$platform_labels = array();
		foreach ( $active_platforms as $slug ) {
			if ( isset( $all_platforms[ $slug ] ) ) {
				$platform_labels[] = $all_platforms[ $slug ]['label'];
			}
		}

		$org_name = RSU_Settings::get( 'organization_name', 'RivianTrackr' );

		$description = sprintf(
			'Release notes for Rivian software update %s covering %s vehicles.',
			$version ? $version : get_the_title( $post_id ),
			implode( ' and ', $platform_labels )
		);

		$graph = array();

		// TechArticle.
		$article = array(
			'@type'         => 'TechArticle',
			'headline'      => get_the_title( $post_id ),
			'description'   => $description,
			'datePublished' => $date_released ? $date_released : get_the_date( 'Y-m-d', $post_id ),
			'dateModified'  => get_the_modified_date( 'Y-m-d', $post_id ),
			'author'        => array(
				'@type' => 'Organization',
				'name'  => $org_name,
				'url'   => home_url( '/' ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => $org_name,
				'url'   => home_url( '/' ),
			),
			'url'           => get_permalink( $post_id ),
			'mainEntityOfPage' => get_permalink( $post_id ),
		);

		if ( $version ) {
			$article['about'] = array(
				'@type'               => 'SoftwareApplication',
				'name'                => 'Rivian Vehicle Software',
				'softwareVersion'     => $version,
				'operatingSystem'     => 'Rivian OS',
				'applicationCategory' => 'DriverApplication',
			);
		}

		if ( ! empty( $sections ) ) {
			$article['articleSection'] = $sections;
		}

		$graph[] = $article;

		$archive_slug = RSU_Settings::get( 'archive_slug', '/software-updates/' );

		// BreadcrumbList.
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(
				array(
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => 'Home',
					'item'     => home_url( '/' ),
				),
				array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => 'Software Updates',
					'item'     => home_url( $archive_slug ),
				),
				array(
					'@type'    => 'ListItem',
					'position' => 3,
					'name'     => $version ? $version : get_the_title( $post_id ),
				),
			),
		);

		$schema = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo '<script type="application/ld+json">';
		echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		echo '</script>' . "\n";
	}

	/**
	 * Extract h3 heading text from platform content for articleSection.
	 */
	private function extract_sections( $post_id, $active_platforms, $all_platforms ) {
		$sections = array();

		foreach ( $active_platforms as $slug ) {
			if ( ! isset( $all_platforms[ $slug ] ) ) {
				continue;
			}

			$content = get_post_meta( $post_id, $all_platforms[ $slug ]['meta_key'], true );
			if ( ! $content ) {
				continue;
			}

			if ( preg_match_all( '/<h[23][^>]*>([^<]+)<\/h[23]>/i', $content, $matches ) ) {
				foreach ( $matches[1] as $heading ) {
					$heading = trim( wp_strip_all_tags( $heading ) );
					if ( $heading && ! in_array( $heading, $sections, true ) ) {
						$sections[] = $heading;
					}
				}
			}
		}

		return $sections;
	}
}
