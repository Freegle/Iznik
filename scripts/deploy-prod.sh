#!/bin/bash
#
# deploy-prod.sh — deploy the current master to the Freegle production estate.
#
# Deploys three native Go services that live in the monorepo, plus the two local
# batch-side spatial containers, in the safe order and with the verification gates
# that a hand deploy uses. Everything is one node at a time; a node that fails its
# health/route/panic checks aborts the run before the next node is touched.
#
# SUCCESS = the monit daemon is up on every node AND actively monitoring every managed
# service (apiv2/routing/knn all "OK", none "Not monitored"). Health 200 is necessary
# but not sufficient — a manually-restarted routing left unmonitored would not auto-
# recover on crash, so the run enables + asserts monitoring on every node, every time.
#
# WHAT IT DEPLOYS (each only if its source actually changed since the node's
# currently-deployed commit — see change detection below, override with --only):
#
#   apiv2     iznik-server-go   native, monit-managed, port ${APIV2_PORT}
#   routing   iznik-routing-go  native, UNMONITORED (manual restart), rebuilds the
#                               ~7.3G UK graph on every start; ports ${ROUTING_PUBLIC_PORT}
#                               (public/authed) + ${ROUTING_INTERNAL_PORT} (internal/no-auth)
#   knn       iznik-spatial-go  native, monit-managed, port ${KNN_PORT}
#   local     the local docker-compose spatial (routing) / spatial-knn containers
#             that batch-prod calls — rebuilt when routing/knn source changed
#   batch     batch-prod (bind mount): no restart, just verify the schedule parses
#
# NOTHING here is hardcoded to a host — set DEPLOY_NODES (and the paths/ports below)
# via the environment or an env file. Example:
#
#   DEPLOY_NODES="db1-internal db2-internal db3-internal" ./scripts/deploy-prod.sh
#   ./scripts/deploy-prod.sh --dry-run
#   ./scripts/deploy-prod.sh --only apiv2 --nodes "db1-internal"
#   ./scripts/deploy-prod.sh --only routing --yes
#
# Reads ./scripts/deploy-prod.env (or $DEPLOY_ENV_FILE) if present, so secrets/host
# lists can live outside the script. CLI flags override env, env overrides defaults.
#
set -uo pipefail

# ---------------------------------------------------------------------------
# Configuration (every value is overridable via environment or the env file)
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-$SCRIPT_DIR/deploy-prod.env}"
# shellcheck disable=SC1090
[ -f "$DEPLOY_ENV_FILE" ] && source "$DEPLOY_ENV_FILE"

# Estate — space-separated ssh targets. NO defaults are baked into the deploy
# logic; this is the one place hosts are named.
DEPLOY_NODES="${DEPLOY_NODES:-db1-internal db2-internal db3-internal}"
SSH="${DEPLOY_SSH:-ssh}"
SSH_OPTS="${DEPLOY_SSH_OPTS:--o ConnectTimeout=10 -o StrictHostKeyChecking=no}"
BRANCH="${DEPLOY_BRANCH:-master}"

# Remote layout. These are the monorepo subdir symlinks; a git pull from any of
# them updates the whole checkout, so apiv2/routing/knn share one pull per node.
REMOTE_APIV2_DIR="${DEPLOY_REMOTE_APIV2_DIR:-/var/www/iznik-server-go}"
REMOTE_ROUTING_DIR="${DEPLOY_REMOTE_ROUTING_DIR:-/var/www/iznik-routing-go}"
REMOTE_KNN_DIR="${DEPLOY_REMOTE_KNN_DIR:-/var/www/iznik-spatial-go}"
GO_BIN="${DEPLOY_GO_BIN:-go}"

# monit — the deploy's SUCCESS INVARIANT is "monit daemon up and actively monitoring
# every managed service" (health 200 alone is not success). Service names are the
# monit check names (`monit summary`); override per estate.
MONIT_CMD="${DEPLOY_MONIT_CMD:-sudo monit}"
MONIT_APIV2_SVC="${DEPLOY_MONIT_APIV2_SVC:-iznik-server-go}"
MONIT_ROUTING_SVC="${DEPLOY_MONIT_ROUTING_SVC:-iznik-routing-go}"
MONIT_KNN_SVC="${DEPLOY_MONIT_KNN_SVC:-iznik-spatial-go}"
MONIT_SERVICES="${DEPLOY_MONIT_SERVICES:-$MONIT_APIV2_SVC $MONIT_ROUTING_SVC $MONIT_KNN_SVC}"

