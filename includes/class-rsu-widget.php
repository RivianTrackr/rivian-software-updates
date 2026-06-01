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
			$html = $this->build_html();
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
	private function build_html() {
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

		ob_start();
		?>
		<a href="<?php echo esc_url( $permalink ); ?>" class="rsu-widget-latest"
			aria-label="Latest Rivian software update — <?php echo esc_attr( $version ); ?>">
			<span class="rsu-widget-latest__overlay"></span>

			<span class="rsu-widget-latest__head">
				<span class="rsu-widget-latest__icon">
					<i class="fa-solid fa-cloud-arrow-down"></i>
				</span>
				<span class="rsu-widget-latest__eyebrow">Latest Update</span>
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

			<span class="rsu-widget-latest__btn">Read the Release Notes</span>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Admin form.
	 */
	public function form( $instance ) {
		?>
		<p class="description">The widget automatically shows the latest software update post.</p>
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
