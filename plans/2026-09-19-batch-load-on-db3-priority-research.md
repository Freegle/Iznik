# Running the batch load on db3 at lower priority: what MySQL and Percona actually offer

Research note, 2026-09-19. Question: the 2-node + garbd plan keeps db2 only because the bulk
(batch) load is assumed to interfere with member-facing traffic on db3, in either direction. Are
there established ways to run the batch queries on db3 at lower priority, interruptibly, or both,
without the batch work being stalled indefinitely?

Short answer: **yes for CPU, yes for lock and lock-wait behaviour, no for I/O and buffer pool, and
the thread-pool "priority" feature is the one to avoid.** The pattern the industry actually relies
on is on the application side (small chunks, checkpointed, load-gated with a floor rate), with the
database-side features as backstops. Separately, the premise that two nodes isolate the workloads is
weaker than it looks, because Galera flow control couples them today, measurably.

## 1. Measured state, 2026-09-19

| | db2 | db3 |
|---|---|---|
| cores / RAM | 8 / 23 GB | 12 / 35 GB |
| mysqld CPU at 10:30 UTC | 3.2 cores | 3.7 cores |
| load average (1/5/15) | 3.7 / 4.7 / 4.9 | 3.8 / 5.5 / 6.5 (includes the Go spatial and routing services) |
| InnoDB buffer pool | 6 GB | 16 GB |
| buffer pool disk reads, lifetime average | ~7,000 pages/s | ~470 pages/s |
| `thread_handling` | one-thread-per-connection | one-thread-per-connection |
| `innodb_thread_concurrency` | 250 | 250 |
| `max_execution_time` | 0 (off) | 0 (off) |
| isolation | REPEATABLE-READ | REPEATABLE-READ |
| history list length | 189 | 2 |
| `wsrep_flow_control_sent` (lifetime) | **4,070** in 29 days | 0 |
| `wsrep_flow_control_recv` (lifetime) | 4,460 | **1,914** in 24 days |
| `wsrep_local_recv_queue_avg` / max | 4.0 / **17,432** | 215 / n/a |
| `wsrep_flow_control_paused` | 0.0002 | 0.00009 |
| `gcs.fc_limit` / `fc_factor` | 100 / 1.0 | 100 / 1.0 |
| mysqld effective capabilities | none | none (`CapEff` 0) |
| accounts in use | root only | root only |

Version: PXC 8.0.46-38.1 (the final 8.0 release). Resource groups exist (the three defaults,
`VCPU 0-7` on db1, `0-11` on db3). Every client, batch and apiv2 alike, connects as `root`, so
nothing today can be told apart by account.

The Percona-packaged `mysql.service` on db3 sets
`CapabilityBoundingSet=CAP_IPC_LOCK CAP_DAC_OVERRIDE CAP_AUDIT_WRITE`, with no drop-in. That
matters in section 3.

## 2. The premise: two nodes do not isolate the workloads

