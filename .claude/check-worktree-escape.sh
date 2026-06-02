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

# Allow the sanctioned worktree-management CLI (./freegle). It is the designated
# tool for creating, switching, and removing sibling worktrees, so it may
# legitimately reference their paths. Only exempt a *bare* freegle invocation:
# refuse if the command chains anything else (;, &, |, backticks, $()), so a
# path escape can't be smuggled alongside it.
if [[ "$CMD" =~ ^[[:space:]]*(\./|[^[:space:]]*/)?freegle([[:space:]]|$) ]] \
   && [[ "$CMD" != *';'* && "$CMD" != *'&'* && "$CMD" != *'|'* && "$CMD" != *'`'* && "$CMD" != *'$('* ]]; then
    exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null || pwd)}"

# If the session has switched into a pre-existing worktree via `freegle switch`,
# treat that worktree as an additional allowed root. The state file lives under
# the launch project's .claude dir, since that is the hook the harness runs
# regardless of the current working directory.
#
# The file is keyed by session id: several Claude sessions can share one launch
# project dir, so a single shared file let one session's switch redirect every
# other session's guard. `freegle switch` writes active-worktree.<session> using
# $CLAUDE_CODE_SESSION_ID, which equals the .session_id the harness passes here.
SID=$(echo "$INPUT" | jq -r '.session_id // empty' 2>/dev/null || true)
[[ -z "$SID" ]] && SID="${CLAUDE_CODE_SESSION_ID:-}"
ACTIVE_WT=""
ACTIVE_WT_FILE="$PROJECT_DIR/.claude/active-worktree.${SID:-nosession}"
if [[ -f "$ACTIVE_WT_FILE" ]]; then
    ACTIVE_WT=$(head -n1 "$ACTIVE_WT_FILE" | tr -d '\r\n')
fi

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
    # ...or inside the worktree the session has switched into via `freegle switch`.
    if [[ -n "$ACTIVE_WT" && "$match" == "$ACTIVE_WT"* ]]; then
        continue
    fi
    echo "BLOCKED: command references '$match' which is outside the current worktree." >&2
    echo "" >&2
    echo "  Current worktree: $PROJECT_DIR" >&2
    if [[ -n "$ACTIVE_WT" ]]; then
        echo "  Switched into:    $ACTIVE_WT" >&2
    fi
    echo "" >&2
    echo "  To work in a pre-existing worktree, run: ./freegle switch <name>" >&2
    echo "  (then cd into it — the guard will allow that worktree going forwards)." >&2
    exit 2
done < <(echo "$CMD" | grep -oE "${ESCAPED_PARENT}/[^[:space:]'\"\\;|&>]+" 2>/dev/null || true)

exit 0
