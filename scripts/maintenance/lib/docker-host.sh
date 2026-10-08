# shellcheck shell=bash
#
# Profile: the Docker host (this machine: batch-prod, Loki, the edge tier, the
# status container). The orchestrator runs here, so it cannot watch its own
# reboot. It drains, patches and reboots; freegle-maint-resume.service (enabled,
# but conditional on the resume marker) runs `freegle-maint resume` at boot and
# does the verification. The lock stays held across the reboot.
#
# While the host is down the member site, ModTools, the API and the database stay
# up; batch jobs, incoming reply mail, tiles, geocode, wiki, uploads and image
# delivery pause (docs/ops/production.md, "Failure behaviour worth knowing").

MAINT_DOCKER_APT_EXCLUDE="${MAINT_DOCKER_APT_EXCLUDE:-^(docker-ce.*|containerd\\.io|docker-compose-plugin|docker-buildx-plugin)$}"
MAINT_DOCKER_UP_TIMEOUT="${MAINT_DOCKER_UP_TIMEOUT:-1500}"
MAINT_STATUS_CONTAINER="${MAINT_STATUS_CONTAINER:-freegledocker-status}"

docker_snapshot() {  # <file>: running containers and whether each has a health check
  rq local "docker ps --format '{{.Names}}' | sort | while read -r c; do printf '%s %s\n' \"\$c\" \"\$(docker inspect -f '{{if .State.Health}}health{{else}}nohealth{{end}}' \"\$c\")\"; done" > "$1"
  [ -s "$1" ] || die "could not list running containers"
}

docker_compare() {  # <snapshot>; waits for every container to run, and each health-checked one to be healthy
  local snap=$1 i c kind bad
  for i in $(seq 1 $(( MAINT_DOCKER_UP_TIMEOUT / 15 ))); do
    bad=""
    while read -r c kind; do
      # "running healthy" for a health-checked container, "running" for one without.
      case "$(rq local "docker inspect -f '{{.State.Status}}{{if .State.Health}} {{.State.Health.Status}}{{end}}' $c" 2>/dev/null)" in
        "running healthy") ;;
        running) [ "$kind" = nohealth ] || bad="$bad $c";;
        *) bad="$bad $c";;
      esac
    done < "$snap"
    [ -z "$bad" ] && { info "every container running before is running again ($(wc -l < "$snap")), health checks passing"; return 0; }
    [ $(( i % 8 )) = 1 ] && info "waiting for containers:$bad"
    sleep 15
  done
  die "containers not running or not healthy after ${MAINT_DOCKER_UP_TIMEOUT}s:$bad"
}

docker_preflight() {
  phase preflight
  [ "$(systemctl is-active freegle-docker.service)" = active ] || die "freegle-docker.service is not active"
  # Without the resume unit nobody verifies the host after it boots.
  [ "$(systemctl is-enabled freegle-maint-resume.service 2>/dev/null)" = enabled ] || die "freegle-maint-resume.service is not installed and enabled"
  rq local "$COMPOSE_CMD config -q" >/dev/null 2>&1 || die "docker compose config does not validate"
  check_root_disk local
  batch_preflight
  # Any data-node maintenance would be on the same lock; check the cluster anyway,
  # because batch writes to it the moment it comes back.
  local n
  for n in $MAINT_DB_NODES; do wsrep_healthy "$n" "$MAINT_DB_CLUSTER_SIZE"; done
  docker_snapshot "$RUN_DIR/containers.before"
  monit_snapshot local "$RUN_DIR/monit-docker.before"
  monit_all_ok local "$RUN_DIR/monit-docker.before"
  svc_snapshot local "$RUN_DIR/services-docker.before"
}

