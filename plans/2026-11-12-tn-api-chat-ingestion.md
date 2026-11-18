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
| 6 | TN sent an automated response on the poster's behalf | Arrives as 1 or 2 | As 1 or 2 | The message is kept; `StripQuotedService` removes TN's `[Note: This is an automated response from trashnothing.com on behalf of the post author]` |
| 7 | Replied to a digest | `replyto-{msgid}-{uid}@…` | `handleReplyToAddress` (:1624) | Only the digest sets this Reply-To, and digests skip TN members, so expect almost none. Measure before deciding whether the feed needs it |
| 8 | Replied to our notification's From instead of its Reply-To | `noreply@ilovefreegle.org` | `handleDigestReply` | Nothing: the member gets the "we can't tell which post" auto-reply and the message is lost. #790 tried to recover these and was closed. The API fixes this by construction, because TN will name the chat |

Scale: [trashnothing.md](../docs/developers/reference/trashnothing.md) records 28,051 TN replies
to Freegle posts in the 30 days to 2026-11-03, about 900 a day for row 2 alone.

### Measure before building, on yesterday

The yesterday server restores production's database and its Loki every night, so most of what
this plan needs to know can be answered before TN builds anything. It has two sources, and they
see different mail.

**The database** has every TN chat mail that became a chat message. The raw mail is kept in
`messages` and linked through `chat_messages_byemail`, with the address it was sent to, its From
and, for 30 days, its headers. The queries are in
[2026-11-12-tn-api-chat-ingestion-queries.sql](2026-11-12-tn-api-chat-ingestion-queries.sql):

| Query | Answers |
|---|---|
| Q1 | Volume per row of the table above, by chat type and message type |
| Q2 | Which headers TN chat mail carries: the secret, the TN user id, `X-FD-MsgId`, any per-message TN id. Also how many are automated, carry images, or carry HTML |
| Q3 | The shape of TN's Message-IDs, which is the parity join key (5.2) |
| Q4 | How often the API path's identity rule (3.1) would pick a different account from the email path's, or none |
| Q5 | How many TN chat messages carry images |
| Q6 | Whether `chats:process-incoming` overwrites the review flags set on arrival (end of section 2) |
| Q7 | Messages a day |

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

Q2 can only test for header names we already know. To list every `X-` header name on TN chat mail,
run a few lines of tinker using the parser the email path uses. It prints counts only:

```php
$until = DB::table('messages')->max('arrival');
$parser = app(\App\Services\Mail\Incoming\MailParserService::class);
$tally = [];
DB::table('messages as m')->join('chat_messages_byemail as cme', 'cme.msgid', '=', 'm.id')
    ->where('m.arrival', '>=', \Carbon\Carbon::parse($until)->subDays(30))
    ->where('m.fromaddr', 'like', '%trashnothing.com')
    ->whereRaw('LENGTH(m.message) > 0')
    ->select('m.id', 'm.message', 'm.envelopefrom', 'm.envelopeto')
    ->orderBy('m.id')
    ->chunk(500, function ($rows) use ($parser, &$tally) {
        foreach ($rows as $r) {
            $parsed = $parser->parse($r->message, (string) $r->envelopefrom, (string) $r->envelopeto);
            foreach (array_keys($parsed->getHeaders()) as $name) {
                if (str_starts_with($name, 'x-')) {
                    $tally[$name] = ($tally[$name] ?? 0) + 1;
                }
            }
        }
    });
arsort($tally);
$tally;
```

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
| `StripQuotedService` strips quoted history and signatures, including TN's own ("Sent from the trash nothing! Mobile App", "Sent from my account on trashnothing.com", the automated-response note). Skipped for TN reports | Ask TN for the text as the member wrote it, without history or footer. Keep stripping TN's automated-response note, so row 6 reads the same |
| Spam check at ingestion: `SpamCheckService::checkMessage(forChatReply: true)`. For TN mail the IP and country checks are already skipped (`isFromTrashNothing()`), so what runs is content only: our domain in the From name, mention of a known spammer, spam and review keywords on the body | Mirrored on the display name and text |
| `checkReview($body)`, then the "last message in this chat is held, so hold this one" rule, then `mapReportReason` | Mirrored |
| `ChatMessage::create` with `platform = 0`, `processingrequired = 1`, `date = now()` | Mirrored. `platform = 0` matters: `ChatProcessService::updateSenderRoster` treats it as "came by email" and marks the message seen and mailed for its sender. `date` must be the time of ingestion, not TN's date (see 3.5) |
| `spamscore` from SpamAssassin over the raw mail (row 2-3 only) | Not applicable, left null. It is stored, not acted on |
| `storeChatEmailMessage` (:2277) keeps the raw mail in `messages` and links it in `chat_messages_byemail`, which is what ModTools chat review shows as the original email (`cme.msgid` in `chat/chatmessage.go`) | Not applicable. The message has no original email, like a message written in the app |
| Each image attachment becomes its own `Image` message: uploaded to tusd, a `chat_images` row, the Freegle logo hashes suppressed, held for review as `SameImage` when the hash was used more than 20 times in 24 hours | Mirrored from image URLs in the feed, taking the largest size as `GroupPostIngestionService::bestPhotoUrl()` does |
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

