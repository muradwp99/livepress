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
plugin/                 the WordPress plugin
  livepress.php         core: menu, REST, meta registration, editor screen
  livepress-schema.php  loader — globs schema-*.php beside it
  admin-screens.php     Content health, Field history
  settings.php          frontend URL, exposed design tokens
  headless.php          public page views 301 to the frontend
  seo-meta.php          SEO fields writable over REST
  activity.php          who changed what, when
  schedule.php          publish a pending change at a chosen time
  migration.php         move field keys without losing values
  field-orphans.php     fields the frontend no longer reads
  broken-links.php      404s the frontend reported back
  asset-check.php       images pointing at the wrong host
  page-text.php         plain-text export of a page's fields
  enquiries.php         form submissions inbox (needs a plugin that stores
                        them — see Enquiries below)
  livepress-forms.php   form schema registration
  assets/               editor.js, editor.css, admin.css
  languages/            translations (see languages/README.md)
  tests/                `node tests/run.mjs`
examples/
  schema-faq.php        one page's schema, to copy
packages/bridge/        the frontend npm package
```

## Install

### 1. WordPress

Copy `plugin/` to `wp-content/plugins/livepress/` and activate. Then:

```bash
wp option update livepress_frontend "http://localhost:3000"
```

Define your pages as `schema-*.php` files inside the plugin directory, one
per page, each returning an array. `livepress-schema.php` globs them and
merges the result, so adding a page is adding a file — nothing to register.
Copy `examples/schema-faq.php` to `plugin/schema-home.php` and edit.

> Changed in 1.1. Previously a single `livepress-schema.php` held every page,
> which meant the plugin shipped with one site's content model compiled into
> it and updating the plugin meant merging your schema by hand. The glob keeps
> your pages out of the plugin's own files. Name them `schema-*.php` and note
> that the glob is not fussy: `schema-anything.example.php` matches too, so
> keep samples out of the plugin root.

Field kinds: `text`, `textarea`, `lines` (newline list), `image`, `video` (a
film: the Media Library's video picker, upload or drop, web-playable files
only), `repeater` (sub-kind `image` adds a Media Library picker), and three
that collections
brought: `bool` (a checkbox, stored `"1"` or `""`), `order` (the post's own
`menu_order`, never meta) and `pick` (chosen items of a collection, in order,
stored as a JSON array of post ids). Every schema field except `order` is
auto-registered as REST-visible post meta.

Create one `sitepage` doc per schema key (slug = key).

Collections — many posts of one type, such as photos — are declared the same
way: a `collection:{type}` schema is the whole declaration, and LivePress
registers the post type, its labels and its taxonomy from it
(`collections.php`). Items are edited in the same fullscreen editor, preview
on the schema's `frontendPath`, and are read by the frontend over plain WP
REST. The `livepress_collections` filter still adds or removes types by hand.

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

The Design screen's controls are declared in `livepress_design_tokens()`, not
hard-coded into the editor. A token is a colour unless it says
`kind => 'length'`, which renders a slider and a number instead of a swatch and
carries `min`/`max`/`step`/`unit` — corner radius is the one that ships. A
length is stored bare so the frontend appends the unit, which is what makes `0`
a real setting rather than an empty one. In both kinds a value equal to the
declared fallback is deleted instead of stored, so Reset hands the token back
to your stylesheet rather than pinning it to whatever the stylesheet says
today.

### Allowing the editor to drive the page

```ts
import { configureEditOrigins } from "livepress-bridge";

configureEditOrigins("https://admin.example.com");
```

> New in 1.1, and it is a breaking change: without this the bridge accepts no
> edits and the live preview stays inert.
>
> It used to accept a `postMessage` from anybody. `isEditMode()` is true for
> any cross-origin parent, so any site could iframe a page using this bridge —
> or open it with `?edit=1` through `window.open` and keep the handle — and
> rewrite whatever copy it liked. Frameworks escape the values, so it was never
> XSS; it was content spoofing, and a visitor had no way to tell. An empty
> allowlist now accepts nothing, which is the right direction to fail.
>
> Pair it with `frame-ancestors` on the frontend so the browser enforces the
> same rule the JS does. Worth checking that header actually arrives: some
> CDNs replace `Content-Security-Policy` with their own, in which case the JS
> allowlist is the only gate you have.

## The protocol

```
admin → frontend:
  { type: "aux-edit",      path: "hero.title", value }    // dot-path overlay
  { type: "aux-edit",      path: "photo_title", value, item: 11821 }
  { type: "aux-edit-bulk", edits: [{ path, value, item? }] }
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

`item` is the post id of the collection item being edited, sent in collection
mode only. A photo previews on the whole photos grid, so `photo_title` alone
would not say which photo it belongs to; the bridge keeps these edits apart
from the page's own, and a page that shows many items reads them with
`useLiveItemEdits()` — post id → that item's unsaved fields — and lays them
over the item with the same mapping it publishes with. A message without
`item` is a page edit, exactly as before, and one whose `item` is not a post id
lands nowhere. An unpublished item is not on the page the preview shows, so
the editor sends nothing for it and says why.

Before 1.5.8 no `lines` or `repeater` edit reached a flat-meta frontend: the
editor posts the list and the rows themselves, and readers that accepted only
WordPress's text sent every one back to the fallback. Read both shapes.

