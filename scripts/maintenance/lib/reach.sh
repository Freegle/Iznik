# shellcheck shell=bash
#
# Profile "reach": carry a refreshed road map into the routing servers that serve
# members (db2, db3) and the one batch calls (the spatial container on this host).
#
# spatial:update-data (batch, monthly) downloads the map and rebuilds the
# deprivation file on this host. Routing does not read the map directly: it loads
# a saved road network and region layout (the reach artifacts), and every post's
# stored notes are tied to one layout by its fingerprint. So a new map means, in
# this order (docs/developers/reference/spatial-servers.md, "The artifacts and the
# stored notes are one versioned pair"):
#
#   housekeep  make the previous refresh permanent: copy staged notes into the live
#              column, delete the previous layout's leaf rows, drop the kept
#              previous artifacts. Until this runs a refresh can be rolled back
#              cheaply; it must run before the next refresh stages its notes, which
#              overwrite the staged column.
#   build      a new road network, layout and tables from the map, here, in a
#              throwaway container from the same image as the spatial service.
#   stage      notes for every post under the new layout, written beside the live
#              ones (reach_labels_next, leaf rows stamped with the new fingerprint).
#   ship       the artifacts and the deprivation file to each node, beside the live
#              ones.
#   switch     a top-up of the notes for posts that arrived since, then the pairing
#              record, then each routing server in turn: this host's container, the
#              standby node, the active node. Each must come back serving the new
#              fingerprint before the next is touched.
#   strand     posts labelled by the old engine between the top-up and the switch
#              get their note cleared and are labelled again by the new engine.
#
# Every step is idempotent and gated; a dry run prints the plan after the real
# read-only preflight. The previous artifacts stay on every server as reach.prev
# until the next housekeep, which is what --rollback swaps back.

MAINT_WINDOW_REACH="${MAINT_WINDOW_REACH:-0030-0600}"
MAINT_REACH_DATA="${MAINT_REACH_DATA:-$MAINT_REPO/iznik-routing-go/data}"
MAINT_REACH_SPATIAL_CONTAINER="${MAINT_REACH_SPATIAL_CONTAINER:-freegledocker-spatial}"
MAINT_REACH_SPATIAL_SERVICE="${MAINT_REACH_SPATIAL_SERVICE:-spatial}"
# Routing servers on the data nodes, the standby first: a node only follows once
# the one before it serves the new layout.
MAINT_REACH_NODES="${MAINT_REACH_NODES:-db2-internal db3-internal}"
MAINT_REACH_NODE_DIR="${MAINT_REACH_NODE_DIR:-/var/www/iznik-routing-go}"
MAINT_REACH_NODE_CSV="${MAINT_REACH_NODE_CSV:-/data/uk_lsoa_quintile.csv}"
MAINT_REACH_ROUTING_SVC="${MAINT_REACH_ROUTING_SVC:-iznik-routing-go}"
MAINT_REACH_PUBLIC_PORT="${MAINT_REACH_PUBLIC_PORT:-8196}"
MAINT_REACH_INTERNAL_PORT="${MAINT_REACH_INTERNAL_PORT:-8197}"
MAINT_REACH_BOOT_TIMEOUT="${MAINT_REACH_BOOT_TIMEOUT:-300}"
MAINT_REACH_SLEEP_MS="${MAINT_REACH_SLEEP_MS:-20}"
MAINT_REACH_EXPORT_WORKERS="${MAINT_REACH_EXPORT_WORKERS:-4}"
MAINT_REACH_HOUSEKEEP_MIN_DAYS="${MAINT_REACH_HOUSEKEEP_MIN_DAYS:-7}"
MAINT_REACH_MIN_FREE_GB_LOCAL="${MAINT_REACH_MIN_FREE_GB_LOCAL:-20}"
MAINT_REACH_MIN_FREE_GB_NODE="${MAINT_REACH_MIN_FREE_GB_NODE:-10}"
MAINT_REACH_BACKFILL="${MAINT_REACH_BACKFILL:-php artisan ripple:backfill-reach-labels --sleep-ms=100}"

