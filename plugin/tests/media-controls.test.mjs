/**
 * The film and image pickers, run for real outside wp-admin.
 *
 * mediaControls and the field builder's media branch are lifted out of
 * editor.js as they stand; only what surrounds them is faked: a small DOM,
 * WordPress's media dialog and its REST API. The spec asked for a check of
 * the media type a `video` field chooses. This is it, along with what a film
 * field does with whatever it is given: a film from the Media Library, an
 * upload, a drop, a file the site cannot play, one too big to send, and a
 * server that says no. Last, the warning the editor keeps up while the film
 * being edited would be hidden on the site.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");
const liftFn = (name) => {
	const m = new RegExp(`\\tfunction ${name}\\([\\s\\S]*?\\n\\t\\}`).exec(src);
	assert.ok(m, `${name}() is not a top-level function in editor.js`);
	return m[0];
};
const liftVar = (name) => {
	const m = new RegExp(`\\tvar ${name} =[\\s\\S]*?;\\r?\\n`).exec(src);
	assert.ok(m, `var ${name} is not declared at the top level of editor.js`);
	return m[0];
};
/* The field builder's media branch, verbatim. */
const start = src.indexOf('if ( def.kind === "image" || def.kind === "video" ) {');
const end = src.indexOf("fields.appendChild( fill( wrap, kids ) );", start);
assert.ok(start > 0 && end > start, "the field builder's media branch is not where this test looks for it");
const branch = src.slice(start, end);

/* ── a DOM just big enough ── */

const registry = [];
class Node {
	constructor(tag) {
		Object.assign(this, { tagName: tag.toUpperCase(), attrs: {}, children: [], listeners: {}, parent: null, value: "", disabled: false, textContent: "", innerHTML: "" });
		registry.push(this);
	}
	get className() { return this.attrs.class || ""; }
	set className(v) { this.attrs.class = v; }
	get classList() {
		const names = () => this.className.split(/\s+/).filter(Boolean);
		return {
			add: (...c) => { this.className = [...new Set([...names(), ...c])].join(" "); },
			remove: (...c) => { this.className = names().filter((n) => !c.includes(n)).join(" "); },
			contains: (c) => names().includes(c),
			toggle() {},
		};
	}
	setAttribute(k, v) { this.attrs[k] = String(v); }
	appendChild(c) { this.children.push(c); c.parent = this; return c; }
	remove() {
		if (this.parent) this.parent.children = this.parent.children.filter((c) => c !== this);
		this.parent = null;
	}
	addEventListener(t, f) { (this.listeners[t] ||= []).push(f); }
	dispatch(t, e = {}) { for (const f of this.listeners[t] || []) f({ preventDefault() {}, ...e }); }
	click() { this.dispatch("click"); }
	all() { return [this, ...this.children.flatMap((c) => c.all())]; }
	/* A tag name ("span") or a run of classes (".lp-bar.upload-failed"). */
	querySelector(sel) {
		const below = this.all().slice(1);
		if (/^[a-z]+$/.test(sel)) return below.find((n) => n.tagName === sel.toUpperCase()) || null;
		const classes = sel.split(".").filter(Boolean);
		return below.find((n) => classes.every((c) => n.classList.contains(c))) || null;
	}
}
const barsHost = new Node("div");
const document = {
	createElement: (t) => new Node(t),
	getElementById: (id) => (id === "lp-bars" ? barsHost : null),
	/* The builder finds a paired field's input by its data-field. */
	querySelector(sel) {
		const key = /data-field="([^"]+)"/.exec(sel)[1];
		const wrap = registry.find((n) => n.attrs["data-field"] === key);
		return wrap ? wrap.all().find((n) => n.tagName === "INPUT" && n.className === "lp-input") || null : null;
	},
};

/* ── WordPress around the editor, faked ── */

const mediaCalls = [];
const apiCalls = [];
let attachment = null;
let reply = () => Promise.resolve(null);
const timers = [];
const errors = [];
const env = {
	document,
	wp: {
		media(opts) {
			mediaCalls.push(opts);
			const on = {};
			return {
				on: (e, f) => { on[e] = f; },
				open: () => { if (attachment) on.select(); },
				state: () => ({ get: () => ({ first: () => ({ toJSON: () => attachment }) }) }),
			};
		},
		apiFetch(opts) { apiCalls.push(opts); return reply(); },
	},
	/* wp_max_upload_size() on the live host: post_max_size, 256 MB. */
	B: { maxUpload: 256 * 1048576 },
	__: (s) => s,
	sprintf: (f, ...a) => String(f).replace(/%[sd]/g, () => a.shift()),
	/* Held, not run, so a check can look before and after the button's reset. */
	setTimeout: (f) => { timers.push(f); },
	console: { error: (...a) => errors.push(a) },
};

