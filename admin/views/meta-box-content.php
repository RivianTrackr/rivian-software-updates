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
/* Tokens are declared on the dialogs too — they're appended to document.body,
   outside .rsu-admin-wrap, so they don't inherit from it. */
.rsu-admin-wrap, .rsu-confirm-dialog, .rsu-import-dialog { --rsu-action: #0071e3; --rsu-action-hover: #0077ed; --rsu-text: #1d1d1f; --rsu-text-secondary: #6e6e73; --rsu-text-muted: #86868b; --rsu-accent: #fba919; --rsu-error: #ff3b30; --rsu-error-light: #ffe5e5; --rsu-border: #d2d2d7; --rsu-border-light: #e8e8ed; --rsu-bg-light: #f5f5f7; --rsu-bg-hover: #e8e8ed; --rsu-bg-info: #dbeafe; --rsu-white: #ffffff; --rsu-placeholder: #86868b; --rsu-note-bg: #fef9ee; --rsu-note-bg-focus: #fdf0d5; }
.rsu-admin-wrap { margin: -6px -12px -12px; padding: 0; }

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
.rsu-block[data-type="note"] .rsu-block__content { background: var(--rsu-note-bg); }
.rsu-block[data-type="note"] .rsu-block__content:focus { background: var(--rsu-note-bg-focus); }

.rsu-bullet-list { padding: 8px 12px 4px; }
.rsu-bullet-row { display: flex; align-items: flex-start; gap: 0; margin-bottom: 6px; background: var(--rsu-white); border: 1px solid var(--rsu-border-light); border-radius: 8px; padding: 0; overflow: hidden; }
.rsu-bullet-row:focus-within { border-color: var(--rsu-action); }
.rsu-bullet-row--indent { margin-inline-start: 28px; }
.rsu-bullet-row__indent { flex-shrink: 0; background: var(--rsu-bg-light); border: none; border-right: 1px solid var(--rsu-border-light); color: var(--rsu-text-muted); cursor: pointer; padding: 0 6px; align-self: stretch; display: flex; align-items: center; opacity: 0.55; transition: opacity 0.15s, color 0.15s, background 0.15s; }
.rsu-bullet-row:hover .rsu-bullet-row__indent,
.rsu-bullet-row:focus-within .rsu-bullet-row__indent { opacity: 1; }
.rsu-bullet-row__indent:hover { color: var(--rsu-action); background: var(--rsu-bg-info); }
.rsu-bullet-row__indent .dashicons { font-size: 14px; width: 14px; height: 14px; }
.rsu-bullet-row--indent .rsu-bullet-row__indent { color: var(--rsu-action); opacity: 1; }
.rsu-bullet-row__marker { flex-shrink: 0; width: 32px; display: flex; align-items: center; justify-content: center; color: var(--rsu-action); font-size: 18px; line-height: 1; padding-top: 8px; user-select: none; background: var(--rsu-bg-light); align-self: stretch; border-right: 1px solid var(--rsu-border-light); }
.rsu-bullet-row__input { flex: 1; border: none !important; background: var(--rsu-white); font-size: 13px; line-height: 1.65; font-family: inherit; color: var(--rsu-text); padding: 8px 10px; resize: none; overflow: hidden; overflow-wrap: break-word; word-break: break-word; min-height: 36px; height: auto; box-sizing: border-box; box-shadow: none !important; outline: none !important; field-sizing: content; }
.rsu-bullet-row__input:focus { background: var(--rsu-bg-light); }
.rsu-bullet-row__input::placeholder { color: var(--rsu-placeholder); }
.rsu-bullet-row__remove { flex-shrink: 0; background: none; border: none; border-left: 1px solid var(--rsu-border-light); font-size: 15px; line-height: 1; color: var(--rsu-text-muted); cursor: pointer; padding: 0 8px; align-self: stretch; display: flex; align-items: center; visibility: hidden; }
.rsu-bullet-row:hover .rsu-bullet-row__remove { visibility: visible; }
.rsu-bullet-row__remove:hover { color: var(--rsu-error); background: var(--rsu-error-light); }
.rsu-bullet-add { display: flex; align-items: center; gap: 4px; width: 100%; padding: 7px 12px; margin: 0; background: var(--rsu-bg-light); border: none; border-top: 1px solid var(--rsu-border-light); color: var(--rsu-text-muted); font-size: 11px; font-weight: 600; cursor: pointer; text-align: left; }
.rsu-bullet-add:hover { background: var(--rsu-bg-info); color: var(--rsu-action); }

/* Note inner blocks (paragraphs / bullet lists nested inside a Note) */
.rsu-note-blocks { padding: 8px 12px 4px; display: flex; flex-direction: column; gap: 8px; }
.rsu-note-blocks .rsu-block { border: 1px solid var(--rsu-border-light); border-left-width: 1px; background: var(--rsu-white); margin-bottom: 0; }
.rsu-note-blocks .rsu-block[data-type="list"] { border-left: 3px solid var(--rsu-accent); }
.rsu-note-blocks .rsu-block__header { padding: 4px 8px; }
.rsu-note-blocks .rsu-block__label { color: var(--rsu-accent); font-size: 9px; }
.rsu-note-add { display: flex; gap: 8px; padding: 8px 12px 12px; border-top: 1px solid var(--rsu-border-light); }
.rsu-note-add .button { font-size: 11px !important; font-weight: 600; color: var(--rsu-text-secondary) !important; padding: 4px 12px !important; border: 1px solid var(--rsu-border) !important; border-radius: 6px !important; background: var(--rsu-white) !important; cursor: pointer; box-shadow: none !important; line-height: 1.5; }
.rsu-note-add .button:hover { color: var(--rsu-accent) !important; border-color: var(--rsu-accent) !important; background: var(--rsu-note-bg-focus) !important; }
.rsu-block[data-type="note"] > .rsu-note-blocks { background: var(--rsu-note-bg); }
.rsu-block[data-type="note"] > .rsu-note-add { background: var(--rsu-note-bg); }

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
.rsu-confirm-dialog__cancel:hover { background: var(--rsu-bg-hover) !important; }
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
.rsu-import-dialog { border: none; border-radius: 12px; padding: 0; box-shadow: 0 8px 32px rgba(0,0,0,0.2); max-width: 640px; width: calc(100% - 32px); font-family: inherit; }
.rsu-import-dialog::backdrop { background: rgba(0,0,0,0.4); }
.rsu-import-dialog__header { padding: 20px 24px 0; }
.rsu-import-dialog__header h3 { font-size: 16px; font-weight: 600; color: var(--rsu-text); margin: 0 0 4px; }
.rsu-import-dialog__header p { font-size: 12px; color: var(--rsu-text-muted); margin: 0; }
.rsu-import-dialog__body { padding: 12px 24px; }
.rsu-import-dialog__source { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.rsu-import-dialog__pdf-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; font-size: 12px; font-weight: 600; border: 1px solid var(--rsu-border); border-radius: 8px; background: var(--rsu-white); color: var(--rsu-text-secondary); cursor: pointer; transition: all 0.15s ease; }
.rsu-import-dialog__pdf-btn:hover { border-color: var(--rsu-action); color: var(--rsu-action); background: var(--rsu-bg-info); }
.rsu-import-dialog__pdf-btn:disabled { opacity: 0.5; cursor: wait; }
.rsu-import-dialog__pdf-status { font-size: 12px; color: var(--rsu-text-muted); }
.rsu-import-dialog__pdf-status--error { color: var(--rsu-error); }
.rsu-import-dialog__textarea { width: 100%; min-height: 160px; border: 1px solid var(--rsu-border); border-radius: 8px; padding: 12px; font-size: 13px; line-height: 1.6; font-family: inherit; color: var(--rsu-text); resize: vertical; box-sizing: border-box; }
.rsu-import-dialog__textarea:focus { border-color: var(--rsu-action); box-shadow: 0 0 0 3px rgba(0,113,227,0.1); outline: none; }
.rsu-import-dialog__textarea::placeholder { color: var(--rsu-placeholder); }
.rsu-import-dialog__preview-label { margin: 12px 0 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--rsu-text-secondary); }
.rsu-import-dialog__preview { border: 1px solid var(--rsu-border); border-radius: 8px; background: var(--rsu-bg-light); max-height: 200px; overflow-y: auto; padding: 10px 14px; }
.rsu-import-preview__section { margin-bottom: 10px; }
.rsu-import-preview__section:last-child { margin-bottom: 0; }
.rsu-import-preview__heading { font-size: 12px; font-weight: 700; color: var(--rsu-text); margin: 0 0 4px; }
.rsu-import-preview__heading--empty { color: var(--rsu-text-muted); font-weight: 500; font-style: italic; }
.rsu-import-preview__list { margin: 0 0 6px 18px; list-style: disc; }
.rsu-import-preview__list li { font-size: 12px; color: var(--rsu-text-secondary); margin: 2px 0; }
.rsu-import-preview__list li.rsu-import-preview__sub { list-style: circle; margin-inline-start: 18px; }
.rsu-import-preview__para { font-size: 12px; color: var(--rsu-text-secondary); margin: 0 0 6px; }
.rsu-import-preview__note { border-left: 3px solid var(--rsu-accent); background: var(--rsu-note-bg); border-radius: 0 6px 6px 0; padding: 6px 10px; margin: 0 0 6px; }
.rsu-import-preview__note-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--rsu-accent); margin-bottom: 2px; }
.rsu-import-preview__empty { font-size: 12px; color: var(--rsu-text-muted); font-style: italic; margin: 0; }
.rsu-import-dialog__actions { display: flex; justify-content: flex-end; gap: 8px; padding: 8px 24px 20px; }
.rsu-import-dialog__cancel { padding: 8px 16px; font-size: 13px; font-weight: 500; border: 1px solid var(--rsu-border); border-radius: 8px; background: var(--rsu-bg-light); color: var(--rsu-text); cursor: pointer; }
.rsu-import-dialog__cancel:hover { background: var(--rsu-bg-hover); }
.rsu-import-dialog__submit { padding: 8px 16px; font-size: 13px; font-weight: 600; border: none; border-radius: 8px; background: var(--rsu-action); color: #fff; cursor: pointer; }
.rsu-import-dialog__submit:hover { background: var(--rsu-action-hover); }
.rsu-import-dialog__submit:disabled { opacity: 0.5; cursor: not-allowed; }

/* Toast notification */
.rsu-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); padding: 10px 20px; background: #1d1d1f; color: #fff; font-size: 13px; font-weight: 500; border-radius: 8px; z-index: 100000; opacity: 0; transition: opacity 0.3s ease; pointer-events: none; }
.rsu-toast--visible { opacity: 1; }

/* Undo bar */
.rsu-undo-bar { display: flex; align-items: center; gap: 8px; padding: 6px 12px; margin-bottom: 12px; background: var(--rsu-bg-info); border: 1px solid rgba(0,113,227,0.2); border-radius: 8px; font-size: 12px; color: var(--rsu-text-secondary); }
.rsu-undo-bar button { background: none; border: none; color: var(--rsu-action); font-size: 12px; font-weight: 600; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.rsu-undo-bar button:hover { background: rgba(0,113,227,0.1); }

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

