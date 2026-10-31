# Token burn candidates

Large, token-heavy builds written up so they can run flat out, with nobody there, during a
period when Claude tokens are abundant. The `token-burn` skill in `.claude/skills/token-burn/`
says what qualifies and how to write one; `candidate-template.md` beside it is the shape.

The point of this folder is to spend the cheap time now on the parts that are expensive
later: decisions with reasons, external access, and a task split that fits parallel
worktrees. Implementation plans are not written here; they are regenerated when a build
starts. `infrastructure.md` is the exception: it is the machines the window runs on, and its tasks
are done before the window, not during it.

## Index

| Candidate | Status | Open questions | Parallel tracks | Last reviewed |
|-----------|--------|----------------|-----------------|---------------|
| [Income dashboard in ModTools SysAdmin](finance-dashboard.md) | draft | 10 | 4 (A: 1-2, B: 3 and 7, C: 4-5, D: 6-6a) | 2026-10-28 |
| [ModTools organised by role, most urgent first, nearest first](modtools-by-role.md) | draft | 7 | 4 (A: 1-2, B: 3, 6-7, C: 4, D: 5) | 2026-10-28 |
| [Automated review inside the no-communities experiment (thought experiment)](automated-review-with-no-communities.md) | draft | 6 | 4 (A: 1-2, B: 3-4, 7, C: 5, D: 6-6a) | 2026-10-28 |
| [Infrastructure: one VM per stream](infrastructure.md) | draft, scripts proven | 4 | 2 (A: 1-2, 4-5, 7, B: 3, 6) | 2026-10-28 |
| [Discourse reply bot and the single channel for reports](discourse-reply-bot.md) | draft | 6 | 3 (A: 1, 5, B: 2-3b, 6, C: 4) | 2026-10-28 |
| [Move the archived images off Azure](azure-archive-migration.md) | draft | 5 | 2 (A: 1-2, 5, B: 2a, 3-4) | 2026-10-28 |
| [Support helper runs first on a referral](support-helper-flow-through.md) | draft | 7 | 4 (A: 1-1a, 4, B: 2, 6, C: 3-3a, D: 5) | 2026-10-28 |
| [A command log in front of the database](command-log-availability.md) | draft | 4 | 4 (A: 1, 3, 5, B: 2, 7, C: 4, D: 6) | 2026-10-28 |
| [User backup index and self-service restore](user-backup-self-service.md) | draft | 3 | 3 (A: 1, 5, B: 2-3, C: 4) | 2026-10-28 |
| [Incident response with nobody awake](incident-response-automation.md) | draft | 4 | 3 (A: 1, 6-7, B: 2-3, 5, C: 4) | 2026-10-28 |
| [An active snapshot served instead of the big tables](active-snapshot-read-model.md) | draft | 6 | 4 (A: 1-2, 5, B: 3, 6, C: 4-4a, D: 7) | 2026-10-28 |
| [Deep code review, every finding proved by a test](deep-code-review.md) | draft | 4 | 6 (A: 1, 7, B to F: 2 to 6) | 2026-10-29 |
| [The monitor FSM answers the geeks mailbox](geeks-mailbox-fsm.md) | draft | 4 | 4 (A: 1-2, 7, B: 3-4, C: 5, D: 6) | 2026-10-29 |
| [Make ChitChat useful, and tell local from national](chitchat-local-voice.md) | draft | 4 | 4 (A: 1, 5, B: 2, C: 3, D: 4) | 2026-10-29 |
| [Make the code run on PostgreSQL as well as MySQL](postgresql-migration.md) | draft | 7 | 7 (A: 1-2, 6, 10, B: 3-4, 8, 15, C: 5, D: 7, E: 9, F: 11-12, G: 13-14) | 2026-10-30 |

Status: `draft` (open questions remain), `ready` (none remain, prerequisites in hand),
`running` (a worktree exists), `done` (delete the file; git keeps it).

## Lifecycle

1. Harvest and screen with the skill; write the file from the template.
2. Batch the open questions to Edward; record the answers as decisions with reasons.
3. Start external prerequisites as soon as they are known.
4. Mark `ready`. When the tokens arrive: worktree, fresh plan from the file, `ralph`, push,
   let CI run.
