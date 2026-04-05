<?php
/**
 * Meta box: Release Notes section builder.
 *
 * @package Rivian_Software_Updates
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;

$is_update        = get_post_meta( $post->ID, '_rsu_is_update', true );
$active_platforms = RSU_Platforms::get_active( $post->ID );
$all_platforms    = RSU_Platforms::get_all();

wp_nonce_field( 'rsu_meta_save', 'rsu_meta_nonce' );
?>

<div class="rsu-admin-wrap" data-rsu-active="<?php echo $is_update ? '1' : '0'; ?>">
	<div class="rsu-toggle-row">
		<label class="rsu-toggle-label">
			<input type="checkbox" name="rsu_is_update" value="1" id="rsu-is-update"
				<?php checked( $is_update, '1' ); ?> />
			This is a Software Update post
		</label>
	</div>

	<div class="rsu-fields" id="rsu-fields" style="<?php echo $is_update ? '' : 'display:none;'; ?>">
		<div class="rsu-platform-checks">
			<span class="rsu-platform-checks__label">Platforms:</span>
			<?php foreach ( $all_platforms as $slug => $platform ) : ?>
				<label class="rsu-platform-check">
					<input type="checkbox" name="rsu_platforms[]" value="<?php echo esc_attr( $slug ); ?>"
						class="rsu-platform-checkbox"
						data-platform="<?php echo esc_attr( $slug ); ?>"
						<?php checked( in_array( $slug, $active_platforms, true ) ); ?> />
					<?php echo esc_html( $platform['label'] ); ?>
					<span class="rsu-platform-desc"><?php echo esc_html( $platform['description'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="rsu-editor-tabs" id="rsu-editor-tabs" role="tablist">
			<?php
			$first = true;
			foreach ( $all_platforms as $slug => $platform ) :
				$is_active = in_array( $slug, $active_platforms, true );
				?>
				<button type="button"
					class="rsu-editor-tab <?php echo $first && $is_active ? 'rsu-editor-tab--active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $first && $is_active ? 'true' : 'false'; ?>"
					aria-controls="rsu-editor-panel-<?php echo esc_attr( $slug ); ?>"
					data-platform="<?php echo esc_attr( $slug ); ?>"
					style="<?php echo $is_active ? '' : 'display:none;'; ?>">
					<?php echo esc_html( $platform['label'] ); ?>
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
		foreach ( $all_platforms as $slug => $platform ) :
			$is_active = in_array( $slug, $active_platforms, true );

			// Load structured sections JSON if available.
			$sections_json = get_post_meta( $post->ID, '_rsu_sections_' . $slug, true );

			// Other platforms for "Copy from" dropdown.
			$other_platforms = array_diff_key( $all_platforms, array( $slug => true ) );
			?>
			<div class="rsu-editor-panel <?php echo $first_visible && $is_active ? '' : 'rsu-editor-panel--hidden'; ?>"
				id="rsu-editor-panel-<?php echo esc_attr( $slug ); ?>"
				role="tabpanel"
				data-platform="<?php echo esc_attr( $slug ); ?>"
				style="<?php echo $is_active ? '' : 'display:none;'; ?>">

				<div class="rsu-editor-toolbar">
					<label class="rsu-copy-from">
						Copy from:
						<select class="rsu-copy-from-select" data-target="<?php echo esc_attr( $slug ); ?>">
							<option value="">-- Select --</option>
							<?php foreach ( $other_platforms as $other_slug => $other ) : ?>
								<option value="<?php echo esc_attr( $other_slug ); ?>">
									<?php echo esc_html( $other['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>

				<!-- Section builder -->
				<div class="rsu-section-builder" data-platform="<?php echo esc_attr( $slug ); ?>">
					<div class="rsu-sections-list">
						<!-- Sections rendered by JS -->
					</div>
					<button type="button" class="button rsu-add-section">+ Add Section</button>
				</div>

				<!-- Hidden input stores the JSON -->
				<input type="hidden"
					name="rsu_sections_<?php echo esc_attr( $slug ); ?>"
					class="rsu-sections-json"
					data-platform="<?php echo esc_attr( $slug ); ?>"
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
