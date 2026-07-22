#!/bin/bash
set -e

echo "=== AI Support Helper Container Starting ==="
echo "Running as user: $(whoami)"

# Claude auth has three modes (same query() code path):
#   subscription - CLAUDE_CODE_OAUTH_TOKEN from `claude setup-token` (headless Max/Pro sub)
#   api          - only ANTHROPIC_API_KEY set (metered API billing; production, on edge)
#   session      - a read-only ~/.claude mount (this logged-in Claude session, for testing)
#
# CLAUDE_CODE_OAUTH_TOKEN wins: the subscription is preferred over metered API spend for this
# headless job. The SDK/CLI bills the API whenever ANTHROPIC_API_KEY is set, so we unset it
# when a token is present (as monitor-fsm/run-loop.sh does) to make the subscription be used.
if [ -n "$CLAUDE_CODE_OAUTH_TOKEN" ]; then
  unset ANTHROPIC_API_KEY
  echo "Claude auth: subscription token from 'claude setup-token' (subscription mode)"
elif [ -n "$ANTHROPIC_API_KEY" ]; then
  echo "Claude auth: API key (api mode)"
elif [ -n "$(ls -A "$HOME/.claude" 2>/dev/null)" ]; then
  echo "Claude auth: mounted ~/.claude session (session mode)"
else
  echo "WARNING: no ANTHROPIC_API_KEY, CLAUDE_CODE_OAUTH_TOKEN, or ~/.claude session - AI features disabled"
fi

echo "=== Starting Node.js server ==="
exec node server.js
