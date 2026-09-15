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
                                    active limit 20,000, no milters,
                                    in_flow_delay=0)
                                      └── warm<n><group> ──► the internet
```

Instance 2's active limit is deliberately **smaller** than the primary's. A lane
pacing one message every few seconds gains no throughput from a big active
queue; it only costs qmgr memory. The backlog waits in `incoming` on disk.

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
cp ../../monit/postfix-warm.conf /etc/monit/conf.d/ && monit reload
postmulti -i postfix-warm -e enable
postmulti -i postfix-warm -p start
```

`ip-warmup.sh` writes **both** maps from one domain list, so they cannot drift:
`/etc/postfix/warmup_transport` (every group domain → `relaywarm`) and
`/etc/postfix-warm/warmup_transport` (domain → the (ip, group) pair carrying it).

Target one instance with `postmulti -i postfix-warm -x <cmd>`; `postmulti -i -`
is the primary; a bare `postmulti -x` hits both.
