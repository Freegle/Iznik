# shellcheck shell=bash
#
# Shared helpers for freegle-maint: configuration, structured logging, the dry-run
# action wrapper, the global lock and kill switch, alerting, and the remote checks
# every profile uses (monit, systemd, apt, reboot, the load balancer).
#
# Rule for every profile: anything that changes state goes through `act`. Plain
# reads go through `rq`. In a dry run `act` prints and does nothing, so a dry run
# runs the real (read-only) preflight and prints every action it would take.

# ---------------------------------------------------------------------------
# Configuration defaults. The env file (MAINT_ENV_FILE) overrides them; it lives
# on the Docker host and is not in git. Nothing here is a secret or an address.
# ---------------------------------------------------------------------------
MAINT_ENV_FILE="${MAINT_ENV_FILE:-/etc/freegle-maint/freegle-maint.env}"
# shellcheck disable=SC1090
[ -f "$MAINT_ENV_FILE" ] && . "$MAINT_ENV_FILE"

MAINT_REPO="${MAINT_REPO:-/var/www/FreegleDocker}"
MAINT_STATE_DIR="${MAINT_STATE_DIR:-/var/lib/freegle-maint}"
MAINT_LOG_DIR="${MAINT_LOG_DIR:-/var/log/freegle-maint}"
MAINT_PAUSE_FILE="${MAINT_PAUSE_FILE:-/etc/freegle-maint/PAUSE}"
# Hosts the timers may run for real. Empty means every scheduled run is a dry run.
MAINT_LIVE_HOSTS="${MAINT_LIVE_HOSTS:-}"
MAINT_ALERT_EMAIL="${MAINT_ALERT_EMAIL:-}"
MAINT_MAIL_DRY_RUN_SUMMARY="${MAINT_MAIL_DRY_RUN_SUMMARY:-1}"
MAINT_SSH_OPTS="${MAINT_SSH_OPTS:--o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4}"

# Estate. ssh names as in the Docker host's /etc/hosts.
MAINT_DB_NODES="${MAINT_DB_NODES:-db2-internal db3-internal}"
MAINT_DB_ROTATION="${MAINT_DB_ROTATION:-db2-internal db3-internal}"
MAINT_ARBITRATOR="${MAINT_ARBITRATOR:-db1-internal}"
MAINT_LB_HOST="${MAINT_LB_HOST:-ha-internal}"
MAINT_MAIL_HOST="${MAINT_MAIL_HOST:-bulk2-internal}"

# Start windows, HHMM-HHMM, in UTC except the load balancer, which is UK time.
# A run that starts outside its window refuses.
MAINT_WINDOW_DB="${MAINT_WINDOW_DB:-0440-0505}"
MAINT_WINDOW_ARBITRATOR="${MAINT_WINDOW_ARBITRATOR:-0440-0505}"
MAINT_WINDOW_MAIL="${MAINT_WINDOW_MAIL:-0215-0240}"
MAINT_WINDOW_DOCKER="${MAINT_WINDOW_DOCKER:-2335-2355}"
MAINT_WINDOW_LB="${MAINT_WINDOW_LB:-0400-0420}"
MAINT_WINDOW_LB_TZ="${MAINT_WINDOW_LB_TZ:-Europe/London}"
WINDOW_TZ=UTC

# Gates shared by every profile.
MAINT_MIN_GAP_HOURS="${MAINT_MIN_GAP_HOURS:-20}"          # since the last live run on any host
MAINT_DEPLOY_QUIET_MIN="${MAINT_DEPLOY_QUIET_MIN:-360}"   # no deploy activity this recently
MAINT_MIN_ROOT_FREE_GB="${MAINT_MIN_ROOT_FREE_GB:-5}"
MAINT_FORCE_REBOOT_DAYS="${MAINT_FORCE_REBOOT_DAYS:-0}"   # 0 = only reboot when patching needs it
MAINT_REBOOT_PKG_RE="${MAINT_REBOOT_PKG_RE:-^(linux-image|linux-modules|linux-base|libc6|systemd|dbus|libssl3)}"
MAINT_BOOT_TIMEOUT="${MAINT_BOOT_TIMEOUT:-1200}"
MAINT_MONIT_TIMEOUT="${MAINT_MONIT_TIMEOUT:-300}"
# systemd services that may legitimately be running before and not after a reboot.
MAINT_SVC_IGNORE_RE="${MAINT_SVC_IGNORE_RE:-^(apt-|unattended-upgrades|packagekit|fwupd|user@|session-|systemd-|snapd|ua-|ubuntu-advantage|motd|man-db|getty@|serial-getty@|polkit|udisks2|ModemManager|multipathd|cloud-|certbot|e2scrub|logrotate|sysstat-|dpkg-db-backup|phpsessionclean|exim4-base|apport)}"

