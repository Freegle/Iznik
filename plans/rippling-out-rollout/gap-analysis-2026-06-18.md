# Rippling-out — adversarial gap analysis (2026-06-18)

Run: 61-agent Workflow (`wf_5f8d1ce7-852`), 12 lenses × probe → adversarial verify → completeness critic.
48 candidate gaps → **46 confirmed real** + **6 critic-additional categories**. Deduped below to ~12 themes
(several were independently found by multiple lenses — the secondary-reject clip-overwrite ~5×).

These are **design / rollout blind spots**, distinct from the implementation bugs already fixed in review
(messages_spatial deletion, Q7 double-mail, etc.). Raw per-gap reasoning + file:line evidence + fix sketches
were in `/tmp/.../tasks/wz5lx4h0q.output` (ephemeral).

---

## TIER 1 — Blockers (break the feature or harm at rollout)

### 1. Secondary-rejection reach clip is silently reverted within ~60s  ★ most-confirmed (5×)
Cross-PR defect. Go `ClipReachForRejectedGroup` writes the clipped polygon to `rippling_reach.polygon`
(`ST_Difference`). PHP `ExpandService::advanceDue` then **unconditionally overwrites `polygon`** from the
static `schedule` JSON on the next minute tick — restoring the full unclipped reach. So for any `status='expanding'`
post the rejection has **no lasting effect**; for `status='done'` posts the clip is permanent with **no undo path**.
The `'stopped'` enum exists in the schema but is **never written** by any code path. Also re-inserts the rejected
group via `rippleIntoNewGroups` on the next tick.
- Evidence: `iznik-server-go/message/message.go:1957`; `iznik-batch/.../ExpandService.php:204-208`; secondary-reject branch makes 0 changes to `advanceDue`.
- Fix: clip must survive ticks — `void_polygon`/`clipped_groups` column re-applied in `advanceDue`, or set `status='stopped'` + `next_expansion_at=NULL` on clip.

### 2. Rippled-in posts never auto-approve (design's "short window" is prose, zero code)
The membership gate in **both** `ContentCheckService::isUserModerated` and `AutoApproveService::shouldApproveOnGroup`
returns "moderated / never approve" because the poster has **no membership** in the secondary group. Design §9 says
"rippled-in posts auto-approve on a short window (already vetted on origin)" — but **no branch implements it**
(`PENDING_HOURS=48` unchanged; no `rippled_in` column). Result: every rippled-in post sits Pending in the secondary
group's mod queue until a human acts → mod-queue flooding + delayed secondary-group digest delivery.
- Nuance: member **browse/reply is NOT blocked** (browse uses the origin Approved `messages_spatial` row + reach polygon), so this is a mod-workflow + design-intent blocker, not "invisible post."
- Fix: `rippled_in` flag + expedited auto-approve when the same msgid is already Approved on another group.

### 3. Immediate-mail fan-out is synchronous + uncapped inside the per-minute expand loop
Design said "**Enqueue** immediate mails"; code calls `mailNewlyReachedForPost` **synchronously** inside
`ExpandService`'s 500-post-per-tick foreach — one MJML render per user, **no LIMIT, no chunkById, no async dispatch**,
allowlist defaults to `'*'`. Cold-start backfill = a burst that blocks the batch worker and risks Gmail bulk-sender
reputation (tick-1 = 70% of the local audience, same body, same domain, tightly bunched). The routing server is the
same shape: synchronous Dijkstra per init post, **no concurrency cap / circuit-breaker**, 20s client timeout → cold
start could take hours.
- Fix: queued job; per-run/per-post recipient cap; chunked hydration; spool-depth backpressure; ceiling alarm in `EmailHealthCommand` (only a floor exists).

---

## TIER 2 — Major design blind spots

### 4. WANTED posts: the model is inverted for them
Rippling assumes the item is at the poster's location. For a WANTED the poster's location is the **destination**;
a donor *anywhere* is a valid match. Reach-gating a WANTED **actively holds/blocks valid donor replies** and shows
wrong UI copy ("we're showing this to people closest to it first"). No type filter anywhere; never examined.
- Fix: exclude WANTEDs from rippling (`ms.msgtype='Offer'` in `initialiseNew`/`advanceDue`; early-return in `shouldHold`; type-gate `mailNewlyReachedForPost`; UI copy).

### 5. Reply-hold is only partially enforced (bypassable)
The "replies don't arrive early" invariant is enforced in the read path + UI + email/TN path, but **NOT**:
(a) the Go chat write path has no reach check — a direct API call or stale client can post straight through;
(b) a deep-link parameter auto-opens the composer on mount, bypassing the gated Reply button;
(c) the Go chat fetch/unread/snippet path lacks the held-reply delivery gate — the poster can read held replies before the hold window expires.
- Fix: enforce the reach check in the chat write path when replying to a reach-gated post; guard the deep-link auto-open on reach eligibility; add the held-reply delivery gate to the Go fetch/unread path (scoped to non-sender).

### 6. Daily digest + push are NOT reach-gated
`getPostsForUser` (daily/push path) has no reach filter; PR F ("digest ordering uses reach") is deferred and absent
from every branch. Deploying A–E without it means the **majority** of email recipients (daily cadence) receive every
rippling post ungated — the "nearest first" promise holds only for immediate-frequency members, and daily users get
notified of posts they then can't reply to.
- Fix: exclude reach-row posts from `getPostsForUser` (interim) or implement PR F's reach-coverage filter before B reaches daily-cadence users.

