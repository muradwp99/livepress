/**
 * The message one field's live edit travels in, checked without WordPress.
 *
 * A collection item previews among its siblings — a photo on the whole
 * photos grid — so its edits must say which item they belong to, or the
 * frontend has nowhere to put them. A page edit must not carry one: the
 * bridge reads a present `item` as a claim to be an item edit, and files the
 * edit away from the page it belongs to.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const lift = (name) => {
	const m = new RegExp(`\\tfunction ${name}\\([\\s\\S]*?\\n\\t\\}`).exec(src);
	assert.ok(m, `${name}() is not a top-level function in editor.js`);
	return m[0];
};

const editMessage = new Function(`${lift("editMessage")}; return editMessage;`)();

const text = { key: "photo_title", path: "photo_title", kind: "text" };
const list = { key: "hero_title", path: "hero_title", kind: "lines" };

/* A page edit is exactly the message every frontend already understands. */
assert.deepEqual(editMessage(list, " One \n\n Two ", 0), { type: "aux-edit", path: "hero_title", value: ["One", "Two"] });

/* The break: a collection edit that does not name its item. */
assert.deepEqual(editMessage(text, "Caption", 11821), { type: "aux-edit", path: "photo_title", value: "Caption", item: 11821 });

/* No item means no key, not `item: 0`: the bridge drops an edit whose item
   is not a post id, so a 0 would silently throw away every page edit. */
assert.equal("item" in editMessage(text, "Caption", 0), false);
assert.equal("item" in editMessage(text, "Caption", undefined), false);

/* An empty list sends an empty list, which the frontend reads as the
   fallback, the same as a saved empty field. */
assert.deepEqual(editMessage(list, "", 0).value, []);
assert.deepEqual(editMessage(list, undefined, 0).value, []);

/* Any other kind goes through as it is: a repeater's rows, a pick's ids. */
const faqRows = [{ q: "Q", a: "A" }];
assert.equal(editMessage({ key: "faqs", path: "faqs", kind: "repeater" }, faqRows, 0).value, faqRows);

/* ── broadcast(): the mode decides whether the post id rides along ── */

const onSite = new Function(`${lift("onSite")}; return onSite;`)();

function harness(B) {
	const sent = [];
	const defs = { photo_title: text, hero_title: list };
	const values = { photo_title: "Caption", hero_title: "One\nTwo" };
	const frame = { contentWindow: { postMessage: (msg, origin) => sent.push({ msg, origin }) } };
	const broadcast = new Function(
		"B", "frame", "values", "fieldDef", "editMessage", "onSite",
		`${lift("broadcast")}; return broadcast;`,
	)(B, frame, values, (k) => defs[k] ?? null, editMessage, onSite);
	return { sent, broadcast };
}

/* Collection mode: a published photo's own post id goes with each edit. */
{
	const { sent, broadcast } = harness({ mode: "collection", postId: 11821, status: "publish", frontend: "https://frontend.example" });
	broadcast("photo_title");
	assert.deepEqual(sent, [{
		msg: { type: "aux-edit", path: "photo_title", value: "Caption", item: 11821 },
		origin: "https://frontend.example",
	}]);
}

/* An unpublished item is not on the page the preview shows, so nothing is
   sent: laying it over the published grid would present a draft as live. */
for (const status of ["draft", "pending", "private", "future"]) {
	const { sent, broadcast } = harness({ mode: "collection", postId: 11821, status, frontend: "https://frontend.example" });
	broadcast("photo_title");
	assert.deepEqual(sent, [], `a ${status} item must not reach the preview`);
}

/* No status at all (an older boot payload) behaves as before rather than
   silencing the preview. */
{
	const { sent, broadcast } = harness({ mode: "collection", postId: 11821, frontend: "https://frontend.example" });
	broadcast("photo_title");
	assert.equal(sent.length, 1);
}

/* Page mode sends no item, although a Site Page has a post id too — and a
   page's status never gates its preview. */
{
	const { sent, broadcast } = harness({ mode: "page", postId: 42, status: "draft", frontend: "https://frontend.example" });
	broadcast("hero_title");
	assert.deepEqual(sent, [{
		msg: { type: "aux-edit", path: "hero_title", value: ["One", "Two"] },
		origin: "https://frontend.example",
	}]);
}

console.log("edit message: all assertions passed (item named in collection mode only, lines split, other kinds passed through)");