REACH_STATE="$MAINT_STATE_DIR/reach"
REACH_ROLLBACK=false
REACH_HOUSEKEEP_ONLY=false
REACH_ARTIFACTS="graph.snap partition.snap matrices.snap leaftables.snap"

# ---------------------------------------------------------------------------
# Reads
# ---------------------------------------------------------------------------
# The node whose database the batch writes to; every SQL here runs on it.
reach_db_node() {
  local ip n
  ip=$(batch_env DB_HOST_IP)
  for n in $MAINT_DB_NODES; do
    rq "$n" "hostname -I" 2>/dev/null | tr ' ' '\n' | grep -qx "$ip" && { echo "$n"; return 0; }
  done
  return 1
}

sqlq() {  # <query> -> rows, tab separated; read-only, runs in dry runs too
  rq "$REACH_DB" "mysql -N -B iznik -e $(printf '%q' "$1")"
}

# Apply a file of statements, one per line, on one connection, pacing each with
# MAINT_REACH_SLEEP_MS: the Galera rule for bulk changes. Printed, not run, in a
# dry run.
sql_stream() {  # <description> <file>
  local desc=$1 file=$2 n
  n=$(grep -c . "$file" 2>/dev/null || echo 0)
  [ "$n" -gt 0 ] || { info "$desc: nothing to do"; return 0; }
  if $DRY; then
    log dry "would [$REACH_DB] $desc: $n statements, e.g. $(head -1 "$file")"
    return 0
  fi
  log act "[$REACH_DB] $desc: $n statements"
  local pause; pause=$(awk -v m="$MAINT_REACH_SLEEP_MS" 'BEGIN{printf "%.3f", m/1000}')
  # shellcheck disable=SC2086
  sed "s/\$/ DO SLEEP($pause);/" "$file" | timeout 21600 ssh $MAINT_SSH_OPTS "$REACH_DB" "mysql iznik" \
    || die "$desc failed part way; it is safe to run again"
}

live_fp() { sqlq "SELECT value FROM config WHERE \`key\` = 'reach_partition_fp'" | head -1; }

# The layout fingerprint of an artifact set, read from the matrices header
# (magic FRGM3SNAP, then the overlay and partition fingerprints, little-endian
# uint64) without loading the engine.
artifact_fp() {  # <host> <dir>
  rq "$1" "od -An -t u8 -j 17 -N 8 '$2/matrices.snap' 2>/dev/null | tr -d ' '"
}

served_fp() {  # <host> -> the fingerprint its routing server reports
  rq "$1" "curl -s --max-time 10 http://127.0.0.1:$MAINT_REACH_PUBLIC_PORT/health" 2>/dev/null \
    | sed -n 's/.*"reach_partition_fp":"\([0-9]*\)".*/\1/p'
}

local_served_fp() {
  docker exec "$MAINT_REACH_SPATIAL_CONTAINER" wget -qO- -T 10 "http://127.0.0.1:$MAINT_REACH_PUBLIC_PORT/health" 2>/dev/null \
    | sed -n 's/.*"reach_partition_fp":"\([0-9]*\)".*/\1/p'
}

state_get() { cat "$REACH_STATE/$1" 2>/dev/null; }
state_set() { $DRY && return 0; mkdir -p "$REACH_STATE"; printf '%s\n' "$2" > "$REACH_STATE/$1"; }

map_is_new() {  # the map on this host is newer than the road network built from it
  [ "$(stat -c %Y "$MAINT_REACH_DATA/uk-latest.osm.pbf" 2>/dev/null || echo 0)" -gt \
    "$(stat -c %Y "$MAINT_REACH_DATA/reach/graph.snap" 2>/dev/null || echo 0)" ]
}

# ---------------------------------------------------------------------------
# Throwaway containers from the spatial image, with the live container's
# database settings and network, so the export and apply read and write the
# same database the routing servers do.
# ---------------------------------------------------------------------------
reach_image() { docker inspect -f '{{.Config.Image}}' "$MAINT_REACH_SPATIAL_CONTAINER" 2>/dev/null; }

