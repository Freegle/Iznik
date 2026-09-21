#!/bin/bash
# Sample /proc every 0.5s for N seconds inside the batch container; attribute CPU ticks to artisan commands.
N=${1:-300}
end=$(( $(date +%s) + N ))
declare -A maxt cmd
while [ $(date +%s) -lt $end ]; do
  for s in /proc/[0-9]*/stat; do
    pid=${s#/proc/}; pid=${pid%/stat}
    read -r line < "$s" 2>/dev/null || continue
    rest=${line##*) }
    set -- $rest
    t=$(( ${12} + ${13} ))   # utime+stime in ticks (fields 14,15 overall)
    if [ -z "${cmd[$pid]}" ]; then
      c=$(tr '\0' ' ' < /proc/$pid/cmdline 2>/dev/null)
      case "$c" in *artisan*) c=$(echo "$c" | sed -E 's/.*artisan //; s/ --.*//; s/ -.*//; s/ +$//');; php*) c="php-other";; *) continue;; esac
      cmd[$pid]="$c"
    fi
    [ "$t" -gt "${maxt[$pid]:-0}" ] && maxt[$pid]=$t
  done
  sleep 0.5
done
declare -A sum cnt
for pid in "${!maxt[@]}"; do c=${cmd[$pid]}; sum[$c]=$(( ${sum[$c]:-0} + ${maxt[$pid]} )); cnt[$c]=$(( ${cnt[$c]:-0} + 1 )); done
hz=$(getconf CLK_TCK)
echo "window ${N}s, CLK_TCK=$hz; CPU seconds by command (processes seen):"
for c in "${!sum[@]}"; do printf "%8.1f  %5d  %s\n" "$(awk -v s=${sum[$c]} -v h=$hz 'BEGIN{print s/h}')" "${cnt[$c]}" "$c"; done | sort -nr | head -30