MAINT_LB_STATS_PORT="${MAINT_LB_STATS_PORT:-1936}"
MAINT_LB_CONFIG="${MAINT_LB_CONFIG:-/etc/haproxy/haproxy.cfg}"

DRY=true
FORCE_REBOOT=false
IGNORE_WINDOW=false
TARGET=""
SLOT=""              # set for timer runs and the Docker-host resume: they report to housekeeping
PROFILE=""
PHASE="init"
RUN_ID=""
RUN_DIR=""
LOG_FILE=""
PONR=false           # set before the first change: patches in service count
DRAINED=false        # set when the drain starts: the estate is no longer in its normal shape
COMPLETED=false
HOLDING_LOCK=false
WROTE_ACTIVE=false   # this run wrote the active marker, so it may remove it
RUN_STARTED_EPOCH=$(date -u +%s)

# ---------------------------------------------------------------------------
# Logging. One JSON object per line to stdout (the journal when run by systemd)
# and to $MAINT_LOG_DIR/maint.log; a human line per event to the run's summary.
# ---------------------------------------------------------------------------
jesc() {
  local s=$1
  s=${s//\\/\\\\}; s=${s//\"/\\\"}; s=${s//$'\n'/\\n}; s=${s//$'\t'/\\t}; s=${s//$'\r'/}
  printf '%s' "$s"
}

log() {  # log <level> <message...>
  local lvl=$1; shift
  local msg="$*" ts line
  ts=$(date -u +%FT%TZ)
  line=$(printf '{"ts":"%s","run":"%s","host":"%s","profile":"%s","phase":"%s","level":"%s","dry_run":%s,"msg":"%s"}' \
    "$ts" "$RUN_ID" "$TARGET" "$PROFILE" "$PHASE" "$lvl" "$DRY" "$(jesc "$msg")")
  printf '%s\n' "$line"
  [ -n "$LOG_FILE" ] && printf '%s\n' "$line" >> "$LOG_FILE" 2>/dev/null
  [ -n "$RUN_DIR" ] && printf '%s %-5s %-12s %s\n' "$ts" "$lvl" "$PHASE" "$msg" >> "$RUN_DIR/summary.txt" 2>/dev/null
  return 0
}
info() { log info "$@"; }
warn() { log warn "$@"; }
phase() { PHASE=$1; log info "phase start"; }

# The ledger lists every state change made, with the command that undoes it. It
# is the core of the failure alert: the person reading it knows exactly where the
# run stopped and how to put each change back.
ledger() {  # ledger <what was done> <how to undo>
  [ -n "$RUN_DIR" ] && printf '%s | done: %s | undo: %s\n' "$(date -u +%T)" "$1" "$2" >> "$RUN_DIR/ledger.txt"
}

# ---------------------------------------------------------------------------
# Running commands
# ---------------------------------------------------------------------------
# rq <host> <command>: read-only, runs in dry runs too. host "local" = this machine.
rq() {
  local h=$1; shift
  if [ "$h" = local ]; then
    timeout "${RQ_TIMEOUT:-90}" bash -c "$*"
  else
    # shellcheck disable=SC2086
    timeout "${RQ_TIMEOUT:-90}" ssh $MAINT_SSH_OPTS "$h" "$*"
  fi
}

# act <host> <description> <command>: a state change. Printed, not run, in a dry run.
act() {
  local h=$1 desc=$2; shift 2
  if $DRY; then
    log dry "would [$h] $desc: $*"
    return 0
  fi
  log act "[$h] $desc"
  RQ_TIMEOUT="${ACT_TIMEOUT:-900}" rq "$h" "$@"
}

# ---------------------------------------------------------------------------
# Failure handling, in three cases:
#  - before any change: an abort. The lock is released and the alert says the run
#    was skipped and why.
#  - after patching in service, before any drain: the machine is in its normal
#    shape, so the run stops, releases the lock, and the alert carries the ledger.
#  - after the drain started: the run stops where it is, the lock stays held as
#    FAILED (so no other maintenance starts on top of a half-finished one), and
#    the alert carries the ledger. Nothing tries to push on.
# ---------------------------------------------------------------------------
die() {
  local msg="$*"
  log error "$msg"
  if $DRAINED && ! $DRY; then
    mark_failed "$msg"
    send_alert "FAILED $TARGET in $PHASE" "Maintenance of $TARGET stopped in phase $PHASE: $msg

The run stopped rather than pushing on. Each change it made is in the ledger
below, with the command that undoes it. The lock is held as FAILED, so no other
maintenance runs until a person has looked and run: freegle-maint clear-failed"
    exit 2
  fi
  release_lock
  if $PONR && ! $DRY; then
    send_alert "stopped $TARGET" "Maintenance of $TARGET stopped in phase $PHASE before any drain: $msg
Changes made (patches installed in service) are in the ledger. The machine was
never taken out of service."
  else
    send_alert "skipped $TARGET" "Maintenance of $TARGET did not start: $msg
Nothing was changed."
  fi
  exit 1
}

on_exit() {
  local rc=$?
  # A run that dies without passing through die() (a signal, a bug) after the
  # point of no return leaves its active marker behind on purpose: the next run
  # sees an active marker that nobody holds and refuses, which is the safe outcome.
  if [ $rc -ne 0 ] && $DRAINED && ! $COMPLETED && ! $DRY && [ ! -f "$MAINT_STATE_DIR/FAILED" ]; then
    mark_failed "exited with status $rc in phase $PHASE without completing"
  fi
}

# ---------------------------------------------------------------------------
# Lock, kill switch, failed marker
# ---------------------------------------------------------------------------
# One maintenance at a time across the whole estate. Every run starts on the
# Docker host, so a lock there is global. Two layers:
#  - flock on lock.flock: two processes can never both be inside a run;
#  - the persistent `active` file: survives a crash and the Docker host's own
#    reboot, so a run that never finished blocks the next one.
acquire_lock() {
  mkdir -p "$MAINT_STATE_DIR"
  exec 9>"$MAINT_STATE_DIR/lock.flock"
  flock -n 9 || die "another freegle-maint run holds the lock"
  HOLDING_LOCK=true
  [ -f "$MAINT_STATE_DIR/FAILED" ] && die "a previous run failed and has not been cleared: $(head -c 400 "$MAINT_STATE_DIR/FAILED")"
  if [ -f "$MAINT_STATE_DIR/active" ]; then
    die "an earlier run is still marked active ($(tr '\n' ' ' < "$MAINT_STATE_DIR/active")); it never finished, so it is treated as failed"
  fi
  if ! $DRY; then
    printf 'host=%s\nprofile=%s\nrun=%s\npid=%s\nstarted=%s\n' "$TARGET" "$PROFILE" "$RUN_ID" "$" "$(date -u +%FT%TZ)" > "$MAINT_STATE_DIR/active"
    WROTE_ACTIVE=true
  fi
}

release_lock() {
  $HOLDING_LOCK || return 0
  $WROTE_ACTIVE && rm -f "$MAINT_STATE_DIR/active"
  flock -u 9 2>/dev/null
  HOLDING_LOCK=false
}

mark_failed() {
  mkdir -p "$MAINT_STATE_DIR"
  {
    echo "host=$TARGET run=$RUN_ID phase=$PHASE at=$(date -u +%FT%TZ)"
    echo "reason: $*"
  } > "$MAINT_STATE_DIR/FAILED"
}

check_pause() {
  [ -e "$MAINT_PAUSE_FILE" ] && die "maintenance is paused ($MAINT_PAUSE_FILE: $(head -c 200 "$MAINT_PAUSE_FILE" 2>/dev/null))"
  return 0
}

# Called once, before the first change. Re-checks the kill switch and the
# window, because preflight can take minutes.
point_of_no_return() {
  check_pause
  check_window
  $DRY || PONR=true
  info "preflight passed; changes start"
}

# Called as the drain starts. The last chance for the kill switch and the window
# to stop the run cleanly: after this, a failure leaves the lock held as FAILED.
begin_drain() {
  check_pause
  check_window
  $DRY || DRAINED=true
  info "drain starts"
}

# ---------------------------------------------------------------------------
# Alerting: the same path monit uses, mail through the local MTA to the monit
# alert address (or MAINT_ALERT_EMAIL). Summary and ledger go in the body.
# ---------------------------------------------------------------------------
alert_address() {
  if [ -n "$MAINT_ALERT_EMAIL" ]; then echo "$MAINT_ALERT_EMAIL"; return; fi
  grep -h -E '^[[:space:]]*set alert ' /etc/monit/monitrc /etc/monit/conf.d/* 2>/dev/null | awk '{print $3}' | head -1
}

send_alert() {  # send_alert <subject> <body>
  local subj="[freegle-maint] $1" body=$2 to
  housekeeping_slot "$1" "$body"
  $DRY && [ "$MAINT_MAIL_DRY_RUN_SUMMARY" != 1 ] && return 0
  $DRY && subj="$subj (dry run)"
  to=$(alert_address)
  if [ -z "$to" ] || ! command -v mail >/dev/null 2>&1; then
    log error "no alert address or no mail command; alert not sent: $subj"
    return 0
  fi
  {
    echo "$body"
    echo
    echo "Host: $TARGET   Profile: $PROFILE   Run: $RUN_ID   Dry run: $DRY"
    if [ -n "$RUN_DIR" ] && [ -s "$RUN_DIR/ledger.txt" ]; then echo; echo "== Ledger (state changes, with undo) =="; cat "$RUN_DIR/ledger.txt"; fi
    if [ -n "$RUN_DIR" ] && [ -s "$RUN_DIR/summary.txt" ]; then echo; echo "== Log =="; tail -n 200 "$RUN_DIR/summary.txt"; fi
  } | mail -s "$subj" "$to" || log error "mail command failed for: $subj"
}

# ---------------------------------------------------------------------------
# Housekeeping: timer runs also report to the ModTools SysAdmin housekeeping tab
# (housekeeper_tasks), through `artisan housekeeper:record` in batch-prod. A slot
# that fails, is skipped, or stops running at all (overdue) shows there.
# Reporting never fails a run: batch-prod can be down, for one.
# ---------------------------------------------------------------------------
MAINT_HOUSEKEEPING="${MAINT_HOUSEKEEPING:-1}"
# A week plus the longest run, so a slot that did not run shows overdue.
MAINT_HK_SLOT_HOURS="${MAINT_HK_SLOT_HOURS:-174}"
# How long the rollout may sit with a host still on dry runs before it shows overdue.
MAINT_HK_ROLLOUT_HOURS="${MAINT_HK_ROLLOUT_HOURS:-336}"

housekeeping_record() {  # <task key> <success|failure> <summary> [artisan options...]; log on stdin
  [ "$MAINT_HOUSEKEEPING" = 1 ] || return 0
  local key=$1 st=$2 summary=$3; shift 3
  timeout 60 docker exec -i "${MAINT_BATCH_CONTAINER:-freegledocker-batch-prod}" \
    php artisan housekeeper:record "$key" "$st" "$summary" --log-stdin "$@" >/dev/null 2>&1 \
    || { log warn "housekeeping: could not record $key"; return 1; }
}

slot_title() {  # <slot> -> "name|when"
  case "$1" in
    db) echo "OS patching: data nodes|Tuesday 04:40 UTC, db2 and db3 in alternate weeks";;
    mail) echo "OS patching: mail relay|Wednesday 02:15 UTC, bulk2";;
    docker) echo "OS patching: Docker host|Wednesday 23:35 UTC";;
    arbitrator) echo "OS patching: arbitrator|Friday 04:40 UTC, db1";;
    lb) echo "OS patching: load balancer|Monday 04:00 UK time";;
  esac
}

housekeeping_slot() {  # <alert subject> <alert body>
  [ -n "$SLOT" ] || return 0
  local st summary t
  case "$1" in "ok "*|"dry run ok "*) st=success;; *) st=failure;; esac
  summary=$(printf '%s' "$2" | head -1 | cut -c1-400)
  $DRY && case "$summary" in "Dry run"*) ;; *) summary="Dry run: $summary";; esac
  t=$(slot_title "$SLOT")
  { [ -n "$RUN_DIR" ] && tail -n 400 "$RUN_DIR/summary.txt" 2>/dev/null; } | housekeeping_record "freegle-maint-$SLOT" "$st" "$summary" \
    --name="${t%%|*}" --description="${t#*|}. $($DRY && echo 'Dry run: not yet live.' || echo 'Live.') Runbook: docs/ops/runbooks/automated-host-maintenance.md" \
    --interval-hours="$MAINT_HK_SLOT_HOURS"
}

# ---------------------------------------------------------------------------
# Generic gates
# ---------------------------------------------------------------------------
check_window() {  # uses WINDOW (HHMM-HHMM) in WINDOW_TZ
  $IGNORE_WINDOW && return 0
  local now start end
  now=$((10#$(TZ=$WINDOW_TZ date +%H%M))); start=$((10#${WINDOW%-*})); end=$((10#${WINDOW#*-}))
  if [ "$start" -le "$end" ]; then
    { [ "$now" -ge "$start" ] && [ "$now" -le "$end" ]; } && return 0
  else
    { [ "$now" -ge "$start" ] || [ "$now" -le "$end" ]; } && return 0
  fi
  die "outside the start window $WINDOW $WINDOW_TZ (now $(TZ=$WINDOW_TZ date +%H%M)); --ignore-window overrides for a human run"
}

check_gap() {
  local last now
  [ -f "$MAINT_STATE_DIR/last-live-finish" ] || return 0
  last=$(cat "$MAINT_STATE_DIR/last-live-finish"); now=$(date -u +%s)
  [ $(( now - last )) -ge $(( MAINT_MIN_GAP_HOURS * 3600 )) ] \
    || die "the last live maintenance finished $(( (now - last) / 3600 ))h ago; minimum gap is ${MAINT_MIN_GAP_HOURS}h"
}

# Deploy activity on the Docker host: deploy-prod.sh running, or a deploy log
# written recently.
check_deploy_quiet() {
  local newest age
  pgrep -f 'scripts/deploy-prod\.sh' >/dev/null && die "scripts/deploy-prod.sh is running"
  newest=$(find "$MAINT_REPO/scripts" -maxdepth 1 -name 'deploy-*.log' -printf '%T@\n' 2>/dev/null | sort -n | tail -1)
  if [ -n "$newest" ]; then
    age=$(( $(date -u +%s) - ${newest%.*} ))
    [ "$age" -ge $(( MAINT_DEPLOY_QUIET_MIN * 60 )) ] || die "a deploy log was written $(( age / 60 )) min ago; quiet period is ${MAINT_DEPLOY_QUIET_MIN} min"
  fi
  # A hand-run graceful cycle uses a transient unit of this name for its watcher.
  systemctl is-active --quiet cycle-watch 2>/dev/null && die "a manual graceful cycle is in progress (cycle-watch is active)"
  return 0
}

check_reachable() { rq "$1" true >/dev/null 2>&1 || die "$1 is not reachable over ssh"; }

check_root_disk() {  # <host>
  local free
  free=$(rq "$1" "df -BG --output=avail / | tail -1 | tr -dc 0-9")
  [ -n "$free" ] && [ "$free" -ge "$MAINT_MIN_ROOT_FREE_GB" ] || die "$1: root filesystem has ${free:-?}G free, need $MAINT_MIN_ROOT_FREE_GB"
  info "$1: root filesystem ${free}G free"
}

# ---------------------------------------------------------------------------
# monit. Traps (docs/ops/runbooks/database-node-restart-and-rejoin.md and
# ops/hosts/README.md): an unmonitor or monitor lands on the next poll, so wait
# for it rather than sleeping a fixed time; never `monit reload` in between,
# which reverts to the state file; one batch of commands, not many rapid calls.
# ---------------------------------------------------------------------------
monit_summary() {  # <host> -> lines "name|status"
  rq "$1" "monit summary 2>/dev/null" | sed 's/[│├┌└─┼┬┴┐┘┤]/ /g' | awk '
    $1=="Monit" || $1=="Service" || NF<3 {next}
    { last=NF; if ($(NF-1)=="Remote") last=NF-1
      s=""; for (i=2;i<last;i++) s=s (s==""?"":" ") $i
      print $1 "|" s }'
}

monit_snapshot() {  # <host> <file>
  monit_summary "$1" > "$2"
  [ -s "$2" ] || die "$1: monit summary returned nothing (is the monit daemon running?)"
}

# Every service must be OK, except ones parked on purpose (`on reboot laststate`).
monit_all_ok() {  # <host> <snapshot file>
  local bad="" name st held
  while IFS='|' read -r name st; do
    [ "$st" = OK ] && continue
    if [ "$st" = "Not monitored" ]; then
      held=$(rq "$1" "monit status $name 2>/dev/null | grep -c 'on reboot.*laststate'")
      [ "${held:-0}" -ge 1 ] && continue
    fi
    bad="$bad $name=$st"
  done < "$2"
  [ -z "$bad" ] || die "$1: monit services not OK:$bad"
  info "$1: monit all OK ($(wc -l < "$2") services)"
}

# Every service a profile will unmonitor or monitor must exist under that name. A dry run only
# prints those commands, so without this a wrong name first fails after the drain has started.
monit_has() {  # <host> <snapshot file> <svc...>
  local h=$1 f=$2 s missing=""; shift 2
  for s in "$@"; do grep -q "^$s|" "$f" || missing="$missing $s"; done
  [ -z "$missing" ] || die "$h: no monit service named:$missing"
}

monit_unmonitor() {  # <host> <svc...>
  local h=$1; shift
  act "$h" "monit unmonitor $*" "for s in $*; do monit unmonitor \$s; done" || die "$h: monit unmonitor failed"
  ledger "$h: monit unmonitor $*" "ssh $h 'for s in $*; do monit monitor \$s; done'"
  $DRY && return 0
  local i s line pending
  for i in $(seq 1 $(( MAINT_MONIT_TIMEOUT / 10 ))); do
    pending=""
    line=$(monit_summary "$h")
    for s in "$@"; do
      printf '%s\n' "$line" | grep -q "^$s|Not monitored$" || pending="$pending $s"
    done
    [ -z "$pending" ] && { info "$h: not monitored: $*"; return 0; }
    sleep 10
  done
  die "$h: still monitored after ${MAINT_MONIT_TIMEOUT}s:$pending"
}

monit_monitor() {  # <host> <svc...>; waits until each is OK
  local h=$1; shift
  act "$h" "monit monitor $*" "for s in $*; do monit monitor \$s; done" || die "$h: monit monitor failed"
  $DRY && return 0
  local i s line pending
  for i in $(seq 1 $(( MAINT_MONIT_TIMEOUT / 10 ))); do
    pending=""
    line=$(monit_summary "$h")
    for s in "$@"; do
      printf '%s\n' "$line" | grep -q "^$s|OK$" || pending="$pending $s"
    done
    [ -z "$pending" ] && { info "$h: monitored and OK: $*"; return 0; }
    sleep 10
  done
  die "$h: not OK after ${MAINT_MONIT_TIMEOUT}s:$pending"
}

# ---------------------------------------------------------------------------
# systemd: what was running before must be running after.
# ---------------------------------------------------------------------------
svc_snapshot() {  # <host> <file>
  rq "$1" "systemctl list-units --type=service --state=running --no-legend --plain | awk '{print \$1}'" \
    | grep -v -E "$MAINT_SVC_IGNORE_RE" | sort > "$2"
  [ -s "$2" ] || die "$1: could not list running services"
}

svc_compare() {  # <host> <before file> [timeout]; waits for missing services
  local h=$1 before=$2 t=${3:-300} i now missing
  for i in $(seq 1 $(( t / 15 ))); do
    now=$(rq "$h" "systemctl list-units --type=service --state=running --no-legend --plain | awk '{print \$1}'" | sort)
    missing=$(comm -23 "$before" <(printf '%s\n' "$now"))
    [ -z "$missing" ] && { info "$h: every service running before is running again"; return 0; }
    sleep 15
  done
  die "$h: services running before maintenance and not after: $(echo "$missing" | tr '\n' ' ')"
}

# ---------------------------------------------------------------------------
# apt. Upgrades are installed with service restarts suppressed (needrestart in
# list mode) and with each profile's excluded packages left alone, because a
# package such as Percona or the Docker engine is an operation of its own.
# ---------------------------------------------------------------------------
APT_ENV="DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1"
APT_OPTS="-o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold"

apt_plan() {  # <host> <exclude-regex> -> sets APT_PKGS, APT_EXCLUDED, APT_REBOOT_LIKELY
  local h=$1 ex=$2 all
  # Package lists: apt-daily refreshes them; the live run refreshes again.
  act "$h" "refresh package lists" "$APT_ENV apt-get $APT_OPTS -qq update" || die "$h: apt-get update failed"
  all=$(rq "$h" "apt-get -s -o Debug::NoLocking=1 --with-new-pkgs upgrade 2>/dev/null | awk '/^Inst /{print \$2}'" | sort -u)
  if [ -n "$ex" ]; then
    APT_PKGS=$(printf '%s\n' "$all" | grep -v -E "$ex" | tr '\n' ' ')
    APT_EXCLUDED=$(printf '%s\n' "$all" | grep -E "$ex" | tr '\n' ' ')
  else
    APT_PKGS=$(printf '%s\n' "$all" | tr '\n' ' '); APT_EXCLUDED=""
  fi
  APT_PKGS=${APT_PKGS%% }; APT_PKGS=${APT_PKGS## }
  APT_REBOOT_LIKELY=false
  printf '%s\n' "$all" | grep -v -E "${ex:-^$}" | grep -q -E "$MAINT_REBOOT_PKG_RE" && APT_REBOOT_LIKELY=true
  info "$h: upgradable: ${APT_PKGS:-none}; held back by policy: ${APT_EXCLUDED:-none}"
}

apt_apply() {  # <host>
  local h=$1
  [ -z "$APT_PKGS" ] && { info "$h: nothing to install"; return 0; }
  # --only-upgrade with the explicit list: excluded packages stay where they are,
  # new dependencies (a new kernel behind its metapackage) are still pulled in.
  ACT_TIMEOUT=1800 act "$h" "install upgrades: $APT_PKGS" \
    "$APT_ENV apt-get $APT_OPTS -y --only-upgrade install $APT_PKGS" || die "$h: apt-get install failed"
  ledger "$h: apt upgraded $APT_PKGS" "none (package upgrades are not rolled back automatically)"
}

reboot_needed() {  # <host> -> sets REBOOT_WHY (empty = not needed)
  local h=$1 rr days
  REBOOT_WHY=""
  rr=$(rq "$h" "[ -f /var/run/reboot-required ] && tr '\n' ' ' < /var/run/reboot-required.pkgs 2>/dev/null; [ -f /var/run/reboot-required ] && echo yes")
  [ -n "$rr" ] && REBOOT_WHY="reboot-required (${rr% yes})"
  $FORCE_REBOOT && REBOOT_WHY="${REBOOT_WHY:+$REBOOT_WHY; }forced by --force-reboot"
  if [ "$MAINT_FORCE_REBOOT_DAYS" -gt 0 ]; then
    days=$(rq "$h" "awk '{print int(\$1/86400)}' /proc/uptime")
    [ "${days:-0}" -ge "$MAINT_FORCE_REBOOT_DAYS" ] && REBOOT_WHY="${REBOOT_WHY:+$REBOOT_WHY; }up ${days} days (cadence $MAINT_FORCE_REBOOT_DAYS)"
  fi
  return 0
}

boot_id() { rq "$1" "cat /proc/sys/kernel/random/boot_id" 2>/dev/null; }

# Reboot a remote host and wait until it answers with a new boot id.
remote_reboot() {  # <host>
  local h=$1 before i now
  before=$(boot_id "$h")
  [ -n "$before" ] || die "$h: cannot read boot id before reboot"
  info "$h: kernel before reboot $(rq "$h" 'uname -r')"
  act "$h" "reboot" "nohup sh -c 'sleep 3; systemctl reboot' >/dev/null 2>&1 &" || die "$h: reboot command failed"
  ledger "$h: rebooted" "none"
  $DRY && return 0
  sleep 30
  for i in $(seq 1 $(( MAINT_BOOT_TIMEOUT / 10 ))); do
    now=$(RQ_TIMEOUT=15 boot_id "$h")
    if [ -n "$now" ] && [ "$now" != "$before" ]; then
      info "$h: back after reboot, kernel $(rq "$h" 'uname -r')"
      return 0
    fi
    sleep 10
  done
  die "$h: did not come back within ${MAINT_BOOT_TIMEOUT}s of the reboot"
}

http_code() {  # <host> <url>
  rq "$1" "curl -s -o /dev/null -w '%{http_code}' --max-time 10 '$2'" 2>/dev/null
}

wait_http() {  # <host> <url> <timeout> <label>
  local h=$1 url=$2 t=$3 label=$4 i c
  $DRY && return 0
  for i in $(seq 1 $(( t / 10 ))); do
    c=$(http_code "$h" "$url"); [ "$c" = 200 ] && { info "$h: $label 200"; return 0; }
    sleep 10
  done
  die "$h: $label did not answer 200 within ${t}s (last ${c:-none})"
}

# ---------------------------------------------------------------------------
# Load balancer (HAProxy on the LB host). The stats password is read from the
# config on that host inside the remote command; it never leaves the host.
# ---------------------------------------------------------------------------
lb_states() {  # <backend> -> lines "server=STATUS"
  rq "$MAINT_LB_HOST" "a=\$(grep -o 'stats auth [^ ]*' $MAINT_LB_CONFIG | awk '{print \$3}' | head -1); \
    curl -s --max-time 10 -u \"\$a\" 'http://127.0.0.1:$MAINT_LB_STATS_PORT/;csv' | \
    awk -F, -v b='$1' '\$1==b && \$2!=\"BACKEND\" && \$2!=\"FRONTEND\" {print \$2\"=\"\$18}'"
}

lb_server_status() {  # <backend> <node short name> -> status of the server whose name starts with it
  lb_states "$1" | awk -F= -v n="$2" 'index($1, n"-")==1 || $1==n {print $2; exit}'
}

wait_lb() {  # <backend> <node short> <UP|DOWN> <timeout>
  local i s
  $DRY && return 0
  for _ in $(seq 1 $(( $4 / 5 ))); do
    s=$(lb_server_status "$1" "$2")
    case "$s" in "$3"*) info "load balancer: $2 in $1 is $s"; return 0;; esac
    sleep 5
  done
  die "load balancer: $2 in $1 is ${s:-unknown}, expected $3"
}

short_name() { echo "${1%%-internal}"; }

# Count API 5xx in Loki on the Docker host over the run, for the summary.
loki_api_5xx() {
  local secs=$(( $(date -u +%s) - RUN_STARTED_EPOCH + 60 ))
  curl -sG --max-time 20 localhost:3100/loki/api/v1/query \
    --data-urlencode "query=sum by (hostname) (count_over_time({source=\"api\", status_code=~\"5..\"}[${secs}s]))" 2>/dev/null \
    | node -e 'let d="";process.stdin.on("data",c=>d+=c).on("end",()=>{try{const j=JSON.parse(d);console.log(j.data.result.map(r=>r.metric.hostname+"="+r.value[1]).join(" ")||"none")}catch(e){console.log("unknown")}})' 2>/dev/null
}

finish_ok() {  # <summary line>
  COMPLETED=true
  if $DRY; then
    info "dry run, nothing was changed. Had it been live: $1"
    release_lock
    send_alert "dry run ok $TARGET" "Dry run: preflight passed and nothing was changed. The actions it would have taken are in the log below. Had it been live: $1"
    return 0
  fi
  info "$1"
  if ! $DRY; then
    date -u +%s > "$MAINT_STATE_DIR/last-live-finish"
    date -u +%FT%TZ > "$MAINT_STATE_DIR/last-ok-$TARGET"
  fi
  release_lock
  send_alert "ok $TARGET" "$1"
}
