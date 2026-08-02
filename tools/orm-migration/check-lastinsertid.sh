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
    for (const [id, s] of Object.entries(m.sites)) {
      if (s.status === "test-fixture") continue;
      if (!/^\s*(insert|replace)/i.test(s.goldenSql || "")) continue;
      let lines;
      try { lines = fs.readFileSync(path.join(work, s.file), "utf8").split("\n"); } catch { continue; }
      const w = lines.slice(s.line - 1, s.line + Number(lookahead)).join("\n");
      if (/LastInsertId|LAST_INSERT_ID/i.test(w)) {
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
