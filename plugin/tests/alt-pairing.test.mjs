/**
 * The image/alt pairing rule, checked without WordPress.
 *
 * Three conventions are live at once and a wrong answer is invisible: a
 * top-level picture is `cta_img` beside `cta_img_alt`, a collection's is
 * `photo_src` beside `photo_alt`, a repeater column is `src` beside plain
 * `alt`. Resolve the wrong one and the editor either
 * writes a description into a field nobody reads, or leaves the real alt
 * text describing an image that has been replaced.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const body = /\tfunction altKeyFor\([\s\S]*?\n\t\}/.exec(src)[0];
const altKeyFor = new Function(`${body}; return altKeyFor;`)();

/* Top-level convention. */
assert.equal(
  altKeyFor("cta_img", ["cta_img", "cta_img_alt", "cta_title"]),
  "cta_img_alt",
);

/* Repeater convention — the image column is `src`, the alt is plain `alt`. */
assert.equal(altKeyFor("src", ["src", "cat", "alt", "title"]), "alt");

/* Collection convention — `photo_src` beside `photo_alt`. The photo alt text
   is what ranks in Google Images, and without this candidate replacing a
   photo's image never touched or questioned it. */
assert.equal(altKeyFor("photo_src", ["photo_src", "photo_alt", "photo_title", "photo_sub"]), "photo_alt");

/* Prefers the specific name over the generic when both somehow exist. */
assert.equal(altKeyFor("src", ["src", "src_alt", "alt"]), "src_alt");

/* No alt field at all — some pictures genuinely have none, and inventing a
   key would write into a field the schema never declared. */
assert.equal(altKeyFor("cmp_before_img", ["cmp_before_img", "cmp_after_img"]), null);

/* Never resolves to the image field itself, which would overwrite the URL
   with a sentence. The guard matters for a field literally named `alt`. */
assert.equal(altKeyFor("alt", ["alt", "src"]), null);

console.log("alt pairing: all assertions passed (all three conventions, and no-alt returns null)");
