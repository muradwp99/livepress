/**
 * What counts as a clash when a save finds the document has moved.
 *
 * Only meta is compared, so only meta can clash. `order` is the post's own
 * menu_order, and it used to be compared against a meta key nothing writes:
 * the second order save in a session always asked "Somebody else changed
 * Sort order", about a change nobody else had made.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const body = /\tfunction metaClashes\([\s\S]*?\n\t\}/.exec(src)[0];
const metaClashes = new Function(`${body}; return metaClashes;`)();

const defs = { photo_title: { kind: "text" }, photo_order: { kind: "order" } };

/* A second save in one session: `before` is what I saved the first time,
   and the document's meta still carries the dead key as "", exactly as
   1.5.6 served it. The order field must not be compared at all. */
const mine = [
  { key: "photo_order", label: "Sort order", before: "5" },
  { key: "photo_title", label: "Caption title", before: "Pool" },
];
assert.deepEqual(metaClashes(mine, { photo_order: "", photo_title: "Pool" }, defs), []);

/* Somebody really did change a meta field under me: still reported. */
assert.deepEqual(
  metaClashes(mine, { photo_order: "", photo_title: "Pond" }, defs).map((c) => c.key),
  ["photo_title"],
);

/* A key the document does not carry cannot clash. */
assert.deepEqual(metaClashes([{ key: "photo_title", before: "Pool" }], {}, defs), []);

/* A key with no field def (section_order is not a schema field) is still
   compared, as before. */
assert.deepEqual(
  metaClashes([{ key: "section_order", before: "a" }], { section_order: "b" }, defs).map((c) => c.key),
  ["section_order"],
);

/* And it is this function the save path actually asks. */
assert.match(src, /function checkConflict\(\) \{[\s\S]*?metaClashes\( mine,/);

console.log("conflict: all assertions passed (order never compared as meta, real meta clashes still caught)");
