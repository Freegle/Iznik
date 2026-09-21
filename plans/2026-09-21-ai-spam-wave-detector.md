# An AI-assisted detector for spam waves

Date: 21 September 2026. Design only; nothing here is implemented. Written the morning after
the voucher-phishing wave, using that wave as a calibration point and not as a template:
the next wave will use different addresses, different cadence, different words and possibly
a different surface. The design therefore keys on two things spammers cannot vary away
without losing their economics - **many actors doing the same thing at once**, and
**content that repeats across actors** - and uses a model only where variation has to be
absorbed: reading a cluster and saying what it is.

## 1. Why the existing layers are the wrong shape for a wave

Everything Freegle has today judges **one message at a time** against **rules written in
advance**: rspamd and SpamAssassin on inbound mail, `ContentCheckService` (keywords, URL
and phone checks, vague-item rules) on posts and chat, `ChatProcessService` holding or
dropping a chat message, moderators reviewing what is held. Each is fine for the thing it was
built for. None of them can notice that 400 accounts created in the last ten minutes have
each sent five near-identical messages, because none of them looks at more than one message
or more than one account.

The 20-21 September wave got through every layer for exactly that reason, and the
wave-specific reasons (a bare domain the URL check does not recognise, a keyword blinded
by the allowed word "shop", bold letters the literal matcher could not see) are already
fixed on master. Fixing them was necessary and is not a defence: they are the last wave's
tricks. `storage/scam-wave/wave-detect.php`, written during the incident, is the first
wave-shaped thing on the host, and it is a 15-minute cron that emails a human.

`docs/ops/reference/spam-and-abuse.md` records a measured negative result for AI
moderation of posts (63.5% versus 63.0% on approve/reject). That result stands and this
plan does not contradict it: it is about a different, easier judgement. "Is this post
acceptable" needs facts outside the text. "Are these 300 messages from 60 brand-new
accounts, all saying the same thing about vouchers, a coordinated scam" does not.

## 2. The calibration wave, in numbers

Measured on the live database on 21 September (details in the wave memory note and
`.claude-session.md`). A week of ordinary traffic, 13-19 September, is the baseline.

| signal | the wave | ordinary week |
|---|---|---|
| Interested replies per 10 minutes, 22:00-00:00 UTC | 2,200-2,600 for 90 minutes; a 20-account probe 20 minutes earlier | max 22, average 6-10 |
| account age at first reply | 4,399 of 4,428 under 30 seconds | 379 of 4,443 new accounts replied within 10 minutes (8.5%) |
| distinct posts one account replied to within an hour | 4,360 accounts: exactly 5, all within 60 seconds | 44 accounts a week at 5-9; 4 at 10+ |
| age of the post replied to | 77% older than 7 days | 12% older than 7 days |
| distinct opening lines across the messages | 11 across 21,977 | essentially every reply differs |
| email addresses | 4,584 of one pattern | - |

The last row is the one to be careful with. The pattern was a convenience for cleaning up,
and the next wave will not repeat it. Every other row is a property of *any* mass action
that a small crew or a script can produce: it must go fast, it must reuse text, it must use
fresh accounts, and it will pick targets by a rule rather than by interest. Those are the
signals, and each is measured against a rolling baseline rather than a constant.

## 3. Design

### 3.1 Principles

1. **Score actors and clusters, never a lone message.** A single message is judged by the
   existing rules. The detector adds a second axis: what else is happening now, by whom.
2. **Compare with the recent past, not with a number.** Every rate is a multiple of the
   same hour-of-week over the last four weeks, so a Sunday-evening lull and a Monday
   morning are each their own normal, and thresholds do not rot.
3. **Content similarity is semantic, not literal.** The folding matcher catches spellings
   of a known phrase. The detector groups messages that *mean* the same thing across
   accounts, using the embedding sidecar Freegle already runs (`EMBEDDING_SIDECAR_URL`,
   `POST /embed`, 40-90 ms a batch), so paraphrase, translation and template fill-ins land
   in one cluster.
4. **The model reads clusters, not messages.** One call per cluster, with a handful of
   representative texts and the aggregate facts (how many accounts, how old, how fast, what
   they replied to). It answers "what is this", not "is this member's post allowed".
5. **Actions are graduated, reversible and rate-limited, with a human gate that is lifted
   per action type as the false-positive record earns it.**
6. **Every surface, one mechanism.** Chat replies were this wave's surface. Posts, ChitChat,
   group joins and event or volunteering submissions are the same problem with different
   tables. The scoring is written once over an "actions" view and pointed at each.

### 3.2 Signals

Per actor (account), over its last hour, and per candidate cluster:

