# shellcheck shell=bash
#
# Profile: the outbound mail relay (bulk2). It runs two postfix instances: the
# primary (default instance) and postfix-warm, which owns delivery to the
# providers we pace. Every queue command must name its instance; the default
# one silently answers for itself only (.claude/rules/mail-and-data.md).
#
# Mail is not lost by a reboot: both queues are on disk, and while the relay is
# down the batch's file spool keeps what it could not hand over and retries.
# The drain stops the primary first (no new mail in, nothing more handed to the
# warm instance), then the warm instance, both through systemd so the units and
# monit agree with reality.

MAINT_MAIL_INSTANCES="${MAINT_MAIL_INSTANCES:-- postfix-warm}"
MAINT_MAIL_MONIT="${MAINT_MAIL_MONIT:-postfix postfix-warm postfix-warm-active}"
MAINT_MAIL_APT_EXCLUDE_LIVE="${MAINT_MAIL_APT_EXCLUDE_LIVE:-^postfix}"
MAINT_MAIL_MAX_PRIMARY_ACTIVE="${MAINT_MAIL_MAX_PRIMARY_ACTIVE:-5000}"
MAINT_MAIL_MAX_WARM_ACTIVE="${MAINT_MAIL_MAX_WARM_ACTIVE:-40000}"
MAINT_MAIL_MAX_SPOOL_PENDING="${MAINT_MAIL_MAX_SPOOL_PENDING:-20000}"
MAINT_MAIL_LOG="${MAINT_MAIL_LOG:-/var/log/mail.log}"
MAINT_MAIL_FLOW_TIMEOUT="${MAINT_MAIL_FLOW_TIMEOUT:-900}"
MAINT_BATCH_SPOOL_DIR="${MAINT_BATCH_SPOOL_DIR:-/var/www/html/storage/spool/mail}"

unit_of() { echo "postfix@$1"; }

# Per instance: "incoming active deferred hold maildrop" file counts.
mail_queue_counts() {  # <host> <instance>
  rq "$1" "q=\$(postmulti -i $2 -x postconf -h queue_directory); for d in incoming active deferred hold maildrop; do find \$q/\$d -type f 2>/dev/null | wc -l; done | tr '\n' ' '"
}

batch_spool_pending() {
  bexec "ls -U $MAINT_BATCH_SPOOL_DIR/pending 2>/dev/null | wc -l"
}

