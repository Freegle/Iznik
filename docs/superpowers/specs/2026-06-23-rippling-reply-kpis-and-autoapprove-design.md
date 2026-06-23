# Rippling: auto-approve rippled-in posts + reply-outcome KPIs

Date: 2026-06-23
Status: implemented (this PR)

## Why this exists (the decision that shaped it)

We considered a live reach-randomized experiment to learn "how far / how many people to
ripple to". We dropped it. The reasoning, recorded so nobody re-derives it:

- The reach **curve** (step-70) and **catch-rate** are already answered from history: the
  `ripplesim` simulator over ~10,521 posts and the `spiralling_analysis.php` hazard analysis
  over 303,491 messages. "Would the schedule reach the eventual replier in time" is a history
  question, and it was answered.
- The remaining unknown was the per-notification conversion rate for an out-of-group immediate
  notification. **We decided to trust the membership-neutral assumption**: response depends on
  whether the person wants the item and how far it is, not on whether they were already a member
  of the posting group (the notification is from Freegle either way). Under that assumption,
  historical reply-by-distance already gives the conversion, so no experiment is needed.
- Therefore the only real cost of rippling is **moderation load**, which we remove by
  auto-approving rippled-in posts. And instead of a randomized trial we **monitor the outcome
  live** with reply KPIs on the sysadmin page.

If the membership-neutral assumption is ever doubted, the minimal check is a small
reach-randomized test of whether out-of-group reach lifts the first-reply rate by what the
history model predicts; it is not built here.

## What this PR ships

### (a) Auto-approve rippled-in posts
`ExpandService` inserts a rippled-in `messages_groups` row as `Approved` at ripple-in time
(no Pending flicker), because the post was already moderated on its origin group. Configurable
via `RIPPLE_RIPPLED_IN_PENDING_HOURS` (default 0 = immediate; >0 = a mod-veto window honoured by
`AutoApproveService`). The receiving-group mod notice explains it was already checked on origin.

### (b) Reply-outcome KPIs on the sysadmin rippling page
Three per-day line charts in `ModSysAdminRippling.vue`, served by `rippling/metrics.go`:

1. **% of Offers with a reply within 36h** - the headline "turn 0-reply posts into 1-reply
   posts" metric. Live from `messages` + `chat_messages`; only days whose 36h window has fully
   elapsed are plotted. Baseline at build time: ~32%.
2. **% of replies that came via rippling** (vs existing group members). Sourced from
   `rippling_reply_attribution` - see the capture note below.
3. **Median reply distance (km)** - post to replier, live from locations.

### (c) View tracking
Lives on PR #848 (`messages_likes.pageview` + `.source`): distinguishes a genuine page-open from
a list-scroll impression and tags a notification-click, so a view->reply correlation can be
measured later. Not in this PR.

## The capture problem (why KPI 2 needs a table, not a query)

Attributing a reply to "rippling vs existing member" cannot be done retrospectively:

- A naive "is the replier a member now?" check is wrong because the Nuxt reply flow **joins the
  group in order to reply** (`useReplyStateMachine.handleJoinGroup`), so every rippling replier
  looks like a member afterwards.
- "Did they join after the post arrived?" is also wrong: someone who joined a week later for
  unrelated reasons, then replied, would be wrongly credited to rippling.

So we capture at reply time, in `CreateChatMessage` (Go), into `rippling_reply_attribution`:
`was_home_member = 1` iff the replier is an approved member of an **origin** (non-rippled-in)
group of the post whose membership was **established more than the join grace (300s) before the
reply**. That excludes a join-made-to-reply (membership age ~0 -> rippling) while still counting a
genuinely established member (membership age large -> home). Frozen at reply time so a later leave
can't erase it. KPI 2 reads this table; it accrues from go-live (no history).

## Reusable guardrail concepts (noted, not built here)

From the dropped experiment harness, two ideas worth keeping for the production reach mailer if
fan-out ever needs bounding: a **per-member fatigue cap** (max N ripple notifications per person
per 24h) and a **per-post nearest-first reach cap**. Neither is needed for (a)/(b)/(c).

## Files

- `iznik-batch/app/Services/Ripple/ExpandService.php`, `AutoApproveService.php`,
  `config/freegle.php`, `iznik-nuxt3/modtools/components/ModMessage.vue` - (a)
- `iznik-batch/database/migrations/2026_06_23_000003_create_rippling_reply_attribution.php`
  (+ `_migration.sql`) - (b) capture table
- `iznik-server-go/chat/chatmessage.go` - (b) reply-time capture
- `iznik-server-go/rippling/metrics.go`, `iznik-nuxt3/modtools/components/ModSysAdminRippling.vue`
  - (b) KPIs + charts
- Tests: `iznik-server-go/test/rippling_metrics_test.go`,
  `iznik-server-go/test/chatmessage_reach_test.go`
</content>
