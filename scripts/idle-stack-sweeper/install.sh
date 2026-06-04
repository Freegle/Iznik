#!/usr/bin/env bash
# Install the Freegle idle-worktree-stack sweeper on a WSL dev host.
#
# This is host tooling — it is NOT wired in automatically (a WSL rebuild loses
# the installed copies under ~/.claude and /etc/systemd). Run this once after a
# rebuild to restore it. Safe to re-run (idempotent). Needs sudo for the systemd
# unit install + reload.
#
# See README.md for what it does and why.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="${HOME}/.claude"
mkdir -p "$DEST"

echo "==> Installing sweeper + notice hook into $DEST"
install -m 0755 "$HERE/freegle-stack-sweeper.sh"     "$DEST/freegle-stack-sweeper.sh"
install -m 0755 "$HERE/freegle-stack-notice-hook.sh" "$DEST/freegle-stack-notice-hook.sh"

echo "==> Installing systemd system timer (needs sudo)"
sudo install -m 0644 "$HERE/freegle-stack-sweeper.service" /etc/systemd/system/freegle-stack-sweeper.service
sudo install -m 0644 "$HERE/freegle-stack-sweeper.timer"   /etc/systemd/system/freegle-stack-sweeper.timer
sudo systemctl daemon-reload
sudo systemctl enable --now freegle-stack-sweeper.timer
systemctl list-timers freegle-stack-sweeper.timer --no-pager || true

echo "==> Wiring the UserPromptSubmit notice hook into $DEST/settings.json"
python3 - "$DEST/settings.json" "$DEST/freegle-stack-notice-hook.sh" <<'PY'
import json, os, sys
path, cmd = sys.argv[1], sys.argv[2]
data = {}
if os.path.exists(path):
    with open(path) as f:
        data = json.load(f)
ups = data.setdefault("hooks", {}).setdefault("UserPromptSubmit", [])
present = any(h.get("command") == cmd for e in ups for h in e.get("hooks", []))
if present:
    print("  hook already present")
else:
    ups.append({"matcher": "", "hooks": [{"type": "command", "command": cmd}]})
    with open(path, "w") as f:
        json.dump(data, f, indent=2); f.write("\n")
    print("  hook added")
PY

echo "==> Done."
echo "    Idle worktree stacks (no dev/git activity for >60 min) are now stopped"
echo "    every 30 min to free RAM. The main repo stack is never touched."
echo "    Log: $DEST/freegle-stack-sweeper.log"
