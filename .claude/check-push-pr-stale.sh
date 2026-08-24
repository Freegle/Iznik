#!/bin/bash
# PreToolUse hook: when pushing a branch that already has an open PR, stop and
# force a decision about whether the PR text still describes the code.
#
# Why: a PR body written at open time silently rots as follow-up commits land.
# The reviewer reads a description of behaviour the branch no longer has - worse
# than no description, because it looks authoritative. This has happened
# repeatedly: corrections get pushed, the PR keeps asserting the original claim.
#
# Scope: only pushes that would add commits to a branch with an OPEN PR. First
# pushes, no-op pushes, dry runs and PR-less branches pass straight through.
#
# Escape hatch: prefix the command with PR_UPDATED=1 once you have checked the
# PR text against the commits below (and updated it if it needed updating).

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null)
[ -z "$COMMAND" ] && exit 0

# Only fire for `git push`.
echo "$COMMAND" | grep -qE '\bgit[[:space:]]+push\b' || exit 0

# Dry runs change nothing.
echo "$COMMAND" | grep -qE '\-\-dry-run\b' && exit 0

# Explicit acknowledgement (env in the command, or already exported).
if echo "$COMMAND" | grep -qE '\bPR_UPDATED=1\b' || [ "${PR_UPDATED:-}" = "1" ]; then
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

TOPLEVEL=$(git -C "$TARGET_DIR" rev-parse --show-toplevel 2>/dev/null)
[ -z "$TOPLEVEL" ] && exit 0

BRANCH=$(git -C "$TOPLEVEL" rev-parse --abbrev-ref HEAD 2>/dev/null)
[ -z "$BRANCH" ] && exit 0
[ "$BRANCH" = "HEAD" ] && exit 0                      # detached
case "$BRANCH" in master|main|production) exit 0 ;; esac

# Nothing new to push means nothing new to describe. If the remote branch does
# not exist yet this is a first push - the PR does not exist either.
git -C "$TOPLEVEL" rev-parse --verify --quiet "origin/$BRANCH" >/dev/null 2>&1 || exit 0
NEW_COMMITS=$(git -C "$TOPLEVEL" log --oneline "origin/$BRANCH..HEAD" 2>/dev/null)
[ -z "$NEW_COMMITS" ] && exit 0

# Look for an open PR. Fail open: no gh, no network, no PR - do not block work.
PR_JSON=$(cd "$TOPLEVEL" && timeout 15 gh pr list --head "$BRANCH" --state open \
  --json number,title,url --limit 1 2>/dev/null)
[ -z "$PR_JSON" ] && exit 0

PR_NUM=$(echo "$PR_JSON" | jq -r '.[0].number // empty' 2>/dev/null)
[ -z "$PR_NUM" ] && exit 0
PR_TITLE=$(echo "$PR_JSON" | jq -r '.[0].title // empty' 2>/dev/null)
PR_URL=$(echo "$PR_JSON" | jq -r '.[0].url // empty' 2>/dev/null)

COUNT=$(echo "$NEW_COMMITS" | grep -c .)
{
  echo "STOP. This push adds $COUNT commit(s) to a branch with an OPEN PR:"
  echo "  #$PR_NUM $PR_TITLE"
  echo "  $PR_URL"
  echo ""
  echo "Commits the PR text has never seen:"
  echo "$NEW_COMMITS" | head -20
  [ "$COUNT" -gt 20 ] && echo "  ... and $((COUNT - 20)) more"
  echo ""
  echo "A PR body that describes superseded behaviour actively misleads the reviewer."
  echo "Before pushing, read the CURRENT body and check every claim against the commits:"
  echo "  gh pr view $PR_NUM --json body --jq .body"
  echo ""
  echo "If any claim, root cause, file list, count or test result changed - and"
  echo "especially if a later commit REVERSES an earlier one - update the body"
  echo "IMMEDIATELY after the push, in the same turn:"
  echo "  gh api -X PATCH repos/{owner}/{repo}/pulls/$PR_NUM -f body=\"\$(cat <file>)\""
  echo ""
  echo "Then re-run the push prefixed with PR_UPDATED=1 to confirm you have done this."
} >&2
exit 2
