#!/usr/bin/env bash
#
# check-buildclauses.sh - is a whole raw statement being smuggled through
# .Select()?
#
# GORM's query callback always registers a FROM clause, but Statement.Build only
# renders the clause NAMES it is handed. Setting
#
#     tx.Statement.BuildClauses = []string{"SELECT"}
#
# therefore emits the SELECT clause alone. That has one legitimate use and one
# abuse, and they look almost identical in a diff.
#
# LEGITIMATE - the argument is a scalar EXPRESSION and GORM still builds the
# statement (a one-clause statement, but it builds it):
#
#     db.Table("users").Select("EXISTS(SELECT 1 FROM users WHERE id = ?)", id)
#
# This is how a bare "SELECT EXISTS(...)" with no top-level FROM gets rendered,
# which GORM otherwise cannot express - it would add a FROM. It renders
# byte-identically to the original. See ormharness/bareexists_test.go.
#
# ABUSE - the argument is the WHOLE STATEMENT, supplying its own FROM, and
# .Table() is a decoy that the override suppresses:
#
#     db.Table("chat_rooms").Select("id FROM chat_rooms WHERE id = ? UNION SELECT ...")
#
# Nothing was converted here. The SQL text is unchanged; it moved from db.Raw()
# into .Select(), and the inventory extractor scans Raw/Exec/Query but not
# Select - so the site silently stopped being counted as raw SQL and started
# being counted as converted work. That is the migration measuring its own
# progress by how well the code hides from the measuring tool.
#
# The distinguishing test is whether the Select argument supplies a TOP-LEVEL
# FROM (a FROM outside any parentheses - one inside a scalar subquery is fine).
#
# A first version scanned only string literals for a top-level FROM and passed
# both getReviewQueue sites, whose statement is assembled into Go VARIABLES
# (baseQuery, widerQuery). The fix is not to distrust every variable - that
# buries the real cases in noise - but to judge the literal fragments that sit
# beside them, which still read "* FROM (" and "GROUP BY id ORDER BY".
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
SOURCE_ROOT="${1:-$REPO_ROOT/iznik-server-go}"
ALLOW="$SCRIPT_DIR/buildclauses-allow.json"

MANIFEST="${RATCHET_MANIFEST:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"

SOURCE_ROOT="$SOURCE_ROOT" ALLOW="$ALLOW" MANIFEST="$MANIFEST" node -e '
const fs = require("fs"), path = require("path"), cp = require("child_process");
const root = process.env.SOURCE_ROOT;
let allow = {};
try { allow = JSON.parse(fs.readFileSync(process.env.ALLOW, "utf8")).allowed || {}; } catch {}

// Sites already RECORDED as keep-raw are declared debt, not new smuggling. The
// gate exists to catch the pattern at the point someone writes it, so that it
// never again silently converts a site by hiding it from the extractor; once a
// site carries a written keep-raw reason it is being counted honestly and this
// check has done its job. Without this the gate would fail forever on the five
// sites that prompted it, and a gate that cannot go green gets switched off.
let declared = new Map();
try {
  const m = JSON.parse(fs.readFileSync(process.env.MANIFEST, "utf8"));
  for (const s of Object.values(m.sites)) {
    if (s.status === "keep-raw" && s.presentInCode) {
      declared.set(s.file.replace(/^iznik-server-go\//, "") + ":" + s.line, true);
    }
  }
} catch {}

const files = cp.execSync(
  "grep -rl \"Statement.BuildClauses\" " + root + " --include=*.go || true"
).toString().trim().split("\n").filter(Boolean).filter((f) => !f.endsWith("_test.go"));

// Remove balanced parenthesised spans so only top-level keywords survive.
const stripParens = (s) => {
  let out = "", d = 0;
  for (const c of s) {
    if (c === "(") { d++; continue; }
    if (c === ")") { if (d) d--; continue; }
    if (!d) out += c;
  }
  return out;
};

let flagged = 0, ok = 0;
for (const file of files) {
  const lines = fs.readFileSync(file, "utf8").split("\n");
  lines.forEach((ln, i) => {
    if (!/Statement\.BuildClauses/.test(ln)) return;

    // Walk back to the .Select( this override belongs to, and take the
    // argument text up to the line before the override.
    let start = i;
    for (let j = i; j >= 0 && j > i - 40; j--) {
      if (/\.Select\(/.test(lines[j])) { start = j; break; }
    }
    const frag = lines.slice(start, i).join("\n");
    const arg = frag.slice(frag.indexOf(".Select(") + 8);

    const rel = path.relative(root, file);
    const id = (frag.match(/ORM migration sites? ([0-9a-f]{12})/) || [])[1] || "";
    const where = rel + ":" + (i + 1) + (id ? "  [" + id + "]" : "");

    if (allow[id]) { ok++; return; }

    // Match on the .Select() line, which is what the manifest records - the
    // override can sit any distance below it (modconfig has 15 lines of
    // statement between them), so a fixed window guesses wrong.
    if (declared.has(rel + ":" + (start + 1))) { ok++; return; }

    // Judge whatever text IS visible. Where the argument is concatenated from
    // Go variables the literals around them still carry the giveaway - both
    // getReviewQueue sites splice baseQuery/widerQuery in, but the literal
    // fragments beside them still read "* FROM (" and "GROUP BY id ORDER BY".
    //
    // An earlier version instead flagged anything containing a variable as
    // suspect-until-justified. That was too blunt: Select(selectCols) and
    // Select("COUNT(*) AS reach_rows, COALESCE(MAX(...), 0) AS in_reach") are
    // ordinary projections, and calling them smuggling would have buried the
    // real cases in noise the next reader learns to skim past.
    const literals = arg.match(/"(?:[^"\\]|\\.)*"/g) || [];
    const top = stripParens(literals.map((s) => s.slice(1, -1)).join(" "));
    const suppliesFrom = /\bFROM\b/i.test(top);
    const suppliesTail = /\b(UNION|GROUP BY|ORDER BY|HAVING)\b/i.test(top);

    if (suppliesFrom || suppliesTail) {
      console.log("BUILDCLAUSES  " + where);
      console.log("              the Select argument supplies its own top-level FROM/UNION/GROUP BY, so the");
      console.log("              whole statement is the argument and Table() is a decoy - relocation, not conversion.");
      flagged++;
    } else {
      ok++;
    }
  });
}

console.log("check-buildclauses: " + (flagged + ok) + " BuildClauses override(s), " + flagged +
  " smuggling a statement, " + ok + " scalar-expression uses");
if (flagged) {
  console.log("fix: the site is not converted - mark it keep-raw with a reason, or add it to");
  console.log("     buildclauses-allow.json with a justification if the argument really is a scalar expression");
  process.exit(1);
}
'
