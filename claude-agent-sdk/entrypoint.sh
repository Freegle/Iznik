#!/bin/bash
set -e

echo "=== AI Support Helper Container Starting ==="
echo "Running as user: $(whoami)"

# Claude auth has three modes (same query() code path):
#   api          - ANTHROPIC_API_KEY set (production, on edge)
#   subscription - CLAUDE_CODE_OAUTH_TOKEN from `claude setup-token` (headless Max/Pro sub)
#   session      - a read-only ~/.claude mount (this logged-in Claude session, for testing)
if [ -n "$ANTHROPIC_API_KEY" ]; then
  echo "Claude auth: API key (api mode)"
elif [ -n "$CLAUDE_CODE_OAUTH_TOKEN" ]; then
  echo "Claude auth: subscription token from 'claude setup-token' (subscription mode)"
elif [ -n "$(ls -A "$HOME/.claude" 2>/dev/null)" ]; then
  echo "Claude auth: mounted ~/.claude session (session mode)"
else
  echo "WARNING: no ANTHROPIC_API_KEY, CLAUDE_CODE_OAUTH_TOKEN, or ~/.claude session - AI features disabled"
fi

echo "=== Starting Node.js server ==="
exec node server.js