mail_run() {
  local M=$MAINT_MAIL_HOST i c inst
  TARGET=$M
  WINDOW=$MAINT_WINDOW_MAIL

  phase preflight
  check_reachable "$M"; check_root_disk "$M"
  for inst in $MAINT_MAIL_INSTANCES; do
    [ "$(rq "$M" "systemctl is-active $(unit_of "$inst")")" = active ] || die "$M: $(unit_of "$inst") is not active"
    rq "$M" "postmulti -i $inst -p status" >/dev/null 2>&1 || die "$M: postfix instance $inst is not running"
    rq "$M" "postmulti -i $inst -x postfix check" >/dev/null 2>&1 || die "$M: postfix check fails for instance $inst"
    c=$(mail_queue_counts "$M" "$inst")
    # shellcheck disable=SC2086
    set -- $c
    local limit=$MAINT_MAIL_MAX_PRIMARY_ACTIVE; [ "$inst" = - ] || limit=$MAINT_MAIL_MAX_WARM_ACTIVE
    [ $(( ${1:-0} + ${2:-0} + ${5:-0} )) -le "$limit" ] || die "$M: instance $inst has $(( $1 + $2 + $5 )) messages incoming/active/maildrop (limit $limit): a bulk send is in progress"
    info "$M: instance $inst queue incoming=$1 active=$2 deferred=$3 hold=$4 maildrop=$5"
  done
  [ "$(rq "$M" "postmulti -l | awk '\$3==\"y\"' | wc -l")" -ge "$(echo "$MAINT_MAIL_INSTANCES" | wc -w)" ] || die "$M: postmulti does not list every instance as enabled"
  SPOOL_BEFORE=$(batch_spool_pending)
  [ "${SPOOL_BEFORE:-0}" -le "$MAINT_MAIL_MAX_SPOOL_PENDING" ] || die "batch mail spool has $SPOOL_BEFORE pending (limit $MAINT_MAIL_MAX_SPOOL_PENDING): a bulk send is in progress"
  info "batch mail spool pending: $SPOOL_BEFORE"
  [ "$(batch_count_procs 'artisan (mail:digest:unified --mode=daily|stories:newsletter|mail:events-digest|mail:volunteering-digest)')" = 0 ] \
    || die "a bulk mail command is running in batch-prod"
  monit_snapshot "$M" "$RUN_DIR/monit-mail.before"
  monit_all_ok "$M" "$RUN_DIR/monit-mail.before"
  monit_has "$M" "$RUN_DIR/monit-mail.before" $MAINT_MAIL_MONIT
  svc_snapshot "$M" "$RUN_DIR/services-mail.before"

  phase plan
  apt_plan "$M" "$MAINT_MAIL_APT_EXCLUDE_LIVE"
  reboot_needed "$M"
  if [ -z "$REBOOT_WHY" ] && ! $APT_REBOOT_LIKELY; then
    if [ -z "$APT_PKGS" ]; then finish_ok "$M: nothing to patch and no reboot needed"; return; fi
    point_of_no_return
    phase patch-live
    apt_apply "$M"
    reboot_needed "$M"
    [ -z "$REBOOT_WHY" ] && { finish_ok "$M: patched in service ($APT_PKGS); no reboot needed"; return; }
  else
    point_of_no_return
  fi
  info "$M: reboot planned (${REBOOT_WHY:-reboot-triggering packages})"

  begin_drain
  phase drain
  # shellcheck disable=SC2086
  monit_unmonitor "$M" $MAINT_MAIL_MONIT
  MAIL_LOG_OFFSET=$(rq "$M" "stat -c %s $MAINT_MAIL_LOG")
  for inst in $MAINT_MAIL_INSTANCES; do
    act "$M" "stop postfix instance $inst" "systemctl stop $(unit_of "$inst")" || die "$M: could not stop $inst"
    ledger "$M postfix $inst stopped" "ssh $M 'systemctl start $(unit_of "$inst")'"
  done
  if ! $DRY; then
    for inst in $MAINT_MAIL_INSTANCES; do
      rq "$M" "postmulti -i $inst -p status" >/dev/null 2>&1 && die "$M: instance $inst still running after stop"
      info "$M: instance $inst stopped; queue on disk: $(mail_queue_counts "$M" "$inst")"
    done
  fi

  phase patch
  # postfix is stopped, so its packages can be upgraded too.
  apt_plan "$M" ""
  apt_apply "$M"

  phase reboot
  remote_reboot "$M"

  phase verify
  if ! $DRY; then
    for inst in $MAINT_MAIL_INSTANCES; do
      for _ in $(seq 1 24); do [ "$(rq "$M" "systemctl is-active $(unit_of "$inst")")" = active ] && break; sleep 5; done
      [ "$(rq "$M" "systemctl is-active $(unit_of "$inst")")" = active ] || die "$M: $(unit_of "$inst") not active after reboot"
      rq "$M" "postmulti -i $inst -p status" >/dev/null 2>&1 || die "$M: instance $inst not running after reboot"
    done
  fi
  # shellcheck disable=SC2086
  monit_monitor "$M" $MAINT_MAIL_MONIT

  phase flow
  # Mail must be moving again: a real delivery from the primary (the loopback
  # hop to the warm instance logs like a delivery and is excluded on both the
  # transport tag and the relay address), the queues draining, and the batch
  # spool handing over what it held.
  if ! $DRY; then
    local sent=0 warm=0
    for _ in $(seq 1 $(( MAINT_MAIL_FLOW_TIMEOUT / 30 ))); do
      # Primary: postfix/smtp and its own transports (postfix-<name>/smtp), not
      # the warm instance's tags and not the loopback hop (postfix-relaywarm).
      sent=$(rq "$M" "tail -c +$(( ${MAIL_LOG_OFFSET:-0} + 1 )) $MAINT_MAIL_LOG | grep -a 'status=sent' | grep -a -E ' postfix(-[a-z0-9]+)?/smtp\\[' | grep -a -v -E ' postfix-(warm|relaywarm)' | grep -a -v -c 'relay=127\\.0\\.0\\.1'")
      # Warm instance: its transports log as postfix-warm<name>/smtp.
      warm=$(rq "$M" "tail -c +$(( ${MAIL_LOG_OFFSET:-0} + 1 )) $MAINT_MAIL_LOG | grep -a 'status=sent' | grep -a -c -E ' postfix-warm[a-z0-9]*/smtp\\['")
      [ "${sent:-0}" -ge 1 ] && break
      sleep 30
    done
    [ "${sent:-0}" -ge 1 ] || die "$M: no delivery from the primary instance within ${MAINT_MAIL_FLOW_TIMEOUT}s of the restart"
    info "$M: deliveries since restart: primary $sent, warm $warm"
    [ "${warm:-0}" -ge 1 ] || warn "$M: no delivery from the warm instance yet; it carries only paced providers, so this can be normal. Check its queue if it persists."
    for inst in $MAINT_MAIL_INSTANCES; do info "$M: instance $inst queue now: $(mail_queue_counts "$M" "$inst")"; done
    local sp; sp=$(batch_spool_pending)
    info "batch mail spool pending: before $SPOOL_BEFORE, now ${sp:-?}"
    monit_snapshot "$M" "$RUN_DIR/monit-mail.after"
    monit_all_ok "$M" "$RUN_DIR/monit-mail.after"
    svc_compare "$M" "$RUN_DIR/services-mail.before"
  fi

  phase finished
  finish_ok "$M: patched and rebooted ($REBOOT_WHY); both postfix instances running, mail flowing"
}
