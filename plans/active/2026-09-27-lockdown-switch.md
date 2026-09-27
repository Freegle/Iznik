# Spam waves: a lockdown switch and an AI duty officer

Date: 21 September 2026. Design only; nothing here is implemented. Written the morning after
the voucher-phishing wave. The wave is used as one worked example. It is not a template: the
next crew will change their addresses, their timing, their wording, their targets and
possibly the surface they use, and any detector built from this wave's shape will be looking
the wrong way. What does not change is that a wave is somebody using Freegle for something
Freegle is not for, at a scale one person's ordinary use never reaches. Recognising that
takes judgement about intent and plausibility, which is what a model is for, and it cannot
be reduced to thresholds without becoming the next thing to be dodged.

This note holds two designs. Sections 1 to 9 are the AI duty officer. Section 10 is the
manual alternative: a lockdown switch that anyone with Support tools can press, after which
Freegle stays up but nothing written reaches anyone else until a person lifts it. The switch
is the thing to build first. It needs no model and mostly works by telling loops that already
exist to stop moving things, and the officer's response is built on the same hold and release.

## 1. What went wrong, and why more rules is the wrong answer

Every existing layer judges **one message at a time** against **rules written in advance**:
rspamd and SpamAssassin on inbound mail, `ContentCheckService` on posts and chat,
`ChatProcessService` holding or dropping a chat message, moderators reviewing what is held.
On 20 September 21,977 near-identical replies from 4,428 accounts created seconds earlier
went through all of them, because each layer saw one plausible-looking reply at a time and
no layer asked what was going on.

The wave-specific holes (a bare domain the URL check did not recognise, a keyword blinded
by the allowed word "shop", bold letters the matcher could not read) are fixed on master.
Fixing them was necessary and buys nothing against the next wave, which will not use them.
Adding a rule per trick is a race the defender loses by design.

`docs/ops/reference/spam-and-abuse.md` records a measured negative result for an AI
moderator of posts (63.5% versus 63.0% on approve/reject). That result stands. It is a
different question: whether one member's post follows a community's rules depends on facts
outside the text. Whether a few hundred fresh accounts pushing the same voucher link to
people who advertised a sofa a month ago are a scam is a question a model answers
correctly, and would have answered correctly with the words, the timing and the addresses
all changed.

## 2. The idea

A **duty officer**: a model given, every few minutes, a situational picture of what is
happening on Freegle, the means to look closer, memory of what past waves and past false
alarms looked like, and a bounded set of actions. It is asked the open question - is
anything happening that a person who runs this site would want to know about or stop - not
a closed one about a threshold. The statistics that follow exist to build the picture and
to keep the model's attention and cost on the parts of the stream worth reading, not to
decide.

Three properties follow from putting judgement at the centre:

- **It generalises by construction.** A slow drip from aged accounts with varied wording
  and fresh targets still reads as "these people are all trying to get recipients to a
  payment page", because that is what the model is asked about.
- **It explains itself.** Every hold or release carries the model's reasoning, which is what
  a moderator needs to confirm or overrule it and what the next iteration learns from.
- **It is assessable.** Its decisions are recorded as decisions, so precision and recall
  can be measured on replayed history and on synthetic waves, including waves the model
  itself is asked to invent.

## 3. What the officer is given

### 3.1 The picture

A structured summary of the last 15 minutes and the last 24 hours, per surface (chat
replies, posts, ChitChat, group joins, event and volunteering submissions, signups), with
every number as a multiple of the same hour-of-week over the preceding four weeks. Not a
list of thresholds crossed: the picture is always produced, and the model reads it.

For each surface: volume, the distribution of account age among the actors, how many actors
did more than one thing, the largest clusters of similar content (from the embedding
sidecar Freegle already runs, `POST /embed`, grouped by cosine similarity, with three
samples each), the targets those clusters went at (posts by age and type, groups, rooms),
member reports received (`ReportedUser` rows: four members reported the wave within
minutes and nothing read them), moderator actions in the window, and anything the
existing filters held.

### 3.2 The means to look closer

The officer can ask for more before deciding, through a small tool set with read-only
access: the full text of a cluster's messages, an account's history (created when, joined
what, posted what, replied to whom, from where, and its login and identity-change audit
rows once those carry a network and device), the recipients' side (did anyone reply,
report, or leave), the signup stream around a burst, and a similarity search against past
confirmed waves and past false alarms. Each tool call is logged with its cost.

### 3.3 Memory

Every past decision - confirmed waves, released clusters, moderator overrules - is kept
with its picture, its reasoning and its outcome, and the officer sees the nearest few by
similarity when it looks at something new. This is how "we have seen this kind of thing"
and "last time this looked alarming it was a partner import" enter the judgement without
anyone writing a rule.

### 3.5 Whose hands are on the account

The calibration wave used fresh accounts, and nothing in this design depends on that. A
crew that buys or phishes a few hundred established members' logins, or that forges the
From address on the inbound mail path, produces the same wave from accounts that are years
old and have real histories. The officer is therefore asked, for every actor in a cluster,
not "how new is this account" but **"is this the person who usually uses it"**:

- **Out of character.** Each account's own history is its baseline: how often it acts,
  on what surface, at what hours, in what communities, at what distance, in what register.
  A member who has replied to four posts in three years and now replies to twelve in a
  minute, all outside their communities, all in bold text about vouchers, is a hijacked
  account whatever its age. The picture carries each actor's deviation from itself, and
  the officer weighs it above age.
- **Session and identity evidence.** Today there is almost none: the sessions table holds
  no address or device, the User/Login audit row records only the method, the site and
  the session series, and no log row is written for a password or email change. Detecting a
  takeover needs those traces, so a prerequisite is to record, on the login audit row and
  on identity changes, a coarse address (network, not host), a user-agent family and a
  country, kept for a bounded period. New session series from a new network on a dormant
  account, followed by a burst, is the shape to look for.