const { buildField, isWebFilm } = new Function(
	...Object.keys(env),
	[
		liftFn("el"), liftFn("fill"), liftFn("bar"), liftFn("showBar"),
		liftVar("ICON_LIBRARY"), liftVar("ICON_UPLOAD"), liftVar("WEB_FILM_MIMES"), liftVar("WEB_FILM_EXTS"),
		liftFn("isWebFilm"), liftFn("uploadMedia"), liftFn("altKeyFor"), liftFn("durationKeyFor"), liftFn("mmss"),
		liftFn("pairedValue"), liftFn("syncAlt"), liftFn("syncDuration"), liftFn("mediaControls"),
		/* One field, built the way the editor's builder builds it. */
		`return { isWebFilm: isWebFilm, buildField: function ( def, values, keys, setValue ) {
			var pushUndo = function () {};
			var allFieldKeys = function () { return keys; };
			var imagePreview = function () { var n = el( "div", { class: "lp-thumb" } ); n.refresh = function () {}; return n; };
			var control = el( "input", { class: "lp-input", type: "text" } );
			control.value = values[ def.key ] || "";
			var kids = [ el( "label", { class: "lp-label", text: def.label } ), control ];
			var wrap = el( "div", { class: "lp-field", "data-field": def.key } );
			${branch}
			return fill( wrap, kids );
		} };`,
	].join("\n"),
)(...Object.values(env));

/* A page holding a film field and its duration, a picture and its alt. */
const values = { video_file: "", video_duration: "02:00", photo_src: "", photo_alt: "A lounge" };
const setValue = (k, v) => { values[k] = v; };
const plain = (key) => {
	const wrap = new Node("div");
	wrap.setAttribute("data-field", key);
	const input = wrap.appendChild(new Node("input"));
	input.className = "lp-input";
	input.value = values[key];
	return input;
};
const durInput = plain("video_duration");
const altInput = plain("photo_alt");
const film = buildField({ key: "video_file", label: "Uploaded film", kind: "video" }, values, Object.keys(values), setValue);
const pic = buildField({ key: "photo_src", label: "Photo", kind: "image" }, values, Object.keys(values), setValue);

const find = (root, pred) => root.all().find(pred);
const button = (root, text) => find(root, (n) => n.tagName === "BUTTON" && (n.textContent === text || n.children.some((c) => c.textContent === text)));
const fileInput = (root) => find(root, (n) => n.tagName === "INPUT" && n.attrs.type === "file");
const flush = async () => { await new Promise((r) => setImmediate(r)); await new Promise((r) => setImmediate(r)); };
/* The request settles, then the button's 1.6 s reset runs. */
const settle = async () => {
	await flush();
	while (timers.length) timers.shift()();
};
const choose = (root, file) => {
	const input = fileInput(root);
	input.files = [file];
	input.dispatch("change");
};
const upload = async (root, file) => {
	choose(root, file);
	await settle();
};
const said = (node) => node.querySelector(".lp-bar-msg").textContent;
const lastBar = () => barsHost.children.at(-1);
const failBars = () => barsHost.children.filter((b) => b.classList.contains("upload-failed"));

/* ── which files are films: the type the browser reports, else the name ── */

assert.equal(isWebFilm("video/mp4", "clip"), true, "a web film's type is enough on its own");
assert.equal(isWebFilm("video/x-m4v", "clip.m4v"), true);
assert.equal(isWebFilm("", "CLIP.M4V"), true);
assert.equal(isWebFilm("video/quicktime", "clip.mov"), false);
assert.equal(isWebFilm("", ""), false);

/* ── a film field: films only, and no thumbnail ── */

assert.equal(find(film, (n) => n.className === "lp-thumb"), undefined, "a film field grew a thumbnail");
assert.equal(fileInput(film).attrs.accept, "video/mp4,video/webm,video/ogg,.mp4,.m4v,.webm,.ogv,.ogg");
assert.equal(fileInput(pic).attrs.accept, "image/*");

/* Choose: the library shows films, the URL lands, the duration follows. */
attachment = { url: "https://admin.example/f/a.mp4", mime: "video/mp4", fileLength: "0:05" };
button(film, "Choose").click();
assert.equal(mediaCalls.at(-1).library.type, "video");
assert.equal(mediaCalls.at(-1).title, "Choose film");
assert.equal(values.video_file, "https://admin.example/f/a.mp4");
assert.equal(values.video_duration, "00:05");
assert.equal(durInput.value, "00:05");
assert.equal(said(lastBar()), "Duration filled in from the film.");

/* An .m4v typed oddly is still a film the site plays: its name decides. */
attachment = { url: "https://admin.example/f/g.m4v", mime: "video/x-m4v", fileLength: "0:07" };
button(film, "Choose").click();
assert.equal(values.video_file, "https://admin.example/f/g.m4v");

