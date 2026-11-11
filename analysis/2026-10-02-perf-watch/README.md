# 24-hour production performance watch, 2026-10-02 14:30 to 2026-10-03 14:30 UTC

Measures the Docker host, db2 and db3 against `plans/2026-09-29-perf-backlog.md` to re-size its
items and find what it misses. Nothing here changes production; every piece is read-only.
**Never commit the TSV, log or json files in this directory**: they hold member ids and reach
polygons. The scripts and `findings.md` are fine to commit.

## What runs, and where

| Where | What | How it was started | Stops |
|---|---|---|---|
| db2, db3 (`/root/`) | `pl-sampler.sh` (processlist ~20x/s in bursts), `longq2.sh` (full text of anything running 10 s+), `cputrack2.sh` (per-minute host/mysqld/spatial/routing CPU), `digestsnap.sh` (hourly performance_schema digest snapshot) | `db-start.sh`: `setsid nohup timeout 25h` each, writes `/root/plsample/` | 25 h after start, or `stop.sh` |
| Docker host | `cgsample.sh` (per-minute cgroup CPU), `bpftrace exectrace.bt` (exact CPU of every exiting process with argv) | `host-start.sh`: transient systemd units `perfwatch-cg`, `perfwatch-bt` | 25 h, or `stop.sh` |
| Docker host | `watch-loop.sh`: every 4 h `checkpoint.sh` then a Claude print-mode pass (`analyst-prompt.md`, interim); at 24 h a final checkpoint, a final pass that rewrites the backlog plan and opens a PR, then `stop.sh` | `start.sh`: systemd unit `perfwatch-loop` | after the final pass |

The host units outlive any shell or Claude session. The db collectors live on the nodes.

## Where the data lands

- `checkpoints/ckpt-<utc>.txt`: one report per window (the thing the analyst reads).
- `findings.md`: the analyst's dated sections, then the final synthesis.
- `db2/`, `db3/`: rsync copies of each node's `/root/plsample/` (`pull.sh`).
- `cgsample.log`, `exectrace.log`, `boot-epoch.txt`, `preexist-*.txt`, `cgroup-ids.txt`: host traces and the
  files `attr2.awk` and `cgreport.awk` in `../2026-09-20-docker-host-cpu/` need.
- `loop.log`, `claude-*.json`, `claude.err`: the loop's own record.

## Checking on it

```
systemctl --no-pager list-units 'perfwatch-*'          # three units active until the end
tail -5 analysis/2026-10-02-perf-watch/loop.log
NOMARK=1 analysis/2026-10-02-perf-watch/checkpoint.sh   # a report now, without moving the window marker
```
The checkpoint header counts the collectors on each node (expect 4/4) and shows disk.

## If something died

- Loop unit gone but collectors alive: `systemd-run -E IS_SANDBOX=1 --unit perfwatch-loop --collect -p WorkingDirectory=/var/www/FreegleDocker -E HOME=/root analysis/2026-10-02-perf-watch/watch-loop.sh`.
  It reads `start-epoch.txt` and carries on from the remaining time.
- Host rebooted: `host-start.sh` again (new boot epoch and pre-existing snapshot; the old `exectrace.log`
  keeps its rows but its timestamps belong to the old boot, so analyse the two boots separately), then the loop as above.
- A node's collectors gone (checkpoint header shows fewer than 4): `db-start.sh` restarts all four on both
  nodes. It moves the earlier files aside into `/root/plsample-0917/`; move them back if they are this run's.
- The final pass did not happen: run it by hand from the repo root (`IS_SANDBOX=1` in the environment, or the CLI refuses `--dangerously-skip-permissions` as root) with the prompt in `analyst-prompt.md`,
  `__MODE__` replaced by `final`.

## Stopping early

`stop.sh` stops the units and the node collectors and pulls the data. Then `systemctl stop perfwatch-loop`.

## Method notes

`../2026-09-17-db-cpu/README.md` and `../2026-09-20-docker-host-cpu/README.md` explain the collectors and
their traps: processlist not the digest table on db2; rank a saturated node by statement time not thread
share; mysqld is not the node; a shell's own pattern matches itself.

## Events during the run

