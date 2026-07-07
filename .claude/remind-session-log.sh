#!/bin/bash
# Hook: Remind about session log when skills are invoked
# Extracts the latest session log entry from CLAUDE.md

CLAUDE_MD="$CLAUDE_PROJECT_DIR/CLAUDE.md"

if [ -f "$CLAUDE_MD" ]; then
  # Extract the most recent dated session log entry (first ### 2026- heading + next 10 lines)
  LATEST=$(grep -A 10 -m 1 '^### 2026-' "$CLAUDE_MD")

  if [ -n "$LATEST" ]; then
    echo "⚠️ SESSION LOG — Read this before proceeding:"
    echo "$LATEST"
    echo ""
    grep '^\*\*Current task\*\*' "$CLAUDE_MD" 2>/dev/null || true
  fi
fi
