<?php
/**
 * Meta box: Release Notes section builder.
 *
 * @package Rivian_Software_Updates
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;

$active_vehicles = RSU_Platforms::get_active( $post->ID );
$all_vehicles    = RSU_Platforms::get_all();

// For new posts with no vehicles saved, use the default vehicles setting.
if ( empty( $active_vehicles ) && 'auto-draft' === get_post_status( $post->ID ) ) {
	$active_vehicles = (array) RSU_Settings::get( 'default_vehicles', array( 'r1', 'r2' ) );
	$active_vehicles = array_intersect( $active_vehicles, array_keys( $all_vehicles ) );
}

wp_nonce_field( 'rsu_meta_save', 'rsu_meta_nonce' );
?>

<div class="rsu-admin-wrap" data-rsu-active="1">
	<input type="hidden" name="rsu_is_update" value="1" />

	<div class="rsu-fields" id="rsu-fields">
		<div class="rsu-vehicle-checks" role="group" aria-label="Select vehicles for this update">
			<span class="rsu-vehicle-checks__label">Vehicles:</span>
			<?php foreach ( $all_vehicles as $slug => $vehicle ) : ?>
				<label class="rsu-vehicle-check">
					<input type="checkbox" name="rsu_vehicles[]" value="<?php echo esc_attr( $slug ); ?>"
						class="rsu-vehicle-checkbox"
						data-vehicle="<?php echo esc_attr( $slug ); ?>"
						<?php checked( in_array( $slug, $active_vehicles, true ) ); ?> />
					<?php echo esc_html( $vehicle['label'] ); ?>
					<span class="rsu-vehicle-desc"><?php echo esc_html( $vehicle['description'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="rsu-editor-tabs" id="rsu-editor-tabs" role="tablist">
			<?php
			$first = true;
			foreach ( $all_vehicles as $slug => $vehicle ) :
				$is_active = in_array( $slug, $active_vehicles, true );
				?>
				<button type="button"
					class="rsu-editor-tab <?php echo $first && $is_active ? 'rsu-editor-tab--active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $first && $is_active ? 'true' : 'false'; ?>"
					aria-controls="rsu-editor-panel-<?php echo esc_attr( $slug ); ?>"
					data-vehicle="<?php echo esc_attr( $slug ); ?>"
					style="<?php echo $is_active ? '' : 'display:none;'; ?>">
					<?php echo esc_html( $vehicle['label'] ); ?>
				</button>
				<?php
				if ( $is_active ) {
					$first = false;
				}
			endforeach;
			?>
		</div>

		<?php
		$first_visible = true;
		foreach ( $all_vehicles as $slug => $vehicle ) :
			$is_active = in_array( $slug, $active_vehicles, true );

			// Load structured sections JSON if available, otherwise parse from HTML.
			// Read directly from DB to bypass persistent object cache (Redis/Memcached).
			global $wpdb;
			$meta_key      = '_rsu_sections_' . $slug;
			$sections_json = $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$post->ID,
				$meta_key
			) );

			// Validate JSON — if invalid, discard and fall back to HTML parsing.
			if ( $sections_json ) {
				json_decode( $sections_json );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$sections_json = '';
				}
			}

			if ( empty( $sections_json ) ) {
				$html_content = get_post_meta( $post->ID, $vehicle['meta_key'], true );
				if ( ! empty( $html_content ) ) {
					$parsed = RSU_Admin::parse_html_to_sections( $html_content );
					if ( ! empty( $parsed ) ) {
						$sections_json = wp_json_encode( $parsed );
					}
				}
			} else {
				// Heal posts whose JSON was polluted by the pre-fix parse, where pill
				// text ("Gen 2 Only") got concatenated into bullet/paragraph content.
				$decoded = json_decode( $sections_json, true );
				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					$cleaned = RSU_Admin::clean_pill_pollution( $decoded, $slug );
					if ( $cleaned !== $decoded ) {
						$sections_json = wp_json_encode( $cleaned );
					}
				}
			}

			// Other vehicles for "Copy from" dropdown.
			$other_vehicles = array_diff_key( $all_vehicles, array( $slug => true ) );

			// Generations for this vehicle.
			$generations = isset( $vehicle['generations'] ) ? $vehicle['generations'] : array();
			$gen_json = wp_json_encode( $generations );
			?>
			<div class="rsu-editor-panel <?php echo $first_visible && $is_active ? '' : 'rsu-editor-panel--hidden'; ?>"
				id="rsu-editor-panel-<?php echo esc_attr( $slug ); ?>"
				role="tabpanel"
				data-vehicle="<?php echo esc_attr( $slug ); ?>"
				style="<?php echo $is_active ? '' : 'display:none;'; ?>">

				<div class="rsu-editor-toolbar">
					<button type="button" class="rsu-import-btn" data-vehicle="<?php echo esc_attr( $slug ); ?>" data-action="show-import">
						<span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span>
						Paste Release Notes
					</button>
					<div class="rsu-editor-toolbar__right">
						<button type="button" class="rsu-collapse-all" data-action="toggle-all-sections" data-vehicle="<?php echo esc_attr( $slug ); ?>">Collapse all</button>
						<label class="rsu-copy-from">
							Copy from:
							<select class="rsu-copy-from-select" data-target="<?php echo esc_attr( $slug ); ?>">
								<option value="">-- Select --</option>
								<?php foreach ( $other_vehicles as $other_slug => $other ) : ?>
									<option value="<?php echo esc_attr( $other_slug ); ?>">
										<?php echo esc_html( $other['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
				</div>

				<!-- Section builder -->
				<div class="rsu-section-builder" data-vehicle="<?php echo esc_attr( $slug ); ?>" data-generations="<?php echo esc_attr( $gen_json ); ?>">
					<div class="rsu-sections-list">
						<!-- Sections rendered by JS -->
					</div>
					<button type="button" class="button rsu-add-section" data-action="add-section">+ Add Section</button>
				</div>

				<!-- Hidden input stores the JSON -->
				<input type="hidden"
					name="rsu_sections_<?php echo esc_attr( $slug ); ?>"
					class="rsu-sections-json"
					data-vehicle="<?php echo esc_attr( $slug ); ?>"
					value="<?php echo esc_attr( $sections_json ? $sections_json : '[]' ); ?>" />
			</div>
			<?php
			if ( $is_active ) {
				$first_visible = false;
			}
		endforeach;
		?>
	</div>
</div>

