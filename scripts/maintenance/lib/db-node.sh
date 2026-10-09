# shellcheck shell=bash
#
# Profile: a Galera data node (db2, db3). The graceful cycle from
# docs/ops/runbooks/database-node-restart-and-rejoin.md, automated, around a reboot:
#
#   move every application read and write off the node -> drain its API ->
#   take monit's hands off -> patch -> clean stop -> reboot -> rejoin (IST) ->
#   services back and verified -> the node's API up and UP on the load balancer ->
#   batch back -> the other node's API back.
#
# There is no variant that lets writes or reads fail while the node is down.

MAINT_DB_CLUSTER_SIZE="${MAINT_DB_CLUSTER_SIZE:-3}"
MAINT_DB_DATADIR="${MAINT_DB_DATADIR:-/var/lib/mysql}"
MAINT_DB_MIN_FREE_GB="${MAINT_DB_MIN_FREE_GB:-40}"
MAINT_DB_MAX_GCACHE_PAGES="${MAINT_DB_MAX_GCACHE_PAGES:-4}"
MAINT_DB_MIN_IST_COVER_MIN="${MAINT_DB_MIN_IST_COVER_MIN:-90}"
MAINT_DB_MIN_PEER_AVAIL_GB="${MAINT_DB_MIN_PEER_AVAIL_GB:-6}"
MAINT_DB_REJOIN_TIMEOUT="${MAINT_DB_REJOIN_TIMEOUT:-2700}"
MAINT_DB_APT_EXCLUDE="${MAINT_DB_APT_EXCLUDE:-^percona-}"
MAINT_DB_API_DIR="${MAINT_DB_API_DIR:-/var/www/iznik-server-go}"
MAINT_DB_API_LOG="${MAINT_DB_API_LOG:-/tmp/iznik-server-go.out}"
MAINT_DB_API_PORT="${MAINT_DB_API_PORT:-8192}"
MAINT_DB_SPATIAL_PORT="${MAINT_DB_SPATIAL_PORT:-8194}"
MAINT_DB_ROUTING_PORT="${MAINT_DB_ROUTING_PORT:-8196}"
MAINT_DB_ROUTING_INTERNAL_PORT="${MAINT_DB_ROUTING_INTERNAL_PORT:-8197}"
MAINT_DB_ROUTING_PROBE="${MAINT_DB_ROUTING_PROBE:-/v1/group-proximity?lat=51.5&lng=-0.1&groupid=21250&mode=drive}"
MAINT_DB_ROUTING_TIMEOUT="${MAINT_DB_ROUTING_TIMEOUT:-900}"
MAINT_DB_MONIT_HOLD="${MAINT_DB_MONIT_HOLD:-iznik-spatial-go iznik-routing-go mysqld mysql mysql_processes}"
MAINT_DB_MONIT_RESTORE="${MAINT_DB_MONIT_RESTORE:-mysqld mysql mysql_processes iznik-spatial-go iznik-routing-go iznik-server-go}"
MAINT_LB_API_BACKEND="${MAINT_LB_API_BACKEND:-api_server_backend}"
MAINT_LB_SPATIAL_BACKEND="${MAINT_LB_SPATIAL_BACKEND:-spatial_backend}"
# The two data nodes are never both patched within this many days.
MAINT_DB_MIN_DAYS_BETWEEN_NODES="${MAINT_DB_MIN_DAYS_BETWEEN_NODES:-6}"

T=""; P=""; TIP=""; PIP=""; TS=""; PS=""; SST_HAPPENED=false; P_API_CHANGED=""

addr_of() { getent ahostsv4 "$1" | awk 'NR==1{print $1}'; }

declare -A W
wsrep_read() {  # <host>: fills W with wsrep status and the desync variable
  W=()
  local k v
  while read -r k v; do [ -n "$k" ] && W[$k]=$v; done < <(rq "$1" \
    "mysql -B --skip-column-names -e \"SHOW GLOBAL STATUS LIKE 'wsrep_%'; SHOW GLOBAL VARIABLES LIKE 'wsrep_desync'\"" 2>/dev/null)
}

