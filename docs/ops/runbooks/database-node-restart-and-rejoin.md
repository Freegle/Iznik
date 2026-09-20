---
last_reviewed: 2026-09-20
owner: Freegle dev team
---

# Restarting a database node, and rejoining it to the cluster

The database is a three-node Percona XtraDB Cluster (Galera). Each node keeps its own copy
of the data and rejoins the others after a restart by itself. The service wrapper that
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
   node with the other two as backups, and Galera routes around a missing node.
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
