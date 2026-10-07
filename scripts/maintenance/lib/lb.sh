# shellcheck shell=bash
#
# Profile: the load balancer host (HAProxy). It is a single machine with no
# standby and no floating address: rebooting it takes every hostname it fronts
# (the API, ModTools, uploads, image delivery) offline until HAProxy is back.
# There is no drain that avoids that. So by default this profile patches in
# service and, when a reboot is needed, does not reboot: it reports that a
# reboot is pending and leaves the outage to be scheduled by a person.
# MAINT_LB_ALLOW_REBOOT=1 lets it reboot in its window, with the checks below.

MAINT_LB_ALLOW_REBOOT="${MAINT_LB_ALLOW_REBOOT:-0}"
MAINT_LB_APT_EXCLUDE="${MAINT_LB_APT_EXCLUDE:-^haproxy}"
MAINT_LB_BACKENDS="${MAINT_LB_BACKENDS:-api_server_backend spatial_backend}"
MAINT_LB_PROBE_URLS="${MAINT_LB_PROBE_URLS:-https://api.ilovefreegle.org/api/group}"

lb_backends_up() {
  local b ups
  for b in $MAINT_LB_BACKENDS; do
    ups=$(lb_states "$b" | grep -c '=UP')
    [ "${ups:-0}" -ge 1 ] || die "load balancer: no server UP in $b"
    info "load balancer: $b has $ups server(s) UP"
  done
}

lb_public_probe() {
  local u c
  for u in $MAINT_LB_PROBE_URLS; do
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$u")
    [ "$c" = 200 ] || die "public probe $u answered ${c:-nothing}"
  done
  info "public probes answer 200"
}

lb_run() {
  local L=$MAINT_LB_HOST
  TARGET=$L
  WINDOW=$MAINT_WINDOW_LB

  phase preflight
  check_reachable "$L"; check_root_disk "$L"
  [ "$(rq "$L" 'systemctl is-active haproxy')" = active ] || die "$L: haproxy is not active"
  [ "$(rq "$L" 'systemctl is-enabled haproxy')" = enabled ] || die "$L: haproxy is not enabled at boot"
  # A config edited on disk but never reloaded would only fail at the next start.
  rq "$L" "haproxy -c -q -f $MAINT_LB_CONFIG" >/dev/null 2>&1 || die "$L: haproxy -c fails on the config on disk"
  lb_backends_up
  lb_public_probe
  monit_snapshot "$L" "$RUN_DIR/monit-lb.before"
  monit_all_ok "$L" "$RUN_DIR/monit-lb.before"
  svc_snapshot "$L" "$RUN_DIR/services-lb.before"

  phase plan
  apt_plan "$L" "$MAINT_LB_APT_EXCLUDE"
  reboot_needed "$L"
  if [ -z "$APT_PKGS" ] && [ -z "$REBOOT_WHY" ]; then finish_ok "$L: nothing to patch and no reboot needed"; return; fi

  point_of_no_return

  phase patch-live
  apt_apply "$L"
  reboot_needed "$L"
  if [ -z "$REBOOT_WHY" ]; then
    rq "$L" "haproxy -c -q -f $MAINT_LB_CONFIG" >/dev/null 2>&1 || die "$L: haproxy -c fails after patching"
    lb_backends_up; lb_public_probe
    finish_ok "$L: patched in service (${APT_PKGS:-nothing}); no reboot needed"
    return
  fi
  if [ "$MAINT_LB_ALLOW_REBOOT" != 1 ]; then
    COMPLETED=true
    warn "$L: reboot pending ($REBOOT_WHY). This host has no standby: a reboot is an outage of everything it fronts. Not rebooting; schedule it, or set MAINT_LB_ALLOW_REBOOT=1."
    $DRY || date -u +%s > "$MAINT_STATE_DIR/last-live-finish"
    release_lock
    send_alert "reboot pending $L" "Patched in service (${APT_PKGS:-nothing}). A reboot is pending ($REBOOT_WHY) and was not done: the load balancer has no standby, so the reboot is a short outage of the API, ModTools, uploads and image delivery. Schedule it by hand, or set MAINT_LB_ALLOW_REBOOT=1 to let the timer do it in its window."
    return
  fi

  begin_drain
  phase reboot
  remote_reboot "$L"

  phase verify
  if ! $DRY; then
    for _ in $(seq 1 24); do [ "$(rq "$L" 'systemctl is-active haproxy')" = active ] && break; sleep 5; done
    [ "$(rq "$L" 'systemctl is-active haproxy')" = active ] || die "$L: haproxy not active after reboot"
    sleep 20
    lb_backends_up
    lb_public_probe
    monit_snapshot "$L" "$RUN_DIR/monit-lb.after"
    monit_all_ok "$L" "$RUN_DIR/monit-lb.after"
    svc_compare "$L" "$RUN_DIR/services-lb.before"
  fi

  phase finished
  finish_ok "$L: patched (${APT_PKGS:-nothing}) and rebooted ($REBOOT_WHY); backends UP, public probes 200"
}
