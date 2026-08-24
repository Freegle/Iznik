#!/bin/bash
# One-off drain for ripple:drain-deduped-blobs (PR #1402, reach geometry dedup).
# Runs bounded batches until the sweep reports nothing left, backing off while
# db3 (the write node all these statements land on) is busy. Resumable: the
# command keeps its own mark in the config table, so a kill costs at most the
# current batch.
LOG=/var/www/FreegleDocker/scripts/reach-geom-blob-drain.log
MAXLOAD=${MAXLOAD:-14}
LIMIT=${LIMIT:-2000}
FIRST=${FIRST:-}
echo "$(date -u +%H:%M:%S) blob drain starting (maxload=$MAXLOAD limit=$LIMIT)" >> "$LOG"
while true; do
  LOAD=$(ssh -o BatchMode=yes -o ConnectTimeout=10 db3-internal 'cut -d" " -f1 /proc/loadavg' 2>/dev/null)
  [ -z "$LOAD" ] && LOAD=0
  if awk -v l="$LOAD" -v m="$MAXLOAD" 'BEGIN{exit !(l>m)}'; then
    echo "$(date -u +%H:%M:%S) db3 load $LOAD > $MAXLOAD - waiting" >> "$LOG"
    sleep 60
    continue
  fi
  ALL=$(docker exec freegledocker-batch-prod php /var/www/html/artisan \
        ripple:drain-deduped-blobs --limit="$LIMIT" $FIRST 2>&1)
  FIRST=""
  # The command prints its summary and THEN a refusal warning, so take the
  # summary line explicitly - tail -1 grabs the warning and looks unexpected.
  OUT=$(echo "$ALL" | grep -E 'Drained |Sweep complete|Nothing drainable' | tail -1)
  REF=$(echo "$ALL" | grep -cE 'refused \(verification guard\)')
  echo "$(date -u +%H:%M:%S) db3load=$LOAD $OUT" >> "$LOG"
  [ "$REF" -gt 0 ] && echo "$(date -u +%H:%M:%S)   ($REF row(s) refused - live rippling rewrote them; retried next pass)" >> "$LOG"
  case "$OUT" in
    *"Sweep complete"*|*"Nothing to drain"*)
      echo "$(date -u +%H:%M:%S) BLOB DRAIN COMPLETE" >> "$LOG"; break ;;
    *Drained*) : ;;
    *) echo "$(date -u +%H:%M:%S) UNEXPECTED OUTPUT - stopping" >> "$LOG"; break ;;
  esac
  sleep 3
done
