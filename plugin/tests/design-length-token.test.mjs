/**
 * The length token's control, checked without WordPress or a browser.
 *
 * A colour token is forgiving: a wrong hex is visible the moment you look at
 * the swatch. A length is not. The failures that matter here are all silent —
 * storing a value equal to the brand default, so "Reset" pins the token to
 * whatever the stylesheet happens to say today rather than handing it back;
 * treating 0 as unset, so the one setting that squares the whole site off is
 * the one you cannot make; or letting a typed number past the declared max,
 * so the frontend renders a radius the design never allowed for.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const src = readFileSync(new URL("../assets/editor.js", import.meta.url), "utf8");

/* Both functions are lifted out of the shipped file — a retyped copy would
   only prove the copy is consistent with itself. */
const el = new Function(
  `${/\tfunction el\( tag, attrs, children \) \{[\s\S]*?\n\t\}/.exec(src)[0]}; return el;`,
)();
const lengthRowSrc = /\t\tfunction lengthRow\( t \) \{[\s\S]*?\n\t\t\}\n/.exec(src)[0];

/* The smallest DOM this control touches. */
function node(tag) {
  return {
    tag,
    value: "",
    textContent: "",
    className: "",
    children: [],
    listeners: {},
    classList: { toggle() {} },
    setAttribute() {},
    addEventListener(type, fn) {
      (this.listeners[type] ||= []).push(fn);
    },
    appendChild(c) {
      this.children.push(c);
      return c;
    },
    fire(type) {
      (this.listeners[type] || []).forEach((fn) => fn());
    },
  };
}
globalThis.document = { createElement: node };

const TOKEN = {
  key: "radius",
  kind: "length",
  label: "Corner radius",
  hint: "How round every card is.",
  fallback: 12,
  min: 0,
  max: 24,
  step: 1,
  unit: "px",
};

/** A fresh control over the given saved state. */
function control(saved) {
  const d = saved;
  const rows = [];
  let pushed = 0;
  const lengthRow = new Function(
    "el",
    "d",
    "rows",
    "push",
    "noteEdit",
    "pushUndo",
    "endEdit",
    "__",
    `${lengthRowSrc}; return lengthRow;`,
  )(
    el,
    d,
    rows,
    () => pushed++,
    () => {},
    () => {},
    () => {},
    (s) => s,
  );
  const row = lengthRow(TOKEN);
  const [range, num, unit, reset] = row.children[1].children;
  return { d, rows, range, num, unit, reset, pushes: () => pushed };
}

const type = (c, v) => {
  c.num.value = String(v);
  c.num.fire("input");
};
const drag = (c, v) => {
  c.range.value = String(v);
  c.range.fire("input");
};

/* Nothing saved means the stylesheet is in charge, and the control still has
   to show the declared default rather than an empty box. */
{
  const c = control({});
  assert.equal(Number(c.range.value), 12);
  assert.equal(Number(c.num.value), 12);
  assert.equal(c.d.radius, undefined);
  assert.equal(c.unit.textContent, "px");
}

/* The two inputs are one value. Dragging has to move the number, or the
   readout lies about what will be saved. */
{
  const c = control({});
  drag(c, 4);
  assert.equal(c.d.radius, "4");
  assert.equal(Number(c.num.value), 4);
}

/* 0 is a setting. A falsy-looking value must survive the round trip. */
{
  const c = control({});
  type(c, 0);
  assert.equal(c.d.radius, "0");
}

/* Back at the default is not an override: the key goes away so the token
   falls through to the stylesheet. */
{
  const c = control({ radius: "4" });
  type(c, 12);
  assert.equal(c.d.radius, undefined);
}

/* Out of range clamps to what PHP declared, both ends. */
{
  const c = control({});
  type(c, 999);
  assert.equal(c.d.radius, "24");
  type(c, -40);
  assert.equal(c.d.radius, "0");
}

/* An empty box is mid-typing, not a zero — clearing it to type another
   number must not write. */
{
  const c = control({ radius: "8" });
  c.num.value = "";
  c.num.fire("input");
  assert.equal(c.d.radius, "8");
}

/* Leaving the field shows what is actually stored, however it was typed. */
{
  const c = control({});
  type(c, 999);
  assert.equal(c.num.value, "999");
  c.num.fire("blur");
  assert.equal(Number(c.num.value), 24);
}

/* Reset hands the token back rather than storing the default. */
{
  const c = control({ radius: "20" });
  c.reset.fire("click");
  assert.equal(c.d.radius, undefined);
  assert.equal(Number(c.num.value), 12);
}

/* Registered with the panel, so "Reset all to brand" reaches it too. */
{
  const c = control({ radius: "20" });
  assert.equal(c.rows.length, 1);
  c.rows[0].paint(c.rows[0].def.fallback, true);
  assert.equal(c.d.radius, undefined);
}

/* A saved value is picked up on the next render, not just the first. */
{
  const c = control({ radius: "6" });
  assert.equal(Number(c.range.value), 6);
  assert.equal(Number(c.num.value), 6);
}

/* Every edit streams to the preview, or the live half of "live editing"
   quietly stops applying to this control. */
{
  const c = control({});
  drag(c, 3);
  type(c, 7);
  assert.ok(c.pushes() >= 2);
}

console.log("design length token: all assertions passed (clamp, 0, unset-on-default, reset, live push)");