# Ports / health + verification probes.
APIV2_PORT="${DEPLOY_APIV2_PORT:-8192}"
APIV2_HEALTH_PATH="${DEPLOY_APIV2_HEALTH_PATH:-/api/group}"
ROUTING_PUBLIC_PORT="${DEPLOY_ROUTING_PUBLIC_PORT:-8196}"
ROUTING_INTERNAL_PORT="${DEPLOY_ROUTING_INTERNAL_PORT:-8197}"   # prod internal (no auth)
KNN_PORT="${DEPLOY_KNN_PORT:-8194}"
# A rippled-in post's coords/group — used to prove the new /v1/group-proximity
# route answers 200 (not 404) after a routing deploy. Override to a live example.
PROBE_LAT="${DEPLOY_PROBE_LAT:-51.5}"
PROBE_LNG="${DEPLOY_PROBE_LNG:--0.1}"
PROBE_GROUPID="${DEPLOY_PROBE_GROUPID:-21250}"
PROBE_MODE="${DEPLOY_PROBE_MODE:-drive}"

# Local (this host) — batch-prod bind mount + the spatial containers it calls.
LOCAL_REPO="${DEPLOY_LOCAL_REPO:-$(cd "$SCRIPT_DIR/.." && pwd)}"
COMPOSE="${DEPLOY_COMPOSE:-docker compose}"
LOCAL_SPATIAL_SERVICE="${DEPLOY_LOCAL_SPATIAL_SERVICE:-spatial}"          # routing-go container
LOCAL_KNN_SERVICE="${DEPLOY_LOCAL_KNN_SERVICE:-spatial-knn}"             # spatial-go container
LOCAL_SPATIAL_CONTAINER="${DEPLOY_LOCAL_SPATIAL_CONTAINER:-freegledocker-spatial}"
LOCAL_SPATIAL_INTERNAL_PORT="${DEPLOY_LOCAL_SPATIAL_INTERNAL_PORT:-8194}" # batch calls this (no auth)
BATCH_CONTAINER="${DEPLOY_BATCH_CONTAINER:-freegledocker-batch-prod}"

# Timeouts (seconds).
BUILD_TIMEOUT="${DEPLOY_BUILD_TIMEOUT:-300}"
APIV2_HEALTH_TIMEOUT="${DEPLOY_APIV2_HEALTH_TIMEOUT:-160}"   # ×3s loop; `monit restart` is queued and can take several minutes to actually cycle
GRAPH_LOAD_TIMEOUT="${DEPLOY_GRAPH_LOAD_TIMEOUT:-600}"   # 7.3G routing graph rebuild
STOP_GRACE="${DEPLOY_STOP_GRACE:-15}"

# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------
DRY_RUN=false
ASSUME_YES=false
ONLY=""            # comma list: apiv2,routing,knn,local,batch  (empty = auto)
FORCE=false        # deploy a component even if change-detection says unchanged
SKIP_LOCAL=false

usage() { sed -n '2,60p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run)   DRY_RUN=true ;;
    --yes|-y)    ASSUME_YES=true ;;
    --force)     FORCE=true ;;
    --skip-local) SKIP_LOCAL=true ;;
    --only)      ONLY="$2"; shift ;;
    --only=*)    ONLY="${1#*=}" ;;
    --nodes)     DEPLOY_NODES="$2"; shift ;;
    --nodes=*)   DEPLOY_NODES="${1#*=}" ;;
    --branch)    BRANCH="$2"; shift ;;
    -h|--help)   usage 0 ;;
    *) echo "unknown arg: $1" >&2; usage 1 ;;
  esac
  shift
