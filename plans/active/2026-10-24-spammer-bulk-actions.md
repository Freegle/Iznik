# Spammer list: bulk select, bulk add, copy list

Source: Discourse 3802/25 (Sheila, spammer checking team, 2021-08-17). When a spammer
creates ~50 accounts, each has to be reported, copied for the team's records and
confirmed one at a time.

Worktree: `/home/edward/FreegleDocker-spammer-bulk`, branch `feat/spammer-bulk-actions`.
Status API port 12045, Traefik HTTP 12048.

## Design

- `modtools/composables/useBulkSelect.js` - a capped (50) selection keyed by id.
- `modtools/components/ModBulkSelectBar.vue` - count, "Select all shown", "Clear",
  "Copy list" (plain tab-separated lines, one per member: id, name, email, reason,
  added, reported by), slot for actions.
- `modtools/components/ModBulkSpammerReport.vue` - one reason for many members.
- Spammers page: checkbox per row, bar above the list. Pending Add + Spam admin:
  "Confirm add to spammer list (N)". Held rows are not selectable there. Copy on
  every tab.
- Members page (`ModMembers.vue`): checkbox per row keyed by userid, bar with
  "Add N to spammer list" (Spam admin, goes straight to Spammer) or "Report N as
  spammers" (everyone else, Pending Add), plus Copy.
- Store: `confirmMany(items)` and `addMany({userids, reason, collection})`, five at
  a time, returning `{done, failed}`. No Go changes.

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Worktree + branch | ✅ | |
| 2 | useBulkSelect composable + spec | ✅ | |
| 3 | Store confirmMany/addMany + spec | ✅ | |
| 4 | ModBulkSelectBar + spec | ✅ | |
| 5 | ModBulkSpammerReport + spec | ✅ | |
| 6 | Spammers page wiring + spec | ✅ | |
| 7 | ModMembers wiring + spec | ✅ | |
| 8 | Playwright: bulk confirm + copy | ✅ | test-modtools-spammers.spec.js |
| 9 | Docs: managing-members.md | ✅ | |
| 10 | Browser check (Chrome MCP) | ✅ | modtools-dev-local: select all, copy (3 plain lines), confirm (3) all worked |
| 11 | Lint, vitest, playwright via status API | ✅ | lint clean; all modtools unit specs pass; test-modtools-spammers.spec.js 9/9 on :12045 |
| 12 | Push, PR | ✅ | PR #1913 |
