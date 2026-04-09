<?php
/**
 * Widget: Latest Software Update — Displays the most recent OTA version
 * with First Noticed and Public Release dates.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Widget extends WP_Widget {

	/** Transient key for cached widget HTML. */
	const CACHE_KEY = 'rsu_latest_update_widget';

	public function __construct() {
		parent::__construct(
			'rsu_latest_update',
			'Latest Software Update',
			array(
				'description' => 'Displays the latest Rivian OTA software update with dates.',
			)
		);

		// Bust cache when any post is saved (covers publish, update, trash).
		add_action( 'save_post', array( $this, 'flush_cache' ) );
	}

	/**
	 * Front-end display.
	 */
	public function widget( $args, $instance ) {
		$html = get_transient( self::CACHE_KEY );

		if ( false === $html ) {
			$html = $this->build_html( $instance );
			set_transient( self::CACHE_KEY, $html, DAY_IN_SECONDS );
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
	 */
	private function build_html( $instance ) {
		$query = new WP_Query( array(
			'post_type'      => 'post',
			'posts_per_page' => 1,
			'order'          => 'DESC',
			'orderby'        => 'date',
			'meta_query'     => array(
				array(
					'key'   => '_rsu_is_update',
					'value' => '1',
				),
			),
		) );

		if ( ! $query->have_posts() ) {
			return '';
		}

		$query->the_post();
		$post_id       = get_the_ID();
		$version       = get_the_title();
		$permalink     = get_permalink();
		$date_noticed  = get_post_meta( $post_id, '_rsu_date_noticed', true );
		$date_released = get_post_meta( $post_id, '_rsu_date_released', true );
		wp_reset_postdata();

		$noticed_display  = $date_noticed ? date_i18n( 'm/d/Y', strtotime( $date_noticed ) ) : 'TBD';
		$released_display = $date_released ? date_i18n( 'm/d/Y', strtotime( $date_released ) ) : 'TBD';

		$title = ! empty( $instance['title'] ) ? esc_html( $instance['title'] ) : '';

		ob_start();
		?>
		<a href="<?php echo esc_url( $permalink ); ?>" class="rsu-widget-latest">
			<div class="rsu-widget-latest__icon">
				<i class="fa-solid fa-cloud-arrow-down"></i>
			</div>
			<div class="rsu-widget-latest__body">
				<span class="rsu-widget-latest__version"><?php echo esc_html( $version ); ?></span>
				<span class="rsu-widget-latest__meta">First Noticed: <?php echo esc_html( $noticed_display ); ?></span>
				<span class="rsu-widget-latest__meta">Public Release: <?php echo esc_html( $released_display ); ?></span>
			</div>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Admin form.
	 */
	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title (optional):</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p class="description">The widget automatically shows the latest software update post.</p>
		<?php
	}

	/**
	 * Save admin form.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		$this->flush_cache();
		return $instance;
	}

	/**
	 * Delete the cached widget HTML.
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
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
