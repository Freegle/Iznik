#!/bin/bash
# PreToolUse hook: guard the text that goes into a PR title/description.
#
# Why: a PR description is read by a reviewer who was not there. It should
# describe the END STATE of the branch - what it does, what it deliberately does
# not do, what the limits are - in language they can read at speed. It should not
# recount the order things were discovered in, must not carry counts inherited
# from an earlier version of the branch, and must not be written in the
# vocabulary of the code it describes.
#
# Fires on: gh pr create / gh pr edit / gh api ... /pulls/N (PATCH or POST).
#
# Four independent checks:
#   1. BODY IS READABLE   -> no override. See the note below; this one is the
#      reason the other three are worth anything.
#   2. PLAIN ENGLISH      -> override with PR_PLAIN_ENGLISH_OK=1
#   3. NARRATIVE phrasing -> override with ALLOW_PR_NARRATIVE=1 (should be rare;
#      if you are reaching for it, the sentence probably wants rewriting).
#   4. NUMERIC claims     -> override with PR_TEXT_VERIFIED=1, meaning "I have
#      re-derived each of these numbers from the branch as it is now". Stale
#      counts are the specific failure this catches: a description written when
#      the branch had 15 gates and 1 manifest, still saying so after it grew.
#
# ON FAILING CLOSED (check 1). This hook used to read the body only from a
# literal --body-file path. Two extremely common shapes defeated that and it
# passed silently, having inspected nothing:
#
#     gh pr create --body-file $SP/body.md         # path is a shell variable
#     gh api ... -f body="$(cat body.md)"          # body inlined by the shell
#
# The hook receives the command BEFORE the shell expands it, so in both cases it
# scanned the literal characters '$SP/body.md' or '$(cat body.md)' and found
# nothing to complain about. Every PR written that way went through unchecked,
# which is worse than having no hook at all - it looks like it passed. So when a
# body-bearing flag is present and the text cannot be resolved, this now blocks
# and asks for a form it can read.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null)
[ -z "$COMMAND" ] && exit 0

