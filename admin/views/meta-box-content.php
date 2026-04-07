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

<style>
/* RSU Admin — inlined for Block Editor compatibility */
.rsu-admin-wrap { margin: -6px -12px -12px; padding: 0; }

.rsu-vehicle-checks { padding: 14px 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 20px; border-bottom: 1px solid #e5e7eb; background: #fff; }
.rsu-vehicle-checks__label { font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
.rsu-vehicle-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; cursor: pointer; color: #374151; }
.rsu-vehicle-check input[type="checkbox"] { margin: 0; }
.rsu-vehicle-desc { color: #9ca3af; font-size: 11px; font-weight: 400; }

.rsu-editor-tabs { display: flex; gap: 0; padding: 0 20px; background: #f9fafb; border-bottom: 1px solid #dcdcde; }
.rsu-editor-tab { padding: 10px 24px; border: none; border-bottom: 2px solid transparent; background: transparent; color: #6b7280; font-size: 13px; font-weight: 500; cursor: pointer; transition: color 0.15s, border-color 0.15s; margin-bottom: -1px; }
.rsu-editor-tab:hover { color: #1d2327; }
.rsu-editor-tab--active { color: #2271b1; font-weight: 600; border-bottom-color: #2271b1; }

.rsu-editor-panel { padding: 20px; background: #fff; }
.rsu-editor-panel--hidden { position: absolute; left: -9999px; visibility: hidden; }

.rsu-editor-toolbar { display: flex; justify-content: flex-end; margin-bottom: 16px; }
.rsu-copy-from { font-size: 12px; color: #9ca3af; display: inline-flex; align-items: center; gap: 6px; }
.rsu-copy-from-select { font-size: 12px; padding: 3px 8px; border-radius: 4px; border: 1px solid #d1d5db; }

.rsu-sections-empty { padding: 40px 24px; text-align: center; color: #9ca3af; font-size: 14px; background: #fafafa; border: 2px dashed #e5e7eb; border-radius: 8px; margin-bottom: 16px; }

.rsu-section-builder .rsu-add-section { display: block; width: 100%; padding: 12px 16px; font-size: 13px; font-weight: 600; border: 2px dashed #d1d5db !important; background: #fafafa !important; color: #2271b1 !important; border-radius: 8px !important; cursor: pointer; transition: all 0.15s ease; text-align: center; box-shadow: none !important; }
.rsu-section-builder .rsu-add-section:hover { border-color: #2271b1 !important; background: #eff6ff !important; }

.rsu-section { border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 16px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }

.rsu-section__header { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; border-radius: 8px 8px 0 0; }
.rsu-section__drag { cursor: grab; color: #d1d5db; font-size: 16px; flex-shrink: 0; }
.rsu-section__drag:hover { color: #6b7280; }

.rsu-section__heading { flex: 1; font-size: 14px !important; font-weight: 600; padding: 8px 12px !important; border: 1px solid #e5e7eb !important; border-radius: 6px !important; background: #fff !important; color: #1d2327; box-shadow: none !important; }
.rsu-section__heading:focus { border-color: #2271b1 !important; box-shadow: 0 0 0 2px rgba(34,113,177,0.15) !important; outline: none; }
.rsu-section__heading::placeholder { font-weight: 400; color: #c3c4c7; font-size: 13px; }

.rsu-section__remove { background: none; border: none; font-size: 18px; line-height: 1; color: #d1d5db; cursor: pointer; padding: 4px 8px; border-radius: 4px; flex-shrink: 0; }
.rsu-section__remove:hover { color: #dc2626; background: #fef2f2; }

.rsu-blocks-list { padding: 12px 14px 4px; }

.rsu-block { border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 10px; background: #fff; overflow: visible; }
.rsu-block:focus-within { border-color: #93c5fd; }
.rsu-block[data-type="list"] { border-left: 3px solid #3b82f6; }
.rsu-block[data-type="note"] { border-left: 3px solid #f59e0b; }

.rsu-block__header { display: flex; align-items: center; gap: 6px; padding: 5px 10px; background: #f9fafb; border-bottom: 1px solid #f0f0f0; }
.rsu-block__drag { cursor: grab; color: #d1d5db; font-size: 13px; flex-shrink: 0; }
.rsu-block__label { flex: 1; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #9ca3af; }
.rsu-block[data-type="list"] .rsu-block__label { color: #2563eb; }
.rsu-block[data-type="note"] .rsu-block__label { color: #d97706; }

.rsu-block__remove { background: none; border: none; font-size: 15px; line-height: 1; color: #d1d5db; cursor: pointer; padding: 2px 4px; border-radius: 3px; }
.rsu-block__remove:hover { color: #dc2626; background: #fef2f2; }

.rsu-block__content { display: block; width: 100%; padding: 10px 12px; border: none !important; resize: none; font-size: 13px; line-height: 1.65; font-family: inherit; color: #374151; overflow: hidden; min-height: 56px; height: auto; box-sizing: border-box; background: #fff; box-shadow: none !important; outline: none !important; field-sizing: content; }
.rsu-block__content:focus { background: #fafbff; }
.rsu-block__content::placeholder { color: #c3c4c7; }
.rsu-block[data-type="note"] .rsu-block__content { background: #fffbeb; }
.rsu-block[data-type="note"] .rsu-block__content:focus { background: #fef3c7; }

.rsu-bullet-list { padding: 8px 12px 4px; }
.rsu-bullet-row { display: flex; align-items: flex-start; gap: 0; margin-bottom: 6px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0; overflow: hidden; }
.rsu-bullet-row:focus-within { border-color: #93c5fd; }
.rsu-bullet-row__marker { flex-shrink: 0; width: 32px; display: flex; align-items: center; justify-content: center; color: #3b82f6; font-size: 18px; line-height: 1; padding-top: 8px; user-select: none; background: #f8faff; align-self: stretch; border-right: 1px solid #f0f0f0; }
.rsu-bullet-row__input { flex: 1; border: none !important; background: #fff; font-size: 13px; line-height: 1.65; font-family: inherit; color: #374151; padding: 8px 10px; resize: none; overflow: hidden; overflow-wrap: break-word; word-break: break-word; min-height: 36px; height: auto; box-sizing: border-box; box-shadow: none !important; outline: none !important; field-sizing: content; }
.rsu-bullet-row__input:focus { background: #fafbff; }
.rsu-bullet-row__input::placeholder { color: #c3c4c7; }
.rsu-bullet-row__remove { flex-shrink: 0; background: none; border: none; border-left: 1px solid #f0f0f0; font-size: 15px; line-height: 1; color: #d1d5db; cursor: pointer; padding: 0 8px; align-self: stretch; display: flex; align-items: center; visibility: hidden; }
.rsu-bullet-row:hover .rsu-bullet-row__remove { visibility: visible; }
.rsu-bullet-row__remove:hover { color: #dc2626; background: #fef2f2; }
.rsu-bullet-add { display: flex; align-items: center; gap: 4px; width: 100%; padding: 7px 12px; margin: 0; background: #f9fafb; border: none; border-top: 1px solid #f0f0f0; color: #9ca3af; font-size: 11px; font-weight: 600; cursor: pointer; text-align: left; }
.rsu-bullet-add:hover { background: #eff6ff; color: #2271b1; }

/* Generation selector */
.rsu-gen-select { font-size: 11px; font-weight: 500; padding: 3px 8px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; color: #9ca3af; cursor: pointer; transition: all 0.15s; line-height: 1.5; flex-shrink: 0; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239ca3af' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 6px center; padding-right: 20px; }
.rsu-gen-select:hover { border-color: #bfdbfe; background: #eff6ff; color: #374151; }
.rsu-gen-select:focus { outline: none; border-color: #2271b1; box-shadow: 0 0 0 2px rgba(34,113,177,0.15); color: #374151; }
.rsu-gen-select--active { background: #eff6ff; border-color: #93c5fd; color: #2563eb; font-weight: 600; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%232563eb' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); }
.rsu-bullet-row__gen { flex-shrink: 0; border-left: 1px solid #f0f0f0; display: flex; align-items: center; padding: 0 8px; background: #f9fafb; }
.rsu-bullet-row__gen .rsu-gen-select { font-size: 10px; padding: 2px 18px 2px 6px; background-position: right 4px center; }

.rsu-section__footer { padding: 8px 14px 12px; border-top: 1px solid #f3f4f6; }
.rsu-add-block-group { display: flex; gap: 8px; }
.rsu-add-block-group .button { font-size: 11px !important; font-weight: 600; color: #6b7280 !important; padding: 4px 12px !important; border: 1px solid #e5e7eb !important; border-radius: 4px !important; background: #f9fafb !important; cursor: pointer; box-shadow: none !important; line-height: 1.5; }
.rsu-add-block-group .button:hover { color: #2271b1 !important; border-color: #bfdbfe !important; background: #eff6ff !important; }

@media (max-width: 782px) {
	.rsu-vehicle-checks { flex-direction: column; align-items: flex-start; gap: 10px; }
	.rsu-editor-tabs { flex-wrap: wrap; }
	.rsu-editor-tab { padding: 8px 16px; font-size: 12px; }
	.rsu-editor-panel { padding: 14px; }
	.rsu-add-block-group { flex-wrap: wrap; }
	.rsu-gen-select { font-size: 9px; }
}
</style>

<div class="rsu-admin-wrap" data-rsu-active="1">
	<input type="hidden" name="rsu_is_update" value="1" />

	<div class="rsu-fields" id="rsu-fields">
		<div class="rsu-vehicle-checks">
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
			$sections_json = get_post_meta( $post->ID, '_rsu_sections_' . $slug, true );
			if ( empty( $sections_json ) ) {
				$html_content = get_post_meta( $post->ID, $vehicle['meta_key'], true );
				if ( ! empty( $html_content ) ) {
					$parsed = RSU_Admin::parse_html_to_sections( $html_content );
					if ( ! empty( $parsed ) ) {
						$sections_json = wp_json_encode( $parsed );
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

				<!-- Section builder -->
				<div class="rsu-section-builder" data-vehicle="<?php echo esc_attr( $slug ); ?>" data-generations="<?php echo esc_attr( $gen_json ); ?>">
					<div class="rsu-sections-list">
						<!-- Sections rendered by JS -->
					</div>
					<button type="button" class="button rsu-add-section" onclick="RSUSectionBuilder.addSection(this)">+ Add Section</button>
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

<script>
/**
 * Section Builder — inline vanilla JS.
 * No jQuery dependency. Runs immediately in the meta box context.
 */
var RSUSectionBuilder = (function () {
	'use strict';

	// ── DOM helpers ──
	function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
	function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function closest(el, sel) { while (el && !el.matches(sel)) { el = el.parentElement; } return el; }

	function createElement(html) {
		var div = document.createElement('div');
		div.innerHTML = html.trim();
		return div.firstChild;
	}

	// ── Get builder and its data ──
	function getBuilder(el) {
		var builder = closest(el, '.rsu-section-builder');
		if (!builder) return null;
		if (!builder._sections) {
			var vehicle = builder.getAttribute('data-vehicle');
			var panel = closest(builder, '.rsu-editor-panel');
			var jsonInput = qs('.rsu-sections-json[data-vehicle="' + vehicle + '"]', panel);
			builder._jsonInput = jsonInput;
			var raw = jsonInput ? jsonInput.value : '[]';
			try { builder._sections = JSON.parse(raw) || []; } catch (e) { builder._sections = []; }

			// Parse generations from data attribute.
			var genAttr = builder.getAttribute('data-generations');
			try { builder._generations = JSON.parse(genAttr) || {}; } catch (e) { builder._generations = {}; }
		}
		return builder;
	}

	// ── Get generation options HTML for a builder ──
	function genOptionsHTML(builder, selected) {
		var gens = builder._generations || {};
		var keys = Object.keys(gens);
		if (keys.length < 2) return ''; // No selector needed for single generation
		var html = '<select class="rsu-gen-select' + (selected ? ' rsu-gen-select--active' : '') + '" title="Generation scope">';
		html += '<option value="">All</option>';
		keys.forEach(function(slug) {
			var label = gens[slug].label || slug;
			html += '<option value="' + slug + '"' + (selected === slug ? ' selected' : '') + '>' + label + ' Only</option>';
		});
		html += '</select>';
		return html;
	}

	// ── Sync data to hidden input ──
	function syncJSON(builder) {
		if (builder._jsonInput) {
			builder._jsonInput.value = JSON.stringify(builder._sections || []);
		}
	}

	// ── Read current DOM state back into data ──
	function readFromDOM(builder) {
		var sections = [];
		qsa('.rsu-sections-list .rsu-section', builder).forEach(function (sEl) {
			var headingInput = qs('.rsu-section__heading', sEl);
			var section = {
				heading: headingInput ? headingInput.value.trim() : '',
				blocks: []
			};

			qsa('.rsu-blocks-list .rsu-block', sEl).forEach(function (bEl) {
				var type = bEl.getAttribute('data-type');
				var genSelect = qs('.rsu-block__header .rsu-gen-select', bEl);
				var blockGen = genSelect ? genSelect.value : '';

				if (type === 'list') {
					var items = [];
					qsa('.rsu-bullet-row', bEl).forEach(function (row) {
						var input = qs('.rsu-bullet-row__input', row);
						var itemGenSelect = qs('.rsu-bullet-row__gen .rsu-gen-select', row);
						var val = input ? input.value.trim() : '';
						if (val !== '') {
							var item = { text: val };
							if (itemGenSelect && itemGenSelect.value) {
								item.generation = itemGenSelect.value;
							}
							items.push(item);
						}
					});
					var block = { type: 'list', items: items };
					if (blockGen) block.generation = blockGen;
					section.blocks.push(block);
				} else {
					var textarea = qs('.rsu-block__content', bEl);
					var raw = textarea ? textarea.value : '';
					var block = { type: type, content: raw.trim() };
					if (blockGen) block.generation = blockGen;
					section.blocks.push(block);
				}
			});

			sections.push(section);
		});

		builder._sections = sections;
		syncJSON(builder);
	}

	// ── Render sections ──
	function renderSections(builder) {
		var sections = builder._sections || [];
		var list = qs('.rsu-sections-list', builder);
		list.innerHTML = '';

		if (!sections.length) {
			list.innerHTML = '<div class="rsu-sections-empty"><span class="dashicons dashicons-text-page" style="font-size:28px;width:28px;height:28px;color:#d1d5db;display:block;margin:0 auto 8px;"></span>No sections yet.<br><span style="font-size:12px;color:#c3c4c7;">Click "+ Add Section" below to start building release notes.</span></div>';
			syncJSON(builder);
			return;
		}

		sections.forEach(function (section, si) {
			list.appendChild(buildSectionEl(builder, section, si));
		});

		syncJSON(builder);

		setTimeout(autoResize, 0);
		setTimeout(autoResize, 100);
		setTimeout(autoResize, 500);
	}

	// ── Build section element ──
	function buildSectionEl(builder, section, si) {
		var el = createElement(
			'<div class="rsu-section" data-index="' + si + '">' +
				'<div class="rsu-section__header">' +
					'<span class="rsu-section__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<input type="text" class="rsu-section__heading" placeholder="Section heading (e.g. Cold Weather Improvements)" />' +
					'<button type="button" class="rsu-section__remove" title="Remove section" onclick="RSUSectionBuilder.removeSection(this)">&times;</button>' +
				'</div>' +
				'<div class="rsu-blocks-list"></div>' +
				'<div class="rsu-section__footer">' +
					'<div class="rsu-add-block-group">' +
						'<button type="button" class="button button-small rsu-add-block" onclick="RSUSectionBuilder.addBlock(this, \'paragraph\')">+ Paragraph</button>' +
						'<button type="button" class="button button-small rsu-add-block" onclick="RSUSectionBuilder.addBlock(this, \'list\')">+ Bullet List</button>' +
						'<button type="button" class="button button-small rsu-add-block" onclick="RSUSectionBuilder.addBlock(this, \'note\')">+ Note</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		qs('.rsu-section__heading', el).value = section.heading || '';

		qs('.rsu-section__heading', el).addEventListener('input', function () {
			readFromDOM(getBuilder(this));
		});

		var blocksList = qs('.rsu-blocks-list', el);
		if (section.blocks && section.blocks.length) {
			section.blocks.forEach(function (block, bi) {
				blocksList.appendChild(buildBlockEl(builder, block, bi));
			});
		}

		return el;
	}

	// ── Build block element ──
	function buildBlockEl(builder, block, bi) {
		var type = block.type || 'paragraph';
		var label = type === 'list' ? 'Bullet List' : type === 'note' ? 'Note' : 'Paragraph';
		var blockGen = block.generation || '';

		if (type === 'list') {
			var items = Array.isArray(block.items) ? block.items : [];
			var el = createElement(
				'<div class="rsu-block" data-index="' + bi + '" data-type="list">' +
					'<div class="rsu-block__header">' +
						'<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
						'<span class="rsu-block__label">' + label + '</span>' +
						genOptionsHTML(builder, blockGen) +
						'<button type="button" class="rsu-block__remove" title="Remove block" onclick="RSUSectionBuilder.removeBlock(this)">&times;</button>' +
					'</div>' +
					'<div class="rsu-bullet-list"></div>' +
					'<button type="button" class="rsu-bullet-add" onclick="RSUSectionBuilder.addBullet(this)" title="Add bullet point">+ Add bullet</button>' +
				'</div>'
			);

			var listContainer = qs('.rsu-bullet-list', el);
			if (items.length === 0) items = [{ text: '' }];
			items.forEach(function (item) {
				if (typeof item === 'string') item = { text: item };
				listContainer.appendChild(buildBulletRow(builder, item));
			});

			// Gen select change handler.
			var genSel = qs('.rsu-gen-select', el);
			if (genSel) {
				genSel.addEventListener('change', function() {
					this.classList.toggle('rsu-gen-select--active', !!this.value);
					readFromDOM(getBuilder(this));
				});
			}

			return el;
		}

		var placeholder = type === 'note' ? 'Note text...' : 'Paragraph text...';
		var content = block.content || '';

		var el = createElement(
			'<div class="rsu-block" data-index="' + bi + '" data-type="' + type + '">' +
				'<div class="rsu-block__header">' +
					'<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<span class="rsu-block__label">' + label + '</span>' +
					genOptionsHTML(builder, blockGen) +
					'<button type="button" class="rsu-block__remove" title="Remove block" onclick="RSUSectionBuilder.removeBlock(this)">&times;</button>' +
				'</div>' +
				'<textarea class="rsu-block__content" placeholder="' + placeholder + '" rows="1"></textarea>' +
			'</div>'
		);

		var textarea = qs('.rsu-block__content', el);
		textarea.value = content;

		textarea.addEventListener('input', function () {
			autoResizeTextarea(this);
			readFromDOM(getBuilder(this));
		});

		textarea.addEventListener('focus', function () {
			autoResizeTextarea(this);
		});

		// Gen select change handler.
		var genSel = qs('.rsu-gen-select', el);
		if (genSel) {
			genSel.addEventListener('change', function() {
				this.classList.toggle('rsu-gen-select--active', !!this.value);
				readFromDOM(getBuilder(this));
			});
		}

		return el;
	}

	// ── Build a single bullet row ──
	function buildBulletRow(builder, item) {
		if (typeof item === 'string') item = { text: item };
		var text = item.text || '';
		var itemGen = item.generation || '';
		var genHTML = genOptionsHTML(builder, itemGen);
		var genCell = genHTML ? '<div class="rsu-bullet-row__gen">' + genHTML + '</div>' : '';

		var row = createElement(
			'<div class="rsu-bullet-row">' +
				'<span class="rsu-bullet-row__marker">&bull;</span>' +
				'<textarea class="rsu-bullet-row__input" placeholder="Bullet point text..." rows="1"></textarea>' +
				genCell +
				'<button type="button" class="rsu-bullet-row__remove" title="Remove bullet" onclick="RSUSectionBuilder.removeBullet(this)">&times;</button>' +
			'</div>'
		);

		var input = qs('.rsu-bullet-row__input', row);
		input.value = text;

		input.addEventListener('input', function () {
			autoResizeTextarea(this);
			readFromDOM(getBuilder(this));
		});

		input.addEventListener('focus', function () {
			autoResizeTextarea(this);
		});

		// Gen select change handler.
		var genSel = qs('.rsu-gen-select', row);
		if (genSel) {
			genSel.addEventListener('change', function() {
				this.classList.toggle('rsu-gen-select--active', !!this.value);
				readFromDOM(getBuilder(this));
			});
		}

		return row;
	}

	// ── Auto-resize a single textarea ──
	function autoResizeTextarea(ta) {
		ta.style.height = '0';
		ta.style.height = Math.max(ta.scrollHeight, 36) + 'px';
	}

	// ── Auto-resize existing textareas on load ──
	function autoResize() {
		qsa('.rsu-block__content, .rsu-bullet-row__input').forEach(function (ta) {
			autoResizeTextarea(ta);
		});
	}

	// ══════════════════════════════════════════════
	// Public API (called via onclick attributes)
	// ══════════════════════════════════════════════

	function addSection(btn) {
		var builder = getBuilder(btn);
		if (!builder) return;

		readFromDOM(builder);
		builder._sections.push({
			heading: '',
			blocks: [{ type: 'paragraph', content: '' }]
		});

		renderSections(builder);

		var lastHeading = builder.querySelectorAll('.rsu-section__heading');
		if (lastHeading.length) {
			lastHeading[lastHeading.length - 1].focus();
		}
	}

	function addBlock(btn, type) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		readFromDOM(builder);

		var sectionIndex = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		var newBlock = type === 'list' ? { type: 'list', items: [] } : { type: type, content: '' };

		builder._sections[sectionIndex].blocks.push(newBlock);
		renderSections(builder);

		var sectionEls = qsa('.rsu-section', builder);
		if (sectionEls[sectionIndex]) {
			var inputs = qsa('.rsu-block__content, .rsu-bullet-row__input', sectionEls[sectionIndex]);
			if (inputs.length) inputs[inputs.length - 1].focus();
		}
	}

	function removeSection(btn) {
		if (!confirm('Remove this section?')) return;

		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		readFromDOM(builder);
		var idx = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		builder._sections.splice(idx, 1);
		renderSections(builder);
	}

	function removeBlock(btn) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		var blockEl = closest(btn, '.rsu-block');
		if (!builder || !sectionEl || !blockEl) return;

		readFromDOM(builder);
		var si = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		var bi = qsa('.rsu-blocks-list .rsu-block', sectionEl).indexOf(blockEl);
		builder._sections[si].blocks.splice(bi, 1);
		renderSections(builder);
	}

	function addBullet(btn) {
		var blockEl = closest(btn, '.rsu-block');
		if (!blockEl) return;
		var builder = getBuilder(btn);
		var listContainer = qs('.rsu-bullet-list', blockEl);
		var row = buildBulletRow(builder, { text: '' });
		listContainer.appendChild(row);
		qs('.rsu-bullet-row__input', row).focus();
		readFromDOM(builder);
	}

	function removeBullet(btn) {
		var row = closest(btn, '.rsu-bullet-row');
		var blockEl = closest(btn, '.rsu-block');
		if (!row || !blockEl) return;
		var builder = getBuilder(btn);
		var listContainer = qs('.rsu-bullet-list', blockEl);
		row.remove();
		if (!qs('.rsu-bullet-row', listContainer)) {
			var newRow = buildBulletRow(builder, { text: '' });
			listContainer.appendChild(newRow);
			qs('.rsu-bullet-row__input', newRow).focus();
		}
		readFromDOM(builder);
	}

	// ══════════════════════════════════════════════
	// Initialization
	// ══════════════════════════════════════════════

	function init() {
		qsa('.rsu-section-builder').forEach(function (builder) {
			if (builder._initialized) return;
			getBuilder(builder);
			renderSections(builder);
			builder._initialized = true;
		});

		var tabs = document.getElementById('rsu-editor-tabs');
		if (tabs) {
			var visibleTabs = qsa('.rsu-editor-tab', tabs).filter(function (t) { return t.style.display !== 'none'; });
			var hasActive = visibleTabs.some(function (t) { return t.classList.contains('rsu-editor-tab--active'); });
			if (visibleTabs.length && !hasActive) {
				activateTab(visibleTabs[0].getAttribute('data-vehicle'));
			}
		}

		setTimeout(autoResize, 100);
	}

	// Tab switching.
	function activateTab(vehicle) {
		var tabs = document.getElementById('rsu-editor-tabs');
		if (!tabs) return;

		qsa('.rsu-editor-tab', tabs).forEach(function (t) {
			t.classList.remove('rsu-editor-tab--active');
			t.setAttribute('aria-selected', 'false');
		});
		qsa('.rsu-editor-panel').forEach(function (p) {
			p.classList.add('rsu-editor-panel--hidden');
		});

		var tab = qs('[data-vehicle="' + vehicle + '"]', tabs);
		if (tab) {
			tab.classList.add('rsu-editor-tab--active');
			tab.setAttribute('aria-selected', 'true');
		}

		var panel = document.getElementById('rsu-editor-panel-' + vehicle);
		if (panel) {
			panel.classList.remove('rsu-editor-panel--hidden');
			panel.style.display = '';
			setTimeout(autoResize, 10);
		}
	}

	// Vehicle checkboxes.
	document.addEventListener('change', function (e) {
		if (e.target.classList.contains('rsu-vehicle-checkbox')) {
			var veh = e.target.getAttribute('data-vehicle');
			var tabsEl = document.getElementById('rsu-editor-tabs');
			var tabEl = qs('[data-vehicle="' + veh + '"]', tabsEl);
			var panelEl = document.getElementById('rsu-editor-panel-' + veh);

			if (e.target.checked) {
				if (tabEl) tabEl.style.display = '';
				var visActive = qsa('.rsu-editor-tab', tabsEl).filter(function (t) {
					return t.style.display !== 'none' && t.classList.contains('rsu-editor-tab--active');
				});
				if (!visActive.length) activateTab(veh);
			} else {
				if (tabEl) { tabEl.style.display = 'none'; tabEl.classList.remove('rsu-editor-tab--active'); }
				if (panelEl) { panelEl.classList.add('rsu-editor-panel--hidden'); panelEl.style.display = 'none'; }
				var firstVis = qsa('.rsu-editor-tab', tabsEl).filter(function (t) { return t.style.display !== 'none'; });
				if (firstVis.length) activateTab(firstVis[0].getAttribute('data-vehicle'));
			}
		}
	});

	// Tab clicks.
	document.addEventListener('click', function (e) {
		var tab = closest(e.target, '.rsu-editor-tab');
		if (tab) {
			e.preventDefault();
			activateTab(tab.getAttribute('data-vehicle'));
		}
	});

	// Copy from.
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('rsu-copy-from-select')) return;

		var sourceSlug = e.target.value;
		var targetSlug = e.target.getAttribute('data-target');
		if (!sourceSlug) return;

		if (!confirm('Copy sections from ' + sourceSlug + '? This will overwrite the current sections.')) {
			e.target.value = '';
			return;
		}

		var srcBuilder = qs('.rsu-section-builder[data-vehicle="' + sourceSlug + '"]');
		var tgtBuilder = qs('.rsu-section-builder[data-vehicle="' + targetSlug + '"]');
		if (!srcBuilder || !tgtBuilder) { e.target.value = ''; return; }

		getBuilder(srcBuilder);
		getBuilder(tgtBuilder);
		readFromDOM(srcBuilder);

		tgtBuilder._sections = JSON.parse(JSON.stringify(srcBuilder._sections));
		renderSections(tgtBuilder);
		e.target.value = '';
	});

	// Run init immediately, plus retry for Block Editor.
	init();
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	var retryCount = 0;
	var retryTimer = setInterval(function () {
		retryCount++;
		var builders = qsa('.rsu-section-builder');
		var uninitialized = builders.filter(function (b) { return !b._initialized; });
		if (uninitialized.length) init();
		if (retryCount >= 10 || !uninitialized.length) clearInterval(retryTimer);
	}, 500);

	return {
		addSection: addSection,
		addBlock: addBlock,
		removeSection: removeSection,
		removeBlock: removeBlock,
		addBullet: addBullet,
		removeBullet: removeBullet,
		activateTab: activateTab,
		init: init
	};
})();
</script>
