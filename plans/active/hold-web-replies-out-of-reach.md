# Hold web replies out of reach (like the email hold)

Branch: `feat/hold-web-replies-out-of-reach` (base origin/master ea917a0cd).

## Goal
When a logged-in user replies to a post whose rippling reach hasn't reached them, stop
REJECTING (403 not_in_reach) and instead HOLD the reply (like email/TN already do): create
the chat message, record a `rippling_held_replies` row (status='held'), and let the existing
`ripple:release-replies` cron deliver it when the post ripples to them. The replier is told
their reply will be passed on; the "you might see things you can't reply to yet" copy goes.

## Key facts (from verified analysis)
- Web write gate: `iznik-server-go/chat/chatmessage.go:573-574` returns 403 BEFORE `db.Create`
  (line 588). Reach evidence (`rc.ReachRows/rc.InReach`, `latlng`) already computed at 566-573.
- Release lifecycle (`ripple:release-replies`, every min) is SOURCE-AGNOSTIC — web rows release
  identically. No lifecycle change needed.
- `rippling_held_replies` has no `updated_at` → Go must `db.Exec` an explicit INSERT (not GORM Create).
- Delivery gate hides held reply from poster across FetchChatMessages, chatroom.go, PHP
  ChatNotificationService, push. BUT gaps that web-hold amplifies (fix): consumerUnreadCounts
  badge (PushNotificationService.php:309), ChatExpectedService.updateExpected:149, ChaseUpService.recentChat:296.
- Read-path `replyeligible` = message.go:877-965. FE gates: ChatReplyPane.vue:107-123,
  MessageExpanded.vue:443-449/527-533/1071-1076, useReplyStateMachine.js:723-730 + 440-446/891/1010/1109.
- Sender pending badge: `heldbyrippling` only set for mods (chatmessage.go:221) → also set for sender's own view.
- Copy to remove: WhichPostsExplanation.vue "Can I always reply?" (lines 35-42).

## Status table
| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Migration: `source` enum (email/tn/web) on rippling_held_replies + idempotent SQL | ✅ | default 'email'; verified present in iznik_go_test |
| 2 | Go write path: 403→hold (insert held row, source='web', increment 'held' metric) | ✅ | chatmessage.go; insert after db.Create for chatmsgid |
| 3 | Go: set heldbyrippling for SENDER's own held messages (pending indicator) | ✅ | FetchChatMessages: modAccess OR userid==caller |
| 4 | PHP hold(): accept + set `source` ('email'/'tn') | ✅ | source via isFromTrashNothing() at both callers |
| 5 | Delivery-gate gap fixes (badge inflation + chase-up side-effects) | ✅ | PushNotification/ChatExpected/ChaseUp gated |
| 6 | Sysadmin metrics: held breakdown by source | ✅ | held_reply_by_source + friction-panel display |
| 7 | FE: let composer through + pre-send "we'll pass it on" notice | ✅ | ChatReplyPane, MessageExpanded (both footers + ?reply=) |
| 8 | FE state machine: drop proactive block; 403 backstop kept | ✅ | useReplyStateMachine.js |
| 9 | FE: sender-facing "waiting to send" badge | ✅ | ChatMessage.vue (branches on userid==myid) |
| 10 | Remove "you might see things you can't reply to yet" copy | ✅ | WhichPostsExplanation + ChatFooter 403 copy |
| 11 | Docs: RIPPLING-OUT-FOR-MEMBERS.md + RIPPLING-OUT-FOR-MODERATORS.md | ✅ | |
| 12 | Tests: Go hold-insert+withheld; FE reach-gate specs flipped | ✅ | + source column in all 4 test stand-ins |
| 13 | Run suites (Go/vitest/Laravel), open PR | ✅ | Go 3426✓, vitest 14233✓, Laravel 4769✓ — all green |

## Result
All suites green (2026-07-08). PR opened off origin/master. Concierge commit sits on local
master (unpushed). Browser verification of the exact reach-blocked UI not staged (needs an
out-of-reach post for the test user); vitest covers the component behaviour.

## Proposed copy
- Pre-send / held state: "This item hasn't reached your area yet, but we'll pass your reply to
  the owner as soon as it does." (replaces "you can't reply / closest first" error copy)
- Sender badge on the held message: "Waiting to send — we'll deliver this when the item reaches your area."
- WhichPostsExplanation "Can I always reply?" → "Yes. If you reply to something that hasn't
  reached your area yet, we'll hold your reply and pass it on the moment it does."
