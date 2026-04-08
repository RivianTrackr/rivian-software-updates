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
/* RSU Admin — inlined for Block Editor compatibility. Uses RivianTrackr design tokens. */
.rsu-admin-wrap { --rsu-action: #0071e3; --rsu-action-hover: #0077ed; --rsu-text: #1d1d1f; --rsu-text-secondary: #6e6e73; --rsu-text-muted: #86868b; --rsu-accent: #fba919; --rsu-error: #ff3b30; --rsu-error-light: #ffe5e5; --rsu-border: #d2d2d7; --rsu-border-light: #e8e8ed; --rsu-bg-light: #f5f5f7; --rsu-bg-info: #dbeafe; --rsu-white: #ffffff; --rsu-placeholder: #86868b; margin: -6px -12px -12px; padding: 0; }

.rsu-vehicle-checks { padding: 14px 20px; display: flex; flex-wrap: wrap; align-items: center; gap: 20px; border-bottom: 1px solid var(--rsu-border); background: var(--rsu-white); }
.rsu-vehicle-checks__label { font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--rsu-text-secondary); }
.rsu-vehicle-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; cursor: pointer; color: var(--rsu-text); }
.rsu-vehicle-check input[type="checkbox"] { margin: 0; }
.rsu-vehicle-desc { color: var(--rsu-text-muted); font-size: 11px; font-weight: 400; }

.rsu-editor-tabs { display: flex; gap: 0; padding: 0 20px; background: var(--rsu-bg-light); border-bottom: 1px solid var(--rsu-border); }
.rsu-editor-tab { padding: 10px 24px; border: none; border-bottom: 2px solid transparent; background: transparent; color: var(--rsu-text-muted); font-size: 13px; font-weight: 500; cursor: pointer; transition: color 0.15s, border-color 0.15s; margin-bottom: -1px; }
.rsu-editor-tab:hover { color: var(--rsu-text); }
.rsu-editor-tab--active { color: var(--rsu-action); font-weight: 600; border-bottom-color: var(--rsu-action); }

.rsu-editor-panel { padding: 20px; background: var(--rsu-white); }
.rsu-editor-panel--hidden { position: absolute; left: -9999px; visibility: hidden; }

.rsu-editor-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; }
.rsu-copy-from { font-size: 13px; font-weight: 500; color: var(--rsu-text-muted); display: inline-flex; align-items: center; gap: 8px; }
.rsu-copy-from-select { font-size: 13px; padding: 6px 28px 6px 10px; border-radius: 8px; border: 1px solid var(--rsu-border); background: var(--rsu-white); color: var(--rsu-text); appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2386868b' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; }
.rsu-copy-from-select:focus { border-color: var(--rsu-action); box-shadow: 0 0 0 3px rgba(0,113,227,0.1); outline: none; }
.rsu-copy-from-select:hover { border-color: var(--rsu-action); }

.rsu-sections-empty { padding: 40px 24px; text-align: center; color: var(--rsu-text-muted); font-size: 14px; background: var(--rsu-bg-light); border: 2px dashed var(--rsu-border); border-radius: 12px; margin-bottom: 16px; }

.rsu-section-builder .rsu-add-section { display: block; width: 100%; padding: 12px 16px; font-size: 13px; font-weight: 600; border: 2px dashed var(--rsu-border) !important; background: var(--rsu-bg-light) !important; color: var(--rsu-action) !important; border-radius: 8px !important; cursor: pointer; transition: all 0.15s ease; text-align: center; box-shadow: none !important; }
.rsu-section-builder .rsu-add-section:hover { border-color: var(--rsu-action) !important; background: var(--rsu-bg-info) !important; }

.rsu-section { border: 1px solid var(--rsu-border); border-radius: 12px; margin-bottom: 16px; background: var(--rsu-white); box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

.rsu-section__header { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--rsu-bg-light); border-bottom: 1px solid var(--rsu-border); border-radius: 12px 12px 0 0; }
.rsu-section__drag { cursor: grab; color: var(--rsu-border); font-size: 16px; flex-shrink: 0; }
.rsu-section__drag:hover { color: var(--rsu-text-secondary); }

.rsu-section__heading { flex: 1; font-size: 14px !important; font-weight: 600; padding: 8px 12px !important; border: 1px solid var(--rsu-border) !important; border-radius: 8px !important; background: var(--rsu-white) !important; color: var(--rsu-text); box-shadow: none !important; }
.rsu-section__heading:focus { border-color: var(--rsu-action) !important; box-shadow: 0 0 0 2px rgba(0,113,227,0.15) !important; outline: none; }
.rsu-section__heading::placeholder { font-weight: 400; color: var(--rsu-placeholder); font-size: 13px; }

.rsu-section__remove { background: none; border: none; font-size: 18px; line-height: 1; color: var(--rsu-text-muted); cursor: pointer; padding: 4px 8px; border-radius: 4px; flex-shrink: 0; }
.rsu-section__remove:hover { color: var(--rsu-error); background: var(--rsu-error-light); }

.rsu-blocks-list { padding: 12px 14px 4px; }

.rsu-block { border: 1px solid var(--rsu-border-light); border-radius: 8px; margin-bottom: 10px; background: var(--rsu-white); overflow: visible; }
.rsu-block:focus-within { border-color: var(--rsu-action); }
.rsu-block[data-type="list"] { border-left: 3px solid var(--rsu-action); }
.rsu-block[data-type="note"] { border-left: 3px solid var(--rsu-accent); }

.rsu-block__header { display: flex; align-items: center; gap: 6px; padding: 5px 10px; background: var(--rsu-bg-light); border-bottom: 1px solid var(--rsu-border-light); }
.rsu-block__drag { cursor: grab; color: var(--rsu-border); font-size: 13px; flex-shrink: 0; }
.rsu-block__label { flex: 1; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--rsu-text-muted); }
.rsu-block[data-type="list"] .rsu-block__label { color: var(--rsu-action); }
.rsu-block[data-type="note"] .rsu-block__label { color: var(--rsu-accent); }

