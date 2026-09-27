#!/bin/bash
# every 60s: epoch, host busy jiffies, per-cgroup usage_usec for all docker scopes + slices
while :; do
  ts=$(date +%s)
  read -r _ u n s idle io irq si st _ < /proc/stat
  echo "T $ts busy=$((u+n+s+io+irq+si+st)) idle=$idle load=$(cut -d' ' -f1-3 /proc/loadavg)"
  for f in /sys/fs/cgroup/*.slice/cpu.stat /sys/fs/cgroup/*.slice/docker-*.scope/cpu.stat /sys/fs/cgroup/*.slice/*.slice/cpu.stat; do
    [ -f "$f" ] || continue
    echo "C $ts $(dirname $f | sed 's#/sys/fs/cgroup/##') $(awk '/^usage_usec/{print $2}' $f)"
  done
  sleep 60
done
