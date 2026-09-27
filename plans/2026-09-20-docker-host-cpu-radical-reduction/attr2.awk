# usage: awk -v from=<epoch_ms> -v to=<epoch_ms> -v pre=preexist.txt -f attr2.awk cgroup-ids.txt exectrace.log
# CPU (s) of processes that EXITED in [from,to], attributed to a command key per container.
# Lifetime totals: a process that started before the window is still counted in full when it exits.
function keyof(v0, v1, v2, v3,   n, P, b0, m, k) {
  n=split(v0, P, "/"); b0=P[n];
  if (b0=="php" && v1 ~ /artisan$/) { k="artisan " v2; if (v3 ~ /^--(mode|within-group|languishing|skip|daemon|worker)/) k=k" "v3; return k }
  if ((b0=="sh"||b0=="bash") && v1=="-c" && v2 ~ /artisan/) { m=v2; sub(/.*'artisan' /, "", m); sub(/ .*/, "", m); return "wrapper:"m }
  if (b0=="sh"||b0=="bash"||b0=="dash") return b0" -c "substr(v2,1,40);
  return b0" "substr(v1,1,30)
}
BEGIN { if (pre!="") while ((getline l < pre) > 0) { n=split(l, a, " "); k=""; for (i=5;i<=n;i++) k=k" "a[i]; sub(/^ /,"",k); split(k, W, " "); prek[a[1]]=keyof(W[1], W[2], W[3], W[4]); precpu[a[1]]=a[3]*1000 } }
FNR==NR { cgname[$1]=$2; next }
$1=="E" { pid=$3; ecg[pid]=$5; a0[pid]=""; a2[pid]=""; a4[pid]=""; next }
$1=="A0" { a0[$2]=substr($0, index($0,"|")); next }
$1=="A2" { a2[$2]=substr($0, index($0,"|")); next }
$1=="A4" { a4[$2]=substr($0, index($0,"|")); next }
$1=="X" {
  ts=$2; if (from!="" && ts<from) next; if (to!="" && ts>to) next;
  tg=$3; tid=$4; cg=$6; comm=$7; cpu=$8+$9; wall=$10;
  c = (cg in cgname) ? cgname[cg] : "cg"cg;
  if (tg in a0) { split(a0[tg], A, "|"); split(a2[tg], B, "|"); key=keyof(A[2], A[3], B[2], B[3]) }
  else if (tg in prek) { if (tg!=tid) next; key=prek[tg]" (pre-existing, since snapshot)"; cpu-=precpu[tg]; if (cpu<0) cpu=0 }
  else { if (tg!=tid) next; key=comm" (unknown)" }
  sum[c SUBSEP key]+=cpu; if (tg==tid) cnt[c SUBSEP key]++; tot[c]+=cpu; all+=cpu;
}
END {
  for (k in sum) { split(k, Z, SUBSEP); printf "%10.1f %6d  %-30s %s\n", sum[k]/1000, cnt[k], Z[1], Z[2] | "sort -k1 -nr" }
  close("sort -k1 -nr");
  printf "\nTOTAL exited-process CPU: %.1f s\n", all/1000; for (c in tot) printf "  %-32s %.1f s\n", c, tot[c]/1000 | "sort -k2 -nr"
}
