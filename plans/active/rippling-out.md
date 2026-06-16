# Rippling Out rollout — implementation tracker

**Spec**: `plans/rippling-out-rollout/design.md` (read it before working — it is the source of truth).
**Worktree**: `/home/edward/FreegleDocker-rippling-out` · **Branch base**: `origin/master` · **Feature branch**: `feature/rippling-out`
**Goal**: all PRs pushed → through CI → adversarial-review fixes applied. **Not merged** (humans merge).
**Rule**: PRs split by deployability — reach infra first (dark), **browse & email last**. Each UI PR carries screenshots. Inter-PR dependencies stated explicitly in each PR body.

## PR / deployability plan

| PR | Branch | Contents | Depends on | Status |
|----|--------|----------|-----------|--------|
| A | `feature/rippling-reach-engine` | #0 reach calc: `messages_reach` migration + `ripple:expand` command (no mails) | — | ⬜ Pending |
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

## Carry-over (deferred to PR D)
Modal files built earlier on `fix/pending-url-spam-collection` (main checkout, uncommitted): `components/RipplingExplanation.vue`, `modtools/components/RipplingHelpModal.vue`, `modtools/components/RipplingExplorer.vue` (modified), + 2 specs. Re-create or copy into PR D.

## Session notes
- 2026-06-16: spec finalised + committed; worktree `rippling-out` created off origin/master (`48be8e973`); feature branch `feature/rippling-out`. Containers starting. Beginning PR A.
