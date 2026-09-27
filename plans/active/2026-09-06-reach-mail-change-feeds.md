# Reach mail: change feeds replace the 60-minute window

Branch `perf/db2-cpu-reduction`, PR #1488, worktree `FreegleDocker-db2-cpu` (status API :12114).
Design: `docs/superpowers/specs/2026-09-06-reach-mail-member-feed-design.md`.

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | `rippling_reach_member_pending` migration + prod SQL; `ReachMemberQueueService::enqueue()` collapses repeats | ✅ | TDD: enqueue twice = one row |
| 2 | Post side: per-shard watermark on `rippling_reach.updated_at` replaces the window; mark = pass start | ✅ | TDD: bounded by mark; post touched mid-pass caught next tick; stall loses nothing |
| 3 | Drain: reach mail job evaluates queued members via `mailNewlyReachedForPost` on candidate posts | ✅ | candidates = outer_bound contains point + ring-admitted; ledger dedupes |
| 4 | Hooks: Go authMiddleware (return >90d), ProcessSettingsUpdate (moved), addMemberToGroup / putMembershipsPartner / PutUser (joined), PatchMemberships (immediate); PHP ripple auto-join, AddMembershipCommand | ✅ | Go side done; PHP ripple auto-join + AddMembershipCommand hooks still to add | TDD each; return hook fires once per transition |
| 5 | Repost bump: `handleRejectToDraft`, `JoinAndPostAs` touch `rippling_reach.updated_at` | ✅ | JoinAndPostAs only; RejectToDraft leaves a draft | no-op when row dropped |
| 6 | Daily reconciliation command + schedule | ✅ | command test read Artisan::output() twice (fetch clears); fixed, verified in the full run | joins + PostcodeChange since yesterday with no ledger row |
| 7 | Docs (`rippling-algorithm.md` §7 reach mail, last_reviewed); commit spec | ✅ | window key was read with a default and never defined in config, nothing to remove; service no longer reads it |
| 8 | Full Laravel + Go, docs freshness, push, PR body | 🔄 | Laravel 6344 / Go 4380 green locally; pushed 33b80643d; CI pending | |