| signal | what it measures | wave / baseline separation |
|---|---|---|
| **velocity** | actions in the last 10 and 60 minutes; distinct targets (posts, rooms, groups) | 5 in 60 s, versus 44 accounts a week above 5 an hour |
| **youth** | account age at the action; whether it has ever done anything else (joined a group, posted, been replied to) | under 30 s, versus 8.5% of genuine new members replying within 10 minutes |
| **target profile** | age of the post replied to, distance from the actor's location (the routing engine already answers this for digests), whether the target is the actor's own community | 77% of targets older than a week, versus 12% |
| **similarity** | cosine similarity of the action's text to other actors' texts in the window (sidecar embeddings), and the folded-signature match `wave-detect.php` already does | 11 openings across 22k, versus all different |
| **global rate** | actions per 10 minutes on each surface, as a multiple of the hour-of-week baseline | 100x |
| **identity clustering** | shared email domain and local-part shape (entropy, length, letter/digit mix, not a fixed regex), signup burst (accounts per minute), shared signup IP or address range where logged | present, but treated as a weak, corroborating signal only |
| **content risk** | the existing concern-keyword hit, a link or bare domain that is not Freegle's, payment or voucher vocabulary, money amounts, urgency | the last wave's words; kept as a feature, never as the trigger |

Each signal is a z-score against its own rolling baseline. A cluster's score is the sum
over the actors in it, so a slow-drip wave (ten accounts an hour for a day) accumulates
the same way a burst does, just later.

### 3.3 Tiers

**Tier 0 - synchronous, cheap, in the Go API.** `chat/chatmessage.go CreateChatMessage`
(and the post, newsfeed and join creators) get a per-actor budget for accounts under 24
hours old: more than N distinct targets in M minutes flips the message to
`reviewrequired = 1` rather than delivering it. Held, not dropped: a genuine keen new
member is inconvenienced by a few minutes, a script is stopped before delivery. N and M come
from the baseline table (5 distinct posts in an hour is the 99.4th percentile of genuine
accounts) and are config, not code. This tier alone would have held 21,900 of the 21,977
messages before any mail went out.

**Tier 1 - every minute, in the batch, on the stream.** `ChatProcessService::processIncoming`
already touches every new chat message once a minute; the equivalent loops exist for posts
(`messages:contentcheck`) and memberships (`memberships:process`). A `WaveDetector` service
is called from each with the batch's rows and does the scoring of 3.2: velocity and youth
from the row and the account, similarity from a sidecar embedding of the batch plus the
last hour's cache (Redis), global rate from a per-surface counter. Actors and clusters over
threshold are written to a `spam_waves` table (cluster id, surface, first and last seen,
actor count, message count, representative ids, scores, status) and their pending rows are
**held** (`reviewrequired = 1`, a reportreason naming the wave) rather than delivered.
Holding is the only action Tier 1 takes on its own.

**Tier 2 - the model, on clusters only.** When a cluster crosses the review threshold, one
request to the metered Anthropic API (`ANTHROPIC_API_KEY`; the shared subscription token is
not suitable, it exhausts its week on one job): three representative messages, the
aggregate facts, and the question "coordinated scam or phishing, coordinated commercial
spam, coordinated but legitimate (an event, a campaign, a partner import), or unclear". The
answer, its reasoning and a confidence go into the `spam_waves` row. Cost is a few calls
per wave; the EEE pipeline already runs the same model at about a thirtieth of a cent a
call. A cluster the model calls legitimate is released (rows back to `reviewrequired = 0`)
and the release is logged; one it calls scam or spam moves to Tier 3. "Unclear" stays held
and goes to a moderator with the model's summary.

**Tier 3 - response, graduated and gated.** The actions are the ones done by hand on
21 September, as a service (`WaveResponseService`, from `storage/scam-wave/wave-respond.php`)
with each step separately switchable:

1. keep holding the cluster's rows (already done by Tier 1);
2. reject the held rows (`reviewrejected = 1`), so nothing is delivered or emailed;
3. add the cluster's shared domain or phrase as a block keyword (folded, so spelling
   variants are covered), which the existing backfill then applies across the board;
4. mark the actors as spammers (`spam_users`, collection Spammer, reason from the model's
   summary), which `users:remove-spammers` and `chats:process-spam` turn into removals and
   warnings to the members they contacted;
5. post the volunteer notice into affected rooms from the Freegle system user (id
   45136673, whitelisted), with the scammer's roster silenced so only the victim is told;
6. notify: email to geeks@, a Sentry event, a Discourse post with the cluster summary.

Steps 1, 2 and 6 run automatically from the start. Steps 3-5 start behind a moderator
confirmation (one click on the ModTools wave page, or a reply to the email) and are
promoted to automatic one at a time after a month with no false positive at that step.

