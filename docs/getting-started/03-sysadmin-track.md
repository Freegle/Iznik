---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Sysadmin: day 1, week 1, month 1

You are the person who now keeps Freegle running. This page is the order to do things
in.
It stands on its own - you do not need the developer track to do this job, though you will
end up reading parts of it, because the line between the two roles is thin here.

**Read [01-what-freegle-is.md](01-what-freegle-is.md) first if you have not.** It is short,
and it defines the words used below.

Two facts to set expectations before anything else:

- **There is no on-call rota, and nobody is paid to be available.** Freegle is run by
  volunteers. The system is built to survive nobody looking at it for a few days, and the
  job is mostly to keep it that way.
- **Members notice outages before monitoring does**, more often than anyone would like.
  Closing that gap is the most useful long-term work available to you.

## Day 1

Three things.

### 1. Get your accounts

1Password first, because everything else is inside it. Then GitHub, the volunteers' forum
and a normal member account on the live site. The list, and who to ask, is
[04-accounts-and-access.md](04-accounts-and-access.md).

You do **not** need production server access on day one, and you should not rush it. Ask
for it when you have a task that needs it.

### 2. Learn the shape of production

Read [../ops/production.md](../ops/production.md) and
[../ops/01-overview-and-environments.md](../ops/01-overview-and-environments.md).

The map in one paragraph. A load balancer takes public traffic. Three database machines run
a **multi-master cluster** - each holds a full copy, any of them can serve reads, and
**writes all go to one nominated node**. Those same three machines also run the API, the
geography services and the routing service directly on the host, not in containers. One
separate machine runs everything containerised: the scheduled jobs, mail, logging and the
image-upload and image-delivery services. The two websites are not on our servers at all -
they are static builds served from a hosting provider.

If that sounds like the database machines are doing too much, see
[06-decisions-and-rationale.md](06-decisions-and-rationale.md) for why it is deliberate.

### 3. Understand what supervises what

This is the single most useful thing to learn early, because it determines *how you
restart something*, and getting it wrong wastes an hour during an incident.

There are four different supervision mechanisms in play, and the right command depends on
which one owns the process. The table is in
[../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md) under "How
things are supervised". Read it now, and be ready to look it up again rather than
guessing.

One trap worth carrying from day one: **the monitoring tool's cycle length is not the same
on every machine** - 120 seconds on the container host, 60 on the database nodes. So an
identical `for 15 cycles` in a check means 30 minutes on one and 15 on the other. See
[`ops/hosts/README.md`](../../ops/hosts/README.md).

## Week 1

### Do the daily checks by hand, every day

They take five minutes and they are listed in
[../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md) under "Routine
checks". Do them manually this week even though it feels mechanical. You are calibrating:
after five days you will know what normal looks like, and that is what lets you spot
abnormal later.

### Learn what does *not* alert you

This is the part that catches people out, so it has its own section in
[../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md): "What alerts
you, and what does not".

The headline gaps, all of which have bitten us:

- **A failed nightly database restore is silent.** It once failed six nights running with
  nobody noticing.
- **Mail deferrals building up for one provider** look like nothing until delivery to that
  provider has been dead for days.
- **A monitoring config that fails to load** looks exactly like nothing being wrong,
  because a tool that is watching nothing reports no problems.
- **Certificates approaching expiry.**
- **One database node leaving the cluster.** The site stays up. That is the problem.

Read that list, then treat "no alerts" as "no information", not "no problems".

### Understand backups, because they are a whole website

Our database backup system is called **Yesterday**, and it is unusual enough to explain
properly. A nightly job takes a physical copy of the production database, streams it to a
separate cloud machine, and that machine then **restores it into a complete, running copy
of Freegle** which volunteers can browse to see what the site looked like yesterday.

The point is that the backup and the restore test are the same job. A backup that cannot
be restored shows up as a Yesterday site that will not start, rather than as a discovery
during a real emergency.

Two operational facts:

- **Nothing alerts when that restore fails**, so checking it is part of your routine until
  that is fixed.
- **The useful log is the restore monitor's service journal**, not the cron log. The cron
  log tells you it failed; the journal tells you why.

Architecture in
[../ops/04-domains-services-and-runbooks.md](../ops/04-domains-services-and-runbooks.md),
mechanics in [`yesterday/README.md`](../../yesterday/README.md).

### Find out where logs and errors go

- **Loki** holds application and service logs, queried with LogQL, with trace and session
  ids so you can follow one request across services.
- **Sentry** collects application errors.
- Sign-in and sign-out are **not** in Loki; they are rows in a database table, which is
  what to reach for when someone reports being logged out unexpectedly.

[../ops/03-monitoring-and-logging.md](../ops/03-monitoring-and-logging.md).

### Learn the triage order before you need it

