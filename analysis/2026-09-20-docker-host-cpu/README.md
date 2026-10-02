# Instruments for the 2026-09-20 Docker host CPU trace

What the September 2026 Docker host trace found and has not been fixed is in `plans/2026-09-29-perf-backlog.md`.

- `exectrace.bt` - `bpftrace exectrace.bt > exectrace.log` (root). Timestamps are ms since boot;
  record `date +%s` minus the integer part of `/proc/uptime` as `boot-epoch.txt` when you start it.
- `cgsample.sh` - per-minute cgroup CPU; run alongside.
- `cgroup-ids.txt` - `stat -c %i` of every `/sys/fs/cgroup/*.slice/docker-*.scope` plus the
  container name; regenerate after any container is recreated (its id changes).
- `attr2.awk`, `cgreport.awk`, `checkpoint.sh HH` - the reports. `checkpoint.sh` expects the log
  files in `CPU_TRACE_DIR` (default: its own directory) and a `preexist-*.txt` snapshot from
  `ps -e -o pid=,ppid=,cputimes=,etimes=,args=` taken when the tracer started.
- `conntrace.bt`, `reqtrace.bt` - short runs (`timeout 180 bpftrace conntrace.bt`); `reqtrace.bt`
  has the batch container's cgroup id hard-coded (706617 on 2026-09-20) - edit it.
