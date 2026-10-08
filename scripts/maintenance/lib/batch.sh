# shellcheck shell=bash
#
# batch-prod on the Docker host: where its database aliases point, recreating it
# safely, and draining its scheduler. Shared by the DB-node and Docker-host profiles.

MAINT_BATCH_CONTAINER="${MAINT_BATCH_CONTAINER:-freegledocker-batch-prod}"
MAINT_BATCH_SERVICE="${MAINT_BATCH_SERVICE:-batch-prod}"
MAINT_BATCH_DRAIN_TIMEOUT="${MAINT_BATCH_DRAIN_TIMEOUT:-1500}"
# Long-running supervisor programs. Everything else running `artisan` in the
# container is a scheduled job, which a drain waits for.
MAINT_BATCH_DAEMON_RE="${MAINT_BATCH_DAEMON_RE:-artisan (schedule:work|queue:work|mail:spool:process --daemon|tinker)}"

# The shell profile on the Docker host can export COMPOSE_* variables; the repo's
# .env must decide, so they are removed for every compose call.
COMPOSE_CMD="cd $MAINT_REPO && env -u COMPOSE_PROJECT_NAME -u COMPOSE_FILE -u COMPOSE_PROFILES docker compose"

bexec() { rq local "docker exec $MAINT_BATCH_CONTAINER sh -c $(printf '%q' "$1")"; }

batch_env() {  # <KEY> -> value in the repo .env
  grep -E "^$1=" "$MAINT_REPO/.env" | tail -1 | cut -d= -f2-
}

batch_alias() {  # <alias> -> address it resolves to inside the container
  bexec "getent hosts $1 | awk '{print \$1}' | head -1"
}

batch_supervisor_running() {
  bexec "supervisorctl status" | awk '$2=="RUNNING"' | wc -l
}

batch_healthy() {
  [ "$(rq local "docker inspect -f '{{.State.Health.Status}}' $MAINT_BATCH_CONTAINER" 2>/dev/null)" = healthy ]
}

# Command lines of the PHP artisan processes in the container. Only lines that
# start with the php binary count, so the scanning shell and grep never match
# themselves (their own command lines contain the pattern too).
ARTISAN_PS="for p in /proc/[0-9]*; do tr '\\0' ' ' < \$p/cmdline 2>/dev/null; echo; done | grep -E '^[^ ]*php[^ ]* [^ ]*artisan '"

batch_count_procs() {  # <extended regex> -> number of artisan processes whose command line matches
  bexec "$ARTISAN_PS | grep -c -E $(printf '%q' "$1")"
}

batch_scheduled_jobs() {  # artisan processes that are not the daemons
  bexec "$ARTISAN_PS | grep -v -E $(printf '%q' "$MAINT_BATCH_DAEMON_RE") | sed 's/^.*artisan //' | cut -c1-60 | sort | uniq -c | tr '\\n' ';'"
}

batch_preflight() {
  batch_healthy || die "batch-prod is not healthy"
  BATCH_RUNNING_BEFORE=$(batch_supervisor_running)
  [ "${BATCH_RUNNING_BEFORE:-0}" -ge 1 ] || die "batch-prod: no supervisor programs RUNNING"
  local stopped
  stopped=$(bexec "supervisorctl status" | awk '$2!="RUNNING"{print $1"="$2}' | tr '\n' ' ')
  [ -z "$stopped" ] || die "batch-prod: supervisor programs not RUNNING: $stopped"
  local n
  n=$(batch_count_procs 'artisan (mail:digest:unified --mode=daily|backup:)')
  [ "${n:-0}" = 0 ] || die "batch-prod: $n daily-digest or backup processes running"
  info "batch-prod healthy, $BATCH_RUNNING_BEFORE supervisor programs RUNNING"
}

batch_wait_no_ripple() {
  local i n
  $DRY && return 0
  for i in $(seq 1 36); do
    n=$(batch_count_procs 'artisan ripple:expand')
    [ "${n:-1}" = 0 ] && return 0
    sleep 5
  done
  die "batch-prod: ripple:expand still running after 3 minutes"
}

# Recreating batch-prod while ripple:expand held its lock leaves the lock row
# behind and the job skips until it expires. Release it only when no
# ripple:expand process exists.
batch_ripple_lock_discipline() {
  local procs rows
  $DRY && { log dry "would check the ripple:expand lock and release it if no process holds it"; return 0; }
  procs=$(batch_count_procs 'artisan ripple:expand')
  [ "${procs:-1}" = 0 ] || { info "ripple:expand is running; its lock is live"; return 0; }
  rows=$(bexec "php artisan tinker --execute='echo DB::table(\"cache_locks\")->where(\"key\",\"iznik-batch-cache-ripple:expand:run\")->count();' 2>/dev/null" | tr -dc 0-9)
  if [ "${rows:-0}" -ge 1 ]; then
    act local "release the orphaned ripple:expand lock" \
      "docker exec $MAINT_BATCH_CONTAINER php artisan tinker --execute='\\Illuminate\\Support\\Facades\\Cache::lock(\"ripple:expand:run\",3600)->forceRelease();'" \
      || die "could not release the ripple:expand lock"
  fi
}