done

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
c_red=$'\033[0;31m'; c_grn=$'\033[0;32m'; c_yel=$'\033[0;33m'; c_blu=$'\033[0;34m'; c_off=$'\033[0m'
log()  { printf '%s[deploy]%s %s\n' "$c_blu" "$c_off" "$*"; }
ok()   { printf '%s[ ok ]%s %s\n' "$c_grn" "$c_off" "$*"; }
warn() { printf '%s[warn]%s %s\n' "$c_yel" "$c_off" "$*"; }
die()  { printf '%s[FAIL]%s %s\n' "$c_red" "$c_off" "$*" >&2; exit 1; }
run()  { if $DRY_RUN; then printf '%s[dry ]%s %s\n' "$c_yel" "$c_off" "$*"; else eval "$@"; fi; }
rsh()  { $SSH $SSH_OPTS "$1" "$2"; }                    # rsh <node> <remote-cmd>
want() { [ -z "$ONLY" ] || [[ ",$ONLY," == *",$1,"* ]]; }  # component selected?

# ---------------------------------------------------------------------------
# Resolve the target commit and what changed
# ---------------------------------------------------------------------------
cd "$LOCAL_REPO" || die "cannot cd $LOCAL_REPO"
log "fetching origin/$BRANCH ..."
$DRY_RUN || git fetch origin "$BRANCH" >/dev/null 2>&1 || die "git fetch failed"
TARGET_COMMIT="$(git rev-parse --short "origin/$BRANCH")" || die "no origin/$BRANCH"
log "target commit: $TARGET_COMMIT  nodes: $DEPLOY_NODES  only: ${ONLY:-<auto>}  dry-run: $DRY_RUN"

# changed_between <deployed> <target> <pathspec...> -> returns 0 if any changed
changed_between() { local a="$1" b="$2"; shift 2; [ -n "$(git diff --name-only "$a".."$b" -- "$@" 2>/dev/null)" ]; }

if ! $ASSUME_YES && ! $DRY_RUN; then
  printf '%sDeploy %s to: %s ? [y/N] %s' "$c_yel" "$TARGET_COMMIT" "$DEPLOY_NODES" "$c_off"
  read -r reply; [[ "$reply" =~ ^[Yy]$ ]] || die "aborted"
fi

# ---------------------------------------------------------------------------
# curl helpers (remote)
# ---------------------------------------------------------------------------
rcode() { rsh "$1" "curl -s -o /dev/null -w '%{http_code}' 'http://localhost:$2$3' 2>/dev/null"; }
gp_path="/v1/group-proximity?lat=$PROBE_LAT&lng=$PROBE_LNG&groupid=$PROBE_GROUPID&mode=$PROBE_MODE"

# ---------------------------------------------------------------------------
# monit: enable monitoring for a service (if off) and wait until monit reports it
# OK. This is how the deploy proves the success invariant — a service left "Not
# monitored" (e.g. after a manual routing restart) would auto-recover on crash only
# once monit is watching it again. Idempotent: already-OK services return at once.
# ---------------------------------------------------------------------------
monit_ensure_ok() {  # <node> <svc>
  local node="$1" svc="$2"
  $DRY_RUN && { warn "[$node] (dry) would ensure monit monitors $svc"; return 0; }
  rsh "$node" '
    SVC="'"$svc"'"; MC="'"$MONIT_CMD"'"
    for i in $(seq 1 16); do
      LINE=$($MC summary 2>/dev/null | grep -E "(^|[[:space:]])${SVC}([[:space:]]|$)" | head -1)
      case "$LINE" in
        "")                echo "$SVC absent from monit"; exit 3 ;;
        *"Not monitored"*) $MC monitor "$SVC" >/dev/null 2>&1 || true ;;  # enable, then wait a cycle
        *OK*)              echo "$SVC OK (monitored)"; exit 0 ;;
      esac
      sleep 15
    done
    echo "$SVC not OK: $LINE"; exit 2
  ' || die "[$node] monit: $svc not monitored+OK"
  ok "[$node] monit: $svc monitored + OK"
}