.rsu-block__remove { background: none; border: none; font-size: 15px; line-height: 1; color: var(--rsu-text-muted); cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.rsu-block__remove:hover { color: var(--rsu-error); background: var(--rsu-error-light); }

.rsu-block__content { display: block; width: 100%; padding: 10px 12px; border: none !important; resize: none; font-size: 13px; line-height: 1.65; font-family: inherit; color: var(--rsu-text); overflow: hidden; min-height: 56px; height: auto; box-sizing: border-box; background: var(--rsu-white); box-shadow: none !important; outline: none !important; field-sizing: content; }
.rsu-block__content:focus { background: var(--rsu-bg-light); }
.rsu-block__content::placeholder { color: var(--rsu-placeholder); }
.rsu-block[data-type="note"] .rsu-block__content { background: #fef9ee; }
.rsu-block[data-type="note"] .rsu-block__content:focus { background: #fdf0d5; }

.rsu-bullet-list { padding: 8px 12px 4px; }
.rsu-bullet-row { display: flex; align-items: flex-start; gap: 0; margin-bottom: 6px; background: var(--rsu-white); border: 1px solid var(--rsu-border-light); border-radius: 8px; padding: 0; overflow: hidden; }
.rsu-bullet-row:focus-within { border-color: var(--rsu-action); }
.rsu-bullet-row__marker { flex-shrink: 0; width: 32px; display: flex; align-items: center; justify-content: center; color: var(--rsu-action); font-size: 18px; line-height: 1; padding-top: 8px; user-select: none; background: var(--rsu-bg-light); align-self: stretch; border-right: 1px solid var(--rsu-border-light); }
.rsu-bullet-row__input { flex: 1; border: none !important; background: var(--rsu-white); font-size: 13px; line-height: 1.65; font-family: inherit; color: var(--rsu-text); padding: 8px 10px; resize: none; overflow: hidden; overflow-wrap: break-word; word-break: break-word; min-height: 36px; height: auto; box-sizing: border-box; box-shadow: none !important; outline: none !important; field-sizing: content; }
.rsu-bullet-row__input:focus { background: var(--rsu-bg-light); }
.rsu-bullet-row__input::placeholder { color: var(--rsu-placeholder); }
.rsu-bullet-row__remove { flex-shrink: 0; background: none; border: none; border-left: 1px solid var(--rsu-border-light); font-size: 15px; line-height: 1; color: var(--rsu-text-muted); cursor: pointer; padding: 0 8px; align-self: stretch; display: flex; align-items: center; visibility: hidden; }
.rsu-bullet-row:hover .rsu-bullet-row__remove { visibility: visible; }
.rsu-bullet-row__remove:hover { color: var(--rsu-error); background: var(--rsu-error-light); }
.rsu-bullet-add { display: flex; align-items: center; gap: 4px; width: 100%; padding: 7px 12px; margin: 0; background: var(--rsu-bg-light); border: none; border-top: 1px solid var(--rsu-border-light); color: var(--rsu-text-muted); font-size: 11px; font-weight: 600; cursor: pointer; text-align: left; }
.rsu-bullet-add:hover { background: var(--rsu-bg-info); color: var(--rsu-action); }

/* Drag and drop */
.rsu-drag-placeholder { border: 2px dashed var(--rsu-border); border-radius: 12px; margin-bottom: 16px; background: var(--rsu-bg-light); }
.rsu-drag-active { position: fixed; z-index: 10000; opacity: 0.9; box-shadow: 0 8px 24px rgba(0,0,0,0.15); pointer-events: none; transition: none; }

/* Confirm dialog */
.rsu-confirm-dialog { border: none; border-radius: 12px; padding: 0; box-shadow: 0 8px 32px rgba(0,0,0,0.2); max-width: 400px; width: calc(100% - 32px); font-family: inherit; }
.rsu-confirm-dialog::backdrop { background: rgba(0,0,0,0.4); }
.rsu-confirm-dialog__body { padding: 24px 24px 0; }
.rsu-confirm-dialog__message { font-size: 14px; line-height: 1.6; color: var(--rsu-text); margin: 0; }
.rsu-confirm-dialog__actions { display: flex; justify-content: flex-end; gap: 8px; padding: 16px 24px 20px; }
.rsu-confirm-dialog__cancel { padding: 8px 16px; font-size: 13px; font-weight: 500; border: 1px solid var(--rsu-border) !important; border-radius: 8px !important; background: var(--rsu-bg-light) !important; color: var(--rsu-text-secondary) !important; cursor: pointer; box-shadow: none !important; }
.rsu-confirm-dialog__cancel:hover { background: #e8e8ed !important; }
.rsu-confirm-dialog__ok { padding: 8px 16px; font-size: 13px; font-weight: 600; border: none !important; border-radius: 8px !important; background: #ff3b30 !important; color: #ffffff !important; cursor: pointer; box-shadow: none !important; }
.rsu-confirm-dialog__ok:hover { background: #e0352b !important; }

/* Collapse / expand */
.rsu-section--collapsed .rsu-blocks-list,
.rsu-section--collapsed .rsu-section__footer { display: none; }
.rsu-section__toggle { background: none; border: none; font-size: 16px; color: var(--rsu-text-muted); cursor: pointer; padding: 2px 4px; flex-shrink: 0; transition: transform 0.2s ease; }
.rsu-section__toggle:hover { color: var(--rsu-text); }
.rsu-section--collapsed .rsu-section__toggle { transform: rotate(-90deg); }

/* Duplicate button */
.rsu-section__dupe { background: none; border: none; font-size: 14px; color: var(--rsu-text-muted); cursor: pointer; padding: 4px 6px; border-radius: 4px; flex-shrink: 0; }
.rsu-section__dupe:hover { color: var(--rsu-action); background: var(--rsu-bg-info); }

/* Paste import */
.rsu-import-bar { display: flex; gap: 8px; margin-bottom: 12px; }
.rsu-import-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 12px; font-weight: 600; border: 1px solid var(--rsu-border); border-radius: 8px; background: var(--rsu-white); color: var(--rsu-text-secondary); cursor: pointer; transition: all 0.15s ease; }
.rsu-import-btn:hover { border-color: var(--rsu-action); color: var(--rsu-action); background: var(--rsu-bg-info); }
.rsu-import-dialog { border: none; border-radius: 12px; padding: 0; box-shadow: 0 8px 32px rgba(0,0,0,0.2); max-width: 560px; width: calc(100% - 32px); font-family: inherit; }
.rsu-import-dialog::backdrop { background: rgba(0,0,0,0.4); }
.rsu-import-dialog__header { padding: 20px 24px 0; }
.rsu-import-dialog__header h3 { font-size: 16px; font-weight: 600; color: var(--rsu-text); margin: 0 0 4px; }
.rsu-import-dialog__header p { font-size: 12px; color: var(--rsu-text-muted); margin: 0; }
.rsu-import-dialog__body { padding: 12px 24px; }
.rsu-import-dialog__textarea { width: 100%; min-height: 200px; border: 1px solid var(--rsu-border); border-radius: 8px; padding: 12px; font-size: 13px; line-height: 1.6; font-family: inherit; color: var(--rsu-text); resize: vertical; box-sizing: border-box; }
.rsu-import-dialog__textarea:focus { border-color: var(--rsu-action); box-shadow: 0 0 0 3px rgba(0,113,227,0.1); outline: none; }
.rsu-import-dialog__textarea::placeholder { color: var(--rsu-placeholder); }
.rsu-import-dialog__actions { display: flex; justify-content: flex-end; gap: 8px; padding: 8px 24px 20px; }
.rsu-import-dialog__cancel { padding: 8px 16px; font-size: 13px; font-weight: 500; border: 1px solid var(--rsu-border); border-radius: 8px; background: var(--rsu-bg-light); color: var(--rsu-text); cursor: pointer; }
.rsu-import-dialog__cancel:hover { background: #e8e8ed; }
.rsu-import-dialog__submit { padding: 8px 16px; font-size: 13px; font-weight: 600; border: none; border-radius: 8px; background: var(--rsu-action); color: #fff; cursor: pointer; }
.rsu-import-dialog__submit:hover { background: var(--rsu-action-hover); }

/* Generation selector */
.rsu-gen-select { font-size: 10px; font-weight: 500; padding: 2px 18px 2px 6px; border: 1px solid var(--rsu-border); border-radius: 4px; background: var(--rsu-bg-light); color: var(--rsu-text-muted); cursor: pointer; transition: all 0.15s; line-height: 1.5; flex-shrink: 0; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5' viewBox='0 0 8 5'%3E%3Cpath d='M1 1l3 3 3-3' stroke='%2386868b' stroke-width='1.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 5px center; }
.rsu-gen-select:hover { border-color: var(--rsu-action); background: var(--rsu-bg-info); color: var(--rsu-text); }
.rsu-gen-select:focus { outline: none; border-color: var(--rsu-action); box-shadow: 0 0 0 2px rgba(0,113,227,0.15); color: var(--rsu-text); }
.rsu-gen-select--active { background: var(--rsu-bg-info); border-color: var(--rsu-action); color: var(--rsu-action); font-weight: 600; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5' viewBox='0 0 8 5'%3E%3Cpath d='M1 1l3 3 3-3' stroke='%230071e3' stroke-width='1.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); }
.rsu-bullet-row__gen { flex-shrink: 0; display: flex; align-items: center; padding: 4px 10px; }

.rsu-section__footer { padding: 8px 14px 12px; border-top: 1px solid var(--rsu-border-light); }
.rsu-add-block-group { display: flex; gap: 8px; }
.rsu-add-block-group .button { font-size: 11px !important; font-weight: 600; color: var(--rsu-text-secondary) !important; padding: 4px 12px !important; border: 1px solid var(--rsu-border) !important; border-radius: 6px !important; background: var(--rsu-bg-light) !important; cursor: pointer; box-shadow: none !important; line-height: 1.5; }
.rsu-add-block-group .button:hover { color: var(--rsu-action) !important; border-color: var(--rsu-action) !important; background: var(--rsu-bg-info) !important; }

@media (max-width: 782px) {
	.rsu-vehicle-checks { flex-direction: column; align-items: flex-start; gap: 10px; }
	.rsu-editor-tabs { flex-wrap: wrap; }
	.rsu-editor-tab { padding: 8px 16px; font-size: 12px; }
	.rsu-editor-panel { padding: 14px; }
	.rsu-add-block-group { flex-wrap: wrap; }
	.rsu-gen-select { font-size: 10px; padding: 2px 16px 2px 6px; }
}
</style>

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
					<button type="button" class="rsu-import-btn" data-vehicle="<?php echo esc_attr( $slug ); ?>" onclick="RSUSectionBuilder.showImport(this)">
						<span class="dashicons dashicons-upload" style="font-size:14px;width:14px;height:14px;"></span>
						Paste Release Notes
					</button>
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

	// ── Styled confirm dialog ──
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
			qs('.rsu-confirm-dialog__cancel', dialog).addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(false); });
			qs('.rsu-confirm-dialog__ok', dialog).addEventListener('click', function () { dialog.close(); dialog.remove(); resolve(true); });
			dialog.addEventListener('cancel', function () { dialog.remove(); resolve(false); });
		});
	}

	// ── Debounce helper ──
	var _debounceTimers = {};
	function debounce(key, fn, delay) {
		clearTimeout(_debounceTimers[key]);
		_debounceTimers[key] = setTimeout(fn, delay || 300);
	}

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
			try {
				builder._sections = JSON.parse(raw) || [];
			} catch (e) {
				console.warn('RSU: Failed to parse sections JSON, starting with empty sections.', e.message);
				builder._sections = [];
			}

			// Parse generations from data attribute.
			var genAttr = builder.getAttribute('data-generations');
			try {
				builder._generations = JSON.parse(genAttr) || {};
			} catch (e) {
				console.warn('RSU: Failed to parse generations JSON.', e.message);
				builder._generations = {};
			}
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
			var sectionGenSelect = qs('.rsu-section__header > .rsu-gen-select', sEl);
			var section = {
				heading: headingInput ? headingInput.value.trim() : '',
				blocks: []
			};
			if (sectionGenSelect && sectionGenSelect.value) {
				section.generation = sectionGenSelect.value;
			}

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

		// Enable drag-and-drop for sections.
		enableDragAndDrop(list, '.rsu-section', '.rsu-section__drag', function () {
			readFromDOM(builder);
		});

		// Enable drag-and-drop for blocks within each section.
		qsa('.rsu-blocks-list', builder).forEach(function (blocksList) {
			enableDragAndDrop(blocksList, '.rsu-block', '.rsu-block__drag', function () {
				readFromDOM(builder);
			});
		});

		requestAnimationFrame(autoResize);
		setTimeout(autoResize, 500);
	}

	// ── Drag and drop ──
	var dragState = { el: null, placeholder: null };

	function enableDragAndDrop(container, itemSel, handleSel, onDrop) {
		qsa(itemSel, container).forEach(function (item) {
			var handle = qs(handleSel, item);
			if (!handle) return;

			handle.addEventListener('mousedown', function (e) {
				e.preventDefault();
				startDrag(container, item, itemSel, e.clientY, onDrop);
			});

			handle.addEventListener('touchstart', function (e) {
				var touch = e.touches[0];
				startDrag(container, item, itemSel, touch.clientY, onDrop);
			}, { passive: true });
		});
	}

	function startDrag(container, item, itemSel, startY, onDrop) {
		var items = qsa(itemSel, container);
		var index = items.indexOf(item);
		if (index === -1) return;

		// Create placeholder.
		var placeholder = document.createElement('div');
		placeholder.className = 'rsu-drag-placeholder';
		placeholder.style.height = item.offsetHeight + 'px';

		// Style the dragged item.
		var rect = item.getBoundingClientRect();
		item.classList.add('rsu-drag-active');
		item.style.top = rect.top + 'px';
		item.style.left = rect.left + 'px';
		item.style.width = rect.width + 'px';

		// Insert placeholder.
		item.parentNode.insertBefore(placeholder, item);

		dragState.el = item;
		dragState.placeholder = placeholder;

		var offsetY = startY - rect.top;
		var cachedRects = null;

		function cacheRects() {
			var siblings = qsa(itemSel + ':not(.rsu-drag-active)', container);
			cachedRects = siblings.map(function (s) {
				var r = s.getBoundingClientRect();
				return { el: s, midY: r.top + r.height / 2 };
			});
		}
		cacheRects();

		function onMove(clientY) {
			item.style.top = (clientY - offsetY) + 'px';

			// Re-cache rects after placeholder moves (layout shifts).
			cacheRects();
			for (var i = 0; i < cachedRects.length; i++) {
				if (clientY < cachedRects[i].midY) {
					container.insertBefore(placeholder, cachedRects[i].el);
					return;
				}
			}
			// Past all items — append.
			container.appendChild(placeholder);
		}

		function onMouseMove(e) { onMove(e.clientY); }
		function onTouchMove(e) { onMove(e.touches[0].clientY); }

		function finish() {
			document.removeEventListener('mousemove', onMouseMove);
			document.removeEventListener('mouseup', finish);
			document.removeEventListener('touchmove', onTouchMove);
			document.removeEventListener('touchend', finish);

			// Reset styles.
			item.classList.remove('rsu-drag-active');
			item.style.top = '';
			item.style.left = '';
			item.style.width = '';

			// Insert item where placeholder is.
			container.insertBefore(item, placeholder);
			placeholder.remove();

			dragState.el = null;
			dragState.placeholder = null;

			if (onDrop) onDrop();
		}

		document.addEventListener('mousemove', onMouseMove);
		document.addEventListener('mouseup', finish);
		document.addEventListener('touchmove', onTouchMove, { passive: true });
		document.addEventListener('touchend', finish);
	}

	// ── Build section element ──
	function buildSectionEl(builder, section, si) {
		var sectionGen = section.generation || '';
		var collapsed = section._collapsed ? ' rsu-section--collapsed' : '';
		var el = createElement(
			'<div class="rsu-section' + collapsed + '" data-index="' + si + '">' +
				'<div class="rsu-section__header">' +
					'<button type="button" class="rsu-section__toggle" title="Collapse / expand" onclick="RSUSectionBuilder.toggleSection(this)">&#9660;</button>' +
					'<span class="rsu-section__drag dashicons dashicons-move" title="Drag to reorder"></span>' +
					'<input type="text" class="rsu-section__heading" placeholder="Section heading (e.g. Cold Weather Improvements)" />' +
					genOptionsHTML(builder, sectionGen) +
					'<button type="button" class="rsu-section__dupe" title="Duplicate section" onclick="RSUSectionBuilder.dupeSection(this)"><span class="dashicons dashicons-admin-page" style="font-size:14px;width:14px;height:14px;"></span></button>' +
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
			var b = getBuilder(this);
			debounce('sync', function () { readFromDOM(b); });
		});

		// Section-level gen select change handler.
		var sectionGenSel = qs('.rsu-section__header > .rsu-gen-select', el);
		if (sectionGenSel) {
			sectionGenSel.addEventListener('change', function() {
				this.classList.toggle('rsu-gen-select--active', !!this.value);
				readFromDOM(getBuilder(this));
			});
		}

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
			var b = getBuilder(this);
			debounce('sync', function () { readFromDOM(b); });
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
			var b = getBuilder(this);
			debounce('sync', function () { readFromDOM(b); });
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
			blocks: []
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
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		rsuConfirm('Remove this section?').then(function (ok) {
			if (!ok) return;

			readFromDOM(builder);
			var sections = qsa('.rsu-sections-list .rsu-section', builder);
			var idx = sections.indexOf(sectionEl);
			builder._sections.splice(idx, 1);
			renderSections(builder);

			// Move focus to next/previous section or add button.
			var remaining = qsa('.rsu-sections-list .rsu-section', builder);
			if (remaining.length) {
				var focusIdx = Math.min(idx, remaining.length - 1);
				var heading = qs('.rsu-section__heading', remaining[focusIdx]);
				if (heading) heading.focus();
			} else {
				var addBtn = qs('.rsu-add-section', builder);
				if (addBtn) addBtn.focus();
			}
		});
	}

	function removeBlock(btn) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		var blockEl = closest(btn, '.rsu-block');
		if (!builder || !sectionEl || !blockEl) return;

		readFromDOM(builder);
		var si = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		var blocks = qsa('.rsu-blocks-list .rsu-block', sectionEl);
		var bi = blocks.indexOf(blockEl);
		builder._sections[si].blocks.splice(bi, 1);
		renderSections(builder);

		// Move focus to next/previous block or section heading.
		var sectionEls = qsa('.rsu-section', builder);
		if (sectionEls[si]) {
			var remainingBlocks = qsa('.rsu-block', sectionEls[si]);
			if (remainingBlocks.length) {
				var focusIdx = Math.min(bi, remainingBlocks.length - 1);
				var input = qs('.rsu-block__content, .rsu-bullet-row__input', remainingBlocks[focusIdx]);
				if (input) input.focus();
			} else {
				var heading = qs('.rsu-section__heading', sectionEls[si]);
				if (heading) heading.focus();
			}
		}
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
	var _copyInProgress = false;
	document.addEventListener('change', function (e) {
		if (!e.target.classList.contains('rsu-copy-from-select')) return;
		if (_copyInProgress) return;

		var sourceSlug = e.target.value;
		var targetSlug = e.target.getAttribute('data-target');
		if (!sourceSlug) return;

		_copyInProgress = true;
		var selectEl = e.target;
		rsuConfirm('Copy sections from ' + sourceSlug + '? This will overwrite the current sections.').then(function (ok) {
			if (!ok) { selectEl.value = ''; _copyInProgress = false; return; }

			var srcBuilder = qs('.rsu-section-builder[data-vehicle="' + sourceSlug + '"]');
			var tgtBuilder = qs('.rsu-section-builder[data-vehicle="' + targetSlug + '"]');
			if (!srcBuilder || !tgtBuilder) { selectEl.value = ''; _copyInProgress = false; return; }

			getBuilder(srcBuilder);
			getBuilder(tgtBuilder);
			readFromDOM(srcBuilder);

			tgtBuilder._sections = JSON.parse(JSON.stringify(srcBuilder._sections));
			renderSections(tgtBuilder);
			selectEl.value = '';
			_copyInProgress = false;
		});
	});

	// ── Toggle section collapse ──
	function toggleSection(btn) {
		var sectionEl = closest(btn, '.rsu-section');
		if (!sectionEl) return;
		sectionEl.classList.toggle('rsu-section--collapsed');
	}

	// ── Duplicate section ──
	function dupeSection(btn) {
		var builder = getBuilder(btn);
		var sectionEl = closest(btn, '.rsu-section');
		if (!builder || !sectionEl) return;

		readFromDOM(builder);
		var idx = qsa('.rsu-sections-list .rsu-section', builder).indexOf(sectionEl);
		var copy = JSON.parse(JSON.stringify(builder._sections[idx]));
		copy.heading = copy.heading ? copy.heading + ' (copy)' : '(copy)';
		builder._sections.splice(idx + 1, 0, copy);
		renderSections(builder);

		var headings = qsa('.rsu-section__heading', builder);
		if (headings[idx + 1]) headings[idx + 1].focus();
	}

	// ── Paste release notes import ──
	function showImport(btn) {
		var vehicle = btn.getAttribute('data-vehicle');
		var dialog = createElement(
			'<dialog class="rsu-import-dialog">' +
				'<div class="rsu-import-dialog__header">' +
					'<h3>Paste Release Notes</h3>' +
					'<p>Paste plain-text release notes below. Headings and bullet points will be auto-detected and converted to sections.</p>' +
				'</div>' +
				'<div class="rsu-import-dialog__body">' +
					'<textarea class="rsu-import-dialog__textarea" placeholder="Paste release notes here...\n\nCold Weather Improvements\n• Battery preconditioning now more efficient\n• Cabin heating reduced warm-up time\n\nNavigation\nUpdated route planning algorithm."></textarea>' +
				'</div>' +
				'<div class="rsu-import-dialog__actions">' +
					'<button type="button" class="rsu-import-dialog__cancel">Cancel</button>' +
					'<button type="button" class="rsu-import-dialog__submit">Import</button>' +
				'</div>' +
			'</dialog>'
		);
		document.body.appendChild(dialog);
		dialog.showModal();
		qs('.rsu-import-dialog__textarea', dialog).focus();

		qs('.rsu-import-dialog__cancel', dialog).addEventListener('click', function () {
			dialog.close(); dialog.remove();
		});
		dialog.addEventListener('cancel', function () { dialog.remove(); });

		qs('.rsu-import-dialog__submit', dialog).addEventListener('click', function () {
			var text = qs('.rsu-import-dialog__textarea', dialog).value;
			dialog.close(); dialog.remove();
			if (!text.trim()) return;

			var builder = qs('.rsu-section-builder[data-vehicle="' + vehicle + '"]');
			if (!builder) return;
			getBuilder(builder);
			readFromDOM(builder);

			var parsed = parseTextToSections(text);
			if (parsed.length) {
				builder._sections = builder._sections.concat(parsed);
				renderSections(builder);
				_dirty = true;
			}
		});
	}

	// ── Parse plain text into sections ──
	function parseTextToSections(text) {
		var lines = text.split(/\r?\n/);
		var sections = [];
		var current = null;
		var currentBlock = null;

		function flushBlock() {
			if (!currentBlock) return;
			if (currentBlock.type === 'list' && currentBlock.items.length) {
				if (current) current.blocks.push(currentBlock);
			} else if (currentBlock.type === 'paragraph' && currentBlock.content.trim()) {
				if (current) current.blocks.push(currentBlock);
			}
			currentBlock = null;
		}

		function flushSection() {
			flushBlock();
			if (current && (current.heading || current.blocks.length)) {
				sections.push(current);
			}
			current = null;
		}

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			var trimmed = line.trim();

			// Skip empty lines.
			if (!trimmed) {
				// Flush paragraph block on blank line.
				if (currentBlock && currentBlock.type === 'paragraph') {
					flushBlock();
				}
				continue;
			}

			// Detect bullet points: •, -, *, or numbered (1., 2.).
			var bulletMatch = trimmed.match(/^(?:[•●○▪▸►\-\*]|\d+[\.\)])\s*(.+)/);
			if (bulletMatch) {
				// If we're in a paragraph block, flush it first.
				if (currentBlock && currentBlock.type === 'paragraph') {
					flushBlock();
				}
				if (!current) {
					current = { heading: '', blocks: [] };
				}
				if (!currentBlock || currentBlock.type !== 'list') {
					flushBlock();
					currentBlock = { type: 'list', items: [] };
				}
				currentBlock.items.push({ text: bulletMatch[1].trim() });
				continue;
			}

			// Detect headings: short lines (< 80 chars) that are followed by bullets or blank line,
			// or lines that look like titles (no trailing punctuation except colon).
			var isHeading = false;
			if (trimmed.length < 80 && !trimmed.match(/[.!?,;]$/) && trimmed.length > 1) {
				// Check next non-empty line for bullets or if this is alone.
				var nextIdx = i + 1;
				while (nextIdx < lines.length && !lines[nextIdx].trim()) nextIdx++;
				if (nextIdx >= lines.length || lines[nextIdx].trim().match(/^(?:[•●○▪▸►\-\*]|\d+[\.\)])\s/)) {
					isHeading = true;
				}
				// If next line is also a short non-bullet line, this might be a heading for a paragraph section.
				if (!isHeading && nextIdx < lines.length && lines[nextIdx].trim().length > 0) {
					// Only treat as heading if we don't already have an active section, or line is clearly title-like.
					if (!current || !current.heading) {
						isHeading = true;
					}
				}
			}

			if (isHeading) {
				flushSection();
				current = { heading: trimmed.replace(/:$/, ''), blocks: [] };
				currentBlock = null;
				continue;
			}

			// Regular text: add to paragraph block.
			if (!current) {
				current = { heading: '', blocks: [] };
			}
			if (currentBlock && currentBlock.type === 'list') {
				flushBlock();
			}
			if (!currentBlock || currentBlock.type !== 'paragraph') {
				flushBlock();
				currentBlock = { type: 'paragraph', content: '' };
			}
			currentBlock.content += (currentBlock.content ? '\n' : '') + trimmed;
		}

		flushSection();
		return sections;
	}

	// ── Unsaved changes warning ──
	var _dirty = false;
	var _origReadFromDOM = readFromDOM;
	readFromDOM = function (builder) {
		_dirty = true;
		return _origReadFromDOM(builder);
	};

	window.addEventListener('beforeunload', function (e) {
		if (_dirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	// Clear dirty flag on form submit.
	document.addEventListener('submit', function () {
		_dirty = false;
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
		toggleSection: toggleSection,
		dupeSection: dupeSection,
		showImport: showImport,
		init: init
	};
})();
</script>
