#!/bin/bash
# Export prod rippling_reach rows for the stage-2 parity harness. READ-ONLY:
# nothing here writes to the database. Uses the Windows-side MobaSSHTunnel DB
# forward (port 1234) with credentials from the checkout's .env.
#
# Selection: recent rows with cells, NO moderator clips (rejected_groups IS
# NULL) so the stored cells are the pure catchment projection, tick >= 3 for a
# non-trivial area. Diversity: dense/medium/sparse bands plus posts nearest to
# four estuary/coastal anchors (Severn, Humber, Mersey, Cornwall).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${1:-$ROOT/iznik-routing-go/data/stage2/prod}"
mkdir -p "$OUT"

export MYSQL_PWD="$(grep -E '^LIVE_DB_PASSWORD=' "$ROOT/.env" | cut -d= -f2-)"
WINIP=$(ip route | awk '/default/{print $3}')
Q() { mysql -h"$WINIP" -P1234 -uroot --connect-timeout=10 -N -A iznik -e "$1"; }

BASE_WHERE="polygon_cells IS NOT NULL AND rejected_groups IS NULL
  AND updated_at >= NOW() - INTERVAL 3 DAY AND tick >= 3"

MSGIDS=""
for band in dense medium sparse; do
  MSGIDS+=" $(Q "SELECT msgid FROM rippling_reach WHERE $BASE_WHERE
     AND density_band='$band' ORDER BY updated_at DESC LIMIT 3")"
done
# Estuary/coastal anchors: nearest candidate post to each.
for anchor in "51.55 -2.65" "53.72 -0.45" "53.39 -2.98" "50.15 -5.07"; do
  set -- $anchor
  MSGIDS+=" $(Q "SELECT msgid FROM rippling_reach WHERE $BASE_WHERE
     ORDER BY ABS(lat-($1))+ABS(lng-($2)) ASC LIMIT 1")"
done

MSGIDS=$(echo $MSGIDS | tr ' ' '\n' | sort -u)
echo "exporting $(echo "$MSGIDS" | wc -l) posts to $OUT"

for id in $MSGIDS; do
  Q "SELECT JSON_OBJECT('msgid', msgid, 'lat', lat, 'lng', lng, 'tick', tick,
        'total_ticks', total_ticks, 'density_band', density_band,
        'updated_at', updated_at, 'schedule', CAST(schedule AS JSON))
     FROM rippling_reach WHERE msgid=$id" > "$OUT/$id.json"
  Q "SELECT HEX(polygon_cells) FROM rippling_reach WHERE msgid=$id" | tr -d '\n' > "$OUT/$id.cells.hex"
  echo "  $id: $(wc -c < "$OUT/$id.cells.hex") hex chars"
done
echo "done"
