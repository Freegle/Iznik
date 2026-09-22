---
last_reviewed: 2026-09-22
owner: Freegle dev team
---

# Restarting a database node, and rejoining it to the cluster

The database is a Percona XtraDB Cluster (Galera) of two data nodes and an arbitrator. Each
data node keeps its own copy of the data and rejoins the other after a restart by itself. The
arbitrator (`garbd`, on the third machine) holds no data and only votes, so one data node can
be down without the cluster losing quorum. The service wrapper that
systemd runs, `/usr/bin/mysql-systemd`, does the position recovery and the state transfer
for you. Almost every manual step beyond `systemctl stop` and `systemctl start` makes the
rejoin slower, not faster.

## How a node rejoins

- **A clean stop** (`systemctl stop mysql`) writes the node's position to
  `/var/lib/mysql/grastate.dat` as a `seqno`. On the next start the wrapper reads it and
  passes it to `mysqld`; nothing else is needed.
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
   `wsrep_cluster_size`, then `monit start iznik-server-go`.

If the node was down longer than the write-set cache covers, step 5 is an SST and takes 10
to 18 minutes. Let it run. Killing the joiner during an SST is what produces the stale pid
file, the abort loop and the half-copied data directory that then look like a broken node.

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

Galera's `pc.recovery` (on by default) saves the last primary component in `gvwstate.dat`
and will re-form the cluster by itself if all nodes come back with that file intact, which
makes step 2 unnecessary after a clean simultaneous power loss. It cannot help when data
directories have been removed.

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
deploy backups, and 8 GB of swap. **Changed**: `mysql` is masked at boot, `garb` is enabled,
and the deploy script's node list on the Docker host names only the data nodes.

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
3. `monit monitor mysqld`, `monit monitor mysql`, `monit monitor mysql_processes`.
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

To go back to arbitrator-only, reverse it: `monit stop` the six checks, stop and mask
`mysql`, empty the data directory, enable and start `garb`, and take db1 out of the deploy
node list.
