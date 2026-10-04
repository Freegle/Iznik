---
last_reviewed: 2026-10-04
owner: Freegle dev team
---

# Restarting a database node, and rejoining it to the cluster

The database is a Percona XtraDB Cluster (Galera) of two data nodes and an arbitrator. Each
data node keeps its own copy of the data and rejoins the other after a restart by itself. The
arbitrator (`garbd`, on the third machine) holds no data and only votes, so one data node can
be down without the cluster losing quorum. The arbitrator runs on a small machine, and
its `GALERA_OPTIONS` in `/etc/default/garb` carry the same `evs.suspect_timeout` and
`evs.inactive_timeout` as the data nodes' `wsrep_provider_options`, so a short stall there does not
evict it and drop the cluster to non-primary. Keep the two matched when either changes. The service wrapper that
systemd runs, `/usr/bin/mysql-systemd`, does the position recovery and the state transfer
for you. Almost every manual step beyond `systemctl stop` and `systemctl start` makes the
rejoin slower, not faster.

## How a node rejoins

- **A clean stop** (`systemctl stop mysql`) writes the node's position to
  `/var/lib/mysql/grastate.dat` as a `seqno`. On the next start the wrapper reads it and
  passes it to `mysqld`; nothing else is needed.
- **A node started by hand** (`mysqld --wsrep-new-cluster ...` from a shell rather than through
  `mysql@bootstrap`) is outside systemd: `systemctl is-active mysql` says failed while it serves,
  `systemctl stop mysql` does nothing to it, and monit's stop program (`service mysql stop`)
  does not know about it either. Stop it with `mysqladmin shutdown`, which is the same clean
  stop and writes the position, and start it again with `systemctl start mysql` so it is back
  under the unit. Do this at the first quiet moment after a hand bootstrap; a node left this way
  is unmanaged until someone notices.
- **A kill or a crash** leaves `seqno: -1`. On the next start the wrapper runs
  `mysqld --wsrep-recover` first, which reads the position out of InnoDB's redo log. This
  takes a minute or two and is normal; it is the recovery `mysqld` you see in `ps` before the
  real one.
- **The catch-up** is IST (incremental) if a donor's write-set cache still holds everything
  written since the node's position, otherwise SST (a full copy with xtrabackup, streamed from
  a donor). IST takes seconds to minutes. SST takes 10 to 18 minutes for this database,
  desyncs the donor for the duration, and leaves the joiner refusing connections until it has
  finished. Both are automatic; the donor decides.
- **The write-set cache** (`gcache.size`) is what makes IST possible. At the cluster's average
  write rate of about 150 KB/s, 2 GB covers about 3.5 hours; a bulk job can turn it over in
  minutes. A node that was down for longer than the cache covers gets an SST. Raising the
  cache is the way to make reboots cheap, and is why the hosting plan sets it to 16 GB.

## What the wrapper refuses, and why

`mysql-systemd` has two deliberate safety gates. Both produce a failed unit with a one-line
message in `journalctl -u mysql`, and both are fixed by waiting or by starting the service
by hand, not by touching files.

- **Within 5 minutes of a reboot**, if `grastate.dat` is missing or holds `seqno: -1`, the
  service will not start automatically: "Node has been rebooted, ... mysql service has not
  been started automatically" or "grastate.dat is missing after reboot". Percona's reason is
  that a node which does not know its position must not be auto-started into a cluster that
  may itself be recovering. After the 5 minutes, or on any manual `systemctl start mysql`
  later, the same start proceeds and runs `--wsrep-recover`.
- **While `mysql@bootstrap.service` is active**, `systemctl start mysql` is refused:
  "PXC is in bootstrap mode. To switch to normal operation, first stop the
  mysql@bootstrap.service then start the mysql service."

Two other messages are symptoms, not causes, and need no action: "Stale PID file" and "mysql
pid file empty or not readable" mean `mysqld` was killed rather than stopped, and the wrapper
found the pid file it left behind. The next start overwrites it.

A half-finished SST is also handled by the wrapper. The joiner leaves a `sst_in_progress`
marker in the data directory, and the next start clears the directory itself before the
next transfer. There is no need to remove `/var/lib/mysql` by hand, and doing so has two
costs: the wrapper then runs `mysqld --initialize`, creating a fresh empty instance and new
TLS certificates, and it deletes `grastate.dat`, which trips the reboot gate above.

