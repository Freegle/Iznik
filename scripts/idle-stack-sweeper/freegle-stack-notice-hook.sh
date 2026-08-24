#!/usr/bin/env bash
# Claude Code UserPromptSubmit hook.
#
# If the idle-stack sweeper (freegle-stack-sweeper.sh) stopped the Docker Compose
# containers for the worktree THIS session is working in, surface a one-time
# message to the user AND to Claude (stdout from a UserPromptSubmit hook is added
# to the conversation), so they know — and can restart the stack if they need it.
#
# Always exits 0. Near-instant on the common path (no stacks stopped -> no-op).

set -uo pipefail

NOTICE_DIR="${HOME:-/home/edward}/.claude/stack-stopped"

# Fast path: nothing has been stopped -> do nothing at all.
[ -d "$NOTICE_DIR" ] || exit 0
[ -n "$(ls -A "$NOTICE_DIR" 2>/dev/null)" ] || exit 0

payload="$(cat 2>/dev/null)"
get() { printf '%s' "$payload" | python3 -c "import sys,json;print(json.load(sys.stdin).get('$1',''))" 2>/dev/null; }
sid="$(get session_id)"
cwd="$(get cwd)"
base="${CLAUDE_PROJECT_DIR:-/home/edward/FreegleDockerWSL}"

# Which worktree is this session bound to? The escape-guard state file written by
# `./freegle switch` is the reliable mapping (the claude process cwd is the main
# repo even while working in a worktree). Fall back to cwd.
wt=""
[ -n "$sid" ] && [ -f "$base/.claude/active-worktree.$sid" ] && wt="$(cat "$base/.claude/active-worktree.$sid" 2>/dev/null)"
[ -n "$wt" ] || wt="$cwd"
[ -n "$wt" ] && [ -f "$wt/.env" ] || exit 0

proj="$(grep -E '^COMPOSE_PROJECT_NAME=' "$wt/.env" 2>/dev/null | cut -d= -f2)"
[ -n "$proj" ] || exit 0

marker="$NOTICE_DIR/$proj"
[ -f "$marker" ] || exit 0

# If the stack is already running again, the marker is stale — clear it silently.
running="$(docker ps -q --filter "label=com.docker.compose.project=$proj" 2>/dev/null | grep -c .)"
if [ "${running:-0}" -gt 0 ]; then
  rm -f "$marker"
  exit 0
fi

# Tell the user + Claude, then clear the marker so it shows only once.
echo "[idle-stack sweeper] Heads up: this worktree's containers (project '$proj') are stopped."
sed 's/^/  /' "$marker" 2>/dev/null
echo "  To bring them back for this work, restart with:  ( cd $wt && docker compose start )"
rm -f "$marker"
exit 0
