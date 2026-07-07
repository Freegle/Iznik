#!/bin/bash
set -e

echo "=== AI Support Helper Container Starting ==="
echo "Running as user: $(whoami)"

# Check for Anthropic API key (optional - server handles missing key gracefully)
if [ -z "$ANTHROPIC_API_KEY" ]; then
  echo "WARNING: ANTHROPIC_API_KEY not set - AI features will be disabled"
  echo "Add ANTHROPIC_API_KEY to your .env file to enable AI support"
else
  echo "Anthropic API key configured"
fi

echo "=== Starting Node.js server ==="
exec node server.js
