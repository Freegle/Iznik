---
last_reviewed: 2026-09-02
owner: Freegle ops
covers:
  - ops/hosts/README.md
  - ops/hosts/SERVICES.md
  - ops/hosts/monit/batch-host/conf.d/disk.conf
  - ops/hosts/monit/db-node/conf.d/mysql.conf
  - yesterday/README.md
---

# Sysadmin duties

What the person looking after the live service actually has to do, how often, and why.
This is the routine-work companion to [../production.md](../production.md) (what the
machines are) and [../04-domains-services-and-runbooks.md](../04-domains-services-and-runbooks.md)
(what each service is for).

New to the role? Read [../../getting-started/03-sysadmin-track.md](../../getting-started/03-sysadmin-track.md)
first - it puts this page in order as a first day, first week and first month.

## The shape of the job

Freegle is a small estate - a load balancer, three database nodes, one Docker host, one
outbound mail relay - carrying a service used by a few million people. There is no
24/7 rota and no paging. That has two consequences you should hold on to:

1. **Almost everything is designed to keep running without you.** The member site, the
   moderator tools, the API and the database survive the Docker host being down. See
   "Failure behaviour worth knowing" in [../production.md](../production.md).
2. **The failures that hurt are the silent ones.** Not "the site is down" - somebody
   tells you that within minutes. It is a backup that has not restored for six nights,
   a monit check that is watching nothing because of a typo, a mail relay quietly
   deferring a provider's worth of members. Your routine checks exist to catch the
   second kind.

## How things are supervised

Three different mechanisms, and it matters which one owns a given service:

| Mechanism | What it supervises | Where the config is recorded |
|---|---|---|
| **monit** | Services running natively on the machines: MySQL/Galera, the v2 API, spatial and routing, nginx, Redis, beanstalkd, Photon, plus disk-space and mail-spool alarms | [`ops/hosts/monit/`](../../../ops/hosts/monit/) |
| **systemd** | Starts the Docker Compose stack on the Docker host at boot | on the host |
| **Docker Compose** | Restart policies for the containers within the stack | `docker-compose*.yml` in this repo |
| **HAProxy** | Which backend is active; failover between database nodes and to the standby frontend | [`ops/hosts/haproxy/haproxy.cfg.template`](../../../ops/hosts/haproxy/haproxy.cfg.template) |

**Read [`ops/hosts/README.md`](../../../ops/hosts/README.md) before you touch any of it.**
It is a record, not a deployment mechanism: nothing applies those files automatically,
and the reason the directory exists is that two monit changes once existed on exactly one
machine with nothing to restore them from.

Two monit traps from that page, repeated here because they bite:

- **Validate with `monit -t` before `monit reload`.** A syntax error leaves monit
  watching *nothing*, and that looks exactly like everything being fine.
- **Cycle length differs by role.** `set daemon` is 120s on the Docker host and 60s on
  the database nodes, so the same `for 15 cycles` means 30 minutes on one and 15 on the
  other. Check before you read any grace period.

## Routine checks

### Every day (five minutes)

