<?php
/**
 * Frontend rendering for software update posts.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

class RSU_Frontend {

	private $should_enqueue = false;

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_css' ) );
		add_filter( 'the_content', array( $this, 'render_update_content' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'fallback_excerpt' ), 5, 2 );
		add_action( 'wp_footer', array( $this, 'maybe_enqueue_js' ) );
		add_filter( 'aioseo_description_context', array( $this, 'aioseo_clean_content' ) );
		add_filter( 'aioseo_og_description_context', array( $this, 'aioseo_clean_content' ) );
		add_filter( 'aioseo_twitter_description_context', array( $this, 'aioseo_clean_content' ) );
	}

	public function aioseo_clean_content( $content ) {
		if ( ! is_singular( 'post' ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return $content;
		}

		$text = self::default_vehicle_text( $post_id );

		return '' !== $text ? $text : $content;
	}

	/**
	 * Give update posts an excerpt when none was written.
	 *
	 * Release notes live in post meta, not post_content, so archives, feeds,
	 * related-post cards, and social previews otherwise get an empty excerpt.
	 * Runs before core's wp_trim_excerpt (priority 10), which leaves a
	 * non-empty excerpt alone.
	 *
	 * @param string       $excerpt The post excerpt.
	 * @param WP_Post|null $post    Post object.
	 * @return string
	 */
	public function fallback_excerpt( $excerpt, $post = null ) {
		if ( '' !== trim( (string) $excerpt ) || is_admin() ) {
			return $excerpt;
		}

		$post = get_post( $post );
		if ( ! $post || 'post' !== $post->post_type || ! get_post_meta( $post->ID, '_rsu_is_update', true ) ) {
			return $excerpt;
		}

		// A hand-written body is the best summary when there is one.
		$body = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		$text = '' !== $body ? $body : self::default_vehicle_text( $post->ID );

		if ( '' === $text ) {
			return $excerpt;
		}

		$length = (int) apply_filters( 'excerpt_length', 55 );
		$more   = apply_filters( 'excerpt_more', ' [&hellip;]' );

		return wp_trim_words( $text, $length, $more );
	}

	/**
	 * Plain text of the default vehicle's release notes (build chips excluded).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function default_vehicle_text( $post_id ) {
		$active_vehicles = RSU_Platforms::get_active( $post_id );
		if ( empty( $active_vehicles ) ) {
			return '';
		}

		$default = RSU_Platforms::get_default();
		if ( ! in_array( $default, $active_vehicles, true ) ) {
			$default = $active_vehicles[0];
		}

		$html = self::vehicle_html( $post_id, $default );
		if ( '' === $html ) {
			return '';
		}

		// Keep sentence breaks between blocks so trimmed excerpts read cleanly.
		$html = preg_replace( '#</(h[1-6]|p|li|blockquote|section)>#i', ' ', $html );

		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/**
	 * Rendered release-notes HTML for one vehicle.
	 *
	 * Rendered from the sections JSON so settings like heading level apply
	 * immediately, falling back to the pre-rendered HTML of legacy posts.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @return string
	 */
	public static function vehicle_html( $post_id, $slug ) {
		$all_vehicles = RSU_Platforms::get_all();
		if ( ! isset( $all_vehicles[ $slug ] ) ) {
			return '';
		}

		$sections = self::vehicle_sections( $post_id, $slug );
		if ( ! empty( $sections ) ) {
			$html = RSU_Admin::render_sections_to_html( $sections, $slug );
			if ( '' !== $html ) {
				return $html;
			}
		}

		return (string) get_post_meta( $post_id, $all_vehicles[ $slug ]['meta_key'], true );
	}

	/**
	 * Decoded sections JSON for one vehicle, or an empty array.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Vehicle slug.
	 * @return array
	 */
	private static function vehicle_sections( $post_id, $slug ) {
		$sections_json = get_post_meta( $post_id, '_rsu_sections_' . $slug, true );
		if ( ! $sections_json ) {
			return array();
		}

		$sections = json_decode( $sections_json, true );

		return ( is_array( $sections ) && ! empty( $sections ) ) ? $sections : array();
	}

	public function render_update_content( $content ) {
		// Feeds get the notes inline: post_content is usually empty on an
		// update post, and a feed item with nothing in it is useless.
		if ( is_feed() ) {
			return $this->render_feed_content( $content );
		}

		if ( ! is_singular( 'post' ) || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return $content;
		}

		$active_vehicles = RSU_Platforms::get_active( $post_id );
		if ( empty( $active_vehicles ) ) {
			return $content;
		}

		$all_vehicles   = RSU_Platforms::get_all();
		$default        = RSU_Platforms::get_default();
		// Raw title (not get_the_title) so the optional SEO H1 filter never
		// rewrites the version used in markup/data attributes here.
		$version        = get_post_field( 'post_title', $post_id );
		$date_noticed   = get_post_meta( $post_id, '_rsu_date_noticed', true );
		$date_released  = get_post_meta( $post_id, '_rsu_date_released', true );

		$is_hotfix      = get_post_meta( $post_id, '_rsu_is_hotfix', true );
		$parent_id      = (int) get_post_meta( $post_id, '_rsu_parent_release', true );
		$builds         = RSU_Builds::get( $post_id );
		$parent_link = ( $is_hotfix && $parent_id && 'publish' === get_post_status( $parent_id ) )
			? array(
				'title' => get_the_title( $parent_id ),
				'url'   => get_permalink( $parent_id ),
			)
			: null;

		// A base release lists the patches that shipped on top of it, so the
		// relationship reads both ways: the hotfix points up, the base points
		// down. Each entry carries the exact builds the patch delivered.
		$patches = array();
		if ( ! $is_hotfix ) {
			foreach ( RSU_Builds::get_patches( $post_id ) as $patch ) {
				$released  = get_post_meta( $patch->ID, '_rsu_date_released', true );
				$noticed   = get_post_meta( $patch->ID, '_rsu_date_noticed', true );
				$when      = $released ? $released : $noticed;
				$patches[] = array(
					'title'  => get_the_title( $patch->ID ),
					'url'    => get_permalink( $patch->ID ),
					'date'   => RSU_Dates::format( $when ),
					'builds' => RSU_Builds::describe( RSU_Builds::get( $patch->ID ) ),
				);
			}
		}

		if ( ! in_array( $default, $active_vehicles, true ) ) {
			$default = $active_vehicles[0];
		}

		// Anything written in the post editor is an editorial intro. It used
		// to be discarded here, which meant text that showed in feeds and
		// archives never appeared on the page itself.
		$intro = trim( $content );
		if ( '' === trim( wp_strip_all_tags( $intro ) ) ) {
			$intro = '';
		}

		$this->should_enqueue = true;

		ob_start();
		?>
		<div class="rsu-update" data-rsu-version="<?php echo esc_attr( $version ); ?>" data-rsu-default="<?php echo esc_attr( $default ); ?>">

			<?php if ( $is_hotfix ) : ?>
				<div class="rsu-hotfix-banner">
					<span class="rsu-hotfix-banner__badge">Hotfix</span>
					<?php if ( $parent_link ) : ?>
						<span class="rsu-hotfix-banner__text">
							Patch for <a href="<?php echo esc_url( $parent_link['url'] ); ?>"><?php echo esc_html( $parent_link['title'] ); ?></a>
						</span>
						<a class="rsu-hotfix-banner__link" href="<?php echo esc_url( $parent_link['url'] ); ?>">
							Full <?php echo esc_html( $parent_link['title'] ); ?> release notes
							<span aria-hidden="true">&rarr;</span>
						</a>
					<?php else : ?>
						<span class="rsu-hotfix-banner__text">Patch release</span>
					<?php endif; ?>
				</div>
			<?php elseif ( ! empty( $patches ) ) : ?>
				<div class="rsu-patches">
					<span class="rsu-patches__label">
						<span class="rsu-patches__badge"><?php echo 1 === count( $patches ) ? 'Patched' : (int) count( $patches ) . ' patches'; ?></span>
						This release was followed by
					</span>
					<?php foreach ( $patches as $patch ) : ?>
						<a class="rsu-patch" href="<?php echo esc_url( $patch['url'] ); ?>">
							<span class="rsu-patch__title"><?php echo esc_html( $patch['title'] ); ?></span>
							<?php if ( ! empty( $patch['builds'] ) ) : ?>
								<span class="rsu-patch__builds">
									<?php foreach ( $patch['builds'] as $build ) : ?>
										<span class="rsu-patch__build">
											<?php if ( '' !== $build['label'] ) : ?>
												<span class="rsu-patch__build-label"><?php echo esc_html( $build['label'] ); ?></span>
											<?php endif; ?>
											<span class="rsu-patch__build-value"><?php echo esc_html( $build['value'] ); ?></span>
										</span>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
							<?php if ( '' !== $patch['date'] ) : ?>
								<span class="rsu-patch__date"><?php echo esc_html( $patch['date'] ); ?></span>
							<?php endif; ?>
							<span class="rsu-patch__arrow" aria-hidden="true">&rarr;</span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php
			// With only one active vehicle there are no tabs, so nothing else
			// tells the reader which vehicle this update is for. Surface it as
			// an "Available For" pill alongside the dates.
			$single_vehicle = ( count( $active_vehicles ) === 1 );
			$solo_label     = '';
			if ( $single_vehicle ) {
				$solo_vehicle = $all_vehicles[ $active_vehicles[0] ];
				$solo_label   = $solo_vehicle['label'];
				if ( ! empty( $solo_vehicle['description'] ) && $solo_vehicle['description'] !== $solo_vehicle['label'] ) {
					$solo_label .= ' · ' . $solo_vehicle['description'];
				}
			}
			?>

			<?php // Public Release always renders (TBD when unset), so the row always shows. ?>
				<div class="rsu-dates">
					<?php if ( $single_vehicle ) : ?>
						<span class="rsu-date rsu-date--vehicle">
							<span class="rsu-date__label">Available For</span>
							<span class="rsu-date__vehicle"><?php echo esc_html( $solo_label ); ?></span>
						</span>
					<?php endif; ?>

					<?php if ( $date_noticed ) : ?>
						<span class="rsu-date rsu-date--noticed">
							<span class="rsu-date__label">First Noticed</span>
							<time datetime="<?php echo esc_attr( $date_noticed ); ?>">
								<?php echo esc_html( RSU_Dates::format( $date_noticed ) ); ?>
							</time>
						</span>
					<?php endif; ?>

					<span class="rsu-date rsu-date--released">
						<span class="rsu-date__label">Public Release</span>
						<?php if ( $date_released ) : ?>
							<time datetime="<?php echo esc_attr( $date_released ); ?>">
								<?php echo esc_html( RSU_Dates::format( $date_released ) ); ?>
							</time>
						<?php else : ?>
							<span class="rsu-date__tbd">TBD</span>
						<?php endif; ?>
					</span>
				</div>

			<?php if ( '' !== $intro ) : ?>
				<div class="rsu-intro">
					<?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post_content already filtered by the_content. ?>
				</div>
			<?php endif; ?>

			<?php if ( count( $active_vehicles ) > 1 ) : ?>
				<div class="rsu-tabs" role="tablist" aria-label="Vehicle model" aria-orientation="horizontal">
					<?php foreach ( $active_vehicles as $slug ) :
						$vehicle    = $all_vehicles[ $slug ];
						$is_default = ( $slug === $default );
						?>
						<button class="rsu-tab <?php echo $is_default ? 'rsu-tab--active' : ''; ?>"
							role="tab"
							type="button"
							aria-selected="<?php echo $is_default ? 'true' : 'false'; ?>"
							aria-controls="rsu-panel-<?php echo esc_attr( $slug ); ?>"
							id="rsu-tab-<?php echo esc_attr( $slug ); ?>"
							data-platform="<?php echo esc_attr( $slug ); ?>">
							<?php echo esc_html( $vehicle['label'] ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php
			foreach ( $active_vehicles as $slug ) :
				$vehicle    = $all_vehicles[ $slug ];
				$is_default = ( $slug === $default );

				$sections        = self::vehicle_sections( $post_id, $slug );
				$vehicle_content = self::vehicle_html( $post_id, $slug );
				$anchors         = ! empty( $sections ) ? RSU_Admin::section_anchors( $sections, $slug ) : array();
				$gen_labels      = RSU_Platforms::get_generations( $slug );
				$multi_gen       = count( $gen_labels ) > 1;

				// The generation filter only earns its space when the notes
				// actually carry generation-specific items. Legacy HTML posts
				// that were never re-saved have pills but no filterable
				// wrappers, so they get no control.
				$has_gen_items = $multi_gen
					&& false !== strpos( $vehicle_content, 'class="rsu-section"' )
					&& false !== strpos( $vehicle_content, 'rsu-gen-pill' );

				$adjacent = $this->adjacent_updates( $post_id, $slug );
				?>
				<div class="rsu-panel <?php echo $is_default ? 'rsu-panel--active' : ''; ?> <?php echo $single_vehicle ? 'rsu-panel--solo' : ''; ?>"
					role="tabpanel"
					tabindex="0"
					id="rsu-panel-<?php echo esc_attr( $slug ); ?>"
					aria-labelledby="rsu-tab-<?php echo esc_attr( $slug ); ?>"
					data-platform="<?php echo esc_attr( $slug ); ?>"
					<?php echo $is_default ? '' : 'hidden'; ?>>
					<div class="rsu-panel__content">
						<?php
						$v_builds = isset( $builds[ $slug ] ) && is_array( $builds[ $slug ] ) ? $builds[ $slug ] : array();
						if ( $v_builds ) :
							?>
							<div class="rsu-builds">
								<?php foreach ( $v_builds as $g_slug => $build ) : ?>
									<span class="rsu-build">
										<?php if ( $multi_gen && isset( $gen_labels[ $g_slug ] ) ) : ?>
											<span class="rsu-build__label"><?php echo esc_html( $gen_labels[ $g_slug ] ); ?></span>
										<?php endif; ?>
										<span class="rsu-build__value"><?php echo esc_html( $build ); ?></span>
									</span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php if ( $has_gen_items ) : ?>
							<div class="rsu-gen-filter" role="group" aria-label="Show notes for <?php echo esc_attr( $vehicle['label'] ); ?> generation">
								<span class="rsu-gen-filter__label">Show notes for</span>
								<div class="rsu-gen-filter__group">
									<button type="button" class="rsu-gen-filter__btn rsu-gen-filter__btn--active" data-generation="all" aria-pressed="true">All</button>
									<?php foreach ( $gen_labels as $g_slug => $g_label ) : ?>
										<button type="button" class="rsu-gen-filter__btn" data-generation="<?php echo esc_attr( $g_slug ); ?>" aria-pressed="false"><?php echo esc_html( $g_label ); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( count( $anchors ) >= 3 ) : ?>
							<nav class="rsu-toc" aria-label="<?php echo esc_attr( $vehicle['label'] ); ?> release note sections">
								<span class="rsu-toc__label">On this page</span>
								<ul class="rsu-toc__list">
									<?php foreach ( $anchors as $anchor ) : ?>
										<li class="rsu-toc__item"<?php echo ( $multi_gen && '' !== $anchor['generation'] ) ? ' data-generation="' . esc_attr( $anchor['generation'] ) . '"' : ''; ?>>
											<a class="rsu-toc__link" href="#<?php echo esc_attr( $anchor['id'] ); ?>"><?php echo esc_html( $anchor['label'] ); ?></a>
										</li>
									<?php endforeach; ?>
								</ul>
							</nav>
						<?php endif; ?>

						<?php if ( ! empty( $vehicle_content ) ) : ?>
							<div class="rsu-notes">
								<?php echo wp_kses_post( $vehicle_content ); ?>
							</div>
						<?php else : ?>
							<p class="rsu-panel__empty">No release notes available for <?php echo esc_html( $vehicle['label'] ); ?>.</p>
						<?php endif; ?>

						<?php if ( $adjacent['prev'] || $adjacent['next'] ) : ?>
							<nav class="rsu-adjacent" aria-label="More <?php echo esc_attr( $vehicle['label'] ); ?> updates">
								<?php if ( $adjacent['prev'] ) : ?>
									<a class="rsu-adjacent__link rsu-adjacent__link--prev" href="<?php echo esc_url( $adjacent['prev']['url'] ); ?>" rel="prev">
										<span class="rsu-adjacent__label"><span aria-hidden="true">&larr;</span> Previous <?php echo esc_html( $vehicle['label'] ); ?> update</span>
										<span class="rsu-adjacent__title"><?php echo esc_html( $adjacent['prev']['title'] ); ?></span>
										<?php if ( '' !== $adjacent['prev']['date'] ) : ?>
											<span class="rsu-adjacent__date"><?php echo esc_html( $adjacent['prev']['date'] ); ?></span>
										<?php endif; ?>
									</a>
								<?php endif; ?>
								<?php if ( $adjacent['next'] ) : ?>
									<a class="rsu-adjacent__link rsu-adjacent__link--next" href="<?php echo esc_url( $adjacent['next']['url'] ); ?>" rel="next">
										<span class="rsu-adjacent__label">Next <?php echo esc_html( $vehicle['label'] ); ?> update <span aria-hidden="true">&rarr;</span></span>
										<span class="rsu-adjacent__title"><?php echo esc_html( $adjacent['next']['title'] ); ?></span>
										<?php if ( '' !== $adjacent['next']['date'] ) : ?>
											<span class="rsu-adjacent__date"><?php echo esc_html( $adjacent['next']['date'] ); ?></span>
										<?php endif; ?>
									</a>
								<?php endif; ?>
							</nav>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$html = ob_get_clean();
		return $html;
	}

	/**
	 * Feed body for an update post: the editorial intro (if any) followed by
	 * each vehicle's builds and notes under its own heading.
	 *
	 * @param string $content Filtered post_content.
	 * @return string
	 */
	private function render_feed_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id || ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return $content;
		}

		$active_vehicles = RSU_Platforms::get_active( $post_id );
		if ( empty( $active_vehicles ) ) {
			return $content;
		}

		$all_vehicles = RSU_Platforms::get_all();
		$builds       = RSU_Builds::get( $post_id );
		$multi        = count( $active_vehicles ) > 1;
		$out          = '';

		$released = get_post_meta( $post_id, '_rsu_date_released', true );
		$noticed  = get_post_meta( $post_id, '_rsu_date_noticed', true );
		$meta     = array();
		if ( $noticed ) {
			$meta[] = 'First noticed: ' . RSU_Dates::format( $noticed );
		}
		$meta[] = 'Public release: ' . ( $released ? RSU_Dates::format( $released ) : 'TBD' );
		$out   .= '<p>' . esc_html( implode( ' · ', $meta ) ) . '</p>' . "\n";

		foreach ( $active_vehicles as $slug ) {
			$vehicle = $all_vehicles[ $slug ];
			$html    = self::vehicle_html( $post_id, $slug );

			if ( $multi ) {
				$out .= '<h2>' . esc_html( $vehicle['label'] ) . '</h2>' . "\n";
			}

			$rows = RSU_Builds::describe( isset( $builds[ $slug ] ) ? array( $slug => $builds[ $slug ] ) : array() );
			if ( ! empty( $rows ) ) {
				$parts = array();
				foreach ( $rows as $row ) {
					$parts[] = ( '' !== $row['label'] ? $row['label'] . ' ' : '' ) . $row['value'];
				}
				$out .= '<p><strong>Build' . ( count( $parts ) > 1 ? 's' : '' ) . ':</strong> ' . esc_html( implode( ', ', $parts ) ) . '</p>' . "\n";
			}

			$out .= '' !== $html
				? wp_kses_post( $html )
				: '<p>No release notes available for ' . esc_html( $vehicle['label'] ) . '.</p>' . "\n";
		}

		return trim( $content ) . "\n" . $out;
	}

	/**
	 * The previous and next update posts tagged for a vehicle, by post date.
	 *
	 * @param int    $post_id Current post ID.
	 * @param string $slug    Vehicle slug.
	 * @return array `array{ prev: ?array, next: ?array }` with title/url/date.
	 */
	private function adjacent_updates( $post_id, $slug ) {
		$post = get_post( $post_id );
		$out  = array( 'prev' => null, 'next' => null );

		if ( ! $post ) {
			return $out;
		}

		foreach ( array( 'prev', 'next' ) as $direction ) {
			$is_prev = ( 'prev' === $direction );

			$query = new WP_Query( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'post__not_in'   => array( $post_id ),
				'orderby'        => 'date',
				'order'          => $is_prev ? 'DESC' : 'ASC',
				'no_found_rows'  => true,
				'date_query'     => array(
					array(
						$is_prev ? 'before' : 'after' => $post->post_date,
						'inclusive'                   => false,
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one row per direction.
					'relation' => 'AND',
					array(
						'key'   => '_rsu_is_update',
						'value' => '1',
					),
					array(
						'key'     => '_rsu_vehicles',
						'value'   => '"' . $slug . '"',
						'compare' => 'LIKE',
					),
				),
			) );

			if ( empty( $query->posts ) ) {
				continue;
			}

			$adjacent = $query->posts[0];
			$released = get_post_meta( $adjacent->ID, '_rsu_date_released', true );
			$noticed  = get_post_meta( $adjacent->ID, '_rsu_date_noticed', true );
			$when     = $released ? $released : $noticed;

			$title = get_the_title( $adjacent->ID );
			if ( get_post_meta( $adjacent->ID, '_rsu_is_hotfix', true ) && false === stripos( $title, 'hotfix' ) ) {
				$title .= ' Hotfix';
			}

			$out[ $direction ] = array(
				'title' => $title,
				'url'   => get_permalink( $adjacent->ID ),
				'date'  => RSU_Dates::format( $when ),
			);
		}

		return $out;
	}

	/**
	 * Enqueue the shared frontend stylesheet, with the accent-color override
	 * applied to every surface (update page, history table, widget).
	 *
	 * Safe to call more than once per request; WordPress de-duplicates the
	 * handle and the inline style is only attached the first time.
	 */
	public static function enqueue_styles() {
		if ( wp_style_is( 'rsu-frontend', 'enqueued' ) ) {
			return;
		}

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

		$accent = RSU_Settings::get( 'accent_color', '#fba919' );
		if ( '#fba919' !== $accent && preg_match( '/^#[a-fA-F0-9]{6}$/', $accent ) ) {
			wp_add_inline_style( 'rsu-frontend', sprintf(
				'.rsu-update, .rsu-history, .rsu-widget-latest { --rsu-accent: %1$s; --rsu-accent-hover: color-mix(in srgb, %1$s 80%%, white); --rsu-accent-tint-8: color-mix(in srgb, %1$s 8%%, #121418); --rsu-accent-tint-15: color-mix(in srgb, %1$s 15%%, #121418); }',
				esc_attr( $accent )
			) );
		}
	}

	/**
	 * Enqueue CSS early in <head> on singular posts to prevent FOUC.
	 */
	public function maybe_enqueue_css() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! get_post_meta( $post_id, '_rsu_is_update', true ) ) {
			return;
		}

		self::enqueue_styles();
	}

	/**
	 * Enqueue JS in footer only when update content was rendered.
	 */
	public function maybe_enqueue_js() {
		if ( ! $this->should_enqueue ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$js_file = RSU_PLUGIN_DIR . 'frontend/js/rsu-frontend' . $suffix . '.js';
		if ( ! file_exists( $js_file ) ) {
			$suffix = '';
		}

		wp_enqueue_script(
			'rsu-frontend',
			RSU_PLUGIN_URL . 'frontend/js/rsu-frontend' . $suffix . '.js',
			array(),
			RSU_VERSION,
			true
		);
	}
}
