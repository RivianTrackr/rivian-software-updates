# Changelog

All notable changes to the Rivian Software Updates plugin will be documented in this file.

## [2.13.3] - 2026-04-09

### Fixed
- History table no longer overflows its container on narrow desktop/tablet viewports. The table now switches to the stacked card layout at 600px (up from 480px) so it reflows before columns get cramped.

## [2.13.2] - 2026-04-09

### Changed
- Widget date text increased to 16px.
- Added mobile responsive styles for the widget (reduced padding, icon size, and font sizes on small screens).

## [2.13.1] - 2026-04-09

### Changed
- Widget title font updated to 20px / weight 800, meta text to bold, cloud icon to 32px.
- Widget card styles matched to existing widget specs (border-radius 20px, padding 24px, min-height 100px, 0.3s transition).

## [2.13.0] - 2026-04-09

### Added
- **Latest Software Update widget**: New WordPress sidebar widget (`RSU_Widget`) that automatically displays the most recent OTA version with First Noticed and Public Release dates. Links to the update post. Blue card background turns white on hover with black text/icon throughout.
- Widget output is cached via a transient and automatically invalidated when any post is saved, so it always reflects the latest OTA.

## [2.12.0] - 2026-04-09

### Added
- **Year-grouped accordion** on `[rsu_history]` shortcode: Updates are now grouped by public release date year and displayed in collapsible `<details>`/`<summary>` sections. The latest year is expanded by default; older years are collapsed.
- Year header shows year label, update count badge, and animated chevron indicator.

### Fixed
- Mobile shortcode history table now shows "First Noticed" and "Public Release" labels on date cells. Previously, the stacked card layout hid the `<thead>` with no replacement labels, making it impossible to distinguish between the two dates.

## [2.11.0] - 2026-04-09

### Added
- **WordPress Block Editor migration**: New migration path for posts that use standard Gutenberg blocks (headings, paragraphs, lists) without Essential Blocks toggle wrappers. Parses `post_content` directly and saves sections for all default vehicles.
- `RSU_Migrate::migrate_block_post()`, `get_block_migratable_posts()`, and `migrate_all_blocks()` methods.
- Migration page now has a dedicated "WordPress Block Editor Posts" section with preview, migrate, and force re-migrate controls.

### Changed
- HTML parser (`parse_html_to_sections`) now skips `<nav>`, `<figure>`, `<style>`, `<script>`, `<iframe>`, and `<form>` elements. This filters out Stackable table-of-contents blocks, video/image embeds, and other non-content elements during migration.

## [2.10.3] - 2026-04-09

### Fixed
- iOS-style toggle knob sized to 28px diameter with 2px inset per design language spec (was 24px / 4px).
- Toggle knob shadow opacity corrected to 0.15 per spec (was 0.2).
- Monospace font stack updated to match design language (`'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono'`).
- Replaced hardcoded color values with CSS custom properties in admin confirm dialog and inline meta-box styles.
- Added missing `--rsu-note-bg`, `--rsu-note-bg-focus`, and `--rsu-bg-hover` tokens to inline Block Editor CSS.

### Added
- `prefers-reduced-motion: reduce` media query in admin and settings stylesheets (frontend already had it).

## [2.10.0] - 2026-04-08

### Added
- **`[rsu_history]` shortcode**: Displays a styled table of all software update posts with OTA Version (linked to post), First Noticed date, Public Release date, and Available For vehicle badges. Supports `limit` and `order` attributes.
- New `RSU_Shortcode` class registered on `plugins_loaded`.
- Dark-themed table styles matching the existing frontend design system, with hover rows, accent-colored version links, and pill-style vehicle badges.
- Responsive card layout on small screens (< 480px): table collapses into stacked cards with hidden headers.
- Print-ready light theme override for the history table.

## [2.9.0] - 2026-04-08

### Added
- **Undo stack**: Destructive actions (delete section/block, duplicate, copy-from, paste import) now push state to an undo stack (max 20 entries). Undo with Ctrl/Cmd+Z when focused inside the section builder.
- **Keyboard shortcuts**: Ctrl/Cmd+Shift+S adds a new section. Enter in an empty section heading auto-creates the first paragraph block.
- **Toast notifications**: Paste import now shows "Imported X sections" confirmation. Copy-from shows "Sections copied". Undo shows "Undone". Failed paste shows "No sections detected".
- **Mobile breakpoint** (< 480px): Date badges stack vertically, tab and content padding tightened, typography scaled down for small phones.
- **Print styles**: Dark theme swaps to light colors for printing. Tabs hidden, all panels shown, generation pills and date badges render cleanly on paper.
- **Multi-vehicle schema**: AIOSEO `about` field now outputs an array of `SoftwareApplication` objects (one per active vehicle) instead of a single generic entry. Standalone schema output matches.

### Changed
- Migrated all inline `onclick` attributes to `data-action` event delegation. Single delegated listener handles all section builder interactions.
- `will-change: opacity, transform` added to panel enter animation for smoother GPU compositing.
- Copy-from `_copyInProgress` flag now auto-resets after 10 seconds to prevent permanent UI lockout.
- Copy-from and paste import now push undo state before modifying sections.

