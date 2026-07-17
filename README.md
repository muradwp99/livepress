# LivePress

**Realtime visual editing for headless WordPress.** Type in wp-admin, watch
your real rendered frontend change **before saving** — the editing experience
Sanity and Storyblok sell, built on WordPress.

One "Site Pages" list. Every page opens a fullscreen editor: schema-driven
fields on the left, a live iframe of your actual site on the right. Every
keystroke streams into the preview over `postMessage` and renders in
milliseconds. Drag to reorder sections and repeater rows, pick images from
the Media Library, preview at desktop/laptop/tablet/mobile widths, click any
section in the preview to jump to its fields. Save persists over REST.

Works with any React frontend — Next.js, TanStack Start, Remix, Vite SPA.

## Repo layout

```
plugin/            WordPress plugin (no build step, vanilla JS editor)
  livepress.php      core: Site Pages CPT, editor screens, REST, meta
  livepress-schema.php   ← YOUR page schemas live here (reference included)
  assets/editor.js   the fullscreen editor app
  assets/editor.css
packages/bridge/   npm package `livepress-bridge` (React runtime)
  src/index.ts       useLiveEdits(), isEditMode(), click-to-edit sender
```

## Install

### 1. WordPress

Copy `plugin/` to `wp-content/plugins/livepress/` and activate. Then:

```bash
wp option update livepress_frontend "http://localhost:3000"
```

Define your pages in `livepress-schema.php` (a full reference schema ships in
the file — replace it with your own). Field kinds: `text`, `textarea`,
`lines` (newline list), `repeater` (sub-kind `image` adds a Media Library
picker). Every schema field is auto-registered as REST-visible post meta.

Create one `sitepage` doc per schema key (slug = key). Collections: filter
`livepress_collections` with post types and add `collection:{type}` schemas.

### 2. Frontend

```bash
npm i livepress-bridge
```

```tsx
import { useLiveEdits } from "livepress-bridge";

function AboutPage() {
  const doc = useLoaderDoc();          // your WP REST fetch, flat meta keys
  const d = useLiveEdits(doc);         // ← overlays admin keystrokes live
  return <h1>{(d?.hero_title as string) || "Fallback title"}</h1>;
}
```

That's the whole integration: fetch the page doc however you already fetch
WP content, wrap it in `useLiveEdits`, render with fallbacks. The hook is
inert outside the editor iframe (or `?edit=1`) — zero production cost.

Optional live channels (globals): listen for `aux-design` (CSS variable
tokens), `aux-menu` (nav config), `aux-footer` — see the protocol below.

## The protocol

```
admin → frontend:
  { type: "aux-edit",      path: "hero.title", value }    // dot-path overlay
  { type: "aux-edit-bulk", edits: [{ path, value }] }
  { type: "aux-edit-reset" }
  { type: "aux-design",    tokens: { radius, ... } }
  { type: "aux-menu",      nav: [{ key, label, visible }] }
  { type: "aux-footer",    footer: { ... } }
frontend → admin:
  { type: "aux-edit-ready" }            // editor rebroadcasts unsaved state
  { type: "aux-focus",     section }    // click-to-edit
```

`value`: string | string[] | row[]. The bridge validates path shape and
applies immutably.

## Wiring a page (the 4-step recipe)

1. **Route**: fetch the `sitepage` doc, `useLiveEdits(doc)`, render every
   string as `doc.key || "built-in fallback"`.
2. **Schema**: add the page entry — flat paths (`path` = meta key).
3. **Seed**: insert the `sitepage` doc with your current copy so editors see
   real values.
4. **Verify with a sentinel**: change one value in WP, request the page,
   expect the sentinel. Fail-soft masks dead wiring — body length lies.

## Hard-won rules

- Meta invisible in REST is the silent killer — LivePress registers every
  schema field itself; custom post types must support `custom-fields`.
- Repeaters travel as JSON strings; parse at your adapter boundary.
- Keep icons/animation chrome code-owned, matched to rows by index.
- One `sitepage` CPT, never CPT-per-page — sidebars don't scale.
- Long-form posts: keep Gutenberg, add a preview iframe. Don't rebuild it.

## Reference implementation

Extracted from a production build: 25 live-editable pages, 32 collection
detail editors, design tokens, menu editor, section reordering — all
verified end-to-end. See the schema file for real-world shapes.

## License

MIT