wsrep_healthy() {  # <host> <expected cluster size>; dies with the reason
  local h=$1 n=$2
  wsrep_read "$h"
  [ "${W[wsrep_local_state]:-}" = 4 ] && [ "${W[wsrep_local_state_comment]:-}" = Synced ] \
    || die "$h: wsrep state ${W[wsrep_local_state]:-?}/${W[wsrep_local_state_comment]:-unreachable}, need 4/Synced"
  [ "${W[wsrep_cluster_size]:-}" = "$n" ] || die "$h: cluster size ${W[wsrep_cluster_size]:-?}, need $n"
  [ "${W[wsrep_cluster_status]:-}" = Primary ] || die "$h: cluster status ${W[wsrep_cluster_status]:-?}"
  [ "${W[wsrep_ready]:-}" = ON ] || die "$h: wsrep_ready ${W[wsrep_ready]:-?}"
  [ "${W[wsrep_desync]:-OFF}" = OFF ] || die "$h: wsrep_desync is ON (a backup or a manual desync is running)"
  awk -v f="${W[wsrep_flow_control_paused]:-1}" 'BEGIN{exit !(f < 0.1)}' \
    || die "$h: wsrep_flow_control_paused ${W[wsrep_flow_control_paused]:-?}"
  info "$h: Synced, cluster size $n, Primary, flow control ${W[wsrep_flow_control_paused]}"
}

api_env() {  # <host> -> "MYSQL_HOST=x MYSQL_HOST_READ=y"
  rq "$1" "grep -E '^export MYSQL_HOST(_READ)?=' $MAINT_DB_API_DIR/.env | sed 's/^export //' | tr '\n' ' '"
}

envval() {  # <"K=v K2=v2" string> <K>
  # shellcheck disable=SC2086
  printf '%s\n' $1 | awk -F= -v k="$2" '$1==k{print $2}'
}

name_of_addr() {
  case "$1" in "$TIP") echo "$TS";; "$PIP") echo "$PS";; *) echo "unknown";; esac
}

db_select() {  # <target>
  T=$1
  case " $MAINT_DB_NODES " in *" $T "*) ;; *) die "$T is not one of MAINT_DB_NODES ($MAINT_DB_NODES)";; esac
  [ "$(echo "$MAINT_DB_NODES" | wc -w)" = 2 ] || die "the DB profile expects exactly two data nodes"
  for P in $MAINT_DB_NODES; do [ "$P" != "$T" ] && break; done
  TS=$(short_name "$T"); PS=$(short_name "$P")
  TIP=$(addr_of "$T"); PIP=$(addr_of "$P")
  [ -n "$TIP" ] && [ -n "$PIP" ] && [ "$TIP" != "$PIP" ] || die "cannot resolve distinct addresses for $T and $P"
}