**Something to check, found while reading:** `ChatProcessService::processMessage` writes
`reviewrequired` and `reportreason` from its own decision (`ChatProcessService.php:446`), starting
from 0, without reading the values the row arrived with. If that is right, the review flags the
email path sets at ingestion (spam check, `checkReview`, the "last held" rule) are overwritten a
minute later, and for a User2Mod chat always cleared. Q6 in the yesterday queries checks this
against production data, and a unit test should pin whichever answer it gives. It
does not change what the API path should do (mirror the ingestion flags, so a fix covers both), but
it means the parity job cannot compare those flags from the stored column; it has to compare the
decision each path made at ingestion, from trace lines (5.3). Raise it separately; it is not part of
this work.

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
  account.
- **No account at all:** skip with `tn-sender-unknown`, noted for the coverage check. Do not
  provision a new user as the posts path does. A replier has always been joined through the partner
  API first, which stamps their TN id, so an unknown id means an account we know only by address;
  making a second account would split their chats from them.

The parity job reports every case where the email path's account and the API path's differ. That
list is useful in itself: each entry is a message the email path filed on an account that does not
hold the sender's TN id. Q4 in the yesterday queries measures this now, from the TN user id header
on the mail. It shows how often each case occurs before anything is built.

### 3.2 The chat and the post come from ids

`fd_chat_id`, `fd_msgid`, `fd_recipient_user_id` and `fd_group_id` replace parsing addresses and
headers, and fuzzy subject matching goes (row 3 keeps the subject in the body when there is no post).

### 3.3 No address is attached to the account

See the `addEmailToUser` row in section 2.

### 3.4 Idempotent on TN's message id

The sync re-reads overlapping windows (10s overlap, and whole windows while a feed is held), so the
same record arrives more than once. A new table, `chat_messages_tn`:

| column | |
|---|---|
| `id` | surrogate key (conventions: every table gets one) |
| `chatmsgid` | FK to `chat_messages.id`, cascade delete: the first message written for the record (the text, or the first image when there is no text) |
| `tnmessageid` | TN's id for the message, **unique** |
| `tndate` | TN's date for the message |
| `created` | |

A new table rather than a column on `chat_messages`, which is very large and would need a
node-by-node ALTER. The text message, its image messages and this row are written in one
transaction, and the unique key decides a race. Image uploads to tusd happen before the
transaction; a lost race orphans those blobs, which is acceptable.

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

A TN-only reimplementation of the four handlers in section 2 plus `createChatMessageFromEmail` and
`addPhotosToChat`, working on a DTO instead of `ParsedEmail`. Same structure as
`GroupPostIngestionService`: it decides the outcome and never touches Loki; it keeps a routing
context with the email path's `dropped()` / `getLastRoutingContext()` semantics; drop reasons use the
email path's exact strings where there is an equivalent (`Reply to non-existent chat`,
`User not part of chat`, `Volunteers message to unknown group`, …) and their own documented strings
otherwise.

One structural change from the posts service: split it into

- `plan(TnChatMessage $record): ChatIngestPlan`, read-only and deterministic: chat, sender,
  recipient, chat type, message type, `refmsgid`, final text, review decision and reason, image URLs,
  or a drop with its reason; and
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
  `isTrashNothingGroupPost()`: a valid `X-Trash-Nothing-Secret` (compared with the configured secret;
  the From domain alone can be forged), and an address that `route()` would hand to one of the
  handlers the feed covers, mirroring `route()`'s phase order as `claimedByAnEarlierPhase()` does.
  Gate each row of section 1 separately, so a kind of message the feed does not carry keeps arriving
  by email. Skipping too widely loses real members' mail with no error.
- **Drift guard.** Add the chat handlers, `createChatMessageFromEmail`, `addPhotosToChat` and
  `findRefMessage` to `EmailPathMirrorDriftTest`, so an edit to the email side forces a decision about
  the API side.

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

For posts it was `tnpostid`. For chat there is nothing shared today, so ask TN for both of these
(section 7):

- **`email_message_id`** on each API record: the `Message-ID` of the email TN sent for the same
  message. The email path stores that in `messages.messageid` and links it to the chat message
  through `chat_messages_byemail`, so the join is exact and needs no change to the frozen email path.
- **`X-Trash-Nothing-Chat-Message-Id`** on each chat email: TN's id for the message. It lets the job
  ask TN about an email the feed did not return (5.3 layer 1), and is what the post-cutover check
  joins on (5.4).

