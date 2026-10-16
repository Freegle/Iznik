#!/bin/bash
# Freeze one funnel's member-facing code as it was at a commit, for the idea generator backtest
# (.claude/workflows/ux-ideas-backtest.js). Writes two files:
#
#   <outdir>/brief.json   what the generator may see: the files at that commit, and the area's
#                         commit subjects BEFORE it (what had just been done)
#   <outdir>/truth.json   what it must not see: the area's commits AFTER it, with subjects and
#                         short diffs, which the judges compare the ideas against
#
# Usage: scripts/ux-ideas-snapshot.sh <commit-ish> <flow> <outdir>
# The flow's paths come from claude-agent-sdk/ux-ideas-prompt.js (FLOW_PATHS).
set -euo pipefail

COMMIT=${1:?commit}
FLOW=${2:?flow}
OUT=${3:?outdir}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

mapfile -t PATHS < <(node -e "const m=require('./claude-agent-sdk/ux-ideas-prompt.js').FLOW_PATHS; const p=m[process.argv[1]]; if(!p){console.error('unknown flow');process.exit(2)}; console.log(p.join('\n'))" "$FLOW")

SHA=$(git rev-parse "$COMMIT")
DATE=$(git show -s --format=%cs "$SHA")
mkdir -p "$OUT"

# The files that existed at the commit under the flow's paths, biggest-page first is not what we
# want: pages first (they are the journey), then components, each cut at 12k characters by the
# prompt builder. Up to 16 files.
mapfile -t FILES < <(git ls-tree -r --name-only "$SHA" -- "${PATHS[@]}" 2>/dev/null | grep -E '\.(vue|js|ts)$' | grep -v -E '\.spec\.|\.test\.' | sort | awk '{ if ($0 ~ /\/pages\//) print "0 " $0; else print "1 " $0 }' | sort | cut -d' ' -f2- | head -16)

node - "$SHA" "$DATE" "$FLOW" "$OUT" "${FILES[@]}" <<'EOF'
const { execFileSync } = require('node:child_process')
const fs = require('node:fs')
const [sha, date, flow, out, ...files] = process.argv.slice(2)
const paths = require('./claude-agent-sdk/ux-ideas-prompt.js').FLOW_PATHS[flow]
const git = (args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })

const code = files.map((path) => ({ path, content: git(['show', `${sha}:${path}`]) }))

// What had been done in this area in the six months before the snapshot, newest first.
const since = new Date(date + 'T00:00:00Z')
since.setMonth(since.getMonth() - 6)
const recentCommits = git(['log', '--no-merges', `--since=${since.toISOString().slice(0, 10)}`, `--until=${date}`, '--format=%cs %s', sha, '--', ...paths])
  .split('\n').filter(Boolean).slice(0, 60)

fs.writeFileSync(`${out}/brief.json`, JSON.stringify({ commit: sha, today: date, flow, count: 15, figures: '', surveys: '', tried: [], recentCommits, code }, null, 1))

// What was done afterwards: the ground truth the judges score against. Subject, body, stat and
// the first part of the diff, per commit, newest first.
const shas = git(['log', '--no-merges', '--format=%H', `${sha}..HEAD`, '--', ...paths]).split('\n').filter(Boolean)
const later = shas.map((h) => {
  const header = git(['show', '-s', '--format=%h%n%cs%n%s%n%b', h]).split('\n')
  const stat = git(['show', '--stat', '--format=', h, '--', ...paths])
  const diff = git(['show', '--format=', h, '--', ...paths])
  return {
    sha: header[0], date: header[1], subject: header[2], body: header.slice(3).join('\n').trim().slice(0, 1500),
    stat: stat.trim().slice(0, 800), diff: diff.slice(0, 3000),
  }
})
fs.writeFileSync(`${out}/truth.json`, JSON.stringify({ commit: sha, date, flow, later }, null, 1))
console.log(`${flow} at ${sha.slice(0, 9)} (${date}): ${code.length} files, ${recentCommits.length} commits before, ${later.length} commits after -> ${out}`)
EOF