reach_db_args() {  # writes an env file into the run directory; prints docker run options
  local envf="$RUN_DIR/spatial.env" h args=""
  docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$MAINT_REACH_SPATIAL_CONTAINER" \
    | grep -E '^(MYSQL_|DB_)' > "$envf"
  chmod 600 "$envf"
  for h in $(docker inspect -f '{{range .HostConfig.ExtraHosts}}{{.}} {{end}}' "$MAINT_REACH_SPATIAL_CONTAINER"); do
    args="$args --add-host $h"
  done
  echo "--env-file $envf --network $(docker inspect -f '{{.HostConfig.NetworkMode}}' "$MAINT_REACH_SPATIAL_CONTAINER")$args"
}

reach_tool() {  # <description> <timeout s> <docker run options> -- <spatial-server args...>
  local desc=$1 t=$2 opts=$3; shift 4
  ACT_TIMEOUT=$t act local "$desc" \
    "docker run --rm $opts --entrypoint spatial-server $(reach_image) $*"
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
reach_preflight() {
  phase preflight
  REACH_DB=$(reach_db_node) || die "cannot tell which data node the batch writes to"
  info "database writes go to $REACH_DB"
  docker inspect "$MAINT_REACH_SPATIAL_CONTAINER" >/dev/null 2>&1 || die "$MAINT_REACH_SPATIAL_CONTAINER is not running"

  LIVE=$(live_fp)
  [ -n "$LIVE" ] || die "config.reach_partition_fp is missing: without the pairing record nothing guards a layout change"
  local fp n
  fp=$(artifact_fp local "$MAINT_REACH_DATA/reach")
  [ "$fp" = "$LIVE" ] || die "this host's artifacts carry $fp, the pairing record says $LIVE"
  fp=$(local_served_fp)
  [ "$fp" = "$LIVE" ] || die "the spatial container serves ${fp:-nothing}, the pairing record says $LIVE"
  for n in $MAINT_REACH_NODES; do
    check_reachable "$n"
    fp=$(served_fp "$n")
    [ "$fp" = "$LIVE" ] || die "$n serves ${fp:-nothing}, the pairing record says $LIVE"
  done
  info "every routing server serves layout $LIVE, as the pairing record says"

  # The artifacts must be read by the same reach code that built them: the nodes
  # run what their checkout was built from, this host builds from its checkout.
  local mine theirs
  mine=$(git -C "$MAINT_REPO" rev-parse HEAD:iznik-routing-go 2>/dev/null)
  for n in $MAINT_REACH_NODES; do
    theirs=$(rq "$n" "git -C $MAINT_REACH_NODE_DIR rev-parse HEAD:iznik-routing-go 2>/dev/null")
    [ "$mine" = "$theirs" ] || die "$n's routing source ($theirs) differs from this host's ($mine): deploy routing first"
  done

  local free
  free=$(df -BG --output=avail "$MAINT_REACH_DATA" | tail -1 | tr -dc 0-9)
  [ "${free:-0}" -ge "$MAINT_REACH_MIN_FREE_GB_LOCAL" ] || die "this host has ${free}G free, needs $MAINT_REACH_MIN_FREE_GB_LOCAL"
  for n in $MAINT_REACH_NODES; do
    free=$(rq "$n" "df -BG --output=avail $MAINT_REACH_NODE_DIR | tail -1" | tr -dc 0-9)
    [ "${free:-0}" -ge "$MAINT_REACH_MIN_FREE_GB_NODE" ] || die "$n has ${free}G free, needs $MAINT_REACH_MIN_FREE_GB_NODE"
  done
}

# ---------------------------------------------------------------------------
# Housekeep: make the last switch permanent
# ---------------------------------------------------------------------------
reach_housekeep() {
  phase housekeep
  local prev switched days
  [ "$(state_get housekept)" = "$LIVE" ] && { info "layout $LIVE is already housekept"; return 0; }
  switched=$(state_get switched-at)
  if [ -n "$switched" ]; then
    days=$(( ($(date -u +%s) - $(date -u -d "$switched" +%s)) / 86400 ))
    [ "$days" -ge "$MAINT_REACH_HOUSEKEEP_MIN_DAYS" ] \
      || die "layout $LIVE went live $days days ago; housekeeping waits $MAINT_REACH_HOUSEKEEP_MIN_DAYS so it can still be rolled back"
  fi
  prev=$(state_get prev-fp)

  sqlq "SELECT msgid FROM rippling_reach WHERE reach_labels_next_fp = $LIVE" \
    | awk -v fp="$LIVE" '{print "UPDATE rippling_reach SET reach_labels = reach_labels_next WHERE msgid = " $1 " AND reach_labels_next_fp = " fp " LIMIT 1;"}' \
    > "$RUN_DIR/housekeep-copy.sql"
  sql_stream "copy staged notes into the live column" "$RUN_DIR/housekeep-copy.sql"

  if [ -n "$prev" ]; then
    # One post at a time, the shape ReachService::storeLabels uses on this table.
    sqlq "SELECT DISTINCT msgid FROM rippling_reach_leaves WHERE fp = $prev" \
      | awk -v fp="$prev" '{print "DELETE FROM rippling_reach_leaves WHERE msgid = " $1 " AND fp = " fp ";"}' \
      > "$RUN_DIR/housekeep-leaves.sql"
    sql_stream "delete layout $prev's leaf rows" "$RUN_DIR/housekeep-leaves.sql"
  fi

  act local "drop the previous artifacts here" "rm -rf '$MAINT_REACH_DATA/reach.prev'"
  local n
  for n in $MAINT_REACH_NODES; do
    act "$n" "drop the previous artifacts" "rm -rf '$MAINT_REACH_NODE_DIR/data/reach.prev' '$MAINT_REACH_NODE_CSV.prev'"
  done
  state_set housekept "$LIVE"
  info "layout $LIVE is permanent; rolling back now means a full refresh"
}

# ---------------------------------------------------------------------------
# Build, stage, ship
# ---------------------------------------------------------------------------
reach_build() {
  phase build
  local next="$MAINT_REACH_DATA/reach.next" f
  ACT_TIMEOUT=3600 act local "rebuild the spatial image from this checkout" "cd '$MAINT_REPO' && docker compose build '$MAINT_REACH_SPATIAL_SERVICE'"
  act local "empty the build directory" "rm -rf '$next' && mkdir -p '$next'"
  # The build commands write to data/reach under their working directory.
  local opts="-v $MAINT_REACH_DATA:/data:ro -v $next:/work/data/reach -w /work -e OSM_PBF_PATH=/data/uk-latest.osm.pbf -e DEPRIVATION_CSV=/data/uk_lsoa_quintile.csv"
  reach_tool "build the road network from the map" 5400 "$opts" -- reach build
  reach_tool "divide it into regions" 3600 "$opts" -- reach partition
  reach_tool "build the region tables" 3600 "$opts" -- reach leaftables --dir data/reach
  $DRY && { NEXT=dry-run; return 0; }
  for f in $REACH_ARTIFACTS; do [ -s "$next/$f" ] || die "the build left no $f"; done
  NEXT=$(artifact_fp local "$next")
  [ -n "$NEXT" ] && [ "$NEXT" != 0 ] || die "cannot read the new layout's fingerprint"
  [ "$NEXT" != "$LIVE" ] || die "the new layout has the live fingerprint $LIVE; refusing to stage over it"
  state_set next-fp "$NEXT"
  info "built layout $NEXT"
}

reach_export() {  # <file name in the run directory>
  local opts
  # A long read of every post and its groups: from the batch's read node, not the
  # node taking the writes (a later -e wins over the env file).
  local read_ip; read_ip=$(batch_env DB_HOST_READ_IP)
  opts="$(reach_db_args)${read_ip:+ -e MYSQL_HOST=$read_ip} -v $MAINT_REACH_DATA/reach.next:/next:ro -v $RUN_DIR:/out"
  reach_tool "work out every post's notes under layout $NEXT" 5400 "$opts" -- \
    reach labels-export --dir /next --out "/out/$1" --workers "$MAINT_REACH_EXPORT_WORKERS"
}

reach_apply() {  # <file name in the run directory> [--skip-staged]
  local opts rc
  opts="$(reach_db_args) -v $RUN_DIR:/out:ro"
  if $DRY; then
    log dry "would [local] stage the notes from $1 for layout $NEXT ${2:-}"
    return 0
  fi
  log act "[local] stage the notes from $1 for layout $NEXT ${2:-}"
  # shellcheck disable=SC2046,SC2086
  timeout 21600 docker run --rm $opts --entrypoint spatial-server "$(reach_image)" \
    reach labels-apply --file "/out/$1" --expect-fp "$NEXT" --leaves-table rippling_reach_leaves \
    --sleep-ms "$MAINT_REACH_SLEEP_MS" ${2:-}
  rc=$?
  # 2 is the apply's own final count disagreeing by the posts withdrawn while it
  # ran; the stranded-post pass after the switch covers any post it missed.
  case $rc in
    0) ;;
    2) warn "labels-apply: final count short by posts withdrawn during the run";;
    *) die "labels-apply failed (exit $rc); it is safe to run again";;
  esac
}