If TN can give neither, the fallback is a fuzzy join on (chat or sender/recipient pair, normalised
text hash, time within ten minutes), which works but makes every layer noisier. Settle this before
building.

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
   Message-ID and the email path's outcome, after asking TN about it by id (deleted, or sent to us
   by email only).
2. **Extras (reported).** API records with no email. Some are expected and each kind is named:
   row 8's misrouted replies, which the email path loses. Anything between two TN members fails the
   run, because it should never be in the feed.
3. **Outcome (fails the run).** Both paths made a message, or both dropped it for an equivalent
   reason. Differences that 3.1 explains are reported in their own section, not failed.
4. **Content (fails the run)**, where both made a message: chat id, sender, chat type, message
   type, `refmsgid`, normalised text, number of images and their hashes, and the ingestion-time
   review decision. The email side's review decision comes from its trace line, for the reason in
   section 2. Expect to tune the text normalisation, as the posts work did with `OFFERED:` and
   `OFFER:`: the email side went through HTML conversion and quote stripping, and the API side should
   be cleaner. "The email had extra quoted or footer text" is reported apart from "the API is missing
   text", which fails.
5. **Loki (fails the run).** The two routed entries have the same labels apart from `source`, the
   same keys, and the same outcome and reason where layer 3 agreed.

The run ends with `logBatchJob('tn:chat-parity-check', …)` and a non-zero exit on any failing layer,
so a failure alerts through the scheduled-outcome checks.

**Cutover gate:** fourteen consecutive days with layers 1, 3, 4 and 5 clean, apart from documented
divergences, and every extra in layer 2 explained.

### 5.4 After cutover: `tn:verify-chat-coverage`

Ask TN to keep emailing chat messages for a soak period after cutover. The gate skips routing them
but the archive keeps them, as for posts. The hourly check reads each archived TN chat email's
`X-Trash-Nothing-Chat-Message-Id` and expects a `chat_messages_tn` row for it. A miss is looked up by
id at TN and classified. Backfilling a genuine miss goes through the same `plan()`/`apply()`, behind
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
- It can look much further back. Raw mail in `messages` lasts 30 days and Loki 31, against the
  archive's 48 hours.
- With the LVM snapshots, it can run against any of the last seven days.

What to run there:

- **Take the email side from the database, not the archive** (`--email-side=db`). TN chat mail
  comes from `messages` and `chat_messages_byemail` for the window, joined to the feed on
  `messages.messageid`. Drops come from yesterday's Loki and are compared as counts per reason,
  because a dropped mail left no Message-ID behind. Choose windows that end before the snapshot was
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
    upload there writes to production storage.
  - Running `chats:process-incoming` on the clone afterwards also compares the state after
    processing. It queues push tasks, but nothing on yesterday sends them while batch is off.
- **Test the gate predicate on real mail.** Run `isTrashNothingChatMessage()` and the existing
  `isTrashNothingGroupPost()` over every raw mail in `messages` for 30 days. The chat predicate must
  match exactly the TN chat mail Q1 counts, row by row, and nothing from anyone else. A false match
  is a member's mail the cutover would silently stop routing.
- **Check query plans.** Run `EXPLAIN` on production-sized tables for each query the ingestion
  service and the parity job run: the "last message in the chat" read, the `SameImage` count over
  `chat_images`, and the `chat_messages_tn` lookups. Run the `chat_messages_tn` migration on the
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
| 0 | Run the section 1 queries and the header scan on yesterday. Settle the Q6 question. Agree the contract in section 7 with Andrew, cut down to what yesterday could not answer | No |
| 1 | PR: `ChatMessageSyncer` (read-only), the DTO, fixtures, `ChatMessageIngestionService::plan()`, `tn:chat-parity-check` with both email sides (archive and `db`), the migration for `chat_messages_tn`, docs. Rehearse on yesterday first (5.6), then deploy and run the parity job hourly on batch-prod | No |
| 2 | Parallel run: hourly on batch-prod, plus daily on yesterday over the previous day, with `--apply`. Fix what either finds, on the API side, and adjust the contract with TN if needed | No |
| 3 | PR: `apply()`, the flag, the gate, `logIngestedChatMessage`, `tn:verify-chat-coverage`, the drift test, docs. Ships with the flag off. Rehearse the cutover and the rollback on yesterday, and run the gate over 30 days of real mail there | No (flag off) |
| 4 | Cutover: turn the flag on. TN keeps emailing; the coverage check runs | Yes |
| 5 | After the soak, TN stops emailing chat messages. Later, outbound | Yes |

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
| `id` | required | TN's id for the message, unique and never reused. Idempotency (3.4) |
| `date` | required | When TN sent it to Freegle. **Must not change after the fact**: `/posts/all` filters on a `date` that moves when a post is edited or reposted, which pushed real posts out of every window (posts plan, section Q) |
| `tn_user_id` | required | The sender (3.1) |
| `fd_user_id` | if known | Our id for the sender, as on the user-changes feed |
| `kind` | required | Which of section 1's rows: `chat_reply`, `post_reply`, `user_message`, `volunteers`, `report` |
| `fd_chat_id` | for `chat_reply` | The chat, as in the notify address TN replied to |
| `fd_msgid` | for `post_reply` | The Freegle post, as `X-FD-MsgId` today |
| `fd_recipient_user_id` | for `post_reply`, `user_message` | The Freegle member written to |
| `fd_group_id` | for `volunteers`, `report` | The community, by Freegle id rather than nameshort |
| `reported_fd_user_id`, `reported_fd_msgid` | for `report` | Who or what is reported, if TN has them |
| `subject` | where TN has one | Kept in the body for `user_message` with no post |
| `text` | required | What the member wrote, plain UTF-8, without quoted history, TN footer or signature. For a report, the reason and the transcript |
| `display_name` | required | The name TN shows for the sender; the spam check reads it |
| `images` | if any | Each photo's sizes ordered smallest to largest, as on posts, with URLs that work without a login for at least a week |
| `automated` | required | True for a message TN wrote on the member's behalf (row 6) |
| `email_message_id` | during the parallel run and soak | The `Message-ID` of the email TN sent for the same message (5.2) |

