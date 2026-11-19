# TrashNothing chat messages by API instead of email

Replace the email path that brings TrashNothing (TN) members' chat messages into Freegle with a
poll of a new TN partner endpoint, `https://trashnothing.com/fd/api/chat-messages`, run from
`tn:sync` in the same way as ratings, user changes and posts.

**Scope: inbound only.** Messages Freegle sends to TN members (chat notifications, moderator
mail) stay on email for now and are replaced later. Subscribe/unsubscribe mail, bounces and
group posts are not chat and are not touched here.

The approach is the one the posts work took ([tn-api-post-ingestion.md](tn-api-post-ingestion.md),
PR #1013 and its follow-ups): the email path stays frozen, the API path is additive, a parity job
compares the two on real traffic until they agree, one flag does the cutover, and a coverage
check uses the incoming-mail archive as an independent witness afterwards.

---

## 1. What TN sends us by email today

Every TN chat message reaches Freegle as an email from the member's TN address. Which handler
picks it up depends only on the address TN sends it to.

| # | What the TN member did | Address TN mails | Handler (`IncomingMailService`) | What it writes |
|---|---|---|---|---|
| 1 | Replied in an existing chat | `notify-{chatid}-{userid}[-{chatmsgid}]@users.ilovefreegle.org`, the Reply-To of our chat notification | `handleChatNotificationReply` (:1893), reached from `route()` Phase 2 (:121) | A `Default` message in that chat. User2User, or User2Mod when a moderator wrote to the member |
| 2 | First reply to a Freegle member's post | The poster's address `*-{uid}@users.ilovefreegle.org`, with an `X-FD-MsgId` header naming the post | `handleDirectMail` (:3619), Phase 6 | Finds or creates the User2User chat, writes an `Interested` message with `refmsgid`, applies the rippling reply hold (`holdReplyIfOutsideReach`, source `tn`) |
| 3 | Wrote to a Freegle member, not about a post | Same address, no `X-FD-MsgId` | `handleDirectMail` | `findRefMessage` (:3748) tries a fuzzy subject match on the recipient's posts from the last 90 days; failing that, a `Default` message with the subject put in front of the body |
| 4 | Wrote to a community's volunteers | `{nameshort}-volunteers@groups.ilovefreegle.org` | `handleVolunteersMessage` (:2529), Phase 4 | A message in the member's User2Mod chat with that community |
| 5 | Reported a member or a post | Same as 4, subject `Reporting member "…"` / `Reporting …` | `handleVolunteersMessage` | As 4, but quoted text is kept because the body carries TN's conversation transcript |
| 6 | TN sent an automated response on the poster's behalf | Arrives as 1 or 2 | As 1 or 2 | The message is kept; `StripQuotedService` removes TN's `[Note: This is an automated response from trashnothing.com on behalf of the post author]`. Q2 and Q9 found none in two days |
| 7 | Replied to a digest | `replyto-{msgid}-{uid}@…` | `handleReplyToAddress` (:1624) | Only the digest sets this Reply-To, and digests skip TN members. Q1 found none, so the feed does not need it |
| 8 | Replied to our notification's From instead of its Reply-To | `noreply@ilovefreegle.org` | `handleDigestReply` | Nothing: the member gets the "we can't tell which post" auto-reply and the message is lost. #790 tried to recover these and was closed. The API fixes this by construction, because TN will name the chat |
| 9 | Nobody: TN itself sent a notice to a community's volunteers | `{nameshort}-volunteers@groups.ilovefreegle.org`, From `noreply@trashnothing.com`, signed with TN's secret | `handleVolunteersMessage` | A message in a User2Mod chat from TN's own account on Freegle: the account holding `noreply@trashnothing.com`, named for Trash Nothing, made from that address in December 2020 and holding a TN user id. It has 34 User2Mod chats and has sent 23,818 messages since June 2021 (Q12, Q12b) |

### Results so far

Q1 and Q7, run on yesterday's snapshot of 18 November 2026. The snapshot's chat mail runs from
about 02:30 on 16 November, where the purge cut it off (below), to about 04:00 on 18 November, when
the backup was taken: about 49 hours. Q1's rows add up to 5,468, the same as Q7's days, so every TN
chat mail falls into one of the rows.

| Row | Messages | Senders | Chats |
|---|---|---|---|
| 1, reply in a User2User chat | 3,643 | 1,434 | 1,654 |
| 1, reply in a User2Mod chat | 9 | 4 | 4 |
| 2, first reply to a post | 1,788 | 1,354 | 1,611 |
| 3, message not about a post | 1 | 1 | 1 |
| 4, to the volunteers | 17 | 7 | 14 |
| 5, report | 10 | 10 | 10 |
| 7, digest reply-to | 0 | | |

| Day (Q7) | TN chat mails | Busiest hour | Hours with mail |
|---|---|---|---|
| Mon 16 Nov (from about 02:30) | 2,800 | 218 | 22 |
| Tue 17 Nov | 2,629 | 209 | 24 |
| Wed 18 Nov (to about 04:00) | 39 | 21 | 4 |

What this settles:

- **Volume.** About 2,650 messages a day: 2,629 on Tuesday 17 November, the one whole day in the
  snapshot. Two thirds are replies in existing chats and one third first replies to posts. Mail to
  the volunteers and reports together are about half a per cent.
- **Load on the sync is small.** The busiest hour had 218 messages, about one every 16 seconds. A
  `tn:sync` run every ten seconds will usually see none, one or two, and one page of 100 covers
  about half an hour even at the peak. A feed held for its full hour (`FEED_HOLD_MAX_MINUTES`)
  re-reads at most about three pages a run. The cost is the fourth request a run, not the volume.
- **It agrees with the earlier count.** [trashnothing.md](../docs/developers/reference/trashnothing.md)
  records 28,051 TN replies to Freegle posts in the 30 days to 3 November. 1,788 in 49 hours is
  about 26,000 in 30 days.
- **Row 3 is almost nothing.** TN names the post on nearly every first reply. That supports dropping
  the fuzzy subject match (3.2). Q2's `has_fd_msgid` shows whether `X-FD-MsgId` is on every row 2
  mail.
- **Row 7 is not a TN route.** The feed needs no kind for it, and the gate need not cover
  `replyto-` addresses.
- **Row 4 is mostly TN itself.** 17 messages from 7 senders landed in 14 chats. 11 of them were
  TN's own notices to volunteers, from `noreply@trashnothing.com` (Q4, Q11), which section 1 now
  lists as row 9. That leaves about 6 messages from members to the volunteers in two days. On the
  second snapshot it was 3, beside 10 of TN's notices (Q8, Q11).
- **No member's message to the volunteers goes to several communities.** Q8, on the second
  snapshot, found 13 mails to the volunteers with 13 different texts, and 8 reports with 8. Each
  member message reaches one community's volunteers.
- **Rows 4-5 are rare but handled differently**: different spam checks, and the transcript is kept.
  From members they are about 7 a day: 16 on the first snapshot, 11 on the second, 27 in about 97
  hours. So the parity gate (5.3) must run long enough to see enough of them; fourteen days gives
  about 90.

Q2, on the same snapshot: the headers on TN chat mail.

| Route | Mails | Secret | TN user id | `X-FD-MsgId` | TN message id header | Image part | HTML part |
|---|---|---|---|---|---|---|---|
| 1 notify | 3,652 | 0 | 3,650 | 2,841 | 0 | 0 | 3,650 |
| 2-3 direct | 1,789 | 0 | 1,789 | 1,788 | 0 | 0 | 1,789 |
| 4-5 volunteers | 27 | 11 | 16 | 1 | 0 | 0 | 8 |

What this settles:

- **TN does not put its secret on reply mail.** None of the 5,441 replies carries
  `X-Trash-Nothing-Secret`, while 11 of the 27 mails to the volunteers do. Nothing on our side
  strips it: nothing in the app touches the header, and the only `header_checks` is on the outbound
  relay. So the gate cannot rely on the secret (4.3).
- **The TN user id is on almost every reply.** All but 2 of the 5,441 replies carry it, but only 16
  of the 27 mails to the volunteers. Without the secret anyone could write it, so it is fit for
  measuring (Q4) and for nothing that decides who someone is (conventions).
- **TN names the post on most chat replies too.** `X-FD-MsgId` is on 2,841 of the 3,652 replies in a
  chat. The email path ignores it there and writes no `refmsgid` on a chat reply. The API path does
  the same, for parity (7.1). Using it could be an improvement later, made on both paths.
- **Every first reply but one names the post.** 1,788 of 1,789 do. The one without is Q1's single
  row 3 message, so the fuzzy subject match decided nothing in the window.
- **TN attaches no images to chat mail.** None of the 5,468 has an image part, so `addPhotosToChat`
  never runs for TN mail and the API path need not mirror it for parity. Photos arrive as links in
  the text instead (Q9, below).
- **Q2 missed TN's per-mail id.** It tested only for header names with "message", "chat" or "reply"
  in them. The header scan (below) found `X-Trash-Nothing-Log-Id` on every mail.
- **Nearly every reply is multipart with an HTML part.** The email path uses the text part. Q2's
  `automated` count was 0, but it searched the encoded raw mail, which can hide a phrase. Q9 reads
  the decoded text instead.

Q9, on the second snapshot: what is in the decoded text part the email path stores.

| Route | Mails | No text part | Automated note | Photo links | Any TN link | TN signature | "Collection times" | Quoted lines | Stripped over 20% | Avg kept / text chars |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 notify | 3,577 | 0 | 0 | 35 | 35 | 0 | 5 | 0 | 0 | 80 / 80 |
| 2-3 direct | 1,691 | 0 | 0 | 14 | 15 | 0 | 1,528 | 0 | 4 | 137 / 135 |
| 4-5 volunteers | 21 | 0 | 0 | 7 | 17 | 0 | 3 | 10 | 3 | 739 / 805 |

What this settles:

- **TN's text is already clean.** Every mail has a text part. Replies carry no quoted history and
  no TN signature, and the email path keeps nearly all of it: 80 of 80 characters on average for a
  chat reply, 137 for 135 on a first reply. Only mail to the volunteers loses much, because reports
  carry transcripts. So the feed's `text` should be the text part TN sends today, and the parity
  job's text comparison has little to normalise (5.3).
- **First replies carry TN's "Possible collection times:".** 1,528 of 1,691 do, about 90%. It is
  part of the text the email path stores, so the feed's `text` must include it the same way (7.1).
- **Photos arrive as links in the text.** 56 of the 5,289 mails carry a `trashnothing.com/pics/`
  link, about 1%. The email path stores them as text, so for parity the API path does the same.
  Fetching them as attachments would be a later improvement, made on both paths (7.1, `images`).
- **No automated responses in two days.** Q9 found none in the decoded text, as Q2 found none in
  the raw mail. Row 6 may no longer be in use. The feed keeps the `automated` flag anyway, since it
  costs nothing and the email path still handles the note.

Q3: the Message-IDs on TN chat mail, over the two-day window.

| Message-ID domain | Mails |
|---|---|
| `m2.trashnothing.com` | 2,082 |
| `s2.trashnothing.com` | 1,966 |
| `w5.trashnothing.com` | 1,410 |

All three have the shape `{yymmddhhmm}.{32 hex}@{host}.trashnothing.com`.

What this settles:

- **Every TN chat mail carries a Message-ID TN made.** There is no row with no Message-ID, or with
  the one the email path makes up when a mail has none. Each is the send time (`yymmddhhmm`), a
  32-character hex string, and one of three TN hosts.
- **The Message-ID is the fallback key.** If the feed cannot use TN's log id (below), each record
  could carry this Message-ID instead, which the email path already stores in
  `messages.messageid` (5.2).

The header scan, on the same two days: every `X-` header on TN chat mail. It shows the shape of
TN's values, never the values.

| Header | Mails | Value shape |
|---|---|---|
| `X-Trash-Nothing-Log-Id` | 5,468 | UUID |
| `X-Trash-Nothing-User-Id` | 5,455 | 5 to 8 digits |
| `X-Trash-Nothing-Ip-Hash` | 5,447 | 40 hex characters (a SHA-1) |
| `X-FD-MsgId` | 4,630 | 9 digits |
| `X-Trash-Nothing-Meta` | 21 | 11 or 18 characters |
| `X-Trash-Nothing-Secret` | 11 | not shown |
| `X-Spam` | 1 | not TN's |

What this settles:

- **TN has its own id for every mail: `X-Trash-Nothing-Log-Id`.** It is on all 5,468, and is a
  UUID. It looks like the key of a log TN keeps of what it sends to Freegle. Q10 confirms it is
  unique, on two snapshots: 5,458 mails carried 5,458 distinct log ids, then 5,289 carried 5,289,
  and none lacked one.
- **That makes the correlation key free.** If the feed's `id` is the same log id, a record and its
  email carry the same value, and TN adds nothing to its mail (5.2).
- **TN may have a chat log to compare against.** The posts parity check reads `fd-post-log.csv`,
  TN's log of the post mail it sent, which has the same `X-Trash-Nothing-Ip-Hash` column. A chat
  counterpart keyed by this log id would list every chat mail TN sent, including any we dropped or
  never received (7.2).
- **The counts agree with Q2.** `X-FD-MsgId` on 4,630 is 2,841 + 1,788 + 1. The TN user id on
  5,455 is 3,650 + 1,789 + 16.
- **`X-Trash-Nothing-Meta` is a list of TN's servers, and the feed does not need it.** Q10 on the
  second snapshot found 18 mails with it, with values like `w1|w4|w5|w2` and `localhost|w1|w2|w4`:
  TN host names in varying order, most likely the hosts that handled the mail. 17 of the 18 were
  to the volunteers (10) or reports (7). So it marks TN's path for that mail, not anything about
  the message.
- **`X-Trash-Nothing-Ip-Hash` is not needed.** The spam check skips IP checks for TN mail
  (section 2).

Q4: would the API path's rule, the account holding the TN user id (3.1), pick the account the email
path used? Read off the `X-Trash-Nothing-User-Id` header, over the two days.

| Case | Route | Messages | Senders |
|---|---|---|---|
| a. no TN user id header | 1 notify | 2 | 2 |
| a. no TN user id header | 4-5 volunteers | 11 | 1 |
| b. same account: it holds the TN id | 1 notify | 3,639 | 1,429 |
| b. same account: it holds the TN id | 2-3 direct | 1,781 | 1,351 |
| b. same account: it holds the TN id | 4-5 volunteers | 16 | 16 |
| c. no account holds the TN id, sender has none | any | 0 | |
| d. another account holds the TN id, sender has none | any | 0 | |
| e. sender holds a different TN id | 1 notify | 5 | 3 |
| e. sender holds a different TN id | 2-3 direct | 4 | 3 |

What this settles:

- **99.6% need no decision.** 5,436 of 5,458 messages went to the account that holds the sender's
  TN id, so the two paths agree on the sender.
- **Every account the email path used holds a TN id.** Cases c and d are empty: no message came
  from an account with no TN id at all.
- **9 messages, about 4 a day, went to an account holding a different TN id.** These look like
  accounts two TN members share, the case `tn:split-shared-account` exists for. They are the only
  messages the API path would handle differently from the email path.

Q4b: what the API path would do with those 9.

| Route | Account holding the header's TN id | Messages | Accounts used | Chats |
|---|---|---|---|---|
| 1 notify | none | 5 | 3 | 3 |
| 2-3 direct | none | 3 | 2 | 2 |
| 2-3 direct | exists, not in the chat | 1 | 1 | 1 |

What this settles:

- **8 would be skipped.** For each, no account holds the TN id in the header, and the account the
  email path used holds another, so `TnMemberMatch` finds no account and 3.1 skips the message
  (`tn-sender-unknown`). This is the "no account at all" case, and it does occur: about 4 a day.
- **1 would go to the right account.** A first reply whose TN id is held by another account would
  be filed on that account, in a new chat with the poster, instead of on the shared one.
- **The skips come from at most 5 accounts.** Each is an account a second TN member uses but was
  never split out of, so their messages land on the first member's account. Splitting those
  accounts before cutover (`tn:split-shared-account`) gives the second member their own account,
  with their TN id, and their messages then go through. Q4c lists the accounts, from the TN ids
  alone. Run it on several snapshots, since each holds about two days.

Q4c, on the next snapshot (its rows run to the evening of 18 November), found 7 mismatched
messages on 6 accounts. The account ids are kept out of this plan, because it is committed and they
name members. By kind:

| Kind | Accounts | Messages | API path | What fixes it |
|---|---|---|---|---|
| No account holds the header's TN id | 3 | 4 | skips | Split each account: the second member gets an account of their own with their TN id |
| The member has an account, but replied in a chat on the shared one | 1 | 1 | skips (`tn-sender-not-in-chat`) | Split it: when the member already has an account, `tn:split-shared-account` moves their chats and the rest onto it |
| A first reply filed on the wrong account, the right one existing | 2 | 2 | files it on the right account | Nothing for the API path. The email path's address lookup chose the wrong account. Splitting only tidies the history |

What this adds:

- **The skips run at about 2 to 4 a day** across the two snapshots: 8 in Q4b's 49 hours, 5 in
  Q4c's two days.
- **Two kinds of mismatch need different handling.** A shared account that was never split makes
  the API path skip, and is fixed by splitting. An address sitting on the wrong account makes the
  email path misfile, and the API path files those correctly by itself.
- **The pre-cutover split list is short**: 4 accounts on this snapshot. Repeat Q4c on a few more
  snapshots and split what they list, on production.
- **11 mails to the volunteers came from one account with no TN user id.** Q11 shows who.

Q11, on the second snapshot: the TN mail with no TN user id header.

| Route | From | Account holds a TN id | TN's secret | Mails | Communities |
|---|---|---|---|---|---|
| 4-5 volunteers | `noreply@trashnothing.com` | yes | yes | 10 | 10 |
| 1 notify | a member address | yes | no | 1 | |

What this settles:

- **These are TN's own notices, not members' messages.** All 10 come from
  `noreply@trashnothing.com`, carry TN's secret and its server-list header, and went to 10
  communities' volunteers, one mail each. This is row 9 of section 1, about 5 a day.
- **The email path files them under an account with a TN user id.** `handleVolunteersMessage`
  looks the sender up by address, and the account holding `noreply@trashnothing.com` also holds a
  TN user id. So moderators see TN's notices as messages from that account.

Q12: the accounts holding a TN address that is not a member's.

- **One account holds `noreply@trashnothing.com`,** as its preferred address, added on 28 December
  2020. It holds a TN user id, has no member TN address (`@user.trashnothing.com`), and is not
  deleted.
- **It is TN's own account on Freegle (Q12b).** It was made in the same second its address was
  added, which is its only address. Its name says Trash Nothing, and its role is an ordinary `User`.
  It has 9 memberships, 34 User2Mod chats and no User2User chats, and has sent 23,818 chat messages
  since June 2021, about 12 a day on average. Its `messages` rows are only the 10 notices in Q11's
  window, so it has no posts. `addEmailToUser` did not put the address there, so Q13 is not needed.
- **So moderators see TN's notices as coming from Trash Nothing,** which is right, and there is
  nothing to fix. The one open question is whose TN user id it holds. No member's mail in Q4c named
  it as the holder of their id. If it is TN's own system id, notices in the feed would carry that id
  and file on this account under the ordinary rule (3.1).
- **The other six rows are members' own addresses mistyped with a TN domain,** such as a personal
  address with `user.trashnothing.com` run onto the end. They are not TN's. The first version of
  Q12 printed them; it now prints only known system names. They are left out of this plan.
- **They stay on email for now.** The gate matches only mail From `user.trashnothing.com` (4.3), so
  these keep routing as today. Whether the feed carries them, and as which sender, is a question
  for Andrew (7.1) and a decision (section 8).

**A snapshot keeps about two days of chat mail.** `purge:messages` (daily at 02:30) runs
`PurgeService::purgeStrandedMessages`. That deletes a `messages` row more than two days old that
has no `messages_groups` row, is not a `refmsgid` and is not a draft. It does not count
`chat_messages_byemail` as a reference, so it takes every chat email's raw row, and the link row
goes with it (cascade). Q7 shows the cut: the first day in the snapshot starts at about 02:30, two
days before the purge that ran shortly before the backup. Two consequences:

- For this plan, the database only answers for about the last two days of each snapshot. For a
  longer sample, run the queries on several of the seven LVM snapshots. For 30-day volumes, use
  Loki.
- Separately, moderators lose "View original email" in chat review after two days, for all email
  chat, not only TN. That may not be intended; raise it on its own.

### Measure before building, on yesterday

The yesterday server restores production's database and its Loki every night, so most of what
this plan needs to know can be answered before TN builds anything. It has two sources, and they
see different mail.

**The database** has, for about two days, every TN chat mail that became a chat message. The raw
mail is kept in `messages` and linked through `chat_messages_byemail`, with the address it was
sent to, its From and its headers. The queries are in
[2026-11-12-tn-api-chat-ingestion-queries.sql](2026-11-12-tn-api-chat-ingestion-queries.sql):

| Query | Answers |
|---|---|
| Q1 | Volume per row of the table above, by chat type and message type |
| Q2 | Which headers TN chat mail carries: the secret, the TN user id, `X-FD-MsgId`, any per-message TN id. Also how many are automated, carry images, or carry HTML |
| Q3 | The shape of TN's Message-IDs, which is the parity join key (5.2) |
| Q4 | How often the API path's identity rule (3.1) would pick a different account from the email path's, or none |
| Q4b | For Q4's mismatches: whether the API path would file each on the account holding the TN id, or skip it |
| Q4c | The accounts behind those mismatches, by id, for a person to split before cutover |
| Q5 | How many TN chat messages carry images. Not needed: Q2 found no image parts |
| Q6 | Whether `chats:process-incoming` overwrites the review flags set on arrival (it no longer does; end of section 2) |
| Q7 | Messages a day and in the busiest hour, and how far back the snapshot's chat mail really goes |
| Q8 | Whether one message to the volunteers goes to several communities (none does) |
| Q9 | What TN puts in the decoded text: the automated note, photo links, TN links and signatures, quoted history, and how much the email path strips (clean text; photos as links) |
| Q10 | Whether `X-Trash-Nothing-Log-Id` is unique to one mail (it is), and what `X-Trash-Nothing-Meta` holds, by route (TN host names) |
| Q11 | Who sends the TN mail that has no TN user id header: the From domain, and TN's secret and meta headers |
| Q12 | Which accounts hold a TN address that is not a member's, such as `noreply@trashnothing.com`, and since when |
| Q12b | What the account holding `noreply@trashnothing.com` looks like: when it was made, its memberships, posts and chats (TN's own account) |
| Q13 | The envelope-from on TN chat mail, which `addEmailToUser` attaches to an account. Not needed after Q12b |

**Loki** has the mail the email path dropped, which left no rows. Each inbound email has one routed
entry (`source="incoming_mail"`, `type="routed"`, written by `IncomingMailController` after
`route()`) with `envelope_to`, `from_address`, `routing_outcome` and `routing_reason`. Count TN mail
by row and by outcome, and the drops by reason, with something like:

```logql
sum by (subtype) (count_over_time({app="freegle",source="incoming_mail",type="routed"}
  |= "trashnothing.com" |~ "\"envelope_to\":\"notify-" [1d]))
```

Repeat it with each of these filters in place of the notify one:

- Rows 2-3: `|~ "\"envelope_to\":\"[^\"]*-[0-9]+@users\\." !~ "\"envelope_to\":\"(notify|replyto|bounce|readreceipt|handover|digestoff|eventsoff|newslettersoff|relevantoff|volunteeringoff|notificationmailsoff|unsubscribe)-"`.
  The exclusion is needed because notify addresses and the command addresses `route()` handles
  first end in digits too. Without it, local Loki counted `digestoff-` and `bounce-` mail as
  direct mail.
- Rows 4-5: `|= "-volunteers@"`.
- Row 7: `|= "replyto-"`.
- Row 8: `|= "noreply@"`. Only Loki can show these, because they made no rows.

These filters have been run against the local Loki. The line is the entry's message JSON with no
space after the colons, and `| json` exposes `routing_reason` for `sum by`. Production TN mail
was not available to test them on, so check a few raw lines on yesterday first. Run a day at a
time, at the snapshot's time rather than now. A day's `ToUser` plus `ToVolunteers` count should
match Q7 for the same day.

Q2 can only test for header names we already know. The header scan lists every `X-` header name on
TN chat mail, using the parser the email path uses. For TN's own headers it also prints the shape
of each value, such as `digits(8)` or `hex(32)`, to show which could be an id. It never prints a
value, and it skips the secret. Run it from `/var/www/FreegleDocker` on yesterday; the one-off
container skips the batch entrypoint (5.7):

```bash
cd /var/www/FreegleDocker && docker compose run --rm --no-deps -T --entrypoint php batch artisan tinker --execute='
$until = DB::table("messages")->max("arrival");
$parser = app(\App\Services\Mail\Incoming\MailParserService::class);
$names = []; $shapes = []; $mails = 0;
DB::table("messages as m")->join("chat_messages_byemail as cme", "cme.msgid", "=", "m.id")
    ->where("m.arrival", ">=", \Carbon\Carbon::parse($until)->subDays(3))
    ->where("m.fromaddr", "like", "%trashnothing.com")
    ->whereRaw("LENGTH(m.message) > 0")
    ->select("m.id", "m.message", "m.envelopefrom", "m.envelopeto")
    ->orderBy("m.id")
    ->chunk(500, function ($rows) use ($parser, &$names, &$shapes, &$mails) {
        foreach ($rows as $r) {
            $mails++;
            $p = $parser->parse($r->message, (string) $r->envelopefrom, (string) $r->envelopeto);
            foreach ($p->getHeaders() as $name => $value) {
                if (!str_starts_with($name, "x-")) continue;
                $names[$name] = ($names[$name] ?? 0) + 1;
                if ($name === "x-trash-nothing-secret") continue;
                if (str_starts_with($name, "x-trash-nothing-") || str_starts_with($name, "x-fd-")) {
                    $v = trim((string) $value);
                    $shape = ctype_digit($v) ? "digits(" . strlen($v) . ")" : (preg_match("/^[0-9a-f]+$/i", $v) ? "hex(" . strlen($v) . ")" : (preg_match("/^[0-9a-f-]{36}$/i", $v) ? "uuid" : "other(" . strlen($v) . ")"));
                    $shapes["$name $shape"] = ($shapes["$name $shape"] ?? 0) + 1;
                }
            }
        }
    });
arsort($names); ksort($shapes);
echo "TN chat mails scanned: $mails\n\nX- headers (mails carrying each):\n";
foreach ($names as $n => $c) echo str_pad($c, 7, " ", STR_PAD_LEFT), "  $n\n";
echo "\nTN header value shapes (no values shown):\n";
foreach ($shapes as $s => $c) echo str_pad($c, 7, " ", STR_PAD_LEFT), "  $s\n";
'
```

The parsing and value shapes were tested locally on the TN fixture email in a one-off container of
the same form. That doesn't show whether yesterday's `replicas: 0` stops `docker compose run`.

The answers decide:

- which rows the feed must carry;
- which questions in 7.2 we can answer ourselves;
- the baseline the parity job is judged against.

Every drop reason Loki shows needs an equivalent on the API path, or a stated reason why not.
5.7 says how to run all of this safely.

---

## 2. What the email path does, and what the API path does instead

Each item below is either **mirrored** (the API path does the same), **replaced** (the API path
does it by a different rule, deliberately), or **not applicable** (no email, so nothing to do).

### Shared by every row: `createChatMessageFromEmail` (:2122) and `addPhotosToChat` (:2357)

| Email path | API path |
|---|---|
| Body is the text part, or the HTML part converted to text | Not applicable: TN sends text |
| Subject put in front of the body when a direct message is not tied to a post (row 3) | Mirrored, from a `subject` field |
| `StripQuotedService` strips quoted history and signatures, including TN's own ("Sent from the trash nothing! Mobile App", "Sent from my account on trashnothing.com", the automated-response note). Skipped for TN reports | Ask TN for the text part it sends today. Q9 found it carries no quoted history or TN signature, and the email path keeps nearly all of it. Run the same stripping anyway, so anything TN adds is treated alike, and row 6 reads the same |
| Spam check at ingestion: `SpamCheckService::checkMessage(forChatReply: true)`. For TN mail the IP and country checks are already skipped (`isFromTrashNothing()`), so what runs is content only: our domain in the From name, mention of a known spammer, spam and review keywords on the body | Mirrored on the display name and text |
| `checkReview($body)`, then the "last message in this chat is held, so hold this one" rule, then `mapReportReason` | Mirrored |
| `ChatMessage::create` with `platform = 0`, `processingrequired = 1`, `date = now()` | Mirrored. `platform = 0` matters: `ChatProcessService::updateSenderRoster` treats it as "came by email" and marks the message seen and mailed for its sender. `date` must be the time of ingestion, not TN's date (see 3.5) |
| `spamscore` from SpamAssassin over the raw mail (row 2-3 only) | Not applicable, left null. It is stored, not acted on |
| `storeChatEmailMessage` (:2277) keeps the raw mail in `messages` and links it in `chat_messages_byemail`, which is what ModTools chat review shows as the original email (`cme.msgid` in `chat/chatmessage.go`). Only for two days: `purge:messages` then deletes both (section 1, Results so far) | Not applicable. The message has no original email, like a message written in the app |
| Each image attachment becomes its own `Image` message: uploaded to tusd, a `chat_images` row, the Freegle logo hashes suppressed, held for review as `SameImage` when the hash was used more than 20 times in 24 hours | Not needed for parity: TN chat mail carries no image attachments (Q2). Built only if the feed carries photos (7.1), then from their URLs, taking the largest size as `GroupPostIngestionService::bestPhotoUrl()` does |
| `users.lastaccess` set to now | Mirrored. `UserManagementService::forgetInactiveUsers` reads it (for accounts with no memberships and no recent logs), so leaving it stale would change who that sweep takes |
| `trackEmailReply` marks our last chat notification to this member as replied, `replied_via = 'email'` | Mirrored with `replied_via = 'tn_api'` (a `varchar(50)`) |
| `addEmailToUser($email->envelopeFrom)` attaches the sending address to the account | **Replaced: not done.** On row 1 the account comes from the notify address and is never looked up, so this attaches whatever address sent the mail to whichever account the address names. That is the address-decides-identity behaviour `.claude/rules/conventions.md` says the replacement must not repeat |
| One routed Loki entry per mail, written by the caller | Mirrored: one entry per feed record, same file and schema, `source="tn_api"` (see 4.4) |

### Per row

**Row 1, reply in a chat** (`handleChatNotificationReply`)

- Misdirected read receipts, bounces and auto-replies are dropped. Not applicable, except that TN
  should flag anything automated (row 6).
- The chat is found by id, following `chat_room_redirects` for merged rooms. **Mirrored**, from
  `fd_chat_id`.
- The message gets no `refmsgid`, although TN names the post in `X-FD-MsgId` on 78% of these
  replies (Q2). **Mirrored.**
- A chat whose last message is more than 84 days old (`STALE_CHAT_DAYS`) drops a reply from an
  address not belonging to a chat member. **Replaced**: the sender is decided by TN user id, and
  must be a member of the chat.
- The sender must be in the chat (the two users of a User2User chat, the roster for others).
  **Mirrored.**
- `chatIsClosedToModerator` refuses a moderator writing into a User2Mod chat closed to them. Never
  fires for a TN sender (TN accounts cannot be moderators), but mirror it so the rule lives in one
  shape on both paths.

**Rows 2-3, direct message** (`handleDirectMail`)

- The recipient comes from the uid in the address; the sender by looking up the From address.
  **Replaced**: the sender by TN user id (3.1), the recipient from `fd_msgid` → `messages.fromuser`
  for a post reply and from `fd_recipient_user_id` otherwise.
- Mail from `noreply@` and mail to oneself are dropped. Self is mirrored; noreply is not applicable.
- `getOrCreateUserChat` (:3902) finds the User2User room in either user order, or creates it with
  both roster rows. **Mirrored.**
- The post comes from `X-FD-MsgId`, else from fuzzy subject matching. **Replaced**: from `fd_msgid`
  only. Keep the subject match only if the parity run shows TN cannot always name the post.
- `Interested` with `refmsgid` when the post is known, `Default` otherwise; the rippling hold when
  the post is known. **Mirrored.**
- No join to the group. TN joins its members to a community before they can reply, and a reply
  never makes a placement ordinary (conventions, "A replier is already a member"). **Mirrored**: the
  API path must not join anyone either.

**Rows 4-5, to the volunteers** (`handleVolunteersMessage`)

- Unknown community, unknown sender, deleted sender: dropped. **Mirrored**, with the community from
  `fd_group_id` and the sender by TN user id.
- Spam detection flags for review and never rejects, because members report spam to volunteers.
  This handler runs `checkMessage` **without** `forChatReply`, so the subject checks (subject reuse,
  bulk volunteer mail, greeting spam) apply too, plus SpamAssassin. **Mirrored** except SpamAssassin.
- `ChatRoom::getOrCreateUser2Mod`. **Mirrored.**
- Quoted text kept for a TN report. **Mirrored**: a report carries its transcript in the text, or in
  a structured field we render the same way.

**Row 7, digest reply-to** (`handleReplyToAddress`) also drops replies to posts more than 42 days
old and to posts on closed communities (and mails the sender about it), and stops the poster being
mailed when the post already has an outcome. `handleDirectMail` does none of this. Mirror the
handler each message would have reached; do not quietly give row 2 row 7's checks. Applying them to
every post reply is a sensible improvement to make on both paths later, not during the switch.

### Downstream, shared, not touched

`chats:process-incoming` (`ChatProcessService`) runs on every chat message whatever its source:
the spammer check, banned-in-common, `chatmodstatus`, block keywords, the review chain, the sender's
roster, the push task and `latestmessage`. Then `ChatNotificationService` mails the other side. The
API path writes rows of the same shape and leaves the rest to these.

**Review flags set on arrival survive processing.** Until 12 November,
`ChatProcessService::processMessage` wrote `reviewrequired` and `reportreason` from its own
decision, starting from 0. So a hold the email path set on arrival (spam check, `checkReview`, the
"last held" rule) was cleared a minute later. Edward fixed that in c5f270ece5: a message that arrives
held stays held with its reason, and processing can add a hold but never clear one.

Q6 confirms the fix in the data, on the 19 November snapshot. 3 processed email-path rows still
carry "Known spam keyword", a reason only the email path writes on arrival. The API path mirrors the
arrival holds, and processing then treats both paths' rows alike. This also lets the parity job
compare holds from the stored rows (5.3).

---

## 3. Rules the API path follows that the email path does not

These are deliberate. Each must be documented in [trashnothing.md](../docs/developers/reference/trashnothing.md)
and explained by the parity job rather than counted as a mismatch.

### 3.1 The sender is decided by TN user id

`.claude/rules/conventions.md`: only `users.tnuserid` says who a TN member is, the email
integration breaks this deliberately, and "its replacement goes by TN user id". So the API path
resolves the sender with `TnMemberMatch::find($fdUserId, $tnUserId)->account()`, the rule ratings
and user changes already share: the account holding the TN id; else the account TN names, unless
that holds a different TN id.

- **Row 1:** the account found must be the one the chat is for. If it is not (TN's id is on a
  different account from the one the notify address named), skip with `tn-sender-not-in-chat` and
  report it once a day to Sentry, like a skipped removal. Never write the message as the named
  account. Q4 found 5 such replies in two days, from 3 accounts. In all 5, Q4b found that no
  account held the TN id, so they fall under the next case.
- **No account at all:** skip with `tn-sender-unknown`, noted for the coverage check. Do not
  provision a new user as the posts path does. A replier has always been joined through the partner
  API first, which stamps their TN id, so an unknown id means an account we know only by address;
  making a second account would split their chats from them. Q4b found 8 in two days, all from
  accounts that hold another TN member's id (section 1, Results so far).

The parity job reports every case where the email path's account and the API path's differ. That
list is useful in itself: each entry is a message the email path filed on an account that does not
hold the sender's TN id. Q4 in the yesterday queries measures this now, from the TN user id header
on the mail. It shows how often each case occurs before anything is built. The header carries no
secret on chat mail (Q2), so it is used here only to measure.

### 3.2 The chat and the post come from ids

`fd_chat_id`, `fd_msgid`, `fd_recipient_user_id` and `fd_group_id` replace parsing addresses and
headers, and fuzzy subject matching goes (row 3 keeps the subject in the body when there is no post).
Q1 found one row 3 message in about two days against 1,788 that named a post, so dropping the
match costs almost nothing.

### 3.3 No address is attached to the account

See the `addEmailToUser` row in section 2.

### 3.4 Idempotent on TN's message id

The sync re-reads overlapping windows (10s overlap, and whole windows while a feed is held), so the
same record arrives more than once. A new table, `chat_messages_tn`:

| column | |
|---|---|
| `id` | surrogate key (conventions: every table gets one) |
| `chatmsgid` | FK to `chat_messages.id`, cascade delete: the message written for the record |
| `tnmessageid` | TN's id for the message, **unique**. Ideally the `X-Trash-Nothing-Log-Id` of the email TN sends for it, so the coverage check can join on it (5.2, 5.4) |
| `tndate` | TN's date for the message |
| `created` | |

If TN's ids cannot be its log ids, add a nullable, indexed `tnlogid` column, filled from the
feed's `email_log_id` while TN still emails, and join on that instead.

A new table rather than a column on `chat_messages`, which is very large and would need a
node-by-node ALTER. The message and this row are written in one transaction, and the unique key
decides a race. If the feed later carries photos (7.1), their `Image` messages join the same
transaction and `chatmsgid` names the first message written. The uploads to tusd then happen before
the transaction, and a lost race orphans those blobs, which is acceptable.

### 3.5 `date` is the time of ingestion

`ChatNotificationService::getUnmailedMessages` admits messages by `chat_messages.date` inside a
four-hour look-back (`.claude/rules/laravel-batch-traps.md`, "Anything that holds a chat message and
releases it later..."). If the sync falls behind and we wrote TN's date, a message read more than
four hours late would never be mailed to the Freegle member, with no error. So `date = now()`, as
on the email path, and TN's date goes in `chat_messages_tn.tndate`.

### 3.6 Order within a chat

The review chain (`ChatProcessService`, "the previous message is held") and the chat display both
go by `chat_messages.id`. TN documents no order for its other feeds, so sort each window's records
by `(date, id)` before writing. A message read late, after a later one in the same chat, still lands
after it; that is the same as the email path when mail arrives out of order.

---

## 4. Architecture

```
TNSyncCommand (every 10s, PreventsOverlapping)
  ├── RatingsSyncer
  ├── UserChangesSyncer
  ├── PostSyncer          → GroupPostIngestionService
  └── ChatMessageSyncer   → ChatMessageIngestionService      (new)
```

### 4.1 `ChatMessageSyncer` (`app/Services/TrashNothing/Sync/`)

Built like `UserChangesSyncer`, which reads the same partner API:

- `GET {api_base_url}/chat-messages?key=…&page=…&per_page=100&date_min=…&date_max=…`, with the
  partner key `FREEGLE_TN_API_KEY` (not the developer key the posts sync uses).
- `ReadsTnFeedByDay` for windows longer than a day; the shared `TrashNothingRateLimiter` (750ms
  between requests, about a sixth of TN's 2 a second). A fourth request adds under a second to each
  run.
- `fetchFailed()` joins the existing stored-date hold (`FEED_HOLD_*` in `TNSyncCommand`): a page that
  cannot be read holds the date for up to an hour, then gives up with a Sentry alert. Re-reading is
  safe because of 3.4.
- `--local-testing` reads `tests/fixtures/tn_sync/chat_messages_page_{n}.json`; `--dry-run` writes
  nothing and traces each would-be write as `TN-SYNC-TRACE [CHAT-WRITE]`, as the other syncers do.
- A response is never logged whole: the records are private conversations.

### 4.2 `ChatMessageIngestionService` (`app/Services/TrashNothing/Ingestion/`)

A TN-only reimplementation of the four handlers in section 2 plus `createChatMessageFromEmail`,
working on a DTO instead of `ParsedEmail`. `addPhotosToChat` is left out of the first version,
because TN chat mail carries no images (Q2); it is added only if the feed carries photos (7.1). Same structure as
`GroupPostIngestionService`: it decides the outcome and never touches Loki; it keeps a routing
context with the email path's `dropped()` / `getLastRoutingContext()` semantics; drop reasons use the
email path's exact strings where there is an equivalent (`Reply to non-existent chat`,
`User not part of chat`, `Volunteers message to unknown group`, …) and their own documented strings
otherwise.

One structural change from the posts service: split it into

- `plan(TnChatMessage $record): ChatIngestPlan`, read-only and deterministic: chat, sender,
  recipient, chat type, message type, `refmsgid`, final text, review decision and reason, or a drop
  with its reason; and
- `apply(ChatIngestPlan $plan)`, which writes.

The parity job (section 5) then calls `plan()` against production without any write, which is safer
than the posts approach of running both paths in rolled-back transactions, and does not need the
email path replayed at all.

### 4.3 Flag and gate

- **`FREEGLE_TN_INGEST_CHAT_VIA_API`**, `freegle.trashnothing.ingest_chat_via_api`, default off. Its
  own flag, not the posts one: the two cut over at different times and roll back independently. Like
  the posts flag, it is one switch for the whole cutover, because neither half is safe alone (API on
  with email still routing writes every message twice; email off with the API off loses them). On,
  it:
  - lets `ChatMessageSyncer` write;
  - makes `TnEmailRoutingGate` skip routing TN chat mail (still archived, outcome `SkippedTnApi`);
  - schedules `tn:verify-chat-coverage` (5.4) and makes the `tn:sync (chat)` scheduled-outcome check
    live.
- **The gate predicate must stay narrow.** A new `isTrashNothingChatMessage()` beside
  `isTrashNothingGroupPost()` matches mail that meets both conditions:
  - It comes from a TN sender: From at `user.trashnothing.com`, envelope-from at TN's domain as
    `ParsedEmail::isFromTrashNothing()` reads it.
  - It goes to an address `route()` would hand to one of the handlers the feed covers. This mirrors
    `route()`'s phase order, as `claimedByAnEarlierPhase()` does.

  It must not take what those handlers drop or treat differently before writing anything: bounces,
  read receipts and auto-replies. Gate each row of section 1 separately, so a kind of message the
  feed does not carry keeps arriving by email. Skipping too widely loses real members' mail with no
  error.

  The gate cannot use TN's secret, which Q2 found on none of the 5,441 replies. The sender's domain
  is enough here, because of which way a mistake goes:
  - A Freegle member's mail never comes from a TN address, so it cannot be skipped by mistake.
  - A forged mail from a TN address would be skipped, and so not delivered. That is safer than
    today, when a reply to a notify address is written as whoever the address names, without
    checking the sender unless the chat is more than 84 days old.

  The parity job's layer 1 then shows that every mail the predicate matches has a feed record.

  TN's own notices to volunteers (row 9) come From `noreply@trashnothing.com`, not from
  `user.trashnothing.com`, so the predicate does not match them and they keep routing by email.
  That is intended until the feed carries them (decision 7).
- **Drift guard.** Add the chat handlers, `createChatMessageFromEmail` and `findRefMessage` to
  `EmailPathMirrorDriftTest`, so an edit to the email side forces a decision about the API side. Add
  `addPhotosToChat` too if the API path comes to handle photos.

### 4.4 Loki

`LokiService::logIngestedChatMessage()`, built on the same private `buildRoutedMessage()` /
`buildRoutedEntry()` as `logIncomingEmail()`: file `incoming_mail.log`, labels identical except
`source="tn_api"`, subtype from `RoutingResult` (`ToUser`, `ToVolunteers`, `Dropped`, `Failure`),
`routing_reason` byte-identical where an equivalent exists, plus `tn_message_id` on the API side. One
entry per record, emitted by `ChatMessageSyncer` after `apply()` returns, exactly once per record as
`PostSyncer::processPost()` does. Run-level events (`failed`, `completed`) go to the batch stream.

---

## 5. Comparing the two paths

### 5.1 How this differs from posts

The posts parity check replayed TN's post-log CSV through the email path and ran both paths in
rolled-back transactions on a database with the groups. Chat cannot be replayed sensibly: a reply
needs the real chat room, the real users and the real state of the chat. It does not need to be.
Until cutover the email path is live on production and has already written the real result. So the
parity job compares the API path's `plan()` with what the email path actually wrote, read-only, on
production.

Because it reads production chats, it runs only on batch-prod or on yesterday's copy (5.6). Either
way it prints ids, outcomes and hashes of the text, never the text itself.

### 5.2 The correlation key

For posts it was `tnpostid`. For chat it is TN's log id. Every TN chat mail carries
`X-Trash-Nothing-Log-Id`, a UUID, and no two mails share one (the header scan and Q10, section 1).
So ask TN for one thing (section 7):

- **The feed's `id` is the log id of the email TN sends for the same message.** It stays the same
  kind of id once TN stops emailing.

That makes three joins exact, with no change to the frozen email path and nothing new on TN's mail:
- **the parity job** reads the log id from each email's headers and joins it to the record's `id`.
  The archive has the raw mail; on yesterday, so does `messages.message`.
- **the coverage check after cutover** joins an archived email's log id to
  `chat_messages_tn.tnmessageid` (5.4);
- **a miss** is looked up at TN by that id, with `GET /fd/api/chat-messages/{id}` (7.1).

If TN's ids have to differ from its log ids, there are fallbacks, in order:
- the record carries the log id as a separate field, `email_log_id`, stored in
  `chat_messages_tn.tnlogid` while TN still emails (3.4);
- the record carries the email's Message-ID instead, which the email path already stores in
  `messages.messageid` (Q3);
- a fuzzy join on (chat or sender/recipient pair, normalised text hash, time within ten minutes).
  That works but makes every layer noisier.

Settle this before building.

### 5.3 `tn:chat-parity-check`

Hourly on batch-prod during the parallel run, and on demand with `--date-min` / `--date-max`. The
window runs about an hour behind real time, so both paths and `chats:process-incoming` have
settled, and well inside the 48-hour archive retention.

- **Email side**: the incoming-mail archive for the window (through `IncomingArchiveReader`, as
  `ArchiveInventoryService` does), keeping the TN chat mail the gate predicate matches (called
  without the flag), with each email's `Message-ID`, its `routing_outcome`, and for those that made
  a message, the `chat_messages` row and its image messages.
- **API side**: the feed for the same window, each record through `plan()`.
- **State is read as it was when the message arrived.** By the time the job runs, the email path
  has written its own copy of the message, and maybe later ones. Without care, `plan()` would take
  that copy as "the last message in this chat" for the review chain, and judge staleness from a
  later `latestmessage`. So `plan()` takes an optional "as of" chat message id, the email copy's,
  and those reads look only at messages before it. A message the email path dropped has no copy,
  so the current state is used.

Layers, modelled on the posts check:

1. **Coverage (fails the run).** Every TN chat email has an API record. A miss is listed with its
   Message-ID and the email path's outcome, after asking TN about it by its log id (deleted,
   or sent to us by email only).
2. **Extras (reported).** API records with no email. Some are expected and each kind is named:
   row 8's misrouted replies, which the email path loses. Anything between two TN members fails the
   run, because it should never be in the feed.
3. **Outcome (fails the run).** Both paths made a message, or both dropped it for an equivalent
   reason. Differences that 3.1 explains are reported in their own section, not failed.
4. **Content (fails the run)**, where both made a message: chat id, sender, chat type, message
   type, `refmsgid`, normalised text, number of images and their hashes, and the hold set on arrival.
   The email side's hold is read from the stored row, since processing keeps it (section 2). A stored
   hold whose reason only processing writes (`Last`, `Spam`, or one of its content checks) is
   processing's own, and is left out of the comparison. Text needs little normalising: Q9 found the
   email path keeps nearly all of TN's text part, which has no quoted history or signature. Compare
   after trimming whitespace and line endings, and tune only if the parity run shows a need, as the
   posts work did with `OFFERED:` and `OFFER:`. "The email had extra text" is reported apart from
   "the API is missing text", which fails.
5. **Loki (fails the run).** The two routed entries have the same labels apart from `source`, the
   same keys, and the same outcome and reason where layer 3 agreed.

The run ends with `logBatchJob('tn:chat-parity-check', …)` and a non-zero exit on any failing layer,
so a failure alerts through the scheduled-outcome checks.

**Cutover gate:** fourteen consecutive days with layers 1, 3, 4 and 5 clean, apart from documented
divergences, and every extra in layer 2 explained. At the rate Q1 and Q7 found, that is about
37,000 messages, but only about 90 from members to the volunteers and reports. TN's own notices
(row 9) stay on email and are not counted. Check that each of rows 4 and 5 has been seen clean
before cutting over.

### 5.4 After cutover: `tn:verify-chat-coverage`

Ask TN to keep emailing chat messages for a soak period after cutover. The gate skips routing them
but the archive keeps them, as for posts. The hourly check reads each archived TN chat email's
`X-Trash-Nothing-Log-Id` and expects a `chat_messages_tn` row with that `tnmessageid`. A miss is
looked up at TN by that id and classified. Backfilling a genuine miss goes through the same `plan()`/`apply()`, behind
the rails the posts verifier uses (a per-run cap that alerts instead of backfilling en masse, an age
guard, escalation when a backfilled message is still missing) and starts report-only.

### 5.5 Rollback

Turn the flag off and the email path routes TN chat mail again at once, which is why TN must still be
sending it. A message read by the API just before the switch and emailed just after could be
delivered twice; the email path cannot see `chat_messages_tn`. Accept that, and say so in the
runbook, rather than teach the frozen email path about it.

### 5.6 Rehearsing on yesterday

Run the same job on yesterday against a past day's snapshot: first, before anything runs on
batch-prod, and then daily through the parallel run. Yesterday has three advantages:

- Nothing it does can touch production.
- It can look further back. Each snapshot holds about two days of chat mail in `messages` (section
  1, Results so far), and there are seven snapshots, so together they cover about a week. Loki
  keeps 31 days. The archive keeps 48 hours.
- It can be run again on any of those snapshots.

What to run there:

- **Take the email side from the database, not the archive** (`--email-side=db`). TN chat mail
  comes from `messages` and `chat_messages_byemail` for the window, joined to the feed on the
  `X-Trash-Nothing-Log-Id` in `messages.message`. Drops come from yesterday's Loki and are compared
  as counts per reason, because a dropped mail left no row and so no log id behind. Choose windows that end before the snapshot was
  taken (`MAX(messages.arrival)`).
- **Read TN's feed for the same window.** Reading is safe; the feed only answers GETs. It does need
  the partner key on yesterday. Agree that with Edward first, and keep the key out of any PR.
- **`--apply`, on yesterday only.** Yesterday's clone is discarded at the next switch, so the API
  path can write its messages for real there. The job then compares written rows with the email
  path's rows, images and `chat_messages_tn` included, instead of comparing plans with rows.
  - The command refuses `--apply` unless `FREEGLE_TN_PARITY_ALLOW_WRITES` is set, and only a
    yesterday run sets it.
  - Before an `--apply` run, check that `TUS_UPLOADER` in the container is the local tusd. The
    yesterday override points the API and the dev sites at `uploads.ilovefreegle.org`, and an image
    upload there writes to production storage. TN chat mail carries no images today (Q2), so this
    only matters if the feed carries photos.
  - Running `chats:process-incoming` on the clone afterwards also compares the state after
    processing. It queues push tasks, but nothing on yesterday sends them while batch is off.
- **Test the gate predicate on real mail.** Run `isTrashNothingChatMessage()` and the existing
  `isTrashNothingGroupPost()` over every raw mail a snapshot holds: about two days of chat mail,
  and 30 days of group posts. The chat predicate must match exactly the TN chat mail Q1 counts, row
  by row, and nothing from anyone else. A false match is a member's mail the cutover would silently
  stop routing. Repeat on several snapshots.
- **Check query plans.** Run `EXPLAIN` on production-sized tables for each query the ingestion
  service and the parity job run: the "last message in the chat" read and the `chat_messages_tn`
  lookups, plus the `SameImage` count over `chat_images` if photos are added. Run the `chat_messages_tn` migration on the
  clone to time it.
- **Rehearse the cutover and the rollback.**
  1. In a one-off container on the clone, with the flag on, read a past day of the feed and write it.
  2. Run the coverage check over that day, joined on the Message-ID, with the raw mail in `messages`
     standing in for the archive. Confirm it finds every message.
  3. Turn the flag off and re-read the day. Confirm nothing is written twice.

### 5.7 Running things on yesterday safely

- **Use a one-off container.** The batch container is off on yesterday on purpose (`replicas: 0`):
  the scheduler must not run against the snapshot. Its entrypoint runs `artisan migrate` and then
  starts supervisor, so skip it, for example
  `docker compose run --rm --no-deps --entrypoint php batch artisan …`.
- **Run branch code from its own checkout.** The yesterday containers and scripts run from
  `/var/www/FreegleDocker`, and the VM is deployed by hand, so switching that checkout's branch
  changes yesterday for everyone using it.
- **Anchor windows on the snapshot.** `NOW()` there is today, not the snapshot, so use
  `MAX(messages.arrival)`.
- **Finish long jobs before anyone switches days.** Switching with `switch-backup.sh` discards
  anything written to the clone. That is what makes `--apply` safe there, and also why a long
  comparison must finish before a switch.
- **Print counts and ids, never message text.** The snapshot is production data, and these are
  private conversations.
- **Watch image uploads.** Mail goes to Mailhog, but images go to production storage unless
  `TUS_UPLOADER` says otherwise (5.6).

---

## 6. Phases

| Phase | What | Writes chat messages on production? |
|---|---|---|
| 0 | Run the section 1 queries and the header scan on yesterday. Agree the contract in section 7 with Andrew, cut down to what yesterday could not answer | No |
| 1 | PR: `ChatMessageSyncer` (read-only), the DTO, fixtures, `ChatMessageIngestionService::plan()`, `tn:chat-parity-check` with both email sides (archive and `db`), the migration for `chat_messages_tn`, docs. Rehearse on yesterday first (5.6), then deploy and run the parity job hourly on batch-prod | No |
| 2 | Parallel run: hourly on batch-prod, plus daily on yesterday over the previous day, with `--apply`. Fix what either finds, on the API side, and adjust the contract with TN if needed | No |
| 3 | PR: `apply()`, the flag, the gate, `logIngestedChatMessage`, `tn:verify-chat-coverage`, the drift test, docs. Ships with the flag off. Rehearse the cutover and the rollback on yesterday, and run the gate over 30 days of real mail there. Split the shared accounts Q4c lists, on production, so the identity rule rarely skips | No (flag off) |
| 4 | Cutover: turn the flag on. TN keeps emailing; the coverage check runs | Yes |
| 5 | After the soak, TN stops emailing chat messages. Later, outbound | Yes |

### Implementation status (19 November)

The code for phases 1 and 3 is on branch `tn-chat-api`, against the contract in section 7.1 as
proposed: TN has not built the endpoint, so it is tested on fixtures only. Both flags are off.

Built: `chat_messages_tn`; `TnChatMessage`; `ChatMessageIngestionService::plan()` and `apply()`;
`ChatMessageSyncer` in `tn:sync`; `TnEmailRoutingGate::trashNothingChatKind()` and the skip;
`LokiService::logIngestedChatMessage`; `tn:chat-parity-check` (archive and `db` sides, `--apply`);
`tn:verify-chat-coverage`; the `tn:sync (chat)` scheduled-outcome check; the drift test; docs.

Decided while building, for review:

- **A read-only flag of its own, `FREEGLE_TN_READ_CHAT_FEED`.** Reading the feed in `tn:sync` before
  TN serves it would ask for a missing endpoint every ten seconds. While only reading, an unreadable
  page does not hold the stored date (the email path still writes the messages); after the cutover
  it does.
- **Per-kind cutover (decision 3) is `FREEGLE_TN_INGEST_CHAT_KINDS`**, read by both the gate and the
  syncer.
- **`email_tracking.replied_via` is `enum('amp','website','email')`, not a varchar**, so `tn_api`
  needed a migration (appending an enum value, `ALGORITHM=INSTANT`).
- **The gate leaves every auto-reply to email**, as 4.3 says, although the email path drops a chat
  reply's auto-reply only when the chat had a message in the last five hours. A message that reads
  like an auto-reply, in an older chat, would be written by both paths. The parity run will show
  whether that happens.
- **Records not written are noted in `DeliberateSkips`** (`chat:{id}`), which also keeps a re-read
  window from logging them twice and tells the coverage check they were decided.
- **Not built yet:** parity layer 5 (comparing Loki entries), the `db` side's drop counts from Loki,
  and photos.

**Q13 settles the gate's envelope test.** Every TN member's chat mail on the second snapshot has the
member's own `@user.trashnothing.com` address as its envelope-from: 3,577 replies in a chat, 1,691
first and other replies, 11 to the volunteers. So `ParsedEmail::isFromTrashNothing()` is true for all
of it and the gate matches it. TN's 10 notices come from `noreply@trashnothing.com`, envelope and
From, and the gate leaves them alone because their From is not a member's address.

**Tests**, for each PR: the ingestion service per row of section 1 and per drop reason; the
syncer's paging, day windows, ordering, `fetchFailed()` and the stored-date hold; idempotency on a
re-read window; a test that feeds the same message through `route()` and through the API path and
compares the rows and the Loki entries (the chat version of `TnApiLokiParityTest`); the parity job
per layer, with fixtures as in `EmailApiParityTest`; and the gate, one test per phase of `route()`
it must not swallow. Run the Laravel and Go suites through the status container.

**Docs**, same PR as each change (`scripts/check-docs-freshness.mjs` enforces it, since
[trashnothing.md](../docs/developers/reference/trashnothing.md) covers `IncomingMailService.php` and
`app/Services/TrashNothing/**`): a "Chat messages via the API" section in trashnothing.md, its
moderator-visible effect (no "original email" in chat review for TN messages), and the note in
[tn-api-post-ingestion.md](tn-api-post-ingestion.md) section E that "the TN API exposes no chat
messages endpoint", which will no longer be true.

---

## 7. What we need from TN (Andrew)

### 7.1 The endpoint

`GET /fd/api/chat-messages`, partner key as `?key=`, `date_min`, `date_max` (a day apart at most is
fine, as the other feeds), `page`, `per_page` up to 100, response `{"chat_messages": [...]}`.

Each record:

| Field | Need | Why |
|---|---|---|
| `id` | required | TN's id for the message, unique and never reused. Ideally the `X-Trash-Nothing-Log-Id` of the email TN sends for the message, so a record and its email carry the same value (5.2). Idempotency (3.4) |
| `date` | required | When TN sent it to Freegle. **Must not change after the fact**: `/posts/all` filters on a `date` that moves when a post is edited or reposted, which pushed real posts out of every window (posts plan, section Q) |
| `tn_user_id` | required | The sender (3.1) |
| `fd_user_id` | if known | Our id for the sender, as on the user-changes feed |
| `kind` | required | Which of section 1's rows: `chat_reply`, `post_reply`, `user_message`, `volunteers`, `report`, and `tn_notice` (row 9) if the feed carries TN's own notices (decision 7) |
| `fd_chat_id` | for `chat_reply` | The chat, as in the notify address TN replied to |
| `fd_msgid` | for `post_reply`; for `chat_reply` where TN knows it | The Freegle post, as `X-FD-MsgId` today. TN sends that on 78% of chat replies too (Q2). We store it only on a post reply, as the email path does |
| `fd_recipient_user_id` | for `post_reply`, `user_message` | The Freegle member written to |
| `fd_group_id` | for `volunteers`, `report` | The community, by Freegle id rather than nameshort |
| `reported_fd_user_id`, `reported_fd_msgid` | for `report` | Who or what is reported, if TN has them |
| `subject` | where TN has one | Kept in the body for `user_message` with no post |
| `text` | required | The text part of the email TN sends today for the message, plain UTF-8. That includes "Possible collection times:" (on 90% of first replies) and any `trashnothing.com/pics/` photo links (Q9). For a report, the reason and the transcript |
| `display_name` | required | The name TN shows for the sender; the spam check reads it |
| `images` | later, optional | Not needed for parity. TN chat mail carries no attachments (Q2), and photos arrive as `trashnothing.com/pics/` links in the text, in about 1% of messages (Q9). If both paths later fetch them as attachments: each photo's sizes ordered smallest to largest, as on posts, with URLs that work without a login for at least a week |
| `automated` | required | True for a message TN wrote on the member's behalf (row 6). None in two days (Q9); ask whether TN still sends them |
| `email_log_id` | only if `id` is not the log id | The `X-Trash-Nothing-Log-Id` of the email TN sent for the same message, while TN still emails (5.2). If even that cannot be given, the email's Message-ID instead |

Behaviour:

- **The same set of messages TN emails us today, no more and no fewer.** Nothing between two TN
  members (conventions: those stay on TN).
- **One record per email.** Each record matches one email TN sends today, by its log id (5.2). Q8
  found no member message sent to several communities' volunteers, so this matters only if TN
  starts doing that.
- **TN's own notices to volunteers.** TN sends about 5 a day to communities' volunteers, From
  `noreply@trashnothing.com` (row 9, Q11). Freegle files them under TN's own account, which holds a
  TN user id (Q12b). Ask what the notices are, whether the feed should carry them, and whether
  that account's TN id is TN's own system id. If it is, the feed can carry the notices with that id
  as `tn_user_id`, and they file on that account under the ordinary rule. Until then they stay on
  email.
- Records in ascending `(date, id)` order, and the same record on every read of a window.
- How far back the feed goes. At least seven days, so an outage of our sync or theirs can be caught
  up; longer is better.
- `GET /fd/api/chat-messages/{id}` for a single message, for the coverage checks to classify a miss,
  as `GET /posts/{id}` is used for posts. A miss is an email with no record, so when `id` is the log
  id, the email itself names the message to look up.
- Whether TN members can edit or delete a chat message after sending, and if so what the feed shows.
  The email path never learns of either; the API path will do the same unless we decide otherwise.
- The existing kick to start `tn:sync` straight away when a chat message is sent, if TN would like
  lower latency than the ten-second schedule.
- Same rate limit as the other feeds (2 requests a second per key), shared across the four.

### 7.2 For testing and the switch

Q2, Q3 and the header scan have answered part of this from production mail. Every TN chat mail
carries TN's log id, and replies carry no secret.

- **Not needed: a new id header on TN's mail.** `X-Trash-Nothing-Log-Id` is already on every chat
  mail (5.2).
- **A chat counterpart of `fd-post-log.csv`, if TN has one.** A log of the chat mail TN sent, keyed
  by the log id, would list every message TN sent us, including any we dropped or never received.
  Layer 1 of the parity job (5.3) would then not depend on our archive or Loki.
- **Not needed: the secret on chat mail.** Q2 found it on none of the 5,441 replies, and the gate
  does without it (4.3). The TN user id header is on all but two replies, which is enough for
  measuring.
- **Keep emailing chat messages** through the parallel run and the soak after cutover, until we say
  stop. Rollback (5.5) depends on it.
- The OpenAPI description of the endpoint, and a sample response covering every `kind` and an
  automated message. These become the fixtures.
- **Test accounts.** Two TN test members joined to a Freegle test community, so each row of section 1
  can be driven end to end and seen arrive both by email and in the feed: reply to a test post, reply
  in the chat, write to the volunteers, report a member, send a photo (it arrives as a link, Q9),
  and trigger an automated response if TN still sends them.
  Say whether TN has a staging environment that can point at a Freegle dev server; if not, this
  happens on production with test accounts.
- What TN does today when Freegle drops a message (the chat no longer exists, the member is not in
  it). The email path drops silently, so TN probably does nothing; with an API there is no bounce
  either. Whether TN wants to hear about refusals can wait for the outbound work.
- The feed carries private conversations, as the email does. Confirm nothing about the agreement
  between us needs to change for an API carrying the same data.

---

## 8. Decisions needed

1. **A sender the TN id puts on another account, or on none (3.1).** Recommended: skip and report.
   The alternative for an unknown sender, provisioning a new user as the posts path does, would
   split a member known only by address from their chats. Q4 and Q4b show how little is at stake:
   - 5,436 of 5,458 messages in two days need no decision;
   - 8, about 4 a day, would be skipped: their TN id is held by no account, and they arrive today on
     an account holding another TN member's id;
   - 1 would be filed on the account holding its TN id instead of the shared one.

   The skips come from a handful of shared accounts: 4 on the second snapshot Q4c ran on. So split
   those before cutover (`tn:split-shared-account`, from Q4c's list). After cutover each skip is
   reported, so a person can split any new one the same way. First replies the email path filed on
   the wrong account need nothing: the API path files them on the right one.
2. **Separate cutover flag (4.3).** Recommended: separate from the posts flag.
3. **Per-row gating (4.3).** Recommended: gate each row of section 1 on its own, so the cutover can
   start with the rows the feed carries and the rest stay on email.
4. **Automated responses (row 6).** Recommended: keep them, with TN's note stripped, as the email
   path does. Q2 and Q9 found none in two days, so this may never arise.
5. **Checks that direct mail lacks** (post more than 42 days old, closed community, post with an
   outcome). Recommended: mirror direct mail now; add them to both paths together later.
6. **Resolved: the review flags set on arrival.** `ChatProcessService` used to clear them; Edward
   fixed that on 12 November (c5f270ece5), and Q6 confirms it (end of section 2).
7. **TN's own notices to volunteers (row 9).** Recommended: leave them on email for the first
   cutover, which the gate already does. Bring them into the feed once Andrew has said what they
   are and confirmed TN's account holds TN's own id. They then need their own `kind` (`tn_notice`)
   and nothing else: they file on TN's existing account under the ordinary rule (3.1).

## 9. Found along the way, outside this work

Each of these turned up while measuring, affects more than TN chat, and needs its own decision:

- **Raw chat mail lasts two days** (section 1, Results so far). `purgeStrandedMessages` does not
  count `chat_messages_byemail` as a reference, so moderators lose "View original email" in chat
  review after two days, for all email chat.
- **TN's own account on Freegle holds a TN user id** (Q12, Q12b). It is the account TN's notices to
  volunteers come from, named for Trash Nothing, and that is as it should be. Confirm with Andrew
  that the id is TN's own system id. If it is a member's, that member's partner calls and API posts
  would resolve to this account.
