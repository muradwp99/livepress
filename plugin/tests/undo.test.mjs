/**
 * The undo stack's semantics, checked without a browser.
 *
 * Three things here are easy to get wrong and invisible when you do:
 * tabbing through fields without typing must not fill the stack with entries
 * that undo nothing; a single field visit must produce exactly one entry
 * however many characters are typed; and the cap must drop the OLDEST entry,
 * because dropping the newest would silently make the next undo jump you back
 * further than the thing you just did.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const block = src.slice(
  src.indexOf("\tvar undoStack = [];"),
  src.indexOf("\t/* ---------- Wave 1: safety ----------"),
);

/* Stub everything the block reaches for; we are testing stack mechanics. */
const harness = `
  var values = {}, globals = { design: {} }, dirty = false;
  var bars = [];
  function broadcastAll() {}
  function rerenderPanel() {}
  function persistDraft() {}
  function refreshReviewCount() {}
  function refreshSectionBadges() {}
  function sendRaw() {}
  function bar(msg) { return msg; }
  /* The editor's i18n shim provides these; the block under test calls them
     now that its messages are translatable. A stub list that silently falls
     behind the code is how a test starts passing for the wrong reason, or in
     this case stops running at all. */
  function __(t) { return t; }
  function sprintf(f) { const a = [].slice.call(arguments, 1); return String(f).replace(/%[sd]/g, () => a.shift()); }
  function showBar(m) { bars.push(m); }
  var document = { getElementById: () => null };
  ${block}
  return {
    setState: (v, g) => { values = v; globals = g; },
    getState: () => ({ values, globals }),
    depth: () => undoStack.length,
    top: () => undoStack[undoStack.length - 1],
    pushUndo, armUndo, fireArmedUndo, cancelArmedUndo, undo,
    bars, UNDO_MAX,
  };
`;
const u = new Function(harness)();

/* Arming without editing leaves nothing behind. */
u.setState({ a: "one" }, { design: {} });
u.armUndo("edit Headline");
u.cancelArmedUndo();
assert.equal(u.depth(), 0, "tabbing through a field must not push an entry");

/* A field visit is one entry however many keystrokes. */
u.armUndo("edit Headline");
u.fireArmedUndo();
u.fireArmedUndo();
u.fireArmedUndo();
assert.equal(u.depth(), 1, "one entry per field visit, not per keystroke");

/* Undo restores the values AND the globals — the design screen lives there. */
u.setState({ a: "one" }, { design: { gold500: "#aaa" } });
u.pushUndo("reset every colour");
u.setState({ a: "two" }, { design: { gold500: "#fff" } });
u.undo();
assert.deepEqual(u.getState().values, { a: "one" }, "values restored");
assert.deepEqual(u.getState().globals, { design: { gold500: "#aaa" } }, "globals restored");

/* Snapshots are deep: mutating live state must not reach into the stack. */
u.setState({ rows: [{ t: "keep" }] }, { design: {} });
u.pushUndo("delete row");
u.getState().values.rows[0].t = "clobbered";
u.undo();
assert.equal(u.getState().values.rows[0].t, "keep", "snapshot must be a deep copy");

/* The cap drops the oldest, so the next undo is always the most recent act. */
while (u.depth()) u.undo();
for (let i = 0; i < u.UNDO_MAX + 10; i++) u.pushUndo("step " + i);
assert.equal(u.depth(), u.UNDO_MAX, "stack is capped");
assert.equal(u.top().label, "step " + (u.UNDO_MAX + 9), "newest survives");
u.undo();
assert.equal(u.top().label, "step " + (u.UNDO_MAX + 8), "undo walks back one step at a time");

/* Undoing an empty stack is a no-op, not a crash. */
while (u.depth()) u.undo();
u.undo();
assert.equal(u.depth(), 0, "empty undo is harmless");

console.log(`undo: all assertions passed (cap ${u.UNDO_MAX}, deep snapshots, one entry per field visit)`);
