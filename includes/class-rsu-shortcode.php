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

		// Enqueue styles and the filter script.
		$this->enqueue_css();
		$this->enqueue_js();

		// Group posts by year based on public release date.
		$grouped          = array();
		$present_vehicles = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id       = get_the_ID();
			$date_released = get_post_meta( $post_id, '_rsu_date_released', true );
			$year          = $date_released ? date_i18n( 'Y', strtotime( $date_released ) ) : get_the_date( 'Y' );

			if ( ! isset( $grouped[ $year ] ) ) {
				$grouped[ $year ] = array();
			}

			$vehicles       = RSU_Platforms::get_active( $post_id );
			$vehicle_labels = array();
			$vehicle_slugs  = array();
			foreach ( $vehicles as $slug ) {
				if ( isset( $all_vehicles[ $slug ] ) ) {
					$vehicle_labels[]            = $all_vehicles[ $slug ]['label'];
					$vehicle_slugs[]             = $slug;
					$present_vehicles[ $slug ]   = true;
				}
			}

			// Hotfix metadata: flag and per-generation build numbers.
			$is_hotfix   = (bool) get_post_meta( $post_id, '_rsu_is_hotfix', true );
			$builds_meta = get_post_meta( $post_id, '_rsu_hotfix_builds', true );
			$builds      = array();
			if ( is_array( $builds_meta ) ) {
				// Prefix labels with the vehicle name only when more than one
				// vehicle carries builds; otherwise the "Available For" column
				// already identifies the vehicle and the prefix is just noise.
				$vehicles_with_builds = 0;
				foreach ( $builds_meta as $v_slug => $gens ) {
					if ( isset( $all_vehicles[ $v_slug ] ) && is_array( $gens ) && array_filter( $gens, 'strlen' ) ) {
						$vehicles_with_builds++;
					}
				}
				$prefix_vehicle = $vehicles_with_builds > 1;

				foreach ( $builds_meta as $v_slug => $gens ) {
					if ( ! isset( $all_vehicles[ $v_slug ] ) || ! is_array( $gens ) ) {
						continue;
					}
					$v_label  = $all_vehicles[ $v_slug ]['label'];
					$gen_defs = ! empty( $all_vehicles[ $v_slug ]['generations'] ) ? $all_vehicles[ $v_slug ]['generations'] : array();
					$multi    = count( $gen_defs ) > 1;
					foreach ( $gens as $g_slug => $build ) {
						if ( '' === trim( (string) $build ) ) {
							continue;
						}
						$parts = array();
						if ( $prefix_vehicle ) {
							$parts[] = $v_label;
						}
						if ( $multi && isset( $gen_defs[ $g_slug ]['label'] ) ) {
							$parts[] = $gen_defs[ $g_slug ]['label'];
						}
						$builds[] = array(
							'label' => implode( ' ', $parts ),
							'value' => $build,
						);
					}
				}
			}

			$grouped[ $year ][] = array(
				'version'        => get_the_title(),
				'permalink'      => get_permalink(),
				'date_noticed'   => get_post_meta( $post_id, '_rsu_date_noticed', true ),
				'date_released'  => $date_released,
				'vehicle_labels' => $vehicle_labels,
				'vehicle_slugs'  => $vehicle_slugs,
				'is_hotfix'      => $is_hotfix,
				'builds'         => $builds,
			);
		}
		wp_reset_postdata();

		// Sort years descending so the latest year is first.
		krsort( $grouped );

		$is_first = true;

		// Filter chips appear only when more than one vehicle is present across
		// the timeline — a lone vehicle needs no filter. Ordered by the platform
		// registry sort so it matches the tabs and widget.
		$filter_vehicles = array();
		foreach ( $all_vehicles as $slug => $vehicle ) {
			if ( isset( $present_vehicles[ $slug ] ) ) {
				$filter_vehicles[ $slug ] = $vehicle['label'];
			}
		}
		$show_filter = count( $filter_vehicles ) > 1;

		ob_start();
		?>
		<div class="rsu-history">
			<?php if ( $show_filter ) : ?>
				<div class="rsu-history__filter" role="group" aria-label="Filter updates by vehicle">
					<button type="button" class="rsu-history__filter-btn rsu-history__filter-btn--active" data-vehicle="all" aria-pressed="true">All Vehicles</button>
					<?php foreach ( $filter_vehicles as $slug => $label ) : ?>
						<button type="button" class="rsu-history__filter-btn" data-vehicle="<?php echo esc_attr( $slug ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $grouped as $year => $posts ) : ?>
				<details class="rsu-history__year" <?php echo $is_first ? 'open' : ''; ?>>
					<summary class="rsu-history__year-header">
						<span class="rsu-history__year-label"><?php echo esc_html( $year ); ?></span>
						<span class="rsu-history__year-count"><?php echo count( $posts ); ?> update<?php echo count( $posts ) !== 1 ? 's' : ''; ?></span>
						<i class="fa-solid fa-chevron-down rsu-history__year-chevron"></i>
					</summary>
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
							<?php foreach ( $posts as $post_data ) : ?>
								<tr data-vehicles="<?php echo esc_attr( implode( ' ', $post_data['vehicle_slugs'] ) ); ?>">
									<td class="rsu-history__version">
										<span class="rsu-history__version-row">
											<a href="<?php echo esc_url( $post_data['permalink'] ); ?>"><?php echo esc_html( $post_data['version'] ); ?></a>
											<?php if ( $post_data['is_hotfix'] ) : ?>
												<span class="rsu-history__hotfix-badge">Hotfix</span>
											<?php endif; ?>
										</span>
										<?php if ( ! empty( $post_data['builds'] ) ) : ?>
											<span class="rsu-history__builds">
												<?php foreach ( $post_data['builds'] as $build ) : ?>
													<span class="rsu-history__build">
														<?php if ( '' !== $build['label'] ) : ?>
															<span class="rsu-history__build-label"><?php echo esc_html( $build['label'] ); ?></span>
														<?php endif; ?>
														<span class="rsu-history__build-value"><?php echo esc_html( $build['value'] ); ?></span>
													</span>
												<?php endforeach; ?>
											</span>
										<?php endif; ?>
									</td>
									<td class="rsu-history__date" data-label="First Noticed">
										<?php if ( $post_data['date_noticed'] ) : ?>
											<time datetime="<?php echo esc_attr( $post_data['date_noticed'] ); ?>">
												<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $post_data['date_noticed'] ) ) ); ?>
											</time>
										<?php else : ?>
											<span class="rsu-history__na">&mdash;</span>
										<?php endif; ?>
									</td>
									<td class="rsu-history__date" data-label="Public Release">
										<?php if ( $post_data['date_released'] ) : ?>
											<time datetime="<?php echo esc_attr( $post_data['date_released'] ); ?>">
												<?php echo esc_html( date_i18n( 'M j, Y', strtotime( $post_data['date_released'] ) ) ); ?>
											</time>
										<?php else : ?>
											<span class="rsu-history__na">&mdash;</span>
										<?php endif; ?>
									</td>
									<td class="rsu-history__vehicles">
										<?php foreach ( $post_data['vehicle_labels'] as $label ) : ?>
											<span class="rsu-history__vehicle-badge"><?php echo esc_html( $label ); ?></span>
										<?php endforeach; ?>
										<?php if ( empty( $post_data['vehicle_labels'] ) ) : ?>
											<span class="rsu-history__na">&mdash;</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php $is_first = false; ?>
			<?php endforeach; ?>
		</div>
		<?php
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

	/**
	 * Enqueue the vehicle-filter script for the history timeline.
	 */
	private function enqueue_js() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$js_file = RSU_PLUGIN_DIR . 'frontend/js/rsu-history' . $suffix . '.js';
		if ( ! file_exists( $js_file ) ) {
			$suffix = '';
		}

		wp_enqueue_script(
			'rsu-history',
			RSU_PLUGIN_URL . 'frontend/js/rsu-history' . $suffix . '.js',
			array(),
			RSU_VERSION,
			true
		);
	}
}
