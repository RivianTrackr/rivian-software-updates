# Changelog

All notable changes to the Rivian Software Updates plugin will be documented in this file.

## [1.5.2] - 2026-04-05

### Fixed
- Default Frontend Tab setting now works correctly. Previously, localStorage from a prior tab click always overrode the admin setting. The server-rendered default tab is now the source of truth on page load; only a URL hash can override it.

## [1.5.1] - 2026-04-05

### Fixed
- Default Platforms setting now pre-checks platforms when creating a new post (was ignored, leaving all unchecked).
- Default Frontend Tab validated against available platforms to prevent invalid slug from breaking tab selection.
- Clarified settings description: default tab applies to first-time visitors; returning visitors see their last-selected tab.

## [1.5.0] - 2026-04-05

### Changed
- Frontend release notes are now full-width, filling the parent container instead of capped at 800px.
- Three responsive breakpoints: desktop (32px padding), tablet ≤768px (24px), mobile ≤480px (16px).
- Larger body text (16px → 15px on mobile) with increased line-height (1.75) for readability.
- Tabs scroll horizontally on small screens instead of wrapping/breaking.
- Date badges stack vertically on mobile.
- Blockquotes/notes styled with accent left border and tinted background.
- Reduced border-radius on mobile (12px → 8px) for tighter fit.
- Links use `word-break: break-word` to prevent overflow on narrow screens.
- Added `box-sizing: border-box` to all RSU elements for consistent sizing.

## [1.4.1] - 2026-04-05

### Fixed
- Inlined all admin CSS directly in the meta box template to fix styles not loading in the Block Editor.
- Added `!important` overrides on key properties to prevent WordPress admin CSS from stripping card borders, input styling, and button appearance.
- External CSS file retained as fallback but no longer the primary source.

## [1.4.0] - 2026-04-05

### Changed
- Redesigned section builder UI with modern card-based layout, rounded corners, and subtle shadows.
- Tab bar uses clean underline indicator instead of faux-tab borders.
- Color-coded block types: blue left border for Bullet List, amber for Note.
- Improved focus states with soft blue glow on inputs and tinted textarea backgrounds.
- Enhanced empty state with icon and two-line helper text.
- Refined add-block buttons with lighter default style and blue hover highlights.
- Better spacing and typography throughout the editor.

### Fixed
- Rewrote section builder as inline vanilla JS to fix buttons not working in the Block Editor.
- Removed dependency on external JS file, jQuery, and jQuery UI Sortable.
- All button actions use direct `onclick` handlers via `RSUSectionBuilder` global API.

## [1.3.1] - 2026-04-05

### Fixed
- Rewrote section builder as inline vanilla JS to fix buttons not working in the Block Editor.
- Removed dependency on external JS file, jQuery, and jQuery UI Sortable.
- All button actions use direct `onclick` handlers via `RSUSectionBuilder` global API.
- Script executes immediately when the meta box renders, with retry fallback for delayed rendering.

## [1.3.0] - 2026-04-05

### Added
- Platform manager on the settings page to add, edit, and remove vehicle platforms.
- Each platform has configurable label, description, and sort order.
- Platform slugs are locked after creation to prevent breaking existing post data.
- New platforms auto-generate `_rsu_content_{slug}` meta keys.

### Changed
- Platforms are now stored in the database (`rsu_platforms` option) instead of hardcoded in PHP.
- Built-in Gen 1, Gen 2, and R2 platforms are used as fallback if no custom platforms are saved.
- Default Platforms and Default Frontend Tab settings now validate against dynamic platform list.

## [1.2.1] - 2026-04-05

### Fixed
- Section builder fully compatible with the Block Editor (Gutenberg) by delegating all event handlers from `document` instead of a wrapper element that may not exist during async meta box rendering.
- Initialization retries up to 10 times for delayed meta box rendering in the Block Editor.
- Button clicks use `stopPropagation()` to prevent Block Editor event interference.

## [1.2.0] - 2026-04-05

### Added
- Settings page under Settings > RSU Settings with the following options:
  - **Default Platforms**: Pre-selected platforms when creating new update posts.
  - **Default Frontend Tab**: Which platform tab is shown first to visitors.
  - **Section Heading Level**: Choose H2, H3, or H4 for rendered section headings.
  - **Note Block Label**: Customize the label on note/blockquote blocks (e.g. "NOTE", "TIP").
  - **Accent Color**: Color picker for the frontend accent color (tabs, links, bullet markers).
  - **Schema Markup**: Toggle JSON-LD structured data output on/off.
  - **Organization Name**: Configurable author/publisher name in schema markup.
  - **Updates Archive Slug**: Configurable breadcrumb URL for the archive page.

### Changed
- Schema markup now reads organization name and archive slug from settings.
- Default platform tab on frontend now reads from settings instead of hardcoded value.
- Section HTML renderer uses configurable heading level and note label.
- Frontend accent color can be overridden via settings (injected as CSS custom property).

## [1.1.1] - 2026-04-05

### Fixed
- Add Section button not responding to clicks due to event handlers being registered after the initialization loop.
- Added guard and try/catch around jQuery UI Sortable initialization to prevent failures on hidden panels from breaking the UI.
- Changed sortable `connectWith` from jQuery object to CSS selector string for proper jQuery UI compatibility.
- Added defensive fallback in Add Section handler to bootstrap builder data if initialization was skipped.

## [1.1.0] - 2026-04-05

### Added
- Structured section builder replacing the TinyMCE editor for release notes content.
- Three purpose-built block types: Paragraph, Bullet List, and Note/Blockquote.
- Drag-and-drop reordering for sections and blocks via jQuery UI Sortable.
- HTML-to-sections parser for converting existing HTML content into structured format.
- Editor fallback: auto-parses legacy HTML when no sections JSON exists for a post.
- Backfill Sections button on the migration page for one-click bulk conversion.
- Migration tool now generates sections JSON alongside HTML during migration.

### Changed
- Replaced `wp_editor()` (TinyMCE) with custom section builder UI in the Release Notes meta box.
- Admin JS dependency updated to include `jquery-ui-sortable`.

### Removed
- TinyMCE editor dependency for release notes content editing.

## [1.0.0] - 2026-04-04

### Added
- Initial release.
- Custom post meta fields for structured release notes.
- Platform support for Gen 1 R1, Gen 2 R1, and R2.
- Tabbed frontend display with platform switching.
- SEO schema markup for software update posts.
- Migration tool for converting Essential Blocks toggle content.
- Copy-between-platforms functionality in the admin editor.