| Check | How | Why |
|---|---|---|
| Anything new and loud in error tracking | Sentry | New exception classes after a deploy are the earliest signal that a release broke something the tests did not cover |
| Did the nightly Yesterday restore succeed? | Restore status file on the Yesterday VM; if it failed, read the **restore monitor service journal**, not the cron log | **Nothing alerts on this.** It has failed for six nights running unnoticed. See [Backups](../04-domains-services-and-runbooks.md#backups) |
| Outbound mail is moving | Queue depth and delivery counts on the mail relay | A provider throttle shows as a growing queue long before a member reports a missing digest |

### Every week

| Check | How | Why |
|---|---|---|
| `monit summary` on each machine | every service should read OK | A check that has been failing for days is invisible unless you look |
| Disk headroom on all machines | monit `disk.conf` alarms plus your own eyes | The Photon index alone is ~6.3G and the Yesterday pool grows by 10-20G a day; disk is the most common slow-motion outage |
| Galera cluster health | `SHOW STATUS LIKE 'wsrep_%'` - cluster size 3, all nodes Synced | A node can drop out and the site stays up, so nothing tells you until the second one goes |
| CI is green on master | [CircleCI](circleci.md) | A red master blocks the auto-merge to `production`, which silently stops all frontend deploys |
| Certificate expiry | whatever your reminder mechanism is | TLS certs are referenced by path in the HAProxy config and are **not** captured in this repo; nothing renews them for you |

### Every month

| Check | How | Why |
|---|---|---|
| Restore drill | Switch the Yesterday environment to an older day and confirm the site comes up | Proves the whole chain, not just last night's link |
| Mail reputation review | Deferral rates by provider on the relay; feedback-loop report counts | See the measured detail in [`ops/hosts/SERVICES.md`](../../../ops/hosts/SERVICES.md) - this is where the evidence for "volume, not complaints" lives |
| Index hygiene | [database-index-hygiene.md](database-index-hygiene.md) | Read counters must be **summed across all three nodes**; one node alone makes almost every index look unused |
| Host config drift | Compare the live files against [`ops/hosts/`](../../../ops/hosts/) | Anything that exists on only one machine is one rebuild away from being lost |

## What alerts you, and what does not

Be clear-eyed about this. The gaps are the job.

**You will be told about:** monit service failures and disk alarms (by email to the ops
alert address), application exceptions (Sentry), CI failures (CircleCI), and members
complaining, which is a real and fast monitoring channel.

**Nothing currently tells you about:**

- A failed nightly backup restore. Check it daily by hand until this is fixed.
- Mail deferrals building up for one provider. The adaptive shaper reacts on its own,
  but nobody is notified that it did.
- A monit configuration that fails to load.
- Certificates approaching expiry.
- A single Galera node leaving the cluster.

If you improve one thing in your first month, make it one of these.

## Jobs that are yours by default

- **Deploys of anything that is not the frontend.** Netlify deploys the two static sites
  automatically from the `production` branch. The v2 API, the spatial and routing
  services and the batch stack are separate, and **the backend goes first** - see
  [../02-deployment-and-ci.md](../02-deployment-and-ci.md).
- **Restarts that are not cheap.** The spatial and routing services rebuild large
  in-memory graphs at start, so a restart is minutes, not seconds. Photon takes about 40
  seconds to load its index. Plan accordingly rather than discovering it mid-incident.
- **Mail reputation.** Not a "set it and forget it" area; it is an ongoing relationship
  with the large mailbox providers. The accumulated measurements in
  [`ops/hosts/SERVICES.md`](../../../ops/hosts/SERVICES.md) will save you days.
- **Spam filtering thresholds.** See [spam-and-abuse.md](spam-and-abuse.md).

## Triage order when something is wrong

1. **Is it the frontend or the backend?** Load the member site. If the pages render but
   nothing works, it is the API or the database. If nothing loads at all, it is Netlify
   or DNS, and nothing you run is involved.
2. **Is it one machine or all of them?** `monit summary` on each. The estate is small
   enough to check by hand and that is faster than reasoning about it.
3. **Read the right log.** The wrong log is the single biggest time sink here. For the
   Yesterday restore, the service journal not the cron log. For the mail relay,
   `/var/log/mail.log` not the stale exim log. For applications, Loki with the trace id -
   see [logging.md](logging.md).
4. **A 504 from the load balancer looks like a CORS error in the browser.** HAProxy's
   504 carries no CORS headers. `timeout server` is 50s. Do not chase the CORS message.
5. **Suspect a stale config file before you suspect the code.** On the database nodes
   `/etc/mysql/my.cnf` is the only file that takes effect, and several convincing
   look-alikes on disk contradict what is actually running. Confirm with
   `SHOW VARIABLES`, not with a file.

## Things not to do

- **Do not recreate or restart production containers without explicit approval.** The
  batch stack is doing real work for real people when you do that.
- **Do not point writes at more than one Galera node.** All writes funnel to one
  nominated node by application convention; that is what avoids write conflicts. See
  [database-read-write-split.md](database-read-write-split.md).
- **Do not "normalise" the database node settings.** db3 has a much larger buffer pool
  than the other two on purpose - it is the only active API backend in HAProxy.
  Benchmarking the API on another node measures a machine taking no traffic.
- **Do not restore `/etc/mysql/percona-xtradb-cluster.conf.d/*.cnf`.** It is not read,
  and its contents would misconfigure the cluster if they ever were.
- **Do not upgrade Docker on the CI runner.** It is pinned at 27.5.1; 28+ breaks
  container-to-container networking and the symptom is mystifying test timeouts.
- **Do not put backup files in `/etc/monit/conf.d/`.** monit includes every file in that
  directory, so `spatial.bak` becomes a duplicate service definition and `monit -t`
  fails.

## Where the rest is

| Need | Page |
|---|---|
| What the machines are and what routes where | [../production.md](../production.md) |
| Getting code to production | [../02-deployment-and-ci.md](../02-deployment-and-ci.md) |
| Finding out what happened | [../03-monitoring-and-logging.md](../03-monitoring-and-logging.md), [logging.md](logging.md) |
| Per-event procedures | [../runbooks/README.md](../runbooks/README.md) |
| Spam and abuse handling | [spam-and-abuse.md](spam-and-abuse.md) |
| Accounts, credentials and who holds them | [../../getting-started/04-accounts-and-access.md](../../getting-started/04-accounts-and-access.md) |
| Backup mechanics in detail | [`yesterday/README.md`](../../../yesterday/README.md) |
