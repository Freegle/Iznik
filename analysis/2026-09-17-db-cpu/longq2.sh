#!/bin/bash
# Capture the FULL text of any query running longer than THRESH seconds.
#
# Appends EVERY sighting, not just the first. The earlier version wrote one row
# per connection id and skipped it thereafter, so the recorded duration was
# whatever the query happened to have reached when first polled - a lower bound,
# not its length. A 43s query was recorded as 19s. Analysis takes max(time) per
# id instead.
#
# Newlines are stored as @@NL@@ so `--` comments do not swallow the rest of the
# statement when the text is replayed into EXPLAIN.
THRESH=${THRESH:-10}
OUT=/root/plsample/longq2-$(hostname -s).tsv
touch "$OUT"
while true; do
  mysql -B --skip-column-names -e "SELECT unix_timestamp(), id, time, LEFT(host,30), replace(replace(info,'\n','@@NL@@'),'\t',' ') FROM information_schema.processlist WHERE command<>'Sleep' AND time>=$THRESH AND info IS NOT NULL" 2>/dev/null >> "$OUT"
  sleep 5
done
