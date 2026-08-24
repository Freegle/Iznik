#!/bin/bash
# Stop hook: Detect when Claude is stopping with a summary that contains
# actionable work it could proceed with instead of stopping.

INPUT=$(cat)
LAST_MESSAGE=$(echo "$INPUT" | jq -r '.last_assistant_message // ""')
STOP_ACTIVE=$(echo "$INPUT" | jq -r '.stop_hook_active // false')

# Prevent infinite loops - only intervene once
if [ "$STOP_ACTIVE" = "true" ]; then
  exit 0
fi

# If message is short, it's probably just a direct answer, not a summary
if [ ${#LAST_MESSAGE} -lt 200 ]; then
  exit 0
fi

# Patterns that suggest Claude is listing next steps or work it could do
# rather than actually doing them.
CONTINUE_PATTERNS=(
  # Explicit next steps / future work
  '(### )?next step[s]*'
  'the next thing to do'
  'we (still )?need to'
  'I (still )?need to'
  'remains to be done'
  'still need[s]* to be'
  'TODO:?\s'
  'should (now|next|also|then)'
  'would (now|next|also|then) need to'
  # Offering to do work. Key off the OFFER PHRASE itself, not a hand-maintained
  # verb allowlist: the old list missed "shall I close", "want me to go in that
  # order", etc., which are exactly the stops we want to block. Any "shall I <verb>"
  # / "want me to <verb>" / "should I <verb>?" at the end of a turn is an offer to
  # do work we should just be doing.
  'shall I [a-z]'
  'should I [a-z][a-z]+'
  '(do you |would you )?want me to [a-z]'
  'would you like me to'
  'would you prefer'
  'I can (just )?(proceed|continue|go|do|fix|run|start|implement|update|add|create|write|close|handle|tackle|push|commit|open|merge|verify|check|investigate|boost|apply|wire|remove|delete|refactor|test|review|look|kick)'
  'let me know'
  "if you'?d like"
  # Choosing between options / asking which to do first
  'which .*(would you like|do you want|should I|first)'
  'in (that|which) order'
  # Summarising remaining work
  'remaining (work|tasks|items|steps)'
  'left to do'
  'here.*(what|the).*(remaining|left|next|outstanding)'
  'summary of (remaining|what|outstanding)'
  '(### )?Summary$'
)

MATCHED=""
for pattern in "${CONTINUE_PATTERNS[@]}"; do
  if echo "$LAST_MESSAGE" | grep -iqE "$pattern"; then
    MATCHED=$(echo "$LAST_MESSAGE" | grep -ioE "$pattern" | head -1)
    break
  fi
done

if [ -n "$MATCHED" ]; then
  cat <<EOF
{
  "decision": "block",
  "reason": "You're about to stop with actionable work in your summary (detected: '$MATCHED'). Don't summarise work you could do — just do it. If you genuinely need user input to proceed, ask a specific question. Otherwise, continue working."
}
EOF
  exit 0
fi

exit 0
