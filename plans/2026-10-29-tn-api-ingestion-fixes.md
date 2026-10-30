# TrashNothing API post ingestion: fixes before it goes live

Follow-up to [2026-10-21-membership-implicit-and-origin.md](2026-10-21-membership-implicit-and-origin.md).
Its own PR, started once that work is done: every phase shipped, including the PR that moves the
reads onto `implicit` / `added_origin` and drops the obsolete columns. TN API ingestion stays
off until this plan has shipped (row 6 of that plan's production sequence).

After that plan, a TN poster who didn't opt in to a group has an implicit membership of it
(`implicit = 1`, `added_origin = 'tn_unaddressed'`). Contact and post ownership are worked out
per group from that membership, and moderators cannot contact any implicit member. What is left
is the TN-only behaviour.

The decisions are in that plan's Phase 0. Line numbers below date from 2026-10-21 to 2026-10-29
and may have moved.

---

## 1 - TN user ID used as a Freegle user ID ([#1697](https://github.com/Freegle/Iznik/pull/1697))

- [ ] Rewrite #1697 against master once the parent plan's PRs are merged, then get it merged.
  Ingestion read TN's `user_id` as a Freegle user id, both to look up the poster and to create
  one. #1697 as it stands looks posters up by `users.tnuserid`, creates new ones from TN's users
  endpoint as `<username>@user.trashnothing.com`, and stops the TN sync merging accounts with
  different TN ids. The parent plan's branch already contains its fix commit `1c7ac72deb`, so
  much of it will be obsolete; keep only what master still lacks.
- [ ] #1697's TODO, repairing users and posts hit by the bug: Q14, Q14b and Q14c of
  [2026-10-22-membership-origin-prod-queries.sql](2026-10-22-membership-origin-prod-queries.sql)
  found none on production on 2026-10-29 (every API post has a linked poster, and no `fromuser`
  is another account's `tnuserid`). Re-run them just before ingestion goes back on, and record
  the result on #1697.

## 2 - A TN member who leaves a group can no longer be contacted by its moderators

- [ ] Today a moderator can open a chat to anyone, member or not
  ([chatroom.go:402](../iznik-server-go/chat/chatroom.go#L402) checks only that the moderator moderates the group).
  Add: a moderator-started chat to a TN account needs an Approved, non-implicit membership.
  The same applies to standard messages and mail.
- [ ] An **existing** chat room closes to the group's moderators once the member leaves (decided
  2026-10-29), so an old chat can't get round the rule. Today that room is returned before any
  check runs.

## 3 - A signature on every moderator message to a TN member

- [ ] TN shows the message as coming from the Freegle user, and our "{Group} Volunteers" sender
  name ([ChatNotification.php:165-188](../iznik-batch/app/Mail/Chat/ChatNotification.php#L165)) is
  lost, so the member can't tell a moderator's message from another member's. Append a sign-off to
  the body for TN recipients (`User::isTN()`, plus a `tnuserid` check) on: User2Mod chat
  notifications, standard messages
  ([ProcessBackgroundTasksCommand.php](../iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php)),
  and moderator replies about a post. No existing signature mechanism was found, so this is new.

## 4 - Replying doesn't join a TN member to the group

- [ ] A TN user replying to a group member's post must not become a member of that group, and must
  not make an implicit membership ordinary.
  - Skip TN users in `clearForReplies` and `clearForWriteBacks`
    ([RippleEngagementService.php:209](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L209),
    [:241](../iznik-batch/app/Services/Ripple/RippleEngagementService.php#L241)).
  - Check every path a TN user's reply can come in by for a join, and skip it for TN users. The
    Go join-to-reply is `AddMembership(..., "Joined to reply to a post", addedorigin.Replied)` at
    [chatmessage.go:1099](../iznik-server-go/chat/chatmessage.go#L1099).
- [ ] Those clears and joins exist so moderators can deal with the replier. For TN users,
  moderators report the user through TN's report API instead:
  - [ ] **Ping Andrew (TN) a few days before this ships** so the report API is ready on their side.
  - [ ] TN API client method, a Go endpoint or background task, and a ModTools "Report to
    TrashNothing" action, shown where Chat is hidden for TN implicit members.

## 5 - Ensure the mixed-poster rule is gone

The rule let moderators of **any** group contact a TN user as long as one of their posts had
consented to some group. The parent plan's Phase 5 removes it, along with
`UserIsUnaddressedOnly`. Before ingestion goes live:

- [ ] Grep for anything that still decides contact from a person's posts or other memberships.
- [ ] An end-to-end check with a TN poster who consented to group A and was cross-posted to group
  B: A's moderators can chat, send standard messages and mail, and edit and get reports on the
  post; B's can do none of these, and B's ModTools member list hides the buttons.

## 6 - A banned poster can't post to the group

Decided 2026-10-29: an FD member banned from a group can't post to it, and neither can a TN one.

- [ ] Ingestion skips a group the poster is banned from (`users_banned`), for the post as well as
  the membership. Today it skips only the membership
  ([GroupPostIngestionService.php:492](../iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php#L492))
  and still places the post, which the group's moderators then own (the parent plan's Phase 5
  rule). Skip with a reason, as for an unknown user. If every group is skipped, the post is not
  ingested at all.
- [ ] A test: a poster banned from one of two groups gets the post on the other group only.

## 7 - Side effects of TN memberships

- [ ] Banning a TN implicit member deletes the row, so their posts on the group pass to its
  moderators (the parent plan's Phase 5 rule). Check that the ban also takes the posts down.
- [ ] TN re-subscribes its members by mail (see [modtools.md](../.claude/rules/modtools.md)). Check that
  doesn't turn an implicit membership into an ordinary one.

---

## Throughout

- **Deploy order.** Go API, then batch, then frontend, as in the parent plan.
- **Tests.** For every change, a test with a TN implicit member, a TN member with an ordinary
  membership, and a TN member who has left the group. Run the Go, Laravel, Vitest and Playwright
  suites through the status container.
- **Docs, in the same PR as each change:** [trashnothing.md](../docs/developers/reference/trashnothing.md)
  and the moderator docs on contacting members. `scripts/check-docs-freshness.mjs` enforces this.
