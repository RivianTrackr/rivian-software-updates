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
	 * Version-scoped transient key, further scoped by the selected vehicle so
	 * each instance (R1, R2, or Automatic) caches independently and can never
	 * serve another vehicle's HTML. Bumping RSU_VERSION changes the key so a
	 * deploy with new markup can never serve HTML cached by an older version.
	 *
	 * @param string $vehicle Vehicle slug, or '' for Automatic (latest overall).
	 */
	private function cache_key( $vehicle = '' ) {
		$suffix = '' !== $vehicle ? $vehicle : 'all';
		return self::CACHE_KEY . '_' . RSU_VERSION . '_' . $suffix;
	}

	public function __construct() {
		parent::__construct(
			'rsu_latest_update',
			'Latest Software Update',
			array(
				'description' => 'Displays the latest Rivian OTA software update with dates. Add one per vehicle (R1, R2) and pick the vehicle in the widget settings.',
			)
		);

		// Bust cache when any post is saved (covers publish, update, trash).
		add_action( 'save_post', array( $this, 'flush_cache' ) );
	}

	/**
	 * Front-end display.
	 */
	public function widget( $args, $instance ) {
		$vehicle   = $this->get_instance_vehicle( $instance );
		$cache_key = $this->cache_key( $vehicle );
		$html      = get_transient( $cache_key );

		if ( false === $html ) {
			$html = $this->build_html( $vehicle );
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
	 * Build the widget markup from the latest update post.
	 *
	 * @param string $vehicle Vehicle slug to scope to, or '' for latest overall.
	 */
	private function build_html( $vehicle = '' ) {
		$query = $this->query_latest_update( $vehicle );

		// Scoped query found nothing (e.g. a vehicle with no tagged posts yet);
		// fall back to the latest overall update so the widget never renders blank.
		if ( '' !== $vehicle && ( ! $query || ! $query->have_posts() ) ) {
			$query   = $this->query_latest_update( '' );
			$vehicle = '';
		}

		if ( ! $query || ! $query->have_posts() ) {
			return '';
		}

		$query->the_post();
		$post_id       = get_the_ID();
		$version       = get_the_title();
		$permalink     = get_permalink();
		$date_noticed  = get_post_meta( $post_id, '_rsu_date_noticed', true );
		$date_released = get_post_meta( $post_id, '_rsu_date_released', true );
		$is_hotfix     = get_post_meta( $post_id, '_rsu_is_hotfix', true );
		wp_reset_postdata();

		// Name the vehicle in the eyebrow so stacked R1/R2 cards are never
		// ambiguous — including when both resolve to the same shared version.
		$vehicle_label = '';
		if ( '' !== $vehicle ) {
			$all = RSU_Platforms::get_all();
			if ( isset( $all[ $vehicle ]['label'] ) ) {
				$vehicle_label = $all[ $vehicle ]['label'] . ' ';
			}
		}

		$eyebrow = $is_hotfix
			? 'Latest ' . $vehicle_label . 'Hotfix'
			: 'Latest ' . $vehicle_label . 'Software Update';

		$noticed_display  = $date_noticed ? date_i18n( 'm/d/Y', strtotime( $date_noticed ) ) : 'TBD';
		$released_display = $date_released ? date_i18n( 'm/d/Y', strtotime( $date_released ) ) : 'TBD';

		ob_start();
		?>
		<a href="<?php echo esc_url( $permalink ); ?>" class="rsu-widget-latest">
			<span class="rsu-widget-latest__overlay" aria-hidden="true"></span>

			<span class="rsu-widget-latest__head">
				<span class="rsu-widget-latest__icon" aria-hidden="true">💿</span>
				<span class="rsu-widget-latest__eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
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

			<span class="rsu-widget-latest__btn">Read Release Notes</span>
		</a>
		<?php
		return ob_get_clean();
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
	 * Resolve and validate the vehicle slug saved on a widget instance.
	 *
	 * @param array $instance Widget instance settings.
	 * @return string Valid vehicle slug, or '' for Automatic (latest overall).
	 */
	private function get_instance_vehicle( $instance ) {
		$vehicle = isset( $instance['vehicle'] ) ? (string) $instance['vehicle'] : '';

		if ( '' !== $vehicle && ! isset( RSU_Platforms::get_all()[ $vehicle ] ) ) {
			return '';
		}

		return $vehicle;
	}

	/**
	 * Admin form.
	 */
	public function form( $instance ) {
		$selected = $this->get_instance_vehicle( $instance );
		$field_id = $this->get_field_id( 'vehicle' );
		?>
		<p>
			<label for="<?php echo esc_attr( $field_id ); ?>">Vehicle:</label>
			<select
				id="<?php echo esc_attr( $field_id ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'vehicle' ) ); ?>"
				class="widefat"
			>
				<option value="" <?php selected( $selected, '' ); ?>>Automatic — latest overall</option>
				<?php foreach ( RSU_Platforms::get_all() as $slug => $vehicle ) : ?>
					<?php
					$option_label = $vehicle['label'];
					if ( ! empty( $vehicle['description'] ) && $vehicle['description'] !== $vehicle['label'] ) {
						$option_label .= ' (' . $vehicle['description'] . ')';
					}
					?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>>
						<?php echo esc_html( $option_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Shows the latest software update post tagged for the selected vehicle. Add the widget once per vehicle.</p>
		<?php
	}

	/**
	 * Save admin form.
	 */
	public function update( $new_instance, $old_instance ) {
		$this->flush_cache();

		$vehicle = isset( $new_instance['vehicle'] ) ? sanitize_text_field( $new_instance['vehicle'] ) : '';

		if ( '' !== $vehicle && ! isset( RSU_Platforms::get_all()[ $vehicle ] ) ) {
			$vehicle = '';
		}

		return array( 'vehicle' => $vehicle );
	}

	/**
	 * Delete the cached widget HTML for every vehicle variant plus Automatic.
	 */
	public function flush_cache() {
		delete_transient( $this->cache_key() );

		foreach ( array_keys( RSU_Platforms::get_all() ) as $slug ) {
			delete_transient( $this->cache_key( $slug ) );
		}
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