reach_stage() {
  phase stage
  reach_export labels.bin
  reach_apply labels.bin
}

reach_ship() {
  phase ship
  local n fp
  for n in $MAINT_REACH_NODES; do
    act "$n" "empty the incoming directory" "rm -rf '$MAINT_REACH_NODE_DIR/data/reach.next' && mkdir -p '$MAINT_REACH_NODE_DIR/data/reach.next'"
    # shellcheck disable=SC2086
    act local "copy the artifacts to $n" \
      "rsync -a --delete -e 'ssh $MAINT_SSH_OPTS' '$MAINT_REACH_DATA/reach.next/' '$n:$MAINT_REACH_NODE_DIR/data/reach.next/'"
    # shellcheck disable=SC2086
    act local "copy the deprivation file to $n" \
      "rsync -a -e 'ssh $MAINT_SSH_OPTS' '$MAINT_REACH_DATA/uk_lsoa_quintile.csv' '$n:$MAINT_REACH_NODE_CSV.next'"
    $DRY && continue
    fp=$(artifact_fp "$n" "$MAINT_REACH_NODE_DIR/data/reach.next")
    [ "$fp" = "$NEXT" ] || die "$n received layout ${fp:-nothing}, expected $NEXT"
    [ "$(rq "$n" "md5sum < '$MAINT_REACH_NODE_DIR/data/reach.next/partition.snap'")" = \
      "$(md5sum < "$MAINT_REACH_DATA/reach.next/partition.snap")" ] || die "$n's partition.snap differs from this host's"
  done
}