# ---------------------------------------------------------------------------
# Component: apiv2 (monit-managed)
# ---------------------------------------------------------------------------
deploy_apiv2() {
  local node="$1"; local ts; ts="$(date +%Y%m%d-%H%M%S)"
  log "[$node] apiv2: backup + build"
  run "rsh '$node' 'cd $REMOTE_APIV2_DIR && cp -a iznik-server-go iznik-server-go.bak-predeploy-$ts'"
  run "rsh '$node' 'cd $REMOTE_APIV2_DIR && timeout $BUILD_TIMEOUT $GO_BIN build -o iznik-server-go .'" || die "[$node] apiv2 build failed"
  $DRY_RUN && { warn "[$node] apiv2: dry-run, skip restart"; return; }
  log "[$node] apiv2: monit restart + verify (pid cycled, health 200, 0 panics)"
  rsh "$node" '
    BIN=$(stat -c %Y '"$REMOTE_APIV2_DIR"'/iznik-server-go)
    # Match the Go process by EXACT NAME (pgrep -x), never by full cmdline (pgrep -f).
    # monit starts apiv2 via a `/bin/bash -c "... ./iznik-server-go ..."` wrapper AND
    # this very verify loop runs as `bash -c "...stat .../iznik-server-go..."` - both
    # carry the string "iznik-server-go" in their argv, so a -f match also counts those
    # shells. That self-match kept the process count >= 2 forever, so the loop never saw
    # "exactly one" and every node timed out despite a healthy restart. -x matches only
    # the process whose name is exactly iznik-server-go (the Go binary).
    OLD=$(pgrep -x iznik-server-go | head -1)
    sudo monit restart iznik-server-go
    for i in $(seq 1 '"$APIV2_HEALTH_TIMEOUT"'); do
      sleep 3
      # `monit restart` is QUEUED and can take 20-30s to act, and the old process
      # then drains gracefully, so old+new briefly coexist. Wait until EXACTLY ONE
      # Go process runs AND its pid differs from the pre-restart pid - the definitive
      # signal that monit has fully cycled to the freshly-built binary and it owns the
      # port (apiv2 is single-process, prefork disabled).
      PIDS=$(pgrep -x iznik-server-go)
      [ "$(echo "$PIDS" | grep -c .)" = "1" ] || continue
      PID=$(echo "$PIDS" | head -1)
      [ -n "$OLD" ] && [ "$PID" = "$OLD" ] && continue
      H=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 --max-time 5 http://localhost:'"$APIV2_PORT$APIV2_HEALTH_PATH"' 2>/dev/null)
      [ "$H" = "200" ] || continue
      P=$(tail -60 /tmp/iznik-server-go.out 2>/dev/null | grep -ciE "panic|nil pointer|fatal error")
      [ "$P" = "0" ] && { echo "OK pid=$PID (was ${OLD:-none}) health=200 panics=0"; exit 0; }
      echo "PANICS=$P after restart (pid=$PID)"; exit 3
    done
    # Timed out: dump the state so a false-negative is diagnosable, not opaque.
    NOW=$(pgrep -x iznik-server-go | tr "\n" " ")
    echo "apiv2 did not come healthy on new binary: oldpid=${OLD:-none} nowpids=[${NOW}] binary_mtime=$BIN health=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 --max-time 5 http://localhost:'"$APIV2_PORT$APIV2_HEALTH_PATH"' 2>/dev/null)"
    sudo monit summary 2>/dev/null | grep -i iznik-server-go
    exit 2
  ' || die "[$node] apiv2 verify failed"
  monit_ensure_ok "$node" "$MONIT_APIV2_SVC"
  ok "[$node] apiv2 live on $TARGET_COMMIT"
}

