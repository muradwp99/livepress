/**
 * Run every check in this directory.
 *
 * It exists because the undo test sat broken for a commit: adding sprintf to a
 * message meant the harness's stub list no longer covered what the code
 * called, and I pushed after running only the linters. One command that runs
 * the lot is the cheap fix for that — a suite you have to remember to run in
 * pieces is a suite that drifts.
 */
import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));
const files = readdirSync(here).filter((f) => f.includes(".test."));
let failed = 0;

for (const f of files.sort()) {
  const [cmd, args] = f.endsWith(".php") ? ["php", [join(here, f)]] : ["node", [join(here, f)]];
  try {
    process.stdout.write(execFileSync(cmd, args, { encoding: "utf8" }));
  } catch (e) {
    console.error(`FAILED ${f}\n${e.stdout || ""}${e.stderr || ""}`);
    failed++;
  }
}
console.log(failed ? `\n${failed} of ${files.length} failed` : `\n${files.length} checks passed`);
process.exit(failed ? 1 : 0);