docker_run() {
  TARGET=local
  WINDOW=$MAINT_WINDOW_DOCKER
  docker_preflight

  phase plan
  apt_plan local "$MAINT_DOCKER_APT_EXCLUDE"
  reboot_needed local
  if [ -z "$REBOOT_WHY" ] && ! $APT_REBOOT_LIKELY; then
    if [ -z "$APT_PKGS" ]; then finish_ok "Docker host: nothing to patch and no reboot needed"; return; fi
    point_of_no_return
    phase patch-live
    apt_apply local
    reboot_needed local
    [ -z "$REBOOT_WHY" ] && { finish_ok "Docker host: patched in service ($APT_PKGS); no reboot needed"; return; }
  else
    point_of_no_return
  fi
  info "Docker host: reboot planned (${REBOOT_WHY:-reboot-triggering packages})"

  begin_drain
  phase drain
  # Stop starting new scheduled jobs, let the running ones finish. If they do
  # not finish in time, put the scheduler back and stop: nothing was rebooted.
  if ! batch_drain; then
    batch_undrain
    DRAINED=false
    die "batch scheduled jobs still running after ${MAINT_BATCH_DRAIN_TIMEOUT}s: $(batch_scheduled_jobs); scheduler restarted, no reboot"
  fi
  # Queue workers finish their current task on stop; the mail spooler's files stay on disk.
  act local "stop the queue workers and mail spoolers" \
    "docker exec $MAINT_BATCH_CONTAINER supervisorctl stop 'laravel-worker:*' 'mail-spooler:*'" || die "could not stop batch workers"
  ledger "batch workers and spoolers stopped" "docker exec $MAINT_BATCH_CONTAINER supervisorctl start 'laravel-worker:*' 'mail-spooler:*'"

  phase patch
  apt_apply local

  phase reboot
  if $DRY; then log dry "would write the resume marker and reboot this host"; finish_ok "Docker host: dry run complete"; return; fi
  printf 'run=%s\nrun_dir=%s\nwhy=%s\nat=%s\n' "$RUN_ID" "$RUN_DIR" "$REBOOT_WHY" "$(date -u +%FT%TZ)" > "$MAINT_STATE_DIR/resume"
  ledger "Docker host reboot with resume marker" "none; freegle-maint-resume.service verifies at boot"
  info "Docker host: kernel $(uname -r); rebooting, freegle-maint-resume.service verifies at boot"
  sync
  COMPLETED=true   # the run continues in resume; do not mark failed on exit
  systemctl reboot
  exit 0
}

# At boot, from freegle-maint-resume.service.
docker_resume() {
  local f="$MAINT_STATE_DIR/resume"
  [ -f "$f" ] || { echo "no resume marker"; exit 0; }
  RUN_ID=$(sed -n 's/^run=//p' "$f"); RUN_DIR=$(sed -n 's/^run_dir=//p' "$f"); REBOOT_WHY=$(sed -n 's/^why=//p' "$f")
  TARGET=local; PROFILE=docker; DRY=false; PONR=true; DRAINED=true; WROTE_ACTIVE=true
  RUN_STARTED_EPOCH=$(date -u -d "$(sed -n 's/^at=//p' "$f")" +%s 2>/dev/null || date -u +%s)
  LOG_FILE="$MAINT_LOG_DIR/maint.log"
  rm -f "$f"     # one attempt only: a resume that dies leaves FAILED, not a loop
  exec 9>"$MAINT_STATE_DIR/lock.flock"; flock -n 9 || die "lock busy at resume"
  HOLDING_LOCK=true

  phase verify
  info "Docker host back, kernel $(uname -r)"
  local i
  for i in $(seq 1 60); do [ "$(systemctl is-active freegle-docker.service)" = active ] && break; sleep 10; done
  [ "$(systemctl is-active freegle-docker.service)" = active ] || die "freegle-docker.service not active after boot"
  docker_compare "$RUN_DIR/containers.before"
  for i in $(seq 1 24); do
    [ -z "$(bexec "supervisorctl status" | awk '$2!="RUNNING"')" ] && break; sleep 5
  done
  local stopped; stopped=$(bexec "supervisorctl status" | awk '$2!="RUNNING"{print $1"="$2}' | tr '\n' ' ')
  [ -z "$stopped" ] || die "batch-prod: supervisor programs not RUNNING after boot: $stopped"
  bexec "php artisan schedule:list >/dev/null 2>&1" || die "batch-prod: schedule:list fails after boot"
  local host; host=$(bexec "php artisan tinker --execute='echo DB::select(\"SELECT @@hostname AS h\",[],false)[0]->h;' 2>/dev/null" | tail -1)
  [ -n "$host" ] || die "batch-prod cannot reach its database after boot"
  batch_ripple_lock_discipline
  [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 localhost:3100/ready)" = 200 ] || die "Loki is not ready"
  [ "$(docker inspect -f '{{.State.Status}}' "$MAINT_STATUS_CONTAINER" 2>/dev/null)" = running ] || die "the status container is not running"
  monit_snapshot local "$RUN_DIR/monit-docker.after"
  monit_all_ok local "$RUN_DIR/monit-docker.after"
  svc_compare local "$RUN_DIR/services-docker.before"

  phase finished
  finish_ok "Docker host: patched and rebooted ($REBOOT_WHY); containers back, batch scheduler running against $host, Loki and status up"
}
