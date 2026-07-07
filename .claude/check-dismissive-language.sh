#!/bin/bash
# Stop hook: Detect dismissive language about test failures.
# Forces Claude to investigate and fix failures instead of dismissing them.
#
# DESIGN PHILOSOPHY: Claude rephrases to avoid exact patterns. So we match
# broadly on the REASONING STRUCTURE, not just specific words:
#   1. Exact dismissive phrases (pre-existing, known flaky, etc.)
#   2. Attribution away from self (not my/our, someone else's, different component)
#   3. Deferral language (later, separate, for now, move on)
#   4. Failure + pivot pattern (mentions failures then immediately does other work)

INPUT=$(cat)
LAST_MESSAGE=$(echo "$INPUT" | jq -r '.last_assistant_message // ""')
STOP_ACTIVE=$(echo "$INPUT" | jq -r '.stop_hook_active // false')

# Prevent infinite loops - only intervene once
if [ "$STOP_ACTIVE" = "true" ]; then
  exit 0
fi

# If message is empty or very short, skip
if [ ${#LAST_MESSAGE} -lt 20 ]; then
  exit 0
fi

# Skip meta-discussion about the hook itself (e.g. explaining what patterns it catches)
if echo "$LAST_MESSAGE" | grep -iqE "(the |this |our |stop )hook.{0,30}(catch|detect|match|block|trigger|check)|check-dismissive-language|LAYER [0-9]:|Layer [0-9]"; then
  exit 0
fi

# ── LAYER 1: Exact dismissive phrases (original patterns) ──────────────
DISMISSIVE_PATTERNS=(
  'pre-existing'
  'pre existing'
  'already fail'
  'already broken'
  'already known.*(fail|test|error|bug)'
  'not caused by.*(fail|test|error)'
  'not related to.*(fail|test|error|change)'
  'unrelated.*(fail|test|error)'
  '(fail|test|error).*unrelated'
  'known (issue|failure|flak)'
  'skip.*(fail|test)'
  'ignore.*(fail|test)'
  'not our (fault|problem|issue)'
  'nothing to do with'
  'beyond the scope'
  'out of scope.*(test|fail)'
  'can be addressed later'
  'separate issue.*(test|fail|error)'
  'existing (bug|issue|problem|failure)'
  'was already (fail|broken|known|passing|flak)'
  'were already (fail|broken|known|passing|flak)'
)

# ── LAYER 2: Attribution / distancing from failures ─────────────────────
# Catches: "not related to my changes", "these are failures in PostMessage",
# "these 3 failures are all X tests", "nothing I changed", etc.
ATTRIBUTION_PATTERNS=(
  'not related to (my|our|the|this|these) change'
  'not related to (what|anything) (I|we)'
  'not caused by (my|our|the|this|these) change'
  'not caused by (what|anything) (I|we)'
  "(fail|error|broken).{0,60}not.{0,20}(my|our) (change|work|code|commit|pr|branch)"
  "(these|those|the) [0-9]+ (fail|error).{0,40}(are|were) (all|both|just|only)"
  "(these|those|the) (fail|error).{0,30}(in|from|of) (PostMessage|MessageEdit|ChatHeader|Mod[A-Z]).{0,60}(let me|moving on|proceed|kick off|run|now)"
  "(didn.t|did not|don.t|do not) (break|cause|introduce|touch)"
  "(I|we) (didn.t|did not) (change|modify|touch|edit).{0,40}(that|those|these|the) (test|file|component)"
  "nothing (I|we) (changed|did|touched|modified)"
  "(my|our) changes (don.t|didn.t|do not|did not) (affect|touch|modify)"
)

# ── LAYER 3: Deferral / moving-on language near failure context ─────────
DEFERRAL_PATTERNS=(
  "(fail|error|broken).{0,80}(for now|move on|moving on|proceed|continue|later|separate|track|parking|backlog)"
  "(for now|move on|moving on|proceed|continue).{0,80}(fail|error|broken)"
  "(fail|error).{0,60}(will|can|should) (be )?(address|fix|investigate|look).{0,20}(later|separate|another|next)"
  "let.s (move on|proceed|continue|kick off|start).{0,40}(fail|error|broken)"
  "(fail|error|broken).{0,40}let.s (move on|proceed|continue|kick off|start)"
)

# ── LAYER 4: Failure + pivot pattern ────────────────────────────────────
# Detects structure: "N failures ... let me now [do something else]"
# This catches the reasoning pattern regardless of what words are used to
# explain the failures away.
# IMPORTANT: exclude negated contexts ("no failures", "0 failed", "all passed")
PIVOT_PATTERNS=(
  "[1-9][0-9]* (fail|error|broken).{0,120}(let me now|now (let me|I.ll|kick off|start|run|move))"
  "[1-9][0-9]* (fail|error|broken).{0,120}(moving on to|proceeding with|switching to|turning to)"
  "(fail|error|broken).{0,20}(in|from|of) [A-Z].{0,100}(let me now|now (let me|I.ll|kick off|start|run|move))"
)

check_patterns() {
  local label="$1"
  shift
  local patterns=("$@")
  for pattern in "${patterns[@]}"; do
    if echo "$LAST_MESSAGE" | grep -iqP "$pattern" 2>/dev/null || echo "$LAST_MESSAGE" | grep -iqE "$pattern" 2>/dev/null; then
      local matched
      matched=$(echo "$LAST_MESSAGE" | grep -ioP "$pattern" 2>/dev/null | head -1)
      if [ -z "$matched" ]; then
        matched=$(echo "$LAST_MESSAGE" | grep -ioE "$pattern" 2>/dev/null | head -1)
      fi
      echo "$label: $matched"
      return 0
    fi
  done
  return 1
}

RESULT=""
RESULT=$(check_patterns "Dismissive phrase" "${DISMISSIVE_PATTERNS[@]}") ||
RESULT=$(check_patterns "Attribution away from self" "${ATTRIBUTION_PATTERNS[@]}") ||
RESULT=$(check_patterns "Deferral of failures" "${DEFERRAL_PATTERNS[@]}") ||
RESULT=$(check_patterns "Failure then pivot" "${PIVOT_PATTERNS[@]}") ||
true

if [ -n "$RESULT" ]; then
  cat <<EOF
{
  "decision": "block",
  "reason": "STOP. $RESULT. Per CLAUDE.md: NEVER dismiss test failures. ALL test failures must be investigated and fixed — even ones that look unrelated to your changes. Go back and: 1) Identify the actual root cause of each failing test. 2) Fix each failure. 3) Verify the fixes. Do not proceed to other work until all tests pass."
}
EOF
  exit 0
fi

exit 0