# ---------------------------------------------------------------------------
# Component: routing (UNMONITORED — manual stop/start; 7.3G graph reload)
# ---------------------------------------------------------------------------
deploy_routing() {
  local node="$1"; local ts; ts="$(date +%Y%m%d-%H%M%S)"
  log "[$node] routing: backup + build (unmonitored service)"
  run "rsh '$node' 'cd $REMOTE_ROUTING_DIR && cp -a iznik-routing-go iznik-routing-go.bak-predeploy-$ts'"
  run "rsh '$node' 'cd $REMOTE_ROUTING_DIR && timeout $BUILD_TIMEOUT $GO_BIN build -o iznik-routing-go .'" || die "[$node] routing build failed"
  $DRY_RUN && { warn "[$node] routing: dry-run, skip restart/graph-reload"; return; }
  log "[$node] routing: unmonitor, stop old, start new, reload graph (~minutes), verify group-proximity"
  rsh "$node" '
    '"$MONIT_CMD"' unmonitor '"$MONIT_ROUTING_SVC"' 2>/dev/null || true   # stop monit racing the manual restart
    sudo killall -SIGINT iznik-routing-go 2>/dev/null || true
    # Liveness by EXACT process name, not -f: this very rsh shell has "iznik-routing-go"
    # in its own argv, so pgrep -f would self-match and the grace loop would never break
    # (always force-killing). The kernel comm truncates to 15 chars, so the name is
    # iznik-routing-g (iznik-routing-go is 16).
    for i in $(seq 1 '"$STOP_GRACE"'); do pgrep -x iznik-routing-g >/dev/null || break; sleep 1; done
    pgrep -x iznik-routing-g >/dev/null && { sudo killall -9 iznik-routing-go; sleep 2; }
    cd '"$REMOTE_ROUTING_DIR"'
    sudo bash -c ". ./.env; nohup ./iznik-routing-go >> /tmp/iznik-routing-go.out 2>&1 &"
    for i in $(seq 1 '"$((GRAPH_LOAD_TIMEOUT/12))"'); do
      sleep 12
      H=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:'"$ROUTING_PUBLIC_PORT"'/health 2>/dev/null)
      OOM=$(dmesg 2>/dev/null | tail -3 | grep -ci "out of memory\|killed process")
      if [ "$H" = "200" ]; then
        GP=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:'"$ROUTING_INTERNAL_PORT$gp_path"'" 2>/dev/null)
        [ "$GP" != "200" ] && { echo "routing healthy but group-proximity=$GP (route missing?)"; exit 3; }
        # Reach-engine probe. health and group-proximity BOTH pass on the PBF
        # fallback, so they cannot tell "engine loaded" from "engine silently
        # absent" — on 2026-09-02 a graphSnapVersion bump met version-1
        # artifacts, every node reported clean, and /v1/reach-* 503d for ~16h.
        # 503 means the engine did not load; 400 is this empty body being
        # rejected by a live engine, which is the pass we want.
        if [ "${DEPLOY_SKIP_REACH_PROBE:-0}" = "1" ]; then
          echo "OK health=200 group-proximity=200 reach-probe=skipped"; exit 0
        fi
        RU=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d "{}" \
             "http://localhost:'"$ROUTING_INTERNAL_PORT"'/v1/reach-union" 2>/dev/null)
        if [ "$RU" = "503" ]; then
          # Two different faults answer 503, and they need opposite responses.
          # A REFUSING line means the engine loaded fine but its partition is
          # not the one the stored labels were built against (the config
          # pairing record) - the artifacts are wrong for this database, and
          # serving them would empty every nearby feed (2026-09-03). Anything
          # else is the engine not loading at all.
          REFUSED=$(grep "reach: REFUSING" /tmp/iznik-routing-go.out 2>/dev/null | tail -1)
          if [ -n "$REFUSED" ]; then
            echo "routing up but REACH ENGINE REFUSED: partition does not match config.reach_partition_fp"
            echo "  $REFUSED"
            exit 6
          fi
          echo "routing up but REACH ENGINE NOT LOADED (reach-union=503) - stale artifacts?"; exit 5
        fi
        # Which partition this node is now serving; the operator should see
        # the same value on every node, and on the stored labels.
        FP=$(curl -s http://localhost:'"$ROUTING_PUBLIC_PORT"'/health 2>/dev/null | sed -n "s/.*\"reach_partition_fp\":\"\([0-9]*\)\".*/\1/p")
        echo "OK health=200 group-proximity=200 reach-union=$RU reach-partition=${FP:-unknown}"; exit 0
      fi
      [ "${OOM:-0}" != "0" ] && { echo "OOM during graph load"; exit 4; }
    done
    echo "routing did not come healthy within '"$GRAPH_LOAD_TIMEOUT"'s"; exit 2
  ' || die "[$node] routing verify failed"
  monit_ensure_ok "$node" "$MONIT_ROUTING_SVC"   # re-enable monitoring + assert OK (leaves it monitored)
  ok "[$node] routing live on $TARGET_COMMIT"
}

