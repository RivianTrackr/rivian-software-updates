# Changelog

All notable changes to the Rivian Software Updates plugin will be documented in this file.

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
