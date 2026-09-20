# bulk2: second Postfix instance for throttled provider groups

**Branch** `fix/postfix-multi-instance-relay` · started 2026-09-14

## Why

The primary instance's active queue sat pinned at exactly `qmgr_message_active_limit`
(40,000) for ~30 hours, 38,236 of it Yahoo. Postfix logged its own clog warning
(`warning: this may slow down other mail deliveries`). `qmgr_message_active_limit` is
global per instance — there is no per-IP or per-transport variant — so the only real
isolation Postfix offers is a second instance with its own queue and its own qmgr.

Yahoo was *accepting* throughout (zero deferrals); the backlog is our own ip-warmup day
cap (warm1 at 53,631 vs ladder top rung 50,000 → `DAYCAP_DELAY=8s` → ~780/h). The cap is
normally adequate (20–34k/day); 09-13/09-14 were the Stories-newsletter tail.

## Topology

```
spooler ──SMTP──► primary (/etc/postfix, :25, DKIM milter, active limit 40,000)
                      │
                      ├── healthy domains ──────────────► the internet
                      │
                      └── warmup-group domains
                          via transport `relaywarm`
                          ──SMTP──► 127.0.0.1:10026
                                    instance postfix-warm
                                    (/etc/postfix-warm, active limit 60,000,
                                     no milters, in_flow_delay=0)
                                      │
                                      └── warm<n><group> transports ──► the internet
```

Instance 2 started at 20,000 — smaller than the primary — on the reasoning that a paced
lane gains no throughput from a big active queue. That was right about throughput and
wrong about fairness: the same starvation exists one level down, and at 17,095/20,000 a
second paced group was about to start waiting behind Yahoo. Measured cost is ~1.9KB per
active message (39MB RSS at 17,103), so the limit is now **60,000** (~114MB, ~6% of
available memory) and a monit check alerts at 80%. See the warm-instance README.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Backup, branch, plan | ✅ | `/root/postfix-backup-20260914-193730` |
| 2 | Create + configure instance `postfix-warm` | ✅ | smtpd 127.0.0.1:10026, milters off, `master_service_disable=smtp/inet` |
| 3 | Move warm* transports, syslog tags verbatim | ✅ | 12 transports + 24 rate params, `diff` IDENTICAL |
| 4 | End-to-end test | ✅ | delivered via Google; tag test proved `-o syslog_name` still overrides the instance default |
| 5 | `eximlogs.php`: V1 guard applied on the relay | ✅ | interim; Laravel port still to land (task 14) |
| 6 | `ip-warmup.sh` instance-aware | ✅ | DRY run reproduced live decisions, `queued=38208` summed across both |
| 7 | `provider-group-discover.sh` exclude loopback hop | ✅ | tested: hop dropped, genuine primary send to the same domain kept; fail-closed probe verified in 4 cases |
| 8 | `DeferralProbe.php` follow chain into instance 2 | ✅ | resolution executed against stubs: post-cutover→77.72.7.253, pre-cutover unchanged, single-instance falls back |
| 9 | monit check for instance 2 | ✅ | proven by stopping it: monit restarted in ~40s |
| 13 | Cutover | ✅ | canary aol.com → full 65 domains; traced primary id → warm id → provider `250 ok dirdel` |
| 10 | Version config under `ops/hosts/mail-host/` | ✅ | plus cron.d, shaped-ramp.sh, release-talktalk.sh, monit — all were live but uncommitted |
| 11 | Docs: runbook + mail-deferrals + logging | ✅ | freshness check passes |
| 12 | Verify adaptive-shaper/shaped-ramp no overlap | ✅ | `shaped_destinations` holds only o2.co.uk; zero overlap |
| 14 | Finish Laravel `RelayLogIngestService` + command + tests | ✅ | service, command, config, schedule, 9 tests. V1 cron NOT yet retired - see below |
| 15 | Commit + PR | ✅ | PR #1524 |
| 16 | Second instance: same starvation one level down | ✅ | measured 1.9KB/msg; limit 20,000 -> 60,000; monit queue-depth check added |

## Retiring the V1 eximlogs cron - AFTER merge, not before

`*/10 * * * * cd /var/www/iznik/scripts/cron; nice php ./eximlogs.php` is still in
root's crontab on the relay, with the loopback-hop guard applied. Do NOT remove it
until `mail:relay-logs:ingest` is confirmed writing rows in production, or there is
a window with no producer for `logs_emails`.

A brief overlap is harmless: both are keyed on the postfix queue id and only ever
fill in fields that are missing, so whichever runs second finds the row and adds
nothing.

Cutover:
1. Merge, let batch-prod pick up the schedule (bind mount, so the code is live).
2. `docker exec batch-prod php artisan mail:relay-logs:ingest --dry-run` - check it
   reports lines and hops.
3. Watch for new `logs_emails` rows with recent timestamps.
4. Comment the eximlogs line in root's crontab on the relay (back it up first).
5. `/tmp/iznik.eximlogs.out` had grown to 1.38GB - delete it.

**Priority note (user, 2026-09-15):** getting mail flowing came first; 10–12 and 14–15
are deliberately after the cutover.

## Traps this change has to avoid

1. **discover counting the loopback hop as the primary accepting.** The primary will log
   `to=<x@yahoo.com> relay=127.0.0.1... status=sent` for a message that never reached
   Yahoo. `provider-group-discover.sh` builds "the primary's traffic" by *excluding*
   non-primary transport tags, so those hops land in `isent`, which always then beats
   `icount` → group declared healthy → family un-routed onto the blocked primary. This is
   exactly 2026-09-02. Exclusion must be structural (relay= loopback AND the relaywarm
   tag) and fail-closed.
2. **qshape reading the wrong queue.** `group_queued()` = 0 is the single value that
   un-routes a group. After cutover the mail is in instance 2's queue.
3. **DeferralProbe binding the blocked primary.** It resolves the bind address by walking
   the primary's `transport_maps`; `relaywarm` has no bind of its own, so it would fall
   through to the default = 185.53.57.161, which Yahoo has refused since 08-15. A refusal
   cancels the 24h stale fail-open, so suppression could never lift.
4. **logs_emails polluted with loopback hops.** Member-visible, and the AI support helper
   treats an absent row as "never sent" and a 250 as "accepted by the recipient MX".
5. **Double DKIM signing.** Milters are off on instance 2; the primary already signed.

## Cutover

Do **not** requeue the existing backlog — Postfix warns against flushing, and `postsuper -r`
would rewrite Received headers on 38k messages. Let it drain from the primary at the old
rate while new mail goes to instance 2; at rung 6 it clears in ~5h once the day cap resets.

Verify after: non-Yahoo queue time (the `a` field of `delays=`) stays ~0.1s, and the
primary's active queue falls off 40,000.
