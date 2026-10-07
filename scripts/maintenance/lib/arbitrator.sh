# shellcheck shell=bash
#
# Profile: the Galera arbitrator (garbd on db1). It holds no data and only votes.
# With it gone the two data nodes keep quorum (two votes of three), so its
# maintenance is safe only while both data nodes are healthy, and garbd is stopped
# cleanly first: a clean leave is one view change, while an arbitrator that stalls
# under patching on this small machine can flap and cost the cluster its primary component.
# Patching always happens with garbd stopped, for the same reason.

MAINT_ARB_APT_EXCLUDE="${MAINT_ARB_APT_EXCLUDE:-^percona-}"
MAINT_ARB_SERVICE="${MAINT_ARB_SERVICE:-garb}"

arb_run() {
  local A=$MAINT_ARBITRATOR n
  TARGET=$A
  WINDOW=$MAINT_WINDOW_ARBITRATOR

  phase preflight
  check_reachable "$A"; check_root_disk "$A"
  [ "$(rq "$A" "systemctl is-active $MAINT_ARB_SERVICE")" = active ] || die "$A: $MAINT_ARB_SERVICE is not active"
  [ "$(rq "$A" "systemctl is-enabled $MAINT_ARB_SERVICE")" = enabled ] || die "$A: $MAINT_ARB_SERVICE is not enabled at boot"
  [ "$(rq "$A" "systemctl is-enabled monit 2>/dev/null")" = enabled ] || die "$A: monit is not enabled at boot (it watches garbd from boot)"
  for n in $MAINT_DB_NODES; do
    check_reachable "$n"
    wsrep_healthy "$n" "$MAINT_DB_CLUSTER_SIZE"
    [ "$(http_code "$n" "http://127.0.0.1:$MAINT_DB_API_PORT/api/group")" = 200 ] || die "$n: API is not answering 200"
  done
  local hm; hm=$((10#$(date -u +%H%M)))
  { [ "$hm" -ge 345 ] && [ "$hm" -le 435 ]; } && die "inside the backup drain window"
  monit_snapshot "$A" "$RUN_DIR/monit-arb.before"
  monit_all_ok "$A" "$RUN_DIR/monit-arb.before"
  svc_snapshot "$A" "$RUN_DIR/services-arb.before"

  phase plan
  apt_plan "$A" "$MAINT_ARB_APT_EXCLUDE"
  reboot_needed "$A"
  if [ -z "$APT_PKGS" ] && [ -z "$REBOOT_WHY" ]; then finish_ok "$A: nothing to patch and no reboot needed"; return; fi

  point_of_no_return
  begin_drain

  phase stop-garbd
  monit_unmonitor "$A" "$MAINT_ARB_SERVICE"
  act "$A" "stop $MAINT_ARB_SERVICE cleanly" "systemctl stop $MAINT_ARB_SERVICE" || die "$A: could not stop $MAINT_ARB_SERVICE"
  ledger "$A $MAINT_ARB_SERVICE stopped" "ssh $A 'systemctl start $MAINT_ARB_SERVICE && monit monitor $MAINT_ARB_SERVICE'"
  if ! $DRY; then
    sleep 15
    for n in $MAINT_DB_NODES; do wsrep_healthy "$n" $(( MAINT_DB_CLUSTER_SIZE - 1 )); done
  fi

  phase patch
  apt_apply "$A"
  reboot_needed "$A"

  if [ -n "$REBOOT_WHY" ]; then
    phase reboot
    info "$A: reboot ($REBOOT_WHY)"
    remote_reboot "$A"
  else
    phase start-garbd
    act "$A" "start $MAINT_ARB_SERVICE" "systemctl start $MAINT_ARB_SERVICE" || die "$A: could not start $MAINT_ARB_SERVICE"
  fi

  phase verify
  # garbd is enabled at boot; monit is too, and is told to monitor explicitly.
  if ! $DRY; then
    local i
    for i in $(seq 1 30); do [ "$(rq "$A" "systemctl is-active $MAINT_ARB_SERVICE")" = active ] && break; sleep 5; done
    [ "$(rq "$A" "systemctl is-active $MAINT_ARB_SERVICE")" = active ] || die "$A: $MAINT_ARB_SERVICE not active"
    for i in $(seq 1 30); do rq "$A" "monit summary >/dev/null 2>&1" && break; sleep 5; done
  fi
  monit_monitor "$A" "$MAINT_ARB_SERVICE"
  if ! $DRY; then
    for n in $MAINT_DB_NODES; do
      for _ in $(seq 1 24); do wsrep_read "$n"; [ "${W[wsrep_cluster_size]:-}" = "$MAINT_DB_CLUSTER_SIZE" ] && break; sleep 5; done
      wsrep_healthy "$n" "$MAINT_DB_CLUSTER_SIZE"
    done
    monit_snapshot "$A" "$RUN_DIR/monit-arb.after"
    monit_all_ok "$A" "$RUN_DIR/monit-arb.after"
    svc_compare "$A" "$RUN_DIR/services-arb.before"
  fi

  phase finished
  finish_ok "$A: patched (${APT_PKGS:-nothing}); ${REBOOT_WHY:+rebooted ($REBOOT_WHY); }cluster size $MAINT_DB_CLUSTER_SIZE on both data nodes"
}