# Recreate batch-prod and prove it came back as expected.
batch_recreate_verify() {  # <expected write address> <expected read address>
  local w=$1 r=$2 i gw gr host
  batch_wait_no_ripple
  act local "recreate batch-prod" "$COMPOSE_CMD up -d --force-recreate --no-deps $MAINT_BATCH_SERVICE" \
    || die "batch-prod recreate failed"
  $DRY && return 0
  for i in $(seq 1 36); do batch_healthy && break; sleep 5; done
  batch_healthy || die "batch-prod not healthy 3 minutes after recreate"
  gw=$(batch_alias db-host); gr=$(batch_alias db-host-read)
  [ "$gw" = "$w" ] || die "batch-prod: db-host resolves to the wrong node after recreate"
  [ "$gr" = "$r" ] || die "batch-prod: db-host-read resolves to the wrong node after recreate"
  host=$(bexec "php artisan tinker --execute='echo DB::select(\"SELECT @@hostname AS h\",[],false)[0]->h;' 2>/dev/null" | tail -1)
  [ -n "$host" ] || die "batch-prod cannot query its write database after recreate"
  for i in $(seq 1 24); do [ "$(batch_supervisor_running)" -ge "$BATCH_RUNNING_BEFORE" ] && break; sleep 5; done
  [ "$(batch_supervisor_running)" -ge "$BATCH_RUNNING_BEFORE" ] || die "batch-prod: fewer supervisor programs RUNNING than before"
  bexec "php artisan schedule:list >/dev/null 2>&1" || die "batch-prod: schedule:list fails after recreate"
  batch_ripple_lock_discipline
  info "batch-prod recreated: writes and reads on the expected nodes, write database $host, supervisor $BATCH_RUNNING_BEFORE RUNNING"
}

# Point the batch at different nodes. Only the lines that name the old node change.
batch_env_swap() {  # <from address> <to address>
  local from=$1 to=$2 k changed=""
  for k in DB_HOST_IP DB_HOST_READ_IP; do
    [ "$(batch_env "$k")" = "$from" ] && changed="$changed $k"
  done
  [ -z "$changed" ] && return 1
  BATCH_CHANGED_KEYS=$changed
  BATCH_ORIG_W=$(batch_env DB_HOST_IP); BATCH_ORIG_R=$(batch_env DB_HOST_READ_IP)
  [ -f "$MAINT_REPO/.env.bak-maint-$RUN_ID" ] || act local "back up .env" "cp -p $MAINT_REPO/.env $MAINT_REPO/.env.bak-maint-$RUN_ID"
  for k in $changed; do
    act local "set $k to the other node" "sed -i 's/^$k=.*/$k=$to/' $MAINT_REPO/.env" || die "could not edit .env"
  done
  ledger "batch .env:$changed moved" "cp $MAINT_REPO/.env.bak-maint-$RUN_ID $MAINT_REPO/.env && $COMPOSE_CMD up -d --force-recreate --no-deps $MAINT_BATCH_SERVICE"
  return 0
}

# Put back only the keys batch_env_swap changed, to their values before it.
batch_env_restore() {
  local k v
  for k in $BATCH_CHANGED_KEYS; do
    if [ "$k" = DB_HOST_IP ]; then v=$BATCH_ORIG_W; else v=$BATCH_ORIG_R; fi
    act local "set $k back" "sed -i 's/^$k=.*/$k=$v/' $MAINT_REPO/.env" || die "could not edit .env"
  done
}

# Stop the scheduler from starting anything new and wait for running jobs to end.
batch_drain() {
  local i jobs
  act local "stop the batch scheduler" "docker exec $MAINT_BATCH_CONTAINER supervisorctl stop laravel-scheduler" \
    || die "could not stop the batch scheduler"
  ledger "batch scheduler stopped" "docker exec $MAINT_BATCH_CONTAINER supervisorctl start laravel-scheduler"
  $DRY && return 0
  for i in $(seq 1 $(( MAINT_BATCH_DRAIN_TIMEOUT / 15 ))); do
    jobs=$(batch_scheduled_jobs)
    [ -z "$jobs" ] && { info "batch: no scheduled jobs running"; return 0; }
    [ $(( i % 8 )) = 1 ] && info "batch: waiting for scheduled jobs: $jobs"
    sleep 15
  done
  return 1
}

batch_undrain() {
  act local "start the batch scheduler" "docker exec $MAINT_BATCH_CONTAINER supervisorctl start laravel-scheduler"
}
