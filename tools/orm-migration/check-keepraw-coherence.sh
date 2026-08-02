#!/usr/bin/env bash
#
# check-keepraw-coherence.sh - does keep-raw.json still agree with the code?
#
# keep-raw.json is edited by several agents at once, and a plain
# read-parse-modify-write on a 200-rule file is a lost-update race: whoever
# writes last silently reverts everyone else's deletions. The failure is quiet -
# the file stays valid JSON, the counts merely stop being true - which is the
# worst kind, because every gate downstream keeps passing.
#
# The observable symptom is a contradiction: a rule saying "this site stays raw"
# for a site whose code carries a conversion marker. That cannot be true of both
# at once, and it is exactly what a reverted deletion looks like.
#
# Also checks the invariants a hand-edit tends to break: duplicate ids (two
# agents adding the same site), and rules missing the reason, porting note or
# category that plan 7.4 requires.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
RULES="${1:-$SCRIPT_DIR/keep-raw.json}"
SOURCE_ROOT="${2:-$REPO_ROOT/iznik-server-go}"

markers="$(grep -rho 'ORM migration site [0-9a-f]\{12\}' "$SOURCE_ROOT" --include='*.go' 2>/dev/null |
  awk '{print $4}' | sort -u)"

MARKERS="$markers" node -e '
const fs = require("fs");
const rules = JSON.parse(fs.readFileSync(process.argv[1], "utf8")).rules;
const marked = new Set((process.env.MARKERS || "").split("\n").filter(Boolean));

const ids = rules.filter((r) => r.id).map((r) => r.id);
const dupes = [...new Set(ids.filter((x, i) => ids.indexOf(x) !== i))];
const contradicted = rules.filter((r) => r.id && marked.has(r.id));
const noReason = rules.filter((r) => !(r.reason || "").trim());
const noPorting = rules.filter((r) => !(r.porting || "").trim());
const noCategory = rules.filter((r) => !(r.category || "").trim());

console.log(`check-keepraw-coherence: ${rules.length} rules, ${marked.size} conversion markers in code`);

let bad = 0;
const report = (label, list, fmt) => {
  if (!list.length) return;
  bad += list.length;
  console.log(`  ${label}: ${list.length}`);
  list.slice(0, 12).forEach((x) => console.log("      " + fmt(x)));
};

report("rules whose site has a conversion marker (a deletion was lost)", contradicted,
  (r) => `${r.id} ${r.file || ""} - ${(r.reason || "").slice(0, 60)}`);
report("duplicate ids (two agents added the same site)", dupes, (x) => x);
report("rules with no reason", noReason, (r) => r.id || r.file);
report("rules with no porting note (plan 7.4)", noPorting, (r) => r.id || r.file);
report("rules with no category", noCategory, (r) => r.id || r.file);

if (bad) {
  console.log("check-keepraw-coherence: FAIL");
  process.exit(1);
}
console.log("check-keepraw-coherence: OK");
' "$RULES"
