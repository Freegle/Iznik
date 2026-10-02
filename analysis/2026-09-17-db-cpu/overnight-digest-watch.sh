#!/bin/bash
# Records the daily digest run and both db nodes every 5 minutes, so the 06:00
# start is evidenced whether or not anyone is watching. The question it exists to
# answer: does the run finish inside 07:00-12:00 London now that the carryover no
# longer costs the query its index?
#
# Two things learned the hard way and fixed here:
#  - Do NOT put \t inside the SQL string. It survives the ssh quoting as a
#    literal backslash-t, merging two columns into one and silently misaligning
#    every row against the header.
#  - Sample CPU over 30s, not 5s. Short windows catch bursts: the same node read
#    6.04 cores over 15s and 2.30 over 60s, minutes apart.
OUT=${1:-/var/www/FreegleDocker/analysis/2026-09-17-db-cpu/overnight.tsv}
[ -s "$OUT" ] || printf 'ts\tsent_today\tlatest_send\tshards\tdb2_load\tdb2_node\tdb2_mysqld\tdb3_load\tdb3_node\tdb3_mysqld\n' > "$OUT"

# $1 = host alias -> "<node cores over 30s> <mysqld cores> <load1>"
#
# Reports the WHOLE NODE as well as mysqld. These boxes also run
# iznik-spatial-go and iznik-routing-go, and during a dataset rebuild spatial
# takes several cores: db2 was measured at 7.26 of 8 with mysqld at 1.90.
# Recording mysqld alone would show tomorrow's digest run against a node that
# looks idle while it is nearly full.
node_cpu () {
  timeout 120 ssh -o ConnectTimeout=8 "$1" 'p=$(pgrep -x mysqld); n=$(nproc)
    a=$(awk "{print \$14+\$15}" /proc/$p/stat)
    s1=$(awk "/^cpu /{u=\$2+\$3+\$4+\$7+\$8; print u\" \"u+\$5+\$6}" /proc/stat)
    sleep 30
    b=$(awk "{print \$14+\$15}" /proc/$p/stat)
    s2=$(awk "/^cpu /{u=\$2+\$3+\$4+\$7+\$8; print u\" \"u+\$5+\$6}" /proc/stat)
    node=$(echo "$s1 $s2" | awk -v n=$n "{du=\$3-\$1; dt=\$4-\$2; if (dt>0) printf \"%.2f\", n*du/dt; else print \"?\"}")
    echo "$node $(echo "scale=2; ($b-$a)/3000" | bc) $(cut -d" " -f1 /proc/loadavg)"' 2>/dev/null
}

while true; do
  sent=$(timeout 90 ssh -o ConnectTimeout=8 db2-internal 'mysql iznik -B --skip-column-names -e "select count(*) from email_tracking where email_type=\"UnifiedDigestDaily\" and sent_at >= curdate()"' 2>/dev/null)
  latest=$(timeout 90 ssh -o ConnectTimeout=8 db2-internal 'mysql iznik -B --skip-column-names -e "select ifnull(max(time(sent_at)),\"-\") from email_tracking where email_type=\"UnifiedDigestDaily\" and sent_at >= curdate()"' 2>/dev/null)
  shards=$(docker exec freegledocker-batch-prod sh -c 'c=0; for p in /proc/[0-9]*; do x=$(tr "\0" " " < $p/cmdline 2>/dev/null); case "$x" in *artisan*mode=daily*) case "$x" in *"sh -c"*) ;; *) c=$((c+1));; esac;; esac; done; echo $c' 2>/dev/null)

  read -r n2 m2 l2 <<< "$(node_cpu db2-internal)"
  read -r n3 m3 l3 <<< "$(node_cpu db3-internal)"

  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M)" "${sent:--}" "${latest:--}" "${shards:--}" \
    "${l2:--}" "${n2:--}" "${m2:--}" "${l3:--}" "${n3:--}" "${m3:--}" >> "$OUT"

  sleep 240
done