### 7. Location-source inconsistency across the three gates
Hold = `lastlocation` only; immediate-mail + read-path reply-eligibility = `mylocation COALESCE lastlocation`;
held-reply **release** tests the frozen snapshot while immediate-mail uses the live point. → contradictory visible
states ("you can reply" + reply silently held; or "you can't reply yet" + email delivered).
- Fix: one shared resolution order (mylocation→lastlocation→…); release tests live + snapshot.

### 8. Cursor stall after full rollout
`getGroupMessagesSinceCursor` excludes all reach-row posts → empty result every tick → cursor **never advances** →
unbounded growing scan each minute, and any non-rippling posts above the stalled cursor are **never mailed**.
- Fix: advance the cursor to the watermark even when all messages are excluded; add a LIMIT.

### 9. Moderation at scale
Cross-posting always-on floods many mod queues; a post approved at origin **re-approves on secondary groups for any
established-member poster** (approved-spam fan-out, faster via ContentCheck than the 48h path); **no per-group opt-out**
for specialist/local groups; secondary rejection writes **no cross-group mod signal / no `logs` row**.
- Fix: `ripple_opt_out` group flag; propagate origin ContentCheck result to secondary rows; `logModAction` on secondary reject.

---

## TIER 3 — Strategic: can we tell if it works, turn it off, and is it safe?

### 10. Untestable hypothesis + no kill-switch + no control arm  (critic)
The whole point is **more reuse / less landfill**, but nothing measures reuse: the simulator measures **notification
timing** ("was the replier notified in time?"), not **outcomes** (taken-rate, time-to-taken). No A/B holdout, no
pre/post taken-rate baseline, no safety metric to halt the self-tuner if outcomes fall — the tuner optimises timing
even if it reduces total reuse. Local-first **delay could reduce** reuse if distant willing donors are blocked until
the poster gives up. And §12 deleted the old behaviour rather than flagging it: **no `RIPPLING_ENABLED` kill-switch** —
disabling after a bad merge needs data surgery (truncate `rippling_reach` + `UPDATE rippling_held_replies`).
- Fix: add an outcome metric + holdout cohort; add a single env-var master switch gating all consumer paths; outcome guard-rail on the tuner.

### 11. Privacy & safeguarding  (critic + privacy lens)
Immediate-mail **tick timing is a location oracle**: a single passively-monitoring account (e.g. an abuser) can
confirm a poster is within a ~30-min drive from **one** tick-1 email (subject carries the item title). The design's
privacy analysis only asked "what is sent TO the poster," never "what is revealed ABOUT the poster." GDPR plumbing:
the new location-bearing tables (`rippling_held_replies.lat/lng`, `rippling_reach_notified`) are **absent from both
SAR export paths** and have **no retention/purge**.
- Fix: coarsen/jitter tick-1; SAR coverage + purge + null-out lat/lng on terminal state; consider a poster opt-out for location-sensitive users.

---

## TIER 4 — Lifecycle & correctness (smaller / mechanical)

- Post **location edit** stales the cached schedule → ripples into the wrong area indefinitely (`removeStale` blind to coordinate change).
- **Auto-repost** bumps `messages_groups.arrival` but not the reach clock → reposts resurface looking fresh but at max reach.
- **"Delete Approved Message"** on a secondary group doesn't clip reach (only `handleReject` does, and Approved posts have no Reject button).
- **Stale reach on taken posts** → `removeStale` only fires when absent from `messages_spatial`, but Taken stays there → stale "new near you" mail (no `messages_outcomes` guard in `mailNewlyReachedForPost`).
- `mailNewlyReachedForPost` skips `bouncing=0` / `tnuserid IS NULL` suppression and uses a tighter activity window than the V1-parity digest gate.
- `initialiseNew` mails **outside the 6am–11pm active-hours gate**; overnight posts hold replies until 06:00.
- **Coastal/water leakage**: 0.01° grid morphological-close bridges narrow straits (Menai Strait) → cross-posts to the wrong side.
- **NULL-location** immediate members silently dropped (`ST_Contains(POINT(NULL,NULL))` = NULL).
- The client-supplied location field is not server-validated, allowing a caller to unlock reply-eligibility or mail delivery early (no bounds or canonical-id check).
- **Freebie-alerts N+1**: `TASK_FREEBIE_ALERTS_ADD` fires once per rippled-in group approval (no msgid dedup).
- `isochroneCount` runs an `information_schema.tables` probe **per browse-count request** (uncached; DDL-lock risk).
- §16 **self-tuning layer + per-user instrumentation hooks** absent from PRs E/F → historical accrual won't begin day one.

---

## Suggested disposition

**Code defects to fix before merge** (no product decision needed): 1, 3 (caps/async), 5, 6, 7, 8, and most of Tier 4
(active-hours mail, bouncing/tnuserid suppression, stale-taken-mail, freebie N+1, location-edit invalidation,
NULL-location, information_schema probe).

**Need an Edward/product decision**: 2 (short-window auto-approve policy), 4 (drop WANTEDs from rippling?),
9 (per-group opt-out), 10 (kill-switch + outcome metric + holdout — do we gate launch on these?),
11 (safeguarding posture + GDPR retention).

Nothing is merged; there is room to fix before human merge.
