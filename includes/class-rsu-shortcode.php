<?php
/**
 * Shortcode: [rsu_history] — Displays a table of all software update posts.
 *
 * Attributes:
 *   limit  — Max rows to show (default: all, -1).
 *   order  — "desc" (newest first, default) or "asc".
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Shortcode {

	public function __construct() {
		add_shortcode( 'rsu_history', array( $this, 'render' ) );
	}

	/**
	 * Render the [rsu_history] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array(
			'limit' => -1,
			'order' => 'DESC',
		), $atts, 'rsu_history' );

		$all_vehicles = RSU_Platforms::get_all();

		$query = new WP_Query( array(
			'post_type'      => 'post',
			'posts_per_page' => intval( $atts['limit'] ),
			'order'          => strtoupper( $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC',
			'orderby'        => 'date',
			'meta_query'     => array(
				array(
					'key'   => '_rsu_is_update',
					'value' => '1',
				),
			),
		) );

		if ( ! $query->have_posts() ) {
			return '<p class="rsu-history__empty">No software updates found.</p>';
		}

		// Enqueue styles.
		$this->enqueue_css();

		ob_start();
		?>
		<div class="rsu-history">
			<table class="rsu-history__table">
				<thead>
					<tr>
						<th>OTA Version</th>
						<th>First Noticed</th>
						<th>Public Release</th>
						<th>Available For</th>
					</tr>
				</thead>
				<tbody>
					<?php while ( $query->have_posts() ) : $query->the_post();
						$post_id       = get_the_ID();
						$version       = get_the_title();
						$permalink     = get_permalink();
						$date_noticed  = get_post_meta( $post_id, '_rsu_date_noticed', true );
						$date_released = get_post_meta( $post_id, '_rsu_date_released', true );
						$vehicles      = RSU_Platforms::get_active( $post_id );

						// Build vehicle labels.
						$vehicle_labels = array();
						foreach ( $vehicles as $slug ) {
							if ( isset( $all_vehicles[ $slug ] ) ) {
								$vehicle_labels[] = $all_vehicles[ $slug ]['label'];
							}
						}
						?>
						<tr>
							<td class="rsu-history__version">
								<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $version ); ?></a>
							</td>
							<td class="rsu-history__date">
								<?php if ( $date_noticed ) : ?>
									<time datetime="<?php echo esc_attr( $date_noticed ); ?>">
										<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $date_noticed ) ) ); ?>
									</time>
								<?php else : ?>
									<span class="rsu-history__na">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="rsu-history__date">
								<?php if ( $date_released ) : ?>
									<time datetime="<?php echo esc_attr( $date_released ); ?>">
										<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $date_released ) ) ); ?>
									</time>
								<?php else : ?>
									<span class="rsu-history__na">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="rsu-history__vehicles">
								<?php foreach ( $vehicle_labels as $label ) : ?>
									<span class="rsu-history__vehicle-badge"><?php echo esc_html( $label ); ?></span>
								<?php endforeach; ?>
								<?php if ( empty( $vehicle_labels ) ) : ?>
									<span class="rsu-history__na">&mdash;</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Enqueue the frontend stylesheet for the history table.
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
