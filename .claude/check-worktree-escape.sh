#!/bin/bash
# PreToolUse hook: prevent Claude from accessing sibling git worktrees.
# Blocks bash commands that contain absolute paths pointing to directories
# that share the same parent as the current project but are not inside it.

if [ -n "${CI:-}" ]; then
    exit 0
fi

INPUT=$(cat)
CMD=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null || true)

if [ -z "$CMD" ]; then
    exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null || pwd)}"
PARENT_DIR=$(dirname "$PROJECT_DIR")

# Don't restrict if parent is a root or common temp directory (too broad)
if [[ "$PARENT_DIR" == "/" || "$PARENT_DIR" == "/tmp" || "$PARENT_DIR" == "/var" ]]; then
    exit 0
fi

# Escape regex metacharacters in the parent path (handles dots etc.)
ESCAPED_PARENT=$(printf '%s' "$PARENT_DIR" | sed 's/[.[\*^$(){}+?|]/\\&/g')

# Extract all absolute paths in the command that start with the parent directory
while IFS= read -r match; do
    [[ -z "$match" ]] && continue
    # Allow paths that are inside (or equal to) the current project directory
    if [[ "$match" == "$PROJECT_DIR"* ]]; then
        continue
    fi
    echo "BLOCKED: command references '$match' which is outside the current worktree." >&2
    echo "" >&2
    echo "  Current worktree: $PROJECT_DIR" >&2
    echo "  Stay within:      $PROJECT_DIR" >&2
    exit 2
done < <(echo "$CMD" | grep -oE "${ESCAPED_PARENT}/[^[:space:]'\"\\;|&>]+" 2>/dev/null || true)

exit 0
