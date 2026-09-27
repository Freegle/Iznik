# Remove per-person item counts from the outcome flow (ordinary posts)

Status: implemented, tests green, PR open. 2026-09-05.

## Decision (Edward, 2026-09-05)

Telling us how many items each person took is more trouble than it is worth for ordinary
posts. The quantity stays at post time; everything after that is about splitting.

- **Ordinary post**: pick who took some, then one switch, "there's still some left" or
  "that's everything gone". No numbers anywhere after posting.
- **Other members see**: "Part gone, some still available". No precise number once giving
  has started.
- **Bulk clearance offers keep absolute counts, unchanged.** Those offerers are running a
  clearance and can handle the arithmetic. `pages/give/clearance.vue` and the
  `messages_bulk_items` flow are untouched.

## Why the seam is clean

The bulk path already counts independently: `bulkEdit.go:172-189` recomputes
`messages.availablenow` from `SUM(quantity)` over `messages_bulk_items` and auto-closes at
zero, and `bulkItem.go:372-386` writes `messages_by` itself on collection. The clearance
page never uses the outcome modal's arithmetic. So the split is a real boundary, not a flag
smeared through shared code.

## What master did, measured

Reproduced in a browser against the worktree database, post 165, three items, two repliers.
Pick one person, press Mark as TAKEN, and master writes `messages_by.count = 3`, drops
`availablenow` to 0 and writes outcome `Taken`. The member said nothing about quantities.
The modal also shows "There will still be some left. If you're giving them all away now,
please adjust the numbers above" before anyone is picked at all - the Discourse 10078 trap,
where the warning appears exactly when the member has got it right.

## What the branch does, measured

Same post, same data: pick one person, choose "There's still some left", submit. Result is
`messages_by.count = 1`, `availablenow` 2 of 3, and **no row in `messages_outcomes`** - the
post stays open and reads "Part gone, some still available".

## Server

No change. `handleAddBy` already defaults `count` to 1 when the request omits it, so
dropping the count from the client leaves `availablenow < availableinitially` true, which is
the "part gone" signal. Both fields are already in the message JSON and the summary JSON.

## Badge rules

| Post | State | Shows |
|---|---|---|
| Bulk clearance | any | "N available" (unchanged) |
| Ordinary, availableinitially 1 | any | nothing |
| Ordinary, untouched | on offer | "N available" |
| Ordinary, part gone | on offer | "Part gone, some still available" |

## Tasks

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Failing tests: OutcomeBy without counts, with remove control | ✅ | |
| 2 | Failing tests: OutcomeModal some-left/all-gone switch | ✅ | |
| 3 | Failing tests: shared availability badge | ✅ | |
| 4 | Confirm the new tests fail against pristine master | ✅ | 16 failed, 75 pre-existing still passed |
| 5 | Implement shared MessageAvailability badge, wire 3 sites | ✅ | replaced 4 copies of drifted markup |
| 6 | Implement OutcomeBy: drop spinners, add remove, keep bulk path | ✅ | |
| 7 | Implement OutcomeModal: switch drives complete + showCompletion | ✅ | |
| 8 | Full vitest suite green | ✅ | 16,325 passed, 0 failed |
| 9 | Headless Chrome verification, GPU disabled, before/after shots | ✅ | worktree instance, port 12437 |
| 10 | Adversarial UX review | ✅ | found and fixed the part-gone copy bug (task 11) |
| 11 | Fix: part-gone post kept plural copy | ✅ | `several` keys off availableinitially, not availablenow |
| 12 | Docs updated | ✅ | docs/members/giving.md covers MyMessage.vue |
| 13 | Lint clean, PR raised | ✅ | |

## Known limitations, recorded not fixed

1. **Reopening the modal does not show who you already told us about.** `OutcomeBy.vue:141`
   reads `message.by`, which the v2 API never returns - there is no `by` field on the
   message JSON. So "You can save and come back later if you like" shows an empty picker on
   the second visit. Re-adding the same person is harmless (`handleAddBy` restores the old
   count before applying the new one, so it is idempotent per user), but the member cannot
   see what they already recorded. Pre-existing; the split flow leans on it harder.
2. **A post can reach `availablenow` 0 while still open.** Name as many takers as the
   quantity you posted and keep saying "there's still some left", and the count clamps at 0
   with the post open. `ChatMessagePromised.vue` gates its promise and TAKEN buttons on
   `refmsg.availablenow`, so those vanish in chat. It needs the member to contradict their
   own posted quantity (the right action there is Edit, which is supported), and My Posts
   gates TAKEN on `!taken && !withdrawn` rather than the count, so the post can always be
   closed. `availablenow` is not used as a WHERE filter anywhere in the Go API or Laravel,
   so nothing hides the post. Changing that chat gate means redefining that component's
   "no longer available" contract, which has its own copy and spec coverage - a separate
   change, not this one.
