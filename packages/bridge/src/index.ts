/**
 * LivePress edit bridge — realtime visual editing for the headless frontend.
 *
 * When the site runs inside the WP admin preview iframe (or with `?edit=1`),
 * it listens for `aux-edit` postMessages from the admin and overlays field
 * values onto the page content **without any save or reload** — keystrokes in
 * the admin appear here live. Overrides live only in memory; the saved page
 * is untouched until the editor hits Update in WP.
 *
 * Message contract (admin → frontend):
 *   { type: "aux-edit", path: "hero.headline", value: "New text" }
 *   { type: "aux-edit-bulk", edits: [{ path, value }, ...] }
 *   { type: "aux-edit-reset" }
 *
 * `path` is a dot path into the page's content object. `value` is a string,
 * string[] (line lists) or object[] (repeaters).
 */
import { useSyncExternalStore } from "react";

type EditValue = string | string[] | Record<string, string>[];

/**
 * Shape of a path the bridge will accept.
 *
 * Underscores matter. LivePress used to send dot paths into a nested content
 * object; its schemas are now flat WordPress meta keys, with `path === key`
 * and no dots at all, and every one of those keys looks like `hero_title`.
 * This pattern excluded `_`, so a modern schema's edits failed the test and
 * were dropped — silently, one by one, with the preview simply never moving.
 *
 * Dots stay allowed: a frontend that still overlays onto a nested object is
 * a supported shape, and `setPath` below walks either.
 */
const PATH_RE = /^[a-zA-Z][a-zA-Z0-9._]{0,80}$/;

/**
 * Origins allowed to drive this page.
 *
 * The listener below used to accept a message from anybody, and `isEditMode()`
 * returns true for any cross-origin parent — so any site could iframe a page
 * using this bridge, or open it with `?edit=1` via window.open and keep the
 * handle, and rewrite whatever copy it liked. The framework escapes the
 * values, so this was never XSS; it was content spoofing with no tell.
 *
 * Defaults to the WordPress origin serving the page when it can be inferred,
 * and otherwise to nothing at all — an empty allowlist accepts no edits, which
 * is the right direction to fail. Call `configureEditOrigins()` before first
 * render to set it explicitly.
 */
let editOrigins = new Set<string>();

export function configureEditOrigins(origins: string | string[]) {
  const list = Array.isArray(origins) ? origins : String(origins).split(",");
  editOrigins = new Set(list.map((o) => o.trim().replace(/\/$/, "")).filter(Boolean));
}

/** True only for a message sent by an origin allowed to edit this page. */
export function isEditOrigin(origin: string): boolean {
  return editOrigins.has(origin);
}

/** Post upward without broadcasting to whoever happens to be embedding. */
function tellEditor(msg: Record<string, unknown>) {
  for (const origin of editOrigins) {
    try {
      window.parent?.postMessage(msg, origin);
    } catch {
      /* no parent, or it went away */
    }
  }
}

/**
 * Where to fetch a preview's values from.
 *
 * A path on your own origin, not the WordPress host: the route behind it
 * proxies `/wp-json/livepress/v1/preview/{token}`, which keeps the CMS
 * hostname out of the client bundle and the token out of a cross-origin
 * request. The default matches the convention in the README; override it if
 * your app routes differently.
 */
let previewPath = "/api/preview";

export function configurePreviewPath(path: string) {
  previewPath = String(path).replace(/\/$/, "");
}

/**
 * The preview token, taken out of the address bar as soon as it is read.
 *
 * A token in a query string is the classic secret-in-URL problem. The worst of
 * it — leaking to every third party the page loads, through the Referer header
 * — is closed by a `strict-origin-when-cross-origin` referrer policy, which
 * most frameworks set by default and which is worth confirming rather than
 * assuming.
 *
 * What no referrer policy touches is the recipient's browser history, the
 * host's own access logs, and the token sitting in the address bar of a screen
 * somebody may well be sharing — which is a plausible thing to be doing with a
 * page you are reviewing together.
 *
 * So the token moves to sessionStorage and the URL is rewritten without it.
 * sessionStorage rather than dropping it, because a reload would otherwise
 * fall back to the published page and somebody comparing a change against what
 * is live would have no idea which they were looking at.
 */
const PREVIEW_STORE = "lp-preview-token";

export function previewToken(): string | null {
  if (typeof window === "undefined") return null;
  try {
    const url = new URL(window.location.href);
    const fromUrl = url.searchParams.get("lp_preview");
    if (fromUrl) {
      try {
        window.sessionStorage.setItem(PREVIEW_STORE, fromUrl);
      } catch {
        /* Private mode. The preview still works for this page view; it just
           will not survive a reload, which is the lesser cost. */
      }
      url.searchParams.delete("lp_preview");
      window.history.replaceState(null, "", url.pathname + url.search + url.hash);
      return fromUrl;
    }
    return window.sessionStorage.getItem(PREVIEW_STORE);
  } catch {
    return null;
  }
}

/**
 * Is a preview active? Asks without changing anything.
 *
 * `previewToken()` moves the token into sessionStorage and rewrites the URL,
 * which is the right thing to do once and the wrong thing to do from a render.
 * Anything that only needs to KNOW uses this — suppressing a cookie banner
 * inside the editor pane, say.
 */