# ---------------------------------------------------------------------------
# Component: knn (monit-managed)
# ---------------------------------------------------------------------------
deploy_knn() {
  local node="$1"; local ts; ts="$(date +%Y%m%d-%H%M%S)"
  log "[$node] knn: backup + build"
  run "rsh '$node' 'cd $REMOTE_KNN_DIR && cp -a iznik-spatial-go iznik-spatial-go.bak-predeploy-$ts'"
  run "rsh '$node' 'cd $REMOTE_KNN_DIR && timeout $BUILD_TIMEOUT $GO_BIN build -o iznik-spatial-go .'" || die "[$node] knn build failed"
  $DRY_RUN && { warn "[$node] knn: dry-run, skip restart"; return; }
  rsh "$node" '
    sudo monit restart iznik-spatial-go
    for i in $(seq 1 60); do
      sleep 3
      [ "$(curl -s -o /dev/null -w "%{http_code}" http://localhost:'"$KNN_PORT"'/health 2>/dev/null)" = "200" ] && { echo OK; exit 0; }
    done; echo "knn not healthy"; exit 2
  ' || die "[$node] knn verify failed"
  monit_ensure_ok "$node" "$MONIT_KNN_SVC"
  ok "[$node] knn live on $TARGET_COMMIT"
}

# ---------------------------------------------------------------------------
# Per-node driver: pre-flight, single pull, then changed components in order
# ---------------------------------------------------------------------------
deploy_node() {
  local node="$1"
  log "===== node $node ====="
  rsh "$node" 'true' || die "[$node] unreachable"
  # Pre-flight: monit daemon must be up (it is the success invariant's foundation).
  rsh "$node" 'pgrep -x monit >/dev/null || systemctl is-active --quiet monit' \
    || die "[$node] monit daemon not running"
  # Pre-flight: clean tree + cluster Synced (skip cluster check with DEPLOY_SKIP_WSREP=1).
  local dirty; dirty="$(rsh "$node" "cd $REMOTE_APIV2_DIR && git status --porcelain | grep '^ M' || true")"
  [ -n "$dirty" ] && die "[$node] tracked local changes in repo — refusing to pull"
  if [ "${DEPLOY_SKIP_WSREP:-0}" != "1" ]; then
    local st; st="$(rsh "$node" 'mysql -N -e "SHOW STATUS LIKE '\''wsrep_local_state_comment'\''" 2>/dev/null | awk "{print \$2}"')"
    [ -n "$st" ] && [ "$st" != "Synced" ] && die "[$node] galera not Synced (state=$st)"
    log "[$node] pre-flight ok (tree clean, wsrep=${st:-n/a})"
  fi
  local deployed; deployed="$(rsh "$node" "cd $REMOTE_APIV2_DIR && git rev-parse --short HEAD")"
  log "[$node] deployed=$deployed target=$TARGET_COMMIT"

  # Decide components: explicit --only wins; otherwise change-detect deployed..target.
  local do_apiv2=false do_routing=false do_knn=false
  if [ -n "$ONLY" ]; then
    want apiv2 && do_apiv2=true; want routing && do_routing=true; want knn && do_knn=true
  elif $FORCE; then
    do_apiv2=true do_routing=true do_knn=true
  else
    changed_between "$deployed" "$TARGET_COMMIT" 'iznik-server-go'  && do_apiv2=true
    changed_between "$deployed" "$TARGET_COMMIT" 'iznik-routing-go' && do_routing=true
    changed_between "$deployed" "$TARGET_COMMIT" 'iznik-spatial-go' && do_knn=true
  fi
  # Only .go changes warrant a rebuild (tests are still .go, but harmless to rebuild).
  log "[$node] plan: apiv2=$do_apiv2 routing=$do_routing knn=$do_knn"

  if $do_apiv2 || $do_routing || $do_knn; then
    run "rsh '$node' 'cd $REMOTE_APIV2_DIR && git pull --ff-only'" || die "[$node] git pull failed"
    local now; now="$(rsh "$node" "cd $REMOTE_APIV2_DIR && git rev-parse --short HEAD")"
    [ "$now" = "$TARGET_COMMIT" ] || $DRY_RUN || die "[$node] pulled $now != target $TARGET_COMMIT"
    $do_apiv2   && deploy_apiv2   "$node"
    $do_knn     && deploy_knn     "$node"
    $do_routing && deploy_routing "$node"
  else
    log "[$node] no component changes to build"
  fi

  # Success invariant (always, even on a no-op node): monit up and monitoring every
  # managed service. Repairs any service left "Not monitored" by an earlier manual restart.
  if [ "${DEPLOY_SKIP_MONIT:-0}" != "1" ]; then
    log "[$node] asserting monit is monitoring everything: $MONIT_SERVICES"
    for svc in $MONIT_SERVICES; do monit_ensure_ok "$node" "$svc"; done
  fi
  ok "[$node] done"
}

