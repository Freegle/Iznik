# Batch host cgroup slices — work-conserving priority for user-facing services (2026-07-08)

## Why

The batch-prod host (185.44.254.12, 8 vCPU/23G) was measured at load 13.6 with
`batch-prod` burning 428% CPU, 12G of 25G swap in active use, and PSI showing
~25% sustained CPU stall - while `embedding-sidecar` (live apiv2 semantic
search) and `postfix` share the same box with **no resource controls anywhere
in the stack**. Separately, the front-end migration plan
(`plans/2026-06-25-frontend-server-migration.md`, PR #894) partitions services
across VMs to get priority - which wastes whichever box is idle.

cgroup v2 **weights** solve prioritisation without waste: they are
work-conserving ratios that only apply under contention. Batch may use 100% of
an idle machine and is squeezed to its weight share the instant user-facing
work needs the resources. Hard limits (`cpus:`, `mem_limit`) are the opposite -
they reserve capacity that sits idle - so we use them only as a safety ceiling
on batch memory.

Weights are relative **among siblings**, so per-container `cpu_shares` doesn't
give a tier guarantee (ten batch containers at 100 collectively outweigh one
nginx at 400). Slices do: the split holds at tier level regardless of how many
containers each side runs. The same pattern applies later to the edge VM
(image cache vs tile-render storms), collapsing the old plan's VM-A/VM-B split
into one VM.

Prerequisites verified live on the host 2026-07-08: cgroup v2, Docker 29.2.1
with `Cgroup Driver: systemd`, cpu/io/memory controllers delegated,
runc 1.3.4. The only gap is the disk IO scheduler (`none`) - see step 4.

## What

- `interactive.slice` (CPUWeight=800, MemoryLow=4G, IOWeight=500):
  embedding-sidecar, postfix.
- `batch.slice` (CPUWeight=25, MemoryHigh=10G, MemoryMax=14G, IOWeight=50):
  batch-prod, spatial, spatial-knn (the digest-serving path).
- Everything else stays under system.slice (weight 100). Under full contention
  CPU splits roughly 86% / 11% / 3%; idle capacity remains fully usable by
  anyone.
- Wiring: `docker-compose.override.batchprod.yml`, opted into via the host's
  `COMPOSE_FILE`. Dev and CI never load it.
- Also in this change: `spatial`/`spatial-knn` gain the `production` profile.
  The batch host runs `COMPOSE_PROFILES=backend,production,mail` and only got
  spatial because Compose v5 auto-enables hard `depends_on` targets across
  profiles; older docker-compose binaries reject the project as "invalid".
  Explicit inclusion fixes validation everywhere and documents reality.
  (Do NOT "fix" this with `required: false` instead - that would make Compose
  v5 skip starting spatial on the prod host, recreating the 2026-07-03
  digest-hang incident.)

## Deployment (human-gated, on the batch-prod host)

Nothing here restarts services until step 3.

1. Install slice units:
   ```
   cd /var/www/FreegleDocker && git pull
   cp interactive.slice batch.slice /etc/systemd/system/
   systemctl daemon-reload
   ```
2. Opt the host into the override - add to `/var/www/FreegleDocker/.env`:
   ```
   COMPOSE_FILE=docker-compose.yml:docker-compose.override.batchprod.yml
   ```
   Sanity check: `docker compose config | grep -A1 cgroup_parent` shows the
   five services; `docker compose config --services` still lists the same 11.
3. Recreate the five affected containers (cgroup_parent applies at create,
   not live): `docker compose up -d` (recreates only changed services).
   Note spatial takes ~7 min to become healthy (graph rebuild) and batch-prod
   gates on it.
4. IO weights need the bfq scheduler to have any effect:
   ```
   echo bfq > /sys/block/sda/queue/scheduler
   ```
   Persist with a udev rule if load tests look good:
   `/etc/udev/rules.d/60-ioschedulers.rules`:
   `ACTION=="add|change", KERNEL=="sda", ATTR{queue/scheduler}="bfq"`
   (Skippable: CPU + memory weighting works without it; IO pressure was light
   when measured.)

## Verification (trust the cgroup files, not docker inspect)

```
systemctl show interactive.slice -p CPUWeight,MemoryLow,IOWeight
cat /sys/fs/cgroup/batch.slice/cpu.weight            # 25
cat /sys/fs/cgroup/interactive.slice/memory.low      # 4294967296
# per-container scope, e.g.:
cat /sys/fs/cgroup/batch.slice/docker-$(docker inspect -f '{{.Id}}' freegledocker-batch-prod).scope/cpu.weight
grep cgroup2 /proc/mounts                             # want memory_recursiveprot
```

Then watch the thing this exists to fix:
- `/proc/pressure/cpu` some avg60 should fall from ~25% for interactive work;
- embedding-sidecar latency during a busy batch window;
- swap: `free -h` + `grep -E 'pswpin|pswpout' /proc/vmstat` deltas - MemoryHigh
  on batch.slice should stop batch driving the host into swap. If batch jobs
  themselves start thrashing against MemoryHigh, raise it or fix the job; the
  host likely needs a RAM upsize regardless (it was 12G into swap).

Rollback: remove the override from `COMPOSE_FILE`, `docker compose up -d`.

## Non-goals / follow-ups

- wiki-media, wiki-mysql, ors-app, confident_curran are standalone containers
  outside compose; they are being decommissioned/moved by the front-end
  migration and are left in the default tier.
- Why batch-prod burns 4+ cores is a separate investigation (likely the
  undeduped ripple snap loop - see plans/routing-performance-step-change.md).
- The same slice pattern should ship with the edge VM profile (PR #894) to
  protect frontend-nginx/delivery/tusd from tile-server render storms.
