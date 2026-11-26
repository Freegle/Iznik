---
last_reviewed: 2026-11-26
owner: Freegle ops
covers:
  - iznik-batch/app/Services/Servers/**
  - iznik-batch/app/Console/Commands/Servers/**
  - iznik-batch/app/Mail/Servers/**
  - iznik-batch/resources/views/emails/mjml/servers/**
  - iznik-batch/resources/views/emails/text/servers/**
  - iznik-batch/database/migrations/2026_11_26_000001_create_server_samples.php
---

# Server right-sizing: the weekly report

Every Monday at 07:50 `servers:rightsize-report` mails one report saying whether each server
we rent is the right size, which disks are filling, and what a change would save or cost. It
only reads. Resizing a server is yours to do.

It is for the person who runs the servers. They read it on Monday, act on a "move down" or a
filling disk when they next have time, and otherwise delete it. If nobody is doing that, turn
it off (see the end) so it does not become mail nobody reads.

## Where the numbers come from

Two scheduled commands in the batch container:

| Command | When | What it does |
|---|---|---|
| `servers:sample` | every 15 minutes | Asks each host in `FREEGLE_MONITORING_HOSTS` and `SERVERS_EXTRA_HOSTS` for its load, processor counters, memory and disks over ssh, and stores one row per host in `server_samples`. |
| `servers:rightsize-report` | Monday 07:50 | Reads the last eight weeks of rows, asks the Katapult API what we rent and what it offers, works out a verdict per server, asks Claude to review it, and mails the result. |

The hosts keep no history of their own load, so `server_samples` is the only record. A host
that is not sampled is shown as "Not measured". To sample one, put the monitoring key's
public half in its `authorized_keys` and add it to `SERVERS_EXTRA_HOSTS`, which samples it
without drawing it into the patch and monit checks. Rows are kept for 400 days.

## How a verdict is reached

The rules are fixed and are in `ServerSizingAnalyser`. The foot of every report repeats them.

- A server is judged on its **busy end**: the level it stayed under for 95% of a week.
- **Move down** is suggested only if the busiest week in the window (up to eight weeks)
  would still leave headroom on the smaller package: load at most 70% of the processors,
  processor time at most 60% in use, memory at most 80% used. It picks the cheapest smaller
  package of the same kind that does. One quiet week is not enough.
- **Move up** is suggested when, in the last week, load is above the number of processors
  while they are more than 60% in use, processor time is above 85% in use, or memory is above
  92% used. It is always the next size up, one step at a time; the following report shows
  whether that was enough. High load with idle processors is work waiting for disk, and a
  bigger package would not help it.
- Between those two marks the verdict is **keep**, so a server near a line does not flip
  from week to week.
- A move down needs readings on **14 days**, and on at least five days of the last week. A
  move up needs readings on three days of the last week. A day counts when it has at least
  six hours of readings. Until then the report says "Too
  early to say" and what the readings point to so far.
- Readings from **before a resize** are left out, so a resized server starts its count again.
- A server that used swap above a tenth of its memory in the last week is **not offered
  less memory**.
- A **disk** is flagged at 85% full, or when its growth over the last eight weeks would take
  it to 90% within eight weeks.

A host is matched to its Katapult machine by short name, whatever the case or domain. A
host that is sampled but matches no machine is listed at the foot of the report.

The review at the top is written by Claude from those figures and verdicts. It is told to add
no numbers of its own. It is there for what the rules cannot see: a database server's memory
is full on purpose, because its cache is sized to fill it, and the report says so. It runs in an empty directory, so it has
nothing of ours to read but the figures. If the call fails the report goes out without the
review.

## Before you act on it

- **What the costs are.** They are the monthly prices the Katapult API gives for our
  account, less `SERVERS_PRICE_DISCOUNT`. The model was checked against the invoice of 10
  November 2026: the discount comes off those prices, every GB of disk is charged for
  whatever the package includes, and a stopped server is still charged for its package. They
  leave out VAT, object storage, data transfer and backups.
- **Taking processors or memory away needs the server shut down.** Adding them does not.
- **Disks can grow but never shrink.** A change of package does not change what the disk
  costs, so the saving shown is the difference in package price.
- **A database node** needs its cache made smaller before it can lose memory, and the other
  node must be carrying the load while it is down. Follow
  [database-node-restart-and-rejoin.md](database-node-restart-and-rejoin.md).

## Running it by hand

Inside the batch container:

```
php artisan servers:sample
php artisan servers:rightsize-report --dry-run
php artisan servers:rightsize-report --to=you@example.org
php artisan servers:rightsize-report --no-review
```

`--dry-run` prints each server's verdict and sends nothing. `--no-review` leaves out the
Claude call.

## Settings

All in `.env.background` on the batch host. The names are in
[deployment-switches.md](../../developers/reference/deployment-switches.md).

- `KATAPULT_API_TOKEN`: a Katapult API token. Without it there is no report.
- `SERVERS_REPORT_TO`: who gets the report. Without it there is no report.
- `SERVERS_PRICE_DISCOUNT`: the share taken off Katapult's prices, for example `0.5`.
- `SERVERS_EXTRA_HOSTS`: ssh targets to sample beside the monitored hosts.

The Claude review uses the same setup token as Community News, so it needs no setting of
its own.

## Turning it off

Remove `SERVERS_REPORT_TO` and the report stops; the readings carry on, and cost almost
nothing. To stop the readings too, take `servers:sample` out of
`iznik-batch/routes/console.php`.
