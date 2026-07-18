#!/bin/bash
# PreToolUse hook: catch Claude blaming READ REPLICATION / replica lag as the
# cause of a bug MID-TURN. Freegle runs on a Galera cluster (virtually-
# synchronous replication), so "a read hit a stale replica" is an extremely
# unlikely explanation — and it is essentially impossible for static reference
# data (locations, groups, config) that exists identically on every node.
# Reaching for replica lag is a recurring lazy diagnosis; this scans the latest
# assistant message (text + thinking) before each tool call and, on a match that
# is NOT a negation/acknowledgement, blocks and prompts for the real cause.
#
# Registered in the Freegle repo (.claude/settings.json, PreToolUse matcher "*") - it is
# Freegle-Galera-specific, so it belongs with the project, not personal config.

INPUT=$(cat 2>/dev/null)
TRANS=$(printf '%s' "$INPUT" | jq -r '.transcript_path // empty' 2>/dev/null)
[ -z "$TRANS" ] && exit 0
[ ! -f "$TRANS" ] && exit 0

MSG=$(tail -n 80 "$TRANS" 2>/dev/null | tac | python3 -c "
import sys, json
for line in sys.stdin:
    try:
        o = json.loads(line)
    except Exception:
        continue
    m = o.get('message', {})
    if isinstance(m, dict) and m.get('role') == 'assistant':
        parts = []
        for b in (m.get('content') or []):
            if isinstance(b, dict):
                parts.append(b.get('text') or b.get('thinking') or '')
        print(' '.join(parts))
        break
" 2>/dev/null)

[ "${#MSG}" -lt 15 ] && exit 0

# Meta-exclusion: don't fire when discussing this hook, or when the reasoning is
# ALREADY ruling replication OUT / acknowledging Galera. These are the correct
# moves the hook exists to encourage, so they must not trip it.
if printf '%s' "$MSG" | grep -iqE "check-replication|replication (hook|guard|excuse)|this hook|the hook|a hook to|guardrail|trigger word|false positive|galera|virtually.?synchronous|synchronous replication|not (a )?(read )?replica|isn.?t (a )?(read )?replica|not replication|isn.?t replication|rule out replica|ruled out replica|drop(ping)? the replica|without the replica|replica.{0,20}unlikely|unlikely.{0,20}replica|static reference data|on every node|present on every|not a (timing|race)"; then
  exit 0
fi

# Replication-blame patterns: invoking replica lag / read-replica staleness as a
# CAUSE. Legitimate read/write-split bugs exist ONLY for freshly-written rows
# (e.g. a just-INSERTed id) — if that is genuinely the case, say so and cite the
# write, which the exclusions above allow; otherwise this is the lazy excuse.
PATTERNS=(
  'repl(ica|ication) (lag|delay|latenc)'
  'read.?replica.{0,45}(miss|lag|stale|behind|not yet|hadn.?t|didn.?t|old|absent|missing|return)'
  '(miss|lag|stale|behind|not yet|hadn.?t|didn.?t|absent|missing).{0,45}read.?replica'
  'routed to (a |the )?(read )?replica'
  'stale (read|replica|read.?replica)'
  'replica.{0,30}(behind|caught up|not yet|hadn.?t applied|didn.?t have|missing|lag)'
  'eventual(ly)? consisten'
  '(read/write|read-write|r/w) split.{0,45}(race|hazard|lag|miss|stale|caused|explain|left|silently|dropped)'
  '(routed|sent|went) to (a |the )?replica'
  'replica (that )?(missed|lacked|didn.?t have|hadn.?t)'
)

for p in "${PATTERNS[@]}"; do
  if printf '%s' "$MSG" | grep -iqE "$p" 2>/dev/null; then
    HIT=$(printf '%s' "$MSG" | grep -ioE "$p" 2>/dev/null | head -1)
    echo "STOP — you are blaming read replication: \"$HIT\". Freegle runs a Galera cluster (virtually-synchronous), so replica lag is extremely unlikely, and impossible for static reference data (locations, groups, config) that is identical on every node. This is a recurring lazy diagnosis. Either PROVE it — the data is freshly written (name the INSERT/UPDATE it races) and cite concrete evidence — or find the actual root cause (a code path, an unchecked error, a missing lookup) before continuing." >&2
    exit 2
  fi
done

exit 0