# ---------------------------------------------------------------------------
# Switch
# ---------------------------------------------------------------------------
reach_wait_local() {  # <fingerprint>
  local i fp=""
  $DRY && return 0
  for i in $(seq 1 $(( MAINT_REACH_BOOT_TIMEOUT / 5 ))); do
    fp=$(local_served_fp); [ "$fp" = "$1" ] && { info "spatial container serves layout $1"; return 0; }
    sleep 5
  done
  die "the spatial container serves ${fp:-nothing} after ${MAINT_REACH_BOOT_TIMEOUT}s, expected $1"
}

reach_wait_node() {  # <node> <fingerprint>
  local n=$1 want=$2 i fp="" ru gp
  $DRY && return 0
  for i in $(seq 1 $(( MAINT_REACH_BOOT_TIMEOUT / 5 ))); do
    fp=$(served_fp "$n"); [ "$fp" = "$want" ] && break
    sleep 5
  done
  [ "$fp" = "$want" ] || die "$n serves ${fp:-nothing} after ${MAINT_REACH_BOOT_TIMEOUT}s, expected $want; $(rq "$n" "grep 'reach:' /tmp/iznik-routing-go.out | tail -2 | tr '\n' ' '")"
  # 400 = the engine answered and refused an empty body; 503 = engine not loaded.
  ru=$(rq "$n" "curl -s -o /dev/null -w '%{http_code}' --max-time 10 -X POST http://127.0.0.1:$MAINT_REACH_INTERNAL_PORT/v1/reach-union" 2>/dev/null)
  [ "$ru" = 400 ] || die "$n: reach-union answers $ru, expected 400 (engine loaded)"
  gp=$(http_code "$n" "http://127.0.0.1:$MAINT_REACH_INTERNAL_PORT$MAINT_DB_ROUTING_PROBE")
  [ "$gp" = 200 ] || die "$n: group-proximity answers $gp"
  info "$n serves layout $want, reach engine and routes answering"
}