db_preflight() {
  phase preflight
  local last
  if [ -f "$MAINT_STATE_DIR/last-ok-$P" ]; then
    last=$(date -u -d "$(cat "$MAINT_STATE_DIR/last-ok-$P")" +%s 2>/dev/null || echo 0)
    [ $(( $(date -u +%s) - last )) -ge $(( MAINT_DB_MIN_DAYS_BETWEEN_NODES * 86400 )) ] \
      || die "$P was patched less than $MAINT_DB_MIN_DAYS_BETWEEN_NODES days ago; the two data nodes are never done in the same week"
  fi
  check_reachable "$T"; check_reachable "$P"; check_reachable "$MAINT_ARBITRATOR"; check_reachable "$MAINT_LB_HOST"

  # Cluster: both data nodes Synced at full size, and the arbitrator present.
  wsrep_healthy "$T" "$MAINT_DB_CLUSTER_SIZE"
  wsrep_healthy "$P" "$MAINT_DB_CLUSTER_SIZE"
  [ "$(rq "$MAINT_ARBITRATOR" 'systemctl is-active garb')" = active ] || die "garbd is not active on $MAINT_ARBITRATOR"

  # The node must be under systemd, not a hand-started bootstrap.
  local s
  s=$(rq "$T" "systemctl is-active mysql; systemctl is-active mysql@bootstrap; tr '\\0' ' ' < /proc/\$(pgrep -o -x mysqld)/cmdline | grep -c -- --wsrep-new-cluster" | tr '\n' ' ')
  case "$s" in "active inactive 0 "|"active failed 0 ") ;; *) die "$T: mysqld is not a plain systemd start (mysql/mysql@bootstrap/new-cluster: $s); a human must bring it under the unit first";; esac

  # No backup: the drain window, and nothing desynced or streaming.
  local hm; hm=$((10#$(date -u +%H%M)))
  { [ "$hm" -ge 345 ] && [ "$hm" -le 435 ]; } && die "inside the backup drain window"
  for s in "$T" "$P"; do
    # By exact process name: a -f match would also match the ssh shell running it.
    # shellcheck disable=SC2016  # expands on the remote host
    [ "$(rq "$s" 'echo $(( $(pgrep -c -x xtrabackup) + $(pgrep -c -x xbstream) ))')" = 0 ] || die "$s: a backup or state-transfer process is running"
  done
  [ "$(batch_count_procs 'artisan backup:')" = 0 ] || die "batch-prod: a backup command is running"

  # Disk and write-set cache on both. Page files mean the cache is overflowing
  # or leaking; that needs the cache cure by a person, not a routine reboot.
  local free pages
  for s in "$T" "$P"; do
    free=$(rq "$s" "df -BG --output=avail $MAINT_DB_DATADIR | tail -1 | tr -dc 0-9")
    pages=$(rq "$s" "ls $MAINT_DB_DATADIR/gcache.page.* 2>/dev/null | wc -l")
    [ "${free:-0}" -ge "$MAINT_DB_MIN_FREE_GB" ] || die "$s: ${free:-?}G free in $MAINT_DB_DATADIR, need $MAINT_DB_MIN_FREE_GB"
    [ "${pages:-99}" -le "$MAINT_DB_MAX_GCACHE_PAGES" ] || die "$s: $pages gcache page files (limit $MAINT_DB_MAX_GCACHE_PAGES); see the write-set cache section of the runbook"
    info "$s: ${free}G free, $pages gcache page files"
  done
  check_root_disk "$T"

  # The peer takes the whole load: memory headroom, and a write-set cache that
  # covers the stop so the node rejoins by IST rather than a full copy.
  local avail; avail=$(rq "$P" "free -g | awk 'NR==2{print \$7}'")
  [ "${avail:-0}" -ge "$MAINT_DB_MIN_PEER_AVAIL_GB" ] || die "$P: ${avail:-?}G memory available, need $MAINT_DB_MIN_PEER_AVAIL_GB"
  db_ist_cover

  # Every service on both nodes answering, the peer's included: it serves alone.
  local h c
  for h in "$T" "$P"; do
    c=$(http_code "$h" "http://127.0.0.1:$MAINT_DB_API_PORT/api/group"); [ "$c" = 200 ] || die "$h: API answers $c"
    c=$(http_code "$h" "http://127.0.0.1:$MAINT_DB_SPATIAL_PORT/health"); [ "$c" = 200 ] || die "$h: spatial answers $c"
    c=$(http_code "$h" "http://127.0.0.1:$MAINT_DB_ROUTING_PORT/health"); [ "$c" = 200 ] || die "$h: routing answers $c"
    c=$(http_code "$h" "http://127.0.0.1:$MAINT_DB_ROUTING_INTERNAL_PORT$MAINT_DB_ROUTING_PROBE"); [ "$c" = 200 ] || die "$h: routing internal route answers $c"
    monit_snapshot "$h" "$RUN_DIR/monit-$(short_name "$h").before"
    monit_all_ok "$h" "$RUN_DIR/monit-$(short_name "$h").before"
    [ "$h" = "$T" ] && monit_has "$h" "$RUN_DIR/monit-$(short_name "$h").before" iznik-server-go $MAINT_DB_MONIT_HOLD $MAINT_DB_MONIT_RESTORE
  done
  svc_snapshot "$T" "$RUN_DIR/services-$TS.before"

  # Recent deploys: the binaries on either node changed within the quiet period.
  local newest
  for h in "$T" "$P"; do
    newest=$(rq "$h" "stat -c %Y $MAINT_DB_API_DIR/iznik-server-go /var/www/iznik-spatial-go/iznik-spatial-go /var/www/iznik-routing-go/iznik-routing-go 2>/dev/null | sort -n | tail -1")
    [ $(( $(date -u +%s) - ${newest:-0} )) -ge $(( MAINT_DEPLOY_QUIET_MIN * 60 )) ] || die "$h: a service binary changed within the last $MAINT_DEPLOY_QUIET_MIN min"
  done

  # Load balancer: both API servers UP, the peer's routing server UP.
  s=$(lb_server_status "$MAINT_LB_API_BACKEND" "$TS"); case "$s" in UP*) ;; *) die "load balancer: $TS in $MAINT_LB_API_BACKEND is ${s:-absent}";; esac
  s=$(lb_server_status "$MAINT_LB_API_BACKEND" "$PS"); case "$s" in UP*) ;; *) die "load balancer: $PS in $MAINT_LB_API_BACKEND is ${s:-absent}";; esac
  s=$(lb_server_status "$MAINT_LB_SPATIAL_BACKEND" "$PS"); case "$s" in UP*) ;; *) die "load balancer: $PS in $MAINT_LB_SPATIAL_BACKEND is ${s:-absent}";; esac
  info "load balancer: $TS and $PS UP in $MAINT_LB_API_BACKEND, $PS UP in $MAINT_LB_SPATIAL_BACKEND"

  # The application funnel must be a shape this script understands: every
  # database address in the API and batch config names one of the two nodes,
  # and the running batch container matches its .env (nothing pending).
  ENV_T=$(api_env "$T"); ENV_P=$(api_env "$P")
  local k v
  for k in MYSQL_HOST MYSQL_HOST_READ; do
    for v in "$(envval "$ENV_T" $k)" "$(envval "$ENV_P" $k)"; do
      # An unset read host means no split (reads go to the write host).
      [ -z "$v" ] && [ "$k" = MYSQL_HOST_READ ] && continue
      [ "$v" = "$TIP" ] || [ "$v" = "$PIP" ] || die "an API .env has $k naming neither data node"
    done
  done
  B_W=$(batch_env DB_HOST_IP); B_R=$(batch_env DB_HOST_READ_IP); B_R=${B_R:-$B_W}
  for v in "$B_W" "$B_R"; do [ "$v" = "$TIP" ] || [ "$v" = "$PIP" ] || die "batch .env names neither data node"; done
  [ "$(batch_alias db-host)" = "$B_W" ] && [ "$(batch_alias db-host-read)" = "$B_R" ] \
    || die "batch-prod's running aliases differ from .env: a change is pending a recreate"
  info "funnel: $TS API writes $(name_of_addr "$(envval "$ENV_T" MYSQL_HOST)") reads $(name_of_addr "$(envval "$ENV_T" MYSQL_HOST_READ)"); $PS API writes $(name_of_addr "$(envval "$ENV_P" MYSQL_HOST)") reads $(name_of_addr "$(envval "$ENV_P" MYSQL_HOST_READ)"); batch writes $(name_of_addr "$B_W") reads $(name_of_addr "$B_R")"
  batch_preflight
}

