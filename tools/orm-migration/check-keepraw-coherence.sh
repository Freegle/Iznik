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
MANIFEST="${RATCHET_MANIFEST:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"

# Conversion markers. Two forms occur in the tree and both must be seen:
#
#   // ORM migration site  1571f00a4ce8 ...
#   // ORM migration sites 1571f00a4ce8 (...) and b0445c89f59e (...)
#
# The plural is written where one statement was replaced on behalf of several
# site ids at once (image.go's doCreate covers two). Matching only the singular
# "site <id>" missed those entirely, which is a silent hole in exactly the
# direction that matters: an unseen marker makes a stale keep-raw rule look
# legitimate, so a lost deletion goes unreported.
#
# So: match "site" or "sites", then take every 12-hex id on the marker line and
# on its comment continuation lines, since a wrapped comment carries the second
# id onto the next line.
markers="$(grep -rhoE 'ORM migration sites?[^A-Za-z0-9]+[0-9a-f]{12}([^A-Za-z0-9]+(and[^A-Za-z0-9]+)?[0-9a-f]{12})*' \
    "$SOURCE_ROOT" --include='*.go' 2>/dev/null |
  grep -oE '[0-9a-f]{12}' | sort -u)"

# The wrapped-comment case: "sites <id> (long note)\n// ... and <id2> (...)".
# The line-oriented match above stops at the newline, so sweep comment lines
# that name an id and sit within a few lines of a marker.
markers="$(printf '%s\n%s\n' "$markers" "$(grep -rhA3 -E 'ORM migration sites ' "$SOURCE_ROOT" --include='*.go' 2>/dev/null |
  grep -oE '^[[:space:]]*//.*[0-9a-f]{12}' | grep -oE '[0-9a-f]{12}')" | sort -u | grep -E '^[0-9a-f]{12}$' || true)"

MARKERS="$markers" node -e '
const fs = require("fs");
const rules = JSON.parse(fs.readFileSync(process.argv[1], "utf8")).rules;
const marked = new Set((process.env.MARKERS || "").split("\n").filter(Boolean));

const ids = rules.filter((r) => r.id).map((r) => r.id);
const dupes = [...new Set(ids.filter((x, i) => ids.indexOf(x) !== i))];
// A marker means "this site was dealt with", and for most sites that means
// converted - so a keep-raw rule alongside one is a contradiction, and the
// usual cause is a concurrent read-modify-write silently reverting a
// deletion made by another agent.
//
// It is only a contradiction when the raw SQL has actually GONE. Some sites
// carry a marker precisely to record that they stay raw: where a statement
// cannot be a GORM chain, the technique is to extract a pure builder and prove
// its output with a parametrized-shape or fieldwise test, and the marker
// documents that proof. The raw call site is still there, presentInCode says
// so, and the keep-raw rule is correct rather than stale.
//
// So consult the manifest instead of assuming. Without it this check told the
// truth about a lost deletion and lied about nine proven-builder sites, and the
// tempting fix - dropping their markers - would have removed the only pointer
// from the code to the test that proves it.
let present = new Set();
try {
  const m = JSON.parse(fs.readFileSync(process.argv[2], "utf8"));
  present = new Set(Object.entries(m.sites)
    .filter(([, s]) => s.presentInCode === true)
    .map(([id]) => id));
} catch { /* no manifest: fall back to the stricter reading */ }

const contradicted = rules.filter((r) => r.id && marked.has(r.id) && !present.has(r.id));
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
' "$RULES" "$MANIFEST"
