/**
 * What a chosen file fills in beside itself, checked without WordPress.
 *
 * An image fills its alt text (alt-pairing.test.mjs); a film fills its
 * duration. The duration lives beside the film the way alt text lives beside
 * a picture — `video_file` beside `video_duration` — and a new file is a new
 * length, so leaving the old one would describe a film that is not there.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const lift = (name) => {
	const m = new RegExp(`\\tfunction ${name}\\([\\s\\S]*?\\n\\t\\}`).exec(src);
	assert.ok(m, `${name}() is not a top-level function in editor.js`);
	return m[0];
};
const { durationKeyFor, mmss, pairedValue } = new Function(
	`${lift("durationKeyFor")}\n${lift("mmss")}\n${lift("pairedValue")}\nreturn { durationKeyFor, mmss, pairedValue };`,
)();

/* The collection convention: video_file beside video_duration. */
assert.equal(durationKeyFor("video_file", ["video_title", "video_file", "video_duration"]), "video_duration");

/* The suffix convention a top-level field would use. */
assert.equal(durationKeyFor("cta_film", ["cta_film", "cta_film_duration"]), "cta_film_duration");

/* No duration field: nothing is invented. */
assert.equal(durationKeyFor("video_file", ["video_file", "video_title"]), null);

/* WordPress writes 1:10; this site writes 01:10. Hours survive, and
   anything unrecognised comes back trimmed, not guessed at. */
assert.equal(mmss("1:10"), "01:10");
assert.equal(mmss("0:05"), "00:05");
assert.equal(mmss("12:30"), "12:30");
assert.equal(mmss(" 3:07 "), "03:07");
assert.equal(mmss("1:02:03"), "1:02:03");
assert.equal(mmss(""), "");
assert.equal(mmss(undefined), "");

/* A film chosen in the Media Library brings its length as fileLength... */
assert.equal(pairedValue("video", { fileLength: "3:13", alt: "ignored" }), "03:13");

/* ...a film just uploaded over REST, as media_details.length_formatted. */
assert.equal(pairedValue("video", { media_details: { length_formatted: "0:45" } }), "00:45");

/* A film with no known length brings nothing, so the old duration is flagged, not blanked. */
assert.equal(pairedValue("video", { media_details: {} }), "");

/* An image still brings its alt text and nothing else. */
assert.equal(pairedValue("image", { alt: "A lounge", fileLength: "1:00" }), "A lounge");
assert.equal(pairedValue("image", { alt: 7 }), "");
assert.equal(pairedValue("video", null), "");

console.log("media pairing: all assertions passed (duration key found, lengths normalised, the right value per kind)");
