---
last_reviewed: 2026-10-07
owner: Freegle ops
covers:
  - scripts/maintenance/**
---

# Automated host maintenance

`freegle-maint` patches the operating system on each production machine and reboots it
when the patching needs it. It does one machine per night, on a weekly rotation, and only
ever one at a time. Around every reboot it uses the same graceful procedure a person
would: traffic and work move off the machine first, and come back only after everything
on it has been verified.

It is shipped switched off. Every scheduled run is a dry run until a person names a
machine as live (see [Turning it on](#turning-it-on)).

The code is in [`scripts/maintenance/`](../../../scripts/maintenance/). The procedure it
automates for the data nodes is
[Restarting a database node](database-node-restart-and-rejoin.md#cycling-a-data-node-and-both-in-turn).

## Where it runs

Everything starts on the Docker host, which already reaches every other machine over
ssh for deploys. That makes one lock, one log and one alert path for the whole estate.

| Piece | What it is |
|---|---|
| `scripts/maintenance/freegle-maint` | The command. `lib/` holds one file per kind of machine plus the shared checks. |
| `systemd/freegle-maint-<slot>.timer` | One timer per slot, calling `freegle-maint@<slot>.service`. |
| `systemd/freegle-maint-resume.service` | Runs at boot, only after a Docker-host maintenance reboot, to verify the host. |
| `/etc/freegle-maint/freegle-maint.env` | Local settings, not in git. `freegle-maint.env.example` lists them. |
| `/var/lib/freegle-maint/` | The lock, the failure marker, and one directory per run with its summary and ledger. |
| `/var/log/freegle-maint/maint.log` | One JSON line per event, also written to the journal. |

## Schedule

All times are UTC. Each run refuses to start outside its start window.

| Slot | When | Machine | Why this time |
|---|---|---|---|
| `db` | Tuesday 04:40 | db2 in even weeks, db3 in odd weeks | After the backup drain and the backup's desync on db2, before the morning digest. Each data node is done every other week, and never both in one week. |
| `mail` | Wednesday 02:15 | bulk2 | The overnight low in outgoing mail, clear of the relay's own 05:00 log rotation and 06:00 upgrade job. |
| `arbitrator` | Thursday 04:40 | db1 | Two days clear of the data-node slot. |
| `docker` | Saturday 23:35 | the Docker host | After the 23:00 jobs and before the 00:30 log rotation and 01:00 postcode remap. Saturday has no 23:00 digest. |
| `lb` | Sunday 03:10 | the load balancer | The API's lowest-traffic hour. |

Weeks are counted from the Unix epoch, so the data nodes alternate strictly. A 53-week
year does not repeat one. Swap the order with `MAINT_DB_ROTATION`.

## What every run does

1. **Preflight**, read-only. Any failure stops the run before anything changes, and the
   alert says why. See [the gates](#the-gates).
2. **Plan.** Refresh the package lists and work out what would be upgraded, minus the
   packages this tool never touches. Decide whether a reboot is needed: the machine
   already says so (`/var/run/reboot-required`), the upgrade includes a kernel, C
   library, systemd, dbus or OpenSSL, or the uptime has reached the optional cadence.
3. **Nothing to do:** stop, with a short summary.
4. **Patch only:** install the upgrades with automatic service restarts switched off, as
   the daily unattended upgrade does. If the machine asks for a reboot afterwards, carry
   on to the reboot path. Otherwise stop.
5. **Reboot path:** drain, stop what depends on the machine, patch, reboot, wait for it,
   verify, give monitoring back, undrain. Each machine's version is below.

Upgrades never restart services by themselves during a run (needrestart is in list
mode). Whatever was running before a reboot must be running after it, checked against
a snapshot of the running systemd services and of `monit summary`.

### Packages left alone

Some upgrades are an operation of their own, so a run never installs them. It lists them
in the summary instead.

| Machine | Excluded | Why |
|---|---|---|
| Data nodes, arbitrator | `percona-*` | A Percona upgrade is a planned cluster change. |
| Docker host | the Docker engine packages | An engine change has broken container networking before. |
| Load balancer | `haproxy` | Its upgrade restarts the only front door. |
| Mail relay | `postfix*`, only while it is serving | Upgraded during the reboot path, while both instances are stopped. |

## Data node (db2, db3)

The [graceful cycle](database-node-restart-and-rejoin.md#cycling-a-data-node-and-both-in-turn),
around a reboot. No step lets writes or reads fail while the node is down.

1. Move the other node's API off this node: change `MYSQL_HOST` or `MYSQL_HOST_READ` in
   its `.env` wherever it names this node, and restart it through monit. The check is a
   new process, 200 on `/api/group`, and the boot line naming the expected database.
2. Move the batch off this node: the same for `DB_HOST_IP` or `DB_HOST_READ_IP` in the
   repo `.env`, then recreate batch-prod. It waits for no `ripple:expand` to be running
   first. Afterwards it checks the container's database aliases, a query, every
   supervisor program RUNNING, and that the schedule parses. It releases an orphaned
   `ripple:expand` lock only when no such process exists.
3. Drain this node's API (`monit unmonitor`, then `SIGQUIT`) and wait for the load
   balancer to mark it DOWN with the other node UP.
4. Unmonitor the spatial, routing and MySQL checks, wait until monit shows them "Not
   monitored", then stop the monit daemon. Monit is off at boot on data nodes, so nothing
   restarts anything against a node that is still resyncing.
5. Patch. Percona stays where it is.
6. Check the other node is Synced and Primary, then `systemctl stop mysql`. Confirm no
   `mysqld` is left and `grastate.dat` says `safe_to_bootstrap: 0`.
7. Reboot and wait for a new boot id.
8. Wait for Synced at full cluster size. One retry of `systemctl start mysql` is allowed
   for the two cases the runbook names: the five-minute reboot gate, and the IST handshake
   that fails with "wrong seqno". A full state transfer is left to run. The other node
   must stay Synced throughout.
9. Start monit and monitor every service explicitly. Verify the API, spatial `/health`,
   routing `/health` and its internal route (monit's grace would report a dead routing
   server as OK), the API boot line, no panics in its log, and monit all OK.
10. Wait for the load balancer to mark the node UP in both backends.
11. Move the batch back, then the other node's API. The order matters: this node's API is
    already UP, so the load balancer is never left without an API server.

The summary says whether the node rejoined by IST or by a full copy (SST). After an SST,
check the donor's write-set cache the next day, as the runbook says.

## Arbitrator (db1)

Its maintenance is safe only while both data nodes are healthy: without it they keep
quorum with two votes of three. The run stops `garbd` cleanly before patching, even when
no reboot is needed. A clean leave is one view change, where a stalled arbitrator on that
small machine has cost the cluster its primary component before. After the patch or
reboot it checks `garbd` is active, monit watches it, and both data nodes show the full
cluster size again.

## Load balancer

There is one load balancer and no standby or floating address. Rebooting it is an outage
of everything it fronts: the API, ModTools, uploads and image delivery. No drain avoids
that. So by default this slot patches in service and does not reboot. When a reboot is
pending it says so in the alert, and a person schedules the outage. With
`MAINT_LB_ALLOW_REBOOT=1` it reboots in its window instead. It first validates the
configuration on disk with `haproxy -c`, and afterwards checks every backend has a
server UP and the public probe answers 200.

## Docker host

The orchestrator runs here, so it cannot watch its own reboot. The drain stops the batch
scheduler and waits for running scheduled jobs to finish. If they do not finish in time,
it restarts the scheduler and stops without rebooting. Then it stops the queue workers
and mail spoolers, which finish their current work; the mail spool stays on disk. It
patches, writes a resume marker and reboots.

At boot `freegle-maint-resume.service` sees the marker and verifies: every container that
ran before runs again, each health-checked one healthy (the spatial graphs take minutes),
every batch supervisor program RUNNING, the schedule parses, the batch can query its
database, Loki is ready, the status container runs, and monit is all OK. The lock is held
across the reboot. The run refuses to start if the resume unit is not enabled.

While it is down the member site, ModTools, the API and the database stay up. Batch
jobs, incoming reply mail, tiles, geocoding, the wiki, uploads and image delivery pause.

## Mail relay (bulk2)

The relay runs two postfix instances. Every check names its instance, as
[the mail traps](../../../.claude/rules/mail-and-data.md) require. Mail survives a reboot:
both queues are on disk, and while the relay is down the batch's spool keeps what it
could not hand over and retries.

The drain unmonitors the three postfix checks and stops the primary instance first, so
nothing new comes in and nothing more is handed to the warm instance. Then it stops the
warm instance, both through systemd. After the reboot both instances must be running,
and mail must be moving: at least one real delivery from the primary within 15 minutes.
The loopback hop to the warm instance logs like a delivery and is excluded on both its
tag and its address. A warm instance with no delivery yet is reported but is not a
failure, because it carries only the providers we pace.

## The gates

Preflight refuses, and changes nothing, if any of these holds:

- Maintenance is paused, an earlier run failed and was not cleared, or another run holds
  the lock.
- It is outside the slot's start window, or less than 20 hours since the last live run.
- A deploy is running or a deploy log was written in the last 6 hours, a service binary
  on a data node changed in that time, or a hand-run graceful cycle is in progress.
- **Data node:** either data node not Synced (state 4), cluster size not 3, not Primary,
  not ready, desynced, or with flow control paused; `garbd` not active; the node not under
  plain systemd (a hand bootstrap needs a person); inside the backup drain, or a backup
  or state-transfer process running; less than 40 GB free in the data directory, or more
  than 4 write-set cache page files, on either node; the other node with less than 6 GB
  memory free or a write-set cache covering less than 90 minutes; any API, spatial or
  routing check not 200 on either node; monit not all OK; the load balancer not showing
  both API servers and the other node's routing server UP; an API or batch database
  setting naming neither node, or batch-prod running with settings that differ from its
  `.env`; batch-prod unhealthy or running a daily digest or a backup; the other data node
  done within 6 days.
- **Arbitrator:** either data node unhealthy as above, or its API not answering;
  `garbd` not active or not enabled at boot; monit not enabled at boot.
- **Load balancer:** HAProxy not active or not enabled; `haproxy -c` failing; a backend
  with no server UP; the public probe failing.
- **Docker host:** the Compose unit not active; the resume unit not enabled; the Compose
  configuration not validating; batch-prod unhealthy; the data nodes unhealthy.
- **Mail relay:** an instance not running or failing `postfix check`; a queue large
  enough to mean a bulk send is in progress; the batch spool over 20,000 pending; a bulk
  mail command running in batch-prod.
- Any machine: less than 5 GB free on its root filesystem, or monit not all OK.

Every threshold is a setting in the env file.

## When a run fails

Before the first change, a failure is an abort: the lock is released and the alert says
the run was skipped and why.

After patching in service but before the drain, the machine is still in its normal
shape. The run stops, releases the lock, and the alert lists the patches installed. This
is also what happens when a reboot turns out to be needed but the window has passed or
maintenance was paused meanwhile: the reboot waits for the next slot.

Once the drain has started, the run stops where it is and does not push on. Each step is
ordered so that stopping at any point leaves the service whole. For a data node, for
instance, everything is already on the other node. The lock is kept as `FAILED`, so no
other maintenance starts on top. The alert carries the ledger: every change made, in
order, with the command that undoes it. A run that dies without reaching its own
failure handling leaves its active marker behind. The next run treats that as a failure
too.

After dealing with it, `freegle-maint clear-failed`.

## Alerts and logs

Alerts go the way monit's do: mail through the local mail transfer agent to the address in
monit's `set alert` line, unless `MAINT_ALERT_EMAIL` is set. There is one mail per run:
the outcome, the ledger, and the run's log. Dry runs mail their summary too while
`MAINT_MAIL_DRY_RUN_SUMMARY=1`. A successful data-node run includes the API 5xx count
per node over the run, from Loki.

## Turning it on

Each step is deliberate, and each machine is turned on separately.

1. On the Docker host, install the units:
   `cp scripts/maintenance/systemd/* /etc/systemd/system/ && systemctl daemon-reload`.
2. `mkdir -p /etc/freegle-maint` and copy `scripts/maintenance/freegle-maint.env.example`
   to `/etc/freegle-maint/freegle-maint.env`. Leave `MAINT_LIVE_HOSTS` empty.
3. `systemctl enable freegle-maint-resume.service`.
4. Enable the timers:
   `systemctl enable --now freegle-maint-{db,mail,arbitrator,docker,lb}.timer`.
   Every run is now a dry run. Each mails what it would have done.
5. After a week of clean dry runs, add one machine to `MAINT_LIVE_HOSTS`, for example
   `db2-internal` (the read node). Watch its first live run.
6. Add the rest one at a time, a week apart. The names are `db2-internal`,
   `db3-internal`, `db1-internal`, `bulk2-internal`, `ha-internal` and `docker`.

To run one machine by hand, as root on the Docker host:

```
freegle-maint run db2-internal                    # dry run: real preflight, prints every action
freegle-maint run db2-internal --live             # for real, inside the window
freegle-maint run db2-internal --live --force-reboot --ignore-window
```

`--force-reboot` reboots even when patching does not need it. That is the way to clear
a slow leak that only a restart cures. `MAINT_FORCE_REBOOT_DAYS` does the same on a
cadence.

## Operating it

| To | Run |
|---|---|
| See the state: pause, failure, lock, which data node is due, recent runs, timers | `freegle-maint status` |
| Stop all maintenance from starting | `freegle-maint pause <reason>` (writes `/etc/freegle-maint/PAUSE`) |
| Allow it again | `freegle-maint unpause` |
| Release a failed run's lock, after dealing with it | `freegle-maint clear-failed` |

A run checks the pause file when it starts, before its first change, and again just
before the drain, so pausing stops a run in progress at the next of those points. Once
the drain has started the run completes its cycle, because stopping half way would leave
the machine drained.

## What it does not do

- Upgrade Percona, the Docker engine or HAProxy.
- Clear a leaking write-set cache. It refuses to run when the page files show one, and
  the [cure](database-node-restart-and-rejoin.md#the-write-set-cache-after-the-node-has-served-an-sst)
  stays a hand procedure.
- Recover a node that will not rejoin, beyond the one retry. It alerts and leaves
  everything on the other node.
- Reboot the load balancer unless allowed to.
