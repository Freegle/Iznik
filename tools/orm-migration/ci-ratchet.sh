#!/usr/bin/env bash
#
# ci-ratchet.sh - Gate 1 of the raw-SQL-to-ORM migration (plan section 7.1.3,
# plans/database-migration-evaluation-2026-07.md lines 104-108).
#
# Regenerates the raw-SQL inventory from source and compares it against the
# manifest committed in this branch/PR (iznik-server-go/ormharness/manifest.json).
# Fails the build if any of:
#
#   (b) the code contains a raw SQL call site whose ID is absent from the
#       committed manifest - i.e. new raw SQL was added without an
#       accompanying manifest update.
#   (c) an ID present in both manifests has a different goldenSql in the
#       committed manifest than what re-extraction finds in the real source
#       today - i.e. the recorded "golden" SQL (what Layer-1 parity tests
#       compare against, plan 7.2) has drifted from reality, either because
#       the manifest was hand-edited or the extractor was not re-run after a
#       source edit.
#   (d) the number of sites with status raw or in-progress has increased
#       beyond the committed manifest's baseline (see "Baseline" below).
#   (e) any site has status keep-raw with an empty/whitespace-only reason -
#       deferral must always carry a written justification (plan 7.4).
#
# Usage: tools/orm-migration/ci-ratchet.sh
#   Works from any cwd; paths are resolved relative to this script's location.
#
# Env overrides (intended for local testing of the gate itself - see
# tools/orm-migration/README.md "Testing the ratchet"; CI never sets these):
#   RATCHET_MANIFEST   Path to the "committed" manifest to check against.
#                       Default: iznik-server-go/ormharness/manifest.json in this repo.
#   RATCHET_ROOT        Source root passed to the extractor.
#                       Default: iznik-server-go in this repo.
#
# --- Baseline design (gate d) -----------------------------------------------
#
# Two ways to detect "the raw+in-progress count went up" were considered:
#
#   1. Compare against a baseline number stored inside manifest.json itself
#      (top-level "ratchet": {"baseline": N}).
#   2. Compare against the manifest as it existed at the PR's merge-base with
#      master, via `git show <merge-base>:iznik-server-go/ormharness/manifest.json`.
#
# This script uses (1). Reasons: it needs no git history (works on shallow
# clones, fresh worktrees, and - as today - before the manifest has ever been
# committed at all), it needs no assumption about which remote/branch is
# "master", and a bad/missing baseline fails safe (see fallback below) rather
# than silently no-op'ing when history is unavailable. The tradeoff is that
# raising the ceiling back up is only prevented by code review, not by git
# archaeology - acceptable here because gate (e) and the parity-test gate
# (Gate 2, see README) already make quietly reintroducing debt hard to hide.
#
# If manifest.json has no top-level "ratchet.baseline", the gate falls back to
# the committed manifest's own counts.raw + counts["in-progress"] as an
# implicit, self-initialising ceiling - so the very first run of this script
# against a manifest with no explicit baseline always passes (there is
# nothing to compare against yet), and the ceiling becomes whatever was last
# committed. Lowering the ceiling as sites convert is a deliberate, reviewed
# action: bump (or add) "ratchet.baseline" in the same PR that reduces the
# count. The script never lowers it automatically - if it did, a later PR
# could silently ratchet the ceiling back up to whatever the committed counts
# happen to be, defeating the point.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
EXTRACTOR_DIR="$SCRIPT_DIR"

COMMITTED_MANIFEST="${RATCHET_MANIFEST:-$REPO_ROOT/iznik-server-go/ormharness/manifest.json}"
SOURCE_ROOT="${RATCHET_ROOT:-$REPO_ROOT/iznik-server-go}"

fail_count=0
note() { printf 'CI-RATCHET: %s\n' "$1"; }
fail() {
  printf 'CI-RATCHET: FAIL: %s\n' "$1"
  fail_count=$((fail_count + 1))
}

for bin in go jq; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "CI-RATCHET: FAIL: required tool '$bin' not found on PATH" >&2
    exit 1
  fi
done

if [ ! -f "$COMMITTED_MANIFEST" ]; then
  echo "CI-RATCHET: FAIL: committed manifest not found at $COMMITTED_MANIFEST" >&2
  exit 1
fi
if ! jq -e . "$COMMITTED_MANIFEST" >/dev/null 2>&1; then
  echo "CI-RATCHET: FAIL: committed manifest at $COMMITTED_MANIFEST is not valid JSON" >&2
  exit 1
fi

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

TEMP_MANIFEST="$WORKDIR/manifest.json"
# Seed the temp path with a copy of the committed manifest BEFORE running the
# extractor. extract.go merges statuses/reasons/approvedDiff forward from
# whatever already exists at its -out path (see extract.go's merge()); without
# this seed step every status would reset to raw/test-fixture on every run,
# making every comparison below meaningless.
cp "$COMMITTED_MANIFEST" "$TEMP_MANIFEST"

note "regenerating inventory from $SOURCE_ROOT into a temp path (committed manifest is untouched)"
# -rules is passed explicitly: the rules file is found next to the manifest by
# default, and regenerating into a temp path would otherwise apply no keep-raw
# decisions at all, making the regenerated inventory disagree with the committed
# one for reasons that have nothing to do with the code.
if ! (cd "$EXTRACTOR_DIR" && go run . -root "$SOURCE_ROOT" -out "$TEMP_MANIFEST" -repo "$REPO_ROOT" -rules "$EXTRACTOR_DIR/keep-raw.json" >"$WORKDIR/extract.log" 2>&1); then
  cat "$WORKDIR/extract.log" >&2
  echo "CI-RATCHET: FAIL: extractor failed to regenerate the manifest (see output above)" >&2
  exit 1
fi
cat "$WORKDIR/extract.log"

if ! jq -e . "$TEMP_MANIFEST" >/dev/null 2>&1; then
  echo "CI-RATCHET: FAIL: regenerated manifest at $TEMP_MANIFEST is not valid JSON" >&2
  exit 1
