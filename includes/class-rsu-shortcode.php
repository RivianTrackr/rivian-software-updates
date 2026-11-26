<?php
/**
 * Shortcode: [rsu_history] — The software update timeline.
 *
 * A card for the newest release, then one line per release grouped by year.
 * Hotfixes are folded into the release they patch ("2 hotfixes") instead of
 * getting rows of their own; per-platform build numbers live on each post.
 *
 * Attributes:
 *   limit  — Max update posts to read (default: all, -1).
 *   order  — "desc" (newest first, default) or "asc". Applies to the years
 *            and to the rows within each year, by public release date.
 *   years  — How many years show before a "Show … and earlier" button
 *            (default 2; 0 shows every year). Without JavaScript every year
 *            shows.
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
			'years' => 2,
		), $atts, 'rsu_history' );

		$all_vehicles = RSU_Platforms::get_all();
		$ascending    = ( 'ASC' === strtoupper( $atts['order'] ) );

		$query = new WP_Query( array(
			'post_type'      => 'post',
			'posts_per_page' => intval( $atts['limit'] ),
			'order'          => $ascending ? 'ASC' : 'DESC',
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

		$this->enqueue_css();
		$this->enqueue_js();

		// Every row's place on the timeline is its public release date, falling
		// back to the first-noticed date, then the post date.
		$rows = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id       = get_the_ID();
			$date_noticed  = get_post_meta( $post_id, '_rsu_date_noticed', true );
			$date_released = get_post_meta( $post_id, '_rsu_date_released', true );

			$released = RSU_Dates::timestamp( $date_released );
			$when     = $released ? $released : RSU_Dates::timestamp( $date_noticed );
			if ( ! $when ) {
				$when = (int) get_post_time( 'U', false, $post_id );
			}

			$vehicle_labels = array();
			foreach ( RSU_Platforms::get_active( $post_id ) as $slug ) {
				if ( isset( $all_vehicles[ $slug ] ) ) {
					$vehicle_labels[] = $all_vehicles[ $slug ]['label'];
				}
			}

			$is_hotfix = (bool) get_post_meta( $post_id, '_rsu_is_hotfix', true );

			$rows[ $post_id ] = array(
				'when'           => $when,
				'post_time'      => (int) get_post_time( 'U', false, $post_id ),
				'version'        => get_the_title(),
				'permalink'      => get_permalink(),
				'released'       => (bool) $released,
				'vehicle_labels' => $vehicle_labels,
				'parent_id'      => $is_hotfix ? (int) get_post_meta( $post_id, '_rsu_parent_release', true ) : 0,
				'fixes'          => 0,
			);
		}
		wp_reset_postdata();

		// Fold each hotfix into the release it patches. A hotfix whose base
		// release is not on the timeline keeps a row of its own.
		$releases = array();
		foreach ( $rows as $post_id => $row ) {
			if ( $row['parent_id'] && isset( $rows[ $row['parent_id'] ] ) ) {
				continue;
			}
			$releases[ $post_id ] = $row;
		}
		foreach ( $rows as $row ) {
			if ( $row['parent_id'] && isset( $releases[ $row['parent_id'] ] ) ) {
				$releases[ $row['parent_id'] ]['fixes']++;
			}
		}

		// Ties fall back to post date so same-day releases keep publish order.
		$releases = array_values( $releases );
		usort( $releases, function ( $a, $b ) use ( $ascending ) {
			$cmp = $a['when'] <=> $b['when'];
			if ( 0 === $cmp ) {
				$cmp = $a['post_time'] <=> $b['post_time'];
			}
			return $ascending ? $cmp : -$cmp;
		} );

		$latest = $ascending ? $releases[ count( $releases ) - 1 ] : $releases[0];

		$grouped = array();
		foreach ( $releases as $release ) {
			$grouped[ date_i18n( 'Y', $release['when'] ) ][] = $release;
		}

		// Years past the first few are folded behind a button by the script.
		$years_shown = max( 0, intval( $atts['years'] ) );
		$year_keys   = array_map( 'strval', array_keys( $grouped ) );
		$older       = ( $years_shown && count( $year_keys ) > $years_shown ) ? array_slice( $year_keys, $years_shown ) : array();
		$uid         = wp_unique_id( 'rsu-history-' );

		ob_start();
		?>
		<div class="rsu-history" id="<?php echo esc_attr( $uid ); ?>">
			<div class="rsu-history__latest">
				<div class="rsu-history__latest-text">
					<p class="rsu-history__latest-label">Latest update</p>
					<p class="rsu-history__latest-version"><?php echo esc_html( $latest['version'] ); ?></p>
					<p class="rsu-history__latest-meta">
						<?php
						echo esc_html( ( $latest['released'] ? 'Released ' : 'First noticed ' ) . date_i18n( 'F j, Y', $latest['when'] ) );
						if ( $latest['vehicle_labels'] ) {
							echo ' &middot; ' . esc_html( $this->join_and( $latest['vehicle_labels'] ) );
						}
						?>
					</p>
				</div>
				<a class="rsu-history__latest-btn" href="<?php echo esc_url( $latest['permalink'] ); ?>">Read the release notes</a>
			</div>

			<?php foreach ( $grouped as $year => $year_releases ) : ?>
				<?php
				$year     = (string) $year;
				$is_older = in_array( $year, $older, true );
				$head_id  = $uid . '-' . $year;
				$count    = count( $year_releases );
				?>
				<section class="rsu-history__year<?php echo $is_older ? ' rsu-history__year--older' : ''; ?>" aria-labelledby="<?php echo esc_attr( $head_id ); ?>">
					<div class="rsu-history__year-head">
						<h2 class="rsu-history__year-label" id="<?php echo esc_attr( $head_id ); ?>" tabindex="-1"><?php echo esc_html( $year ); ?></h2>
						<span class="rsu-history__year-count"><?php echo esc_html( sprintf( '%d release%s', $count, 1 === $count ? '' : 's' ) ); ?></span>
					</div>
					<ul class="rsu-history__list">
						<?php foreach ( $year_releases as $release ) : ?>
							<li class="rsu-history__row">
								<a class="rsu-history__version" href="<?php echo esc_url( $release['permalink'] ); ?>"><?php echo esc_html( $release['version'] ); ?></a>
								<span class="rsu-history__fixes"><?php echo $release['fixes'] ? esc_html( sprintf( '%d hotfix%s', $release['fixes'], 1 === $release['fixes'] ? '' : 'es' ) ) : ''; ?></span>
								<span class="rsu-history__vehicles"><?php echo esc_html( implode( ', ', $release['vehicle_labels'] ) ); ?></span>
								<time class="rsu-history__date" datetime="<?php echo esc_attr( gmdate( 'Y-m-d', $release['when'] ) ); ?>"<?php echo $release['released'] ? '' : ' title="First noticed"'; ?>><?php echo esc_html( date_i18n( 'M j', $release['when'] ) ); ?></time>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>

			<?php if ( $older ) : ?>
				<div class="rsu-history__more" hidden>
					<button type="button" class="rsu-history__more-btn" aria-expanded="false"><?php echo esc_html( sprintf( 'Show %s and earlier', $older[0] ) ); ?></button>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * "A", "A and B", "A, B and C".
	 *
	 * @param string[] $items Labels.
	 * @return string
	 */
	private function join_and( array $items ) {
		$last = array_pop( $items );
		return $items ? implode( ', ', $items ) . ' and ' . $last : (string) $last;
	}

	/**
	 * Enqueue the shared frontend stylesheet (with the accent override).
	 */
	private function enqueue_css() {
		RSU_Frontend::enqueue_styles();
	}

	/**
	 * Enqueue the script behind the "Show … and earlier" button.
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
