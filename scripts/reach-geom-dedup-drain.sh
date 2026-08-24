#!/bin/bash
# One-off drain for ripple:dedup-geometry (PR #1402, reach geometry dedup).
# Runs bounded batches until the sweep reports nothing left, backing off while
# db3 (the write node all these statements land on) is busy. Resumable: the
# command keeps its own mark in the config table, so a kill costs at most the
# current batch.
LOG=/var/www/FreegleDocker/scripts/reach-geom-dedup-drain.log
MAXLOAD=${MAXLOAD:-14}
LIMIT=${LIMIT:-2000}
echo "$(date -u +%H:%M:%S) dedup drain starting (maxload=$MAXLOAD limit=$LIMIT)" >> "$LOG"
while true; do
  LOAD=$(ssh -o BatchMode=yes -o ConnectTimeout=10 db3-internal 'cut -d" " -f1 /proc/loadavg' 2>/dev/null)
  [ -z "$LOAD" ] && LOAD=0
  if awk -v l="$LOAD" -v m="$MAXLOAD" 'BEGIN{exit !(l>m)}'; then
    echo "$(date -u +%H:%M:%S) db3 load $LOAD > $MAXLOAD - waiting" >> "$LOG"
    sleep 60
    continue
  fi
  OUT=$(docker exec freegledocker-batch-prod php /var/www/html/artisan \
        ripple:dedup-geometry --limit="$LIMIT" 2>&1 | tail -1)
  echo "$(date -u +%H:%M:%S) db3load=$LOAD $OUT" >> "$LOG"
  case "$OUT" in
    *"Sweep complete"*|*"Nothing to dedup"*)
      echo "$(date -u +%H:%M:%S) DEDUP DRAIN COMPLETE" >> "$LOG"; break ;;
    *Filled*) : ;;
    *) echo "$(date -u +%H:%M:%S) UNEXPECTED OUTPUT - stopping" >> "$LOG"; break ;;
  esac
  sleep 3
done
