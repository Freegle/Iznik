#!/bin/bash
# Capture the FULL text of any query running longer than THRESH seconds, once per
# connection id.  Newlines are preserved as @@NL@@ so `--` comments don't swallow
# the rest of the statement when the text is replayed into EXPLAIN.
THRESH=${THRESH:-45}
OUT=/root/plsample/longq-$(hostname -s).tsv
touch "$OUT"
while true; do
  mysql -B --skip-column-names -e "SELECT unix_timestamp(), id, time, LEFT(host,30), replace(replace(info,'\n','@@NL@@'),'\t',' ') FROM information_schema.processlist WHERE command<>'Sleep' AND time>=$THRESH AND info IS NOT NULL" 2>/dev/null |
  while IFS=$'\t' read -r ts id t host info; do
    grep -q "^[0-9]*	$id	" "$OUT" 2>/dev/null || printf '%s\t%s\t%s\t%s\t%s\n' "$ts" "$id" "$t" "$host" "$info" >> "$OUT"
  done
  sleep 10
done