# How long the peer's write-set cache reaches back, at the current write rate.
db_ist_cover() {
  local a b down rate cover
  wsrep_read "$P"; a=${W[wsrep_last_committed]:-0}; down=${W[wsrep_local_cached_downto]:-0}
  sleep 30
  wsrep_read "$P"; b=${W[wsrep_last_committed]:-0}
  rate=$(( (b - a) / 30 ))
  if [ "$rate" -le 0 ]; then info "$P: no writes in 30 s; write-set cache covers any stop"; return 0; fi
  cover=$(( (b - down) / rate / 60 ))
  [ "$cover" -ge "$MAINT_DB_MIN_IST_COVER_MIN" ] \
    || die "$P: write-set cache covers about $cover min at the current rate, need $MAINT_DB_MIN_IST_COVER_MIN"
  info "$P: write-set cache covers about $cover min at $rate write-sets/s"
}

# Restart a node's API through monit and prove the new process is the one serving.
api_restart_verify() {  # <host> <expected write address>
  local h=$1 w=$2 old i new c line
  old=$(rq "$h" "pgrep -o -x iznik-server-go")
  act "$h" "restart the API" "monit restart iznik-server-go" || die "$h: monit restart failed"
  $DRY && return 0
  for i in $(seq 1 80); do
    sleep 3
    new=$(rq "$h" "pgrep -x iznik-server-go | wc -l; pgrep -o -x iznik-server-go" | tr '\n' ' ')
    # shellcheck disable=SC2086
    set -- $new
    [ "${1:-0}" = 1 ] && [ "${2:-}" != "$old" ] || continue
    c=$(http_code "$h" "http://127.0.0.1:$MAINT_DB_API_PORT/api/group"); [ "$c" = 200 ] || continue
    line=$(rq "$h" "grep -a 'Connecting to database' $MAINT_DB_API_LOG | tail -1")
    case "$line" in *"$w"*) info "$h: API restarted (pid $old -> $2), writes to $(name_of_addr "$w")"; return 0;; esac
    die "$h: API restarted but its boot line does not name the expected write node"
  done
  die "$h: API did not come back on a new process within 4 minutes"
}

