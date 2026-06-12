# Idle worktree stack sweeper

Stops the Docker Compose stack of any FreegleDocker **worktree** that is no longer
being worked on, so idle stacks don't accumulate and fill the WSL VM's memory.

## Why this exists

On a busy dev host we run several worktree environments in parallel (one per
PR/feature). Each full stack holds several GB. When enough of them pile up, the
WSL2 VM (capped at ~50% of host RAM) fills, the Linux **global OOM killer** fires,
kills a process in the WSL session cgroup (`/init.scope`), and the **entire VM
reboots** — losing all in-flight work. (See `finding_wsl_stops_host_bugcheck` in
the project memory.)

Raising the memory cap or adding an OOM killer just treats the symptom. The real
problem is accumulation: worktree stacks that nobody is using any more. This sweeper
reclaims them.

## What it does

A **systemd system timer** runs every 30 minutes (`freegle-stack-sweeper.timer` →
`freegle-stack-sweeper.service`, as user `edward`). It runs `freegle-stack-sweeper.sh`,
which for each worktree **except the main repo** (`/home/edward/FreegleDockerWSL`):

1. Works out when it was last *actually worked on*.
2. If that was more than `FREEGLE_SWEEP_IDLE_SECS` (default **3600s / 1h**) ago and
   it has running containers, it `docker stop`s that stack (`stop`, not `down`, so
   restart is seconds and no data is lost).

The **main repo stack is never stopped.** An actively-edited worktree is never
stopped, because its activity timestamp keeps refreshing.

### How "last worked on" is measured

Only from signals a developer/Claude produces, never the containers:

- git bookkeeping mtimes: `HEAD`, `ORIG_HEAD`, `FETCH_HEAD`, `logs/HEAD`
- mtimes of the worktree's changed / untracked-not-ignored files
  (`git --no-optional-locks status --porcelain`)

It deliberately does **not** use `.git/index` — `git status` (ours, or your shell
prompt / editor) rewrites it, which would make every worktree look freshly active.
Container-written churn (logs, `.nuxt`, caches) is ignored because only git-tracked
or git-recognised paths are counted.

### Notification

When the sweeper stops a stack it drops a marker in `~/.claude/stack-stopped/<project>`.
The `freegle-stack-notice-hook.sh` **UserPromptSubmit** hook (wired into
`~/.claude/settings.json`) then tells the Claude session bound to that worktree —
the next time you type — that its containers were stopped, with the restart command,
and clears the marker. It is a no-op (near-instant) when nothing has been stopped.

The session→worktree mapping uses the escape-guard state file
`<CLAUDE_PROJECT_DIR>/.claude/active-worktree.<session_id>` (the `claude` process
cwd is the *main* repo even while working in a worktree, so cwd alone is unreliable).

## Install / re-install (after a WSL rebuild)

```bash
scripts/idle-stack-sweeper/install.sh
```

Idempotent; needs sudo for the systemd parts.

## Tune

- Idle threshold: edit `Environment=FREEGLE_SWEEP_IDLE_SECS=...` in the `.service`
  (re-run install or `sudo systemctl daemon-reload`).
- Cadence: edit `OnUnitActiveSec=` in the `.timer`.
- Dry run (log only, stop nothing): `FREEGLE_SWEEP_DRYRUN=1 ~/.claude/freegle-stack-sweeper.sh`
- Log: `~/.claude/freegle-stack-sweeper.log`

## Uninstall

```bash
sudo systemctl disable --now freegle-stack-sweeper.timer
sudo rm /etc/systemd/system/freegle-stack-sweeper.{service,timer}
sudo systemctl daemon-reload
# then remove the UserPromptSubmit hook from ~/.claude/settings.json
```

## Restart a stack the sweeper stopped

```bash
cd <worktree> && docker compose start
```

## Note

The `.service` unit hardcodes `User=edward` and `/home/edward`. On a host with a
different username, edit the unit before installing.
