#!/bin/bash
# Hook: block any curl POST to the Discourse site.
# Discourse replies must only be posted manually by the user — never by Claude.
# The URL is read from DISCOURSE_URL in .env (default: community.ilovefreegle.org).

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')

if [ -z "$COMMAND" ]; then
  exit 0
fi

# Load DISCOURSE_URL from .env if not already set.
if [ -z "$DISCOURSE_URL" ]; then
  ENV_FILE="$(dirname "$0")/../../.env"
  if [ -f "$ENV_FILE" ]; then
    DISCOURSE_URL=$(grep -E '^DISCOURSE_URL=' "$ENV_FILE" | cut -d= -f2-)
  fi
fi
# Strip protocol for hostname matching.
DISCOURSE_HOST=$(echo "${DISCOURSE_URL:-community.ilovefreegle.org}" | sed 's|https\?://||')

# Block curl -X POST (or --request POST) to the Discourse site.
if echo "$COMMAND" | grep -qiE '\bcurl\b' && \
   echo "$COMMAND" | grep -qiE '(-X\s*POST|--request\s*POST)' && \
   echo "$COMMAND" | grep -qi "$DISCOURSE_HOST"; then
  echo "STOP. Never post to Discourse directly (${DISCOURSE_HOST})." >&2
  echo "" >&2
  echo "Compose the reply text and show it to the user." >&2
  echo "The user will paste and post it manually." >&2
  exit 2
fi

exit 0
