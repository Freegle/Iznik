#!/bin/bash
# Per node: WHOLE-NODE cpu, mysqld cpu, load, and the longest running query.
#
# Reports the node as well as mysqld because db2 and db3 also run
# iznik-spatial-go and iznik-routing-go, which take several cores during a
# dataset rebuild. Measuring mysqld alone reads 2.1 cores on a box that is
# actually at 6.9, and the load average then looks inexplicable.
for h in db2 db3; do
  timeout 60 ssh -o ConnectTimeout=8 $h-internal '
    p=$(pgrep -x mysqld); n=$(nproc)
    a=$(awk "{print \$14+\$15}" /proc/$p/stat)
    s1=$(awk "/^cpu /{u=\$2+\$3+\$4+\$7+\$8; print u\" \"u+\$5+\$6}" /proc/stat)
    sleep 20
    b=$(awk "{print \$14+\$15}" /proc/$p/stat)
    s2=$(awk "/^cpu /{u=\$2+\$3+\$4+\$7+\$8; print u\" \"u+\$5+\$6}" /proc/stat)
    node=$(echo "$s1 $s2" | awk -v n=$n "{du=\$3-\$1; dt=\$4-\$2; if (dt>0) printf \"%.2f\", n*du/dt; else print \"?\"}")
    myd=$(echo "scale=2;($b-$a)/2000" | bc)
    lq=$(mysql -B --skip-column-names -e "select ifnull(max(time),0) from information_schema.processlist where command not in (\"Sleep\",\"Daemon\") and id<>connection_id()" 2>/dev/null)
    printf "%s node=%s/%s mysqld=%s load=%s longest=%ss\n" "$(hostname -s)" "$node" "$n" "$myd" "$(cut -d" " -f1 /proc/loadavg)" "${lq:-?}"' 2>/dev/null
done
