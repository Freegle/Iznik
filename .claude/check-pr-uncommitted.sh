#!/bin/bash
# PreToolUse hook: block `gh pr create` when the target repo's working tree has
# uncommitted files (modified-tracked OR untracked-non-ignored).
#
# Why: a PR should be opened from a clean tree. Otherwise unrelated work-in-
# progress lingers uncommitted in the checkout and silently rots (stale drafts,
# orphaned files, sibling-worktree sync contamination). Opening the PR is the
# natural checkpoint to force that WIP to a decision.
#
# Scope: only `gh pr create`. Pushing a branch is fine; it is PR creation that
# declares "this is ready", at which point nothing should be left dangling.
#
# Escape hatch: prefix the command with `ALLOW_DIRTY_PR=1` for a genuine case.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null)
[ -z "$COMMAND" ] && exit 0

# Decide whether to fire from the command with heredoc BODIES stripped, and skip
# commands that merely QUOTE the trigger rather than run it. A grep whose pattern
# contains the words, or a commit message showing an example, is not opening a PR.
# Both were observed firing this hook before this guard existed.
TRIGGER=$(echo "$COMMAND" | awk '
  {
    if (tag != "") { if ($0 ~ ("^[[:space:]]*" tag "[[:space:]]*$")) tag = ""; next }
    line = $0
    if (match(line, /<<-?["'"'"'"]?[A-Za-z_][A-Za-z0-9_]*["'"'"'"]?/)) {
      t = substr(line, RSTART, RLENGTH)
      sub(/^<<-?/, "", t); gsub(/["'"'"'"]/, "", t)
      tag = t
    }
    print line
  }')

# Only fire for an actual `gh pr create` invocation.
echo "$TRIGGER" | grep -qE '(^|[;&|]|&&|\|\|)[[:space:]]*([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*[[:space:]]+)*gh[[:space:]]+pr[[:space:]]+create\b' || exit 0

# A grep/rg/awk whose PATTERN contains the trigger is searching, not creating.
echo "$TRIGGER" | grep -qE '\b(grep|rg|ag|ack|awk|sed)\b' \
  && ! echo "$TRIGGER" | grep -qE '(^|[;&|])[[:space:]]*(cd[[:space:]]+[^;&|]+[[:space:]]*(&&|;)[[:space:]]*)?([A-Za-z_][A-Za-z0-9_]*=[^[:space:]]*[[:space:]]+)*gh[[:space:]]+pr[[:space:]]+create\b' \
  && exit 0

# Explicit override (env in the command, or already exported).
if echo "$TRIGGER" | grep -qE '\bALLOW_DIRTY_PR=1\b' || [ "${ALLOW_DIRTY_PR:-}" = "1" ]; then
  exit 0
fi

HOOK_CWD=$(echo "$INPUT" | jq -r '.cwd // empty' 2>/dev/null)

# Determine which repo to check: a leading `cd <path>` in the command wins,
# else the tool's cwd, else the project dir.
CD_PATH=$(echo "$COMMAND" | grep -oE '(^|&&|\||;)[[:space:]]*cd[[:space:]]+[^&|;]+' | head -1 \
  | sed -E 's/^.*cd[[:space:]]+//; s/[[:space:]]+$//' | tr -d '"'"'"'')
BASE_DIR="${HOOK_CWD:-${CLAUDE_PROJECT_DIR:-$PWD}}"
if [ -n "$CD_PATH" ]; then
  case "$CD_PATH" in
    /*) TARGET_DIR="$CD_PATH" ;;
    *)  TARGET_DIR="$BASE_DIR/$CD_PATH" ;;
  esac
else
  TARGET_DIR="$BASE_DIR"
fi

# Resolve to the git toplevel; if not a git repo, nothing to enforce.
TOPLEVEL=$(git -C "$TARGET_DIR" rev-parse --show-toplevel 2>/dev/null)
[ -z "$TOPLEVEL" ] && exit 0

DIRTY=$(git -C "$TOPLEVEL" status --porcelain 2>/dev/null)
[ -z "$DIRTY" ] && exit 0

COUNT=$(echo "$DIRTY" | grep -c .)
{
  echo "STOP. Refusing to open a PR: the working tree at $TOPLEVEL has $COUNT uncommitted file(s)."
  echo ""
  echo "$DIRTY" | head -30
  [ "$COUNT" -gt 30 ] && echo "... and $((COUNT - 30)) more"
  echo ""
  echo "Open PRs from a clean tree so work does not linger uncommitted. First:"
  echo "  - commit the files that belong to THIS PR;"
  echo "  - commit unrelated work to its own branch (or stash it);"
  echo "  - remove or .gitignore throwaway files."
  echo "If leaving them is genuinely intentional, prefix the command with ALLOW_DIRTY_PR=1."
} >&2
exit 2
