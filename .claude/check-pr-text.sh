#!/bin/bash
# PreToolUse hook: block PR titles/descriptions that read as a NARRATIVE of how
# the work happened, or that assert numbers nobody has re-checked.
#
# Why: a PR description is read by a reviewer who was not there. It should
# describe the END STATE of the branch - what it does, what it deliberately does
# not do, what the limits are. It should not recount the order things were
# discovered in, and it must not carry counts inherited from an earlier version
# of the branch. Both failures look fine to the person who wrote them and waste
# the reviewer's time or, worse, mislead them.
#
# Fires on: gh pr create / gh pr edit / gh api ... /pulls/N (PATCH or POST).
#
# Two independent checks:
#   1. NARRATIVE phrasing  -> override with ALLOW_PR_NARRATIVE=1 (should be rare;
#      if you are reaching for it, the sentence probably wants rewriting).
#   2. NUMERIC claims      -> override with PR_TEXT_VERIFIED=1, meaning "I have
#      re-derived each of these numbers from the branch as it is now". Stale
#      counts are the specific failure this catches: a description written when
#      the branch had 15 gates and 1 manifest, still saying so after it grew.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null)
[ -z "$COMMAND" ] && exit 0

# Only fire for commands that set PR title/body.
echo "$COMMAND" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+(create|edit)\b|\bgh[[:space:]]+api\b[^|]*\bpulls?/[0-9]+' || exit 0
# gh pr edit that only changes labels/reviewers/base has no text to check.
if echo "$COMMAND" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+edit\b' \
   && ! echo "$COMMAND" | grep -qE '\-\-(title|body|body-file)\b'; then
  exit 0
fi

# Assemble the text under review: the command itself (catches inline --title/
# --body) plus the contents of any file passed by --body-file or -F body=@file.
TEXT="$COMMAND"
for f in $(echo "$COMMAND" | grep -oE '(--body-file[= ]|-F[[:space:]]+body=@|--body-file[[:space:]]+)[^[:space:]]+' \
           | sed -E 's/^(--body-file[= ]|-F[[:space:]]+body=@|--body-file[[:space:]]+)//' | tr -d '"'"'"''); do
  [ -f "$f" ] && TEXT="$TEXT
$(cat "$f")"
done

FAILED=0

# --- Check 1: narrative / process-diary phrasing ---------------------------
# Each pattern describes the WRITING PROCESS or the order of discovery rather
# than the state of the branch.
NARRATIVE_PATTERNS=(
  '\b(I|we) (then|also then|next|initially|originally|first|eventually|finally) '
  '\bthen (I|we) '
  '\b(it |as it )?turn(s|ed) out\b'
  '\b(initially|originally|at first|to begin with|in the end|after that|subsequently|along the way)\b'
  '\b(I|we) (discovered|noticed|realised|realized|found that|had to|ended up|spent|tried|attempted)\b'
  '\b(this session|tonight|earlier today|this evening|so far today)\b'
  '\b(first|second|third|another) attempt\b'
  '\b(went|was|going) red\b'
  '\btook (the|this) (PR|branch|build) red\b'
  '\b(broke|broken) (CI|the build)\b'
  '\bwhile (doing|working on) this\b'
  '\bduring (this|the) (work|session|investigation)\b'
  '\bnote to self\b'
  '\bas (it happens|mentioned above, )'
)
NARRATIVE_HITS=""
for p in "${NARRATIVE_PATTERNS[@]}"; do
  hit=$(echo "$TEXT" | grep -inE "$p" | head -2)
  [ -n "$hit" ] && NARRATIVE_HITS="$NARRATIVE_HITS
$hit"
done

if [ -n "$NARRATIVE_HITS" ] \
   && ! echo "$COMMAND" | grep -qE '\bALLOW_PR_NARRATIVE=1\b' && [ "${ALLOW_PR_NARRATIVE:-}" != "1" ]; then
  {
    echo "STOP. This PR text reads as a narrative of how the work happened."
    echo ""
    echo "$NARRATIVE_HITS" | grep -v '^$' | head -12
    echo ""
    echo "A reviewer was not there and does not need the order of events. Describe the"
    echo "END STATE: what the branch does, what it deliberately does not do, the known"
    echo "limits, and how to verify it. Cut anything that only makes sense as a story."
    echo ""
    echo "If a phrase is genuinely descriptive rather than narrative, prefix with"
    echo "ALLOW_PR_NARRATIVE=1 - but prefer rewriting the sentence."
  } >&2
  FAILED=1
fi

# --- Check 2: numeric claims that may be stale -----------------------------
# Counts are the thing that silently rots when a branch grows after the
# description was written.
CLAIMS=$(echo "$TEXT" | grep -oiE '\b[0-9][0-9,]* (sites?|tests?|gates?|assertions?|files?|commits?|conversions?|passing|passed|failing|errors?)\b' \
         | sort -u | head -20)

if [ -n "$CLAIMS" ] \
   && ! echo "$COMMAND" | grep -qE '\bPR_TEXT_VERIFIED=1\b' && [ "${PR_TEXT_VERIFIED:-}" != "1" ]; then
  {
    echo "STOP. This PR text asserts counts. Re-derive each from the branch AS IT IS NOW,"
    echo "not from an earlier draft or an earlier state of the branch:"
    echo ""
    echo "$CLAIMS" | sed 's/^/  /'
    echo ""
    echo "Stale numbers are the common failure here: a description written when the"
    echo "branch was smaller keeps asserting the old totals after it grows, and the"
    echo "reviewer has no way to tell."
    echo ""
    echo "Check them against the manifest / test output / git, then prefix the command"
    echo "with PR_TEXT_VERIFIED=1 to confirm you have done so."
  } >&2
  FAILED=1
fi

[ "$FAILED" = "1" ] && exit 2
exit 0
