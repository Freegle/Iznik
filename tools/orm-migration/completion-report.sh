#!/usr/bin/env bash
#
# completion-report.sh - plan 7.4's completion report.
#
# "A one-page completion report lists every keep-raw site and its reason. There
# is no residual category for anything to hide in."
#
# Generated from the manifest rather than written by hand, so it cannot drift
# from what the code actually contains. Writing it by hand would reintroduce
# exactly the human bookkeeping the whole process exists to remove.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MANIFEST="${1:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"

node -e '
const fs = require("fs");
const m = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
const sites = Object.entries(m.sites);

const by = (st) => sites.filter(([, s]) => s.status === st);
const keepRaw = by("keep-raw");
const converted = by("converted");
const retired = by("retired");
const fixture = by("test-fixture");
const raw = by("raw").concat(by("in-progress"));

const out = [];
out.push("# ORM migration completion report - iznik-server-go (v2 API)");
out.push("");
out.push("Generated from iznik-server-go/ormharness/manifest.json by");
out.push("tools/orm-migration/completion-report.sh. Do not hand-edit.");
out.push("");
out.push("## Status counts (plan 7.4)");
out.push("");
out.push("| status | count |");
out.push("| --- | ---: |");
out.push("| converted | " + converted.length + " |");
out.push("| keep-raw | " + keepRaw.length + " |");
out.push("| retired | " + retired.length + " |");
out.push("| test-fixture | " + fixture.length + " |");
out.push("| **raw / in-progress** | **" + raw.length + "** |");
out.push("");
out.push(raw.length === 0
  ? "Zero raw and zero in-progress: plan 7.4 is satisfied for this codebase."
  : "**NOT COMPLETE**: " + raw.length + " site(s) remain raw or in-progress.");
out.push("");

// Group keep-raw by the mechanism named in its porting note, which is the
// axis Phase 4 actually needs to plan against.
const groups = new Map();
for (const [id, s] of keepRaw) {
  const key = s.category || "(uncategorised)";
  if (!groups.has(key)) groups.set(key, []);
  groups.get(key).push([id, s]);
}
const ordered = [...groups.entries()].sort((a, b) => b[1].length - a[1].length);

out.push("## Keep-raw by porting category");
out.push("");
out.push("| category | sites |");
out.push("| --- | ---: |");
for (const [k, v] of ordered) out.push("| " + k + " | " + v.length + " |");
out.push("");

out.push("## Every keep-raw site");
out.push("");
for (const [k, v] of ordered) {
  out.push("### " + k + " (" + v.length + ")");
  out.push("");
  out.push(v[0][1].porting || "(no porting note)");
  out.push("");
  v.sort((a, b) => (a[1].file + a[1].line).localeCompare(b[1].file + b[1].line));
  for (const [id, s] of v) {
    out.push("- `" + id + "` " + s.file.replace("iznik-server-go/", "") + ":" + s.line +
      " `" + (s.function || "?") + "` - " + (s.reason || "(no reason)"));
  }
  out.push("");
}

if (retired.length) {
  out.push("## Retired (" + retired.length + ")");
  out.push("");
  out.push("Sites that no longer exist and are not coming back.");
  out.push("");
  for (const [id, s] of retired) {
    out.push("- `" + id + "` " + s.file.replace("iznik-server-go/", "") + " - " + (s.reason || ""));
  }
  out.push("");
}

console.log(out.join("\n"));
' "$MANIFEST"
