#!/usr/bin/env bash
#
# burndown.sh - plan 7.3's burn-down, stated so it cannot flatter itself.
#
# The obvious query - count sites with status "raw" - under-reports, and the
# under-reporting is invisible. A site whose raw SQL has been replaced but which
# has no parity test yet keeps status "raw" and gets presentInCode=false,
# because re-extraction can no longer find it. Filtering the burn-down on
# presentInCode (which is natural, since you want sites that still exist) then
# hides exactly the sites that are half-done.
#
# That is not hypothetical: it made wave 4 read as complete while 70 of its 140
# sites had been converted with no test yet written. Gate (f) would have caught
# it at commit time, but the progress report said "zero" first.
#
# So this reports three numbers per wave, and the middle one is the dangerous
# one: still raw in the code, converted-but-unproven, and done.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MANIFEST="${1:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"

node -e '
const m = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
const waves = {};
let unproven = 0;
for (const [, s] of Object.entries(m.sites)) {
  if (s.status === "test-fixture" || s.status === "retired") continue;
  const w = s.wave ?? "?";
  waves[w] ||= {raw: 0, unproven: 0, converted: 0, keepRaw: 0};
  if (s.status === "converted") waves[w].converted++;
  else if (s.status === "keep-raw") waves[w].keepRaw++;
  else if (s.presentInCode) waves[w].raw++;
  else { waves[w].unproven++; unproven++; }
}
console.log("wave |  raw | UNPROVEN | converted | keep-raw");
for (const w of Object.keys(waves).sort()) {
  const v = waves[w];
  console.log(
    String(w).padStart(4) + " | " + String(v.raw).padStart(4) + " | " +
    String(v.unproven).padStart(8) + " | " + String(v.converted).padStart(9) + " | " +
    String(v.keepRaw).padStart(8));
}
console.log("\ncounts recorded in the manifest: " + JSON.stringify(m.counts));
if (unproven) {
  console.log("\n" + unproven + " site(s) have had their raw SQL removed with no parity test bearing their id.");
  console.log("Those are NOT done: plan 7.2 Gate 2 says a site is not converted until a test names it.");
  process.exit(1);
}
console.log("\nNo unproven sites: every site whose raw SQL is gone has a parity test.");
' "$MANIFEST"
