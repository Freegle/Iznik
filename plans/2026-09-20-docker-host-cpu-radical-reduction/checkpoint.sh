#!/bin/bash
# usage: checkpoint.sh HH  -> report for the hour HH:00-HH+1:00 UTC (or up to now)
S=${CPU_TRACE_DIR:-$(dirname "$0")}; cd "$S"; H=$1; boot=$(cat boot-epoch.txt)  # boot-epoch.txt: date +%s minus /proc/uptime at trace start
f0=$(date -d "$(date +%F) $H:00:00 UTC" +%s); f1=$((f0+3600)); now=$(date +%s); [ $f1 -gt $now ] && f1=$now
echo "=== HOUR $H: $(date -d @$f0 +%H:%M)-$(date -d @$f1 +%H:%M) UTC ==="
awk -v from=$f0 -v to=$f1 -f cgreport.awk cgsample.log | head -8
echo "--- exited-process CPU by command (s), top 25"
pre=$(ls preexist-*.txt | head -1)
awk -v from=$(( (f0-boot)*1000 )) -v to=$(( (f1-boot)*1000 )) -v pre=$pre -f attr2.awk cgroup-ids.txt exectrace.log 2>/dev/null | head -25
echo "--- long-lived batch processes now (cputimes s)"
cg=$(ls -d /sys/fs/cgroup/batch.slice/docker-*.scope | while read d; do grep -q . $d/cgroup.procs && echo $d; done | head -1)
ps -o pid,etimes,cputimes,args --sort=-cputimes -p $(cat $(ls -d /sys/fs/cgroup/batch.slice/docker-$(docker inspect -f '{{.Id}}' freegledocker-batch-prod).scope)/cgroup.procs | tr '\n' ',' | sed 's/,$//') 2>/dev/null | head -6 | cut -c1-110
echo "--- routing memsummary"; docker exec freegledocker-spatial curl -s 127.0.0.1:6060/debug/memsummary | grep -E "NumGC|HeapAlloc"