# Stop routing on a node, put a set of artifacts in place, start it through monit
# (which knows how each node starts it), and wait for it to serve <fp>.
reach_node_swap() {  # <node> <in: next|prev> <fp>
  local n=$1 from=$2 fp=$3 d="$MAINT_REACH_NODE_DIR/data" csv="$MAINT_REACH_NODE_CSV" swap
  if [ "$from" = next ]; then
    swap="rm -rf $d/reach.prev && mv $d/reach $d/reach.prev && mv $d/reach.next $d/reach && cp -p $csv $csv.prev && mv $csv.next $csv"
  else
    swap="rm -rf $d/reach.failed && mv $d/reach $d/reach.failed && mv $d/reach.prev $d/reach && { [ ! -f $csv.prev ] || cp -p $csv.prev $csv; }"
  fi
  monit_unmonitor "$n" "$MAINT_REACH_ROUTING_SVC"
  act "$n" "stop routing" "killall -SIGINT iznik-routing-go 2>/dev/null; for i in \$(seq 1 30); do pgrep -x iznik-routing-g >/dev/null || exit 0; sleep 1; done; killall -9 iznik-routing-go; sleep 2; ! pgrep -x iznik-routing-g"
  act "$n" "put the $from artifacts in place" "$swap" || die "$n: could not swap the artifacts"
  ledger "$n artifacts set to $from" "see freegle-maint run reach --rollback"
  act "$n" "start routing" "monit start $MAINT_REACH_ROUTING_SVC"
  reach_wait_node "$n" "$fp"
  monit_monitor "$n" "$MAINT_REACH_ROUTING_SVC"
}

reach_local_swap() {  # <in: next|prev> <fp>
  local d="$MAINT_REACH_DATA" swap
  if [ "$1" = next ]; then
    swap="rm -rf $d/reach.prev && mv $d/reach $d/reach.prev && mv $d/reach.next $d/reach"
  else
    swap="rm -rf $d/reach.failed && mv $d/reach $d/reach.failed && mv $d/reach.prev $d/reach"
  fi
  act local "put the $1 artifacts in place" "$swap" || die "could not swap this host's artifacts"
  act local "recreate the spatial container" "cd '$MAINT_REPO' && docker compose up -d --no-deps '$MAINT_REACH_SPATIAL_SERVICE'"
  reach_wait_local "$2"
}

set_pairing_record() {  # <fp>
  act "$REACH_DB" "set the pairing record to $1" \
    "mysql iznik -e \"UPDATE config SET value = '$1' WHERE \\\`key\\\` = 'reach_partition_fp' LIMIT 1\""
}

# Posts whose note was written by the engine that was live before the switch and
# that carry no note for the layout now live: clear the note and let the backfill
# label them again under the live layout.
reach_strand() {  # <fp now live>
  phase strand
  if $DRY; then
    log dry "would clear and relabel posts holding notes only for the layout before $1"
    return 0
  fi
  sqlq "SELECT r.msgid FROM rippling_reach r WHERE r.reach_labels IS NOT NULL AND r.max_drive_min > 0 \
        AND (r.reach_labels_next_fp IS NULL OR r.reach_labels_next_fp <> $1) \
        AND NOT EXISTS (SELECT 1 FROM rippling_reach_leaves l WHERE l.msgid = r.msgid AND l.fp = $1)" \
    | awk '{print "UPDATE rippling_reach SET reach_labels = NULL WHERE msgid = " $1 " LIMIT 1;"}' \
    > "$RUN_DIR/strand.sql"
  sql_stream "clear the notes of posts labelled by the old layout" "$RUN_DIR/strand.sql"
  if grep -q . "$RUN_DIR/strand.sql"; then
    ACT_TIMEOUT=7200 act local "label them under layout $1" \
      "docker exec ${MAINT_BATCH_CONTAINER:-freegledocker-batch-prod} $MAINT_REACH_BACKFILL"
  fi
}