### Fixed
- `get_all_generation_slugs()` now uses static cache to avoid rebuilding the array on every call.
- `parse_html_to_sections()` now runs `wp_kses_post()` on input HTML before DOM parsing for XSS safety.
- Frontend tab switch preserves scroll position to prevent page jump when panel heights differ.

## [2.8.0] - 2026-04-08

### Added
- **Paste Release Notes**: New "Paste Release Notes" button in the editor toolbar opens a dialog where you can paste plain-text release notes. Headings and bullet points are auto-detected and converted into structured sections.
- **Section collapse/expand**: Each section now has a toggle arrow to collapse or expand its content, making long posts easier to navigate.
- **Duplicate section**: New copy button on each section header creates a deep copy of the section (heading, blocks, and generation tags) inserted directly below.
- **Unsaved changes warning**: Browser now warns before navigating away when you have unsaved edits in the section builder. The warning clears on form submit.
- **Drag-to-reorder vehicles**: Settings page now uses drag handles to reorder vehicles instead of manual sort number inputs. Sort values are auto-calculated from position.

### Fixed
- **Date picker UX**: Replaced janky `onfocus` type-switching hack with native `type="date"` inputs. Added `max` date constraint on "First Noticed" field to prevent future dates. Removed unnecessary inline JS for date type initialization.

## [2.7.0] - 2026-04-08

### Performance
- Replaced triple staggered `setTimeout` textarea auto-resize with `requestAnimationFrame` + single delayed pass.
- Cached `getBoundingClientRect()` calls during drag-and-drop instead of recalculating every mousemove.
- Debounced `readFromDOM()` on textarea input (300ms) to reduce JSON serialization during typing.
- Added `contain: layout style` to section cards to isolate reflow scope.

### Fixed
- BreadcrumbList schema now includes the required `item` URL on the last breadcrumb entry.
- `softwareVersion` in schema uses explicit `! empty()` guard to prevent empty values.
- `json_decode()` in schema section extraction now checks `json_last_error()` before using parsed data.
- Focus now moves to next/previous sibling after deleting a section or block instead of jumping randomly.
- Copy-from dropdown guarded against double-click race condition with processing flag.
- JSON parse failures in section builder now log a `console.warn` instead of failing silently.

### Changed
- Replaced all `confirm()` dialogs with styled `<dialog>` modals matching the RivianTrackr design system.
- Replaced hardcoded inline drag-and-drop styles with `.rsu-drag-placeholder` and `.rsu-drag-active` CSS classes.
- Converted LTR-only CSS properties (`border-left`, `padding-left`, `margin-left`, `left`) to logical equivalents (`border-inline-start`, `padding-inline-start`, `margin-inline-start`, `inset-inline-start`) for RTL support.
- Migration page buttons now show a disabled/loading state during form submission.
- esbuild now generates linked source maps for minified JS files.

### Accessibility
- Vehicle checkbox group now has `role="group"` and `aria-label` for screen readers.
- Added tablet breakpoint (1024px) for admin section builder with flex-wrap on headers and bullet rows.
- Tightened mobile (782px) styles with reduced blocks-list padding.

## [2.6.1] - 2026-04-08

### Fixed
- Removed bottom margin bleed on last element inside frontend panel content sections.
- Replaced hardcoded note block colors (`#fef9ee`, `#fdf0d5`) and border color (`#f0f0f0`) with CSS custom properties in admin and settings stylesheets.
- Standardized admin meta box transition duration from 0.15s to 0.2s to match frontend and settings.

### Added
- Subtle hover states on frontend generation pills (background and border transition).
- Subtle hover states on frontend date badges ("First Noticed" and "Public Release").

## [2.6.0] - 2026-04-08

### Fixed
- **localStorage tab preference never read back**: Saved preferred tab was written to localStorage but never restored on page load — only the URL hash was checked. Now falls back to stored preference when no hash is present.
- **Schema `extract_sections` ignored configurable heading level**: Heading extraction regex only matched `h2`/`h3`, missing `h4` when configured. Now reads structured sections JSON directly (with HTML regex fallback for legacy posts matching all heading levels).
- **Frontend CSS loaded in footer causing FOUC**: Stylesheet was enqueued in `wp_footer`, causing a flash of unstyled content. CSS now loads in `<head>` via `wp_enqueue_scripts`; JS remains in footer.

### Changed
- **`RSU_Platforms::get_all()` now uses a static cache**: Eliminates 5+ redundant `get_option()` calls and sort operations per page load.
- Vehicle tabs only render when 2+ vehicles are active. Single-vehicle posts display a full-radius panel without an unnecessary tab button.
- Empty tab panels now show a "No release notes available" message instead of a blank panel.
- `uninstall.php` uses a single batch `DELETE ... WHERE meta_key IN (...)` query instead of looping individual deletes.
- `aioseo_clean_content` filter exits earlier when post ID is missing.
- `package.json` version synced with plugin version (was 1.5.2, now 2.6.0).

