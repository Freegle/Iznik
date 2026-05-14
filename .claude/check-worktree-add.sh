#!/bin/bash
# Hook: Block bare `git worktree add` — use ./freegle worktree create instead.
# The freegle command assigns unique port offsets, sets LOKI_VOLUME, and starts
# containers automatically. A bare `git worktree add` skips all of that and
# leaves the worktree with a broken .env (or no .env), causing port conflicts
# and container startup failures.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""' 2>/dev/null)

if echo "$COMMAND" | grep -qE '\bgit\b.*\bworktree\s+add\b'; then
  echo "BLOCKED: Use ./freegle worktree create <name> [branch] instead of git worktree add." >&2
  echo "" >&2
  echo "  ./freegle worktree create <name>          # creates from HEAD" >&2
  echo "  ./freegle worktree create <name> <branch> # creates from a specific branch" >&2
  echo "" >&2
  echo "The freegle command assigns unique port offsets (+5000 per slot), sets" >&2
  echo "LOKI_VOLUME, and starts containers automatically. A bare git worktree add" >&2
  echo "skips all of that and produces port conflicts or broken containers." >&2
  exit 2
fi
