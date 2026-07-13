#!/bin/bash
set -e

echo "=== AI Support Helper Container Starting ==="
echo "Running as user: $(whoami)"

# Claude auth has two modes (same query() code path either way):
#   api     - ANTHROPIC_API_KEY set (production, on edge)
#   session - a read-only ~/.claude mount (this logged-in Claude session, for testing)
if [ -n "$ANTHROPIC_API_KEY" ]; then
  echo "Claude auth: API key (api mode)"
elif [ -n "$(ls -A "$HOME/.claude" 2>/dev/null)" ]; then
  echo "Claude auth: mounted ~/.claude session (session mode)"
else
  echo "WARNING: no ANTHROPIC_API_KEY and no ~/.claude session mounted - AI features disabled"
fi

echo "=== Starting Node.js server ==="
exec node server.js
