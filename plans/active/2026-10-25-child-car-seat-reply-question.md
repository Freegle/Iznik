# Child car seat: safety question at the point of reply

Source: Discourse 9939, posts 53 to 63. Neil's wording for repliers is post 61; Saira asked
for an offerer version in post 62; Zach asked about the bare word "seat" in post 63.

Branch `feat/car-seat-reply-confirm` (worktree FreegleDocker-spammer-bulk, stack stopped:
host memory too low for a second running stack, so the database Go tests run in CI).

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Go: car seat path in planReplyConfirm, outside the experiment, app included | ✅ | replyconfirm.go, chatmessage.go |
| 2 | Go tests: pure (words, wording) and API (arms, app, once, wanted, damaged, TN) | ✅ | compile-checked with go vet; run in CI |
| 3 | Posting note for car seats: offer and wanted wording, more words | ✅ | PostItem.vue, spec passes |
| 4 | Docs: chat-prompts, ux-experiments, members/getting | ✅ | freshness check OK |
| 5 | Push, PR, CI green | ✅ | PR #1914, pipeline 13589 green |
| 6 | Discourse 9939: reply with what was built, ask Saira/Neil to confirm the offerer wording | ✅ | post #64 |
| 7 | Screenshots in the PR: posting note, chat question | 🔄 | |
