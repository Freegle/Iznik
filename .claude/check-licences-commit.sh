#!/bin/bash
# PreToolUse hook for git commit: refuse a commit that adds a dependency whose licence
# is not compatible with Freegle's, AGPL-3.0-or-later.
#
# CI's check-licences job runs the same script over the whole branch, so this only
# moves the answer from minutes after a push to the moment of the commit. It looks
# only when a lock file (package-lock.json, composer.lock, go.mod) is staged, so an
# ordinary commit pays nothing.
#
# It BLOCKS rather than warns: an incompatible dependency is never right to ship, and
# the fix is either a different package or a named exception, with a reason, in
# scripts/dependency-licences.json.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""' 2>/dev/null)

if ! echo "$COMMAND" | grep -qE '\bgit\s+commit\b'; then
  exit 0
fi

. "$(dirname "$0")/lib/commit-repo.sh"
REPO_ROOT=$(commit_repo_dir "$COMMAND" "$(echo "$INPUT" | jq -r '.cwd // ""' 2>/dev/null)") || exit 0
[ -n "$REPO_ROOT" ] || exit 0
cd "$REPO_ROOT" 2>/dev/null || exit 0
[ -f scripts/check-dependency-licences.mjs ] || exit 0

STAGED=$(git diff --cached --name-only 2>/dev/null)
if echo "$COMMAND" | grep -qE '\bgit\s+commit\b[^|;&]*\s-[a-zA-Z]*a'; then
  STAGED="$STAGED"$'\n'$(git diff --name-only 2>/dev/null)
fi
echo "$STAGED" | grep -qE '(^|/)(package-lock\.json|composer\.lock|go\.mod)$' || exit 0

if ! OUTPUT=$(node scripts/check-dependency-licences.mjs --staged 2>&1); then
  echo "BLOCKED: this commit adds a dependency with an incompatible licence." >&2
  echo "$OUTPUT" >&2
  exit 2
fi
exit 0
