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

Written on 21 September after the officer sections, from the code as it is on master and
from what other platforms do. Design only.

### 10.1 What it is

A button on the Support page, and a command on the batch host, that anyone with Support
tools can press. Freegle stays up. Members can still post, reply, chat and write on
ChitChat, and what they write looks sent to them. It reaches nobody. No email goes out. No
push goes out. Nothing can be downloaded. Moderators can see everything and do two things:
approve, and report a spammer. It stays that way until a person lifts it, surface by
surface, in an order that is written down. It is for hours, not days: there is no expiry,
because nothing should lift itself, and the costs of a long one are met by lifting surfaces
and classes one at a time rather than by the clock.

It needs no model, no baseline and no threshold. It needs a person who has noticed, and last
night that person existed within minutes of the probe. What they lacked was one thing to
press. At the wave's rate of 2,200 to 2,600 replies every ten minutes, each ten minutes
between noticing and pressing is another two thousand phishing messages read by people who
advertised a sofa.

The officer of sections 2 to 6 recommends this switch before it is trusted to press it, and
its response service (section 4) is the same hold and release, applied to a cluster instead
of the whole site.

### 10.2 What others do

- [Twitch Shield Mode](https://safety.twitch.tv/s/article/Protect-your-channel-with-Shield-Mode?language=en_US)
  (2022): safety settings prepared in advance and applied with one button or a slash
  command, by the streamer or a moderator, during a hate raid. Ban phrases entered while it
  is on are cleared when it is turned off. Lesson: prepare the bundle in calm, press it in a
  hurry, and leave nothing behind when it is lifted.
- [GitHub interaction limits](https://docs.github.com/en/communities/moderating-comments-and-conversations/limiting-interactions-in-your-repository)
  (2017): restrict who may comment or open issues by account age or prior contribution, for
  24 hours to six months, expiring by themselves; an organisation-level limit overrides
  repository ones. Lesson: an expiry is part of the switch, and the limit is by trust, not by
  everyone.
- [Discord Security Actions](https://support.discord.com/hc/en-us/articles/17439993574167-Activity-Alerts-Security-Actions):
  pause invites and pause direct messages, straight from the alert about unusual activity,
  time-limited, with a "mark as resolved" note for the other moderators. Lesson: pause rather
  than block, and write the note for whoever comes next.
- [Meta's "break glass" measures](https://www.techpolicy.press/we-know-a-little-about-metas-break-glass-measures-we-should-know-more/):
  a prepared list of reach reductions used around the 2020 election and 6 January, rolled
  back afterwards and argued over since because nobody outside knew what was in it. Lesson:
  the list is written before the emergency, and lifting is a decision recorded as carefully
  as pressing.
- [Reddit Crowd Control](https://mods.reddithelp.com/hc/en-us/articles/360038129231-Crowd-Control):
  comments from outsiders and new accounts are collapsed or held in the moderation queue
  until approved; moderators see them expanded and labelled. Lesson: the reader and the
  writer see different things, and the moderator view labels what was held and why.
- [Wikipedia pending changes](https://en.wikipedia.org/wiki/Wikipedia:Pending_changes):
  edits by new and unregistered users are saved and shown to the editor but hidden from
  readers until a reviewer accepts them; reviewers check that a change is broadly acceptable,
  not that it is correct; the protection is not to be applied before a problem exists.
  Lesson: this is exactly our shape, and the review standard is low and quick.
- [Twitter, 15 July 2020](https://en.wikipedia.org/wiki/2020_Twitter_account_hijacking):
  during the account hijack it blocked every verified account from tweeting for about two and
  a half hours and blocked password resets, with the message "To protect our users from
  spam and other malicious activity, we can't complete this action right now". Innocent
  people who had changed their password were locked out for days. Lesson: a manual,
  site-wide degraded mode is an ordinary incident tool even at that scale; blocking identity
  recovery has collateral; and when you must refuse, say so honestly.
- [Discourse read-only mode](https://meta.discourse.org/t/what-to-do-when-you-have-locked-yourself-out-by-invalid-sso-configuration-or-read-only-mode/89605)
  locks administrators out too, and needs a back door. Lesson: the people working the
  incident are exempt, and there is a way in that does not depend on the app.
- [Mastodon, February 2024](https://techcrunch.com/?p=2667481): the advice to server
  operators under a spam wave was to switch registration to approval and block disposable
  email providers. Lesson: a signup gate is the standard blunt lever; ours is in section 9.
- The [Google SRE chapter on cascading failures](https://sre.google/sre-book/addressing-cascading-failures/)
  asks of any degraded mode what enters it, automatically or by hand, and what it does once
  in. A [PostHog post-mortem from September 2025](https://posthog.com/handbook/company/post-mortems/2025-09-29-flags-is-down.md)
  records the feature-flag service itself failing and recovery delayed by "untested rollback
  procedures". Lesson: the switch must not depend on anything that is likely to be broken,
  and both directions are drilled.

### 10.3 Why it is cheap to build

Everything a member writes reaches other people through a loop in the batch, and every one
of those loops already has a "not yet" state that the front end shows to the author as if it
were done:

- A chat message is created with `processingrequired = 1` and stays visible only to its
  sender until `chats:process-incoming` (every minute) marks it processed
  (`chat/chatmessage.go` `CreateChatMessage`; the listing in `FetchChatMessages` shows the
  other party only rows with `processingsuccessful = 1`). The flags are blanked before they reach a
  participant, and there is no "held" wording in the member UI: the rippling hold badge is
  deliberately never shown to the sender, so their reply "should look like an ordinary sent
  message awaiting a response". Chat notification mail and push both require the message
  to be processed.
- Every post starts `Pending` (the comment at the top of the create path in
  `message/message.go` says so: the content check promotes clean posts), and
  `messages:contentcheck` (every minute) promotes it to `Approved` and into
  `messages_spatial`, which is the only thing Browse and search read. The poster's own posts
  are unioned in separately, "so that it is less obvious if a message is delayed for
  approval", and My Posts shows a Pending post exactly as it shows an Approved one. Email
  posts, TrashNothing posts and rippled copies take the same path.
- A ChitChat post with `hidden` set is returned to its author and to moderators and to
  nobody else, the author sees no notice (the "This has been hidden" text is mod-only), and
  reply notifications are skipped for hidden posts.
- Every outgoing email goes through one method, `EmailSpoolerService::spool()`, into a file
  spool drained by four daemons, and `MailSuppressionService::shouldSkip()` is already
  called before rendering in every per-recipient loop, recording what it skipped in
  `mail_suppressed_counts` so that `DeferralCatchUpService` can send the right catch-up
  later. That machinery was built for the Yahoo episode and its catch-up policy is already
  the right one: drop stale post mails and periodics, one digest, one chat summary.
- Every push goes through one class, `PushNotificationService`.
- Every moderator action is served by the Go API, and post actions already pass one gate,
  `dispatchPostMessageAction`, written so that "a new moderation action cannot silently skip
  the check by forgetting to call it". Membership, chat, group settings, comments, concern
  keywords and spammer actions each have one handler.
- Data leaves through four endpoints: the member's own GDPR export, the Support user dump
  (which also accepts an API key without a login), the spammer list export and the
  partnership stats file.

So the switch is mostly the batch declining to move things, plus a few refusals in the Go
API, plus a place to read the state. The one surface the API delivers directly, ChitChat,
needs one line at creation.

### 10.4 The state

A table `lockdowns`, append-only, one row per change, so the history is the audit:
`id`, `active`, `surfaces` (JSON: `chat`, `chat_mode` hard or soft, `posts`, `chitchat`,
`events`, `email`, `push`, `export`, `mods`), `reason`, `notice` (text or null),
`notice_audience` (`none`, `mods`, `members`), `startedby`, `startedat`, `endedby`,
`endedat`, `endnote`. The current state is the newest row. Two companions:
`lockdown_holds` (`id`, `lockdownid`, `kind`, `refid`, `userid`, `created`, `risk` low,
risky or spam, `releasedat`, `outcome`), written by the triage loop for chat and posts and
by the Go API at creation for ChitChat (the only way to tell a lockdown-hidden post from one
hidden because its author is suppressed, since both have `hiddenby` null), and
`lockdown_counters` (`id`, `lockdownid`, `kind`, `count`) for what was refused or not sent.

There is no expiry. A lockdown is designed to last a few hours, the hourly report of 10.10
is the reminder that one is on, and the soft chat mode of 10.6 is how a longer one keeps
the site useful. Nothing lifts by itself, because lifting into an empty room at four in the
morning releases the wave to nobody watching.

The Go API reads the newest row through a five-second in-memory cache (the
`browsecount/cache.go` pattern), so the gates cost nothing per request; if a read fails it
keeps the last state it saw, and a process that has never read one treats the site as open,
which only happens when the database is unreachable and nothing else works either. The
batch reads it at the top of every loop iteration, not once per process, because the spool
daemons and the scheduler run for days. Neither the shared `config` table nor an environment
variable is used: the first has no history and a public-read allowlist that would leak
which surfaces are held, and the second needs a restart in each container.

Members' clients learn only whether there is a notice and what it says, from a public
`GET /lockdown` fetched with the navbar's sixty-second pass. They never learn which surfaces
are held; an attacker with an account must not be able to read the switch. ModTools gets
the full state on its thirty-second work poll, and the platform traffic light it already
shows turns red. Setting and lifting are `PATCH /lockdown` behind the existing
`RequireSupportOrAdminMiddleware`, open to any Support or Admin user, and
`php artisan lockdown:on|off|status` on the batch host, which still works when ModTools or
the API is what is broken or what an attacker holds. Every change writes a row, sends a
Sentry event and mails geeks@ at once.

### 10.5 What the switch does, surface by surface

| surface | the member sees | what happens | where |
|---|---|---|---|
| chat (User2User) | an ordinary sent message | the processor leaves `processingrequired = 1` rows alone; nothing is delivered, mailed or pushed. Replies by email and LoveJunk replies enter the same state | `ChatProcessService::processIncoming`, skipping User2User while chat is held; User2Mod and Mod2Mod keep flowing so member reports reach volunteers |
| chat, soft mode | as above | the processor runs, and the triage of 10.7 decides each message: spam is dropped through the existing `dropBlocked` path, risky goes to the review queue with `reportreason = 'Lockdown'`, low risk is processed normally | same, plus one new `reportreason` value (appended to the enum, which MariaDB applies without rewriting the table; confirmed on the live version before the migration runs) |
| posts | their post in My Posts, as always | nothing is promoted from `Pending`; auto-approve does not run; the direct-approve path for unmoderated members is forced to `Pending` | `ContentCheckService` promote guard, `AutoApproveService` guard, `message.go` where `collection` becomes `Approved` on `PUT /message` |
| post edits | their edit, on their own view | an edit to a live post goes to the pending-edits state that `ApproveEdits` already serves, whatever the member's moderation status | the edit path in `message.go` |
| ChitChat | their post and replies, as always | created with `hidden = NOW()` and a `lockdown_holds` row; no reply notifications | `newsfeed.go` `createPost`, `create.go` |
| ChitChat edits, profile text, avatar | "Changes are paused for a few hours while we deal with a security incident." | refused; rare, visible to others, and cheaper to refuse honestly than to queue | the edit and profile handlers |
| events, volunteering | their submission | created with `pending = 1` | the Go creators |
| email | nothing | `shouldSkip()` answers yes for everyone, recording the count by type; `spool()` refuses anything not on the allowlist; the spool daemons pause, so mail spooled just before the button waits and can be purged; `background_tasks` email rows are stepped over and left untouched, not attempted, so the queue keeps moving and nothing counts as failed; the welcome mail command does not run at all, because its cursor advances past anyone it skips | `MailSuppressionService`, `EmailSpoolerService::spool()` and `processSpool()`, `ProcessBackgroundTasksCommand`, `SendPendingWelcomeMailsCommand` |
| email allowlist | still arrives | verify address, sign-in link and forgot password (a member locked out is harm, and these were what Twitter regretted blocking), the lockdown's own reports to geeks@ and Support, backup failure | a short list in code, not configuration |
| push | nothing | every entry point returns 0 and counts | `PushNotificationService` |
| export | "Downloads are paused while we deal with a security incident." | refused, including the user dump's API-key path | `export.go`, `userdump.go`, `spammers.go` export, partnership stats file |
| moderator actions | ModTools banner: "Freegle is in lockdown since 22:41, started by <name>. You can approve and report spammers. Everything else is paused." | approve (posts, chat, member requests, events), unhide (ChitChat) and `POST /modtools/spammers` are allowed, one item at a time; ModTools offers only the basic approve button, with the approve-with-message controls hidden, and the API refuses an approve that carries a message rather than stripping it, so a stale client cannot send one; bulk approve, including the one in the chat review page, is refused; every other write returns 409 with a lockdown status; Support and Admin are exempt | `lockdown.ModActionAllowed()` in `dispatchPostMessageAction`, the membership action switch, the chat action switch, `group.go` PATCH, comments, concern keywords, spammer PATCH and DELETE; and a fail-closed middleware on every other `/modtools` write, allowlisting the two |
| signups, joins, logins, identity changes | as always | continue; accounts created during the window are counted and their holds start as risky | nothing |

Not held in the first version, with the reason: group joins (needed for replying, and the
join notifications are mail, which is stopped); inbound mail parsing (it creates Pending
posts and unprocessed chat rows, which the holds above cover); the system chat messages the
batch writes already processed (ModMail, Completed, Promised), which carry no member text
and, with approve limited to the basic button, no moderator text either. Stories and
noticeboards are checked during the build and given a hold if either reaches anyone without
a person in between. One thing the switch cannot reach: mail the relay has already
accepted, which only a hold on the relay's own queue can stop (10.14).

### 10.6 Review, and who reads what

Posts and ChitChat are public, and holding them in the Pending queue and the hidden state
is the normal moderation path made universal. Moderators see them in the queues they
already use, labelled "held by lockdown", and approve them one at a time. That is the
review the moderators are for, and it costs nothing in privacy.

Chat is different. Today a moderator reads a chat message only when a rule flagged it or the
member is on moderation. Sending every chat message on the site to the review queue would
turn that into moderators reading everyone's private messages for the duration, and a wave
would put twenty thousand phishing messages in front of them. So chat has two modes, and
the rule is the same in both: **no person reads a chat message the lockdown held unless the
triage classed it as risky or the recipient reported it.**

- **Hard** (what the button does when pressed): nothing is processed and nobody reads
  anything. This is the mode for the first minutes, when nobody knows what is happening.
- **Soft** (switched to from the Support page once the triage's classification has been
  looked at for this incident): the triage runs every minute over new messages; spam is
  dropped, low risk flows as normal, risky goes to the review queue for the moderators of
  the groups involved, who approve or report. A member's ordinary chat is delayed by a minute
  and never read; a phishing message is never delivered; the residue gets a human.

What is risky is decided by the triage of 10.7, and its classification is shown to Support
with samples of the spam and risky sets, never of the low-risk set. Review of held chat
records `reviewedby` as it does now. **Nothing held is released without a person deciding.**
Risky chat that no moderator reviews stays in the queue, where the existing
`chats:review-pending` chases the moderators and rejects after seven days as it does for
any held message; and Support can release or reject a whole class from the page with the
samples in front of them. The privacy page gains one sentence saying that during a security
incident messages may be held and a small number reviewed by volunteers (10.13).

### 10.7 Triage

A batch command, `lockdown:triage`, run every minute while a lockdown is active and once on
lifting. For each held chat message and post it writes a `lockdown_holds` row with a class:

- **spam**: the sender is in `spam_users`, or the account was created during the window and
  the item carries a link or matches a phrase Support has entered for this incident, or the
  item is in a cluster of five or more near-identical items (same folded opening line, or
  cosine similarity from the embedding sidecar above the threshold used by the officer)
  whose senders include a marked spammer.
- **low**: the account is older than thirty days with at least one earlier reply or post,
  the item carries no link, it is not in any cluster of five or more, and the sender's rate
  over the last hour is within their own history.
- **risky**: everything else.

For posts the class is a label and a count only. A post held for the group's own reasons
(moderated group, moderated member, no location) is indistinguishable in the queue from one
held by the lockdown, so posts are never released by the triage: lifting lets the content
check run again, and it re-decides every Pending post exactly as it would have on the day.
The label says which posts it would have promoted.

The numbers are the calibration wave's (section 8) and are constants in the service with
their reasoning beside them, not settings. Support can enter phrases and addresses for the
incident on the Support page, which the triage applies from its next run; they are cleared
when the lockdown ends, as Twitch clears Shield Mode's ban phrases.

### 10.8 The notice

Members see nothing by default; moderators always see the banner. The presser may choose
one of two pre-written member notices instead of none:

- a delay notice: "Freegle is running slowly today. Messages and posts may take longer than
  usual to reach people."
- a security notice: "We're dealing with a spam attack. Messages may be delayed. If you
  received a message about vouchers or payments, please don't click the link."

The second is the one that limits harm, because the damage from a scam is done when it is
read, and it is also the one that worries people and tells the attacker the site has
noticed. That is why it is a choice on the day and not a default. Rendered by a
`LockdownNotice` component next to `MailDelayed` in `LayoutCommon.vue`, fed by
`GET /lockdown`.

### 10.9 Lifting: the sequence

Lifting releases what was held, so it is the dangerous direction, and it is done by any
Support user from a page that shows the counts at every step. The order matters: people
before content, content before mail, and the door data leaves through last.

1. **Understand.** The cause is known, the wave-specific holes are fixed and deployed, and
   the rate of new holds per minute is back at the baseline. The page shows those three
   things and the Support user decides.
2. **Triage** runs once more over everything held. Support reads the spam and risky counts
   and samples, adds phrases and addresses, and reruns until the spam set looks right.
3. **Mark the actors.** The spam set's senders are added to `spam_users`; the existing
   `users:remove-spammers` and `chats:process-spam` remove them and warn the members they
   reached before the button. Their held items are rejected: chat through `dropBlocked`
   (`reviewrejected = 1`, marked processed so nothing picks it up later), posts to `Spam`,
   ChitChat deleted. Nothing of theirs is ever delivered or mailed.
4. **Moderators back.** The mods surface is lifted first so that people are in the queues
   before the queues fill. The ModTools banner changes to say the lockdown is lifting and
   the queues hold released items.
5. **Release low risk.** Chat: the processor takes the held rows in id order at a bounded
   rate (a few hundred a minute, inside its existing one-minute iteration budget) with the
   normal checks, so the mail and push that follow are paced too; the chat notification
   mail re-admits them by `lockdown_holds.releasedat`, as it already does for released
   rippling holds, because their `date` may be older than its look-back. Posts: the content
   check promotes as it always did, at most a couple of hundred a minute so the immediate
   digests are paced, with `arrival` set to now so a post held for three hours appears at
   the top of Browse and in the next digest rather than three hours down. ChitChat:
   `hidden` cleared for rows with a hold. The chat mode goes to soft at this step if it was
   hard.
6. **Risky to review.** Chat is processed with `reviewrequired = 1, reportreason =
   'Lockdown'`; posts stay Pending with the label; ChitChat stays hidden with the label.
   Moderators approve or report, one at a time. Nothing in this set is released by the
   passage of time.
7. **Push on.** Chat pushes are created by the processor as it works, so they follow the
   paced release.
8. **Email on.** First `mail:spool:purge-spammers` removes any spooled mail from step 3's
   actors that was written before the button. Then the daemons resume and the deferral
   catch-up runs for what was attempted and skipped: one unread-chat summary per member
   owed one, one digest, stale post mails and periodics dropped. Chat notifications for
   messages released in step 5 go out as ordinary mail, paced by the release. The untouched
   `background_tasks` email rows go out.
9. **Export on.**
10. **Notice off**, or changed for a day to "Things are back to normal."
11. **Close.** The `lockdowns` row gets `endedby`, `endedat` and `endnote`; the closing
    report of 10.10 goes to geeks@ and, if the operator chooses, to Discourse; the
    incident's phrases are cleared.

Steps 4 to 10 are each a switch on the page with its count beside it, so a lift can stop
part way, and so a surface can be pressed again on its own if the wave resumes.

**A false alarm** is the same sequence with an empty spam set: the triage finds nothing,
step 3 has nothing to do, and the page offers "lift everything" which runs steps 4 to 10 in
order. The cost of a mistaken press is the delay, and 10.10 measures it.

### 10.10 Stats on impact

Two views, both from `lockdown_holds`, `lockdown_counters` and `mail_suppressed_counts`.

**While it is on**, the Support page refreshes every minute, and the same numbers go to
geeks@ every hour, which is also what reminds everyone that it is on:

| | |
|---|---|
| held per surface | count, distinct members, oldest hold, and the rate of new holds per minute against the same hour last week |
| triage | spam, risky and low counts; the largest clusters with three samples each; accounts created during the window |
| not sent | emails by type, pushes, exports refused |
| moderation | actions refused and approvals made, by moderator, so Support can see anyone hammering a button or approving a wave within a minute of it starting |
| time | pressed at, by whom, how long ago |

**On closing**, a report with the same numbers plus what happened to the holds (rejected,
released without a person reading, reviewed and approved, reviewed and rejected, still in
review), the delay distribution for released chat (median and worst), the number of members
whose mail was caught up and how, and the comparison that justifies the button: messages
from the marked actors delivered before it was pressed against messages from them held
after. The replay fixtures of section 8 give the same numbers for the calibration wave
pressed at each minute after the probe, so the cost of a slow press is a known curve rather
than an argument.

### 10.11 Tests and drills

- Unit tests at every gate, in the codebase that owns it: the batch does not promote,
  process, render or send while held; the Go API refuses the listed actions and exports and
  allows the two; the cache; the triage classes; the catch-up.
- One cross-stack Playwright test: press; member A replies to member B; B sees nothing and
  A sees a sent message; a moderator cannot reject and can approve; lift; B sees it; exactly
  one email is spooled.
- A drill, because an untested switch is the one that fails: the full preset for ten
  minutes in the yesterday environment monthly, and push alone for five minutes in
  production quarterly at a quiet hour, both recorded in `lockdowns` with the reason "drill".
- The switch depends on the database and nothing else: not on the Go API (the artisan path),
  not on ModTools, not on email (Sentry and the Support banner also carry every change),
  and not on the model.

### 10.12 Where it goes

| piece | where |
|---|---|
| state | migrations for `lockdowns`, `lockdown_holds`, `lockdown_counters`; `iznik-server-go/lockdown/lockdown.go` (state with a five-second cache, `GET /lockdown`, `PATCH /lockdown`); `iznik-batch/app/Services/Lockdown/LockdownService.php`; commands `lockdown:on`, `off`, `status`, `triage`, `release`, `report` |
| chat | `ChatProcessService::processIncoming` (hard skip, soft triage, paced release); `reportreason` enum gains `Lockdown`; `ChatNotificationService` re-admits by `lockdown_holds.releasedat` |
| posts | `ContentCheckService` guard and paced promotion; `AutoApproveService` guard; `message.go` direct-approve path and the edit path; the Pending list label in `message_list.go` and ModTools |
| ChitChat | `newsfeed.go` `createPost` and `create.go` set `hidden` and write the hold row; the edit handler refuses; a "held" filter in the ModTools ChitChat view |
| profile | the profile text and avatar handlers refuse with the short message |
| email | `MailSuppressionService::shouldSkip()` global branch with scope `lockdown`; `EmailSpoolerService::spool()` allowlist; `ProcessSpoolCommand` pause per iteration; `ProcessBackgroundTasksCommand` steps over email tasks; `SendPendingWelcomeMailsCommand` skip; `mail:spool:purge-spammers`; `DeferralCatchUpService` as is |
| push | `PushNotificationService` entry points |
| moderator gate | `lockdown.ModActionAllowed()` at the seven dispatch points in 10.5, approve refused when it carries a message, bulk approve refused, plus a `/modtools` write middleware; ModTools shows only the basic approve button while locked down and handles the 409 the way `heldConflict.js` handles a held post |
| export gate | `export.go`, `userdump.go` (both auth paths), `spammers.go`, partnerships stats file |
| Support page | `modtools/pages/support`: a Lockdown tab first, red: press, preset, notice choice, incident phrases, live stats, the lift sequence as switches with counts, "lift everything" for a false alarm, history |
| notice | `LockdownNotice.vue` in `LayoutCommon.vue`; the ModTools banner in `modtools/layouts/default.vue` and the traffic light in `ModStatus.vue`; fetched by `useNavbar` and `useModMe` |
| docs | `docs/ops/reference/spam-and-abuse.md` gains a "Lockdown" section; a runbook page under `docs/ops/` that is the lift sequence plus the relay-queue step of 10.14; one sentence on the privacy page |
| tests | Go and batch unit tests; `iznik-nuxt3/tests/e2e/lockdown.spec.js`; the drill entries |

### 10.13 Open questions, each with a default

Decided while writing: nothing held is released without a person; members see no notice
unless the presser chooses one; there is no expiry; any Support user can press and any
Support user can lift. What remains, with the default that applies unless the operator says
otherwise:

- **Who reviews risky chat.** Default: the moderators of the groups involved, in the queue
  they already use, because that is today's exposure model with a broader rule, and Support
  alone is a bottleneck at three in the morning. The alternative is Support only.
- **The privacy page.** Default: add one sentence saying that during a security incident
  messages may be held and a small number reviewed by volunteers. The alternative is to say
  nothing, on the ground that flagged messages are already reviewed and the page does not
  say that either.
- **The user dump's API-key path.** Default: remove it outright, lockdown or not, unless the
  operator names what uses it. A login-free door to every table about a member is not
  something a lockdown should have to think about.
- **Post edits and profile text.** Default: as in 10.5, edits to the pending-edits state and
  profile changes refused with the short message. The alternative is to leave both open in
  the first version and accept that a hijacked established account can put a link on a live
  post.
- **Identity changes during a lockdown.** Default: they continue, because blocking recovery
  is what Twitter regretted, and section 3.5's audit prerequisite records them. If the next
  wave is hijacked accounts, hold email-address changes only.
- **Mail already at the relay.** Default: a manual runbook step on the relay host (10.14),
  not part of the button, because the button must not depend on reaching another host.
- **Drills.** Default: the full preset monthly in yesterday, push alone quarterly in
  production at a quiet hour. The alternative is yesterday only, which never proves the
  production path.

### 10.14 What an attacker can still do

Written against the design above, so that what is accepted is accepted on purpose.

- **Detect the lockdown.** Two accounts and a test message reveal within a minute that
  nothing is being delivered, whatever the member notice says. The switch is not there to
  deceive the crew; it is there to stop delivery. Once they know, they stop, which is the
  aim, or move surface, which the soft mode, the triage and the per-surface switches are
  for. The secrecy about which surfaces are held buys the minutes before they test.
- **Approve their own wave with a hijacked moderator account.** Approve is one item at a
  time, in that moderator's groups only, without a message to the poster, and the page
  lists approvals by moderator every minute, so a moderator approving a wave is visible
  before it has approved a dozen. Support removes the moderator's role, which is an
  existing Support action and exempt from the gate.
- **Lift it with a hijacked Support account.** Every change mails geeks@ and raises a
  Sentry event at once, lifting is several switches each of which does the same, and the
  batch-host command presses it again without the API. The cost of the window is bounded by
  how quickly someone reads geeks@; the same account could do far worse things with Support
  tools than lift a lockdown, and that is section 3.5's problem, not this one's.
- **Use what was already sent.** Mail the relay accepted before the button is out of reach
  of the application. The runbook's first step on the relay host is to hold its queue
  (`postsuper -h ALL`) and, before releasing it, delete what is from or about the marked
  actors (`postsuper -d` by queue id from a `postqueue -p` listing); the relay queue reader
  the batch already has (`RelayQueueRecorder`) shows what is there. Messages already read
  are the officer's "warn the recipients" action (section 4, step 5), and the existing
  `chats:process-spam` warning covers everyone a marked actor contacted.
- **Reach members somewhere the switch does not run.** The Facebook page, the support
  mailbox and Discourse, which are not ours to hold and where the notice, if chosen, is
  repeated by hand.
- **Wait it out.** The lockdown's cost is every innocent chat delayed for its length, and a
  crew that pauses until it is lifted costs nothing to wait. That is why lifting is by
  surface and by class, why the soft mode exists, and why the young-account budget of
  section 5 is the next thing to build: it is the one that holds a resumed wave without
  holding everyone.
