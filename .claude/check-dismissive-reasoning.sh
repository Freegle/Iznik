#!/bin/bash
# PreToolUse hook: catch dismissive language ("unrelated", "pre-existing", and
# kin) in Claude's reasoning MID-TURN. The existing project Stop hook
# (check-dismissive-language.sh) only fires at turn end, reading the final
# message — so a dismissal made mid-turn (in a response that then calls a tool)
# slips through. This scans the LATEST assistant message (text + thinking) from
# the transcript before each tool call; on a match it blocks the call and
# reminds Claude to investigate/fix rather than wave the problem away.
#
# Registered in the Freegle repo (.claude/settings.json, PreToolUse matcher "*"), alongside
# its turn-end sibling check-dismissive-language.sh.

INPUT=$(cat 2>/dev/null)
TRANS=$(printf '%s' "$INPUT" | jq -r '.transcript_path // empty' 2>/dev/null)
[ -z "$TRANS" ] && exit 0
[ ! -f "$TRANS" ] && exit 0

# Pull the most recent assistant message's text + thinking. Only the tail is read
# so this stays fast (PreToolUse runs before every tool call).
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

# Don't fire while discussing the hook itself or dismissive-language detection -
# e.g. listing the trigger words, explaining a false positive. Otherwise the
# guardrail cries wolf every time it is described, which trains it to be ignored.
if printf '%s' "$MSG" | grep -iqE "dismissive|check-dismissive|this hook|the hook|a hook to|guardrail|self-monitor|reasoning hook|stop hook|pretooluse hook|trigger word|false positive|cry wolf|literal (word|string)|meta-exclusion|exclusion (list|regex|pattern)"; then
  exit 0
fi

# Dismissive patterns. "unrelated" / "pre-existing" the user explicitly named;
# the contextual forms catch the same move when phrased around a problem so
# benign uses ("two unrelated features") don't trip it.
PATTERNS=(
  '\bpre-?existing\b'
  '\bpre existing\b'
  'unrelated.{0,40}(fail|test|error|bug|hang|issue|change|broken|flak|warning)'
  '(fail|test|error|bug|hang|issue|broken|flak|warning).{0,40}unrelated'
  '(fail|failing|failure|broke|broken|crash|error|red mark).{0,70}not (my|our|related to (my|our|the|this)) (change|code|commit|pr|branch|fault|problem|bug)'
  'not (my|our|related to (my|our|the|this)) (change|code|commit|pr|branch|fault|problem|bug).{0,70}(fail|failing|failure|broke|broken|crash|error|red mark)'
  '(fail|failing|failure|broke|broken|crash|error).{0,70}nothing to do with (my|our|this|the|what)'
  'nothing to do with (my|our|this|the|what).{0,70}(fail|failing|failure|broke|broken|crash|error)'
  'known (issue|failure|flak|bug).{0,40}(ignore|skip|move|proceed|leave)'
  'already (failing|broken|flaky)( before)?'
  '\b(out of|beyond) (the )?scope\b'
  '(environmental|infra(structure)?|transient) (issue|flake|failure|hang)'
)

for p in "${PATTERNS[@]}"; do
  if printf '%s' "$MSG" | grep -iqE "$p" 2>/dev/null; then
    HIT=$(printf '%s' "$MSG" | grep -ioE "$p" 2>/dev/null | head -1)
    echo "STOP — dismissive reasoning detected mid-turn: \"$HIT\". All bugs need fixes; calling a failure/bug 'unrelated', 'pre-existing', 'flaky', 'environmental', or 'out of scope' is cheating. Find the root cause and FIX it, or prove the dismissal with concrete evidence (a stack trace, a git blame, a passing run on a clean tree) before continuing." >&2
    exit 2
  fi
done

exit 0
