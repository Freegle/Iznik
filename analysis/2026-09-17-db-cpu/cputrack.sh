#!/bin/bash
# Per-minute mysqld + load time series, so "how busy was the node" is answerable
# after the fact rather than only at the moment someone looked.
OUT=/root/plsample/cpu-$(hostname -s).tsv
while true; do
  pid=$(pgrep -x mysqld)
  read -r _ _ _ _ _ _ _ _ _ _ _ _ _ ut st rest < /proc/"$pid"/stat
  printf '%s\t%s\t%s\t%s\n' "$(date -u +%s)" "$(( (ut+st)/100 ))" "$(cut -d' ' -f1 /proc/loadavg)" "$(awk '{print $1}' /proc/uptime)" >> "$OUT"
  sleep 60
done