/* A .mov: refused, and nothing changes. */
attachment = { url: "https://admin.example/f/b.mov", mime: "video/quicktime", fileLength: "1:00" };
button(film, "Choose").click();
assert.equal(values.video_file, "https://admin.example/f/g.m4v");
assert.match(said(lastBar()), /^Only MP4, WebM or Ogg/);

/* No known length: the old duration stays, flagged. */
attachment = { url: "https://admin.example/f/c.webm", mime: "video/webm" };
button(film, "Choose").click();
assert.equal(values.video_file, "https://admin.example/f/c.webm");
assert.equal(values.video_duration, "00:07");
assert.ok(lastBar().classList.contains("warn"));

/* Upload: POSTed to /wp/v2/media, and the URL and the length come back. */
reply = () => Promise.resolve({ source_url: "https://admin.example/f/d.mp4", media_details: { length_formatted: "1:10" } });
await upload(film, new File(["x"], "d.mp4", { type: "video/mp4" }));
assert.equal(apiCalls.at(-1).path, "/wp/v2/media");
assert.equal(apiCalls.at(-1).method, "POST");
assert.equal(values.video_file, "https://admin.example/f/d.mp4");
assert.equal(values.video_duration, "01:10");

/* Dropped on the field: the same path. */
reply = () => Promise.resolve({ source_url: "https://admin.example/f/e.webm", media_details: { length_formatted: "12:30" } });
film.dispatch("drop", { dataTransfer: { files: [new File(["x"], "e.webm", { type: "video/webm" })], types: ["Files"] } });
await settle();
assert.equal(values.video_file, "https://admin.example/f/e.webm");
assert.equal(values.video_duration, "12:30");

/* The type a browser reports is not the last word: a Mac calls an .m4v
   video/x-m4v, and some files arrive with no type. The name decides, as it
   does on the site. */
for (const [name, type] of [["h.m4v", "video/x-m4v"], ["i.m4v", ""]]) {
	reply = () => Promise.resolve({ source_url: `https://admin.example/f/${name}`, media_details: {} });
	await upload(film, new File(["x"], name, { type }));
	assert.equal(values.video_file, `https://admin.example/f/${name}`, `${name} (${type || "no type"}) was refused`);
}

/* What the site cannot play never leaves this computer. */
const sent = apiCalls.length;
for (const [name, type] of [["j.mov", "video/quicktime"], ["k.mov", ""], ["film", ""]]) {
	await upload(film, new File(["x"], name, { type }));
	assert.match(said(lastBar()), /^Only MP4, WebM or Ogg/, `${name} (${type || "no type"}) was not refused`);
}
assert.equal(apiCalls.length, sent, "a refused film was uploaded anyway");

/* Too big to send: refused before a byte goes, naming the limit. */
await upload(film, { name: "big.mp4", type: "video/mp4", size: 256 * 1048576 + 1 });
assert.equal(apiCalls.length, sent, "an oversized film was uploaded anyway");
assert.equal(said(lastBar()), "That file is over 256 MB, the most this site accepts, so it was not uploaded. Make it smaller and try again.");
assert.ok(lastBar().classList.contains("warn"));

/* The server says no: its own reason, still up after the button's
   "Failed" has gone back to "Upload". */
reply = () => Promise.reject({ code: "rest_upload_file_type", message: "Sorry, you are not allowed to upload this file type." });
choose(film, new File(["x"], "l.mp4", { type: "video/mp4" }));
await flush();
assert.ok(button(film, "Failed"), "the button did not say Failed");
await settle();
assert.equal(button(film, "Upload").disabled, false, "the button's own reset did not run");
assert.deepEqual(failBars().map(said), ["Sorry, you are not allowed to upload this file type."]);

/* No reason given: a plain one, replacing the last rather than stacking. */
reply = () => Promise.reject({});
await upload(film, new File(["x"], "m.mp4", { type: "video/mp4" }));
assert.equal(failBars().length, 1, "failure bars stacked up");
assert.match(said(failBars()[0]), /^The upload failed\./);

/* Dismiss takes it down. */
button(failBars()[0], "Dismiss").click();
assert.equal(failBars().length, 0);

/* A failure is stale once the next upload starts, and one that works clears it. */
reply = () => Promise.reject({ message: "Specified file failed upload test." });
await upload(film, new File(["x"], "n.mp4", { type: "video/mp4" }));
assert.equal(failBars().length, 1);
reply = () => Promise.resolve({ source_url: "https://admin.example/f/o.mp4", media_details: {} });
await upload(film, new File(["x"], "o.mp4", { type: "video/mp4" }));
assert.equal(failBars().length, 0, "the last attempt's failure outlived a successful upload");
assert.equal(values.video_file, "https://admin.example/f/o.mp4");
assert.equal(errors.length, 3, "each failure is logged to the console as well");

