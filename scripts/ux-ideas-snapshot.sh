#!/bin/bash
# Freeze one funnel's member-facing code as it was at a commit, for the idea generator backtest
# (.claude/workflows/ux-ideas-backtest.js). Writes:
#
#   <outdir>/code/        the front-end source directories the live generator may read
#                         (iznik-nuxt3/components, composables, stores, pages and so on) as they
#                         were at that commit, with repository paths, so the generator reads and
#                         follows imports as it does live
#   <outdir>/brief.json   what the generator may see: codeDir (the folder above) and the area's
#                         commit subjects BEFORE the commit (what had just been done). It holds
#                         no figures, surveys, reports or issues, so the backtest tests the part
#                         of the generator that works from code and history
#   <outdir>/truth.json   what it must not see: the area's commits after it, up to MONTHS later,
#                         with subjects and short diffs, which the judges compare the ideas to
#
# Usage: scripts/ux-ideas-snapshot.sh <commit-ish> <flow> <outdir> [months-of-truth, default 6]
# The flow's paths come from claude-agent-sdk/ux-ideas-prompt.js (FLOW_PATHS).
set -euo pipefail

COMMIT=${1:?commit}
FLOW=${2:?flow}
OUT=${3:?outdir}
MONTHS=${4:-6}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

mapfile -t PATHS < <(node -e "const m=require('./claude-agent-sdk/ux-ideas-prompt.js').FLOW_PATHS; const p=m[process.argv[1]]; if(!p){console.error('unknown flow');process.exit(2)}; console.log(p.join('\n'))" "$FLOW")

SHA=$(git rev-parse "$COMMIT")
mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)

# The source the live generator is allowed to read (READABLE_DIRS and READABLE_FILES in
# claude-agent-sdk/ux-ideas.js), whatever of it existed at the commit.
READABLE=(components composables stores pages layouts plugins utils mixins api constants constants.js)
SRC=()
for d in "${READABLE[@]}"; do
  if [ -n "$(git ls-tree --name-only "$SHA" "iznik-nuxt3/$d" 2>/dev/null)" ]; then
    SRC+=("iznik-nuxt3/$d")
  fi
done

rm -rf "$OUT/code"
mkdir -p "$OUT/code"
git archive "$SHA" -- "${SRC[@]}" | tar -x -C "$OUT/code"

DATE=$(git show -s --format=%cs "$SHA")

node - "$SHA" "$DATE" "$FLOW" "$OUT" "$MONTHS" <<'EOF'
const { execFileSync } = require('node:child_process')
const fs = require('node:fs')
const [sha, date, flow, out, monthsText] = process.argv.slice(2)
const months = Number(monthsText)
const paths = require('./claude-agent-sdk/ux-ideas-prompt.js').FLOW_PATHS[flow]
const git = (args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

// What had been done in this area in the six months before the snapshot, newest first.
const since = new Date(date + 'T00:00:00Z')
since.setUTCMonth(since.getUTCMonth() - 6)
const recentCommits = git(['log', '--no-merges', `--since=${since.toISOString().slice(0, 10)}`, `--until=${date}`, '--format=%cs %s', sha, '--', ...paths])
  .split('\n').filter(Boolean).slice(0, 60)

fs.writeFileSync(`${out}/brief.json`, JSON.stringify({ commit: sha, today: date, flow, count: 15, codeDir: `${out}/code`, recentCommits }, null, 1))

// What was done afterwards, for a fixed window so the score does not depend on how old the
// snapshot is: the ground truth the judges score against. Subject, body, stat and the first part
// of the diff, per commit, newest first.
const until = new Date(date + 'T00:00:00Z')
until.setUTCMonth(until.getUTCMonth() + months)
const untilDate = until.toISOString().slice(0, 10)
const shas = git(['log', '--no-merges', '--format=%H', `--until=${untilDate}`, `${sha}..HEAD`, '--', ...paths]).split('\n').filter(Boolean)
const later = shas.map((h) => {
  const header = git(['show', '-s', '--format=%h%n%cs%n%s%n%b', h]).split('\n')
  const stat = git(['show', '--stat', '--format=', h, '--', ...paths])
  const diff = git(['show', '--format=', h, '--', ...paths])
  return {
    sha: header[0], date: header[1], subject: header[2], body: header.slice(3).join('\n').trim().slice(0, 1500),
    stat: stat.trim().slice(0, 800), diff: diff.slice(0, 3000),
  }
})
fs.writeFileSync(`${out}/truth.json`, JSON.stringify({ commit: sha, date, flow, until: untilDate, count: later.length, later }, null, 1))
console.log(`${flow} at ${sha.slice(0, 9)} (${date}): code in ${out}/code, ${recentCommits.length} commits before, ${later.length} commits after up to ${untilDate} -> ${out}`)
EOF