# Move the API on <host> off <from> and onto <to>, if it uses <from> at all.
api_env_swap() {  # <host> <from> <to>
  local h=$1 from=$2 to=$3 k changed="" envs
  envs=$(api_env "$h")
  for k in MYSQL_HOST MYSQL_HOST_READ; do [ "$(envval "$envs" $k)" = "$from" ] && changed="$changed $k"; done
  [ -z "$changed" ] && return 1
  P_API_CHANGED=$changed
  act "$h" "back up the API .env" "cp -p $MAINT_DB_API_DIR/.env $MAINT_DB_API_DIR/.env.bak-maint-$RUN_ID" || die "$h: .env backup failed"
  for k in $changed; do
    act "$h" "set $k to $(name_of_addr "$to")" "sed -i 's/^export $k=.*/export $k=$to/' $MAINT_DB_API_DIR/.env" || die "$h: .env edit failed"
  done
  ledger "$h API .env:$changed moved to $(name_of_addr "$to")" "ssh $h 'cp $MAINT_DB_API_DIR/.env.bak-maint-$RUN_ID $MAINT_DB_API_DIR/.env && monit restart iznik-server-go'"
  return 0
}

# Put back only the keys this run changed, to the values read in preflight.
api_env_restore() {  # <host> <original env string>
  local k v
  for k in $P_API_CHANGED; do
    v=$(envval "$2" "$k")
    act "$1" "set $k back to $(name_of_addr "$v")" "sed -i 's/^export $k=.*/export $k=$v/' $MAINT_DB_API_DIR/.env" || die "$1: .env restore failed"
  done
}

# Wait for the node to rejoin after boot. One retry of the start is allowed for
# the two cases the runbook names: the five-minute reboot gate, and the IST
# handshake that reports "wrong seqno". An SST is left to run.
db_wait_rejoin() {
  local i st retried=false sst
  $DRY && { log dry "would wait for $T to be Synced (IST expected), up to ${MAINT_DB_REJOIN_TIMEOUT}s"; return 0; }
  for i in $(seq 1 $(( MAINT_DB_REJOIN_TIMEOUT / 10 ))); do
    wsrep_read "$T"
    if [ "${W[wsrep_local_state_comment]:-}" = Synced ] && [ "${W[wsrep_cluster_size]:-}" = "$MAINT_DB_CLUSTER_SIZE" ]; then
      info "$T: Synced, cluster size $MAINT_DB_CLUSTER_SIZE"
      break
    fi
    st=$(rq "$T" "systemctl is-active mysql")
    if [ "$st" = failed ] || { [ "$st" = inactive ] && [ "$(rq "$T" "awk '{print int(\$1)}' /proc/uptime" || echo 0)" -ge 330 ]; }; then
      $retried && die "$T: mysql $st after one retry; journalctl -u mysql on $T"
      retried=true
      warn "$T: mysql $st ($(rq "$T" "journalctl -u mysql -b --no-pager | tail -3 | tr '\n' ' '")); one retry"
      act "$T" "start mysql again" "systemctl reset-failed mysql; systemctl start mysql" || true
    fi
    # The peer must stay healthy the whole time; it is the cluster's only data node now.
    [ $(( i % 6 )) = 0 ] && { wsrep_read "$P"; [ "${W[wsrep_local_state_comment]:-}" = Synced ] || die "$P lost Synced while $T was down"; }
    sleep 10
  done
  [ "${W[wsrep_local_state_comment]:-}" = Synced ] || die "$T: not Synced within ${MAINT_DB_REJOIN_TIMEOUT}s"
  [ "$(rq "$T" 'systemctl is-active mysql')" = active ] || die "$T: Synced but the mysql unit is not active"
  # IST or SST: read the error log from where it ended before the reboot.
  sst=$(rq "$T" "f=\$(mysql -B -N -e 'SELECT @@log_error'); case \$f in /*) ;; *) f=$MAINT_DB_DATADIR/\${f#./};; esac; tail -c +$(( ${DB_LOG_OFFSET:-0} + 1 )) \$f | grep -a -c 'Proceeding with SST'")
  if [ "${sst:-0}" != 0 ]; then
    warn "$T rejoined by SST, not IST. $P was the donor: check its gcache page files tomorrow (runbook, write-set cache section)"
    SST_HAPPENED=true
  else
    info "$T rejoined by IST"
  fi
}

