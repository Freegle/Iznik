---
last_reviewed: 2026-09-15
owner: Freegle ops
covers:
  - iznik-batch/app/Services/EmailSpoolerService.php
  - iznik-batch/app/Console/Commands/Mail/ProcessSpoolCommand.php
  - iznik-batch/app/Services/Mail/RelayLogIngestService.php
  - iznik-batch/docker/supervisor.conf
  - ops/hosts/monit/batch-host/conf.d/mail-spool
  - ops/hosts/monit/batch-host/scripts/mail-spool-backlog.sh
---

# How an email gets from Freegle to someone's inbox

Freegle sends a few hundred thousand emails a day. This page is the spine: what
happens between a Laravel job deciding to email someone and that email arriving.
It exists so that when the backlog alarm goes off at 3am you can tell which of
four quite different problems you have.

The detail of each stage lives elsewhere, linked below. Nothing here repeats what
the code says - it says where the code is and what the shape is.

## The path

```
a Laravel job                     iznik-batch
      │
      │ EmailSpoolerService
      ▼
storage/spool/mail/pending/       a file queue on the batch host
      │
      │ mail:spool:process  ×4 workers
      ▼
the relay, over SMTP              "mail-host"; SPF and DKIM applied here
      │
      ├── healthy domains ──────────────────────► the recipient
      │
      └── paced providers, over a loopback hop
          ──► a second postfix instance ────────► the recipient
```

Four stages, four different failure modes. Work out which one you are in before
changing anything.

## 1. Generation

Laravel jobs build a `Mailable` and hand it to `EmailSpoolerService`. The
expensive part is the render (MJML, and AMP for some types), which is why mail to
a provider that is refusing us is stopped *before* the render rather than at send
time - see [mail deferrals](../../developers/reference/mail-deferrals.md).

## 2. The spool

A file queue at `storage/spool/mail/{pending,sending,sent,failed}` on the batch
host. It exists so that a message survives a container restart, and so that
generation is not blocked by delivery.

**Priority bands.** The band is part of the *filename*, so the drain can order
tens of thousands of entries without opening any of them:

| band | what |
|---|---|
| `p1` | chat, welcome, session, donation - a person is waiting |
| `p3` | immediate digests - people expect these promptly |
| `p5` | daily digests, engage, **and anything unrecognised** |
| `p9` | community events, newsletters, chase-ups |

Draining is strict priority, so the bottom band waits for everything above it. A
deep `p9` queue behind a digest run is normal, not a fault.

The default band matters more than it looks: a new kind of email with no entry in
the map still flows, at normal priority, rather than failing or stalling.

**Four workers.** `mail:spool:process` runs four times under supervisor. Delivery
is latency-bound rather than CPU-bound (about 150ms a message, dominated by the
SMTP round trip), so it parallelises: 1 to 2 workers measured 440/min to about
800/min.

Each worker gets a **private claim area** (`--worker=NN`). This is not tidiness.
With a shared `sending/` directory, restarting one worker would reclaim a live
sibling's in-flight file and send it twice, and because the Message-ID is
regenerated per send there would be nothing in the logs to show it happened.

The four supervisor settings behind that are one atomic change - see the comment
block in `iznik-batch/docker/supervisor.conf` before touching any of them.
supervisord is PID 1 in that container, so a config it refuses to parse
crash-loops the whole container and takes the scheduler, the queue workers and
the incoming-mail listener with it.

**The ceiling is what the relay accepts**, not what the internet accepts. Bursts
followed by a crawl are the designed behaviour, not a symptom.

## 3. The relay

One host, referred to as `mail-host`. SPF and DKIM are applied here. It runs
**two postfix instances**:

- the **primary**, which delivers to everything healthy
- **`postfix-warm`**, which owns delivery to providers we are deliberately pacing
  (currently the Yahoo estate and Virgin Media), reached over an SMTP hop on
  loopback

The second instance exists because postfix sizes its active queue with a single
setting per instance, with no per-provider equivalent. A provider we are pacing
holds tens of thousands of messages for hours, and in a shared queue each one
takes a slot every other domain needs. In September 2026 that pinned the primary
at its ceiling for thirty hours and slowed mail to everyone.

Full description, including the warm-up that decides which sending address is
used for which provider: **[outbound relay warm-up](../runbooks/outbound-relay-ip-warmup.md)**.

**If you read the relay's log, you must know about the loopback hop.** It looks
exactly like a successful delivery while having reached nothing but our own second
instance. Everything that parses that log already excludes it; anything new must
too, or it will report mail as delivered that the provider has not seen.

## 4. The provider

Out of our hands, and the most common place for things to go wrong. Two shapes:

- **They refuse us.** The message stays in the relay's queue, so we can count it.
  This is what [mail deferrals](../../developers/reference/mail-deferrals.md)
  detects, and it stops us generating into a hole.
- **They accept and then bin it.** No queue entry, nothing to count, everything
  looks perfect from our side. Caught instead by watching each domain's open rate
  against its own history.

## Telling the four apart

The single most useful distinction: **time in our spool is our delay; time in the
relay's queue afterwards is the provider throttling us.** They call for opposite
responses. A growing deferred queue on the relay is not a reason to slow
ourselves down - postfix retries far better than the file queue would, so handing
off quickly is the goal.

| symptom | look at |
|---|---|
| backlog alarm on the batch host | which band is deep, and whether the bands above it are still moving |
| one provider getting nothing | [mail deferrals](../../developers/reference/mail-deferrals.md) |
| everything slow, all domains | the relay's active queue, and [the warm-up runbook](../runbooks/outbound-relay-ip-warmup.md) |
| "did you email me?" from one member | `logs_emails` - see [logging](logging.md) |

## Alarms

- **Mail spool backlog** (batch host): fires when `pending/` stays above 20,000
  for 15 cycles, about half an hour. A digest run legitimately spikes it, hence
  the delay before it speaks. Establish *which band* is deep before acting.
- **`postfix` / `postfix-warm`** (relay): each instance is watched separately. The
  second one needs its own check, because the primary would go on looking healthy
  while every paced provider stopped dead.
- **`postfix-warm-active`** (relay): warns when the second instance's own active
  queue passes 80% of its limit, which is the point at which one paced provider
  starts delaying another.

## What is deliberately not here

Sending addresses, hostnames and IPs. They live in the environment and in
`ops/hosts/mail-host/`, not in documentation.