/* ── an image field: as before ── */

attachment = { url: "https://admin.example/i/1.jpg", mime: "image/jpeg", alt: "A terrace" };
button(pic, "Choose").click();
assert.equal(mediaCalls.at(-1).library.type, "image");
assert.equal(mediaCalls.at(-1).title, "Choose image");
assert.equal(values.photo_src, "https://admin.example/i/1.jpg");
assert.equal(values.photo_alt, "A terrace");
assert.equal(altInput.value, "A terrace");
assert.equal(said(lastBar()), "Alt text updated from the Media Library.");

reply = () => Promise.resolve({ source_url: "https://admin.example/i/2.jpg" });
await upload(pic, new File(["x"], "2.jpg", { type: "image/jpeg" }));
assert.equal(values.photo_src, "https://admin.example/i/2.jpg");
assert.equal(values.photo_alt, "A terrace", "an upload wiped the alt text");
assert.ok(lastBar().classList.contains("warn"));

/* The same limit holds for a picture, and a non-picture is ignored. */
const before = apiCalls.length;
await upload(pic, { name: "huge.jpg", type: "image/jpeg", size: 300 * 1048576 });
assert.match(said(lastBar()), /^That file is over 256 MB/);
await upload(pic, new File(["x"], "a.pdf", { type: "application/pdf" }));
assert.equal(apiCalls.length, before, "an oversized picture or a PDF was uploaded");

/* ── the warning a film raises while the site would hide it ── */

barsHost.children = [];
const B = {
	mode: "collection",
	schema: {
		sections: [
			{
				key: "video",
				fields: [
					{ key: "video_title", kind: "text" },
					{ key: "video_youtube", kind: "text" },
					{ key: "video_file", kind: "video" },
					{ key: "video_poster", kind: "image" },
				],
			},
		],
	},
};
const item = { video_title: "Lobby", video_youtube: "z_XEF7EfrJo", video_file: "", video_poster: "" };
const refreshFilmBar = new Function(
	"document", "B", "values", "__",
	[
		liftFn("el"), liftFn("bar"), liftFn("allFieldKeys"), liftVar("WEB_FILM_MIMES"), liftVar("WEB_FILM_EXTS"),
		liftFn("isWebFilm"), liftFn("youtubeId"), liftFn("filmHiddenMessage"), liftFn("refreshFilmBar"),
		"return refreshFilmBar;",
	].join("\n"),
)(document, B, item, (s) => s);
const filmBars = () => barsHost.children.filter((b) => b.classList.contains("film-hidden"));

/* A YouTube film shows: nothing to say. */
refreshFilmBar();
assert.equal(filmBars().length, 0);

/* Switched to an upload and the link cleared, with no cover: said, once. */
item.video_file = "https://admin.example/f/lobby.mp4";
item.video_youtube = "";
refreshFilmBar();
assert.deepEqual(filmBars().map(said), ["This film won't show on the site yet: add a Cover image, or a YouTube link."]);
assert.ok(filmBars()[0].classList.contains("warn"));

/* Left alone by an edit that changes nothing it says, so the live region
   does not read it out again. */
const first = filmBars()[0];
item.video_title = "Lobby, dusk";
refreshFilmBar();
assert.equal(filmBars().length, 1);
assert.equal(filmBars()[0], first, "an unchanged warning was put up again");

/* Something else missing: one bar, saying the new thing. */
item.video_file = "";
refreshFilmBar();
assert.deepEqual(filmBars().map(said), [
	"This film won't show on the site yet: add a YouTube link, or upload an MP4, WebM or Ogg film and add a Cover image.",
]);

/* A film and a cover: down the moment it would show. */
item.video_file = "https://admin.example/f/lobby.mp4";
item.video_poster = "https://admin.example/f/lobby.jpg";
refreshFilmBar();
assert.equal(filmBars().length, 0);

/* The keys come off the film field's own name, not a "video_" prefix... */
Object.assign(item, { clip_file: "https://admin.example/f/c.mp4", clip_youtube: "", clip_poster: "" });
B.schema = { sections: [{ key: "clip", fields: [{ key: "clip_file", kind: "video" }, { key: "clip_youtube", kind: "text" }, { key: "clip_poster", kind: "image" }] }] };
refreshFilmBar();
assert.equal(filmBars().length, 1, "a film field under another prefix was not checked");

/* ...and a page editor, which is not a film, never raises it. */
barsHost.children = [];
B.mode = "page";
refreshFilmBar();
assert.equal(filmBars().length, 0);

console.log("media controls: all assertions passed (film choose/refuse/unknown length/upload/drop; .m4v by name; .mov refused; size limit; server's reason kept, replaced, dismissed; image unchanged; hidden-film warning up, kept, replaced, down)");
