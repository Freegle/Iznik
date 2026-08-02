#!/usr/bin/env bash
#
# check-lastinsertid.sh - has an INSERT whose generated id is read back been
# converted?
#
# An INSERT followed by a LastInsertId read has to stay raw. LAST_INSERT_ID() is
# connection-scoped session state, so the read only returns the right value when
# it happens on the same connection as the write. Converting the INSERT to
# GORM's map-Create moves the id onto GORM's own "@id" writeback path, which is
# undocumented and untested upstream, and hands the connection choice to the
# dbresolver plugin. The failure mode is not an error: it is a row created with
# the wrong parent id, found days later.
#
# This is written down in the conversion rules and agents are told about it.
# That is not enforcement. This gate refuses the build instead, so the rule
# survives someone not having read the rules.
#
# The list cannot be derived from the manifest after the fact. Converting a site
# removes its raw SQL, so re-extraction stops finding it and presentInCode goes
# false - by design (that is what gate (f) checks). The question "did this
# INSERT read its id back?" can only be answered against a tree where the raw
# SQL is still there. So the list is generated once from the merge-base with
# master and committed as lastinsertid-sites.json; this script then asserts that
# none of those ids has status "converted".
#
# Regenerate after rebasing onto a master that added INSERT sites:
#   tools/orm-migration/check-lastinsertid.sh --regenerate
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LIST="$SCRIPT_DIR/lastinsertid-sites.json"
MANIFEST="${RATCHET_MANIFEST:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"
LOOKAHEAD="${LOOKAHEAD:-12}"

if [ "${1:-}" = "--regenerate" ]; then
  MB="$(cd "$REPO_ROOT" && git merge-base HEAD origin/master)"
  WORK="$(mktemp -d)"
  trap 'rm -rf "$WORK"' EXIT
  (cd "$REPO_ROOT" && git archive "$MB" | tar -x -C "$WORK")
  (cd "$SCRIPT_DIR" && go run . -root "$WORK/iznik-server-go" -repo "$WORK" -out "$WORK/base.json" >/dev/null 2>&1)
  node -e '
    const fs = require("fs"), path = require("path");
    const [work, listPath, lookahead, mb] = process.argv.slice(1);
    const m = JSON.parse(fs.readFileSync(work + "/base.json", "utf8"));
    const out = {};

    // Link THIS Exec to the LastInsertId read, rather than guessing by
    // proximity. Two earlier attempts got this wrong in opposite directions:
    //
    //   - a 12-line lookahead missed comment.go PostComment, where the read is
    //     13 lines below the write, separated only by error handling and a var
    //     declaration. How far apart they sit is a property of the surrounding
    //     code, not of the risk.
    //   - widening to the enclosing function then flagged the
    //     noticeboards_checks INSERTs, which are correctly converted: the
    //     LastInsertId in that function belongs to an entirely different Exec.
    //
    // What actually matters is whether the result of this statement is the one
    // whose id is read. An Exec whose result is assigned to a variable that is
    // later asked for LastInsertId is unsafe; an Exec whose result is discarded
    // cannot have its id read at all, however many other statements nearby do.
    const enclosingFunc = (lines, line) => {
      let start = 0;
      for (let i = line - 1; i >= 0; i--) {
        if (/^func\b/.test(lines[i] || "")) { start = i; break; }
      }
      let end = lines.length;
      for (let i = line; i < lines.length; i++) {
        if (/^func\b/.test(lines[i] || "")) { end = i; break; }
      }
      return {start, end};
    };

    for (const [id, s] of Object.entries(m.sites)) {
      if (s.status === "test-fixture") continue;
      if (!/^\s*(insert|replace)/i.test(s.goldenSql || "")) continue;
      let lines;
      try { lines = fs.readFileSync(path.join(work, s.file), "utf8").split("\n"); } catch { continue; }

      // MySQL LAST_INSERT_ID() in the statement text is unsafe on its own.
      if (/LAST_INSERT_ID/i.test(s.goldenSql || "")) {
        out[id] = {file: s.file, line: s.line, sql: (s.goldenSql || "").slice(0, 90)};
        continue;
      }

      // Find the result variable this Exec assigns to. The call may be split
      // over lines, so look at the site line and the two above it.
      const head = lines.slice(Math.max(0, s.line - 3), s.line).join("\n");
      const assign = head.match(/(\w+)\s*,\s*\w+\s*:?=\s*[\w.]*Exec\(/);
      if (!assign) continue; // result discarded: its id cannot be read
      const resultVar = assign[1];
      if (resultVar === "_") continue;

      const {end} = enclosingFunc(lines, s.line);
      const after = lines.slice(s.line - 1, end).join("\n");
      if (new RegExp("\\b" + resultVar + "\\s*\\.\\s*LastInsertId\\s*\\(").test(after)) {
        out[id] = {file: s.file, line: s.line, sql: (s.goldenSql || "").slice(0, 90)};
      }
    }
    fs.writeFileSync(listPath, JSON.stringify({
      _comment: "Site ids whose INSERT has its generated id read back on the same connection. Generated from " + mb + " by check-lastinsertid.sh --regenerate. These must never be converted: LAST_INSERT_ID() is connection-scoped session state.",
      sites: out,
    }, null, 2) + "\n");
    console.log(`check-lastinsertid: regenerated ${listPath} with ${Object.keys(out).length} sites from ${mb}`);
  ' "$WORK" "$LIST" "$LOOKAHEAD" "$MB"
  exit 0
fi

if [ ! -f "$LIST" ]; then
  echo "check-lastinsertid: FAIL: $LIST is missing; run with --regenerate" >&2
  exit 1
fi

node -e '
const fs = require("fs");
const [listPath, manifestPath] = process.argv.slice(1);
const list = JSON.parse(fs.readFileSync(listPath, "utf8")).sites;
const m = JSON.parse(fs.readFileSync(manifestPath, "utf8"));

let flagged = 0, missing = 0;
for (const [id, info] of Object.entries(list)) {
  const site = m.sites[id];
  if (!site) { missing++; continue; }
  if (site.status === "converted" || site.status === "in-progress") {
    console.log(`LASTINSERTID  ${id}  ${info.file}:${info.line}  status=${site.status}`);
    console.log(`              ${info.sql}`);
    flagged++;
  }
}
console.log(`check-lastinsertid: ${Object.keys(list).length} id-reading INSERT sites, ${flagged} wrongly converted, ${missing} no longer in the manifest`);
process.exit(flagged ? 1 : 0);
' "$LIST" "$MANIFEST"
