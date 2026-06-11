<?php
/**
 * Widget: Latest Software Update — Displays the most recent OTA version
 * with First Noticed and Public Release dates.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Widget extends WP_Widget {

	/** Base transient key for cached widget HTML (suffixed with the plugin version). */
	const CACHE_KEY = 'rsu_latest_update_widget';

	/**
	 * Version-scoped transient key. Bumping RSU_VERSION changes the key so a
	 * deploy with new markup can never serve HTML cached by an older version.
	 */
	private function cache_key() {
		return self::CACHE_KEY . '_' . RSU_VERSION;
	}

	public function __construct() {
		parent::__construct(
			'rsu_latest_update',
			'Latest Software Update',
			array(
				'description' => 'Displays the latest Rivian OTA update for each vehicle. Vehicles on the same version are combined into one card.',
			)
		);

		// Bust cache when any post is saved (covers publish, update, trash).
		add_action( 'save_post', array( $this, 'flush_cache' ) );
	}

	/**
	 * Front-end display.
	 */
	public function widget( $args, $instance ) {
		$cache_key = $this->cache_key();
		$html      = get_transient( $cache_key );

		if ( false === $html ) {
			$html = $this->build_html();
			set_transient( $cache_key, $html, DAY_IN_SECONDS );
		}

		if ( ! $html ) {
			return;
		}

		$this->enqueue_css();

		echo $args['before_widget'];
		echo $html;
		echo $args['after_widget'];
	}

	/**
	 * Build the widget markup: a single card listing each vehicle's latest
	 * update as its own row, with vehicles that share a version collapsed into
	 * one row.
	 */
	private function build_html() {
		$all_vehicles = RSU_Platforms::get_all();

		// Resolve each vehicle to its latest update post, then group vehicles by
		// the post they land on — vehicles on the same version share one row.
		// Iterating in registry order keeps the rows (and grouped labels) in a
		// stable R1-then-R2 order.
		$groups = array();
		$index  = array();
		foreach ( array_keys( $all_vehicles ) as $slug ) {
			$post_id = $this->latest_post_id( $slug );
			if ( ! $post_id ) {
				continue;
			}
			if ( ! isset( $index[ $post_id ] ) ) {
				$index[ $post_id ] = count( $groups );
				$groups[]          = array( 'post_id' => $post_id, 'slugs' => array() );
			}
			$groups[ $index[ $post_id ] ]['slugs'][] = $slug;
		}

		// Fallback for legacy data with no vehicle tags: show the latest overall
		// update with no vehicle pills, so the widget never renders blank.
		if ( empty( $groups ) ) {
			$post_id = $this->latest_post_id( '' );
			if ( ! $post_id ) {
				return '';
			}
			$groups[] = array( 'post_id' => $post_id, 'slugs' => array() );
		}

		$entries = '';
		foreach ( $groups as $group ) {
			$entries .= $this->render_entry( $group['post_id'], $group['slugs'], $all_vehicles );
		}

		if ( '' === $entries ) {
			return '';
		}

		ob_start();
		?>
		<div class="rsu-widget-latest">
			<span class="rsu-widget-latest__overlay" aria-hidden="true"></span>
			<span class="rsu-widget-latest__header">
				<span class="rsu-widget-latest__icon" aria-hidden="true">💿</span>
				<span class="rsu-widget-latest__title">Latest Software Updates</span>
			</span>
			<div class="rsu-widget-latest__entries">
				<?php echo $entries; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in render_entry(). ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single vehicle's update row within the widget card.
	 *
	 * @param int   $post_id      Update post ID.
	 * @param array $slugs        Vehicle slugs sharing this version (may be empty).
	 * @param array $all_vehicles Platform registry from RSU_Platforms::get_all().
	 * @return string
	 */
	private function render_entry( $post_id, $slugs, $all_vehicles ) {
		$version       = get_the_title( $post_id );
		$permalink     = get_permalink( $post_id );
		$date_noticed  = get_post_meta( $post_id, '_rsu_date_noticed', true );
		$date_released = get_post_meta( $post_id, '_rsu_date_released', true );
		$is_hotfix     = get_post_meta( $post_id, '_rsu_is_hotfix', true );

		$labels = array();
		foreach ( $slugs as $slug ) {
			if ( isset( $all_vehicles[ $slug ]['label'] ) ) {
				$labels[] = $all_vehicles[ $slug ]['label'];
			}
		}

		$noticed_display  = $date_noticed ? date_i18n( 'm/d/Y', strtotime( $date_noticed ) ) : 'TBD';
		$released_display = $date_released ? date_i18n( 'm/d/Y', strtotime( $date_released ) ) : 'TBD';

		ob_start();
		?>
		<a href="<?php echo esc_url( $permalink ); ?>" class="rsu-widget-entry">
			<span class="rsu-widget-entry__head">
				<?php foreach ( $labels as $label ) : ?>
					<span class="rsu-widget-latest__vehicle"><?php echo esc_html( $label ); ?></span>
				<?php endforeach; ?>
				<?php if ( $is_hotfix ) : ?>
					<span class="rsu-widget-entry__tag">Hotfix</span>
				<?php endif; ?>
			</span>

			<span class="rsu-widget-latest__version"><?php echo esc_html( $version ); ?></span>

			<span class="rsu-widget-latest__dates">
				<span class="rsu-widget-latest__meta">
					<span class="rsu-widget-latest__meta-label">First Noticed</span>
					<span class="rsu-widget-latest__meta-value"><?php echo esc_html( $noticed_display ); ?></span>
				</span>
				<span class="rsu-widget-latest__meta">
					<span class="rsu-widget-latest__meta-label">Public Release</span>
					<span class="rsu-widget-latest__meta-value"><?php echo esc_html( $released_display ); ?></span>
				</span>
			</span>

			<span class="rsu-widget-entry__cta">
				Read Release Notes
				<span class="rsu-widget-entry__cta-arrow" aria-hidden="true">→</span>
			</span>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Resolve the latest update post ID for a vehicle (or overall when '').
	 *
	 * @param string $vehicle Vehicle slug, or '' for latest overall.
	 * @return int Post ID, or 0 if none.
	 */
	private function latest_post_id( $vehicle = '' ) {
		$query = $this->query_latest_update( $vehicle );

		if ( $query && ! empty( $query->posts ) ) {
			return (int) $query->posts[0]->ID;
		}

		return 0;
	}

	/**
	 * Query for the latest software update post, optionally scoped to a vehicle.
	 *
	 * @param string $vehicle Vehicle slug, or '' for latest overall.
	 * @return WP_Query
	 */
	private function query_latest_update( $vehicle = '' ) {
		$meta_query = array(
			array(
				'key'   => '_rsu_is_update',
				'value' => '1',
			),
		);

		// `_rsu_vehicles` is stored as a serialized array; matching the quoted
		// slug ("r1") inside it avoids r1/r2 substring collisions and still
		// matches posts tagged for both vehicles.
		if ( '' !== $vehicle ) {
			$meta_query['relation'] = 'AND';
			$meta_query[]           = array(
				'key'     => '_rsu_vehicles',
				'value'   => '"' . $vehicle . '"',
				'compare' => 'LIKE',
			);
		}

		return new WP_Query( array(
			'post_type'      => 'post',
			'posts_per_page' => 1,
			'order'          => 'DESC',
			'orderby'        => 'date',
			'no_found_rows'  => true,
			'meta_query'     => $meta_query,
		) );
	}

	/**
	 * Admin form.
	 */
	public function form( $instance ) {
		?>
		<p class="description">Shows the latest update for each vehicle automatically. Vehicles on the same version are combined into a single card.</p>
		<?php
	}

	/**
	 * Save admin form.
	 */
	public function update( $new_instance, $old_instance ) {
		$this->flush_cache();
		return array();
	}

	/**
	 * Delete the cached widget HTML.
	 */
	public function flush_cache() {
		delete_transient( $this->cache_key() );
	}

	/**
	 * Enqueue the frontend stylesheet.
	 */
	private function enqueue_css() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$css_file = RSU_PLUGIN_DIR . 'frontend/css/rsu-frontend' . $suffix . '.css';
		if ( ! file_exists( $css_file ) ) {
			$suffix = '';
		}

		wp_enqueue_style(
			'rsu-frontend',
			RSU_PLUGIN_URL . 'frontend/css/rsu-frontend' . $suffix . '.css',
			array(),
			RSU_VERSION
		);
	}
}
