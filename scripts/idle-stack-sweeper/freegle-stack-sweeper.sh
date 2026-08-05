#!/usr/bin/env bash
# Freegle idle-worktree stack sweeper.
#
# Stops the Docker Compose stack of any FreegleDocker *worktree* that has not
# been worked on for FREEGLE_SWEEP_IDLE_SECS, to free RAM and stop the 62GB WSL
# VM from filling up and OOM-rebooting. The MAIN repo stack is never touched.
#
# "Worked on" is measured only from signals a developer/Claude produces — git
# bookkeeping (commit/stage/rebase/fetch) and the mtimes of files git considers
# changed/untracked-not-ignored. Container-written churn (logs, .nuxt, caches)
# is deliberately ignored, so a stack running an idle app still counts as idle.
#
# Run by the freegle-stack-sweeper.timer systemd timer (as user edward).
# Env: FREEGLE_SWEEP_IDLE_SECS (default 3600), FREEGLE_SWEEP_DRYRUN=1 (log only).

set -uo pipefail

MAIN_REPO="/home/edward/FreegleDockerWSL"
IDLE_SECS="${FREEGLE_SWEEP_IDLE_SECS:-3600}"
DRYRUN="${FREEGLE_SWEEP_DRYRUN:-0}"
LOG="${HOME:-/home/edward}/.claude/freegle-stack-sweeper.log"
NOTICE_DIR="${HOME:-/home/edward}/.claude/stack-stopped"
now="$(date +%s)"

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG" 2>/dev/null; }

# newest mtime (epoch) among the given paths that exist; 0 if none
newest_mtime() {
  local f t max=0
  for f in "$@"; do
    [ -e "$f" ] || continue
    t="$(stat -c %Y "$f" 2>/dev/null)" || continue
    (( t > max )) && max="$t"
  done
  printf '%s' "$max"
}

# epoch of the most recent developer/Claude activity in a worktree (0 if unknown)
worktree_last_activity() {
  local wt="$1" gitdir best t rel
  gitdir="$(git --no-optional-locks -C "$wt" rev-parse --git-dir 2>/dev/null)" || { printf 0; return; }
  case "$gitdir" in /*) ;; *) gitdir="$wt/$gitdir" ;; esac
  # NB: deliberately NOT using .git/index — `git status` (ours or any editor/shell
  # prompt) opportunistically rewrites it, which would make every worktree look
  # freshly-active on the next sweep. Staging is covered by changed-file mtimes.
  best="$(newest_mtime \
    "$gitdir/HEAD" "$gitdir/ORIG_HEAD" \
    "$gitdir/FETCH_HEAD" "$gitdir/logs/HEAD")"
  # mtimes of changed + untracked-not-ignored files (real edits, not container junk).
  # --no-optional-locks so this read never mutates the index.
  while IFS= read -r rel; do
    [ -n "$rel" ] || continue
    t="$(stat -c %Y "$wt/$rel" 2>/dev/null)" || continue
    (( t > best )) && best="$t"
  done < <(
    git --no-optional-locks -C "$wt" status --porcelain --no-renames 2>/dev/null \
      | sed 's/^...//'
  )
  printf '%s' "$best"
}

# Is a test suite running in this worktree right now?
#
# worktree_last_activity above measures git refs and changed-file mtimes, so a
# worktree whose only current activity is a LONG TEST RUN looks completely idle:
# a clean tree, no edits, no git operations, for the ten to fifteen minutes a Go
# suite takes. That is indistinguishable from abandonment by mtime alone, and it
# bit twice in ninety minutes on 2026-08-03 - both sweeps landing just as a run
# finished, killing the containers before the detailed result could be read. The
# status container holds its per-test log in memory only, so stopping it mid-run
# or just after destroys the evidence that the run happened at all, which is far
# more costly than the RAM the sweep reclaims.
#
# Ask the worktree's own status container instead. Any suite reporting an
# in-flight state means real work is in flight regardless of what the filesystem
# looks like. Both non-terminal states count: a suite reports "started" from the
# POST until the runner actually spawns (test discovery alone is tens of seconds
# for Playwright), and only then flips to "running". Matching "running" alone
# left that startup window unguarded, and a sweep landed in it on 2026-08-05,
# killing a 184-test run seconds after it was kicked off.
# Fails open (returns 1, "not running") if the port is unknown or unreachable -
# an unreachable status container is not evidence of activity.
worktree_suite_running() {
  local wt="$1" port suite state
  port="$(grep -E '^PORT_STATUS=' "$wt/.env" 2>/dev/null | cut -d= -f2)"
  [ -n "$port" ] || return 1
  for suite in go laravel vitest playwright php; do
    state="$(curl -s --max-time 3 "http://localhost:${port}/api/tests/${suite}/status" 2>/dev/null \
             | jq -r '.status // empty' 2>/dev/null)"
    case "$state" in
      running|started) return 0 ;;
    esac
  done
  return 1
}

stopped=0 considered=0
while IFS= read -r wt; do
  [ -n "$wt" ] || continue
  [ "$wt" = "$MAIN_REPO" ] && continue            # never the main stack
  [ -f "$wt/.env" ] || continue
  proj="$(grep -E '^COMPOSE_PROJECT_NAME=' "$wt/.env" 2>/dev/null | cut -d= -f2)"
  [ -n "$proj" ] || continue

  ids="$(docker ps -q --filter "label=com.docker.compose.project=$proj" 2>/dev/null)"
  [ -n "$ids" ] || continue                        # nothing running -> nothing to stop
  running="$(printf '%s\n' "$ids" | grep -c .)"
  considered=$((considered + 1))

  last="$(worktree_last_activity "$wt")"
  if [ "$last" -le 0 ] 2>/dev/null; then
    log "SKIP $proj — activity unknown, leaving ${running} containers up"
    continue
  fi
  idle=$((now - last))
  if (( idle < IDLE_SECS )); then
    continue                                       # active recently -> leave it
  fi

  # Idle by mtime, but a suite may still be running - see worktree_suite_running.
  if worktree_suite_running "$wt"; then
    log "SKIP $proj — idle ${idle}s by mtime, but a test suite is RUNNING; leaving ${running} containers up"
    continue
  fi

  if [ "$DRYRUN" = "1" ]; then
    log "DRYRUN would STOP $proj ($wt) — idle ${idle}s >= ${IDLE_SECS}s, ${running} containers"
  else
    log "STOP $proj ($wt) — idle ${idle}s >= ${IDLE_SECS}s, ${running} containers"
    if docker stop $ids >/dev/null 2>&1; then
      log "STOPPED $proj OK (${running} containers)"
      stopped=$((stopped + 1))
      # Leave a marker so the Claude session bound to this worktree is told (via
      # the UserPromptSubmit hook) that its containers were stopped — and can
      # restart them if needed.
      mkdir -p "$NOTICE_DIR" 2>/dev/null \
        && printf 'Stopped %s by the idle-stack sweeper after ~%dh idle (%s containers).\n' \
             "$(date '+%Y-%m-%d %H:%M:%S')" "$(( idle / 3600 ))" "$running" \
             >"$NOTICE_DIR/$proj" 2>/dev/null
    else
      log "STOP $proj FAILED"
    fi
  fi
done < <(git -C "$MAIN_REPO" worktree list --porcelain 2>/dev/null | awk '/^worktree /{print $2}')

log "sweep done — considered ${considered} running worktree stack(s), stopped ${stopped} (idle>=${IDLE_SECS}s, dryrun=${DRYRUN})"
