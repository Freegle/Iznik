#!/usr/bin/env bash
#
# check-set-order.sh - does any converted UPDATE have a SET clause whose order
# is load-bearing?
#
# MySQL evaluates UPDATE assignments left to right, so
#
#   UPDATE t SET a = b, b = 1     -- a gets the OLD b
#   UPDATE t SET b = 1, a = b     -- a gets 1
#
# are different statements. GORM sorts map keys alphabetically when it builds
# Updates(map[string]interface{}{...}), so a conversion silently reorders the
# SET list.
#
# For almost every statement here that is harmless: the values are binds and
# constants that do not reference each other. But where one assigned value
# mentions another assigned column, the reorder changes what gets written.
#
# The reason this needs its own check is that the golden-SQL harness CANNOT
# catch it. normaliseColumnOrder deliberately sorts SET lists on both sides so
# that a reordered-but-equivalent statement passes - which means it would also
# wave through a reordered-and-NOT-equivalent one. A normalisation that hides a
# class of bug has to be paired with a check for that class.
#
# Flags any Updates(map...) literal where a value expression names one of the
# other keys in the same map. Read the flagged site and either keep it raw or
# split it into two statements.
set -uo pipefail

ROOT="${1:-/home/edward/FreegleDocker-orm-v2/iznik-server-go}"

node -e '
const fs = require("fs"), path = require("path");
const root = process.argv[1];
let flagged = 0, checked = 0;

const walk = (d) => fs.readdirSync(d, {withFileTypes: true}).flatMap((e) => {
  const p = path.join(d, e.name);
  if (e.isDirectory()) return e.name === "vendor" ? [] : walk(p);
  return e.name.endsWith(".go") ? [p] : [];
});

for (const file of walk(root)) {
  const src = fs.readFileSync(file, "utf8");
  // Find each Updates(map[string]interface{}{ ... }) and take its braces span.
  const re = /Updates\(\s*map\[string\]interface\{\}\{/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    let depth = 1, i = m.index + m[0].length;
    while (i < src.length && depth > 0) {
      if (src[i] === "{") depth++;
      else if (src[i] === "}") depth--;
      i++;
    }
    const body = src.slice(m.index + m[0].length, i - 1);
    checked++;

    // Keys are the quoted strings in key position: "col": value
    const keys = [...body.matchAll(/"([A-Za-z_][A-Za-z0-9_]*)"\s*:/g)].map((k) => k[1]);
    if (keys.length < 2) continue;

    // For each entry, does its VALUE mention another key as a bare word?
    // Only gorm.Expr / clause values can contain SQL that names a column; a
    // plain Go variable is a bind and cannot.
    for (const entry of body.split(/,\s*\n|,(?=\s*")/)) {
      const km = entry.match(/"([A-Za-z_][A-Za-z0-9_]*)"\s*:\s*([\s\S]*)/);
      if (!km) continue;
      const [, key, value] = km;
      if (!/gorm\.Expr|clause\./.test(value)) continue;
      for (const other of keys) {
        if (other === key) continue;
        // Bare column reference, not inside a quoted SQL string literal.
        const stripped = value.replace(/'"'"'[^'"'"']*'"'"'/g, "");
        if (new RegExp("\\b" + other + "\\b").test(stripped)) {
          const line = src.slice(0, m.index).split("\n").length;
          console.log(`SET ORDER  ${path.relative(root, file)}:${line}  "${key}" is set from an expression naming "${other}", which is also set here`);
          flagged++;
        }
      }
    }
  }
}

console.log(`check-set-order: ${checked} Updates(map) sites checked, ${flagged} flagged`);
process.exit(flagged ? 1 : 0);
' "$ROOT"