fi

# --- Gate (b): raw SQL in code but not in the committed manifest -----------
jq -n --slurpfile c "$COMMITTED_MANIFEST" --slurpfile t "$TEMP_MANIFEST" '
  ($c[0].sites) as $committed | ($t[0].sites) as $temp |
  [ $temp | keys[] | select($committed[.] == null) |
    {id: ., file: $temp[.].file, line: $temp[.].line, function: $temp[.].function, goldenSql: $temp[.].goldenSql} ]
' >"$WORKDIR/new-sites.json"

new_count=$(jq 'length' "$WORKDIR/new-sites.json")
if [ "$new_count" -gt 0 ]; then
  fail "$new_count raw SQL site(s) found in code but missing from the committed manifest (new raw SQL must be added deliberately, with a status and reason):"
  jq -r '.[] | "  \(.file):\(.line)  [\(.id)]  \(.function)()  \(.goldenSql)"' "$WORKDIR/new-sites.json"
  note "fix: run the extractor (cd tools/orm-migration && go run .) and set an explicit status for each new site, then commit the updated manifest.json"
else
  note "gate (b) OK: no raw SQL sites found in code that are missing from the committed manifest"
fi

# --- Gate (c): goldenSql drifted from the committed manifest ---------------
jq -n --slurpfile c "$COMMITTED_MANIFEST" --slurpfile t "$TEMP_MANIFEST" '
  ($c[0].sites) as $committed | ($t[0].sites) as $temp |
  [ $temp | keys[] | select($committed[.] != null) | select($committed[.].goldenSql != $temp[.].goldenSql) |
    {id: ., file: $temp[.].file, line: $temp[.].line, committedSql: $committed[.].goldenSql, actualSql: $temp[.].goldenSql} ]
' >"$WORKDIR/sql-drift.json"

drift_count=$(jq 'length' "$WORKDIR/sql-drift.json")
if [ "$drift_count" -gt 0 ]; then
  fail "$drift_count site(s) whose committed goldenSql no longer matches the real source (manifest.json edited by hand, or source edited without regenerating the manifest):"
  jq -r '.[] | "  \(.file):\(.line)  [\(.id)]\n    committed: \(.committedSql)\n    actual:    \(.actualSql)"' "$WORKDIR/sql-drift.json"
  note "fix: regenerate the manifest from source and commit the result, so the golden SQL that Layer-1 parity tests compare against is genuine"
else
  note "gate (c) OK: no committed goldenSql has drifted from the real source"
fi

