/**
 * What a picker stores.
 *
 * IDs, not slugs: a slug changes when somebody edits a title, and a featured
 * list that silently empties itself because a photo was renamed is worse than
 * one that survives the rename.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const body = /\tfunction pickValue\([\s\S]*?\n\t\}/.exec(src)[0];
const pickValue = new Function(`${body}; return pickValue;`)();

/* Order is the whole point — it is the order they appear on the page. */
assert.deepEqual(pickValue([12, 3, 40], 8), [12, 3, 40]);

/* Strings arrive from the DOM; they are positions, not labels. */
assert.deepEqual(pickValue(["12", "3"], 8), [12, 3]);

/* A duplicate would render the same photo twice and shift everything after
   it; keep the first occurrence so the earlier deliberate choice wins. */
assert.deepEqual(pickValue([5, 5, 6], 8), [5, 6]);

/* Over the max, the extras are dropped rather than silently rejected whole. */
assert.deepEqual(pickValue([1, 2, 3], 2), [1, 2]);

/* Junk is dropped, not coerced: NaN would serialise to null, a pick that can
   never resolve to a photo. */
assert.deepEqual(pickValue([1, "x", null, 0, 2], 8), [1, 2]);

console.log("pick value: all assertions passed (ordered, deduped, capped, integers only)");