**Tier 4 - learning.** Confirmed clusters keep their embeddings as prototypes, the way
`ContentEmbeddingService::isInnocentContext` keeps innocent and concerning prototypes now,
so the next variant of a known scam scores as similar on arrival with no keyword. Released
clusters are the negatives. A weekly job re-derives the baselines and reports drift.

### 3.4 What a moderator sees

A ModTools page under SysAdmin listing waves: status, surface, actor and message counts, the
model's one-line summary and confidence, the three sample messages, and the buttons for
steps 3-5 and for "release, this is legitimate". Releasing a cluster feeds Tier 4 and
raises the threshold for that shape.

### 3.5 Safety

- Never act on one signal. Velocity alone is a keen new member; similarity alone is a
  partner import or a group announcement copied to several rooms; a rate spike alone is a
  digest going out or a television mention.
- Whitelist by identity: the system user, TrashNothing partner accounts, moderators and
  support, and `spam_users` Whitelisted rows are never scored.
- Bound the damage: Tier 1 may hold at most X% of a surface's last-hour volume without a
  human; above that it alerts and stops holding new rows, on the principle that a runaway
  detector is a worse outage than a wave.
- Everything reversible: a held cluster is released with one action; rejected rows are
  restored by the same script in reverse; keywords added by the detector carry a tag so
  they can be found and removed.
- Dry-run first: the detector runs for two weeks writing `spam_waves` rows and sending the
  email, holding nothing, and its would-have-held counts are compared with what moderators
  actually rejected.

## 4. Where it goes in the code

| piece | where | notes |
|---|---|---|
| per-actor budget for young accounts | `iznik-server-go/chat/chatmessage.go`, `message/`, `newsfeed/`, group join | config in the Go env; hold, never drop |
| `WaveDetector` service | `iznik-batch/app/Services/Spam/WaveDetector.php` | called from `ChatProcessService`, `ContentCheckService` callers, `MembershipsProcessingService`; pure scoring, no side effects beyond `spam_waves` and holds |
| baselines | `iznik-batch/app/Services/Spam/WaveBaselines.php` + a nightly `spam:wave-baselines` command | hour-of-week rates and per-actor percentiles, kept in a small table |
| similarity | sidecar `/embed` via `ContentEmbeddingService::fetchEmbeddings`; last-hour vectors in Redis | reuse, do not add a second embedding path |
| model call | `iznik-batch/app/Services/Spam/WaveJudge.php` | metered API key; prompt and schema in one place; every call logged with cost |
| response | `iznik-batch/app/Services/Spam/WaveResponseService.php` | from `storage/scam-wave/wave-respond.php`; every step a method with a dry-run flag |
| table | Laravel migration for `spam_waves` (+ `spam_wave_members`) | migrations are the schema source of truth |
| moderator page | `iznik-nuxt3/modtools/pages/sysadmin/` + a Go endpoint under `/housekeeper` or a new `/spam/waves` | list, detail, release, confirm |
| ops doc | `docs/ops/reference/spam-and-abuse.md` | a "Waves" section between the content checks and human moderation; update the "no AI moderator" section to say what the model is and is not used for |
| tests | unit tests that replay synthetic waves built from the calibration table (burst, slow drip, paraphrased text, legitimate partner import, digest-hour spike) and assert what is held, what is released, and that the damage bound trips | the calibration numbers become fixtures, never thresholds |

The 15-minute `wave-detect.php` cron stays until Tier 1 has run in dry-run for two weeks
and matched it, then goes.

## 5. Rollout

1. Tier 0 first: it is a few lines in each creator, config-driven, holds rather than drops,
   and would have stopped this wave before delivery. Ship with the ModTools review queue
   showing the hold reason.
2. Tier 1 in dry-run: `spam_waves` rows and the email only. Compare with moderator actions
   for two weeks; tune baselines.
3. Tier 1 holding, Tier 2 judging, Tier 3 steps 1, 2 and 6 automatic, 3-5 behind
   confirmation.
4. Promote steps 3-5 one at a time on a clean month each.
5. Tier 4 prototypes and the weekly drift report.

## 6. Open questions for the operator

- Signup friction: there is no captcha and no signup rate limit anywhere today. A budget on
  young accounts (Tier 0) is the gentler answer; a challenge on a signup burst is the
  blunter one. Which, or both?
- Who confirms Tier 3 steps 3-5 out of hours: support volunteers, or the operator only?
- Should confirmed wave actors be banned outright (today: spammer plus membership removal,
  the account remains)?
- The nine wave senders whose addresses did not match the cleanup pattern are still not
  marked; the detector would have caught them by behaviour. Mark them now?