export function isPreviewMode(): boolean {
  if (typeof window === "undefined") return false;
  try {
    if (new URLSearchParams(window.location.search).has("lp_preview")) return true;
    return !!window.sessionStorage.getItem(PREVIEW_STORE);
  } catch {
    return false;
  }
}

let previewLoaded = false;

function startPreview(token: string) {
  if (previewLoaded) return;
  previewLoaded = true;

  /* A preview shows content that is deliberately not published yet. Telling
     crawlers not to keep it is cheap; discovering later that a scheduled
     announcement was indexed a week early is not. */
  if (typeof document !== "undefined") {
    const meta = document.createElement("meta");
    meta.name = "robots";
    meta.content = "noindex, nofollow";
    document.head.appendChild(meta);
  }

  fetch(`${previewPath}/${encodeURIComponent(token)}`, { cache: "no-store" })
    .then((r) => (r.ok ? r.json() : null))
    .then((data: { values?: Record<string, EditValue> } | null) => {
      if (!data?.values) return;
      const next = { ...overrides };
      for (const [path, value] of Object.entries(data.values)) {
        if (PATH_RE.test(path)) next[path] = value;
      }
      overrides = next;
      emit();
    })
    .catch(() => {
      /* A dead or cancelled link simply shows the published page, which is the
         honest thing for it to do. */
    });
}

let overrides: Record<string, EditValue> = {};
let version = 0;
const listeners = new Set<() => void>();

function emit() {
  version++;
  listeners.forEach((l) => l());
}

/** Editing is active only inside an iframe or with ?edit=1 — zero cost otherwise. */
export function isEditMode(): boolean {
  if (typeof window === "undefined") return false;
  try {
    return (
      window.self !== window.top ||
      new URLSearchParams(window.location.search).has("edit")
    );
  } catch {
    return true; // cross-origin parent → we are embedded
  }
}

let started = false;
function startListener() {
  if (started || typeof window === "undefined") return;
  started = true;
  window.addEventListener("message", (e: MessageEvent) => {
    if (!isEditOrigin(e.origin)) return;
    const data = e.data as
      | { type?: string; path?: string; value?: EditValue; edits?: { path: string; value: EditValue }[] }
      | null;
    if (!data || typeof data !== "object") return;
    if (data.type === "aux-edit" && typeof data.path === "string" && PATH_RE.test(data.path)) {
      overrides = { ...overrides, [data.path]: data.value as EditValue };
      emit();
    } else if (data.type === "aux-edit-bulk" && Array.isArray(data.edits)) {
      const next = { ...overrides };
      for (const ed of data.edits) {
        if (ed && typeof ed.path === "string" && PATH_RE.test(ed.path)) next[ed.path] = ed.value;
      }
      overrides = next;
      emit();
    } else if (data.type === "aux-edit-reset") {
      overrides = {};
      emit();
    }
  });
  // Tell the parent admin we are ready to receive live edits.
  try {
    tellEditor({ type: "aux-edit-ready" });
  } catch {
    /* no parent — fine */
  }

  // Click-to-edit: clicking inside a [data-lp] section focuses its panel in
  // the editor. Link navigation is suppressed so the preview stays put.
  document.addEventListener(
    "click",
    (e) => {
      const target = e.target as HTMLElement | null;
      const anchor = target?.closest("a");
      if (anchor) e.preventDefault();
      const zone = target?.closest("[data-lp]");
      const key = zone?.getAttribute("data-lp");
      if (!key) return;
      try {
        tellEditor({ type: "aux-focus", section: key });
      } catch {
        /* no parent */
      }
    },
    true,
  );
}

/** Immutable deep-set of a dot path. Arrays are copied; unknown segments create objects. */
function setPath<T>(obj: T, path: string, value: EditValue): T {
  const keys = path.split(".");
  const root: Record<string, unknown> = Array.isArray(obj)
    ? ([...(obj as unknown[])] as unknown as Record<string, unknown>)
    : { ...(obj as Record<string, unknown>) };
  let cur: Record<string, unknown> = root;
  for (let i = 0; i < keys.length - 1; i++) {
    const k = keys[i];
    const nxt = cur[k];
    cur[k] =
      Array.isArray(nxt) ? [...nxt] : nxt && typeof nxt === "object" ? { ...nxt } : {};
    cur = cur[k] as Record<string, unknown>;
  }
  cur[keys[keys.length - 1]] = value;
  return root as T;
}

/**
 * Overlay live edits onto loader content. Outside edit mode this returns
 * `base` untouched (and subscribes to nothing meaningful).
 */
export function useLiveEdits<T>(base: T): T {
  const v = useSyncExternalStore(
    (cb) => {
      if (isEditMode()) startListener();
      const token = previewToken();
      if (token) startPreview(token);
      listeners.add(cb);
      return () => listeners.delete(cb);
    },
    () => version,
    () => 0,
  );
  void v;
  if (!isEditMode() && !previewLoaded) return base;
  let out = base;
  for (const [path, value] of Object.entries(overrides)) {
    out = setPath(out, path, value);
  }
  return out;
}
