# Monitor scan fixes, 13 September 2026

Approved by Edward from the scan-only monitor run of 12 September. Work one at a time, each
as its own branch off origin/master, TDD, full relevant suite green locally, then PR. Never
merge. Status markers: ⬜ pending, 🔄 in progress, ✅ PR open, ❌ blocked.

Local tracker only (not committed); the session log (.claude-session.md) points here.

## In-flight PR merge work (must finish first)

| PR | State |
|----|-------|
| 1497 | pushed after merge; MERGEABLE |
| 1493 | pushed e4e9b4cd7; MERGEABLE; local L+G on 12138 to report |
| 1488 | pushed 5613a0880; MERGEABLE; local L+G on 12114 to report (chat unit test to re-check) |
| 1490 | pushed 0f744473b; MERGEABLE; local Laravel + filtered agreement Go on 12210 to report |
| 1482 | pushed f22f88e82; note as comment 5652364251; MERGEABLE |
| 1500, 1266, 984, 962, 658 | real code conflicts, not started; 1500's worktree in use by another session |

## Fixes (in order)

| # | Fix | Area | Status | Branch / PR |
|---|-----|------|--------|-------------|
| 1 | Owner/Moderator self-leave refused when rippling did not create the row; unsubscribe picker gated | Go membership.go, nuxt unsubscribe page, Laravel rejoin | ✅ PR 1502 MERGED 16:02 UTC (CI green after merge spec stub; user override) | fix/owner-self-leave-10148 |
| 2 | Expiry job sources candidates from messages_groups, not the 31-day spatial index | Laravel MessageExpiryService | ✅ PR 1503 | fix/expiry-candidates-live-postings-9808 |
| 4 | Incoming mail routing scopes the Pending update by group id | Laravel IncomingMailService | ✅ PR 1508 (Laravel 6338 green) | fix/tn-crosspost-pending-scope-10142 (scan-fixes) |
| 5a | AutoApprove origin-only condition (rippled_in = 0) | Laravel AutoApproveService | ✅ PR 1509 (Laravel 6342 green) | fix/autoapprove-origin-not-rippled-in-10102 (scan-fixes) |
| 5b | Withdrawn: pending count ignores deleted rows; gate Withdrawn button on home group | Go message.go, ModMessageButtons.vue | ✅ PR 1512 (Go 4385 + vitest 16355 green) | fix/withdrawn-scope-rippled-copies-10102 (fsm-switch) |
| 5c | hasCollection scoped to context group; plain-delete Reject on Approved row guarded | ModMessage.vue, ModMessageButtons.vue, Go handleReject | ✅ PR 1514 (Go 4383 + vitest 16362 green) | fix/modtools-collection-scope-10102 (fsm-switch) |
| 5d | SendForReviewAllGroups logs a row per flipped group | Go microvolunteering.go | ✅ PR 1511 (Go 4382 green) | fix/back-to-pending-log-per-group-10102 (scan-fixes) |
| 3 | Unpromise in the mobile action row | MyMessage.vue | ✅ PR 1506 | fix/unpromise-mobile-10152 |
| 6 | Bottom padding for Browse and My Posts lists | PostMapAndList.vue, MyPostsPostsList.vue | ✅ PR 1507 (vitest 16353 green) | fix/browse-myposts-bottom-padding-9808 (fsm-switch) |
| 7 | Dedup the in-bounds fetch in PostMap.vue | PostMap.vue | ✅ PR 1510 (vitest 16355 green; runtime check on explore place page) | fix/postmap-inbounds-dedup-sentry (fsm-switch) |
| 8 | Sentry beforeSend filter for pagead2.googlesyndication.com | sentry.client.ts | ✅ PR 1505 | fix/sentry-ignore-google-pagead |
| 10 | Bad-AI-image flag for all mods; mods (not members) can delete a post image on the member site with the same popup | Go aiimage suppress authz, ModTools menu, FD MessageExpanded/photo delete | ✅ PR 1515 (vitest 16362 green; runtime-verified) | fix/ai-image-remove-reason-for-mods-9630 (scan-fixes) |
| 11 | monitor-fsm PARSE_ONLY guard moves to the top of the step loop | monitor-fsm/src/driver.ts | ✅ PR 1504 | fix/monitor-fsm-parse-only-guard (fsm-switch) |

Not approved: 9 (tusd lock / HEIC upload).

| L | ModTools reach modal: explorer bare leaflet import clobbers window.L | setupRipplingExplorer.js | ✅ PR 1519 (vitest 16402 green) | fix/rippling-explorer-single-leaflet (fsm-switch) |
