/**
 * `order` is not meta.
 *
 * Every other field in LivePress is post meta, and the save path posts one
 * `{ meta: {...} }` bag. `order` binds to WordPress's own `menu_order`, which
 * lives on the post row — put it in the meta bag and WordPress stores a meta
 * key nobody reads while the real sort order never moves, with a cheerful 200
 * either way.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const body = /\tfunction splitSave\([\s\S]*?\n\t\}/.exec(src)[0];
const splitSave = new Function(`${body}; return splitSave;`)();

const defs = {
  photo_title: { kind: "text" },
  photo_featured: { kind: "bool" },
  photo_order: { kind: "order" },
};

const out = splitSave({ photo_title: "Pool", photo_featured: "1", photo_order: "30" }, defs);

assert.deepEqual(out.meta, { photo_title: "Pool", photo_featured: "1" });
assert.deepEqual(out.attrs, { menu_order: 30 });

/* A blank order is not zero. Zero is a real position and would silently move
   the photo to the front of the list. */
assert.deepEqual(splitSave({ photo_order: "" }, defs).attrs, {});

/* Anything non-numeric is dropped rather than coerced to 0, for the same
   reason. */
assert.deepEqual(splitSave({ photo_order: "abc" }, defs).attrs, {});

/* A bool stays a string: every LivePress value is a string, and one key that
   is sometimes boolean is how a resolver grows a `=== true` that never fires. */
assert.equal(typeof splitSave({ photo_featured: "1" }, defs).meta.photo_featured, "string");

console.log("save split: all assertions passed (menu_order leaves the meta bag)");