reach_switch() {
  phase topup
  # Posts initialised since the full export: stage them too, then switch at once,
  # so as few as possible are labelled by the old engine in between.
  reach_export labels-topup.bin
  reach_apply labels-topup.bin --skip-staged

  phase switch
  begin_drain
  set_pairing_record "$NEXT"
  ledger "pairing record set to $NEXT" "mysql: UPDATE config SET value = '$LIVE' WHERE \`key\` = 'reach_partition_fp'"
  reach_local_swap next "$NEXT"
  local n
  for n in $MAINT_REACH_NODES; do reach_node_swap "$n" next "$NEXT"; done
  state_set prev-fp "$LIVE"
  state_set switched-at "$(date -u +%FT%TZ)"
  reach_strand "$NEXT"
}

# ---------------------------------------------------------------------------
# Rollback: the previous layout back, while its artifacts are still kept
# ---------------------------------------------------------------------------
reach_rollback() {
  phase rollback
  local prev n
  prev=$(state_get prev-fp)
  [ -n "$prev" ] || die "no previous layout is recorded"
  [ "$(state_get housekept)" = "$LIVE" ] && die "layout $LIVE was already housekept; the previous artifacts are gone"
  [ -d "$MAINT_REACH_DATA/reach.prev" ] || die "this host has no reach.prev"
  for n in $MAINT_REACH_NODES; do
    rq "$n" "test -d $MAINT_REACH_NODE_DIR/data/reach.prev" || die "$n has no reach.prev"
  done
  begin_drain
  set_pairing_record "$prev"
  reach_local_swap prev "$prev"
  for n in $MAINT_REACH_NODES; do reach_node_swap "$n" prev "$prev"; done
  state_set prev-fp ""
  state_set switched-at ""
  state_set housekept "$prev"
  # Posts labelled by the rolled-back layout carry notes the live one cannot read.
  reach_strand "$prev"
}

# ---------------------------------------------------------------------------
reach_finish() {  # <summary>; a refresh restarts no host, so it does not hold back host maintenance
  COMPLETED=true
  rm -f "$RUN_DIR/spatial.env"
  release_lock
  if $DRY; then
    info "dry run, nothing was changed. Had it been live: $1"
    send_alert "dry run ok reach" "Dry run: preflight passed and nothing was changed. The actions it would have taken are in the log below. Had it been live: $1"
  else
    info "$1"
    date -u +%FT%TZ > "$MAINT_STATE_DIR/last-ok-reach"
    send_alert "ok reach" "$1"
  fi
}

reach_run() {
  reach_preflight
  if $REACH_ROLLBACK; then
    point_of_no_return
    reach_rollback
    reach_finish "rolled back from layout $LIVE to $(live_fp)"
    return
  fi
  if $REACH_HOUSEKEEP_ONLY; then
    point_of_no_return
    reach_housekeep
    reach_finish "layout $LIVE housekept"
    return
  fi
  if ! map_is_new; then
    point_of_no_return
    reach_housekeep
    reach_finish "the map has not changed since layout $LIVE was built; nothing to refresh"
    return
  fi
  point_of_no_return
  reach_housekeep
  reach_build
  reach_stage
  reach_ship
  reach_switch
  local n summary="layout $NEXT live (was $LIVE):"
  summary="$summary spatial container $(local_served_fp)"
  for n in $MAINT_REACH_NODES; do summary="$summary, $(short_name "$n") $(served_fp "$n")"; done
  summary="$summary; $(grep -c . "$RUN_DIR/strand.sql" 2>/dev/null || echo 0) posts relabelled after the switch. Rollback until the next housekeep: freegle-maint run reach --rollback --live"
  reach_finish "$summary"
}