# ---------------------------------------------------------------------------
# Local (this host): spatial containers batch-prod calls, + batch sanity
# ---------------------------------------------------------------------------
deploy_local() {
  $SKIP_LOCAL && { log "skip-local set"; return; }
  ( ! want local && [ -n "$ONLY" ] && ! want batch ) && return
  local base; base="$(git rev-parse --short HEAD)"   # what the local tree was on
  local routing_changed=false knn_changed=false
  if [ -n "$ONLY" ]; then
    want local && { routing_changed=true; knn_changed=true; }
  elif $FORCE; then routing_changed=true; knn_changed=true
  else
    changed_between "$base" "$TARGET_COMMIT" 'iznik-routing-go' && routing_changed=true
    changed_between "$base" "$TARGET_COMMIT" 'iznik-spatial-go' && knn_changed=true
  fi
  # Bring the local tree to target so the rebuilt containers carry the new code.
  if [ "$base" != "$TARGET_COMMIT" ] && ! $DRY_RUN; then
    warn "local tree at $base, target $TARGET_COMMIT — pull master into $LOCAL_REPO before rebuilding containers"
  fi
  if $routing_changed; then
    log "local: rebuild $LOCAL_SPATIAL_SERVICE (routing-go) container [batch group-proximity path]"
    run "$COMPOSE build $LOCAL_SPATIAL_SERVICE"
    run "$COMPOSE up -d --no-deps $LOCAL_SPATIAL_SERVICE"
    $DRY_RUN || {
      log "local: waiting for $LOCAL_SPATIAL_CONTAINER healthy (graph reload)"
      for i in $(seq 1 "$((GRAPH_LOAD_TIMEOUT/12))"); do
        [ "$(docker inspect --format '{{.State.Health.Status}}' "$LOCAL_SPATIAL_CONTAINER" 2>/dev/null)" = "healthy" ] && break
        sleep 12
      done
      local gp; gp="$(docker exec "$LOCAL_SPATIAL_CONTAINER" sh -c "curl -s -o /dev/null -w '%{http_code}' 'http://localhost:$LOCAL_SPATIAL_INTERNAL_PORT$gp_path'" 2>/dev/null)"
      [ "$gp" = "200" ] && ok "local: batch group-proximity path = 200" || die "local: batch group-proximity=$gp"
    }
  fi
  if $knn_changed; then
    log "local: rebuild $LOCAL_KNN_SERVICE (spatial-go) container"
    run "$COMPOSE build $LOCAL_KNN_SERVICE"
    run "$COMPOSE up -d --no-deps $LOCAL_KNN_SERVICE"
  fi
  # batch-prod is a bind mount: no restart, just confirm the schedule still parses.
  if want batch || [ -z "$ONLY" ]; then
    $DRY_RUN || {
      docker exec "$BATCH_CONTAINER" php artisan schedule:list >/dev/null 2>&1 \
        && ok "local: batch-prod schedule parses (bind mount live)" \
        || warn "local: batch-prod schedule:list failed — check console.php"
    }
  fi
}

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
deploy_local
for node in $DEPLOY_NODES; do
  deploy_node "$node"
done
ok "deploy complete → $TARGET_COMMIT"