# Decide whether to fire from the command with heredoc BODIES stripped. A commit
# message or a doc routinely quotes an example PR command, and matching that
# fires this hook on a git commit - which it did, on the commit that added this.
TRIGGER=$(echo "$COMMAND" | awk '
  {
    if (tag != "") { if ($0 ~ ("^[[:space:]]*" tag "[[:space:]]*$")) tag = ""; next }
    line = $0
    if (match(line, /<<-?["'"'"'"]?[A-Za-z_][A-Za-z0-9_]*["'"'"'"]?/)) {
      t = substr(line, RSTART, RLENGTH)
      sub(/^<<-?/, "", t); gsub(/["'"'"'"]/, "", t)
      tag = t
    }
    print line
  }')

# Only fire for commands that set PR title/body.
echo "$TRIGGER" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+(create|edit)\b|\bgh[[:space:]]+api\b[^|]*\bpulls?/[0-9]+' || exit 0
# gh pr edit that only changes labels/reviewers/base has no text to check.
if echo "$TRIGGER" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+edit\b' \
   && ! echo "$TRIGGER" | grep -qE '\-\-(title|body|body-file)\b'; then
  exit 0
fi
# Reading a PR is not writing one.
echo "$TRIGGER" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+view\b' && exit 0

# --------------------------------------------------------------------------
# Resolve the body text
# --------------------------------------------------------------------------
TEXT="$COMMAND"
BODY_RESOLVED=0
UNREADABLE=""

add_file() {
  local f="$1"
  # Strip one layer of surrounding quotes.
  f="${f%\"}"; f="${f#\"}"; f="${f%\'}"; f="${f#\'}"
  [ -z "$f" ] && return
  if [ -f "$f" ]; then
    TEXT="$TEXT
$(cat "$f")"
    BODY_RESOLVED=1
  else
    UNREADABLE="$UNREADABLE $f"
  fi
}

# --body-file PATH | --body-file=PATH | -F body=@PATH | -f body=@PATH
while IFS= read -r f; do
  [ -n "$f" ] && add_file "$f"
done < <(echo "$COMMAND" \
  | grep -oE '(--body-file[=[:space:]]+|-[fF][[:space:]]+body=@)[^[:space:]]+' \
  | sed -E 's/^(--body-file[=[:space:]]+|-[fF][[:space:]]+body=@)//')

# -f body="$(cat PATH)" / --field body="$(cat PATH)" - the shell would expand
# this, but the hook sees it raw, so dig the path out of the substitution.
while IFS= read -r f; do
  [ -n "$f" ] && add_file "$f"
done < <(echo "$COMMAND" | grep -oE '\$\((cat|<)[[:space:]]+[^)]+\)' \
  | sed -E 's/^\$\((cat|<)[[:space:]]+//; s/\)$//')

# An inline body written straight into the command is already in TEXT.
if echo "$COMMAND" | grep -qE '\-\-body[[:space:]]+["'"'"']' \
   || echo "$COMMAND" | grep -qE '\-[fF][[:space:]]+body=["'"'"']' ; then
  BODY_RESOLVED=1
fi
# A heredoc body is in the command text too.
echo "$COMMAND" | grep -qE '<<[-]?['"'"'"]?(EOF|MSG|BODY|PR)' && BODY_RESOLVED=1

HAS_BODY_FLAG=0
echo "$COMMAND" | grep -qE '\-\-body(-file)?\b|\-[fF][[:space:]]+body=' && HAS_BODY_FLAG=1

if [ "$HAS_BODY_FLAG" = "1" ] && [ "$BODY_RESOLVED" = "0" ]; then
  {
    echo "STOP. This command sets a PR body, but the hook cannot read it, so none of"
    echo "the PR text checks can run."
    [ -n "$UNREADABLE" ] && echo "" && echo "  could not open:$UNREADABLE"
    echo ""
    echo "The hook sees the command BEFORE the shell expands it, so a path built from a"
    echo "variable (--body-file \$SP/body.md) or a body inlined by the shell"
    echo "(-f body=\"\$(cat body.md)\") both arrive here as literal text with no content"
    echo "behind them. Passing silently in that case is worse than no hook at all."
    echo ""
    echo "Use a form the hook can read:"
    echo "  gh pr create --body-file /absolute/path/to/body.md"
    echo "  gh api -X PATCH repos/O/R/pulls/N -F body=@/absolute/path/to/body.md"
  } >&2
  exit 2
fi

# --------------------------------------------------------------------------
# Prose under review: drop fenced code blocks and inline code spans.
# Pasted command output and identifiers in backticks are evidence, not writing,
# and holding them to prose rules produces exactly the noise that gets a hook
# switched off.
# --------------------------------------------------------------------------
PROSE=$(echo "$TEXT" | awk '
  /^[[:space:]]*```/ { infence = !infence; next }
  infence { next }
  # A markdown table row is structured reference rather than writing. A glossary
  # of "instead of X, say Y" necessarily contains X, and flagging it would make
  # the hook unable to describe itself.
  /^[[:space:]]*\|/ { next }
  { print }
' | sed -E 's/`[^`]*`//g')

FAILED=0

# --- Check 2: plain English ------------------------------------------------
# Terms lifted from the code, the runbook or the incident channel. Each is fine
# in a comment next to the thing it names; in a PR description it makes the
# reader stop and translate. The replacement is the point - if a term genuinely
# carries meaning nothing else does, say it once in plain words and then use it.
declare -a JARGON_TERMS=(
  'single[- ]flight'      'callers asking the same question at once share one answer'
  'fan[- ]out'            'how many calls this makes at once'
  'no[- ]op(s| write| update|ped)?' 'a write that changes nothing'
  're-?upsert'            'writing the same row back'
  'upsert'                'insert or update'
  'watermark'             'where we got to last time'
  'delta set'             'the things that changed'
  '\bseam\b'              'the one place that does X'
  'fail[- ]open'          'when in doubt it does the full work'
  'shed load'             'turn work away'
  'UX class'              'what a member sees'
  'row images'            'the changes copied to every database node'
  'backstop'              'safety net'
  'native[- ]distinct'    'counted once per group it was posted to'
  'sargable'              'able to use the index'
  'thundering herd'       'everyone retrying at once'
  'tail latency'          'the slowest requests'
  '\bp(50|95|99)\b'       'the slowest N% of requests'
  'Dijkstra'              'a route search'
  'kswapd'                'the kernel reclaiming memory'
  'goroutine'             'a concurrent request'
  'semaphore'             'a cap on how many run at once'
  'memoi[sz]e'            'remember the answer'
  'idempotent'            'safe to run twice'
  'hot path'              'the code every request goes through'
  'cardinality'           'how many distinct values'
  '\bTTL\b'               'how long the answer is kept'
  '\bcursors?\b'          'where we got to last time'
  '\bdecrements?\b'       'the count going down'
  '\bincrements?\b'       'the count going up'
  'primary[- ]key range'  'a short run of consecutive rows'
  'cron move'             'running it at a different time'
)
JARGON_HITS=""
for ((i=0; i<${#JARGON_TERMS[@]}; i+=2)); do
  term="${JARGON_TERMS[$i]}"; plain="${JARGON_TERMS[$i+1]}"
  if echo "$PROSE" | grep -qiE "$term"; then
    example=$(echo "$PROSE" | grep -ioE ".{0,45}${term}.{0,45}" | head -1)
    JARGON_HITS="$JARGON_HITS
  $example
     -> try: $plain"
  fi
done

# Deliberately NOT checked: bare (un-backticked) code identifiers in prose. It
# sounds like a good rule and it is not. Measured across 25 merged descriptions
# plus the two worst offenders this hook was written for, the highest count in
# any body was ONE, and 24 of 27 had none - people here already backtick their
# identifiers. Every hit it produced was incidental (localStorage, versionCode,
# max_minutes), so at any threshold it was all false positives and no signal.
#
# The related failure it CANNOT catch is an ordinary English word used as a term
# of art - "the walkers' deadline", where "walkers" names something in
# dashboard.go. No pattern distinguishes that from normal prose; it needs a
# reader.
#
# Telegraphic list items: a bullet that ends in a semicolon is a fragment with
# the verb removed, and a description written that way has to be reassembled
# into claims before it can be reviewed.
FRAGMENTS=$(echo "$PROSE" | grep -nE '^[[:space:]]*[-*][[:space:]]+.*;[[:space:]]*$' \
  | awk 'length($0) <= 100' | head -4)

if { [ -n "$JARGON_HITS" ] || [ -n "$FRAGMENTS" ]; } \
   && ! echo "$COMMAND" | grep -qE '\bPR_PLAIN_ENGLISH_OK=1\b' && [ "${PR_PLAIN_ENGLISH_OK:-}" != "1" ]; then
  {
    echo "STOP. This PR text is not in plain English."
    echo ""
    echo "It is read by someone who has not read the branch. Every term they have to"
    echo "translate is a term you could have translated for them once."
    if [ -n "$JARGON_HITS" ]; then
      echo ""
      echo "Jargon that needs saying in words:"
      echo "$JARGON_HITS" | grep -v '^$'
    fi
    if [ -n "$FRAGMENTS" ]; then
      echo ""
      echo "List items ending in ';' - these are fragments with the verb taken out."
      echo "Write each as a sentence that makes a claim:"
      echo "$FRAGMENTS" | sed 's/^/  /'
    fi
    echo ""
    echo "Fenced code blocks and \`backticked\` spans are exempt - paste output freely."
    echo ""
    echo "If a term really is the clearest word available, prefix with"
    echo "PR_PLAIN_ENGLISH_OK=1 - but prefer explaining it once in the text."
  } >&2
  FAILED=1
fi

# --- Check 3: narrative / process-diary phrasing ---------------------------
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
  hit=$(echo "$PROSE" | grep -inE "$p" | head -2)
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

# --- Check 4: numeric claims that may be stale -----------------------------
# Counts are the thing that silently rots when a branch grows after the
# description was written.
CLAIMS=$(echo "$PROSE" | grep -oiE '\b[0-9][0-9,]* (sites?|tests?|gates?|assertions?|files?|commits?|conversions?|passing|passed|failing|errors?)\b' \
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

# --- Check 5: AI attribution ------------------------------------------------
if echo "$TEXT" | grep -qiE 'Generated with \[?Claude Code|🤖 Generated with'; then
  {
    echo "STOP. PR descriptions here do not carry AI attribution footers."
    echo "Remove the 'Generated with Claude Code' block. (Commit message trailers are"
    echo "a separate thing and are fine.)"
  } >&2
  FAILED=1
fi

[ "$FAILED" = "1" ] && exit 2
exit 0
