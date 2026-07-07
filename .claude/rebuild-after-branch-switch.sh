#!/bin/bash
# PostToolUse hook: After a git checkout/switch, rebuild dev containers
# so they run code from the new branch.

COMMAND=$(jq -r '.tool_input.command // ""' 2>/dev/null)

if echo "$COMMAND" | grep -qE 'git (checkout|switch)\b'; then
  # A branch switch just happened. Rebuild the containers that serve
  # code from the working directory.
  echo "Rebuilding containers after branch switch..." >&2

  cd /home/edward/FreegleDockerWSL

  # Rebuild and restart the key containers
  docker-compose build modtools-prod-local freegle-prod-local 2>/dev/null
  docker-compose up -d --force-recreate modtools-prod-local freegle-prod-local 2>/dev/null

  # Restart dev containers (they use file sync, just need a restart)
  docker restart modtools-dev-local freegle-dev-live 2>/dev/null

  # Restart the Go API container
  docker restart freegle-apiv2 2>/dev/null

  echo '{"hookSpecificOutput":{"hookEventName":"PostToolUse","additionalContext":"Containers rebuilt after branch switch. modtools-prod-local, freegle-prod-local rebuilt. modtools-dev-local, freegle-dev-live, freegle-apiv2 restarted."}}'
fi
