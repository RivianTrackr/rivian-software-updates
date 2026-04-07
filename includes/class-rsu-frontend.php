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
		add_filter( 'the_content', array( $this, 'render_update_content' ), 20 );
		add_action( 'wp_footer', array( $this, 'maybe_enqueue_assets' ) );
		add_filter( 'aioseo_description_context', array( $this, 'aioseo_clean_content' ) );
		add_filter( 'aioseo_og_description_context', array( $this, 'aioseo_clean_content' ) );
		add_filter( 'aioseo_twitter_description_context', array( $this, 'aioseo_clean_content' ) );
	}

	public function aioseo_clean_content( $content ) {
		if ( ! is_singular( 'post' ) ) {
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

		$all_vehicles = RSU_Platforms::get_all();
		$default      = RSU_Platforms::get_default();

		if ( ! in_array( $default, $active_vehicles, true ) ) {
			$default = $active_vehicles[0];
		}

		if ( isset( $all_vehicles[ $default ] ) ) {
			$vehicle_content = '';
			$sections_json   = get_post_meta( $post_id, '_rsu_sections_' . $default, true );
			if ( $sections_json ) {
				$sections = json_decode( $sections_json, true );
				if ( is_array( $sections ) && ! empty( $sections ) ) {
					$vehicle_content = RSU_Admin::render_sections_to_html( $sections, $default );
				}
			}
			if ( empty( $vehicle_content ) ) {
				$vehicle_content = get_post_meta( $post_id, $all_vehicles[ $default ]['meta_key'], true );
			}
			if ( $vehicle_content ) {
				return wp_strip_all_tags( $vehicle_content );
			}
		}

		return $content;
	}

	public function render_update_content( $content ) {
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
		$version        = get_the_title( $post_id );
		$date_noticed   = get_post_meta( $post_id, '_rsu_date_noticed', true );
		$date_released  = get_post_meta( $post_id, '_rsu_date_released', true );

		if ( ! in_array( $default, $active_vehicles, true ) ) {
			$default = $active_vehicles[0];
		}

		$this->should_enqueue = true;

		ob_start();
		?>
		<div class="rsu-update alignfull" data-rsu-version="<?php echo esc_attr( $version ); ?>" data-rsu-default="<?php echo esc_attr( $default ); ?>">

			<?php if ( $date_noticed || $date_released ) : ?>
				<div class="rsu-dates">
					<?php if ( $date_noticed ) : ?>
						<span class="rsu-date rsu-date--noticed">
							<span class="rsu-date__label">First Noticed</span>
							<time datetime="<?php echo esc_attr( $date_noticed ); ?>">
								<?php echo esc_html( date_i18n( 'F j, Y', strtotime( $date_noticed ) ) ); ?>
							</time>
						</span>
					<?php endif; ?>

					<?php if ( $date_released ) : ?>
						<span class="rsu-date rsu-date--released">
							<span class="rsu-date__label">Public Release</span>
							<time datetime="<?php echo esc_attr( $date_released ); ?>">
								<?php echo esc_html( date_i18n( 'F j, Y', strtotime( $date_released ) ) ); ?>
							</time>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( count( $active_vehicles ) >= 1 ) : ?>
				<div class="rsu-tabs" role="tablist" aria-label="Vehicle model">
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

			<?php foreach ( $active_vehicles as $slug ) :
				$vehicle    = $all_vehicles[ $slug ];
				$is_default = ( $slug === $default );

				// Render from sections JSON so settings like heading level apply immediately.
				$vehicle_content = '';
				$sections_json   = get_post_meta( $post_id, '_rsu_sections_' . $slug, true );
				if ( $sections_json ) {
					$sections = json_decode( $sections_json, true );
					if ( is_array( $sections ) && ! empty( $sections ) ) {
						$vehicle_content = RSU_Admin::render_sections_to_html( $sections, $slug );
					}
				}
				// Fallback to pre-rendered HTML for legacy posts without sections JSON.
				if ( empty( $vehicle_content ) ) {
					$vehicle_content = get_post_meta( $post_id, $vehicle['meta_key'], true );
				}
				?>
				<div class="rsu-panel <?php echo $is_default ? 'rsu-panel--active' : ''; ?>"
					role="tabpanel"
					id="rsu-panel-<?php echo esc_attr( $slug ); ?>"
					aria-labelledby="rsu-tab-<?php echo esc_attr( $slug ); ?>"
					<?php echo $is_default ? '' : 'hidden'; ?>>
					<div class="rsu-panel__content">
						<?php echo wp_kses_post( $vehicle_content ); ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		$html = ob_get_clean();
		return $html;
	}

	public function maybe_enqueue_assets() {
		if ( ! $this->should_enqueue ) {
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
		if ( '#fba919' !== $accent ) {
			wp_add_inline_style( 'rsu-frontend', sprintf(
				'.rsu-update { --rsu-accent: %1$s; --rsu-accent-hover: color-mix(in srgb, %1$s 80%%, white); --rsu-accent-tint-8: color-mix(in srgb, %1$s 8%%, #0f1a26); --rsu-accent-tint-15: color-mix(in srgb, %1$s 15%%, #0f1a26); }',
				esc_attr( $accent )
			) );
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
