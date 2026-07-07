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
  # Offering to do work
  'shall I (proceed|continue|go ahead|do|fix|run|start|implement|update|add|create|write)'
  'want me to (proceed|continue|go ahead|do|fix|run|start|implement|update|add|create|write)'
  'would you like me to'
  'I can (proceed|continue|go ahead|do this|fix|run|start|implement|update|add|create|write)'
  'let me know if you.*(want|need|like)'
  "if you'?d like.*(I can|we can)"
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
