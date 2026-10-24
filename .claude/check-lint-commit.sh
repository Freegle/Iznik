#!/bin/bash
# PreToolUse hook for git commit: refuse a commit whose staged iznik-nuxt3 files have eslint
# errors, the check CI's lint-nuxt job makes. Without it a lint error was found only once
# CI ran, minutes after the push, and cost a second full CI run.
#
# It BLOCKS rather than warns, because CI fails the build on the same errors. The fix is
# usually `npx eslint --fix <file>` in iznik-nuxt3. Only commits that stage iznik-nuxt3
# code pay for it.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""' 2>/dev/null)
CWD=$(echo "$INPUT" | jq -r '.cwd // ""' 2>/dev/null)

echo "$COMMAND" | grep -qE '\bgit(\s+-C\s+\S+)?\s+commit\b' || exit 0

. "$(dirname "$0")/lib/commit-repo.sh"
REPO_ROOT=$(commit_repo_dir "$COMMAND" "${CWD:-$PWD}") || exit 0
[ -n "$REPO_ROOT" ] || exit 0
SCRIPT="$REPO_ROOT/scripts/lint-staged-nuxt.mjs"
[ -f "$SCRIPT" ] || SCRIPT="$(dirname "$0")/../scripts/lint-staged-nuxt.mjs"
[ -f "$SCRIPT" ] || exit 0

ALL=()
if echo "$COMMAND" | grep -qE '\bcommit\b[^|;&]*\s-[a-zA-Z]*a'; then
  ALL=(--all)
fi

if ! OUTPUT=$(node "$SCRIPT" --repo "$REPO_ROOT" "${ALL[@]}" 2>&1); then
  echo "BLOCKED: the staged iznik-nuxt3 files have eslint errors (in $REPO_ROOT)." >&2
  echo "$OUTPUT" >&2
  exit 2
fi
[ -n "$OUTPUT" ] && echo "$OUTPUT" >&2
exit 0
