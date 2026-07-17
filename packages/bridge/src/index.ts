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

const PATH_RE = /^[a-zA-Z][a-zA-Z0-9.]{0,80}$/;

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
    window.parent?.postMessage({ type: "aux-edit-ready" }, "*");
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
        window.parent?.postMessage({ type: "aux-focus", section: key }, "*");
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
      listeners.add(cb);
      return () => listeners.delete(cb);
    },
    () => version,
    () => 0,
  );
  void v;
  if (!isEditMode()) return base;
  let out = base;
  for (const [path, value] of Object.entries(overrides)) {
    out = setPath(out, path, value);
  }
  return out;
}