- **Spoofed identity on the mail path.** Inbound mail is attributed to a member by its
  From address (`IncomingMailService`, `findUserByEmail` with the canon fallback);
  `SpamCheckService` looks for our-domain spoofing and Spamhaus listings but consults no
  DKIM, SPF or DMARC result. rspamd sits in front and computes them. The attribution must
  carry that verdict, and an action arriving by unauthenticated mail in a member's name
  is scored as **unverified identity**, not as the member.
- **Partner and API identities.** TrashNothing and LoveJunk act on behalf of members
  through partner accounts; a compromise there is a wave from one credential. The picture
  shows partner-originated actions as their own surface with their own baseline.

The response differs, and the officer is required to say which case it believes it is in:
a hijacked member is **not marked as a spammer**. Their sessions are ended, their password
reset, their recent actions held and reviewed, and the real owner told through a channel
the attacker does not control (the existing preferred email, plus the app push if
registered). Marking them as a spammer removes a genuine member and warns everyone they
ever spoke to; the wrong call there does more harm than the wave.

### 3.4 What it must decide

For each thing it flags: what it is (coordinated scam or phishing; coordinated commercial
spam; harassment; a legitimate campaign, import or event; a system fault such as a mailer
loop; or unclear), how confident it is and why, who is affected, whether the actors are throwaway
accounts, hijacked members or spoofed identities (3.5), and which of the actions in
section 4 it recommends. A verdict without reasoning is rejected by the harness.

## 4. What it may do

Graduated, reversible, and rate-limited. Each action is a separate switch, off by default,
promoted to automatic on its own record:

1. **Hold** the cluster's undelivered rows (`reviewrequired = 1`, with a reason naming the
   wave) so nothing more goes out while a human looks. Automatic from day one; it is what a
   cautious moderator would do and it costs a genuine member minutes. Pressed for the
   whole site at once, this is the lockdown switch of section 10.
2. **Reject** held rows (`reviewrejected = 1`), so they are never delivered or emailed.
3. **Block** the cluster's shared link or phrase as a folded block keyword, which the
   existing backfill applies across the board.
