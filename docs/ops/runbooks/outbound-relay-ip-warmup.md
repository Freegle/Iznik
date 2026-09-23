---
last_reviewed: 2026-09-15
owner: Freegle dev team
covers:
  - scripts/bulk2/ip-warmup.sh
  - scripts/bulk2/provider-group-discover.sh
  - ops/hosts/mail-host/postfix/warm-instance/*
  - ops/hosts/monit/mail-host/conf.d/postfix-warm.conf
  - ops/hosts/monit/mail-host/scripts/postfix-warm-active.sh
---

# Outbound relay: warming sending addresses per provider

> For the whole outbound path - generation, the spool, this relay, the provider -
> see [how an email gets from Freegle to an inbox](../reference/outbound-mail.md).

The outbound mail relay has several sending addresses. Large receiving providers
(Yahoo's estate is the recurring one: `yahoo.*`, `aol.*`, `sky.com`, `ymail.com` ...)
judge us per *sending address x receiving provider*, and when one of those pairs is
throttled (`421 4.7.0 [TSSnn] temporarily deferred due to unexpected volume`) every
message to that provider from that address sits in the relay's queue and eventually
expires. The batch side notices this and stops generating mail for the provider - see
[mail deferrals](../../developers/reference/mail-deferrals.md) - but getting the mail
delivered again is the relay's job, and that is what these two scripts do.

They live on the relay in `/usr/local/sbin` and run from cron. The copies in
`scripts/bulk2/` are the versioned source; deploy by copying them over (there is no
package). Both are plain bash, both are data-driven from files in `/etc/postfix`, and
`DRY=1 ip-warmup.sh` runs the whole decision against the live relay and prints what it
would change without touching anything. Use it before believing any edit.

## Shape

**`provider-group-discover.sh`** (hourly) reads the relay's log and works out which
*provider groups* the primary address is being refused by. A group is a set of recipient
domains that share an inbound MX - grouping by brand name gets it wrong in both
directions (`sky.com` is Yahoo-hosted; `btinternet.com` is not). It writes
`/etc/postfix/warmup-groups` as `<domain> <group>` lines: every group currently in play,
with every domain seen in recent traffic whose MX resolves into it. A group enters play
on refusals and leaves only when the primary is *confirmed* accepting it again - more
sends than refusals, from the primary, in a window the log sample actually covered.

**`ip-warmup.sh`** (every minute) does the routing. For each (candidate address, group)
pair it creates a dedicated postfix transport (`<candidate><group>`, e.g.
`warm1yahoodnsnet`) with its own bind address, syslog tag and rate settings, so every
measurement is per pair for free. Each pair climbs a ladder of daily allowances
(2k / 4k / 8k / 12k / 20k / 30k / 50k) and pacing delays; a rung is earned by a clean day
that used most of its allowance, and lost on the spot by refusals in the recent window,
followed by a cool-off. The group's *bulk* domains (`/etc/postfix/warmup-bulk-domains`)
go to the highest-rung healthy pair; the group's low-volume domains are spread over the
other candidates as *canaries*, so a blocked address keeps being probed and is noticed
when it re-opens. Over a cap the script slows the transport down; it never re-routes
because of a cap, and when nothing is healthy it holds the current routing rather than
handing mail to an unproven address.

## Two postfix instances

A throttled group is **not delivered by the primary postfix instance at all**. The
primary hands it over an SMTP hop on `127.0.0.1:10026` (transport `relaywarm`) to a
second instance, `postfix-warm` (`/etc/postfix-warm`, queue `/var/spool/postfix-warm`),
which owns the warm transports and does the real delivery.

`qmgr_message_active_limit` is a single global limit **per instance** - Postfix has no
per-transport, per-destination or per-IP variant. A paced lane holds tens of thousands of
messages for hours, and each occupies an active-queue slot healthy domains need. The
second instance keeps that backlog out of the queue the rest of the mail uses.

`ip-warmup.sh` writes **both** maps from one domain list, so they cannot drift:

| map | says |
|---|---|
| `/etc/postfix/warmup_transport` | every domain of every group in play → `relaywarm` |
| `/etc/postfix-warm/warmup_transport` | domain → the (address, group) pair carrying it |

Target one instance with `postmulti -i postfix-warm -x <cmd>`; `postmulti -i -` is the
primary; a bare `postmulti -x` hits both. Setup and the traps are in
[`ops/hosts/mail-host/postfix/warm-instance/README.md`](../../../ops/hosts/mail-host/postfix/warm-instance/README.md).
monit watches the second instance separately (`postfix-warm`) - without that it could die
and every throttled provider would stop while the primary went on looking healthy.

### The loopback hop is not a delivery

This is the trap for **anything that reads the maillog**. The hop logs exactly like a
successful delivery:

```
postfix-relaywarm/smtp[...]: ABC: to=<someone@yahoo.com>,
  relay=127.0.0.1[127.0.0.1]:10026, ... status=sent (250 ... queued as DEF)
```

while having reached nothing but the second instance.

`provider-group-discover.sh` must not count it as a send. There is one hop per message,
so the count is unbounded and always exceeds the refusal count; every routed group would
read as "primary confirmed accepting" and the group would be taken off its warmed address
and put back on one that is refusing it.

The exclusion uses two independent signals - the `postfix-relaywarm` tag and the loopback
`relay=` - so one going stale does not restore the fault. If domains are routed to
`relaywarm` but neither signal matches any hop, no group may leave play that run.

The relay's `logs_emails` ingest excludes the same hop, otherwise a member asking "did you
send it?" is told the provider accepted a message it has not seen. The warm instance runs
`header_checks` so its own records carry the Subject line.

`DeferralProbe` crosses instances for the same reason: the primary resolves a paced domain
to `relaywarm`, which has no `smtp_bind_address` of its own, and stopping there falls back
to the global default - the address the provider is refusing.

The output is `/etc/postfix/warmup_transport`, consulted first in `transport_maps`.

## Per pair, not per address

Every (address, group) pair has its own transport, rung, state and cool-off, so one
provider's refusals cannot move another provider's routing. No group competes with
another for the warm-up, and there is nothing to choose between them: every group the
primary is refused by is warmed independently.

## What to look at

- `/var/log/ip-warmup.log`: one status line per group per minute
  (`[group] bulk=<address>(<transport>) canaries=[...] queued=N | <per-candidate state>`),
  plus events: `REFUSED`, `promoted`, `routing changed`, `created transport`, `cold`.
- `/var/log/provider-discover.log`: `enters play`, `leaves play`, coverage warnings,
  `rewrote`/`unchanged`.
- Per-pair truth in the relay log is by syslog tag: `grep postfix-warm1yahoodnsnet/ mail.log`.
- State is in `/var/lib/ip-warmup/<address>.<group>.{state,cooloff,delivered,baseline}`.

## Hand interventions

- **Pause the automation before editing state.** The cron re-judges every minute and will
  undo a hand-set rung within a minute, because the pair is still judged on a window that
  still holds the old refusals. Comment the line in `/etc/cron.d/ip-warmup`, make the
  change, run the script once by hand, check the log line, uncomment.
- **Restore a rung** you have evidence for by writing the pair's `.state` file
  (`date=<today> rung=N hot=0 lastup=<epoch>`) and removing its `.cooloff`.
- **Rate settings must be in `main.cf`** as `<transport>_destination_rate_delay` /
  `_concurrency_limit` (the queue manager reads those). `-o` values in `master.cf`
  configure the SMTP client and pace nothing - `postconf -n | grep <transport>` is the
  truth, `ps` is not.
- A probe from a rested address answering `250` proves permission to start, not
  capacity. Judge an address on sustained per-minute deliveries, never on a hand probe.
