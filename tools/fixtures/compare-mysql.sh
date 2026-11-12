#!/bin/bash
# Compares two MySQL databases of the same schema row by row, and their AUTO_INCREMENT counters.
# Used to show that a change to the fixtures, or to the loader, loads exactly the rows it did before:
# load the old and the new fixtures into two scratch databases and compare them.
#
#   COMPOSE_PROJECT_NAME=freegle-<name> tools/fixtures/compare-mysql.sh <database A> <database B>
#
# Every table is dumped one row to a line, geometry and binary values as hex, and the lines are
# sorted, so a difference in any byte of any value shows. Read-only: it only dumps. Exit 0 when the
# two are identical.
#
# Time columns that hold the moment of loading (the extra steps use NOW()) differ between two loads
# made at different times. Run each load with `SET TIMESTAMP=<same number>;` first to hold the clock.

PREFIX="${COMPOSE_PROJECT_NAME:-freegle}"
A="$1"
B="$2"
if [ -z "$A" ] || [ -z "$B" ]; then
  echo "usage: compare-mysql.sh <database A> <database B>"
  exit 2
fi
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

dump() {
  docker exec "${PREFIX}-percona" mysqldump -uroot -piznik --no-create-info --skip-extended-insert \
    --complete-insert --hex-blob --skip-comments --skip-triggers --no-tablespaces --order-by-primary "$1" 2>/dev/null \
    | grep -v '^/\*!\|^$' | sort
}
counters() {
  docker exec "${PREFIX}-percona" mysql -uroot -piznik -N -e \
    "SELECT table_name, IFNULL(auto_increment, 0) FROM information_schema.tables WHERE table_schema='$1' ORDER BY 1" 2>/dev/null
}

dump "$A" > "$WORK/a.txt"
dump "$B" > "$WORK/b.txt"
status=0
echo "rows: $(wc -l < "$WORK/a.txt") in ${A}, $(wc -l < "$WORK/b.txt") in ${B}"
if cmp -s "$WORK/a.txt" "$WORK/b.txt"; then
  echo "the rows are identical"
else
  echo "the rows differ:"
  diff "$WORK/a.txt" "$WORK/b.txt" | head -20 | cut -c1-300
  status=1
fi
counters "$A" > "$WORK/ai-a.txt"
counters "$B" > "$WORK/ai-b.txt"
if cmp -s "$WORK/ai-a.txt" "$WORK/ai-b.txt"; then
  echo "the AUTO_INCREMENT counters are identical"
else
  echo "the AUTO_INCREMENT counters differ:"
  diff "$WORK/ai-a.txt" "$WORK/ai-b.txt" | head
  status=1
fi
exit $status
