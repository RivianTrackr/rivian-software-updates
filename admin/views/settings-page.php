<?php
/**
 * Settings admin page — card-based layout.
 *
 * @package Rivian_Software_Updates
 */

defined( 'ABSPATH' ) || exit;

$settings = RSU_Settings::get_all();
$vehicles = RSU_Platforms::get_all();

$selected_vehicles = isset( $settings['default_vehicles'] ) ? (array) $settings['default_vehicles'] : array();
$heading_levels    = array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4' );
?>

<div class="rsu-settings-wrap">
	<div class="rsu-settings-header">
		<div>
			<h1 class="rsu-settings-title">Rivian Software Updates</h1>
			<p class="rsu-settings-subtitle">Configure vehicle platforms, display settings, and SEO options.</p>
		</div>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'rsu_settings_group' ); ?>

		<!-- ==================== Vehicles Card ==================== -->
		<div class="rsu-card">
			<div class="rsu-card__header">
				<div>
					<h2 class="rsu-card__title">Vehicles</h2>
					<p class="rsu-card__desc">Manage vehicle models and generations for release notes tabs and pills.</p>
				</div>
			</div>
			<div class="rsu-card__body" style="padding: 20px;">
				<div id="rsu-vehicles-manager">
					<?php
					$vi = 0;
					foreach ( $vehicles as $slug => $vehicle ) :
						$prefix      = 'rsu_platforms[' . $vi . ']';
						$is_existing = ! empty( $slug );
						$generations = isset( $vehicle['generations'] ) ? $vehicle['generations'] : array();
						?>
						<div class="rsu-vehicle-block" data-index="<?php echo esc_attr( $vi ); ?>">
							<div class="rsu-vehicle-block__header">
								<div class="rsu-vehicle-field" style="flex: 0 0 120px;">
									<label class="rsu-vehicle-field__label">Slug</label>
									<?php if ( $is_existing ) : ?>
										<code><?php echo esc_html( $slug ); ?></code>
										<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[slug]" value="<?php echo esc_attr( $slug ); ?>" />
									<?php else : ?>
										<input type="text" name="<?php echo esc_attr( $prefix ); ?>[slug]"
											class="rsu-input rsu-vehicle-slug"
											placeholder="e.g. r3" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
											value="" />
									<?php endif; ?>
								</div>
								<div class="rsu-vehicle-field" style="flex: 1;">
									<label class="rsu-vehicle-field__label">Label</label>
									<input type="text" name="<?php echo esc_attr( $prefix ); ?>[label]"
										class="rsu-input"
										value="<?php echo esc_attr( $vehicle['label'] ); ?>"
										placeholder="e.g. R3" />
								</div>
								<div class="rsu-vehicle-field" style="flex: 1;">
									<label class="rsu-vehicle-field__label">Description</label>
									<input type="text" name="<?php echo esc_attr( $prefix ); ?>[description]"
										class="rsu-input"
										value="<?php echo esc_attr( $vehicle['description'] ); ?>"
										placeholder="e.g. R3 SUV" />
								</div>
								<div class="rsu-vehicle-field" style="flex: 0 0 80px;">
									<label class="rsu-vehicle-field__label">Order</label>
									<input type="number" name="<?php echo esc_attr( $prefix ); ?>[sort]"
										class="rsu-input"
										value="<?php echo esc_attr( isset( $vehicle['sort'] ) ? $vehicle['sort'] : '' ); ?>"
										min="0" step="10" />
								</div>
								<div class="rsu-vehicle-field" style="flex: 0 0 40px; padding-top: 18px;">
									<button type="button" class="button-link rsu-remove-vehicle rsu-remove-btn" title="Remove vehicle">
										<span class="dashicons dashicons-trash"></span>
									</button>
								</div>
							</div>

							<div class="rsu-vehicle-block__generations">
								<strong class="rsu-vehicle-block__gen-title">Generations</strong>
								<table class="rsu-generations-table">
									<thead>
										<tr>
											<th style="width: 110px;">Slug</th>
											<th>Label</th>
											<th>Description</th>
											<th style="width: 70px;">Order</th>
											<th style="width: 36px;"></th>
										</tr>
									</thead>
									<tbody class="rsu-gen-tbody">
										<?php
										$gi = 0;
										foreach ( $generations as $gen_slug => $gen ) :
											$gen_prefix     = 'rsu_platforms[' . $vi . '][generations][' . $gi . ']';
											$gen_is_existing = ! empty( $gen_slug );
											?>
											<tr>
												<td>
													<?php if ( $gen_is_existing ) : ?>
														<code style="font-size: 11px;"><?php echo esc_html( $gen_slug ); ?></code>
														<input type="hidden" name="<?php echo esc_attr( $gen_prefix ); ?>[slug]" value="<?php echo esc_attr( $gen_slug ); ?>" />
													<?php else : ?>
														<input type="text" name="<?php echo esc_attr( $gen_prefix ); ?>[slug]"
															class="rsu-input rsu-gen-slug"
															placeholder="e.g. gen3" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
															value="" />
													<?php endif; ?>
												</td>
												<td>
													<input type="text" name="<?php echo esc_attr( $gen_prefix ); ?>[label]"
														class="rsu-input"
														value="<?php echo esc_attr( $gen['label'] ); ?>"
														placeholder="e.g. Gen 3" />
												</td>
												<td>
													<input type="text" name="<?php echo esc_attr( $gen_prefix ); ?>[description]"
														class="rsu-input"
														value="<?php echo esc_attr( $gen['description'] ); ?>"
														placeholder="e.g. 2028+" />
												</td>
												<td>
													<input type="number" name="<?php echo esc_attr( $gen_prefix ); ?>[sort]"
														class="rsu-input"
														value="<?php echo esc_attr( isset( $gen['sort'] ) ? $gen['sort'] : '' ); ?>"
														min="0" step="10" />
												</td>
												<td>
													<button type="button" class="button-link rsu-remove-generation rsu-remove-btn" title="Remove generation">
														<span class="dashicons dashicons-trash"></span>
													</button>
												</td>
											</tr>
											<?php
											$gi++;
										endforeach;
										?>
									</tbody>
								</table>
								<button type="button" class="button button-small rsu-add-generation">+ Add Generation</button>
							</div>
						</div>
						<?php
						$vi++;
					endforeach;
					?>
				</div>

				<p style="margin-top: 12px;">
					<button type="button" class="button" id="rsu-add-vehicle">+ Add Vehicle</button>
				</p>

				<!-- Vehicle template for JS -->
				<template id="rsu-vehicle-template">
					<div class="rsu-vehicle-block" data-index="__VI__">
						<div class="rsu-vehicle-block__header">
							<div class="rsu-vehicle-field" style="flex: 0 0 120px;">
								<label class="rsu-vehicle-field__label">Slug</label>
								<input type="text" name="rsu_platforms[__VI__][slug]"
									class="rsu-input rsu-vehicle-slug"
									placeholder="e.g. r3" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
									value="" />
							</div>
							<div class="rsu-vehicle-field" style="flex: 1;">
								<label class="rsu-vehicle-field__label">Label</label>
								<input type="text" name="rsu_platforms[__VI__][label]"
									class="rsu-input"
									value=""
									placeholder="e.g. R3" />
							</div>
							<div class="rsu-vehicle-field" style="flex: 1;">
								<label class="rsu-vehicle-field__label">Description</label>
								<input type="text" name="rsu_platforms[__VI__][description]"
									class="rsu-input"
									value=""
									placeholder="e.g. R3 SUV" />
							</div>
							<div class="rsu-vehicle-field" style="flex: 0 0 80px;">
								<label class="rsu-vehicle-field__label">Order</label>
								<input type="number" name="rsu_platforms[__VI__][sort]"
									class="rsu-input"
									value=""
									min="0" step="10" />
							</div>
							<div class="rsu-vehicle-field" style="flex: 0 0 40px; padding-top: 18px;">
								<button type="button" class="button-link rsu-remove-vehicle rsu-remove-btn" title="Remove vehicle">
									<span class="dashicons dashicons-trash"></span>
								</button>
							</div>
						</div>
						<div class="rsu-vehicle-block__generations">
							<strong class="rsu-vehicle-block__gen-title">Generations</strong>
							<table class="rsu-generations-table">
								<thead>
									<tr>
										<th style="width: 110px;">Slug</th>
										<th>Label</th>
										<th>Description</th>
										<th style="width: 70px;">Order</th>
										<th style="width: 36px;"></th>
									</tr>
								</thead>
								<tbody class="rsu-gen-tbody"></tbody>
							</table>
							<button type="button" class="button button-small rsu-add-generation">+ Add Generation</button>
						</div>
					</div>
				</template>

				<!-- Generation row template for JS -->
				<template id="rsu-generation-template">
					<tr>
						<td>
							<input type="text" name="rsu_platforms[__VI__][generations][__GI__][slug]"
								class="rsu-input rsu-gen-slug"
								placeholder="e.g. gen3" pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only"
								value="" />
						</td>
						<td>
							<input type="text" name="rsu_platforms[__VI__][generations][__GI__][label]"
								class="rsu-input"
								value=""
								placeholder="e.g. Gen 3" />
						</td>
						<td>
							<input type="text" name="rsu_platforms[__VI__][generations][__GI__][description]"
								class="rsu-input"
								value=""
								placeholder="e.g. 2028+" />
						</td>
						<td>
							<input type="number" name="rsu_platforms[__VI__][generations][__GI__][sort]"
								class="rsu-input"
								value=""
								min="0" step="10" />
						</td>
						<td>
							<button type="button" class="button-link rsu-remove-generation rsu-remove-btn" title="Remove generation">
								<span class="dashicons dashicons-trash"></span>
							</button>
						</td>
					</tr>
				</template>
			</div>
		</div>

		<!-- ==================== General Card ==================== -->
		<div class="rsu-card">
			<div class="rsu-card__header">
				<div>
					<h2 class="rsu-card__title">General</h2>
					<p class="rsu-card__desc">Default behavior for the release notes editor.</p>
				</div>
			</div>

			<!-- Default Vehicles -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Default Vehicles</label>
					<p>Pre-selected when creating a new post.</p>
				</div>
				<div class="rsu-field-control">
					<?php foreach ( $vehicles as $slug => $vehicle ) : ?>
						<label class="rsu-checkbox-label">
							<input type="checkbox"
								name="rsu_settings[default_vehicles][]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $selected_vehicles, true ) ); ?> />
							<?php echo esc_html( $vehicle['label'] ); ?>
							<span class="rsu-checkbox-desc">(<?php echo esc_html( $vehicle['description'] ); ?>)</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Default Frontend Tab -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Default Frontend Tab</label>
					<p>Vehicle tab shown first for new visitors.</p>
				</div>
				<div class="rsu-field-control">
					<select name="rsu_settings[default_tab]" class="rsu-select">
						<?php foreach ( $vehicles as $slug => $vehicle ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['default_tab'], $slug ); ?>>
								<?php echo esc_html( $vehicle['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- Section Heading Level -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Section Heading Level</label>
					<p>HTML heading level for section headings in rendered notes.</p>
				</div>
				<div class="rsu-field-control">
					<select name="rsu_settings[heading_level]" class="rsu-select">
						<?php foreach ( $heading_levels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['heading_level'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<!-- Note Block Label -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Note Block Label</label>
					<p>Label shown on note/blockquote blocks (e.g. "NOTE", "TIP").</p>
				</div>
				<div class="rsu-field-control">
					<input type="text"
						name="rsu_settings[note_label]"
						class="rsu-input"
						value="<?php echo esc_attr( $settings['note_label'] ); ?>" />
				</div>
			</div>
		</div>

		<!-- ==================== Appearance Card ==================== -->
		<div class="rsu-card">
			<div class="rsu-card__header">
				<div>
					<h2 class="rsu-card__title">Appearance</h2>
					<p class="rsu-card__desc">Customize the frontend appearance of release notes.</p>
				</div>
			</div>

			<!-- Accent Color -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Accent Color</label>
					<p>Primary color for tabs, links, and bullet markers.</p>
				</div>
				<div class="rsu-field-control">
					<input type="text"
						name="rsu_settings[accent_color]"
						class="rsu-color-picker"
						value="<?php echo esc_attr( $settings['accent_color'] ); ?>"
						data-default-color="#fba919" />
				</div>
			</div>
		</div>

		<!-- ==================== SEO & Schema Card ==================== -->
		<div class="rsu-card">
			<div class="rsu-card__header">
				<div>
					<h2 class="rsu-card__title">SEO &amp; Schema</h2>
					<p class="rsu-card__desc">Configure SEO structured data output for software update posts.</p>
				</div>
			</div>

			<!-- Schema Markup Toggle -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Schema Markup</label>
					<p>Output JSON-LD structured data on update posts.</p>
				</div>
				<div class="rsu-field-control">
					<label class="rsu-toggle">
						<input type="checkbox"
							name="rsu_settings[schema_enabled]"
							value="1"
							<?php checked( $settings['schema_enabled'] ); ?> />
						<span class="rsu-toggle__track"></span>
					</label>
				</div>
			</div>

			<!-- Organization Name -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Organization Name</label>
					<p>Used as the author and publisher in schema markup.</p>
				</div>
				<div class="rsu-field-control">
					<input type="text"
						name="rsu_settings[organization_name]"
						class="rsu-input"
						value="<?php echo esc_attr( $settings['organization_name'] ); ?>" />
				</div>
			</div>

			<!-- Updates Archive Slug -->
			<div class="rsu-field-row">
				<div class="rsu-field-label">
					<label>Updates Archive Slug</label>
					<p>URL path for the breadcrumb schema archive page.</p>
				</div>
				<div class="rsu-field-control">
					<span class="rsu-field-prefix"><code><?php echo esc_html( home_url() ); ?></code></span>
					<input type="text"
						name="rsu_settings[archive_slug]"
						class="rsu-input"
						value="<?php echo esc_attr( $settings['archive_slug'] ); ?>"
						style="width: 200px;" />
				</div>
			</div>
		</div>

		<!-- ==================== Save Bar ==================== -->
		<div class="rsu-save-bar">
			<button type="submit" class="rsu-btn rsu-btn-primary">Save Changes</button>
		</div>
	</form>
</div>

<script>
(function() {
	var manager = document.getElementById('rsu-vehicles-manager');
	var vTemplate = document.getElementById('rsu-vehicle-template');
	var gTemplate = document.getElementById('rsu-generation-template');

	function rsuConfirm(message) {
		return new Promise(function (resolve) {
			var dialog = document.createElement('dialog');
			dialog.className = 'rsu-confirm-dialog';
			dialog.innerHTML =
				'<div class="rsu-confirm-dialog__body"><p class="rsu-confirm-dialog__message">' + message + '</p></div>' +
				'<div class="rsu-confirm-dialog__actions">' +
					'<button type="button" class="rsu-confirm-dialog__cancel">Cancel</button>' +
					'<button type="button" class="rsu-confirm-dialog__ok">Confirm</button>' +
				'</div>';
			document.body.appendChild(dialog);
			dialog.showModal();
			dialog.querySelector('.rsu-confirm-dialog__cancel').addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(false); });
			dialog.querySelector('.rsu-confirm-dialog__ok').addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(true); });
			dialog.addEventListener('cancel', function () { dialog.remove(); resolve(false); });
		});
	}

	document.getElementById('rsu-add-vehicle').addEventListener('click', function() {
		var vi = manager.querySelectorAll('.rsu-vehicle-block').length;
		var html = vTemplate.innerHTML.replace(/__VI__/g, vi);
		var temp = document.createElement('div');
		temp.innerHTML = html;
		var block = temp.firstElementChild;
		manager.appendChild(block);
		block.querySelector('.rsu-vehicle-slug').focus();
	});

	document.addEventListener('click', function(e) {
		if (e.target.closest('.rsu-add-generation')) {
			var block = e.target.closest('.rsu-vehicle-block');
			var tbody = block.querySelector('.rsu-gen-tbody');
			var vi = block.getAttribute('data-index');
			var gi = tbody.querySelectorAll('tr').length;
			var html = gTemplate.innerHTML.replace(/__VI__/g, vi).replace(/__GI__/g, gi);
			var temp = document.createElement('tbody');
			temp.innerHTML = html;
			var row = temp.querySelector('tr');
			tbody.appendChild(row);
			row.querySelector('.rsu-gen-slug').focus();
		}

		if (e.target.closest('.rsu-remove-vehicle')) {
			if (manager.querySelectorAll('.rsu-vehicle-block').length <= 1) {
				alert('You must have at least one vehicle.');
				return;
			}
			var vehicleBlock = e.target.closest('.rsu-vehicle-block');
			rsuConfirm('Remove this vehicle? Existing post data will not be deleted.').then(function (ok) {
				if (ok) vehicleBlock.remove();
			});
		}

		if (e.target.closest('.rsu-remove-generation')) {
			var row = e.target.closest('tr');
			rsuConfirm('Remove this generation?').then(function (ok) {
				if (ok) row.remove();
			});
		}
	});
})();
</script>
