#!/bin/bash
# Do the mail and the website admit the SAME people to the same posts?
#
# The overflow lanes were switched off on 2026-08-21 because they did not: members
# were emailed posts browse would not show them, and the reply gate held their
# replies. Both sides now ask one service, and this asks it both ways for real
# members and checks the answers match.
#
# Read-only. Run before turning the lanes back on, and after any change to how a
# surface asks. Usage: ring-surface-agreement.sh [sample-size]
set -u
SAMPLE=${1:-20}
# The index is not published on the host, so ask it the way the batch does - from
# inside the container network.
KNN_EXEC=${RING_KNN_EXEC:-docker exec freegledocker-spatial-knn}
KNN=${SPATIAL_KNN_URL:-http://localhost:8194}
ask() { $KNN_EXEC curl -s --max-time 10 "$@"; }
NODE=${RING_DB_NODE:-db1-internal}

echo "sampling $SAMPLE ring-eligible members from $NODE"
ssh -o ConnectTimeout=10 "$NODE" "mysql iznik -N -B -e \"
  SELECT u.id,
         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(u.settings,'\\\$.browseDensityBand')),'') AS band,
         ST_Y(a.position), ST_X(a.position)
    FROM users u
    JOIN users_approxlocs a ON a.userid = u.id
   WHERE JSON_EXTRACT(u.settings,'\\\$.browseDensityBand') IS NOT NULL
   ORDER BY RAND() LIMIT $SAMPLE\"" > /tmp/ring-members.tsv || exit 1

agree=0; disagree=0; nolanes=0; noposts=0
while IFS=$'\t' read -r id band lat lng; do
  case "$band" in
    dense|medium|sparse) lane="\$.rural.$band" ;;
    *) nolanes=$((nolanes+1)); continue ;;
  esac

  # Browse's direction: which posts admit this member?
  ids=$(ask --get "$KNN/v1/reachoverflow/containing" \
        --data-urlencode "lng=$lng" --data-urlencode "lat=$lat" --data-urlencode "lanes=$lane" \
        | python3 -c "import json,sys
try:
    print(' '.join(str(i) for i in (json.load(sys.stdin).get('in') or [])[:5]))
except Exception:
    pass")
  [ -z "$ids" ] && { noposts=$((noposts+1)); continue; }

  # The mail's direction, for each of those posts: does it admit this member?
  for m in $ids; do
    got=$(ask -X POST -H 'Content-Type: application/json' \
          -d "{\"msgid\":$m,\"points\":[{\"lng\":$lng,\"lat\":$lat,\"lanes\":[\"$lane\"]}]}" \
          "$KNN/v1/reachoverflow/admits")
    case "$got" in
      *"[0]"*) agree=$((agree+1)) ;;
      *) disagree=$((disagree+1)); echo "  DISAGREE member=$id post=$m band=$band -> $got" ;;
    esac
  done
done < /tmp/ring-members.tsv

echo "browse-admitted pairs the mail also admits: $agree"
echo "disagreements: $disagree"
echo "members whose rings admit nothing right now: $noposts"
echo "members with no lane (no band recorded): $nolanes"

# A run that compared nothing has proved nothing. Saying "agree" on an empty
# sample is how a check like this comes to be trusted while it is broken - which
# is exactly what it did the first time it was run.
if [ $((agree + disagree)) -eq 0 ]; then
    echo "NOTHING COMPARED - the index answered for no one. Check $KNN is reachable"
    echo "via '$KNN_EXEC' and that the reachoverflow dataset is ready."
    exit 2
fi
[ "$disagree" -eq 0 ] || { echo "SURFACES DISAGREE - do not enable the lanes"; exit 1; }
echo "surfaces agree on $agree pairs"
