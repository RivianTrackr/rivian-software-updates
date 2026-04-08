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
		add_filter( 'aioseo_schema_output', array( $this, 'enrich_aioseo_schema' ), 10, 1 );
	}

	public function enrich_aioseo_schema( $schema ) {
		if ( ! is_singular( 'post' ) ) {
			return $schema;
		}

		$post_id = get_the_ID();
		if ( ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return $schema;
		}

		$version          = get_the_title( $post_id );
		$active_vehicles  = RSU_Platforms::get_active( $post_id );
		$all_vehicles     = RSU_Platforms::get_all();
		$sections         = $this->extract_sections( $post_id, $active_vehicles, $all_vehicles );
		$description      = $this->build_description( $version, $active_vehicles, $all_vehicles );

		if ( isset( $schema['@graph'] ) && is_array( $schema['@graph'] ) ) {
			foreach ( $schema['@graph'] as &$node ) {
				if ( ! isset( $node['@type'] ) ) {
					continue;
				}

				$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );

				if ( array_intersect( $type, array( 'Article', 'BlogPosting', 'WebPage' ) ) ) {
					$node['@type']       = 'TechArticle';
					$node['description'] = $description;

					if ( ! empty( $version ) ) {
						$node['about'] = array(
							'@type'               => 'SoftwareApplication',
							'name'                => 'Rivian Vehicle Software',
							'softwareVersion'     => $version,
							'operatingSystem'     => 'Rivian OS',
							'applicationCategory' => 'DriverApplication',
						);
					}

					if ( ! empty( $sections ) ) {
						$node['articleSection'] = $sections;
					}
				}
			}
			unset( $node );
		}

		return $schema;
	}

	public function output_structured_data() {
		if ( ! RSU_Settings::get( 'schema_enabled', true ) ) {
			return;
		}

		if ( function_exists( 'aioseo' ) ) {
			return;
		}

		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return;
		}

		$version          = get_the_title( $post_id );
		$date_released    = get_post_meta( $post_id, '_rsu_date_released', true );
		$active_vehicles  = RSU_Platforms::get_active( $post_id );
		$all_vehicles     = RSU_Platforms::get_all();
		$sections         = $this->extract_sections( $post_id, $active_vehicles, $all_vehicles );
		$description      = $this->build_description( $version, $active_vehicles, $all_vehicles );
		$org_name         = RSU_Settings::get( 'organization_name', 'RivianTrackr' );

		$graph = array();

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

		if ( ! empty( $version ) ) {
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
					'item'     => get_permalink( $post_id ),
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
	 * Build a description string including vehicle and generation info.
	 */
	private function build_description( $version, $active_vehicles, $all_vehicles ) {
		$vehicle_parts = array();
		foreach ( $active_vehicles as $slug ) {
			if ( ! isset( $all_vehicles[ $slug ] ) ) {
				continue;
			}
			$v = $all_vehicles[ $slug ];
			$label = $v['label'];
			if ( ! empty( $v['generations'] ) && count( $v['generations'] ) > 1 ) {
				$gen_labels = array();
				foreach ( $v['generations'] as $gen ) {
					$gen_labels[] = $gen['label'];
				}
				$label .= ' (' . implode( ', ', $gen_labels ) . ')';
			}
			$vehicle_parts[] = $label;
		}

		return sprintf(
			'Release notes for Rivian software update %s covering %s vehicles.',
			$version ? $version : 'unknown',
			implode( ' and ', $vehicle_parts )
		);
	}

	private function extract_sections( $post_id, $active_vehicles, $all_vehicles ) {
		$sections = array();

		foreach ( $active_vehicles as $slug ) {
			if ( ! isset( $all_vehicles[ $slug ] ) ) {
				continue;
			}

			// Prefer structured JSON — handles all heading levels correctly.
			$sections_json = get_post_meta( $post_id, '_rsu_sections_' . $slug, true );
			if ( $sections_json ) {
				$parsed = json_decode( $sections_json, true );
				if ( is_array( $parsed ) && json_last_error() === JSON_ERROR_NONE ) {
					foreach ( $parsed as $section ) {
						$heading = isset( $section['heading'] ) ? trim( $section['heading'] ) : '';
						if ( $heading && ! in_array( $heading, $sections, true ) ) {
							$sections[] = $heading;
						}
					}
					continue;
				}
			}

			// Fallback: parse headings from pre-rendered HTML for legacy posts.
			$content = get_post_meta( $post_id, $all_vehicles[ $slug ]['meta_key'], true );
			if ( ! $content ) {
				continue;
			}

			if ( preg_match_all( '/<h[2-6][^>]*>([^<]+)<\/h[2-6]>/i', $content, $matches ) ) {
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
