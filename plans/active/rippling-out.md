# Rippling Out rollout — implementation tracker

**Spec**: `plans/rippling-out-rollout/design.md` (read it before working — it is the source of truth).
**Worktree**: `/home/edward/FreegleDocker-rippling-out` · **Branch base**: `origin/master` · **Feature branch**: `feature/rippling-out`
**Goal**: all PRs pushed → through CI → adversarial-review fixes applied. **Not merged** (humans merge).
**Rule**: PRs split by deployability — reach infra first (dark), **browse & email last**. Each UI PR carries screenshots. Inter-PR dependencies stated explicitly in each PR body.

## PR / deployability plan

| PR | Branch | Contents | Depends on | Status |
|----|--------|----------|-----------|--------|
| A | `feature/rippling-reach-engine` | #0 reach calc: `messages_reach` migration + `ripple:expand` command (no mails) | — | ✅ PR #768, 12/12 green, adversarial review done + fixed (blocker+2 major+minor), CI running. NOT merged. |
| B | `feature/rippling-immediate-mails` | #0 immediate mails on expansion (flagged/allowlisted) | A | ⬜ Pending |
| C | `feature/rippling-held-replies` | #3 `chat_messages_rippling` hold/release + mod chat-held reason | A | ⬜ Pending |
| D | `feature/rippling-mod-ui` | #6 ripple-in mod banner + #7 reach map + #4 help modal (carry-over) | A | ⬜ Pending |
| E | `feature/rippling-browse` | #1 browse UI (filter/order/map) + #2 reply-eligibility + #8 FAQ | A | ⬜ Pending |
| F | `feature/rippling-digest` | #5 unified digest ordering uses reach | A | ⬜ Pending |

(Each PR branches off `feature/rippling-out` or `origin/master`+A as appropriate; A must land first conceptually since all consume `messages_reach`.)

## PR A — reach engine (current)

| # | Task | Status | Notes |
|---|------|--------|-------|
| A1 | Read existing routing-go reach API (`ripple.go`, `fairness.go`, `server.go` routes, `posts_for_member.go`) | 🔄 In Progress | `/v1/ripple-schedule` returns per-tick {drive_min, cumulative_users, polygon}; one call per post origin |
| A2 | Design `messages_reach` schema + migration (Laravel) | ⬜ | msgid PK, polygon, tick, schedule cache, next_expansion_at, status; scope = msgids in `messages_spatial` |
| A3 | `ripple:expand` artisan command (compute schedule, persist current reach, advance ticks per time schedule, stop conditions) | ⬜ | no mails in PR A; cross-into-new-group insert deferred to its own task but design in |
| A4 | Wire command into scheduler (every min, active hours 6am–11pm) | ⬜ | mirror `messages:contentcheck` |
| A5 | Tests (migration, command, schedule walk, stop conditions) — 90%+ on touched modules | ⬜ | |
| A6 | Production idempotent SQL (`*_migration.sql`) | ⬜ | per ralph §9 |
| A7 | Code-quality review + validate against running worktree | ⬜ | |
| A8 | Push branch, open PR (deps stated), get CI green, adversarial review + fixes | ⬜ | not merged |

## PR B — immediate mails on expansion (RE-SCOPED — see discovery below)
Branch `feature/rippling-immediate-mails` off A. **B1 done** (committed `a75c01ce5`):
`messages_reach_notified` ledger + prod SQL.

**DISCOVERY (2026-06-16):** a standalone "ripple immediate mail" would **double-mail**.
`UnifiedDigestService::processGroupImmediate` already mails a group's immediate-eligible members
(`memberships.emailfrequency = IMMEDIATE`) when a post arrives on that group — and rippling adds
the post to new groups (#6), so that existing path fires there too. So immediate-mail-by-reach is
NOT additive; it is the **immediate digest's recipient selection becoming reach-gated** (only
members whose `users_approxlocs` point is inside `messages_reach.polygon`, via
`ST_Contains(mr.polygon, ST_SRID(POINT(uap.lng,uap.lat),3857))`), using the ledger for dedup.
That is a **modification of live immediate email**, not a dark add-on, and it belongs WITH the
digest reach-selection work (#5/F), not as a separate early PR.

**Decision:** keep B1 (the ledger) as the committed foundation. Fold the immediate-mail-by-reach
recipient change INTO the digest PR (now **PR F: reach-gated digest selection, immediate + daily
modes**) so immediate and daily are changed together and can't double-send. The ledger serves the
immediate-mode dedup there. Mark B "ledger only; mailing folded into F".

Also note (member-vs-non-member): immediate mail stays **members-only** (don't cold-email
non-members about groups they haven't joined); non-members within reach get the post via **browse
(#1)** and the **daily digest (F)**, not an immediate cold email.

## Re-sequenced remaining PRs
- **C** — held external replies (`chat_messages_rippling`): self-contained backend; next buildable.
- **D** — mod-UI: #6 ripple-in banner + secondary-rejection (no poster notify + clip + track) +
  #7 reach map + #4 modal carry-over + multi-group edit warning.
- **E** — browse: #1 filter/order/map + #2 reply-eligibility + #8 FAQ (consumer, late).
- **F** — reach-gated digest selection (immediate + daily) + #5 ordering (email, late). Absorbs B's mailing.
- **G** — observability/self-tuning (#9).
- **#10** — postcode-driven single-group posting + TN main-group-only (latest, after rippling live).
- Then the two moderator-audience change docs.

## Carry-over (deferred to PR D)
Modal files built earlier on `fix/pending-url-spam-collection` (main checkout, uncommitted): `components/RipplingExplanation.vue`, `modtools/components/RipplingHelpModal.vue`, `modtools/components/RipplingExplorer.vue` (modified), + 2 specs. Re-create or copy into PR D.

## Documentation deliverables (after all PRs)
Two plain-language MD files, **both aimed at a moderator audience** (Freegle-familiar, NOT
technical, NOT geospatially aware — very simple language). Focus on *what they'll see that's
different, what they can do differently, and what they should NOT do differently*:
1. **What changes for moderators.**
2. **What changes for members** (still written for mods to understand/relay).
Must stress: **do not reject a post just for being "out of area"** — it's showing because it's
close to a member and/or hasn't been taken elsewhere (rippling). Out-of-area rejection by a
secondary group is a real veto we track (#9) and should be rare/intentional, not reflexive.

## Session notes
- 2026-06-16: spec finalised + committed; worktree `rippling-out` created off origin/master (`48be8e973`); feature branch `feature/rippling-reach-engine`. Containers starting. Beginning PR A.
- 2026-06-16: PR A built — `messages_reach` migration (+ idempotent prod SQL), `routing_server_url`/`ripple.*` config (internal no-auth routing port 8194), `ReachService` (drives `/v1/ripple-schedule`, WKT, tick timing), `ExpandService` (init/advance/remove, active-hours gate, #9 log), `ripple:expand` command + scheduler entry. 11/11 Ripple tests green via status API. Dev `spatial` has no UK graph → tests mock routing.
- Design grew: #9 observability/self-tuning; #6 multi-group (secondary out-of-area rejection = clip + no poster notify + track; mod edit "applies to all groups" warning; visibility = reach ∩ approved-covering-group); #10 postcode-driven single-group posting + TN main-group-only (retire manual cross-posting; deploy LATE). Two moderator-audience change docs due after all PRs.
