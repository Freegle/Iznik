#!/usr/bin/env bash
#
# check-set-order.sh - has a conversion reordered an UPDATE whose SET order
# carries meaning?
#
# MySQL evaluates UPDATE assignments left to right, so
#
#   UPDATE t SET action = action + 1, rate = 100 * action / shown
#
# computes rate from the INCREMENTED action, while the reverse order computes it
# from the old one. GORM sorts map keys when it builds
# Updates(map[string]interface{}{...}) (callbacks/update.go:205-208), so a
# conversion emits the assignments in alphabetical order regardless of how the
# original was written.
#
# Two distinct things follow, and the first version of this check conflated
# them:
#
#   1. The order carries meaning (one assignment's value names another
#      assigned column). That on its own is not a defect.
#   2. GORM's alphabetical order DIFFERS from the order the original statement
#      used. That is the defect, and only that.
#
# Freegle's one order-dependent site, engage_mails in user.go, is case 1 but not
# case 2: "action" sorts before "rate", which is the order the original SQL
# already used, so the conversion is correct. A check that failed on case 1
# alone would block a correct tree - which is exactly what the first version
# did, and it is why this now compares against the recorded goldenSql rather
# than flagging on shape.
#
# The golden-SQL harness cannot do this itself: normaliseColumnOrder sorts SET
# lists on both sides so that harmless reordering passes, which means it would
# accept harmful reordering just as readily. It now declines to normalise these
# statements, and this gate covers the case where a site has no golden to pin.
#
# Test files are skipped: a parity test mirrors its production chain, so the
# production site is where the question is decided, and flagging both just
# doubles the noise.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ROOT="${1:-$REPO_ROOT/iznik-server-go}"
MANIFEST="${RATCHET_MANIFEST:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"

node -e '
const fs = require("fs"), path = require("path");
const [root, manifestPath] = process.argv.slice(1);