# --- Gate (d): raw + in-progress count vs baseline --------------------------
jq -n --slurpfile c "$COMMITTED_MANIFEST" --slurpfile t "$TEMP_MANIFEST" '
  ($c[0]) as $committed | ($t[0]) as $temp |
  (($committed.ratchet.baseline // (($committed.counts.raw // 0) + ($committed.counts["in-progress"] // 0)))) as $baseline |
  ([$temp.sites[] | select(.status == "raw" or .status == "in-progress")] | length) as $current |
  {baseline: $baseline, current: $current, exceeded: ($current > $baseline)}
' >"$WORKDIR/ratchet-count.json"

baseline=$(jq -r '.baseline' "$WORKDIR/ratchet-count.json")
current=$(jq -r '.current' "$WORKDIR/ratchet-count.json")
exceeded=$(jq -r '.exceeded' "$WORKDIR/ratchet-count.json")
if [ "$exceeded" = "true" ]; then
  fail "raw+in-progress count is $current, above the ratchet baseline of $baseline (see 'ratchet.baseline' in manifest.json, or the manifest's own counts if unset)"
  note "fix: this PR added raw SQL debt, or reverted a site's status from converted/keep-raw back to raw/in-progress. Either back that out, or - if the increase is deliberate - explain why and lower/raise ratchet.baseline in manifest.json in the same PR"
else
  note "gate (d) OK: raw+in-progress count is $current, at or below the ratchet baseline of $baseline"
fi

# --- Gate (e): keep-raw sites must carry a written reason -------------------
jq -n --slurpfile c "$COMMITTED_MANIFEST" '
  [ $c[0].sites | to_entries[] | select(.value.status == "keep-raw") |
    select((.value.reason // "") | gsub("\\s";"") | length == 0) |
    {id: .key, file: .value.file, line: .value.line} ]
' >"$WORKDIR/missing-reason.json"

missing_count=$(jq 'length' "$WORKDIR/missing-reason.json")
if [ "$missing_count" -gt 0 ]; then
  fail "$missing_count site(s) marked keep-raw with no written reason (plan 7.4: deferral must always carry a justification):"
  jq -r '.[] | "  \(.file):\(.line)  [\(.id)]"' "$WORKDIR/missing-reason.json"
  note "fix: add a non-empty 'reason' field to each of these entries in manifest.json"
else
  note "gate (e) OK: every keep-raw site carries a written reason"
fi

# --- Gate (f): nothing may leave the inventory without proof -----------------
# Converting a site deletes its raw SQL, so the site stops being found in the
# code. That is expected, but it is indistinguishable from the site simply
# having been deleted, and "it vanished" must never be a route out of the
# ratchet. A site that is no longer present in the code may only be counted
# converted when a parity test names its ID (plan 7.2's Gate 2); anything else
# has to be marked retired or keep-raw deliberately.
jq -n --slurpfile c "$COMMITTED_MANIFEST" '
  [ $c[0].sites | to_entries[] |
    select(.value.presentInCode == false) |
    select(.value.status == "raw" or .value.status == "in-progress" or
           (.value.status == "converted" and .value.hasParityTest != true)) |
    {id: .key, file: .value.file, line: .value.line, status: .value.status} ]
' >"$WORKDIR/unproven.json"

unproven_count=$(jq 'length' "$WORKDIR/unproven.json")
if [ "$unproven_count" -gt 0 ]; then
  fail "$unproven_count site(s) are no longer in the code but have no parity test to account for them:"
  jq -r '.[] | "  \(.file):\(.line)  [\(.id)]  status=\(.status)"' "$WORKDIR/unproven.json"
  note "fix: add a parity test naming the site ID (Gate 2), or mark the site retired/keep-raw with a reason if the code was deleted on purpose"
else
  note "gate (f) OK: every site missing from the code is accounted for by a parity test"
fi

# --- Gate (g): the committed manifest must not be stale -----------------------
# The mirror image of gate (f). A site the manifest still calls raw and present
# in the code, which re-extraction no longer finds, means the manifest was not
# regenerated after those sites were converted. The manifest is then lying in
# the safe-looking direction: it under-reports progress, and because the stale
# entry claims the site is still there, gate (f) has nothing to complain about.
#
# This is not hypothetical. Committing with `git add -A` while conversion work
# was still in flight captured half-converted files alongside a manifest
# generated before them, and every other gate passed.
jq -n --slurpfile c "$COMMITTED_MANIFEST" --slurpfile t "$TEMP_MANIFEST" '
  ($c[0].sites) as $committed | ($t[0].sites) as $temp |
  [ $committed | keys[] |
    select($committed[.].presentInCode == true) |
    select($committed[.].status == "raw" or $committed[.].status == "in-progress") |
    select($temp[.] == null or $temp[.].presentInCode == false) |
    {id: ., file: $committed[.].file, line: $committed[.].line} ]
' >"$WORKDIR/stale.json"

stale_count=$(jq 'length' "$WORKDIR/stale.json")
if [ "$stale_count" -gt 0 ]; then
  fail "$stale_count site(s) recorded as raw and present, but re-extraction no longer finds them - the committed manifest is stale:"
  jq -r '.[] | "  \(.file):\(.line)  [\(.id)]"' "$WORKDIR/stale.json" | head -20
  note "fix: regenerate the manifest (cd tools/orm-migration && go run .) and commit it together with the conversions"
else
  note "gate (g) OK: the committed manifest matches what re-extraction finds"
fi

# --- Gate (h): identical statements in a file convert together ----------------
# A site ID is a hash of (file, normalised SQL, occurrence index within the
# file). That index is positional, so converting ONE of two textually identical
# statements renumbers the survivor: the second becomes the first, inherits the
# first's ID, and therefore inherits its parity test. The manifest then records
# the wrong line, and a test vouches for a site it was not written for. Gate 2's
# guarantee quietly stops holding.
#
# This was not hypothetical: converting group.go's first "SELECT id, poly,
# polyofficial ... IN ?" rebound its ID to the untouched second one.
#
# 364 of the 1,636 in-scope sites sit in such duplicate groups, so the exposure
# is real. Rather than chase a perfectly stable identifier - any positional
# index renumbers when a sibling disappears - this refuses the one configuration
# that triggers it. Identical statements in a file are converted together, which
# is natural anyway since they are the same statement.
jq -n --slurpfile c "$COMMITTED_MANIFEST" '
  [ $c[0].sites | to_entries[]
    | select(.value.status != "test-fixture")
    | {k: (.value.file + " " + .value.goldenSql), id: .key, st: .value.status, f: .value.file} ]
  | group_by(.k)
  | map(select(length > 1))
  | map(select((map(.st) | index("converted")) and (map(.st) | index("raw"))))
  | map({file: .[0].f, members: map(.id + "/" + .st)})
' >"$WORKDIR/split.json"

split_count=$(jq 'length' "$WORKDIR/split.json")
if [ "$split_count" -gt 0 ]; then
  fail "$split_count group(s) of identical statements are half converted, which renumbers the survivors' site IDs:"
  jq -r '.[] | "  \(.file)\n    \(.members | join("  "))"' "$WORKDIR/split.json" | head -30
  note "fix: convert every identical statement in the file together, or leave them all raw"
else
  note "gate (h) OK: no half-converted groups of identical statements"
fi

# --- Gate (i): SET order that carries meaning --------------------------------
#
# GORM sorts map keys when building Updates(map[string]interface{}{...}), and
# MySQL evaluates SET assignments left to right, so a conversion can reorder a
# statement into computing something different. The golden-SQL harness cannot
# catch this on its own: normaliseColumnOrder sorts SET lists on both sides so
# that harmless reordering passes, which means it would wave through harmful
# reordering too. The harness now declines to normalise these, and this gate
# stops one being introduced without anyone looking at it.
if bash "$SCRIPT_DIR/check-set-order.sh" "$SOURCE_ROOT" >"$WORKDIR/setorder.txt" 2>&1; then
  note "gate (i) OK: $(tail -1 "$WORKDIR/setorder.txt")"
else
  fail "an UPDATE's SET order carries meaning, and GORM sorts map keys:"
  grep '^SET ORDER' "$WORKDIR/setorder.txt" | head -20
  note "fix: read the site. If the order matters, split it into separate statements or keep it raw."
fi

# --- Gate (j): uses of gorm/clause without the import ------------------------
#
# gofmt validates formatting, not compilation. A file using gorm.Expr with no
# gorm import passes every cheap check and only fails the build, which costs a
# full suite run to discover.
if bash "$SCRIPT_DIR/check-imports.sh" "$SOURCE_ROOT" >"$WORKDIR/imports.txt" 2>&1; then
  note "gate (j) OK: no missing gorm/clause/dbresolver imports"
else
  fail "a file uses gorm/clause/dbresolver without importing it:"
  grep -E '^(MISSING IMPORT|BAD IMPORT PATH)' "$WORKDIR/imports.txt" | head -20
fi

# --- Gate (k): INSERTs whose generated id is read back -----------------------
#
# LAST_INSERT_ID() is connection-scoped session state, so an INSERT whose id is
# read afterwards must stay raw; converting it moves the id onto GORM's "@id"
# writeback and hands the connection choice to dbresolver. The failure mode is
# silent - a row with the wrong parent id, not an error.
#
# The list is generated from the merge-base with master, because after a
# conversion the raw SQL is gone and the question is no longer answerable from
# the code. See check-lastinsertid.sh.
if RATCHET_MANIFEST="$COMMITTED_MANIFEST" bash "$SCRIPT_DIR/check-lastinsertid.sh" >"$WORKDIR/lastid.txt" 2>&1; then
  note "gate (k) OK: $(tail -1 "$WORKDIR/lastid.txt")"
else
  fail "an INSERT whose generated id is read back has been converted:"
  grep '^LASTINSERTID' "$WORKDIR/lastid.txt" | head -20
  note "fix: revert the site to raw db.Exec and add it to keep-raw.json with a reason"
fi

# --- Gate (l): keep-raw.json still agrees with the code ----------------------
#
# keep-raw.json is edited concurrently, and a read-modify-write on a 200-rule
# file silently reverts other people's deletions. The file stays valid JSON and
# every other gate keeps passing; only the counts stop being true.
#
# A rule saying "this site stays raw" for a site whose code carries a conversion
# marker cannot be true of both at once, and that contradiction is what a
# reverted deletion looks like from the outside.
if bash "$SCRIPT_DIR/check-keepraw-coherence.sh" "$SCRIPT_DIR/keep-raw.json" "$SOURCE_ROOT" >"$WORKDIR/keepraw.txt" 2>&1; then
  note "gate (l) OK: $(head -1 "$WORKDIR/keepraw.txt")"
else
  fail "keep-raw.json disagrees with the code:"
  grep -vE '^check-keepraw-coherence: [0-9]+ rules' "$WORKDIR/keepraw.txt" | head -20
  note "fix: a rule for a site that carries a conversion marker means a deletion was lost - re-remove it"
fi

# --- Gate (m): the extractor knows every assertion that proves a site ---------
#
# Gate (f) and the extractor's promotion-to-converted rule both key off
# extract.go's parityAssertions map. If an assertion is added to ormharness and
# not to that map, every site proven by it silently stops counting: gate (f)
# reports it as "no longer in the code but with no parity test", which reads as
# raw SQL deleted without proof - the most alarming thing the ratchet can say.
#
# That is not a hypothetical failure mode. parityAssertions was written when the
# harness had two assertions, three more were added as Layer 1 grew, and 68
# sites with real passing tests were reported as unaccounted-for. The gates were
# all green up to that point, so nothing pointed at the map; the finger pointed
# at 68 innocent conversions instead.
#
# So the map is checked against the harness's actual surface: every exported
# assertion taking a siteID must be listed. AssertHintSurvivesInRawSQL takes no
# siteID and so is not in scope - it proves a hint survives in a statement that
# is staying raw, which is the opposite claim.
missing_assertions=""
while read -r name; do
  [ -n "$name" ] || continue
  if ! grep -q "\"$name\":" "$SCRIPT_DIR/extract.go"; then
    missing_assertions="$missing_assertions $name"
  fi
done <<EOF
$(grep -rhoE '^func (Assert[A-Za-z]+)\(t TestingT, siteID string' "$SOURCE_ROOT/ormharness/"*.go 2>/dev/null \
    | sed -E 's/^func (Assert[A-Za-z]+)\(.*/\1/' | sort -u)
EOF

if [ -z "$missing_assertions" ]; then
  note "gate (m) OK: extract.go's parityAssertions lists every ormharness assertion that names a site"
else
  fail "ormharness assertion(s) missing from extract.go's parityAssertions:$missing_assertions"
  note "fix: add them to parityAssertions in tools/orm-migration/extract.go and regenerate the manifest - until then every site proven only by those assertions counts as unproven"
fi

# --- Gate (n): "converted" must mean the raw SQL is GONE ----------------------
#
# The counterpart to gate (f). Gate (f) asks whether a site that VANISHED has a
# test; this asks whether a site marked converted is actually still sitting in
# the code as raw SQL. Nothing checked that direction, and nine sites had drifted
# into it.
#
# They got there honestly. Where a statement cannot be expressed as a GORM chain
# - a UNION with a variable number of arms, a SET list built per optional field -
# the technique used was to extract a pure builder function and prove its output
# with a parametrized-shape or fieldwise parity test. That is real work and the
# proof is real. But the raw SQL is still there, so calling it converted counts
# the same statement twice: once as converted under its old id, and again as raw
# under the new id the extractor assigns once the text moves into the builder.
#
# "Converted" has to keep meaning one thing, or the burn-down stops measuring
# anything. A statement that is proven but still raw is keep-raw with a reason
# that says so - which is exactly what those sites now carry.
jq -n --slurpfile c "$COMMITTED_MANIFEST" '
  [ $c[0].sites | to_entries[] |
    select(.value.status == "converted" and .value.presentInCode == true) |
    {id: .key, file: .value.file, line: .value.line, function: .value.function} ]
' >"$WORKDIR/stillraw.json"

stillraw_count=$(jq 'length' "$WORKDIR/stillraw.json")
if [ "$stillraw_count" -gt 0 ]; then
  fail "$stillraw_count site(s) are marked converted but their raw SQL is still in the code:"
  jq -r '.[] | "  \(.file):\(.line)  [\(.id)]  \(.function)"' "$WORKDIR/stillraw.json"
  note "fix: either finish the conversion so the raw call site goes away, or mark it keep-raw with a reason - a proven builder is still raw SQL"
else
  note "gate (n) OK: every converted site has actually had its raw SQL removed"
fi

# --- Gate (o): no smuggling a whole statement through .Select() -------------
#
# Setting Statement.BuildClauses = {"SELECT"} suppresses the FROM clause GORM
# always registers. That has a legitimate use (a scalar expression, so a bare
# "SELECT EXISTS(...)" renders byte-identically) and an abuse (the whole
# statement as the Select argument, with Table() as a decoy). The abuse looks
# like a conversion in a diff and moved the SQL into a method the extractor did
# not scan, so five sites stopped counting as raw and started counting as
# converted work without a line of SQL changing.
#
# The extractor now inventories those, so they cannot vanish again; this gate is
# the second line, catching new ones at the point they are written.
if bash "$SCRIPT_DIR/check-buildclauses.sh" "$SOURCE_ROOT" >"$WORKDIR/buildclauses.txt" 2>&1; then
  note "gate (o) OK: $(tail -1 "$WORKDIR/buildclauses.txt")"
else
  fail "a whole statement is being passed through .Select() with the FROM suppressed:"
  grep -A2 '^BUILDCLAUSES' "$WORKDIR/buildclauses.txt" | head -20
  note "fix: this is relocation, not conversion - mark the site keep-raw with a reason"
fi

# --- Gate (p): per-service manifests (iznik-spatial-go, iznik-routing-go) ----
#
# Those two services do not import gorm.io/gorm and, once scoped, turned out
# to have essentially no wave 1-3 mechanical surface (see the scoping report
# in the team's session history) - not enough to justify adding a GORM
# dependency and the full 4-layer parity harness to either. So unlike
# iznik-server-go they get no Layer-1/2 harness and no raw/in-progress/
# converted lifecycle: every site in tools/orm-migration/services/*/manifest.json
# is either keep-raw (a real MySQL statement, deferred, with a Phase-4 porting
# note) or test-fixture. There is nothing here for gates (d), (f), (h)-(o) to
# check - no ratchet baseline, no parity tests, no conversions - but the one
# failure mode that matters for a fully-triaged manifest is unchanged: new raw
# SQL appearing with nobody noticing. This gate is the equivalent of (b), (c),
# (e) and (g) above, scaled down to that one concern, run once per service.
#
# Self-guarding: if tools/orm-migration/services/ does not exist at all (e.g.
# an older branch, or a future checkout that never added it), this gate is a
# no-op rather than a failure - "wire it in" should not make ci-ratchet.sh
# depend on a directory this repo's history did not always have.
SERVICES_DIR="$SCRIPT_DIR/services"
if [ ! -d "$SERVICES_DIR" ]; then
  note "gate (p) SKIP: no $SERVICES_DIR directory - nothing to check"
else
  shopt -s nullglob
  service_dirs=("$SERVICES_DIR"/*/)
  shopt -u nullglob
  if [ "${#service_dirs[@]}" -eq 0 ]; then
    note "gate (p) SKIP: $SERVICES_DIR exists but has no service subdirectories"
  fi
  for svc_dir in "${service_dirs[@]}"; do
    svc_name="$(basename "$svc_dir")"
    # services/laravel/ follows a different convention (PHP, not Go; its own
    # extractor and lifecycle - see gate (q) below) and does not have an
    # "iznik-laravel-go" source tree to check against. Excluded here rather
    # than made to fit the "iznik-$name-go" convention below, so this loop's
    # behaviour for spatial/routing is unchanged.
    if [ "$svc_name" = "laravel" ]; then
      continue
    fi
    svc_manifest="$svc_dir/manifest.json"
    svc_rules="$svc_dir/keep-raw.json"
    # Convention: services/spatial -> iznik-spatial-go, services/routing ->
    # iznik-routing-go. This needs no per-service registration - adding a new
    # services/<name>/ directory that matches an iznik-<name>-go source tree is
    # enough - but a directory that does not follow it fails loudly rather than
    # being silently skipped, because that is a real setup bug, not absence.
    svc_source_root="$REPO_ROOT/iznik-$svc_name-go"

    if [ ! -f "$svc_manifest" ] || [ ! -f "$svc_rules" ]; then
      fail "services/$svc_name/ is missing manifest.json or keep-raw.json"
      continue
    fi
    if ! jq -e . "$svc_manifest" >/dev/null 2>&1; then
      fail "services/$svc_name/manifest.json is not valid JSON"
      continue
    fi
    if [ ! -d "$svc_source_root" ]; then
      fail "services/$svc_name/ expects a source tree at $svc_source_root, which does not exist"
      continue
    fi

    svc_temp="$WORKDIR/svc-$svc_name.json"
    cp "$svc_manifest" "$svc_temp"
    if ! (cd "$EXTRACTOR_DIR" && go run . -root "$svc_source_root" -out "$svc_temp" -repo "$REPO_ROOT" -rules "$svc_rules" >"$WORKDIR/svc-$svc_name.log" 2>&1); then
      cat "$WORKDIR/svc-$svc_name.log" >&2
      fail "services/$svc_name/: extractor failed to regenerate the manifest (see output above)"
      continue
    fi

    # (b)-equivalent: a site re-extraction finds that the committed manifest
    # does not know about at all - the direct signal of new raw SQL.
    jq -n --slurpfile c "$svc_manifest" --slurpfile t "$svc_temp" '
      ($c[0].sites) as $committed | ($t[0].sites) as $temp |
      [ $temp | keys[] | select($committed[.] == null) |
        {id: ., file: $temp[.].file, line: $temp[.].line, function: $temp[.].function, goldenSql: $temp[.].goldenSql} ]
    ' >"$WORKDIR/svc-$svc_name-new.json"
    svc_new_count=$(jq 'length' "$WORKDIR/svc-$svc_name-new.json")
    if [ "$svc_new_count" -gt 0 ]; then
      fail "services/$svc_name/: $svc_new_count raw SQL site(s) found in code but missing from the committed manifest:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]  \(.function)()  \(.goldenSql)"' "$WORKDIR/svc-$svc_name-new.json"
      note "fix: cd tools/orm-migration && go run . -root ../../iznik-$svc_name-go -out services/$svc_name/manifest.json -repo ../.. -rules services/$svc_name/keep-raw.json, then add a keep-raw rule (with reason and Phase-4 porting note) or a test-fixture/retired entry for each new site, and commit"
    fi

    # (c)-equivalent: goldenSql drifted for a site present in both.
    jq -n --slurpfile c "$svc_manifest" --slurpfile t "$svc_temp" '
      ($c[0].sites) as $committed | ($t[0].sites) as $temp |
      [ $temp | keys[] | select($committed[.] != null) | select($committed[.].goldenSql != $temp[.].goldenSql) |
        {id: ., file: $temp[.].file, line: $temp[.].line, committedSql: $committed[.].goldenSql, actualSql: $temp[.].goldenSql} ]
    ' >"$WORKDIR/svc-$svc_name-drift.json"
    svc_drift_count=$(jq 'length' "$WORKDIR/svc-$svc_name-drift.json")
    if [ "$svc_drift_count" -gt 0 ]; then
      fail "services/$svc_name/: $svc_drift_count site(s) whose committed goldenSql no longer matches the real source:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]\n    committed: \(.committedSql)\n    actual:    \(.actualSql)"' "$WORKDIR/svc-$svc_name-drift.json"
      note "fix: regenerate services/$svc_name/manifest.json from source and commit the result"
    fi

    # (e)-equivalent: every keep-raw site must carry a written reason. (The
    # extractor already refuses to apply a rule with no reason/porting note at
    # generation time - this also catches a site that matched no rule at all
    # and is sitting at status "raw", which would otherwise slip through.)
    jq -n --slurpfile c "$svc_manifest" '
      [ $c[0].sites | to_entries[] |
        select(.value.status == "keep-raw") |
        select((.value.reason // "") | gsub("\\s";"") | length == 0) |
        {id: .key, file: .value.file, line: .value.line} ]
    ' >"$WORKDIR/svc-$svc_name-noreason.json"
    svc_noreason_count=$(jq 'length' "$WORKDIR/svc-$svc_name-noreason.json")
    if [ "$svc_noreason_count" -gt 0 ]; then
      fail "services/$svc_name/: $svc_noreason_count site(s) marked keep-raw with no written reason:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]"' "$WORKDIR/svc-$svc_name-noreason.json"
    fi
    svc_notriaged_count=$(jq '[.sites[] | select(.status != "keep-raw" and .status != "test-fixture")] | length' "$svc_temp")
    if [ "$svc_notriaged_count" -gt 0 ]; then
      fail "services/$svc_name/: $svc_notriaged_count site(s) are not fully triaged (status other than keep-raw/test-fixture) - this service's manifest is meant to carry zero raw/in-progress sites:"
      jq -r '.sites[] | select(.status != "keep-raw" and .status != "test-fixture") | "  \(.file):\(.line)  [\(.id)]  status=\(.status)"' "$svc_temp"
    fi

    # (g)-equivalent: a site the committed manifest still says is present in
    # code, which re-extraction can no longer find - the manifest is stale.
    jq -n --slurpfile c "$svc_manifest" --slurpfile t "$svc_temp" '
      ($c[0].sites) as $committed | ($t[0].sites) as $temp |
      [ $committed | keys[] |
        select($committed[.].presentInCode == true) |
        select($temp[.] == null or $temp[.].presentInCode == false) |
        {id: ., file: $committed[.].file, line: $committed[.].line} ]
    ' >"$WORKDIR/svc-$svc_name-stale.json"
    svc_stale_count=$(jq 'length' "$WORKDIR/svc-$svc_name-stale.json")
    if [ "$svc_stale_count" -gt 0 ]; then
      fail "services/$svc_name/: $svc_stale_count site(s) recorded as present, but re-extraction no longer finds them - the committed manifest is stale:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]"' "$WORKDIR/svc-$svc_name-stale.json"
      note "fix: regenerate services/$svc_name/manifest.json and commit it together with the code change"
    fi

    if [ "$svc_new_count" -eq 0 ] && [ "$svc_drift_count" -eq 0 ] && [ "$svc_noreason_count" -eq 0 ] && [ "$svc_notriaged_count" -eq 0 ] && [ "$svc_stale_count" -eq 0 ]; then
      note "gate (p) OK for services/$svc_name/: manifest matches re-extraction, every site triaged with a reason"
    fi
  done
fi

# --- Gate (q): Laravel (iznik-batch) raw-SQL inventory ------------------------
#
# The PHP counterpart to gates (b)/(c)/(d)/(e)/(g) above, for
# tools/orm-migration/services/laravel/manifest.json (extractor:
# tools/orm-migration/php-extractor/extract.php - a nikic/php-parser AST walk
# over iznik-batch, not a Go one; see that file's header comment for the
# enumerated raw-SQL method surface and how it was verified against the
# framework source rather than copied from memory).
#
# Unlike gate (p)'s services (spatial/routing), this manifest carries the
# FULL raw/in-progress/converted/keep-raw/test-fixture/retired lifecycle
# (plus a Laravel-only "excluded" status for the SQLite classification-store
# cluster - see extract.php), because iznik-batch's raw-SQL surface has not
# been triaged yet; it is exactly where iznik-server-go's own manifest
# started. So this checks (b)/(c)/(d)/(e)/(g)'s Laravel equivalents, not
# gate (p)'s narrower "everything must already be keep-raw or test-fixture"
# contract. It does NOT port gates (f)/(h)-(o): those all guard the
# conversion/parity-test lifecycle (Gate 2), and nothing is being converted
# yet - there is no harness for this manifest in this branch. Porting them
# now would be guarding against a failure mode that cannot occur.
#
# Self-guarding in TWO ways, both deliberate:
#   - No services/laravel/manifest.json committed: skip. Same reasoning as
#     gate (p) and this file's own top-level self-guard in the orb step - an
#     unpublished manifest is not a gate, it is a comment.
#   - No `php`/`composer` on PATH: skip LOCALLY, but FAIL when
#     ORM_RATCHET_REQUIRE_PHP=1. Go and jq are HARD required at the top of
#     this script (the whole ratchet cannot run without them), and making php
#     equally hard would break every OTHER gate here - including the Go-only
#     ones - on any developer machine that legitimately has no PHP. So the
#     skip stays for local runs, and CI opts into strictness instead: the
#     orb's ratchet step provisions php + composer and exports
#     ORM_RATCHET_REQUIRE_PHP=1, so a gate that cannot run THERE is a build
#     failure rather than a quiet pass.
#
#     This distinction is the whole point. A gate that silently skips in CI
#     reads green while checking nothing, which is precisely the "we quietly
#     deferred a bit" failure mode section 7.1 of the plan exists to make
#     structurally impossible. Skipping is acceptable only where the person
#     reading the output knows why; in CI nobody is reading it.
#
# THE EXPLICIT-BASELINE FIX: gate (d) above falls back to the COMMITTED
# manifest's own raw+in-progress count when "ratchet.baseline" is absent - a
# self-initialising ceiling, documented as deliberate earlier in this file.
# In practice that fallback lets the ceiling silently ratchet upward: a PR
# that adds raw debt commits a manifest whose own counts already include the
# increase, so the fallback re-derives its limit from the thing it is
# supposed to be limiting, and the gate reports OK. This gate refuses that
# fallback outright for the Laravel manifest: a missing "ratchet.baseline" is
# a hard FAIL here, never a self-initialising default. The existing gate (d)
# for the Go manifest is NOT changed to match - that would be a bigger,
# separately-reviewable change to a gate other work already depends on,
# outside the scope of adding a new one - but the inconsistency between the
# two is real and worth reconciling later.
LARAVEL_MANIFEST="$SCRIPT_DIR/services/laravel/manifest.json"
LARAVEL_RULES="$SCRIPT_DIR/services/laravel/keep-raw.json"
LARAVEL_EXTRACTOR_DIR="$SCRIPT_DIR/php-extractor"
LARAVEL_SOURCE_ROOT="$REPO_ROOT/iznik-batch"

# Cross-language canonicaliser corpus sync check (plan 7.2, Layer 1). Needs
# neither php nor composer - it is a plain diff between three committed
# files - so it runs whenever the Laravel manifest exists, even on a
# machine with no PHP, unlike the rest of this gate. See
# check-canonical-corpus-sync.sh's own header for why three copies exist at
# all (both test runners' containers are isolated to their own service
# subtree and cannot read tools/orm-migration/ directly).
if [ -f "$LARAVEL_MANIFEST" ]; then
  if bash "$SCRIPT_DIR/check-canonical-corpus-sync.sh" >"$WORKDIR/corpus-sync.txt" 2>&1; then
    note "gate (q) OK (corpus sync): $(tail -1 "$WORKDIR/corpus-sync.txt")"
  else
    fail "the cross-language canonicaliser pinning corpus has drifted between its three copies:"
    cat "$WORKDIR/corpus-sync.txt"
  fi
fi

laravel_missing_tools=""
command -v php >/dev/null 2>&1 || laravel_missing_tools="php"
command -v composer >/dev/null 2>&1 || laravel_missing_tools="${laravel_missing_tools:+$laravel_missing_tools }composer"

if [ ! -f "$LARAVEL_MANIFEST" ]; then
  note "gate (q) SKIP: no $LARAVEL_MANIFEST - Laravel ORM migration tooling not present on this branch"
elif [ -n "$laravel_missing_tools" ] && [ "${ORM_RATCHET_REQUIRE_PHP:-0}" = "1" ]; then
  fail "gate (q): ORM_RATCHET_REQUIRE_PHP=1 but not on PATH: $laravel_missing_tools - the Laravel inventory could not be regenerated, so this gate would otherwise report nothing while checking nothing"
  note "fix: this is CI (the orb's ratchet step provisions php + composer before running this script). If that provisioning has broken, fix it there - do not unset ORM_RATCHET_REQUIRE_PHP, which would turn the gate back into a silent no-op"
elif [ -n "$laravel_missing_tools" ]; then
  note "gate (q) SKIP: not on PATH: $laravel_missing_tools - cannot regenerate the Laravel inventory to check it. This is a local-run convenience only; CI sets ORM_RATCHET_REQUIRE_PHP=1 so the same condition is a hard failure there"
elif ! jq -e . "$LARAVEL_MANIFEST" >/dev/null 2>&1; then
  fail "services/laravel/manifest.json is not valid JSON"
else
  if [ ! -d "$LARAVEL_EXTRACTOR_DIR/vendor" ]; then
    note "gate (q): installing php-extractor's own (small, standalone) composer dependencies"
    if ! (cd "$LARAVEL_EXTRACTOR_DIR" && composer install --no-interaction --quiet) >"$WORKDIR/laravel-composer.log" 2>&1; then
      cat "$WORKDIR/laravel-composer.log" >&2
      fail "php-extractor: composer install failed (see output above)"
    fi
  fi

  LARAVEL_TEMP="$WORKDIR/laravel-manifest.json"
  cp "$LARAVEL_MANIFEST" "$LARAVEL_TEMP"
  if ! php "$LARAVEL_EXTRACTOR_DIR/extract.php" --root="$LARAVEL_SOURCE_ROOT" --repo="$REPO_ROOT" --out="$LARAVEL_TEMP" --rules="$LARAVEL_RULES" >"$WORKDIR/laravel-extract.log" 2>&1; then
    cat "$WORKDIR/laravel-extract.log" >&2
    fail "php-extractor: extract.php failed to regenerate the Laravel manifest (see output above)"
  else
    cat "$WORKDIR/laravel-extract.log"

    # (b)-equivalent: raw SQL in iznik-batch but not in the committed manifest.
    jq -n --slurpfile c "$LARAVEL_MANIFEST" --slurpfile t "$LARAVEL_TEMP" '
      ($c[0].sites) as $committed | ($t[0].sites) as $temp |
      [ $temp | keys[] | select($committed[.] == null) |
        {id: ., file: $temp[.].file, line: $temp[.].line, function: $temp[.].function, goldenSql: $temp[.].goldenSql} ]
    ' >"$WORKDIR/laravel-new-sites.json"
    laravel_new_count=$(jq 'length' "$WORKDIR/laravel-new-sites.json")
    if [ "$laravel_new_count" -gt 0 ]; then
      fail "services/laravel: $laravel_new_count raw SQL site(s) found in iznik-batch but missing from the committed manifest:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]  \(.function)()  \(.goldenSql)"' "$WORKDIR/laravel-new-sites.json" | head -20
      note "fix: cd tools/orm-migration/php-extractor && php extract.php, review the new sites, set an explicit status for each, and commit the updated manifest.json"
    fi

    # (c)-equivalent: goldenSql drifted from the committed manifest.
    jq -n --slurpfile c "$LARAVEL_MANIFEST" --slurpfile t "$LARAVEL_TEMP" '
      ($c[0].sites) as $committed | ($t[0].sites) as $temp |
      [ $temp | keys[] | select($committed[.] != null) | select($committed[.].goldenSql != $temp[.].goldenSql) |
        {id: ., file: $temp[.].file, line: $temp[.].line, committedSql: $committed[.].goldenSql, actualSql: $temp[.].goldenSql} ]
    ' >"$WORKDIR/laravel-drift.json"
    laravel_drift_count=$(jq 'length' "$WORKDIR/laravel-drift.json")
    if [ "$laravel_drift_count" -gt 0 ]; then
      fail "services/laravel: $laravel_drift_count site(s) whose committed goldenSql no longer matches the real source:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]\n    committed: \(.committedSql)\n    actual:    \(.actualSql)"' "$WORKDIR/laravel-drift.json" | head -20
      note "fix: regenerate services/laravel/manifest.json from source and commit the result"
    fi

    # (d)-equivalent, with the explicit-baseline fix described above.
    laravel_baseline=$(jq -r '.ratchet.baseline // "MISSING"' "$LARAVEL_MANIFEST")
    if [ "$laravel_baseline" = "MISSING" ]; then
      fail "services/laravel/manifest.json has no explicit ratchet.baseline - this gate refuses the self-initialising fallback gate (d) uses above (see this gate's header comment for why)"
    else
      laravel_current=$(jq '[.sites[] | select(.status == "raw" or .status == "in-progress")] | length' "$LARAVEL_TEMP")
      if [ "$laravel_current" -gt "$laravel_baseline" ]; then
        fail "services/laravel: raw+in-progress count is $laravel_current, above the ratchet baseline of $laravel_baseline"
        note "fix: this PR added raw SQL debt to iznik-batch, or reverted a site's status back to raw/in-progress. Back it out, or lower/raise ratchet.baseline in services/laravel/manifest.json in the same PR with a reason"
      else
        note "gate (q) OK (d-equivalent): raw+in-progress count is $laravel_current, at or below the ratchet baseline of $laravel_baseline"
      fi
    fi

    # (e)-equivalent: keep-raw needs a written reason. "excluded" sites carry
    # the same requirement - "this is a different database engine" still
    # needs saying, not just asserting via a bare status.
    jq -n --slurpfile c "$LARAVEL_MANIFEST" '
      [ $c[0].sites | to_entries[] | select(.value.status == "keep-raw" or .value.status == "excluded") |
        select((.value.reason // "") | gsub("\\s";"") | length == 0) |
        {id: .key, file: .value.file, line: .value.line, status: .value.status} ]
    ' >"$WORKDIR/laravel-noreason.json"
    laravel_noreason_count=$(jq 'length' "$WORKDIR/laravel-noreason.json")
    if [ "$laravel_noreason_count" -gt 0 ]; then
      fail "services/laravel: $laravel_noreason_count site(s) marked keep-raw/excluded with no written reason:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]  status=\(.status)"' "$WORKDIR/laravel-noreason.json" | head -20
    fi

    # (g)-equivalent: committed manifest stale (says raw+present, re-extraction disagrees).
    jq -n --slurpfile c "$LARAVEL_MANIFEST" --slurpfile t "$LARAVEL_TEMP" '
      ($c[0].sites) as $committed | ($t[0].sites) as $temp |
      [ $committed | keys[] |
        select($committed[.].presentInCode == true) |
        select($committed[.].status == "raw" or $committed[.].status == "in-progress") |
        select($temp[.] == null or $temp[.].presentInCode == false) |
        {id: ., file: $committed[.].file, line: $committed[.].line} ]
    ' >"$WORKDIR/laravel-stale.json"
    laravel_stale_count=$(jq 'length' "$WORKDIR/laravel-stale.json")
    if [ "$laravel_stale_count" -gt 0 ]; then
      fail "services/laravel: $laravel_stale_count site(s) recorded as raw and present, but re-extraction no longer finds them - the committed manifest is stale:"
      jq -r '.[] | "  \(.file):\(.line)  [\(.id)]"' "$WORKDIR/laravel-stale.json" | head -20
      note "fix: regenerate services/laravel/manifest.json and commit it together with the conversions"
    fi

    if [ "$laravel_new_count" -eq 0 ] && [ "$laravel_drift_count" -eq 0 ] && [ "$laravel_noreason_count" -eq 0 ] && [ "$laravel_stale_count" -eq 0 ]; then
      note "gate (q) OK: services/laravel/manifest.json matches re-extraction, no new/drifted/stale/unjustified sites"
    fi
  fi
fi

# --- Summary -----------------------------------------------------------------
counts=$(jq -c '.counts' "$COMMITTED_MANIFEST")
note "committed manifest status counts: $counts"

if [ "$fail_count" -gt 0 ]; then
  note "FAIL ($fail_count gate(s) failed)"
  exit 1
fi
note "PASS (all gates green)"
