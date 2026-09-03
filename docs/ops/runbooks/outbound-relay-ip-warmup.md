---
last_reviewed: 2026-09-03
owner: Freegle dev team
covers:
  - scripts/bulk2/ip-warmup.sh
  - scripts/bulk2/provider-group-discover.sh
---

# Outbound relay: warming sending addresses per provider

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

The output is `/etc/postfix/warmup_transport`, consulted first in `transport_maps`.

## Why per pair, not per address

Until 2026-09-03 the warm-up managed one provider group at a time, with one rung per
address, and the discover script *chose* which group that was by counting refusals.
That contest is how a second provider's unrelated policy refusals took the chain away
from Yahoo on 2026-09-02 (a log sample that covered 102 of the 360 minutes asked for,
inside which the primary had made zero Yahoo attempts, read as "no refusals") and left
the whole Yahoo estate dark for 33 hours while walking the working address's rung from
6 to 0 without a single Yahoo refusal. Per pair, nothing about one provider can touch
another, and there is nothing to choose.

## What to look at

- `/var/log/ip-warmup.log`: one status line per group per minute
  (`[group] bulk=<address>(<transport>) canaries=[...] queued=N | <per-candidate state>`),
  plus events: `REFUSED`, `promoted`, `routing changed`, `created transport`, `cold`.
- `/var/log/provider-discover.log`: `enters play`, `leaves play`, coverage warnings,
  `rewrote`/`unchanged`.
- Per-pair truth in the relay log is by syslog tag: `grep postfix-warm1yahoodnsnet/ mail.log`.
- State is in `/var/lib/ip-warmup/<address>.<group>.{state,cooloff,delivered,baseline}`.

## Hand interventions

- **Pause the automation before editing state.** The cron re-judges every minute, and a
  freshly reset rung was demoted again within sixty seconds on 2026-09-03 because the
  pair was still being judged as a canary on a three-hour window full of old refusals.
  Comment the line in `/etc/cron.d/ip-warmup`, make the change, run the script once by
  hand, check the log line, uncomment.
- **Restore a rung** you have evidence for by writing the pair's `.state` file
  (`date=<today> rung=N hot=0 lastup=<epoch>`) and removing its `.cooloff`.
- **Rate settings must be in `main.cf`** as `<transport>_destination_rate_delay` /
  `_concurrency_limit` (the queue manager reads those). `-o` values in `master.cf`
  configure the SMTP client and pace nothing - `postconf -n | grep <transport>` is the
  truth, `ps` is not.
- A probe from a rested address answering `250` proves permission to start, not
  capacity. Judge an address on sustained per-minute deliveries, never on a hand probe.