db_verify_services() {
  local api_w line p
  wait_http "$T" "http://127.0.0.1:$MAINT_DB_API_PORT/api/group" 300 "API"
  wait_http "$T" "http://127.0.0.1:$MAINT_DB_SPATIAL_PORT/health" 600 "spatial /health"
  # monit's routing grace reports a dead routing server as OK: ask it directly.
  wait_http "$T" "http://127.0.0.1:$MAINT_DB_ROUTING_PORT/health" "$MAINT_DB_ROUTING_TIMEOUT" "routing /health"
  wait_http "$T" "http://127.0.0.1:$MAINT_DB_ROUTING_INTERNAL_PORT$MAINT_DB_ROUTING_PROBE" 120 "routing internal route"
  $DRY && return 0
  api_w=$(envval "$ENV_T" MYSQL_HOST)
  line=$(rq "$T" "grep -a 'Connecting to database' $MAINT_DB_API_LOG | tail -1")
  case "$line" in *"$api_w"*) ;; *) die "$T: API boot line does not name its configured write node";; esac
  p=$(rq "$T" "tail -n 300 $MAINT_DB_API_LOG | grep -a -c -i -E 'panic|fatal error'")
  [ "${p:-0}" = 0 ] || die "$T: API log shows $p panic lines since start"
  monit_snapshot "$T" "$RUN_DIR/monit-$TS.after"
  monit_all_ok "$T" "$RUN_DIR/monit-$TS.after"
  svc_compare "$T" "$RUN_DIR/services-$TS.before"
}

