---
last_reviewed: 2026-08-05
covers:
  - iznik-batch/app/Services/FirstReply/**
  - iznik-batch/app/Console/Commands/FirstReply/**
  - iznik-server-go/firstreply/**
  - iznik-server-go/chat/chatprompt.go
  - iznik-nuxt3/components/ChatMessagePrompt.vue
  - iznik-nuxt3/modtools/components/ModSysAdminFirstReply.vue
---

# Getting a First Reply In - Technical Reference

**44% of rippled posts get no reply at all.** From the poster's side, a post that is quietly
working and a post that has failed look exactly the same: nothing happens. That silence is
the problem this code exists to attack, from two directions - make a first reply arrive
sooner, and when there isn't one, make the wait informative rather than blank.

Everything here ships dark behind `freegle.firstreply.*`. With the switches off, none of it
runs and nothing else behaves differently.

## The two kinds of silence

**Manufactured silence** is when somebody did reply and the system held it. Rippling grows a
post's reach over days, and a reply from outside today's reach is parked in
`rippling_held_replies` until the reach catches up ([rippling-algorithm.md](rippling-algorithm.md)).
On a post that already has replies that is a fair trade. On a post with none it is not: the
replier is inside the reach the post will EVENTUALLY have, so they were always going to be
allowed to reply. Holding them changes when the poster hears, not whether.

**Real silence** is when nobody suitable has seen it yet - or has seen it but will not hear
until tomorrow's digest.

## 1. The first-reply passthrough

A post's first reply is never held, provided the replier is inside the reach the post will
eventually have.

`rippling_reach.polygon` is the reach a post has now. The routing server returns the whole
tick schedule at t=0, so the widest tick - what the reach becomes - is knowable immediately;
nothing stored it. `rippling_reach.max_polygon` now does, populated by a background pass
(`firstreply:maxreach`) rather than derived on demand, because some schedule entries carry no
inline geometry and have to be re-fetched from the routing server. A point-in-polygon test on
somebody's reply is not the place to discover that.

| Where | What |
|---|---|
| `iznik-batch/app/Services/FirstReply/MaxReachService.php` | populates and tests `max_polygon` |
| `iznik-batch/app/Services/Ripple/RippleReplyService.php` | `shouldHold()` consults the passthrough - email and TrashNothing replies |
| `iznik-server-go/firstreply/passthrough.go` | the same decision for in-app replies |

Both sides have to agree, because which door a reply came through is not something the poster
knows or should care about. Both are conservative at every step: switched off, `max_polygon`
not yet populated, a query error, or a post that already has repliers all mean "hold as
before". Being wrong in that direction costs a delay; being wrong the other way would deliver
a reply the reach never covers.

`freegle.firstreply.passthrough.max_existing_repliers` (default 1) is how many distinct
repliers a post may already have and still qualify. The poster talking on their own post does
not count.

## 2. Scouts

When a post has been quiet for `quiet_minutes`, pick a handful of members who look genuinely
likely to want THIS item and mail them now, ahead of their digest and ahead of the ripple
reaching them. How far ahead of their digest depends on what picked them - see
[below](#what-justifies-the-mail-decides-whether-it-may-be-an-extra-one).

Two problems, only one of which is about reach. Immediate mail on a rippling post goes only to
members with `emailfrequency=IMMEDIATE`; everyone on the daily digest hears tomorrow,
including the person two streets away who has replied to nine similar posts this year. And
separately, somebody with an open WANTED for exactly this item may sit outside today's
polygon and inside next Tuesday's.

Three signals, in `iznik-batch/app/Services/FirstReply/ScoutService.php`:

| Signal | Source | Weight |
|---|---|---|
| `wanted` | an open post of the **opposite** type whose subject matches | 5 |
| `search` | a saved search (`users_searches`) that matches | 3 |
| `frequent` | distinct Interested replies in 90 days, on this post's own communities | 1 |

`wanted` is type-aware on purpose: a WANTED matches an OFFER and vice versa. Somebody else
wanting the same thing you want is competition, not a lead.

The geographic bound differs by signal on purpose. `wanted` and `search` start from a small
national candidate set, so testing each against the eventual reach polygon is cheap and they
get the full benefit of it. `frequent` starts from members of the post's own communities,
because "every frequent replier in Britain" is not a set worth building to then discard
99.9% of.

Small is the point. Ten well-chosen people is a different product from "the digest, but
sooner", and `user_cooldown_hours` / `user_max_per_week` exist so that being good at replying
never turns into being punished for it. Scouts are written to `rippling_reach_notified` as
well as `firstreply_scouts`, so the reach mailer never sends the same post again later.

### What justifies the mail decides whether it may be an extra one

The two match signals and the propensity signal are held to different standards, because what
they claim is different.

| | `wanted` / `search` | `frequent` |
|---|---|---|
| What it claims | this member asked about **this thing** | this member replies to a lot of things |
| May be an extra mail? | yes | **no** |
| Consent gate | `users.relevantallowed` ("Suggested posts for you") | at least one community not set to "never" |
| Cadence gate | none | skipped if today's daily digest has already gone |

So a member who saved a search for "bookcase" can hear about a bookcase even if their digest
has been today, because they asked for that, item by item. A member who is merely a good
replier can only ever have their daily digest arriving **early**, never an additional mail.

"Today" is the London calendar day, using the same boundary as the daily digest's own
once-per-day guard. A rolling 24h window was rejected there because off-schedule sends make
the digest time drift later every day, and the two must agree on what "today" means.

A candidate dropped by either gate does not leave a hole: filtering happens **before** the
top-N cap, so the next-best candidate takes the slot and the post still gets its full
complement.

**A `frequent` scout's digest is then recorded as sent**, so today's does not also go out -
the mail genuinely replaces it rather than arriving alongside it. Only `lastsent` is stamped,
never the `lastmsgid` cursor: the member has not actually seen today's roll-up, so tomorrow's
must still start from where it would have. (The scouted post is not then duplicated in it,
because the daily digest excludes posts that have a `rippling_reach` row, which every scouted
post does.) Match-driven scouts are excluded from this - their mail was an extra they asked
for, and taking the digest away as well would be a straight loss.

The stamp is keyed on who was **actually mailed**, not who was picked, so a member whose spool
failed keeps both their digest and their eligibility for the reach mail. That is why
`mailPostToUsers` returns the ids it sent to rather than a count.

### The mail is the ordinary digest mail

A scout mail is byte-for-byte an immediate digest for that one post, via the shared
`spoolPostToRecipients` the reach mailer uses: same Mailable, same `MODE_IMMEDIATE`, same
`emailType: 'digest_immediate'`, and the same recipient checks (preferred address,
`browseMaxDistance` slider) so the two cannot drift. There is no scout-specific subject,
preamble or footer, and nothing in it reveals how the recipient was chosen. A member should not be able to tell a
scouted post from one the ripple reached normally, and nor should anyone they forward it to.

**One policy departure worth knowing about.** `UnifiedDigestService`'s reach mailer is
deliberately members-only, on the rule that cold-emailing someone about a community they have
not joined is not appropriate. `wanted` and `search` scouts can be members of a neighbouring
community, because they are not cold: they wrote down a WANTED for this item, or saved a
search for it. `frequent`, which carries no such request, stays inside the post's own
communities exactly as the reach mailer does. Any new signal has to clear the same bar - an
explicit request from the member, or membership.

## 3. The Freegle chat

Freegle has its own user account and talks to the poster in an ordinary `User2User` chat.

That choice buys the entire chat stack for free: delivery, email notification, push, unread
state, history, the app's chat UI. It costs having an account in the data set that is not a
person, so everything walking User2User chats has to skip it -
`FirstReply\FreegleUserService::isSystemUser()` is what those checks call.

### Prompts

A prompt is a chat message of type `Prompt` whose tappable part lives in `chat_prompts`, keyed
on the chat message id. The question text sits in `chat_messages.message` like any other
message, so email, push, mod review and search render something sensible knowing nothing about
prompts. A side table rather than columns on `chat_messages` because that table is one of the
largest here and a vanishing fraction of rows will ever be prompts.

| Kind | Asked when | Answering does |
|---|---|---|
| `photo` | 1.5h, no attachment | records intent; the button opens the post |
| `delivery` | 3h, OFFER without `deliverypossible` | sets `messages.deliverypossible` |
| `views` | 8h, once `views_min` people have opened it | nothing - it is information |
| `deadline` | 24h, no deadline set | sets `messages.deadline` |

`delivery` and `deadline` were modals fired the instant someone finished posting.
`DeliveryAskModal.vue` and `DeadlineAskModal.vue` are still in the tree and neither is wired
to anything, which is a fair verdict on that timing: at the moment of posting you are trying
to finish, and logistics for an item nobody has asked about yet is noise. Hours later, on a
post that has gone quiet, the same question is worth answering.

**Answering is implemented once, in Go** (`chat.AnswerChatPrompt`), because that is where the
member's tap arrives. A second implementation in the batch app would mean two versions of what
"by this weekend" means, drifting apart the first time either was touched. Only the person who
was asked may answer, only once, and the post update is matched on `fromuser` as well as id.

**Email carries the question and a link, never the buttons.** A one-click answer in a mail is
answerable by anyone who ever sees that mail, including a forwarded copy, and these answers
change what the member's post says. The extra step is deliberate, and it is why prompts are
rationed hard - each one has to be worth the trip.

### What Freegle will not say

The `views` prompt reports genuine page-opens (`messages_likes.pageview = 1`) and, when the
reach schedule can tell us, roughly how many more people the post is still going to reach. It
is rounded to the nearest 50 and omitted entirely when unknown, because a made-up number here
would be exactly the dishonesty the feature exists to avoid. No fabricated replies, no fake
accounts, no invented interest: a poster who chases a handover that was never real is worse
off than one who heard nothing.

### Not annoying people

The documented failure mode for helper bots in this space is Olio's: messages that cannot be
told apart from a real reply and cannot be turned off, which trains people to ignore the
notification that matters. Against that:

- `user_gap_hours` is per MEMBER, not per post, so clearing out a house does not start ten
  conversations at once.
- `max_per_post` caps how much any one post can generate.
- `users.settings.freeglechat = false` stops them entirely.
- Prompts expire (`expiry_days`) and stop offering buttons, but still render, because a
  conversation with holes in it is more confusing than a stale question.

Freegle chat messages DO count towards the unread badge and DO push, like any other message.
That is a deliberate product decision: these are messages to you, about your post, and burying
them in a quiet channel would mean nobody ever answers them.

## Schema

| Table | What |
|---|---|
| `rippling_reach.max_polygon` | the reach the post ends up with. NULL = not computed yet, and every reader falls back to current-reach behaviour |
| `chat_prompts` | options and answer for a `Prompt` chat message |
| `firstreply_scouts` | who was scouted about what, why, and whether they then replied (`replied_at`). Doubles as the fatigue ledger |
| `firstreply_prompts_sent` | which prompts a post has had. The `(msgid, kind)` unique key is what makes the cadence engine idempotent |
| `firstreply_passthroughs` | one row per reply let through, plus how long it would otherwise have waited (`waited_hours`, NULL until the sweep runs and when unanswerable) |
| `firstreply_event_metrics` | daily counters, same shape as `rippling_event_metrics` |

## Crons

All three are registered in `iznik-batch/routes/console.php` inside
`if (config('freegle.firstreply.enabled'))`.

| Command | Cadence | What |
|---|---|---|
| `firstreply:maxreach` | every minute | fills in `max_polygon`, and sizes recorded passthroughs. Kept out of `ripple:expand`, which is the hot single-writer loop |
| `firstreply:scout` | every 5 min | attributes replies to earlier scouts, then picks and mails new ones |
| `firstreply:engage` | every 5 min | sends the next due prompt |

Each takes `--dry-run`.

## Turning it on

`FIRSTREPLY_ENABLED` is the master switch; `FIRSTREPLY_PASSTHROUGH_ENABLED`,
`FIRSTREPLY_SCOUTS_ENABLED` and `FIRSTREPLY_CHAT_ENABLED` gate the three levers independently.
The Go API needs `FIRSTREPLY_ENABLED` and `FIRSTREPLY_PASSTHROUGH_ENABLED` too, since the
in-app reply path is enforced there.

Sensible order: turn on `FIRSTREPLY_ENABLED` alone first so `firstreply:maxreach` can drain,
then the passthrough (which needs `max_polygon` to do anything), then scouts, then chat.

## Measuring it

**ModTools → SysAdmin → First reply** (`/sysadmin?tab=firstreply`), served by
`GET /api/firstreply/metrics` (Support/Admin only, `iznik-server-go/firstreply/metrics.go`).

Each lever is shown against something, because a counter on its own says a number went up
without saying whether it was worth having:

| Lever | Read it as |
|---|---|
| Passthrough | first replies let through, and **how much earlier the poster heard because of it** - measured per reply, not guessed from a population |
| Scouting | reply rate and rehome rate **per signal**, so `wanted` / `search` / `frequent` can be compared directly. A signal that does not convert should be switched off rather than left spending mail |
| Freegle chat | answer rate per question, and how often the answer actually changed the post. "Collection only" and "no rush" are real answers that leave the post as it was, so they are counted separately from ones that did something |

### Sizing a passthrough

A count of passthroughs says the lever fired. It does not say whether firing was worth
anything, and the obvious proxy - the average hold duration across all held replies - is a
different population answering a different question.

The real number is per reply: for **this** replier, at **this** location, when would the
post's reach have got to them? That is knowable, because the routing server hands over the
whole tick schedule at t=0. Find the lowest tick whose polygon contains the replier, ask the
hazard schedule when that tick was due, and measure from when they actually replied.

Recording and sizing are split deliberately. Both the batch app (email/TrashNothing) and the
Go API (web/app) let replies through, so each does a cheap INSERT into
`firstreply_passthroughs`; one sweep in `MaxReachService::computePassthroughSavings` (run from
`firstreply:maxreach`) fills in `waited_hours` afterwards. Putting the tick-schedule geometry
in both would be the same non-trivial logic in two languages, drifting apart.

Two deliberate choices in that sweep:

- a replier already inside the tick the post had reached is sized at **0**, not discarded.
  Dropping the least impressive cases would quietly flatter the average.
- a replier no tick covers is left **NULL**, not 0, and the dashboard averages only the rows
  it could answer while showing how many it could not. An unknown saving is not a zero saving.

The dashboard also splits out how many of the sized replies would have arrived within a day
anyway - a passthrough that saves twenty minutes is worth much less than one that saves three
days, and a single average hides which kind these are.

### Which signal picked a scout

`firstreply_scouts.reason` records only the **strongest** signal that fired, so the `frequent`
row means "frequent and nothing else". That is the right denominator for deciding whether
propensity on its own earns its place: a member who also had a matching saved search is
counted under `search`, where the credit belongs.

Scout replies are attributed by a sweep in `firstreply:scout` rather than a hook on the reply
path, because replies arrive through four doors (web, app, email, TrashNothing) and none of
them knows or should know the replier was scouted. It is correlation, not proof - they replied
after we mailed them - which is why the rate is read next to the rehome rate rather than alone.