Galera flow control: when any node's receive queue exceeds `gcs.fc_limit`, it sends a pause and
**every node stops committing** until that node drains
([Percona, Galera flow control in PXC](https://www.percona.com/blog/galera-flow-control-in-percona-xtradb-cluster-for-mysql/)).
A node that is too busy serving reads to apply write-sets is exactly the case.

- db2 has sent 4,070 pauses in 29 days. db3 has received 1,914 in 24 days. Each one stalled every
  member-facing write on db3 until db2 caught up.
- The fraction of time paused is tiny so far (0.009% on db3), so this is a small effect today. But
  during the digest regression db2's receive queue peaked at 17,432 write-sets against a limit of
  100, and the 09-17 plan recorded the queue average at 4 as "the margin that is left". The
  coupling is real and it scales with db2's load.
- Replication apply on db3 costs about 1% of a core (db1 is the control: identical write-set
  stream, 0.08 cores lifetime). All writes already run on db3. So what would move is the batch
  **read** work: db2's ~3.2 cores, of which about 0.56 is db1's apiv2 reads.

Consolidating onto db3 removes the last cross-node coupling: an idle db2 never sends flow control,
and the nightly backup with `wsrep_desync` on db2 then touches nothing that anyone reads.

The failure case forces the same design anyway. If db3 dies, db2 (8 cores, 6 GB pool) carries
members, writes and batch together. Whatever keeps batch from hurting members on one node is
needed for that day regardless of the steady-state topology.

The vendor-clean answer for reporting load is an asynchronous replica outside the ring, with no
flow control at all
([Severalnines](https://severalnines.com/blog/hybrid-oltpanalytics-database-workloads-galera-cluster-using-asynchronous-slaves/)).
That is the £90/mo option already costed in `plans/database-migration-evaluation-2026-07.md`, and
the shape the PostgreSQL plan mirrors. It is the right answer for analytics; the batch load here is
scheduled jobs that also write, not analytics, and the read/write split already offloads only reads.

## 3. What the database can do, feature by feature

### 3a. Resource groups: the native CPU priority, and it does not starve

MySQL 8.0 and 8.4 resource groups (`CREATE RESOURCE GROUP batch TYPE=USER THREAD_PRIORITY=19
[VCPU=8-11]`, then `SET RESOURCE GROUP batch` per session or `/*+ RESOURCE_GROUP(batch) */` per
statement) control **CPU only**: a Linux nice value and optional core affinity. No I/O, no memory
([MySQL manual](https://dev.mysql.com/doc/refman/8.0/en/resource-groups.html);
[Percona overview](https://www.percona.com/blog/2018/01/25/mysql-8-0-resource_group-overview/)).

This is the property the question asks for. Linux nice is **proportional share**, not strict
priority: nice 19 against nice 0 is a CFS weight of 15 against 1024, so under full contention batch
still gets about 1.5% of each core and never stops, and when the box has spare CPU (db3 is at 30%)
batch runs at full speed. Lower priority, never stalled, and the hand-off between the two is
automatic. Pinning batch to a subset of cores (`VCPU`) adds a hard cap on top, at the cost of
slowing batch when the box is otherwise idle. Priority first, pinning only if peaks demand it.

Constraints, all verified:

- **db3's mysqld cannot use it today.** THREAD_PRIORITY needs `CAP_SYS_NICE`; without it the
  server silently keeps priority 0 and raises warning 4560. mysqld on db3 runs with no
  capabilities, and the packaged unit's bounding set excludes it. Fix is a systemd drop-in
  (`CapabilityBoundingSet=CAP_IPC_LOCK CAP_DAC_OVERRIDE CAP_AUDIT_WRITE CAP_SYS_NICE` plus
  `AmbientCapabilities=CAP_SYS_NICE`) and a mysqld restart, which on the write node means a
  failover window. Fold it into the 8.4 rolling upgrade rather than restarting for it alone.
- Resource groups are unavailable when the thread pool plugin is loaded. It is not loaded here.
- Resource group DDL is local to the server and not replicated. Create it on every node.
- The batch account needs `RESOURCE_GROUP_USER` to run `SET RESOURCE GROUP`.
- Percona documents no PXC restriction; the PXC 8.0 limitations page does not mention the feature.
- Nobody has published a clean benchmark of nice 19 protecting OLTP latency. The mechanism is
  kernel scheduling, well understood, but treat the first digest run on db3 as the benchmark.

### 3b. Thread pool priority queues: the feature to avoid

Percona's thread pool (`thread_handling=pool-of-threads`) has high and low priority queues;
`SET thread_pool_high_prio_tickets=0` puts a connection in the low queue. But the low queue is only
serviced when the high queue is empty, and **Percona has no anti-starvation kick-up timer**. MariaDB
and MySQL Enterprise both have `thread_pool_prio_kickup_timer`; MariaDB's docs say outright that
Percona does not ([MariaDB thread pool](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/buffers-caches-and-threads/thread-pool/thread-pool-in-mariadb);
[Percona thread pool](https://docs.percona.com/percona-server/8.0/threadpool.html)). That is
precisely the indefinite-stall failure the question rules out. It also only orders admission: once
a long batch statement holds a worker it runs at full CPU, and Percona's own test shows batch DML
driving OLTP throughput to near zero even with the priority queues on
([Percona](https://www.percona.com/blog/is-thread-pool-plugin-the-right-choice-for-your-workload/)).
And it is mutually exclusive with resource groups. Not the tool.

### 3c. Interruptible: timeouts and a watchdog

- `max_execution_time` (session variable, or `/*+ MAX_EXECUTION_TIME(ms) */`) kills a SELECT with
  error 3024. **SELECT only**: not UPDATE, DELETE, or the SELECT inside INSERT...SELECT
  ([MySQL](https://dev.mysql.com/blog-archive/server-side-select-statement-timeouts/)). MariaDB's
  `max_statement_time` covers writes; MySQL has no equivalent.
- `pt-kill --match-user batch --busy-time N --kill-query` as a daemon is the standard backstop
  ([Percona Toolkit](https://docs.percona.com/percona-toolkit/pt-kill.html)).
- Either one only works if the job treats 3024 or a killed query as "retry smaller or later",
  which requires chunked, resumable loops. Most of the batch loops now are (#1488, #1542, #1543);
  the ones that are not need the same keyset treatment before this is safe.

### 3d. Locks: make batch the loser in every race

- Short `innodb_lock_wait_timeout` for batch sessions (pt-osc sets 1 s for itself) with retry, so
  batch backs off rather than queueing behind members
  ([Percona](https://www.percona.com/blog/mysql8-hot-rows-with-nowait-skip-locked/)).
- `SELECT ... FOR UPDATE SKIP LOCKED` or `NOWAIT` for queue-style tables.
- `READ COMMITTED` for batch sessions. For UPDATE and DELETE it releases the locks on rows scanned
  but not matched (under REPEATABLE READ they are held to commit), and it lets purge run once other
  transactions commit. It does not shorten a single long statement's read view, so a 20-minute
  SELECT still pins undo history under either level; chunking is the fix for that
  ([Percona](https://www.percona.com/blog/chasing-a-hung-transaction-in-mysql-innodb-history-length-strikes-back/)).
- Metadata locks: a long batch SELECT on db3 holds a shared MDL there. A DDL queued behind it
  blocks every later query on that table
  ([MySQL](https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-performance.html)). Rule:
  no plain DDL while batch runs on db3; use pt-online-schema-change, which retries with short
  lock waits. The pending index adds D and H in the 09-17 plan are the immediate case.

### 3e. Concurrency cap per account

`CREATE USER batch ... WITH MAX_USER_CONNECTIONS n` hard-caps simultaneous batch connections
([MySQL](https://dev.mysql.com/doc/refman/8.0/en/user-resources.html)). The batch host holds 16
connections on db3 and 24 on db2 today. The failure mode is a connect error, not queueing, so cap
concurrency in the scheduler and keep this as a backstop set above it.

### 3f. What does not exist: I/O and buffer pool isolation

- MySQL has **no per-session I/O control**. Only whole-process cgroup `io.weight` exists, and that
  cannot tell batch from members. db2's read load misses the buffer pool about 7,000 pages/s
  against db3's 470, so consolidation lands that on db3's disk. A bigger pool is the only lever.
- Buffer pool: midpoint insertion (`innodb_old_blocks_time=1000`, already the default) stops a
  single scan pass evicting the hot set, and nothing stops repeated scans of the same cold tables
  (the five-minute spatial reconcile, the 31-day admin scans) earning their way in
  ([MySQL](https://dev.mysql.com/doc/refman/8.0/en/innodb-performance-midpoint_insertion.html)).
  There is no per-session quota. db3's pool can grow from 16 GB to about 24 GB (mysqld uses 19 GB
  of 35 today), which also cuts the batch disk reads. Fixing the repeated scans themselves, which
  the 09-17 plan already lists, is the other half.

## 4. The pattern that solves it in practice: throttled, chunked, floor-rated batch

Every widely used tool that runs heavy work against a live database does the same thing:

- **pt-online-schema-change / pt-archiver**: after each chunk, read `Threads_running`; pause above
  `--max-load` (default 25), abort above `--critical-load` (50). The pause has no timeout: the docs
  say it "waits forever" ([pt-osc](https://docs.percona.com/percona-toolkit/pt-online-schema-change.html)).
- **gh-ost**: same gate, plus `--nice-ratio` (for every 1 ms working, sleep n ms), and
  `--critical-load-hibernate-seconds` that hibernates and retries indefinitely
  ([gh-ost](https://github.com/github/gh-ost/blob/master/doc/throttle.md)).
- **Shopify job-iteration / maintenance_tasks**: `throttle_on(backoff:) { condition }`, chunk
  checkpointed, re-enqueued on the signal
  ([maintenance_tasks](https://github.com/Shopify/maintenance_tasks)).
- **PlanetScale's throttler**: lag-driven, small chunks, 1 to 2 s sampling
  ([PlanetScale](https://planetscale.com/blog/anatomy-of-a-throttler-part-1)).

The indefinite-stall concern is real: the pure "pause while loaded" gate in pt-osc and gh-ost does
starve if load never drops, and there are gh-ost issues of jobs stuck throttled. The answer the
tools converge on is to combine three things, and that is what to build:

1. **A duty-cycle floor** (gh-ost's nice ratio). Batch always makes progress at some rate; the
   gate slows it, never stops it.
2. **A load gate with a maximum pause**, say 30 s, after which the next chunk runs regardless.
3. **A job deadline alarm** (the digest must finish by 12:00) so a slowed job is visible instead of
   silently late, which is the exact failure of 2026-09-15.

The signal to gate on: `Threads_running` is the classic and cheap. Better here, because it measures
what members feel, is the apiv2 p95 already exposed to the status service, with
`wsrep_flow_control_paused` and `wsrep_local_recv_queue` as the cluster-health guard.

No named engineering team has published a case study of retiring a reporting replica onto the
primary this way; what is published is the toolset above and the general vendor advice to keep
analytics off the cluster. The batch load here is not analytics, so the toolset applies.

## 5. Recommended stack, in order

1. **A dedicated `batch` MySQL account** (`DB_USERNAME` in `.env.background`, `CREATE USER` on
   the cluster). Prerequisite for everything below, and it makes batch visible in the process list
   and performance schema for the first time.
2. **Session setup on every batch connection**: `SET RESOURCE GROUP batch`, `transaction_isolation
   = READ-COMMITTED`, `max_execution_time` at a generous cap for reads, `innodb_lock_wait_timeout`
   short with retry. Hook point on the Laravel side is a `ConnectionEstablished` listener (a PDO
   init command holds only one statement). Server-side alternative: `init_connect`, which fires
   only for accounts without SUPER, so it would apply to the batch account and skip apiv2's root.
   Prefer the app side; it is in code and tested.
3. **Resource group `batch` with THREAD_PRIORITY=19** on both data nodes, after the `CAP_SYS_NICE`
   drop-in and restart. Add `VCPU` pinning only if the first digest window on db3 shows CPU
   contention hurting p95.
4. **The governor** from section 4 as a shared helper in `iznik-batch`, used by every chunk loop.
5. **Raise db3's buffer pool to about 24 GB** before moving anything. Watch
   `Innodb_buffer_pool_reads` per second and apiv2 p95 for a week.
6. **Move in one reversible step**: `DB_HOST_READ_IP` on the batch host to db3's address. Then
   db1's apiv2 `MYSQL_HOST_READ` to db3 (or itself) so db2 goes fully idle; db1 is leaving in the
   garbd plan anyway. Success check: `wsrep_flow_control_sent` on db2 stops moving.
7. **`pt-kill` on db3 as the backstop**, batch account only, query kill not connection kill, at a
   threshold well above `max_execution_time`.

Capacity sanity check: db3 at 3.7 cores plus db2's 3.2 is about 7 of 12 on average, and the
post-fix digest send peaked db2 at 4.7 cores. Peaks will contend, which is exactly when nice 19
does its job. The nightly block was profiled at under a tenth of db2.

## 6. What this does not decide

Whether to keep db2 at all. After consolidation its justification is availability (failover, SST
donor, backup node), not load. A single MySQL host has no failover, takes its 18-minute backup on
the serving node, and at Krystal's linear pricing saves about £60/mo over ROCK-36 plus ROCK-24.
That is an availability call, and the consolidation work above is worth doing under either answer
because the failover case already requires it.

## Sources not linked above

- Resource groups unavailable with thread pool: [WL#9467](https://dev.mysql.com/worklog/task/?id=9467)
- Per-account limits and pooled connections: [Percona](https://www.percona.com/blog/setting-up-resource-limits-on-users-in-mysql/)
- Multi-writer conflicts and single-writer advice: [Percona](https://www.percona.com/blog/understanding-multi-node-writing-conflict-metrics-in-percona-xtradb-cluster-and-galera/)
- `wsrep_desync` semantics: [Percona](https://www.percona.com/blog/measuring-replication-throughput-percona-xtradb-cluster-wsrep_desync/)
- Two-node clusters with garbd: [galeracluster.com](https://galeracluster.com/library/kb/two-node-clusters.html)
- ProxySQL per-rule `delay` and `timeout` as an alternative throttle point: [Percona](https://www.percona.com/blog/rate-limit-throttle-for-mysql-with-proxysql/). Not recommended here; it adds a component to do what the app can do itself.

## 7. Follow-up questions, 2026-09-19

### 7a. How small can a failover-only db2 go, and what does it save

What db2 runs today (measured): mysqld at 8.6 GB RSS on a 6 GB pool, 168 GB under
`/var/lib/mysql` on a 296 GB root disk (206 GB used), plus the haproxy backup-pool copies of
apiv2, spatial and routing (under 300 MB between them), nginx, redis, exim, beanstalkd, php-fpm.

What it must still do as a standby: apply the write stream (about 1% of a core), hold the data
(168 GB and growing), take the nightly backup (18 minutes on 8 vCPU, compression-bound), act as
the only SST donor for db3 (136 GB), and become the serving node on failover.

Krystal rules ([upgrade/downgrade](https://docs.katapult.io/docs/product/compute/virtual-machines/how-to/upgrade-downgrade-package),
[disks](https://docs.katapult.io/manager/how-to-guides/compute/virtual-machines/disks/system-disks/grow-shrink-a-disk)):
adding vCPU or RAM is done live with no downtime; removing either needs a shutdown; a disk can
only grow. So the failover runbook gains one step, "upsize the package", which is live, and
MySQL 8 resizes `innodb_buffer_pool_size` online. Test that once before relying on it.

Prices from [krystal.io/cloud/pricing](https://krystal.io/cloud/pricing) on 2026-09-19 (the SKUs
are now named C-n; VAT status of the page is not stated). Disk is the part that does not shrink:
the 296 GB disk stays, and the assumption below is that the excess over the package allowance is
billed at the £0.15/GB block-storage rate. Confirm that with Krystal; if a downgrade cannot keep
the disk, a fresh VM plus block storage lands in the same range.

| package | vCPU / RAM | package | disk over allowance | total | saving vs C-24 |
|---|---|---|---|---|---|
| C-24 (today) | 8 / 24 GB | £123.75 | 0 | £123.75 | 0 |
| C-12 | 4 / 12 GB | £67.50 | 146 GB, £21.90 | £89.40 | £34/mo, £410/yr |
| **C-8** | 4 / 8 GB | £51.25 | 171 GB, £25.65 | £76.90 | **£47/mo, £560/yr** |
| C-6 | 2 / 6 GB | £37.50 | 196 GB, £29.40 | £66.90 | £57/mo, £680/yr |
| C-4 | 2 / 4 GB | £27.50 | 221 GB, £33.15 | £60.65 | not viable: no room for a pool |

Recommendation: **C-8 is the floor worth taking.** It keeps a 4 to 5 GB pool, halves nothing
important, and backups and SST stay in the tens of minutes. C-6 saves £10/mo more and roughly
doubles backup and SST time on 2 vCPU. Either way raise gcache from 2 GB to 8 GB with the disk
headroom, which widens the IST window from 190 minutes to about 12 hours.

Whole-cluster arithmetic: today two C-24 plus one C-36 is about £427/mo. Drop db1 for garbd on the
ha VM and squeeze db2 to C-8: about £257/mo, a saving of about £170/mo or £2,000/yr. The db2
squeeze is £47 of that; dropping db1 is the rest.

### 7b. How the interrupted batch work avoids starvation

Three mechanisms, each with its own guarantee. None of them can starve batch on its own, and
together they replace "silently slow for three days" with "visibly late, or a page".

**CPU priority never interrupts anything.** A nice-19 thread is never killed or paused by the
scheduler; it gets a smaller share when cores are contended and full speed when they are not.
db3 is at about 30% CPU, so most of the day batch runs at full speed. The hazard is priority
inversion: a batch chunk holding a row lock a member needs while running at 1.5% of a core.
MySQL has no priority inheritance, so the bound is the chunk. Laravel already keeps separate read
and write connections, so give the read connection nice 19 and the write connection a moderate
value such as nice 10 (about a 9:1 share, so a half-second chunk stretches to a few seconds at
worst, not half a minute), and keep write chunks small.

**The throttle gate slows, never stops.** Between chunks the governor reads the load signal and
sleeps while it is high, with two hard bounds: a maximum pause per chunk (say 30 s), and the chunk
in flight is never touched by the gate. Throughput therefore never drops below
`chunk / (chunk_time + max_pause)`. Under sustained load the job finishes later; it cannot finish
never. This is the fix for pt-osc's "waits forever" gate and gh-ost's indefinite hibernation.

**The kill backstop only fires on pathology, and retries shrink rather than loop.**

- Thresholds sit far above the nominal chunk cost, at 20 to 60 times, so nothing is killed under
  ordinary contention. What does get killed is a chunk whose plan has broken: the 09-15 digest
  regression went from 0.29 s to 60 s per chunk, 200 times, and a 30 s cap would have fired on
  the first chunk and raised an alert within minutes instead of after three days.
- On a kill, a lock-wait timeout or a deadlock: back off, halve the chunk size, retry. Repeat
  down to a minimum chunk. pt-online-schema-change does the same with `--chunk-time` adaptive
  sizing and bounded `--tries`, then aborts loudly.
- At the minimum chunk after K failures: fail the job loudly (Sentry), release its overlap lock,
  and let the next scheduled run resume from the checkpoint. A one-row chunk that still fails is
  a bug, and a bug should page, not spin. Wasted work per failure is bounded by one chunk.
- Reads can be killed freely (nothing to roll back). Writes cannot be timed out natively in
  MySQL, so the write-side interruption path is the lock-wait timeout, which rolls back only the
  statement (`innodb_rollback_on_timeout=OFF`), and deadlock 1213, which rolls back the
  transaction. Both need idempotent chunks: keyset position plus a WHERE that re-evaluates, so a
  re-run is safe. With every write on db3 and db2 idle, Galera brute-force aborts of local
  transactions stop happening, so 1213 means an InnoDB deadlock and nothing else.
- pt-kill stays as the last resort for a runaway write statement, batch account only, query kill
  not connection kill, at a threshold above the read cap.

**The deadline alarm closes the loop.** Every job declares its window (the digest must finish by
12:00). Alert at 75% of the window with progress so far. The `PreventsOverlapping` lock already
stops the next tick piling on. A job that is throttled to its floor rate and misses its window is
then a capacity or query problem with a name on it, which is the 09-17 outcome arrived at in
minutes rather than days.

"No starvation" therefore means: guaranteed progress at a floor rate, bounded retries with
shrinking chunks, and loud failure. Batch may run slower under contention; the alarm says when
that is not acceptable.