## Planned reboot of one node

1. On the load-balancer side nothing is needed: HAProxy sends API traffic to one active
   node with the others as backups, and Galera routes around a missing node.
2. Drain the API on the node first, so clients are not stuck to a node that is about to go
   (`monit stop iznik-server-go`, or follow the deploy drain in the developer docs).
3. `systemctl stop mysql`. Wait for it to return; a busy node can take a minute or two to
   flush. Do not `kill -9` it.
4. Reboot.
5. On boot, `mysql.service` starts by itself because `grastate.dat` holds a real `seqno`.
   Watch it with `journalctl -fu mysql` and `tail -f /var/lib/mysql/<host>.log`. You will see
   "Check if state gap can be serviced using IST" and then either an IST or "Proceeding with
   SST". Until "ready for connections" appears, `mysql` cannot connect; that is the transfer
   in progress, not a hang.
6. Confirm with `mysql -e "SHOW STATUS LIKE 'wsrep_local_state_comment'"` (Synced) and
   `wsrep_cluster_size`, then `systemctl start monit`, which starts the API, spatial and
   routing servers it manages.

Monit is deliberately **not** enabled at boot on the data nodes. When it was, it started and
restarted the services it manages against a node that was still resyncing, and made the
rejoin worse. So after a reboot nothing monit manages runs until a person has seen `Synced`
and started monit by hand. The arbitrator machine is the exception: nothing resyncs there,
and `garbd` needs watching from boot, so monit is enabled at boot on it alone.

If the node was down longer than the write-set cache covers, step 5 is an SST and takes 10
to 18 minutes. Let it run. Killing the joiner during an SST is what produces the stale pid
file, the abort loop and the half-copied data directory that then look like a broken node.

## Cycling a data node, and both in turn

Untested as written: walk it on the read node first, with someone watching. It is the
procedure for a stop and start that is not a reboot: clearing a frozen write-set cache, changing
`gcache.size`, or taking a Percona upgrade. The point of the choreography is that members see
at most a short pause in writes, and nothing on the node is restarted by monit while the
database is away.

**What runs on a data node.** `mysqld`, and under monit the API (`iznik-server-go`), the spatial
server (`iznik-spatial-go`) and the routing server (`iznik-routing-go`); all three talk to the
local database. The load balancer sends API traffic to one active node with the other as backup,
sticky by source address for 30 minutes. The application funnels writes to one node and reads to
the other: the Go API through `MYSQL_HOST` and `MYSQL_HOST_READ` in each node's API `.env`, the
batch through `DB_HOST_IP` and `DB_HOST_READ_IP` on the Docker host, which batch-prod picks up
only when it is recreated ([read/write split](../reference/database-read-write-split.md)).

