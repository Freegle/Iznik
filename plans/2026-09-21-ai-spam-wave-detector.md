# An AI duty officer for spam waves

Date: 21 September 2026. Design only; nothing here is implemented. Written the morning after
the voucher-phishing wave. The wave is used as one worked example. It is not a template: the
next crew will change their addresses, their timing, their wording, their targets and
possibly the surface they use, and any detector built from this wave's shape will be looking
the wrong way. What does not change is that a wave is somebody using Freegle for something
Freegle is not for, at a scale one person's ordinary use never reaches. Recognising that
takes judgement about intent and plausibility, which is what a model is for, and it cannot
be reduced to thresholds without becoming the next thing to be dodged.

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
   cautious moderator would do and it costs a genuine member minutes.
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