Behaviour:

- **The same set of messages TN emails us today, no more and no fewer.** Nothing between two TN
  members (conventions: those stay on TN).
- Records in ascending `(date, id)` order, and the same record on every read of a window.
- How far back the feed goes. At least seven days, so an outage of our sync or theirs can be caught
  up; longer is better.
- `GET /fd/api/chat-messages/{id}` for a single message, for the coverage checks to classify a miss,
  as `GET /posts/{id}` is used for posts.
- Whether TN members can edit or delete a chat message after sending, and if so what the feed shows.
  The email path never learns of either; the API path will do the same unless we decide otherwise.
- The existing kick to start `tn:sync` straight away when a chat message is sent, if TN would like
  lower latency than the ten-second schedule.
- Same rate limit as the other feeds (2 requests a second per key), shared across the four.

### 7.2 For testing and the switch

Run Q2, Q3 and the header scan on yesterday before asking for the first two items. They may show
that TN already sends what we need.

- **`X-Trash-Nothing-Chat-Message-Id`** on every chat email, with the value of `id` (5.2). Not
  needed if the header scan finds a per-message TN id already, or if TN's Message-IDs carry one (Q3).
- Chat mail carrying `X-Trash-Nothing-Secret` and `X-Trash-Nothing-User-Id`. The gate relies on the
  secret, and the user-id header has only been measured on posts (trashnothing.md, "measured on
  posts only"). Q2 answers this from production mail. Ask TN only about the routes where it shows
  gaps.
- **Keep emailing chat messages** through the parallel run and the soak after cutover, until we say
  stop. Rollback (5.5) depends on it.
- The OpenAPI description of the endpoint, and a sample response covering every `kind`, with and
  without images, and an automated one. These become the fixtures.
- **Test accounts.** Two TN test members joined to a Freegle test community, so each row of section 1
  can be driven end to end and seen arrive both by email and in the feed: reply to a test post, reply
  in the chat, write to the volunteers, report a member, send a photo, trigger an automated response.
  Say whether TN has a staging environment that can point at a Freegle dev server; if not, this
  happens on production with test accounts.
- What TN does today when Freegle drops a message (the chat no longer exists, the member is not in
  it). The email path drops silently, so TN probably does nothing; with an API there is no bounce
  either. Whether TN wants to hear about refusals can wait for the outbound work.
- The feed carries private conversations, as the email does. Confirm nothing about the agreement
  between us needs to change for an API carrying the same data.

---

## 8. Decisions needed

1. **Unknown sender (3.1).** Recommended: skip and report. The alternative, provisioning a new user
   as the posts path does, would split a member known only by address from their chats. Decide
   with Q4's numbers. Its case c is the messages this decision is about, unless TN sends our id.
   Cases d and e are the ones 3.1 files on another account or skips.
2. **Separate cutover flag (4.3).** Recommended: separate from the posts flag.
3. **Per-row gating (4.3).** Recommended: gate each row of section 1 on its own, so the cutover can
   start with the rows the feed carries and the rest stay on email.
4. **Automated responses (row 6).** Recommended: keep them, with TN's note stripped, as the email
   path does.
5. **Checks that direct mail lacks** (post more than 42 days old, closed community, post with an
   outcome). Recommended: mirror direct mail now; add them to both paths together later.
6. **The ingestion-time review flags** (end of section 2). Not part of this work, but someone should
   decide whether `ChatProcessService` overwriting them is intended.
