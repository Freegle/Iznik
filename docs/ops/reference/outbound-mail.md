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

Freegle sends a few hundred thousand emails a day. This page is the spine of that
path, so that when mail is slow you can tell which of four stages you are in.

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

## 1. Generation

Laravel jobs build a `Mailable` and hand it to `EmailSpoolerService`. The expensive
part is the render (MJML, and AMP for some types), so mail to a provider that is
refusing us is stopped *before* the render - see
[mail deferrals](../../developers/reference/mail-deferrals.md).

## 2. The spool

A file queue at `storage/spool/mail/{pending,sending,sent,failed}` on the batch host.
A message survives a container restart, and generation is not blocked by delivery.

**Priority bands.** The band is part of the filename, so the drain orders tens of
thousands of entries without opening any:

| band | what |
|---|---|
| `p1` | chat, welcome, session, donation - a person is waiting |
| `p3` | immediate digests - people expect these promptly |
| `p5` | daily digests, engage, **and anything unrecognised** |
| `p9` | community events, newsletters, chase-ups |

Draining is strict priority, so the bottom band waits for everything above it. A deep
`p9` queue behind a digest run is normal.

A kind of email with no entry in the map flows at `p5` rather than failing or stalling.

**Four workers.** `mail:spool:process` runs four times under supervisor. Delivery is
latency-bound rather than CPU-bound - about 150ms a message, dominated by the SMTP
round trip - so it parallelises.

Each worker has a private claim area (`--worker=NN`). With a shared `sending/`
directory, restarting one worker reclaims a live sibling's in-flight file and sends it
twice, and the Message-ID is regenerated per send so nothing in the logs shows it.

The four supervisor settings behind that are one atomic change - read the comment block
in `iznik-batch/docker/supervisor.conf` before touching any of them. supervisord is PID
1 in that container, so a config it refuses to parse crash-loops the container and takes
the scheduler, the queue workers and the incoming-mail listener with it.

**The ceiling is what the relay accepts**, not what the internet accepts. Bursts
followed by a crawl are the designed behaviour.

## 3. The relay

One host, `mail-host`. SPF and DKIM are applied here. It runs two postfix instances:

- the **primary**, which delivers to everything healthy
- **`postfix-warm`**, which owns delivery to paced providers (currently the Yahoo estate
  and Virgin Media), reached over an SMTP hop on loopback

Postfix sizes its active queue with a single setting per instance, with no per-provider
equivalent. A paced provider holds tens of thousands of messages for hours, and in a
shared queue each one takes a slot every other domain needs. Separate instances keep a
paced provider's backlog out of the queue that healthy domains use.

Which sending address is used for which provider:
**[outbound relay warm-up](../runbooks/outbound-relay-ip-warmup.md)**.

**If you read the relay's log, you must know about the loopback hop.** It looks exactly
like a successful delivery while having reached nothing but the second instance.
Everything that parses that log excludes it; anything new must too, or it will report
mail as delivered that the provider has not seen.

## 4. The provider

Two shapes of failure:

- **They refuse us.** The message stays in the relay's queue, so it can be counted. This
  is what [mail deferrals](../../developers/reference/mail-deferrals.md) detects, and it
  stops us generating into a hole.
- **They accept and then bin it.** No queue entry, nothing to count, and everything looks
  correct from our side. Caught by watching each domain's open rate against its own
  history.

## Telling the stages apart

**Time in our spool is our delay. Time in the relay's queue afterwards is the provider
throttling us.** These call for opposite responses: a growing deferred queue on the relay
is not a reason to slow ourselves down, because postfix retries better than the file
queue would, so handing off quickly is the goal.

| symptom | look at |
|---|---|
| backlog alarm on the batch host | which band is deep, and whether the bands above it are still moving |
| one provider getting nothing | [mail deferrals](../../developers/reference/mail-deferrals.md) |
| everything slow, all domains | the relay's active queue, and [the warm-up runbook](../runbooks/outbound-relay-ip-warmup.md) |
| "did you email me?" about one member | `logs_emails` - see [logging](logging.md) |

## Alarms

- **`mail-spool-backlog`** (batch host): `pending/` above 20,000 for 15 cycles, about half
  an hour. A digest run legitimately spikes it, hence the delay before it speaks.
  Establish which band is deep before acting.
- **`postfix` / `postfix-warm`** (relay): each instance is watched separately. Without its
  own check the second instance could die while the primary went on looking healthy.
- **`postfix-warm-active`** (relay): the second instance's active queue past 80% of its
  limit, the point at which one paced provider starts delaying another.

## Not here

Sending addresses, hostnames and IPs. They are in the environment and in
`ops/hosts/mail-host/`.
