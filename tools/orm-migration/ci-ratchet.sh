#!/usr/bin/env bash
#
# ci-ratchet.sh - Gate 1 of the raw-SQL-to-ORM migration (plan section 7.1.3,
# plans/database-migration-evaluation-2026-07.md lines 104-108).
#
# Regenerates the raw-SQL inventory from source and compares it against the
# manifest committed in this branch/PR (tools/orm-migration/manifest.json).
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
#                       Default: tools/orm-migration/manifest.json in this repo.
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
#      master, via `git show <merge-base>:tools/orm-migration/manifest.json`.
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

COMMITTED_MANIFEST="${RATCHET_MANIFEST:-$SCRIPT_DIR/manifest.json}"
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
if ! (cd "$EXTRACTOR_DIR" && go run . -root "$SOURCE_ROOT" -out "$TEMP_MANIFEST" -repo "$REPO_ROOT" >"$WORKDIR/extract.log" 2>&1); then
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
  note "fix: run the extractor (cd tools/orm-migration && go run . -out manifest.json) and set an explicit status for each new site, then commit the updated manifest.json"
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

# --- Summary -----------------------------------------------------------------
counts=$(jq -c '.counts' "$COMMITTED_MANIFEST")
note "committed manifest status counts: $counts"

if [ "$fail_count" -gt 0 ]; then
  note "FAIL ($fail_count gate(s) failed)"
  exit 1
fi
note "PASS (all gates green)"
