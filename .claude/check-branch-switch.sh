#!/bin/bash
# Hook: Warn before switching branches. Switching branches means you
# may end up reading, building, and testing code that is NOT the code
# you were asked to work on — leading to wrong conclusions and wasted time.

COMMAND=$(jq -r '.tool_input.command // ""' 2>/dev/null)

# Only warn on branch switches, not file restores (git checkout -- file)
if echo "$COMMAND" | grep -qE 'git (checkout|switch)\b' && ! echo "$COMMAND" | grep -qE 'git checkout\s+--\s'; then
  CURRENT=$(cd /home/edward/FreegleDockerWSL/iznik-nuxt3 && git branch --show-current 2>/dev/null)

  if [ -n "$CURRENT" ] && [ "$CURRENT" != "master" ]; then
    echo "{\"stopReason\":\"STOP: You are on branch '$CURRENT'. Switching branches means you will be reading, building, and testing DIFFERENT CODE than what you were asked to work on. This has repeatedly caused wasted effort. Explain why you need to switch before proceeding.\",\"continue\":false}"
    exit 0
  fi
fi
