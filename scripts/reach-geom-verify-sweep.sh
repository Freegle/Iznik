#!/bin/bash
# Full-table verification for the reach geometry dedup (PR #1402). The artisan
# checker takes --after/--limit but does not report a resume point, so we compute
# each window's last msgid ourselves with exactly the checker's own selection
# predicate. Fails loudly and stops on the first mismatch.
LOG=/var/www/FreegleDocker/scripts/reach-geom-verify-sweep.log
LIMIT=${LIMIT:-1000}
AFTER=${AFTER:-0}
TOTALROWS=0; TOTALHASH=0
echo "$(date -u +%H:%M:%S) verify sweep starting (limit=$LIMIT)" >> "$LOG"
while true; do
  NEXT=$(ssh -o BatchMode=yes -o ConnectTimeout=10 db2-internal \
    "mysql -N -B iznik -e \"SELECT COALESCE(MAX(msgid),0) FROM (SELECT msgid FROM rippling_reach WHERE msgid > $AFTER AND (polygon_hash IS NOT NULL OR max_polygon_hash IS NOT NULL) ORDER BY msgid LIMIT $LIMIT) t\"" 2>/dev/null)
  if [ -z "$NEXT" ] || [ "$NEXT" = "0" ]; then
    echo "$(date -u +%H:%M:%S) VERIFY SWEEP COMPLETE - rows=$TOTALROWS hashes=$TOTALHASH, 0 failures" >> "$LOG"
    break
  fi
  OUT=$(docker exec freegledocker-batch-prod php /var/www/html/artisan \
        ripple:verify-geometry-dedup --limit="$LIMIT" --after="$AFTER" 2>&1)
  RC=$?
  LAST=$(echo "$OUT" | tail -1)
  if [ $RC -ne 0 ] || ! echo "$LAST" | grep -q '0 dangling, 0 shared-row mismatch(es), 0 blob mismatch(es)'; then
    echo "$(date -u +%H:%M:%S) VERIFY FAILED after=$AFTER rc=$RC" >> "$LOG"
    echo "$OUT" | tail -20 >> "$LOG"
    break
  fi
  H=$(echo "$LAST" | sed -E 's/^Verified ([0-9]+) hash.*/\1/')
  R=$(echo "$LAST" | sed -E 's/.*across ([0-9]+) row.*/\1/')
  TOTALHASH=$((TOTALHASH + H)); TOTALROWS=$((TOTALROWS + R))
  echo "$(date -u +%H:%M:%S) after=$AFTER rows=$R hashes=$H cum_rows=$TOTALROWS" >> "$LOG"
  AFTER=$NEXT
done
