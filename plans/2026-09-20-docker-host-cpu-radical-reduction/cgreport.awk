# usage: awk -v from=<epoch> -v to=<epoch> -f cgreport.awk cgsample.log  -> average cores per cgroup and host over the window
$1=="T" { ts=$2; if (ts<from || ts>to) next; split($3,b,"="); split($4,i,"="); if (!(hs)) { hs=ts; hb=b[2]; hi=i[2] } he=ts; heb=b[2]; hei=i[2]; next }
$1=="C" { ts=$2; if (ts<from || ts>to) next; g=$3; u=$4; if (!(g in s)) { s[g]=ts; su[g]=u } e[g]=ts; eu[g]=u }
END {
  hz=100; dt=he-hs; if (dt<=0) { print "no window"; exit }
  printf "host busy: %.2f cores over %d min (%s..%s)\n", (heb-hb)/hz/dt, dt/60, strftime("%H:%M",hs,1), strftime("%H:%M",he,1)
  for (g in s) if (e[g]>s[g]) printf "%8.3f cores  %s\n", (eu[g]-su[g])/1e6/(e[g]-s[g]), g | "sort -nr"
}
