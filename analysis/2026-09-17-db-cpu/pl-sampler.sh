#!/bin/bash
# Processlist sampler.  Ranks DB load by sampling information_schema.processlist,
# NOT events_statements_summary_by_digest (which misses Laravel's prepared
# statements entirely - see plans/2026-09-02-db2-cpu-reduction.md).
#
# One long-lived mysql connection per burst; DO SLEEP() paces it.  Every poll
# emits at least one row (the sampler's own connection, tagged PLSAMPLE), so
# idle polls still count towards the denominator.
set -u
OUT=/root/plsample
mkdir -p "$OUT"
POLLS=${POLLS:-1000}
GAP=${GAP:-0.05}
INTERVAL=${INTERVAL:-240}
BURST="$OUT/burst.sql"

Q='SELECT /*PLSAMPLE*/ unix_timestamp(now(3)), id, user, LEFT(host,40), command, time, IFNULL(state,""), LENGTH(IFNULL(info,"")), LEFT(REPLACE(REPLACE(REPLACE(IFNULL(info,""),"\n"," "),"\r"," "),"\t"," "),400) FROM information_schema.processlist WHERE command NOT IN ("Sleep","Daemon");'

{ for i in $(seq 1 "$POLLS"); do echo "DO SLEEP($GAP);"; echo "$Q"; done; } > "$BURST"

while true; do
  F="$OUT/$(hostname -s)-$(date -u +%Y%m%d).tsv"
  mysql -B --skip-column-names --silent < "$BURST" >> "$F" 2>>"$OUT/err.log"
  gzip -q "$OUT"/*-$(date -u -d yesterday +%Y%m%d).tsv 2>/dev/null
  sleep "$INTERVAL"
done
