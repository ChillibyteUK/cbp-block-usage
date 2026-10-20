# CBP Block Usage

WordPress plugin that adds a `[block_usage_table]` shortcode for QA/debugging — lists every theme block and where it's used, so you can tell at a glance whether a block is safe to remove.

Works with both block styles:

- **JSX blocks** — `blocks/{slug}/block.json` (name read from the `name` field in `block.json`)
- **ACF blocks** — `blocks/{slug}.php` (registered as `acf/{slug}`)

Both the active (child) theme and, if different, the parent theme are scanned.

## Usage

Add the shortcode to any page:

```
[block_usage_table]
```

This renders a table with, per block:

- Block name and type (`ACF` or `JSX`)
- Count of published **pages**, **posts**, and **CPTs** using it
- Up to 2 example URLs
- A full "Used In" list linking to the editor for each page/post/CPT

A **Download CSV** link sits above the table and exports one row per block with the same counts and example URLs.

## How it works

- Blocks are discovered by scanning the theme's `/blocks/` directory.
- Usage is detected by scanning published post content for `<!-- wp:{namespace}/{slug}` comment delimiters and matching against the discovered block names.
- Post types scanned are all public post types (excluding attachments) — pages and posts are counted individually, everything else is grouped as CPTs.
- The usage map is cached in a transient for 1 hour and cleared automatically on `save_post`, `deleted_post`, and `switch_theme`.

## CSV columns

`Block Name, Type, Pages, Posts, CPTs, Total, Example URL 1, Example URL 2`

## Install

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate `CBP Block Usage`.
3. Add `[block_usage_table]` to a QA/staging page.

## Notes

- This is a debugging/QA tool — no capability check is applied to the shortcode itself (matching the original per-theme `block-usage.php` utilities it replaces), but the CSV export is nonce-protected. Remove the page or restrict it before launch if it shouldn't be publicly visible.
- If a theme uses a different directory than `/blocks/`, or a non-standard ACF block naming convention, discovery will need a small tweak in `discover_blocks()`.