`path` matches `/^[a-zA-Z][a-zA-Z0-9._]{0,80}$/`. Underscores were missing
from that pattern until 1.1, which mattered more than it sounds: schemas are
flat WordPress meta keys now, `path === key`, and every one of them looks like
`hero_title`. Anything a schema sent failed the test and was dropped one key at
a time, with no error and a preview that simply never moved. Dots still work,
for a frontend overlaying onto a nested object.

## Wiring a page (the 4-step recipe)

1. **Route**: fetch the `sitepage` doc, `useLiveEdits(doc)`, render every
   string as `doc.key || "built-in fallback"`.
2. **Schema**: add the page entry — flat paths (`path` = meta key).
3. **Seed**: insert the `sitepage` doc with your current copy so editors see
   real values.
4. **Verify with a sentinel**: change one value in WP, request the page,
   expect the sentinel. Fail-soft masks dead wiring — body length lies.

## Undo

The editor keeps an undo stack for the moves the browser cannot undo for you:
deleting a repeater row, reordering, replacing a picture, resetting the brand
colours — plus one entry per field you visit and change.

Deliberately not keystroke-level. Inside a focused text field Ctrl+Z belongs
to the browser, which knows about words, selections and the caret; binding it
globally would take that away and replace it with something coarser. So the
shortcut only fires when the caret is outside a text field, which is exactly
where there is no native undo to lose.

## Preview a scheduled change

A change parked with Schedule gets a link that renders it on the real
frontend, for somebody who has no WordPress login — which is usually the
person whose approval you want.

The token lives on the pending record rather than in a store of its own, so
it stops working the moment the change lands or is cancelled. Rescheduling
regenerates it, invalidating a link already sent: the old one was shared to
show a specific change and would otherwise quietly show a different one.

The frontend reads `?lp_preview=<token>`, fetches the values and overlays
them through the same bridge a live keystroke uses, and adds `noindex`. A
dead link shows the published page, which is the honest failure — the change
is gone or already live, and either way what you see is what is true.

The frontend half ships in bridge 1.1. `useLiveEdits()` picks the token up on
its own, moves it straight out of the address bar into `sessionStorage` and
rewrites the URL — a token in a query string ends up in browser history, in
access logs, and on the screen of whoever you are sharing it with — then adds
`noindex` and overlays the values through the same path a live keystroke uses.

`isPreviewMode()` answers whether a preview is active without changing
anything, for the things that should stay out of the way: a cookie banner has
no business appearing over a page somebody was sent to approve.

You still supply the route it fetches, which proxies
`/wp-json/livepress/v1/preview/{token}` so the CMS hostname stays out of the
client bundle. It defaults to `/api/preview/<token>`; `configurePreviewPath()`
moves it.

## Who else has the page open

Opening a document shows a warning if somebody else already has it open, and
the editor keeps that lock alive over WordPress's heartbeat so a third person
arriving later is told too.

This is core's post lock and core's own refresh handler — LivePress simply
never used them, because it redirects away from the classic editor that sets
the lock. The lock is deliberately not taken when somebody else holds it:
claiming it would evict them from a screen they are working in, to warn them
about the person who evicted them.

It is an early warning, not the safeguard. The safeguard is still the
field-by-field comparison at save, which catches a genuine clash whether or
not anyone was warned.

## Alt text follows the picture

Choosing a new image pulls that attachment's alt text from the Media Library
into the picture's paired alt field — `cta_img` beside `cta_img_alt` for a
top-level field, `photo_src` beside `photo_alt` in a collection, `src` beside
plain `alt` inside a repeater. If the attachment
has no alt text (a fresh upload never does) the existing text is left alone
and flagged, because deleting somebody's sentence is not the picker's call.

Without this, changing an image left the page carrying a confident
description of a picture that was no longer there — read aloud as fact, with
nothing to say otherwise.

## A film brings its length

Choosing or uploading a film fills its paired duration field — `video_file`
beside `video_duration` — from the attachment's length. Only files a browser
plays inline are taken (MP4, WebM or Ogg, judged by type or by extension, so a
Mac's `.m4v` gets through); a `.mov` is refused with the reason, not saved.
Uploads over the server's `wp_max_upload_size()` are refused before they are
sent, and a failed upload keeps the server's reason on screen until dismissed.

In a collection whose schema pairs `<x>_file` with `<x>_youtube` and
`<x>_poster`, a warning stays up while the item has nothing the site would
show — nothing to play, or no cover — and says which is missing. It comes down
the moment the item would show.

## Translation

Text domain `livepress`, and the pass is complete — every user-facing string
in the plugin and the editor is translatable. See `plugin/languages/README.md` for
how to generate the template — and note `make-json` as well as `make-pot`,
since `wp.i18n` reads JSON and skipping it leaves the whole editor in English
while the admin screens change around it.

## Enquiries

The Enquiries screen lists submissions stored as a private `enquiry` post
type. LivePress does not create that post type or receive the submissions —
it renders an inbox for whatever does. It attaches through a registration
filter rather than re-registering, so the plugin that owns the post type keeps
owning it. With no such plugin installed the screen is simply empty.

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