4. **Mark the actors** as spammers (`spam_users`, reason from the officer's summary), which
   `users:remove-spammers` and `chats:process-spam` turn into membership removal and
   warnings to the members they contacted.
5. **Warn the recipients** with a notice from the Freegle system user (id 45136673,
   whitelisted) in each affected room, the sender's roster silenced so only the victim is
   told. This is the step that limits harm: the damage from a scam is done when it is read.
6. **Tell people**: email to geeks@, a Sentry event, a Discourse post with the summary.
7. **Secure a hijacked account** instead of 4: end its sessions, force a password reset,
   hold its recent actions for review, and tell the owner through the existing preferred
   email and app push. Never a spammer mark.

A damage bound sits above all of it: no more than a configured share of a surface's
last-hour volume may be held without a human, and past that the officer alerts and stops
holding, because a runaway detector is a worse outage than a wave. Everything is undone by
the same service in reverse, and keywords it adds carry a tag.

## 5. What stays statistical, and why

The model does not read every message: that is too slow, too expensive and unnecessary.
Two cheap layers exist for cost and latency, and neither decides anything on its own.

- **A budget for young accounts, synchronously in the Go API** (`chat/chatmessage.go
  CreateChatMessage` and the post, ChitChat and join creators): an account under a day old
  that exceeds a small number of distinct targets in a few minutes has the rest held, not
  dropped, for the officer to look at. The same budget applies, relative to the account's
  own history, to any account whose rate in the last ten minutes is far above anything it
  has done before: an established member who suddenly replies to a dozen posts a minute
  is held the same way, whatever their age. This is the one place a hard number is defensible,
  because it is set from the genuine population (five distinct posts in an hour is the
  99.4th percentile of real accounts) and because holding is cheap to reverse. It would
  have held 21,900 of the 21,977 messages before any mail went out, and it costs a keen
  new member a few minutes.
- **Clustering and baselines in the batch**, every minute, in the loops that already touch
  every new row (`ChatProcessService::processIncoming`, the content-check callers,
  `MembershipsProcessingService`): embeddings from the sidecar, similarity groups, per-actor
  and per-surface rates against rolling hour-of-week baselines. Their output is the picture
  in 3.1. They never hold anything themselves.

The hour-of-week baselines are recomputed nightly, so a busier month raises the bar
without anyone editing a constant.

## 6. Evaluation, before it is trusted with anything

- **Replay.** The 20-21 September wave and the 6 September spam bot from the reply-stats
  note, minute by minute, plus a week of ordinary traffic including digest hours, a partner
  import and a group announcement copied to many rooms. Measure what the officer would have
  held, when, and what it would have released.
- **Red team.** Ask a model to write five waves that this design should still catch and
  that share nothing with the calibration wave: hijacked dormant members, a slow drip over
  a day, varied wording, replies to fresh posts, a different surface, a different ask,
  forged From addresses on the mail path. Replay
  those too. The ones that get through are the next iteration's work.
- **Shadow run.** Two weeks live with every action switched off, recording decisions and
  emailing them. Compare with what moderators actually did.
- **Promotion.** Hold on from day one after the shadow run; each further action promoted
  to automatic after a month without a false positive at that step, one step at a time.

## 7. Where it goes

| piece | where |
|---|---|
| young-account budget | `iznik-server-go/chat/chatmessage.go`, `message/`, `newsfeed/`, group join; limits in the Go env |
| login and identity audit | `iznik-server-go/auth/auth.go LogLogin` and the password and email change paths: coarse network, user-agent family, country, bounded retention; a prerequisite for hijack detection |
| mail identity verdict | `IncomingMailService` attribution carries rspamd's DKIM/SPF/DMARC result; unauthenticated From in a member's name scored as unverified |
| picture builder and clustering | `iznik-batch/app/Services/Spam/WavePicture.php`, called from the per-minute loops; sidecar via `ContentEmbeddingService::fetchEmbeddings`; last-hour vectors in Redis |
| baselines | `iznik-batch/app/Services/Spam/WaveBaselines.php` + nightly `spam:wave-baselines` |
| the officer | `iznik-batch/app/Services/Spam/WaveOfficer.php`: prompt, tools, memory lookup, decision schema; metered Anthropic API (`ANTHROPIC_API_KEY`, not the shared subscription token, which exhausts its week on one job); every call logged with cost |
| response | `iznik-batch/app/Services/Spam/WaveResponseService.php`, from `storage/scam-wave/wave-respond.php`; one method per action, each with a dry-run flag and a reverse |
| tables | Laravel migrations: `spam_waves` (picture, reasoning, verdict, confidence, actions, outcome), `spam_wave_members`, `spam_wave_memory` (embeddings of past pictures) |
| moderator page | ModTools SysAdmin: waves list, detail with reasoning and samples, confirm and release buttons; a Go endpoint under `/spam/waves` |
| ops doc | `docs/ops/reference/spam-and-abuse.md`: a "Waves" section, and the AI section amended to say what the model is and is not used for |
| tests | replays and the red-team waves as fixtures; assertions on holds, releases and the damage bound |

The 15-minute `wave-detect.php` cron stays until the shadow run has matched it, then goes.

## 8. The calibration wave, for the replay fixtures

Measured on 21 September; a week of ordinary traffic, 13-19 September, is the baseline.
These numbers seed the fixtures and the young-account budget. They are not thresholds.

| | the wave | ordinary week |
|---|---|---|
| Interested replies per 10 minutes, 22:00-00:00 UTC | 2,200-2,600 for 90 minutes, after a 20-account probe | max 22, average 6-10 |
| account age at first reply | 4,399 of 4,428 under 30 seconds | 8.5% of genuine new accounts reply within 10 minutes |
| distinct posts per account within an hour | 4,360 accounts at exactly 5, within 60 seconds | 44 accounts a week at 5-9; 4 at 10+ |
| age of the post replied to | 77% older than a week | 12% |
| distinct opening lines | 11 across 21,977 messages | every reply differs |
| member reports during the wave | 4 `ReportedUser` messages, unread by anything | - |

## 9. Open questions for the operator

- Signup friction: no captcha and no signup rate limit exist today. The young-account
  budget is the gentler answer; a challenge on a signup burst is the blunter one.
- Who confirms actions 2-5 out of hours: support volunteers, or the operator only?
- Should confirmed wave actors be banned outright, rather than marked and removed?
- Recording a coarse address and device on login and identity changes is a privacy
  decision as well as a security one: what retention, and does the privacy page change?
- The nine wave senders whose addresses did not match the cleanup pattern are still
  unmarked; the officer would have caught them by behaviour. Mark them now?

## 10. The lockdown switch

Design only, 21 September, from master and from what other platforms do.

### 10.1 What it is

- A button on the Support page and a command on the batch host. Any Support or Admin user
  presses it and any Support or Admin user lifts it.
- Freegle stays up. Members can post, reply, chat and write on ChitChat. What they write
  looks sent. It reaches nobody.
- No email, no push, no download. Moderators see everything and can approve and report a
  spammer. Nothing else.
- It lasts hours, not days. There is no expiry. Nothing lifts itself. Lifting is by
  surface and by class, in a written order.
- Cost of a slow press: at 2,200 to 2,600 replies every ten minutes, each ten minutes
  between noticing and pressing is another two thousand phishing messages read.
- Relation to the officer: the officer recommends the switch before it is trusted to press
  it, and its response service (section 4) is the same hold and release applied to a
  cluster instead of the site.

### 10.2 What others do

| platform | mechanism | taken from it |
|---|---|---|
| [Twitch Shield Mode](https://safety.twitch.tv/s/article/Protect-your-channel-with-Shield-Mode?language=en_US) (2022) | safety settings prepared in advance, applied by one button or a slash command, by streamer or moderator; ban phrases cleared when it ends | prepare in calm, press in a hurry, leave nothing behind |
| [GitHub interaction limits](https://docs.github.com/en/communities/moderating-comments-and-conversations/limiting-interactions-in-your-repository) (2017) | limit by account age or prior contribution for a fixed period; organisation overrides repository | limit by trust, not everyone; a hierarchy of scope |
| [Discord Security Actions](https://support.discord.com/hc/en-us/articles/17439993574167-Activity-Alerts-Security-Actions) | pause invites and direct messages from the alert; "mark as resolved" with a note | pause rather than block; a note for whoever comes next |
| [Meta break-glass measures](https://www.techpolicy.press/we-know-a-little-about-metas-break-glass-measures-we-should-know-more/) | a prepared list of reach reductions, used in 2020 and on 6 January, rolled back after | the list is written before the emergency; lifting is recorded as carefully as pressing |
| [Reddit Crowd Control](https://mods.reddithelp.com/hc/en-us/articles/360038129231-Crowd-Control) | comments from outsiders and new accounts collapsed or held for approval; moderators see them labelled | writer and reader see different things; the moderator view labels what was held |
| [Wikipedia pending changes](https://en.wikipedia.org/wiki/Wikipedia:Pending_changes) | edits saved and shown to the editor, hidden from readers until accepted; reviewers check "broadly acceptable", not correct | our shape exactly; the review standard is low and quick |
| [Twitter, 15 July 2020](https://en.wikipedia.org/wiki/2020_Twitter_account_hijacking) | all verified accounts blocked from tweeting for about two and a half hours; password resets blocked; innocent people locked out for days | a manual site-wide degraded mode is an ordinary incident tool; blocking identity recovery has collateral; refuse honestly |
| [Discourse read-only mode](https://meta.discourse.org/t/what-to-do-when-you-have-locked-yourself-out-by-invalid-sso-configuration-or-read-only-mode/89605) | locks administrators out too; needs a back door | the people working the incident are exempt; a way in that does not depend on the app |
| [Mastodon, February 2024](https://techcrunch.com/?p=2667481) | advice under a spam wave: registration to approval, block disposable email | the signup gate is the blunt lever (section 9) |
| [Google SRE, cascading failures](https://sre.google/sre-book/addressing-cascading-failures/); [PostHog post-mortem, September 2025](https://posthog.com/handbook/company/post-mortems/2025-09-29-flags-is-down.md) | what enters a degraded mode and what it does there; the flag service itself failed, recovery delayed by "untested rollback procedures" | depend on nothing likely to be broken; drill both directions |

### 10.3 Why it is cheap to build

Everything a member writes reaches other people through a batch loop, and each loop has a
"not yet" state the front end shows to the author as done:

- Chat: created with `processingrequired = 1`; visible only to the sender until
  `chats:process-incoming` (every minute) marks it processed (`chat/chatmessage.go`
  `CreateChatMessage`; `FetchChatMessages` shows the other party only rows with
  `processingsuccessful = 1`). Flags are blanked before they reach a participant. No "held"
  wording exists in the member UI; the rippling hold badge is deliberately never shown to
  the sender. Chat mail and push both require the message to be processed.
- Posts: every post starts `Pending`; `messages:contentcheck` (every minute) promotes to
  `Approved` and into `messages_spatial`, the only thing Browse and search read. The
  poster's own posts are unioned in separately "so that it is less obvious if a message is
  delayed for approval". My Posts shows Pending as Approved. Email posts, TrashNothing posts
  and rippled copies take the same path.
- ChitChat: a post with `hidden` set is returned to its author and moderators only; the
  author sees no notice; reply notifications skip hidden posts.
- Email: every mail goes through `EmailSpoolerService::spool()` into a file spool drained
  by four daemons; `MailSuppressionService::shouldSkip()` runs before rendering in every
  per-recipient loop and records skips in `mail_suppressed_counts`; `DeferralCatchUpService`
  already holds the right catch-up policy (drop stale post mails and periodics, one digest,
  one chat summary).
- Push: one class, `PushNotificationService`, FCM only.
- Moderator actions: all in the Go API. Post actions pass one gate,
  `dispatchPostMessageAction`, written so "a new moderation action cannot silently skip the
  check by forgetting to call it". Membership, chat, group, comment, keyword and spammer
  actions each have one handler.
- Data export: four endpoints (member GDPR export, Support user dump with a login-free key
  path, spammer list export, partnership stats file).

So: the batch declines to move things; the Go API refuses a short list; ChitChat creation
gets one line; the state has one reader in each codebase.

### 10.4 The state

- `lockdowns`, append-only, one row per change, the history is the audit: `id`, `active`,
  `surfaces` (JSON: `chat`, `chat_mode` hard or soft, `posts`, `chitchat`, `events`,
  `email`, `push`, `export`, `mods`), `reason`, `notice`, `notice_audience` (`none`,
  `mods`, `members`), `startedby`, `startedat`, `endedby`, `endedat`, `endnote`. Current
  state is the newest row.
- `lockdown_holds`: `id`, `lockdownid`, `kind`, `refid`, `userid`, `created`, `risk`
  (low, risky, spam), `releasedat`, `outcome`. Written by the triage for chat and posts;
  written by the Go API at creation for ChitChat, because a lockdown-hidden post and a
  suppressed author's post both have `hiddenby` null.
- `lockdown_counters`: `id`, `lockdownid`, `kind`, `count`. What was refused or not sent.
- Go reads the newest row through a five-second in-memory cache (`browsecount/cache.go`
  pattern). A failed read keeps the last state. A process that has never read one treats the
  site as open; that only happens with the database unreachable.
- Batch reads at the top of every loop iteration, not per process; the spool daemons and
  the scheduler run for days.
- Not the `config` table (no history; a public-read allowlist would leak which surfaces
  are held). Not an environment variable (needs a restart per container).
- Members' clients: `GET /lockdown` returns only whether there is a notice and its text,
  fetched with the navbar's sixty-second pass. Which surfaces are held is never returned.
- ModTools: full state on the thirty-second work poll; the platform traffic light in
  `ModStatus.vue` turns red.
- Setting and lifting: `PATCH /lockdown` behind `RequireSupportOrAdminMiddleware`;
  `php artisan lockdown:on|off|status` on the batch host, for when ModTools or the API is
  what is broken or what an attacker holds.
- Every change: a row, a Sentry event, a mail to geeks@.

### 10.5 Every write endpoint, and what happens to it

Treatments:

- **held**: accepted, shown to the author as done, reaches nobody until lifted.
- **refused**: 409 with a lockdown status; the client hides or disables the control first.
  Member-facing wording: "Changes are paused for a few hours while we deal with a
  security incident."
- **allowed**: continues, including its email where named.
- **own**: private to the caller or telemetry; reaches nobody; continues.
- **exempt**: Support or Admin only; continues.

Every non-GET route in `iznik-server-go/router/routes.go`, grouped. An endpoint with an
`action` or a field list is split by what it does.

**Session, identity, account**

| endpoint | what | treatment | note |
|---|---|---|---|
| `POST /session` login | sign in | allowed | |
| `POST /session` `LostPassword` | sends the reset or sign-in link | allowed, mail allowed | Twitter's regret |
| `POST /session` `Unsubscribe`, `POST /user/unsubscribe` | leave Freegle | allowed, mail allowed | |
| `POST /session` `Forget`, `DELETE /user` | delete account | allowed | |
| `POST /session` `Related` | related-account record | own | |
| `PATCH /session` | own settings | allowed | |
| `DELETE /session` | sign out | allowed | |
| `PUT /user` | signup | allowed, verification mail allowed | account counted; its holds start risky |
| `POST /user` `AddEmail`, `RemoveEmail` | change addresses | allowed, verification mail allowed | recorded in the section 3.5 audit |
| `POST /user` `Merge`, `PUT|POST|DELETE /merge` | merge accounts | allowed | the merge mail waits with the rest |
| `POST /user` `Rate` | rate a member | allowed | no text reaches anyone |
| `POST /user` `RatingReviewed` | moderator | refused | |
| `POST /user` `Unbounce`, `POST /user/relevantoff` | own flags | own | |
| `PATCH /user` display name, about me, avatar | profile visible to others | refused | rare, visible, cheaper to refuse than to queue |
| `PATCH /user` own settings, holiday | private | allowed | |
| `PATCH /user` moderation statuses | moderator | refused | "moderated membership changes" |
| `DELETE /usersearch` | own | own | |

**Posts**

| endpoint | what | treatment | note |
|---|---|---|---|
| `PUT /message` | create or submit | held | stays `Pending`; the direct-approve path for unmoderated members forced to `Pending` |
| `PATCH /message` member edit | edit a live post | held | the pending-edits state `ApproveEdits` already serves |
| `PATCH /message` moderator edit | | refused | |
| `POST /message` `Reply`, `JoinAndPost` | reply, join then post | held | reply is a chat message; post is `Pending` |
| `POST /message` `Promise`, `Renege`, `AddBy`, `RemoveBy` | handover state | allowed | system chat messages, no member text |
| `POST /message` `Outcome`, `OutcomeIntended` | taken, received, withdrawn | allowed | outcome mail waits |
| `POST /message` `View`, `AcceptAgreement`, `PartnerConsent` | | own | |
| `POST /message` `BulkInterest`, `BulkInterestState`, `BulkEditLink`; `POST /bulkoffer/update/:token`; `POST /helper` | bulk offer flows, the concierge | held | their chat messages are held; the edit is held |
| `POST /message` `Approve` | moderator | allowed, basic button only | the API refuses an approve carrying a message |
| `POST /message` `Reject`, `Delete`, `Spam`, `Hold`, `Release`, `ApproveEdits`, `RevertEdits`, `Move`, `BackToPending`, `RejectToDraft`, `BackToDraft` | moderator | refused | |
| `PATCH /message/tn/:tnpostid` | partner fan-out of the above | as the action | |
| `DELETE /message/:id` | withdraw own post | allowed | |
| `POST /messages/markseen`, `POST /messages/clearcount` | | own | |

**Chat**

| endpoint | what | treatment | note |
|---|---|---|---|
| `POST /chat/:id/message` | send | held | `processingrequired = 1`; User2Mod and Mod2Mod rooms keep flowing |
| `POST /chat/lovejunk`; `POST /amp/chat/:id/reply`; `POST /amp/digest/:id/reply`; `POST /amp/digest/reply` | partner and AMP-email replies | held | all set `processingrequired = 1` (`chatmessage.go`, `amp.go`) |
| `POST /chat/:id/message/:mid/prompt` | answer a prompt | held | |
| `PUT /chat/rooms` | open a room | allowed | a room with no visible message is not listed to the other party |
| `POST /chatrooms` `Typing`, `AllSeen` | | own | |
| `POST /chatrooms` `Nudge` | nudge | held | confirm in the build that the Nudge row takes the processing flag |
| `POST /chatrooms` `ReferToSupport`, `ReportNoGroup` | report | allowed | User2Mod |
| `PATCH /chatmessages` | reply expected | own | |
| `DELETE /chatmessages` | delete own message | allowed | |
| `POST /chatmessages` `Approve` | moderator | allowed, basic button only | |
| `POST /chatmessages` `ApproveAllFuture` | changes the member's moderation status | refused | |
| `POST /chatmessages` `Reject`, `Hold`, `Release`, `Redact` | moderator | refused | |
| `PUT|POST|PATCH|DELETE /tryst` | handover arrangements | held | its chat messages carry the processing flag; its calendar mail waits |

**ChitChat**

| endpoint | what | treatment | note |
|---|---|---|---|
| `POST /newsfeed` post or reply | write | held | `hidden = NOW()` plus a `lockdown_holds` row |
| `POST /newsfeed` `Love`, `Unlove`, `Follow`, `Unfollow`, `Seen`, `SeenAll` | | own | |
| `POST /newsfeed` `Report` | report | allowed | |
| `POST /newsfeed` `Unhide` | moderator | allowed | the approve for ChitChat |
| `POST /newsfeed` `Hide`, `ConvertToStory`, `ConvertedToPost`, `AttachToThread`, `ReferToOffer`, `ReferToWanted`, `ReferToTaken`, `ReferToReceived` | moderator | refused | |
| `PATCH /newsfeed` | edit | refused | |
| `DELETE /newsfeed/:id` | delete own | allowed | |

**Memberships**

| endpoint | what | treatment | note |
|---|---|---|---|
| `PUT /memberships` | join | allowed | needed for replying; a join request to a group that approves members waits as a request |
| `DELETE /memberships` own | leave | allowed | |
| `DELETE /memberships` by a moderator, with or without `ban` | remove, ban | refused | |
| `PATCH /memberships` `Emailfrequency`, `Eventsallowed`, `Volunteeringallowed`, `Settings` | own | allowed | |
| `PATCH /memberships` `Role`, `OurPostingStatus` | moderator | refused | "moderated membership changes" |
| `POST /memberships` `Leave Member`, `Leave Approved Member`, `Happy`, `Unhappy`, `Fine` | own | allowed | |
| `POST /memberships` `Approve` | approve a join request | allowed, basic button only | |
| `POST /memberships` `Reject`, `Delete Approved Member`, `Ban`, `Unban`, `Hold`, `Release`, `ReviewHold`, `ReviewIgnore`, `ReviewRelease`, `HappinessReviewed` | moderator | refused | |

**Events, volunteering, noticeboards, stories**

| endpoint | what | treatment | note |
|---|---|---|---|
| `POST /communityevent`, `POST /volunteering` | create | held | `pending = 1` |
| `PATCH /communityevent`, `PATCH /volunteering` | edit or moderator approve | edit refused; approve allowed | |
| `DELETE /communityevent/:id`, `DELETE /volunteering/:id` own | | allowed | |
| `POST /noticeboard` | create | held | needs a hold state; confirm in the build |
| `PATCH /noticeboard` | edit | refused | |
| `DELETE /noticeboard/:id` | own | allowed | |
| `PUT /story`, `POST /story` | write a story | held | stories wait for review before publication; confirm in the build |
| `PATCH /story` | edit or review | refused | |
| `DELETE /story/:id` own | | allowed | |
| `POST /story/like`, `unlike` | | own | |

**Moderator tooling**

| endpoint | what | treatment | note |
|---|---|---|---|
| `POST|PATCH|DELETE /comment` | notes on members | refused | |
| `PATCH /group` | community settings | refused | |
| `POST|PATCH|DELETE /modtools/admin` | ADMIN broadcasts to whole communities | refused | the widest reach on the site; the mail would wait, but the row must not be created |
| `POST|PATCH|DELETE /modtools/modconfig`, `/modtools/stdmsg` | standard messages and configs | refused | |
| `POST /modtools/spammers` | report a spammer | allowed | lands as `PendingAdd` for Support |
| `PATCH|DELETE /modtools/spammers` | confirm, remove | refused | Support exempt |
| `PATCH /microvolunteering` | feedback | refused | |
| `POST /shortlink` | create a link | refused | |
| `PUT|POST|PATCH /locations` | map editing | refused | Support exempt |

**Support tooling (exempt)**

`POST /group`; `PUT|POST /modtools/alert`; `POST|DELETE /config/admin/concern_keywords`;
`PATCH /config/admin`; `POST /rippling/analytics/*`; `POST /admin/ai-images/*`;
`POST|PATCH|DELETE /team`; `POST|PATCH|DELETE /partnership/*`; `PUT /donations`,
`POST /donations/bulk`; `POST /charities`; `POST /locations/kml`. All behind Support or
Admin checks today, all continue.

**Downloads**

| endpoint | what | treatment |
|---|---|---|
| `POST /export`, `GET /export` | member's data export | refused: "Downloads are paused while we deal with a security incident." |
| `GET /modtools/user/:id/dump`, both auth paths | Support user dump | refused |
| `GET /modtools/spammers/export` | spammer list | refused |
| `GET /partnership/statsfile/:id` | stats file | refused |

**Own state, telemetry, uploads, payments**

| endpoint | treatment | note |
|---|---|---|
| `POST /abtest`, `/clientlog`, `/src`, `/scrolldepth`, `/job`, `/drivedistance`, `/notification/seen`, `/notification/allseen` | own | |
| `PUT|PATCH|DELETE /address`; `PUT|PATCH|DELETE /isochrone`; `POST|PATCH|DELETE /giftaid` | own | |
| `POST /image` | allowed | reaches nobody until attached to something that is held |
| `POST /microvolunteering` | not offered | member verdicts feed moderation; the GET returns no tasks while locked down |
| `POST /donateipn`, `/stripeipn`, `/stripecreateintent`, `/stripecreatesubscription` | allowed | webhooks must keep working; thank-you mail waits |
| `POST /housekeeper/*` | allowed | internal |

Not reached by the switch:

- Mail the relay has already accepted (10.14).
- The system chat messages the batch writes already processed (ModMail, Completed,
  Promised): no member text, and with approve limited to the basic button, no moderator
  text.
- Facebook, the support mailbox, Discourse.

### 10.6 Review, and who reads what

- Posts and ChitChat are public. Held items sit in the queues moderators already use,
  labelled "held by lockdown". Moderators approve one at a time. No privacy cost.
- Chat is private. Today a moderator reads a chat message only when a rule flagged it or
  the member is on moderation. Rule for the lockdown: **no person reads a held chat message
  unless the triage classed it risky or the recipient reported it.**
- Hard mode (the button): nothing processed, nobody reads anything. For the first minutes.
- Soft mode (switched to from the Support page once the triage has been looked at for this
  incident): triage every minute over new messages; spam dropped through `dropBlocked`;
  low risk processed normally, a minute's delay, never read; risky to the review queue
  with `reportreason = 'Lockdown'`, for the moderators of the groups involved, approve or
  report.
- Support sees counts and samples of the spam and risky sets. Never of the low set.
- `reviewedby` is recorded as now.
- **Nothing held is released without a person deciding.** Unreviewed risky chat stays in
  the queue; the existing `chats:review-pending` chases the moderators and rejects after
  seven days as for any held message; Support can release or reject a whole class from the
  page with the samples in front of them.
- Privacy page: one sentence that during a security incident messages may be held and a
  small number reviewed by volunteers (10.13).

### 10.7 Triage

`lockdown:triage`, every minute while active and once on lifting. One `lockdown_holds` row
per held chat message and post, with a class:

- **spam**: sender in `spam_users`; or account created during the window and the item
  carries a link or matches an incident phrase; or the item is in a cluster of five or more
  near-identical items (same folded opening line, or sidecar cosine similarity above the
  officer's threshold) whose senders include a marked spammer.
- **low**: account older than thirty days with an earlier reply or post; no link; not in
  any cluster of five or more; sender's rate over the last hour within their own history.
- **risky**: everything else.

- Posts: the class is a label and a count only. A post held for the group's own reasons is
  indistinguishable in the queue from one held by the lockdown, so lifting lets the content
  check re-decide every Pending post as it would have on the day. The label says which it
  would have promoted.
- Numbers are the calibration wave's (section 8): constants with their reasoning beside
  them, not settings.
- Incident phrases and addresses: entered on the Support page, applied from the next run,
  cleared when the lockdown ends (Twitch clears Shield Mode's ban phrases).

### 10.8 The notice

- Members: nothing, by default. Moderators: always the banner.
- The presser may choose one member notice instead:
  - delay: "Freegle is running slowly today. Messages and posts may take longer than usual
    to reach people."
  - security: "We're dealing with a spam attack. Messages may be delayed. If you received a
    message about vouchers or payments, please don't click the link."
- The security notice limits harm (the damage is done when the message is read) and worries
  people and tells the crew the site has noticed. A choice on the day, not a default.
- `LockdownNotice.vue` next to `MailDelayed` in `LayoutCommon.vue`, fed by `GET /lockdown`.

### 10.9 Lifting: the sequence

Any Support user, from a page that shows the counts at every step. People before content,
content before mail, downloads last.

1. **Understand.** Cause known; wave-specific holes fixed and deployed; new holds per
   minute back at baseline. The page shows all three.
2. **Triage** once more over everything held. Read the spam and risky counts and samples;
   add phrases and addresses; rerun until the spam set looks right.
3. **Mark the actors.** Spam set's senders into `spam_users`; `users:remove-spammers` and
   `chats:process-spam` remove them and warn the members they reached before the button.
   Their held items rejected: chat through `dropBlocked` (`reviewrejected = 1`, marked
   processed), posts to `Spam`, ChitChat deleted.
4. **Moderators back.** Mods surface lifted first, so people are in the queues before the
   queues fill. Banner changes to "lifting; the queues hold released items".
5. **Release low risk.**
   - Chat: processor takes held rows in id order, a few hundred a minute inside its
     one-minute iteration, normal checks; mail and push follow at that pace. Chat mail
     re-admits them by `lockdown_holds.releasedat`, as it does released rippling holds,
     because their `date` may be older than its look-back.
   - Posts: the content check promotes as it always did, a couple of hundred a minute so
     the immediate digests are paced, with `arrival` set to now so a post held three hours
     appears at the top of Browse and in the next digest.
   - ChitChat: `hidden` cleared for rows with a hold.
   - Chat mode goes to soft here if it was hard.
6. **Risky to review.** Chat processed with `reviewrequired = 1, reportreason =
   'Lockdown'`; posts stay Pending, labelled; ChitChat stays hidden, labelled. Moderators
   approve or report, one at a time. Nothing in this set is released by time.
7. **Push on.** Chat pushes are created by the processor as it works.
8. **Email on.** `mail:spool:purge-spammers` removes spooled mail from step 3's actors
   written before the button. Daemons resume. Deferral catch-up runs for what was attempted
   and skipped: one unread-chat summary per member owed one, one digest, stale post mails
   and periodics dropped. Chat notifications for step 5's messages go out as ordinary mail
   at the release pace. Untouched `background_tasks` email rows go out.
9. **Export on.**
10. **Notice off**, or "Things are back to normal" for a day.
11. **Close.** `endedby`, `endedat`, `endnote` on the row; closing report (10.10) to geeks@
    and, if chosen, Discourse; incident phrases cleared.

- Steps 4 to 10 are each a switch with its count beside it. A lift can stop part way. A
  surface can be pressed again alone if the wave resumes.
- **False alarm**: the same sequence with an empty spam set; the page offers "lift
  everything", which runs 4 to 10 in order. The cost is the delay, and 10.10 measures it.

### 10.10 Stats on impact

From `lockdown_holds`, `lockdown_counters` and `mail_suppressed_counts`.

**While on**: the Support page every minute, and the same numbers to geeks@ every hour,
which is also the reminder that it is on.

| | |
|---|---|
| held per surface | count, distinct members, oldest hold, new holds per minute against the same hour last week |
| triage | spam, risky, low counts; largest clusters with three samples each; accounts created during the window |
| not sent | emails by type, pushes, exports refused |
| moderation | actions refused and approvals made, by moderator; a moderator approving a wave is visible within a minute |
| time | pressed at, by whom, how long ago |

**On closing**: the same, plus:

| | |
|---|---|
| holds | rejected; released with no person reading; reviewed and approved; reviewed and rejected; still in review |
| delay | median and worst for released chat |
| mail | members caught up, and how |
| the justification | messages from the marked actors delivered before the press against messages from them held after |
| the curve | the replay fixtures (section 8) give the same numbers for the calibration wave pressed at each minute after the probe |

### 10.11 Tests and drills

- Unit tests at every gate, in the codebase that owns it: batch does not promote, process,
  render or send while held; Go refuses the listed actions and downloads and allows the
  rest; the cache; the triage classes; the catch-up.
- One cross-stack Playwright test: press; A replies to B; B sees nothing, A sees a sent
  message; a moderator cannot reject and can approve; lift; B sees it; exactly one email is
  spooled.
- Drills: full preset for ten minutes in yesterday monthly; push alone for five minutes in
  production quarterly at a quiet hour; both rows in `lockdowns` with reason "drill".
- Dependencies: the database and nothing else. Not the Go API (artisan path), not
  ModTools, not email (Sentry and the banner carry every change), not the model.

### 10.12 Where it goes

| piece | where |
|---|---|
| state | migrations `lockdowns`, `lockdown_holds`, `lockdown_counters`; `iznik-server-go/lockdown/lockdown.go` (five-second cache, `GET /lockdown`, `PATCH /lockdown`); `iznik-batch/app/Services/Lockdown/LockdownService.php`; commands `lockdown:on`, `off`, `status`, `triage`, `release`, `report` |
| chat | `ChatProcessService::processIncoming` (hard skip, soft triage, paced release); `reportreason` enum gains `Lockdown` (appended; MariaDB applies without a rewrite, confirmed on the live version first); `ChatNotificationService` re-admits by `lockdown_holds.releasedat` |
| posts | `ContentCheckService` guard and paced promotion; `AutoApproveService` guard; `message.go` direct-approve path and member edit path; Pending list label in `message_list.go` and ModTools |
| ChitChat | `newsfeed.go` `createPost` and `create.go` set `hidden` and write the hold row; `Edit` refuses; a "held" filter in the ModTools ChitChat view |
| profile, events, noticeboards, stories | the handlers in 10.5 refuse edits and set the pending flags |
| email | `MailSuppressionService::shouldSkip()` global branch, scope `lockdown`; `EmailSpoolerService::spool()` allowlist; `ProcessSpoolCommand` pause per iteration; `ProcessBackgroundTasksCommand` steps over email tasks; `SendPendingWelcomeMailsCommand` skip; `mail:spool:purge-spammers`; `DeferralCatchUpService` as is |
| push | `PushNotificationService` entry points |
| moderator gate | `lockdown.ModActionAllowed()` at the dispatch points in 10.5; approve refused when it carries a message; bulk approve refused; a fail-closed middleware on every other `/modtools` write; ModTools shows only the basic approve button and handles the 409 as `heldConflict.js` handles a held post |
| download gate | `export.go`, `userdump.go` (both auth paths), `spammers.go`, partnerships stats file |
| Support page | `modtools/pages/support`: a Lockdown tab first, red: press, preset, notice choice, incident phrases, live stats, the lift sequence as switches with counts, "lift everything", history |
| notice | `LockdownNotice.vue` in `LayoutCommon.vue`; the ModTools banner in `modtools/layouts/default.vue`; the traffic light in `ModStatus.vue`; fetched by `useNavbar` and `useModMe` |
| docs | `docs/ops/reference/spam-and-abuse.md` "Lockdown" section; a runbook under `docs/ops/` that is 10.9 plus the relay step of 10.14; one sentence on the privacy page |
| tests | Go and batch unit tests; `iznik-nuxt3/tests/e2e/lockdown.spec.js`; the drill rows |

### 10.13 Open questions, each with a default

Decided: nothing held is released without a person; no member notice unless chosen; no
expiry; any Support user presses and lifts; approve is the basic button.

| question | default | alternative |
|---|---|---|
| who reviews risky chat | the moderators of the groups involved, in the queue they use today; same exposure model, broader rule; Support alone is a bottleneck at 3am | Support only |
| the privacy page | one sentence: during a security incident messages may be held and a small number reviewed by volunteers | say nothing, as flagged messages are reviewed today without the page saying so |
| the user dump's API-key path | remove it, lockdown or not, unless the operator names what uses it | keep, refused during lockdown only |
| post edits and profile changes | edits to the pending-edits state; profile changes refused with the short message | leave both open and accept that a hijacked established account can put a link on a live post |
| identity changes during a lockdown | continue; section 3.5's audit records them | hold email-address changes if the next wave is hijacked accounts |
| mail already at the relay | a manual runbook step on the relay host (10.14) | make the button reach the relay, which adds a host it must depend on |
| drills | full preset monthly in yesterday; push alone quarterly in production | yesterday only, which never proves the production path |
| noticeboards and stories | give each a hold state if it reaches anyone without a person in between (confirm in the build) | refuse creation during a lockdown |

### 10.14 What an attacker can still do

| attack | what limits it | accepted because |
|---|---|---|
| detect the lockdown with two accounts and a test message | nothing; the member notice does not matter | the switch stops delivery, it does not deceive; once they know they stop (the aim) or move surface (the soft mode, the triage and per-surface switches); secrecy about surfaces buys the minutes before they test |
| approve their own wave with a hijacked moderator account | approve is one item at a time, in that moderator's groups, basic button only; approvals listed by moderator every minute; Support removes the role, an exempt action | visible before a dozen approvals |
| lift it with a hijacked Support account | every change mails geeks@ and raises Sentry at once; lifting is several switches, each doing the same; the batch-host command presses again without the API | bounded by how fast someone reads geeks@; the same account could do worse with Support tools, which is section 3.5's problem |
| use mail already sent | runbook step on the relay host: `postsuper -h ALL` to hold the queue; `postqueue -p` to list; `postsuper -d` by queue id for anything from or about the marked actors before release; `RelayQueueRecorder` shows what is there | out of the application's reach; already-read messages are the officer's "warn the recipients" (section 4, step 5) and `chats:process-spam` |
| reach members where the switch does not run | Facebook, the support mailbox, Discourse: the notice, if chosen, repeated by hand | not ours to hold |
| wait it out | lifting by surface and class; the soft mode; the young-account budget (section 5) is the next thing to build, as it holds a resumed wave without holding everyone | the lockdown's cost is every innocent chat delayed for its length; a crew that waits costs nothing to wait |
