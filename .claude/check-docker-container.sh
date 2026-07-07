#!/bin/bash
# PreToolUse hook: warn when docker exec targets a container from a different worktree.
#
# Reads JSON from stdin (Claude Code hook format), extracts the Bash command,
# and checks that any "docker exec <container>" call matches the current
# worktree's COMPOSE_PROJECT_NAME prefix.

set -euo pipefail

# Read stdin
INPUT=$(cat)

# Only care about Bash tool calls
TOOL_NAME=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_name',''))" 2>/dev/null || true)
if [[ "$TOOL_NAME" != "Bash" ]]; then
    exit 0
fi

# Extract the command
CMD=$(echo "$INPUT" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('command',''))" 2>/dev/null || true)

# Skip git commands — commit messages may contain "docker exec" as plain text
if echo "$CMD" | grep -qE '^\s*git '; then
    exit 0
fi

# Only check commands that actually invoke docker exec (at start or after shell operators)
if ! echo "$CMD" | grep -qE '(^|[;&|]\s*)\s*docker exec'; then
    exit 0
fi

# Extract the container name from: docker exec [options] <container> ...
# Strip options like -it, -i, -t, -e VAR=val, -u user, --user, --env
CONTAINER=$(echo "$CMD" | grep -oP 'docker exec(\s+(-[ite]\S*|--\S+(\s+\S+)?|\S+=\S+))*\s+\K[a-zA-Z0-9_.-]+' 2>/dev/null || true)

if [[ -z "$CONTAINER" ]]; then
    exit 0
fi

# Determine the current worktree directory (where the hook runs from)
WORKTREE_DIR=$(git rev-parse --show-toplevel 2>/dev/null || pwd)

# Walk up to find .env (worktrees have .env at the FreegleDocker root, not inside submodules)
ENV_DIR="$WORKTREE_DIR"
while [[ "$ENV_DIR" != "/" ]]; do
    if [[ -f "$ENV_DIR/.env" ]] && grep -q 'COMPOSE_PROJECT_NAME' "$ENV_DIR/.env" 2>/dev/null; then
        break
    fi
    ENV_DIR=$(dirname "$ENV_DIR")
done

if [[ ! -f "$ENV_DIR/.env" ]]; then
    exit 0
fi

EXPECTED_PROJECT=$(grep -E '^COMPOSE_PROJECT_NAME=' "$ENV_DIR/.env" 2>/dev/null | cut -d= -f2 | tr -d '[:space:]' || true)

if [[ -z "$EXPECTED_PROJECT" ]]; then
    exit 0
fi

# Check: container name should start with the expected project name
if ! echo "$CONTAINER" | grep -qE "^${EXPECTED_PROJECT}(-|$)"; then
    echo "⚠️  WARNING: docker exec targeting '$CONTAINER' but current worktree expects project '$EXPECTED_PROJECT'." >&2
    echo "   Expected containers like: ${EXPECTED_PROJECT}-dev-local, ${EXPECTED_PROJECT}-apiv1, etc." >&2
    echo "   If this is intentional, proceed. Otherwise check you're using the right container." >&2
    # Exit 2 = warn but allow (non-blocking). Exit 1 = block.
    exit 2
fi

exit 0