**Order.** The read node (the load balancer's backup) first, then the write node (the active one).
Wait for the first to be Synced before touching the second. Pick the gap after the backup drain
and before the morning digest, outside the WhatJobs syncs, and tell whoever is on call.

**Per node:**

1. Pre-flight. Both nodes Synced, cluster size 3, `wsrep_flow_control_paused` near zero, the
   other node with free disk and a write-set cache that covers the stop (2 GB is about 3.5
   hours). If the node was started by hand, note it: step 5 differs.
2. Move the application off the node. For the read node, point `MYSQL_HOST_READ` at the other
   node in both API `.env` files and `monit restart iznik-server-go` on each (the restart is
   queued, so wait for the pid to change), then set `DB_HOST_READ_IP` and recreate batch-prod.
   For the write node, the same with `MYSQL_HOST` and `DB_HOST_IP`. Recreating batch-prod while
   `ripple:expand` is running leaves its lock held; clear it afterwards or the job skips until
   the lock expires. The cheap alternative is to skip this step: reads (or writes) then fail for
   the few minutes the database is down and resume by themselves. For the write node that is a
   two to three minute write outage; for the read node every read fails, so do not skip it there.
3. Drain the API on the node: `monit unmonitor iznik-server-go`, then
   `killall -SIGQUIT iznik-server-go`. The load balancer's health check marks the node down
   within seconds and sticky clients move. On the active node this sends all API traffic to the
   backup node, where it stays for 30 minutes or more.
4. Take monit's hands off the rest: `monit unmonitor iznik-spatial-go iznik-routing-go mysqld
   mysql mysql_processes`. The spatial and routing servers can keep running; they log database
   errors for the duration and carry on, the spatial server reopens its indexes and the routing
   server keeps its graph.
5. Stop the database cleanly: `systemctl stop mysql`, or `mysqladmin shutdown` for a node that
   was started by hand. Wait until `pgrep -x mysqld` prints nothing. Never `kill -9`.
6. If the stop is for the cache: delete `galera.cache` and `gcache.page.*` from the data
   directory now, and change `gcache.size` in the configuration first if that is the purpose.
   Nothing else in the data directory is touched.
7. `systemctl start mysql`. Watch `journalctl -fu mysql` and the node's error log for the IST
   and "ready for connections", then confirm `wsrep_local_state_comment` Synced and cluster
   size 3. The node is now under the ordinary unit even if it was hand-started before.
8. Give the services back: `monit monitor mysqld mysql mysql_processes iznik-spatial-go
   iznik-routing-go iznik-server-go`; monit starts the API from the node's `.env`. Verify by
   hand rather than by monit: the API answers `/api/group` with 200 on its port, the routing
   server answers `/health` and its internal route, because monit's 15-cycle grace reports a
   dead routing server as OK, the spatial server answers `/health`, the API log has no panics
   or "Error 1" lines since the start, and `monit summary` shows every service OK and none
   "Not monitored".
9. Move the application back by reversing step 2, unless the cycle is also a role swap. The
   load balancer needs nothing: the node returns as its health check passes, and sticky clients
   drift back over the next half hour.

Then the other node, from step 1. A full cycle of both nodes is two short stops of the write
node's duty rather than one, because the roles are swapped across for the second half; keep the
swap if the plan is to leave the roles the other way round, and skip step 9 on the first node.

## A node that crashed or was killed

Just `systemctl start mysql`. The wrapper runs `--wsrep-recover`, finds the position, and
the node rejoins by IST or SST as above. If it is within 5 minutes of a reboot, wait, or
start it by hand; the gate applies only to automatic starts.

## The whole cluster is down

1. On every node, find the most advanced position: read `grastate.dat` if it has a `seqno`
   other than -1, otherwise run `mysqld --wsrep-recover` and read "Recovered position" from
   the log it writes. Pick the node with the highest `seqno`. Bootstrapping from any other
   node loses the transactions it does not have.
2. On that node only, set `safe_to_bootstrap: 1` in `grastate.dat`, then
   `systemctl start mysql@bootstrap`.
3. On the other nodes, `systemctl start mysql`. They join by IST or SST.
4. When all are Synced, on the bootstrap node `systemctl stop mysql@bootstrap` and then
   `systemctl start mysql`, so it is running under the ordinary unit again. Until you do,
   `systemctl start mysql` there is refused.
   If the bootstrap was done by hand with `mysqld --wsrep-new-cluster` instead of the unit, this
   step is `mysqladmin shutdown` followed by `systemctl start mysql` (see the hand-started node
   above). Do it only once the other data node is Synced: the bootstrap node is the only one with
   the data until then.

Galera's `pc.recovery` (on by default) saves the last primary component in `gvwstate.dat`
and will re-form the cluster by itself if all nodes come back with that file intact, which
makes step 2 unnecessary after a clean simultaneous power loss. It cannot help when data
directories have been removed.

## The write-set cache after the node has served an SST

A node can stop reusing its write-set cache: one buffer in the ring that is never released pins
the ring tail, so every later write-set goes into a 128 MB `gcache.page.NNNNNN` file in the data
directory, and because Galera only ever deletes the oldest page, and only once nothing in it is
live, no page is deleted either, whatever `gcache.keep_pages_count` says. It has been seen on the
write node after an SST and an arbitrator eviction in the same evening. The node keeps serving and stays Synced; the only symptom is
its disk filling at the cluster's write rate, about 12 GB a day, while the other data node's
disk is flat. The current Percona version (8.0.46) does this even though its release notes
list PXC-4495, the known form of the bug, as fixed; report a fresh case to Percona with the
error log.

**Check it the day after any SST**, on the donor:

```
ls /var/lib/mysql/gcache.page.* 2>/dev/null | wc -l
mysql -e "SHOW STATUS LIKE 'wsrep_local_cached_downto'; SHOW STATUS LIKE 'wsrep_last_committed'"
```

run the second command twice a few minutes apart. Healthy: no page files, or a handful that
come and go, and `wsrep_local_cached_downto` moving. Frozen: the same `cached_downto` at every
reading while `last_committed` climbs, and a new page file every 10 to 20 minutes. The error
log shows each one as "Created page ... gcache.page.NNNNNN" and never "Deleted page". The
sharpest sign is in the process: one thread of `mysqld` named `galera_recv-0` with hours of CPU
(`for t in /proc/0 0pgrep -o -x mysqld)/task/*; do echo "0 0cat /comm) 0 0awk '{print (+)/100}' /stat)"; done | sort -k2 -rn | head`)
and one such thread per page file behind it with none. That is the page-removal thread spinning
on a buffer it cannot discard, with every later removal thread waiting on it; it also costs the
node a full core for as long as it runs.

**Cure.** There is no runtime fix. Stop the node cleanly, move `galera.cache` and the oldest
`gcache.page.*` file aside for the bug report (the leaked buffer is in them) and delete the rest,
then start it; it rebuilds an empty cache and rejoins
by IST as long as the other data node's cache still covers the stop (2 GB is about 3.5 hours).
Deleting those two things is the one case where removing files from the data directory is
right; leave everything else. The application funnels writes to one node, so if that node is
the one being restarted, writes fail until it is back unless they are moved first. The cycling
recipe above gives the order for the services on the node and for moving writes.

**Keeping it rare.** The leak needs an SST, so the measures that avoid SSTs avoid it: the
16 GB cache in the hosting plan, clean stops, and never wiping a data directory to make a
slow rejoin go away. A page-file count on the data nodes belongs in the host checks. The leak gives days of
warning, but only to something that is counting.

## Things not to do

- `killall -9 mysqld` to make a slow stop or a joining node go away. It leaves `seqno: -1`,
  forces a recovery run on the next start, and if the node was mid-SST leaves a partial data
  directory. Under `Restart=on-abort` systemd then starts the recovery `mysqld` itself, and a
  second `kill -9` lands on that.
- `rm -r /var/lib/mysql`. See above: it turns a possible IST into a certain SST and trips the
  reboot gate.
- `touch /var/lib/mysql/grastate.dat`. An empty file gets past the reboot gate only because
  the wrapper's number test fails on an empty value; it does not give the node a position,
  so the result is still a full SST.
- Touching or removing `/var/run/mysqld/mysqld.pid`. The wrapper creates the directory and
  `mysqld` writes the file; the messages about it are describing a kill that already
  happened.
- Starting `mysql.service` on a node that was bootstrapped. Stop `mysql@bootstrap` first.
- Stopping or restarting the last Synced data node. With one data node already down or
  resyncing, that node is the whole primary component; stopping it dissolves the cluster and
  the restart then needs a bootstrap. Wait for the other node to be Synced first.
- Leaving a hand-bootstrapped node outside systemd. See the hand-started node above.

## Where the evidence is

- `journalctl -u mysql` (and `-u mysql@bootstrap`): every start, stop, refusal and abort,
  with times. This survives a data-directory wipe; the error log does not, because it lives
  in `/var/lib/mysql/<host>.log`.
- `/var/lib/mysql/innobackup.*.log`: the SST logs on both sides, with timestamps at each
  phase; the donor writes `innobackup.backup.log`, the joiner `decompress`, `prepare` and
  `move`.
- `journalctl --list-boots` for reboot times, and `grastate.dat` and `gvwstate.dat` for the
  node's saved position and last primary component.

## Bringing the arbitrator machine back as a full member

db1 runs only the arbitrator. Everything else it used to run is still installed and
configured there, stopped; bringing it back is a matter of resources and order, not of
setting anything up again.

**Still on the machine, unchanged**: Percona with its configuration; the API, spatial and
routing checkouts, binaries and `.env` files; their monit checks in `conf.d`, which monit has
been told to leave alone; the OSM extract and the spatial indexes under `/data`; the log
shipper; its entries in the load balancer, which health-check down. **Removed**: the MySQL
data directory, the reach artefacts under the routing data directory, build caches and old
deploy backups, and 8 GB of swap. **Changed**: `mysql` is masked at boot, `garb` is enabled, monit is enabled at boot on this
machine alone (see the planned-reboot section), the six retired checks carry `mode manual` so
monit never starts them (monit 5.26 and later maps that to `onreboot laststate`, and
`monit status` shows it as `on reboot laststate` under `monitoring mode active`), which the
ModTools host check reads as held on purpose rather than as a warning, and the deploy
script's node list on the Docker host names only the data nodes.

**Before starting anything:**

1. **Disk.** The data directory needs the whole database (about 170 GB in September 2026,
   growing) plus the write-set cache and headroom, so the disk must be at least what the data
   nodes have before Percona starts. If the machine was shrunk, grow the disk first, then
   `growpart /dev/sda 2` and `resize2fs /dev/sda2`; growing is online, only shrinking was not.
2. **Size.** A data node needs the buffer pool RAM and CPU the others have (8 vCPU and 24 GB
   when it was retired). Resize before the state transfer, not after.
3. **Timing.** The state transfer takes 10 to 18 minutes and desyncs the donor, so pick a quiet
   hour outside the backup drain window and tell whoever is on call.

**In this order:**

1. `systemctl stop garb && systemctl disable garb`. The arbitrator and `mysqld` both listen
   on the Galera port. The cluster is two nodes from here until `mysqld` has joined, so do not
   restart db2 or db3 in between.
2. `systemctl unmask mysql && systemctl enable --now mysql`. The empty data directory means an
   SST from a donor; watch `wsrep_local_state_comment` on db1 reach `Synced` and the donor
   return to `Synced`, as above.
3. For every retired check from here on, first delete its `mode manual` line in
   `/etc/monit/conf.d/` (that line is what keeps monit from starting it) and `monit reload`,
   then `monit monitor <name>`. Start with `mysqld`, `mysql` and `mysql_processes` in
   `mysql.conf`.
4. Routing. Rebuild the reach artefacts from the extract it still has:
   `cd /var/www/iznik-routing-go && . ./.env && ./iznik-routing-go reach build`. If the map
   was refreshed on the data nodes since, copy their extract into `/data` first
   ([refreshing the map](../domains-services-and-runbooks.md#refreshing-the-map)). Then
   `monit monitor iznik-routing-go`; monit starts it, and the graph build means `/health`
   answers after about five minutes.
5. Spatial. `monit monitor iznik-spatial-go`. It reopens the indexes under `/data` and
   refreshes them from the local database; an index whose schema moved rebuilds itself.
6. API. `monit monitor iznik-server-go`, then `curl -s http://127.0.0.1:8192/api/group`. Its
   `.env` still points writes and reads at the data nodes, which is right.
7. Deploy. Add `db1-internal` back to `DEPLOY_NODES` in `scripts/deploy-prod.env` on the
   Docker host and run a deploy: the binaries on db1 are frozen at the last deploy before it was
   retired, and the deploy's monit invariant then proves every service is watched again.
8. Load balancer: nothing to do. It never stopped listing db1, and the health checks bring it
   up as a backup on their own.
9. Swap, if wanted: recreate `/swapfile2` at its old 10 GB; the `fstab` entry is unchanged.

**Check**: `wsrep_cluster_size` 3 and `wsrep_cluster_weight` 3 on any node with three
addresses in `wsrep_incoming_addresses`; `monit summary` on db1 all `OK`; the API through the
load balancer still 200.

To go back to arbitrator-only, reverse it: put `mode manual` back on the six checks and
`monit unmonitor` each (not `monit stop`: its stop program calls `service mysql stop`, which a
masked unit refuses, and the check stays monitored), stop and mask `mysql`, empty the data
directory, enable and start `garb`, and take db1 out of the deploy node list.