- 2026-10-02 17:33-17:37 UTC: production deploy of master 8158c0c7a8 (apiv2 rebuilt and restarted on db2 ~17:35 and db3 ~17:36, one process cycle each; batch code changed via the bind-mount pull at 17:33; status container restarted 17:38). A short apiv2 blip on each node around those minutes is the deploy, not a finding.
- 2026-10-03 08:30 UTC, found by hand (not from the checkpoints): `browse:backfill-max-distance` (routes/console.php, dailyAt 02:40, withoutOverlapping 720) ran 02:40-08:11, scanned 156,121 members and made one `GET /apiv2/town/near` per member over the PUBLIC API from this host's own public address (~6,500 requests/h 03:00-09:00 both days against ~1,000/h by day), to correct 1,018 (136,697 already consistent, 18,331 no location). Each call runs an isochrone on db3's routing server and returns a reach polygon; it is why db3's routing process runs ~1 core overnight and why the member-facing slider endpoint answers in 3-8 s through the morning (241 responses over 3 s in the 08:00 hour). Not in the backlog. The final pass should add it as a candidate: skip members whose inputs are unchanged, call the routing server directly, or batch.
- Also 2026-10-03: the 141 x 503 on /apiv2/message/count at 03:02 on db2 = breaker opened after the local routing server timed out during the 03:00 spatial rebuild pin (apiv2 log 03:02:04). Same cause as the pass-4 candidate 1.
- 2026-10-03 10:40 UTC, found by hand: db3 free disk falling 0.5 GB/h (64 G at 18:28 10-02 -> 56 G at 10:37 10-03). Not table data (db2 holds the same dataset and is flat at 73 G). It is Galera gcache overflow: /var/lib/mysql/gcache.page.000000-000133, 134 files, 17 GB, oldest 2026-10-02 03:29, one new 128 MB page every ~15 min, never unlinked although gcache.keep_pages_size=0 (gcache.size=2G, page_size=128M, recover=yes on both nodes). db2 has none. Both Synced/Primary, no flow control, no long transaction. Operator decision: controlled mysqld restart on db3 (failover to db2) clears the pages; raising gcache.size also needs a restart. Not a perf-backlog item; recorded here because the analyst sees db3's disk figure each checkpoint.
- 2026-10-03 11:50 UTC, confirmed on the load balancer (applb /etc/haproxy/haproxy.cfg, backend api_server_backend): `server db3 ... check` is the ONLY active server; db1 and db2 are `check backup`; `balance leastconn`, `stick on src` with a 30-minute stick table. So db2's apiv2 serves client traffic only after db3's apiv2 restarts (yesterday's deploys at 11:22 and 17:36), via stickiness, decaying for hours; it fell to health checks only (60/h) at 04:10 today when db2 desynced for the backup, exactly as on 10-02 (05:00-11:59 at 60/h, back at 12:00 after the 11:22 restart). Loki `sum by (hostname) (count_over_time({source="api"}[1h]))`: db-3 alone served 517k-994k requests/h 07:00-11:00 today; db-2 59-60. CONSEQUENCE for the synthesis: the 14:28-22:39 windows on 10-02 had db2 carrying 30-50% of apiv2 load (not the steady state); 04:10-14:28 on 10-03 is the steady state (db3 alone). Candidate for the backlog: make db2 an active weighted server (its 8 cores sit at ~2 all morning while db3 is saturated by waits), subject to the write-funnel convention (both apiv2 instances already write to db3 and read db2).
- 2026-10-03 14:28 UTC: final checkpoint (`ckpt-20261003-1428.txt`, window 11:26-14:28) and the final pass. Collectors 4/4 on both nodes and all three host units active to the end; no container recreated during the run. The whole-run synthesis is the last section of `findings.md`; the refined backlog is `plans/2026-09-29-perf-backlog.md` (PR "plans: perf backlog re-measured over 24 hours (2026-10-02/03)"). The db3 full-run sampler file (1.67 M rows, 362 MB plus the 10-02 archive) is larger than `../2026-09-17-db-cpu/analyse.mjs` can read in one string (Node's 512 MB string limit); `tmp/analyse-stream.mjs`, a readline copy of it, produced the full-run ranking. `tmp/` is working data and is not committed.