Five steps, in [../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md)
under "Triage order when something is wrong". Read them cold now so that under pressure you
are recalling rather than inventing.

The one that saves the most time: **a 504 from the load balancer shows up in a browser as a
CORS error.** If a developer reports a sudden CORS problem, check whether the backend is
simply timing out before you go anywhere near CORS configuration.

## Month 1

### Do a runbook end to end

[../ops/runbooks/README.md](../ops/runbooks/README.md). The background-host reboot is the
best first one: it exercises the supervision mechanisms, the service start order and the
warm-up behaviour of the slow services, in a planned window rather than an incident.

Know before you start that **the geography and routing services hold a graph of the country
in memory and rebuild it on start**, taking several minutes. Rippling, browse and the daily
digest all depend on them. A restart is therefore never instant, and needs to be planned
rather than fired off.

### Understand how deployment happens, so you can tell "broken" from "deploying"

Every push to master runs the full test suite in CI. If it passes, master is merged to a
`production` branch, which triggers the hosting provider to rebuild and publish both
websites. The mobile apps build from the same branch.

The consequence for you: **a frontend change reaching members is not something you do, it
is something that happens.** Your involvement is when it does not happen, or when a
backend service it depends on has not been deployed first. Read
[../ops/02-deployment-and-ci.md](../ops/02-deployment-and-ci.md), including the rollback
section, before you need it.

### Learn the mail system properly

Freegle is, operationally, a very large mail sender - hundreds of thousands of messages a
day. Most member-visible incidents are mail incidents. Two things to understand:

- **Deferrals and suppression**: how we react when a provider slows us down or rejects us,
  and why per-provider deferral buildup is the failure that hides best. See
  [../developers/reference/mail-deferrals.md](../developers/reference/mail-deferrals.md).
- **Spam filtering**: an SMTP-time check and an application-layer content check running in
  parallel. Layers and thresholds in
  [../ops/reference/spam-and-abuse.md](../ops/reference/spam-and-abuse.md).

### Learn the database rules

Three, and they explain a surprising number of confusing reports:

- **Writes go to one nominated node.** The cluster resolves write conflicts by aborting
  transactions, so spreading writes across nodes causes failures rather than throughput.
- **The application routes reads and writes separately.** So "this write is not visible
  yet" is a real class of bug in our code - but do **not** explain a bug away as
  replication lag without checking. It usually is not.
- **Index usage counters must be summed across all three nodes.** Looking at one node makes
  almost every index look unused, and dropping one on that basis is how you cause an
  outage.

[../ops/reference/database-read-write-split.md](../ops/reference/database-read-write-split.md)
and
[../ops/reference/database-index-hygiene.md](../ops/reference/database-index-hygiene.md).

### Capture what only exists on one machine

Configuration that lives on the machines rather than in a container image is recorded in
[`ops/hosts/`](../../ops/hosts/README.md). Read its "Why this exists" section: two
monitoring changes once existed on exactly one machine with nothing to restore them from.

That directory is a **record, not a deployment mechanism**. Merging a change to it changes
nothing on any server. You copy the file into place and reload the service yourself. And
validate the monitoring config before reloading, because a syntax error leaves the tool
watching nothing at all.

If you find something during your first month that exists on one box and nowhere in git,
capturing it there is the single most valuable thing you can do that week.

### Things not to do

There is a list in
[../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md). The two that
matter most:

- **Do not recreate or restart production containers without explicit approval.** The
  scheduled-jobs container is doing real work for real people when you interrupt it.
- **Do not make changes on a server that are not also made in git.** Container changes are
  lost on restart, and host changes that exist in one place are lost on rebuild - silently,
  because nothing that has stopped being watched complains.

### What "good" looks like after a month

You can be told "the site is slow" or "somebody says they are not getting emails" and work
out which layer owns it, without guessing and without waking anybody up. You know which
supervision mechanism owns each service. And you have added at least one thing to the
monitoring or the host-config record that was previously only in somebody's head.

### Where to go next

| Subject | Page |
|---|---|
| The full duties reference | [../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md) |
| Production topology | [../ops/production.md](../ops/production.md) |
| Domains, services, backups | [../ops/04-domains-services-and-runbooks.md](../ops/04-domains-services-and-runbooks.md) |
| Deployment and CI | [../ops/02-deployment-and-ci.md](../ops/02-deployment-and-ci.md) |
| Monitoring and logging | [../ops/03-monitoring-and-logging.md](../ops/03-monitoring-and-logging.md) |
| Third parties we depend on | [../developers/reference/external-services.md](../developers/reference/external-services.md) |
| Why the architecture is like this | [06-decisions-and-rationale.md](06-decisions-and-rationale.md) |
| Everything else, by subject | [../ops/README.md](../ops/README.md) |