let manifest = {sites: {}};
try { manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8")); } catch {}

const walk = (d) => fs.readdirSync(d, {withFileTypes: true}).flatMap((e) => {
  const p = path.join(d, e.name);
  if (e.isDirectory()) return e.name === "vendor" ? [] : walk(p);
  return e.name.endsWith(".go") && !e.name.endsWith("_test.go") ? [p] : [];
});

// Column order of the SET list in a raw UPDATE, e.g.
// "UPDATE t SET a = a + 1, rate = ... WHERE id = ?" -> ["a", "rate"]
const goldenSetOrder = (sql, kind) => {
  // An UPDATE keeps its assignments after SET; an upsert keeps them after
  // ON DUPLICATE KEY UPDATE. Both are ordered lists that MySQL evaluates left
  // to right, and GORM sorts the keys of both Updates(map) and
  // clause.Assignments(map), so the same question applies to each.
  const m = kind === "assignments"
    ? /\bon\s+duplicate\s+key\s+update\b([\s\S]*)$/i.exec(sql || "")
    : /\bset\b([\s\S]*?)(?:\bwhere\b|$)/i.exec(sql || "");
  if (!m) return null;
  const parts = [];
  let depth = 0, cur = "", inQuote = false;
  for (const ch of m[1]) {
    if (ch === "'"'"'") inQuote = !inQuote;
    if (!inQuote) {
      if (ch === "(") depth++;
      else if (ch === ")") depth--;
      else if (ch === "," && depth === 0) { parts.push(cur); cur = ""; continue; }
    }
    cur += ch;
  }
  parts.push(cur);
  return parts.map((p) => {
    const eq = p.indexOf("=");
    return eq < 0 ? "" : p.slice(0, eq).trim().replace(/`/g, "").split(".").pop().toLowerCase();
  }).filter(Boolean);
};

const stripStrings = (s) => {
  let out = "", inQuote = false;
  for (let i = 0; i < s.length; i++) {
    if (s[i] === "\\" && inQuote) { i++; continue; }
    if (s[i] === "'"'"'") { inQuote = !inQuote; continue; }
    if (!inQuote) out += s[i];
  }
  return out;
};

let flagged = 0, checked = 0, benign = 0;
for (const file of walk(root)) {
  const src = fs.readFileSync(file, "utf8");
  // Both map-valued forms, not just Updates. clause.Assignments(map...) sorts
  // its keys exactly as Updates(map...) does, and the wave 3 ON DUPLICATE KEY
  // UPDATE sites are where it appears - so scanning only Updates left the
  // upsert half of the codebase unguarded. abtest.go is the live case: rate is
  // computed from a column the same statement assigns, and the alphabetical
  // order really does differ from the original. It was caught by hand during a
  // conversion batch, which is precisely the kind of catch that should not
  // depend on someone remembering.
  //
  // An explicit ordered clause.Set literal is the FIX for these, not a case to
  // flag: it states the order rather than deriving it from a map, so there is
  // nothing for a sort to reorder.
  const re = /(?:Updates|clause\.Assignments)\(\s*map\[string\]interface\{\}\{/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    let depth = 1, i = m.index + m[0].length;
    while (i < src.length && depth > 0) {
      if (src[i] === "{") depth++;
      else if (src[i] === "}") depth--;
      i++;
    }
    const body = src.slice(m.index + m[0].length, i - 1);
    const kind = m[0].startsWith("clause.Assignments") ? "assignments" : "updates";
    checked++;

    const keys = [...body.matchAll(/"([A-Za-z_][A-Za-z0-9_]*)"\s*:/g)].map((k) => k[1]);
    if (keys.length < 2) continue;

    // Does any entry read another entry'"'"'s column?
    let crossRef = false;
    for (const entry of body.split(/,\s*\n|,(?=\s*")/)) {
      const km = entry.match(/"([A-Za-z_][A-Za-z0-9_]*)"\s*:\s*([\s\S]*)/);
      if (!km) continue;
      const [, key, value] = km;
      if (!/gorm\.Expr|clause\./.test(value)) continue;

      // Scan the SQL only - the Go string literal inside gorm.Expr - and not
      // the bind arguments that follow it. Those are Go identifiers, and a Go
      // variable that happens to share a column name is not a reference to
      // that column. spammers.go PatchSpammer is the case in point:
      //
      //   "heldat": gorm.Expr("CASE WHEN ? IS NOT NULL THEN NOW() ELSE NULL END", heldby)
      //
      // The "?" is bound from a Go variable called heldby; the SQL names no
      // column at all. Scanning the whole expression read that as heldat
      // depending on the heldby column, and a convertible site was left raw on
      // the strength of it.
      const sqlLiterals = [...value.matchAll(/"((?:[^"\\]|\\.)*)"/g)].map((x) => x[1]).join(" ");
      const stripped = stripStrings(sqlLiterals);
      for (const other of keys) {
        if (other !== key && new RegExp("\\b" + other + "\\b").test(stripped)) crossRef = true;
      }
    }
    if (!crossRef) continue;

    const line = src.slice(0, m.index).split("\n").length;
    const rel = path.relative(path.dirname(root), file);

    // Order matters here. Does GORM'"'"'s alphabetical order match what the
    // original statement did? Find the site id from the conversion marker
    // above, and compare against its recorded goldenSql.
    const above = src.slice(Math.max(0, m.index - 600), m.index);
    const idm = [...above.matchAll(/ORM migration site ([0-9a-f]{12})/g)].pop();
    if (!idm) {
      console.log(`SET ORDER  ${rel}:${line}  order-dependent SET list with no ORM migration site marker, so it cannot be checked against a golden`);
      flagged++;
      continue;
    }
    const site = manifest.sites[idm[1]];
    const want = site ? goldenSetOrder(site.goldenSql, kind) : null;
    if (!want) {
      console.log(`SET ORDER  ${rel}:${line}  site ${idm[1]} has no usable goldenSql to check the SET order against`);
      flagged++;
      continue;
    }
    const got = [...keys].map((k) => k.toLowerCase()).sort();
    const wantFiltered = want.filter((c) => got.includes(c));
    if (JSON.stringify(wantFiltered) !== JSON.stringify(got)) {
      console.log(`SET ORDER  ${rel}:${line}  site ${idm[1]}: GORM sorts to [${got.join(", ")}] but the original SET order was [${want.join(", ")}], and the order changes what is written`);
      flagged++;
    } else {
      benign++;
    }
  }
}

console.log(`check-set-order: ${checked} map-valued assignment sites checked (Updates and clause.Assignments), ${benign} order-dependent but unchanged by sorting, ${flagged} flagged`);
process.exit(flagged ? 1 : 0);
' "$ROOT" "$MANIFEST"
