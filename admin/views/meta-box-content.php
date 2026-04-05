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

// For new posts with no platforms saved, use the default platforms setting.
if ( empty( $active_platforms ) && 'auto-draft' === get_post_status( $post->ID ) ) {
	$active_platforms = (array) RSU_Settings::get( 'default_platforms', array( 'gen1', 'gen2' ) );
	$active_platforms = array_intersect( $active_platforms, array_keys( $all_platforms ) );
}

wp_nonce_field( 'rsu_meta_save', 'rsu_meta_nonce' );
?>

<style>
/* RSU Admin — inlined for Block Editor compatibility */
.rsu-admin-wrap { margin: -6px -12px -12px; padding: 0; }

.rsu-toggle-row { padding: 14px 20px; background: linear-gradient(to bottom, #f9fafb, #f3f4f6); border-bottom: 1px solid #dcdcde; }
.rsu-toggle-label { font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; color: #1d2327; }
.rsu-toggle-label input[type="checkbox"] { margin: 0; width: 16px; height: 16px; }

.rsu-platform-checks { padding: 14px 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 20px; border-bottom: 1px solid #e5e7eb; background: #fff; }
.rsu-platform-checks__label { font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
.rsu-platform-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; cursor: pointer; color: #374151; }
.rsu-platform-check input[type="checkbox"] { margin: 0; }
.rsu-platform-desc { color: #9ca3af; font-size: 11px; font-weight: 400; }

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

.rsu-block { border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 10px; background: #fff; overflow: hidden; }
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

.rsu-block__content { display: block; width: 100%; padding: 10px 12px; border: none !important; resize: none; font-size: 13px; line-height: 1.65; font-family: inherit; color: #374151; overflow: hidden; min-height: 56px; box-sizing: border-box; background: #fff; box-shadow: none !important; outline: none !important; }
.rsu-block__content:focus { background: #fafbff; }
.rsu-block__content::placeholder { color: #c3c4c7; }
.rsu-block[data-type="list"] .rsu-block__content { background: #f8faff; }
.rsu-block[data-type="list"] .rsu-block__content:focus { background: #eff6ff; }
.rsu-block[data-type="note"] .rsu-block__content { background: #fffbeb; }
.rsu-block[data-type="note"] .rsu-block__content:focus { background: #fef3c7; }

.rsu-section__footer { padding: 8px 14px 12px; border-top: 1px solid #f3f4f6; }
.rsu-add-block-group { display: flex; gap: 8px; }
.rsu-add-block-group .button { font-size: 11px !important; font-weight: 600; color: #6b7280 !important; padding: 4px 12px !important; border: 1px solid #e5e7eb !important; border-radius: 4px !important; background: #f9fafb !important; cursor: pointer; box-shadow: none !important; line-height: 1.5; }
.rsu-add-block-group .button:hover { color: #2271b1 !important; border-color: #bfdbfe !important; background: #eff6ff !important; }

@media (max-width: 782px) {
	.rsu-platform-checks { flex-direction: column; align-items: flex-start; gap: 10px; }
	.rsu-editor-tabs { flex-wrap: wrap; }
	.rsu-editor-tab { padding: 8px 16px; font-size: 12px; }
	.rsu-editor-panel { padding: 14px; }
	.rsu-add-block-group { flex-wrap: wrap; }
}
</style>

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

			// Load structured sections JSON if available, otherwise parse from HTML.
			$sections_json = get_post_meta( $post->ID, '_rsu_sections_' . $slug, true );
			if ( empty( $sections_json ) ) {
				$html_content = get_post_meta( $post->ID, $platform['meta_key'], true );
				if ( ! empty( $html_content ) ) {
					$parsed = RSU_Admin::parse_html_to_sections( $html_content );
					if ( ! empty( $parsed ) ) {
						$sections_json = wp_json_encode( $parsed );
					}
				}
			}

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
					<button type="button" class="button rsu-add-section" onclick="RSUSectionBuilder.addSection(this)">+ Add Section</button>
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
			var platform = builder.getAttribute('data-platform');
			var panel = closest(builder, '.rsu-editor-panel');
			var jsonInput = qs('.rsu-sections-json[data-platform="' + platform + '"]', panel);
			builder._jsonInput = jsonInput;
			var raw = jsonInput ? jsonInput.value : '[]';
			try { builder._sections = JSON.parse(raw) || []; } catch (e) { builder._sections = []; }
		}
		return builder;
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
				var textarea = qs('.rsu-block__content', bEl);
				var raw = textarea ? textarea.value : '';

				if (type === 'list') {
					section.blocks.push({
						type: 'list',
						items: raw.split('\n').filter(function (l) { return l.trim() !== ''; })
					});
				} else {
					section.blocks.push({ type: type, content: raw.trim() });
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
			list.appendChild(buildSectionEl(section, si));
		});

		syncJSON(builder);
	}

	// ── Build section element ──
	function buildSectionEl(section, si) {
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

		// Live sync heading changes.
		qs('.rsu-section__heading', el).addEventListener('input', function () {
			readFromDOM(getBuilder(this));
		});

		var blocksList = qs('.rsu-blocks-list', el);
		if (section.blocks && section.blocks.length) {
			section.blocks.forEach(function (block, bi) {
				blocksList.appendChild(buildBlockEl(block, bi));
			});
		}

		return el;
	}

	// ── Build block element ──
	function buildBlockEl(block, bi) {
		var type = block.type || 'paragraph';
		var label = type === 'list' ? 'Bullet List' : type === 'note' ? 'Note' : 'Paragraph';
		var placeholder, content;

		if (type === 'list') {
			placeholder = 'One bullet point per line';
			content = Array.isArray(block.items) ? block.items.join('\n') : '';
		} else {
			placeholder = type === 'note' ? 'Note text...' : 'Paragraph text...';
			content = block.content || '';
		}

		var el = createElement(
			'<div class="rsu-block" data-index="' + bi + '" data-type="' + type + '">' +
				'<div class="rsu-block__header">' +
					'<span class="rsu-block__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<span class="rsu-block__label">' + label + '</span>' +
					'<button type="button" class="rsu-block__remove" title="Remove block" onclick="RSUSectionBuilder.removeBlock(this)">&times;</button>' +
				'</div>' +
				'<textarea class="rsu-block__content" placeholder="' + placeholder + '" rows="3"></textarea>' +
			'</div>'
		);

		var textarea = qs('.rsu-block__content', el);
		textarea.value = content;

		// Live sync and auto-resize.
		textarea.addEventListener('input', function () {
			this.style.height = 'auto';
			this.style.height = this.scrollHeight + 'px';
			readFromDOM(getBuilder(this));
		});

		return el;
	}

	// ── Auto-resize existing textareas on load ──
	function autoResize() {
		qsa('.rsu-block__content').forEach(function (ta) {
			ta.style.height = 'auto';
			ta.style.height = ta.scrollHeight + 'px';
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
			var blocks = qsa('.rsu-block__content', sectionEls[sectionIndex]);
			if (blocks.length) blocks[blocks.length - 1].focus();
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

		// Tab: ensure first active tab is shown.
		var tabs = document.getElementById('rsu-editor-tabs');
		if (tabs) {
			var visibleTabs = qsa('.rsu-editor-tab', tabs).filter(function (t) { return t.style.display !== 'none'; });
			var hasActive = visibleTabs.some(function (t) { return t.classList.contains('rsu-editor-tab--active'); });
			if (visibleTabs.length && !hasActive) {
				activateTab(visibleTabs[0].getAttribute('data-platform'));
			}
		}

		setTimeout(autoResize, 100);
	}

	// Tab switching.
	function activateTab(platform) {
		var tabs = document.getElementById('rsu-editor-tabs');
		if (!tabs) return;

		qsa('.rsu-editor-tab', tabs).forEach(function (t) {
			t.classList.remove('rsu-editor-tab--active');
			t.setAttribute('aria-selected', 'false');
		});
		qsa('.rsu-editor-panel').forEach(function (p) {
			p.classList.add('rsu-editor-panel--hidden');
		});

		var tab = qs('[data-platform="' + platform + '"]', tabs);
		if (tab) {
			tab.classList.add('rsu-editor-tab--active');
			tab.setAttribute('aria-selected', 'true');
		}

		var panel = document.getElementById('rsu-editor-panel-' + platform);
		if (panel) {
			panel.classList.remove('rsu-editor-panel--hidden');
			panel.style.display = '';
		}
	}

	// Toggle field.
	document.addEventListener('change', function (e) {
		if (e.target.id === 'rsu-is-update') {
			var fields = document.getElementById('rsu-fields');
			if (fields) fields.style.display = e.target.checked ? '' : 'none';
		}

		// Platform checkboxes.
		if (e.target.classList.contains('rsu-platform-checkbox')) {
			var plat = e.target.getAttribute('data-platform');
			var tabsEl = document.getElementById('rsu-editor-tabs');
			var tabEl = qs('[data-platform="' + plat + '"]', tabsEl);
			var panelEl = document.getElementById('rsu-editor-panel-' + plat);

			if (e.target.checked) {
				if (tabEl) tabEl.style.display = '';
				var visActive = qsa('.rsu-editor-tab', tabsEl).filter(function (t) {
					return t.style.display !== 'none' && t.classList.contains('rsu-editor-tab--active');
				});
				if (!visActive.length) activateTab(plat);
			} else {
				if (tabEl) { tabEl.style.display = 'none'; tabEl.classList.remove('rsu-editor-tab--active'); }
				if (panelEl) { panelEl.classList.add('rsu-editor-panel--hidden'); panelEl.style.display = 'none'; }
				var firstVis = qsa('.rsu-editor-tab', tabsEl).filter(function (t) { return t.style.display !== 'none'; });
				if (firstVis.length) activateTab(firstVis[0].getAttribute('data-platform'));
			}
		}
	});

	// Tab clicks.
	document.addEventListener('click', function (e) {
		var tab = closest(e.target, '.rsu-editor-tab');
		if (tab) {
			e.preventDefault();
			activateTab(tab.getAttribute('data-platform'));
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

		var srcBuilder = qs('.rsu-section-builder[data-platform="' + sourceSlug + '"]');
		var tgtBuilder = qs('.rsu-section-builder[data-platform="' + targetSlug + '"]');
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
		activateTab: activateTab,
		init: init
	};
})();
</script>