db_run() {  # <target>
  db_select "$1"
  WINDOW=$MAINT_WINDOW_DB
  db_preflight

  phase plan
  apt_plan "$T" "$MAINT_DB_APT_EXCLUDE"
  reboot_needed "$T"
  if [ -z "$REBOOT_WHY" ] && ! $APT_REBOOT_LIKELY; then
    if [ -z "$APT_PKGS" ]; then finish_ok "$T: nothing to patch and no reboot needed"; return; fi
    # Same as the daily unattended upgrade: no service restarts, node stays in service.
    point_of_no_return
    phase patch-live
    apt_apply "$T"
    reboot_needed "$T"
    [ -z "$REBOOT_WHY" ] && { finish_ok "$T: patched in service ($APT_PKGS); no reboot needed"; return; }
    APT_PKGS=""
    db_preflight   # things may have moved while patching
  fi
  info "$T: reboot planned (${REBOOT_WHY:-reboot-triggering packages: $APT_PKGS})"

  $PONR || point_of_no_return
  begin_drain

  phase move-api
  # 1. The other node's API stops using this node. Its restart is safe now: this
  #    node's API is still serving, so the load balancer always has a server.
  if api_env_swap "$P" "$TIP" "$PIP"; then api_restart_verify "$P" "$PIP"; P_API_MOVED=true; else P_API_MOVED=false; info "$PS API does not use $TS"; fi

  phase move-batch
  # 2. batch-prod's writes and reads off this node.
  if batch_env_swap "$TIP" "$PIP"; then
    local nw nr
    nw=$(batch_env DB_HOST_IP); nr=$(batch_env DB_HOST_READ_IP); nr=${nr:-$nw}
    if $DRY; then
      nw=$B_W; [ "$nw" = "$TIP" ] && nw=$PIP
      nr=$B_R; [ "$nr" = "$TIP" ] && nr=$PIP
    fi
    batch_recreate_verify "$nw" "$nr"; BATCH_MOVED=true
  else BATCH_MOVED=false; info "batch does not use $TS"; fi

  phase drain-api
  # 3. Drain this node's API; the load balancer moves its clients to the peer.
  monit_unmonitor "$T" iznik-server-go
  act "$T" "stop the API (SIGQUIT)" "killall -SIGQUIT iznik-server-go; for i in \$(seq 1 20); do pgrep -x iznik-server-go >/dev/null || exit 0; sleep 1; done; exit 1" \
    || die "$T: API still running 20 s after SIGQUIT"
  ledger "$T API stopped" "ssh $T 'monit monitor iznik-server-go'"
  wait_lb "$MAINT_LB_API_BACKEND" "$TS" DOWN 60
  wait_lb "$MAINT_LB_API_BACKEND" "$PS" UP 10

  phase monit-off
  # 4. Hands off everything else, then stop the daemon so nothing restarts
  #    anything until the node is Synced (monit is off at boot on data nodes).
  # shellcheck disable=SC2086
  monit_unmonitor "$T" $MAINT_DB_MONIT_HOLD
  act "$T" "stop the monit daemon" "systemctl stop monit" || die "$T: could not stop monit"
  ledger "$T monit daemon stopped" "ssh $T 'systemctl start monit' then monit monitor each service"

  phase patch
  apt_apply "$T"

  phase stop-db
  # 5. Never stop the last Synced data node: check the peer first.
  wsrep_healthy "$P" "$MAINT_DB_CLUSTER_SIZE"
  DB_LOG_OFFSET=$(rq "$T" "f=\$(mysql -B -N -e 'SELECT @@log_error'); case \$f in /*) ;; *) f=$MAINT_DB_DATADIR/\${f#./};; esac; stat -c %s \$f")
  ACT_TIMEOUT=900 act "$T" "stop mysql cleanly" "systemctl stop mysql" || die "$T: systemctl stop mysql failed"
  ledger "$T mysql stopped" "ssh $T 'systemctl start mysql'"
  if ! $DRY; then
    [ -z "$(rq "$T" 'pgrep -x mysqld')" ] || die "$T: mysqld still running after stop"
    local gs; gs=$(rq "$T" "grep -E '^(seqno|safe_to_bootstrap):' $MAINT_DB_DATADIR/grastate.dat | tr '\n' ' '")
    case "$gs" in *"seqno:   -1"*|*"seqno: -1"*) warn "$T: grastate seqno -1 after stop; the start will run --wsrep-recover";; esac
    case "$gs" in *"safe_to_bootstrap: 0"*) ;; *) die "$T: grastate after stop: $gs";; esac
    wsrep_read "$P"
    [ "${W[wsrep_local_state_comment]:-}" = Synced ] && [ "${W[wsrep_cluster_status]:-}" = Primary ] \
      || die "$P is not Synced/Primary after $T stopped"
    info "$T stopped cleanly ($gs); $P Synced and Primary, cluster size ${W[wsrep_cluster_size]}"
  fi

  phase reboot
  remote_reboot "$T"

  phase rejoin
  db_wait_rejoin

  phase services
  # 6. monit back: start the daemon, then monitor every service explicitly
  #    (whether "Not monitored" survives a reboot is not relied on either way).
  act "$T" "start the monit daemon" "systemctl start monit" || die "$T: could not start monit"
  # shellcheck disable=SC2086
  monit_monitor "$T" $MAINT_DB_MONIT_RESTORE
  db_verify_services
  wait_lb "$MAINT_LB_API_BACKEND" "$TS" UP 120
  wait_lb "$MAINT_LB_SPATIAL_BACKEND" "$TS" UP 120

  phase move-back
  # 7. Batch back first, then (this node's API being UP already) the peer's API.
  if $BATCH_MOVED; then
    batch_env_restore
    batch_recreate_verify "$B_W" "$B_R"
  fi
  if $P_API_MOVED; then
    api_env_restore "$P" "$ENV_P"
    api_restart_verify "$P" "$(envval "$ENV_P" MYSQL_HOST)"
  fi
  wsrep_healthy "$T" "$MAINT_DB_CLUSTER_SIZE"
  wsrep_healthy "$P" "$MAINT_DB_CLUSTER_SIZE"
  local pages; pages=$(rq "$P" "ls $MAINT_DB_DATADIR/gcache.page.* 2>/dev/null | wc -l")
  info "$P (donor) gcache page files: $pages"

  phase finished
  finish_ok "$T: patched and rebooted ($REBOOT_WHY), kernel $(rq "$T" 'uname -r'), rejoined by $($SST_HAPPENED && echo SST || echo IST); API 5xx by node in the window: $(loki_api_5xx)"
}