### Added
- `Enter` and `Space` key support for tab activation (WAI-ARIA tab pattern compliance).
- `getPreferred()` helper restored for reading stored tab preference from localStorage.
- Sanitized URL hash values — only alphanumeric, hyphens, and underscores are accepted in the platform selector to prevent malformed selectors.

## [2.3.9] - 2026-04-07

### Changed
- Vehicle toggle tab bar now displays even when only one vehicle is active, so users always see which model they are viewing.
- Removed all responsive media query overrides (padding, margins, font sizes, widths) from plugin CSS — layout responsiveness is now fully inherited from the WordPress theme (Blocksy).
- Removed `width: 100%` from `.rsu-update` wrapper to let the theme control container sizing.
- Set proper ARIA `tabindex` attributes on single-vehicle tab for accessibility.

### Removed
- Dead "single platform (no tabs)" CSS rule (`.rsu-update:not(:has(.rsu-tabs))`).
- Unused `getPreferred()` function from frontend JS (localStorage read was no longer called).

## [2.2.0] - 2026-04-07

### Added
- New card-based settings page matching the RivianTrackr admin design system (Apple/iOS-inspired).
- iOS-style toggle switch for Schema Markup setting.
- Dedicated `rsu-settings.css` stylesheet with full RTG design tokens.
- Sticky save bar at the bottom of the settings page.
- Custom-styled inputs, selects, buttons (primary/secondary/danger), and checkbox groups.
- Settings CSS added to esbuild pipeline for minification.

### Changed
- Settings page now renders custom HTML instead of default WordPress Settings API tables.
- Settings page is full-width instead of capped at 900px.
- RSU_Settings class slimmed down — removed all field/section renderer methods; view handles rendering directly.
- Bullet row generation selectors resized to match block-level selectors (11px font, consistent padding).

### Removed
- WordPress Settings API `add_settings_section` and `add_settings_field` calls (form still uses `register_setting` for sanitization).

## [2.1.0] - 2026-04-07

### Changed
- Admin panel restyled to match the RivianTrackr design system (shared with Tire Guide and AI Search Summary plugins).
- Action blue updated from WordPress blue (`#2271b1`) to RTG action blue (`#0071e3`).
- Borders, backgrounds, and text colors aligned to RTG light theme tokens (`#d2d2d7`, `#f5f5f7`, `#1d1d1f`, `#6e6e73`, `#86868b`).
- Error/delete states use RTG error red (`#ff3b30` / `#ffe5e5`).
- Note block accent updated to brand gold (`#fba919`).
- Card border radius increased to 12px, input/block radius to 8px per RTG scale.
- Shadows use RTG subtle/hover levels for more visible card depth.
- All admin colors defined as CSS custom properties on `.rsu-admin-wrap` for easy theming.
- Frontend now renders release notes from sections JSON at display time — settings like heading level and note label apply immediately to all posts without re-saving.

### Fixed
- Changing Section Heading Level or Note Block Label in settings now updates all existing software update posts immediately.

## [2.0.1] - 2026-04-07

### Fixed
- Generation pills now consistently appear after content (right-aligned) across all element types — headings, paragraphs, list items, and notes.
- Generation selector dropdowns in the admin editor restyled to match the plugin's design language — consistent font size, background, border radius, and custom chevron arrow.

## [2.0.0] - 2026-04-07

### Added
- **Vehicle + Generation model**: Top-level tabs are now vehicle models (R1, R2) instead of flat platforms (Gen 1 R1, Gen 2 R1, R2).
- **Generation pills**: Individual blocks, list items, and section headings can be tagged with a generation (e.g. "Gen 1 Only", "Gen 2 Only") shown as inline pill badges.
- Generation selector dropdowns in the section builder for blocks, bullet items, and section headings.
- Vehicles have nested generations in the settings manager — each vehicle can have multiple generations configured independently.
- `RSU_Platforms::get_generations()` and `get_all_generation_slugs()` helper methods.
- Backward compatibility: reads old `_rsu_platforms` meta key when `_rsu_vehicles` is not set.

### Changed
- Platform registry restructured from flat list to vehicle → generation hierarchy.
- Settings page "Platforms" section renamed to "Vehicles" with nested generation management UI.
- `default_platforms` setting renamed to `default_vehicles`; `default_tab` now defaults to `r1`.
- Section JSON format updated: blocks and list items support optional `generation` field; list items changed from plain strings to `{text, generation}` objects.
- Schema description now includes generation info (e.g. "R1 (Gen 1, Gen 2) and R2 (Gen 1)").
- Frontend `aria-label` changed from "Vehicle platform" to "Vehicle model".
- Plugin version bumped to 2.0.0.

## [1.6.0] - 2026-04-05

### Added
- Bullet list blocks now use individual input rows instead of a single textarea, each with an auto-expanding text area and a remove button on hover.
- "+ Add bullet" button at the bottom of each bullet list block for easily adding new items.

### Changed
- All textareas (paragraph, note, bullet items) auto-expand to fit content as you type — no more fixed-height boxes that cut off text or require scrolling.
- Textareas start at one row and grow dynamically; auto-resize also triggers on initial load and tab switches.
- Removed the "This is a Software Update post" toggle checkbox — the release notes editor now shows immediately since all posts are software updates.

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
