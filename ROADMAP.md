# Roadmap

Improvements to how the plugin presents an update to readers. These came
out of a front-end review alongside the fixes shipped in 2.33.0 and go
beyond fixing a problem: each one adds something the site does not do
today. Nothing here is scheduled; the order is a suggestion.

## Reader-facing

### Show the sighting-to-release gap
The plugin already tracks First Noticed and Public Release. One line on
the update page such as "Rolled out publicly 9 days after first sighting"
turns two dates into a story, and the timeline could carry the same number
as a column so slow and fast rollouts stand out at a glance.

### Relative time on the widget
The Latest Software Updates card sits on every page. "Public Release ·
6 days ago" reads faster than a full date in a narrow sidebar, and it
signals freshness without the reader doing date math. Keep the absolute
date in a `title` or as a second line.

### Order the widget by the reader's vehicle
The stored `rsu_preferences.vehicle` could put the reader's vehicle first
in the widget and, when the vehicle is on a different version than the
others, visually lead with it. The widget is cached HTML, so this is a
small client-side reorder rather than a server change.

### Generation-aware widget and timeline
With the generation preference now stored per vehicle, the widget's build
line and the timeline's build chips could highlight the build for the
reader's own generation and dim the rest.

### Search and version jump on the timeline
A single text field above the year list that filters rows by version
string ("2026.2" narrows to every 2026.2x release) would help once the
table runs past a few dozen rows. Client-side only, same pattern as the
vehicle filter.

### Sticky-offset setting
`--rsu-sticky-offset` is exposed as a CSS custom property for themes with
a fixed header. A numeric field in RSU Settings that writes the same
variable inline would let a site owner set it without touching theme CSS.

### Collapse long sections on phones
On small screens a 20-section set of notes is a long scroll even with the
jump list. Rendering each `.rsu-section` as a `<details>` that starts open
on desktop and closed on phones (with "Expand all") would keep the jump
list useful and the page short.

### Light theme
Every front-end surface is hard-coded to the dark "ink & brass" palette,
which matches riviantrackr.com. A `prefers-color-scheme: light` block or a
`data-rsu-theme="light"` opt-in on the tokens block would let the same
components sit on a light site without a rewrite.

## Authoring

### Decide what the post body is for
2.33.0 renders the post body as an intro above the tabs. If that is the
intended use, the editor could carry a placeholder saying so; if the body
should stay empty, `remove_post_type_support('post', 'editor')` scoped to
update posts would stop authors writing text that nobody sees. Either way
the choice should be explicit rather than accidental.

### Inline formatting in release notes
Section text is stored as plain text (`sanitize_text_field`), so bold
feature names and links inside a bullet are impossible. Rivian's own notes
bold the feature name at the start of each item. Allowing a small
whitelist (`strong`, `em`, `a`, `code`) through `wp_kses` in the section
builder, with matching support in the PDF importer, would make the
rendered notes closer to the source document.

### Per-generation "what changed" on hotfixes
A hotfix already links to its base release and lists its builds. Showing
the base release's notes collapsed beneath the hotfix notes (or a "Changes
since 2026.30" toggle) would save the round trip for readers who arrive
at the hotfix first.

## Structured data

### Per-section `hasPart` nodes
The schema output lists section headings as `articleSection`. Emitting
each section as a `WebPageElement` / `hasPart` with its anchor URL would
let search engines deep-link to a section, now that headings have ids.

### `softwareVersion` on the timeline page
The `[rsu_history]` page has no structured data. An `ItemList` of the
visible releases with their `SoftwareApplication` builds would make the
timeline itself eligible for rich results.
