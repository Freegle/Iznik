# The `postfix-warm` instance on the mail relay

A second Postfix instance that owns delivery to **throttled provider groups**
(the Yahoo estate, Virgin Media). Everything else keeps leaving from the primary
instance exactly as before.

## Why it exists

`qmgr_message_active_limit` is a single global limit **per instance** — Postfix
has no per-transport, per-destination or per-IP variant. A provider we are
deliberately pacing holds tens of thousands of messages for hours, and while
they sit in the active queue they occupy slots that every other domain needs.

On 2026-09-13/14 the primary's active queue sat pinned at exactly 40,000 for
~30 hours with 38,236 of it Yahoo, and Postfix logged its own diagnosis:

```
warning: you may need to increase the main.cf warm1yahoodnsnet_destination_concurrency_limit from 1
warning: this may slow down other mail deliveries
```

Yahoo was *accepting* throughout — the backlog was our own ip-warmup day cap.
That is the point: the lane is slow **by design**, so it needs a queue of its
own rather than a bigger share of everyone else's.

## Shape

```
spooler ──SMTP──► primary (/etc/postfix, :25, DKIM milter, active limit 40,000)
                      ├── healthy domains ─────────────────► the internet
                      └── warmup-group domains
                          transport `relaywarm`
                          ──SMTP──► 127.0.0.1:10026
                                    postfix-warm (/etc/postfix-warm,
                                    active limit 60,000, no milters,
                                    in_flow_delay=0)
                                      └── warm<n><group> ──► the internet
```

## Sizing the second instance's own active queue

The same starvation exists one level down, and it is worth being explicit about
why it is handled differently.

Inside instance 2 all the paced groups share one active queue. When that queue
fills, its queue manager stops importing from `incoming`, and a second paced
group then waits behind the first. That is *much* less serious than the problem
this instance solves - both providers are ones we are deliberately slowing, and
the delay is bounded by the pacing anyway - but it is the same shape.

Measured on 2026-09-15: instance 2's qmgr held **17,103 active messages in 39MB
RSS**, against 6.5MB for a near-empty one. So roughly **1.9KB per active
message**. The box had ~2,000MB available.

The starvation only happens when the queue is *full*, so the fix is to size it
above a realistic paced backlog rather than to build anything: the limit is
**60,000**, about 114MB, around 6% of available memory. The newsletter blowout
of 2026-09-13 peaked near 40,000.

The alternative - one instance per group - was rejected for now. Groups are
invented dynamically by `provider-group-discover.sh`, so it would mean creating
and destroying postfix instances from a cron job that runs every minute on the
live relay. That is a lot of moving parts for a case where the harm is one
paced provider delaying another.

What the original incident actually cost us was thirty hours of nobody
noticing, so the residual risk is **monitored** instead:
`ops/hosts/monit/mail-host/scripts/postfix-warm-active.sh` alerts when the
active queue passes 80% of its limit. If that fires regularly, give the busiest
group its own instance.

## Things that will bite you

- **`relaywarm` must override `smtp_bind_address`.** The primary sets it
  globally to the public sending address. Inherited, the kernel would be asked
  to reach 127.0.0.1 from a public source address — unroutable, and every
  throttled message fails at connect. See `setup-relaywarm.sh`.
- **No milters on instance 2.** The primary already DKIM-signed; signing twice
  is worse than not signing.
- **Per-transport `syslog_name` overrides the instance-wide one** (verified
  2026-09-14). That is load-bearing: the tag *is* ip-warmup's per-pair
  measurement, so if the instance default ever won, every harvest window would
  collapse into one bucket.
- **`header_checks` is enabled on instance 2 too**, so its records carry the
  Subject line. Its queue ids differ from the primary's, so a message that
  crosses the hop is logged under two ids.
- **The loopback hop logs exactly like a delivery.** Anything reading the
  maillog must exclude it — see the hazard note in
  `docs/ops/runbooks/outbound-relay-ip-warmup.md`.

## Recreating it

```sh
postmulti -e init                                   # on the default instance
postmulti -I postfix-warm -G warm -e create
# then apply main.cf / master.cf from this directory, and:
bash setup-relaywarm.sh                             # the primary's handover transport
postmulti -i postfix-warm -e enable
postmulti -i postfix-warm -p start

# monit: the process check AND the queue-depth check
install -m 644 ../../../monit/mail-host/conf.d/postfix-warm.conf /etc/monit/conf.d/
install -m 755 -D ../../../monit/mail-host/scripts/postfix-warm-active.sh /etc/monit/scripts/postfix-warm-active.sh
monit reload
```

Test a monit script the way monit runs it - no HOME, minimal PATH - or it can
sit inert for years looking healthy:

```sh
env -i /etc/monit/scripts/postfix-warm-active.sh; echo "exit=$?"
env -i WARN_PCT=1 /etc/monit/scripts/postfix-warm-active.sh; echo "exit=$?"   # must fail
```

`ip-warmup.sh` writes **both** maps from one domain list, so they cannot drift:
`/etc/postfix/warmup_transport` (every group domain → `relaywarm`) and
`/etc/postfix-warm/warmup_transport` (domain → the (ip, group) pair carrying it).

Target one instance with `postmulti -i postfix-warm -x <cmd>`; `postmulti -i -`
is the primary; a bare `postmulti -x` hits both.
