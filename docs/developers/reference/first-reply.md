---
last_reviewed: 2026-08-17
covers:
  - iznik-batch/app/Services/FirstReply/**
  - iznik-batch/app/Console/Commands/FirstReply/**
  - iznik-server-go/firstreply/**
  - iznik-server-go/message/searchmatches.go
  - iznik-server-go/chat/chatprompt.go
  - iznik-nuxt3/components/ChatMessagePrompt.vue
  - iznik-nuxt3/components/ChatPromptPost.vue
---

# Getting a First Reply In - Technical Reference

**44% of rippled posts get no reply at all.** From the poster's side, a post that is quietly
working and a post that has failed look exactly the same: nothing happens. That silence is
the problem this code exists to attack, from two directions - make a first reply arrive
sooner, and when there isn't one, make the wait informative rather than blank.

Everything here ships dark behind `freegle.firstreply.*`. With the switches off, none of it
runs and nothing else behaves differently.

**The Freegle chat (lever 3) is currently switched OFF** - `freegle.firstreply.chat.enabled` is
hard `false` in `config/freegle.php` and no longer reads its env var, so it cannot come back on
from a deployed environment file. Nothing has been dismantled: §3 below describes what runs when
the flag goes back on. Sending is all that stops - `EngagementService` is the single writer and
returns immediately - while answering stays live, so prompts already sent are not left with dead
buttons.

**It also rolls out by percentage.** `freegle.firstreply.rollout_percent` (default **0**)
buckets on `CRC32(msgid . '|firstreply') % 100`, so a post is in or out for its whole life and
across **all three levers at once** - a post in the trial gets the passthrough, match mail and
the Freegle chat, and one outside gets none of them. Split per lever instead and the arms
overlap, so nothing could be attributed to anything. Raising the percentage only ever adds
posts, so a trial widens without shuffling anyone out of the arm they were being measured in.
A hash rather than a raw `msgid % 100` because ids are minted under Galera's
`auto_increment_increment` stride - a raw modulus is only uniform while the stride stays
coprime with the bucket count, and a cluster resize would silently skew the split. PHP
`crc32`, MySQL `CRC32()` and Go `crc32.ChecksumIEEE` share the same polynomial; pinned tests
on each side (`RolloutTest.php`, `TestRolloutBucketPinnedCrossLanguage`) hold the three
expressions together. Check a post by eye with
`SELECT CRC32(CONCAT(msgid, '|firstreply')) % 100`.

**The trial arm decides which QUESTIONS a post gets, not whether Freegle speaks to it at all.**

| Question | Holdout | In trial |
|---|---|---|
| `delivery` | yes | yes |
| `deadline` | yes | yes |
| `photo` | no | yes |
| `views` | no | yes |

The split is whether the question changes the post itself. `delivery` and `deadline` set
`messages.deliverypossible` and `messages.deadline`, which everyone browsing then sees - a
product improvement in its own right, and not what this trial is measuring. Withholding them
from the holdout arm would cost those posters something real and buy no experimental
cleanliness, so they are asked of everyone. `photo` and `views` are about how the wait FEELS,
which is exactly what is being measured, and neither changes anything a browser can see.

The consequence for analysis is worth stating plainly: the comparison is **not** "chat versus
nothing". It measures the passthrough, match mail and the two reassurance prompts. Any effect of
the delivery and deadline questions themselves is present in both arms and therefore invisible
to it.

The filter lives in `EngagementService::applicable()` rather than on the candidate queries,
because prompts are per-MEMBER while the rollout buckets per-POST: a member with some posts in
the trial and some out gets the trial-only questions covering just their in-trial posts, and the
universal ones covering all of them.

The default of 0 means switching a lever on does nothing until a percentage is set as well.
That is deliberate: forgetting the percentage costs a quiet run whose cron log says exactly
why, where the opposite default would cost an unplanned full-network rollout of something that
sends mail. Both `firstreply:matchmail` and `firstreply:engage` print the active percentage every
run. The Go API reads the same `FIRSTREPLY_ROLLOUT_PERCENT` and buckets identically, because
otherwise a post would be in the trial for an emailed reply and out of it for an in-app one.

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

## 2. Match mail

As soon as a post with no reply is seen, find the members who have **asked for that item** and
mail them about it now, ahead of their digest and ahead of the ripple reaching them.

`quiet_minutes` defaults to **0**. Waiting to avoid spending mail on posts about to get a reply
anyway does not survive the timings: whatever the wait saves is dwarfed by how long the
recipient then takes to read the mail and reply. The knob remains for rationing. The cron runs
every minute to match, and this path fills in a brand-new post's eventual reach itself rather
than waiting for the background pass.

Two problems, only one of which is about reach. Immediate mail on a rippling post goes only to
members with `emailfrequency=IMMEDIATE`; everyone on the daily digest hears tomorrow, buried
among everything else posted that day. And separately, somebody with an open WANTED for exactly
this item may sit outside today's polygon and inside next Tuesday's.

Two signals, in `iznik-batch/app/Services/FirstReply/MatchMailService.php`:

| Signal | Source | Weight |
|---|---|---|
| `wanted` | an open post of the **opposite** type that matches by vector | 5 |
| `search` | a saved search (`users_searches`) that matches by vector | 3 |

**It matches both ways round.** A new OFFER finds the people holding open WANTEDs for it, and a
new WANTED finds the people sitting on open OFFERs of it, so both sides of a would-be exchange
get the chance to start it rather than only whichever of them posted second. The type rule
lives in the matcher - a WANTED matches an OFFER and vice versa, because somebody else wanting
what you want is competition rather than a lead - so it is not reimplemented here.

### Propensity is not a signal, and the numbers are why

A third signal used to pick members who reply to a lot of posts and live near this one. Over
three days on live it sent **7,902 mails and produced 3 replies**, against 4 replies from the
7,909 sent in total. It is gone, and nothing has replaced it: every mail this service sends now
answers something the recipient asked for, item by item, which is also what justifies it being
an EXTRA mail rather than their digest arriving early.

`firstreply_scouts` keeps its name. Those rows are the evidence base for how this mail
performs, including the propensity trial the paragraph above is drawn from, and renaming a live
table with foreign keys to tidy up a word would put that continuity at risk for nothing. Rows
with `reason = 'frequent'` are the surviving record of that trial; nothing writes new ones, and
no code path reads that value any more.

### Matching is vector, at the bar for mail

Both match signals go through apiv2 and are held to **`MinMatchedPostScore` (0.85)** - the same
constant the matched-posts email uses, not the `MinSimilarScore` (0.80) of the on-site "similar
posts" strip.

Those are separate numbers because precision falls off a cliff. Hand-judged on live posts:
0.85-0.90 scores 0.92, 0.80-0.85 scores **0.43**. A strip somebody chose to look at can carry a
weak suggestion; an email cannot. `postmatches.go` puts it plainly - a missed match costs one
email nobody sees, a junk match teaches somebody to ignore the next one.

Both signals were previously SQL LIKE on keywords, which has no score at all: a saved search for
"bed" fired on "bed lever", and nothing could say how badly it had matched.

| Signal | Endpoint | Compares |
|---|---|---|
| `wanted` | `/message/{id}/matches` | this post's subject vector against opposite-type posts |
| `search` | `/message/{id}/searchmatches` | this post's subject vector against saved search terms |

**Saved search terms are embedded as DOCUMENTS, not as queries, and that is what makes one
threshold legitimate for both.** A term ("pine bookcase") is the same kind of text as a post
subject once `EmbeddingService::preprocessSubject` has stripped `OFFER:` and the trailing
location, so the cosines land on the same document-vs-document scale as
`messages_embeddings.subject_embedding`. Embedded as a query instead, they would sit on the
search scale (`MinVectorScore`, 0.65), where reusing 0.85 would filter out nearly everything -
the same class of error that left the matched-posts email at 0.60 with a precision of 0.49.

`users_searches_embeddings` is populated by `embeddings:searches` (hourly), which also
re-embeds anything written by an older model rather than silently mixing scales. **Until that
has run the `search` signal matches nothing** - it fails closed, which is the right direction,
but it is inert rather than obviously off.

### Post views are NOT a signal, and the reason is not what you would guess

Views look like the obvious fourth signal - a weaker `search`. There is plenty of data (1.5M
genuine page-opens a week from ~18k members), `messages_likes.pageview` already separates a real
page-open from a list-scroll impression, and viewed posts already carry embeddings, so no new
table or embedding job would be needed. **It was measured on live and it does not work.** Do not
rebuild it without new evidence.

**A single view is far too weak to mail on.** Of genuine page-opens, **0.29%** are followed by
that member replying to that post - about 1 in 345.

**Repeat viewing looks much stronger, and is misleading.** Conversion by how many times a member
opened the same post:

| Views of that post | Sampled | Replied | Rate |
|---|---|---|---|
| 1 | 207,824 | 527 | 0.25% |
| 2 | 4,046 | 57 | 1.41% |
| 3 | 202 | 14 | 6.93% |
| 4 | 97 | 11 | 11.34% |

That is ~28x better at 3+ views, but it is largely reverse causation: people re-open a post
*because* they are about to reply to it. It says nothing about what they want NEXT, which is what
an interest profile would need.

**The decisive test kills the idea.** For members who replied to a post, take the best cosine
between that post and their prior *repeat-viewed* posts, and compare against the same posts
shuffled between members - which controls for the fact that all Freegle items resemble each other
somewhat:

| Best prior repeat-viewed post vs the post actually replied to | median | p90 | >=0.85 |
|---|---|---|---|
| Real view history (n=1,142 reply events) | **0.596** | 0.685 | 0.3% |
| Shuffled (same posts, wrong owners) | **0.597** | 0.675 | 0.2% |

**Indistinguishable from random**, to three decimal places. View history is not a weak semantic
signal, it is an empty one. The likely reason is that Freegle replying is driven by proximity and
timing far more than by what an item resembles: somebody views a sofa and replies to a bookcase,
because the bookcase is two streets away and free now.

Limits of that measurement, so a future attempt knows what was and was not covered: a 21-day
window, only posts carrying embeddings, and it tests *best-of* their repeat-viewed posts rather
than a centroid of them. A centroid formulation is untested - though a median identical to
shuffled leaves it little to rescue. The embeddings are the subject-only 256-dim ones, but those
are exactly what the match matcher uses, so a signal invisible here is not available to the
feature either.

To re-run it: sample `(userid, refmsgid)` from `chat_messages` where `type='Interested'`, join
`messages_likes` on the same member with `type='View' AND pageview=1 AND count >= 2` and
`timestamp < chat_messages.date`, pull `messages_embeddings.subject_embedding` for both sides
(little-endian float32, already unit-norm, so cosine is a dot product), and compare the real
pairing against a shuffled one.

### A recipient is someone the ripple has NOT reached yet

The geographic test is a band, not a radius: **outside `rippling_reach.polygon` (the reach the
post has right now) and inside `max_polygon` (the reach it ends up with)**.

Both halves matter. The upper bound stops us mailing someone the post will never legitimately
reach. The lower bound is what makes this mail worth sending at all: somebody already inside the
current polygon is going to be told anyway, on the ordinary schedule, so mailing them spends a
slot, a mail and a per-member cooldown to change nothing. Reaching past the current edge is the
entire point.

Both signals can afford the full test. `wanted` and `search` start from a small national
candidate set - the people who asked for this particular item - so checking each one against the
reach polygons is cheap. (The withdrawn propensity signal could not: "every frequent replier in
Britain" is not a set worth building in order to discard 99.9% of it, so it started from members
of the post's own communities instead.)

### A matched member who replies pulls the reach out to them

They were picked precisely because the ripple had not got to them. So a reply is evidence the
item is wanted at a distance the schedule had not yet allowed for - and the people around them
deserve the same chance rather than waiting on the clock.

`MatchMailService::attributeReplies` therefore does more than record the reply. For each newly
attributed one it finds the lowest tick of the post's schedule whose polygon covers that member,
and writes it to **`rippling_reach.min_tick`** with `next_expansion_at = NOW()`.
`ExpandService::advanceDue` then takes `max(elapsed-time target, min_tick)`, capped at the
post's own schedule length, so the next pass jumps out to cover them.

A floor rather than a polygon write, deliberately. Advancing reach means resolving the tick's
geometry, unioning the origin group's area, deriving bounds, re-applying rejected-group clips
and upgrading routing-provided bounds - all of which `ExpandService` already does. Writing the
polygon from `MatchMailService` would be that same geometry implemented twice, which is a
mistake this codebase has paid for before.

It only ever moves forward, only while the post is still `expanding`, and a reply from someone
already inside the current reach moves nothing, because there is nothing to pull out to.

Small is the point. A few well-chosen people is a different product from "the digest, but
sooner", and `user_cooldown_hours` / `user_max_per_week` exist so that asking for things never
turns into being mailed constantly. Recipients are written to `rippling_reach_notified` as well
as `firstreply_scouts`, so the reach mailer never sends the same post again later.

### What justifies the mail is that they asked for this item

Both surviving signals claim the same thing: **this member asked about this thing.** That is what
makes the mail legitimate as an EXTRA one rather than as their digest arriving early, and the
consent for it is `users.relevantallowed` - the existing "Suggested posts for you" setting.

So a member who saved a search for "bookcase" can hear about a bookcase even if their digest has
already gone today, because they asked for that, item by item. The withdrawn propensity signal
claimed only "this member replies to a lot of things", which is why it was never allowed to be an
extra mail: it could bring that member's daily digest forward and nothing more.

A candidate dropped by that gate does not leave a hole: filtering happens **before** the top-N
cap, so the next-best candidate takes the slot and the post still gets its full complement.

**Slots are spent nearest-the-edge first** (signal score only breaks ties). Every candidate
stands outside today's polygon by construction — inside it the ordinary ripple already tells
them - but the reach will have grown by the time they read their mail, so if the ceiling ever
does bind, the slots should go to the people just past the edge, whom the reach is about to
cover, rather than to the strongest signal ten miles out. The ceiling is a backstop that should
never bind, so in practice the ordering decides nothing. The distance
comes from `ST_Distance` to the current polygon inside the eligibility query itself
(coordinate degrees — the SRID 3857 tag is the site-wide mislabel — which ranks correctly
within a post).

**A match mail is an extra, and does not consume the recipient's digest.** They asked for this
item, so taking their daily roll-up away as well would be a straight loss to them.

What it does consume is the reach mail: everyone actually mailed gets a
`rippling_reach_notified` row, so the ripple does not later send them the same post a second
time. That is keyed on who was **actually mailed**, not who was picked, so a member whose spool
failed stays eligible for the reach mail rather than silently getting nothing. It is why
`mailPostToUsers` returns the ids it sent to rather than a count.

### The mail is the ordinary digest mail

A match mail is the immediate-digest layout for that one post, via the shared
`spoolPostToRecipients` the reach mailer uses: same Mailable, same `MODE_IMMEDIATE`, same
`emailType: 'digest_immediate'`, and the same recipient checks (preferred address,
`browseMaxDistance` slider) so the two cannot drift.

**Two things distinguish it, and both exist because an anonymous copy of the digest is
indistinguishable from the digest people are already ignoring.**

| | Ordinary digest | Match mail |
|---|---|---|
| Subject | `[Group] OFFER: Pine bookcase (Ealing)` | `OFFER: Pine bookcase (Ealing)` |
| Body opens with | the post count heading | one line naming the post or search of theirs it matched |

The subject is the post's own, with no `[Group]` prefix, so the inbox line is the item rather
than the shape every other Freegle mail shares. The intro line comes from
`UnifiedDigest::matchIntro()`, driven by the per-recipient `$matchReason` map that
`mailPostToUsers` threads through - `wanted` says "You have an open post about X, and this one
looks like a match", `search` says "This matches a search you saved for X". A digest never gets
either: `matchReason` is null and `matchIntro()` returns null.

Nothing else reveals how the recipient was chosen, and nothing identifies which of their posts
or searches matched beyond the item name.

**Membership is not required.** `UnifiedDigestService`'s reach mailer is members-only; this
deliberately is not. Anyone inside the post's eventual reach may be told about it whether or not
they have joined the community it was posted to, because replying joins them - the in-app path
calls `AddMembership` as part of creating the reply, and an emailed reply is joined on its way
in. The membership follows the interest rather than gating it. (Product decision, Edward,
2026-08-05.)

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

### It talks about a member's posts as a set

**The unit is the member, not the post** - the same shape as bulk freegling, where a clearance
is one conversation about many items rather than one conversation per item. `chat_prompts.msgids`
holds the set a question covers, and answering applies to all of them.

Per-post messaging was tried first and is wrong. Somebody clearing a house has six posts going;
a question about each produces a thread where every message opens "still nothing on this one"
about a different thing, half of them concern items that have since gone, and the member has to
hold the mapping in their head. Grouped, the same information is one message that is actually
useful:

- *"your 3 outstanding posts have been looked at 12 times between them"* - the number that
  means something. Per post it is three separately discouraging small numbers.
- *"Still nothing on 4 of your offers. Could you drop things off?"* - one question, four posts
  patched by one answer.

Each question covers only the posts it applies to (photo covers the ones without photos,
delivery covers the OFFERs), and the card lists them - collapsing past three, because a house
clearance would otherwise be a wall of cards. That is also why the wording never names an item:
the cards say which posts, so the text does not have to, which disposes of the fact that an
item name reads fine as "your dining chairs" and badly as whatever someone actually typed.

| Kind | Asked when | Answering does |
|---|---|---|
| `photo` | 1.5h, posts with no attachment | records intent; the button opens the posts |
| `delivery` | 3h, OFFERs without `deliverypossible` | sets `deliverypossible` on all of them |
| `views` | 8h, once the total across their posts passes `views_min` | nothing - it is information |
| `deadline` | 24h, posts with no deadline | sets `deadline` on all of them, from a date picker |

Cadence follows the same unit: a member gets a given question at most once per
`kind_cooldown_days` (default 14) however many posts they have, so volume is bounded by the
member rather than by how much they are giving away. Due-ness is judged on their **oldest**
silent post, so posting something new cannot reset a clock they have already earned.

`deadline` takes a **date picker**, not fixed timescales: "by this weekend" is wrong for
somebody moving house on the 14th, and the poster already knows their own date. The server
validates by shape and range (a bare date, today or later, within a year) rather than against
the option list, and still accepts the older named timescales so prompts sent before the picker
remain answerable.

**Questions that stop applying are retired.** A prompt is expired once **all** of its posts have
stopped being silent - replied to, given an outcome, or deleted. While any of them is still
waiting the question still means something, and answering applies to whichever remain. A live
"could you deliver?" under items collected yesterday is not clutter, it is wrong: answering it
would edit finished posts. They expire rather than vanish so the thread still reads back in
order, and an already-answered prompt is never rewritten.

### The chat header

A Freegle chat gets its own header (`chat.systemchat`, set by the API when the other party is
the Freegle account). Rating, blocking and reporting are dropped, because almost nothing here is
a conversation and none of those verbs mean anything pointed at Freegle. The "these are
automated" note lives there **once** rather than on every message, and Hide is offered plainly -
as is the settings toggle that stops them entirely.

There are **three** chat headers and the member-facing ones are not the obvious file.
`ChatHeader.vue` is only rendered by ModTools (`ModChatPane.vue`); what a member actually sees
is the header built inline in `ChatPane.vue` (desktop) and `ChatMobileNavbar.vue` (mobile). All
three carry the variant, so check all three when changing it.

The posts a question covers are listed by `ChatPromptPost.vue` - a compact thumbnail-and-subject
row, modelled on the bulk freegling item list, rather than `ChatMessageSummary`'s full card. One
question routinely covers five posts, and five full-bleed photo tiles would bury the question
under them.

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

- **Grouping is the main control.** One message covers everything a member has outstanding, so
  clearing out a house produces the same volume as posting one thing.
- `kind_cooldown_days` bounds how often the same question can come back, per member.
- `user_gap_hours` is a backstop on top of that.
- `users.settings.freeglechat = false` stops them entirely, from Settings.
- The chat header says once that these are automated and that replies there are not read - it
  is not repeated on every message - and offers Hide.
- Prompts expire (`expiry_days`, or sooner once their posts are no longer waiting) and stop
  offering buttons, but still render, because a conversation with holes in it is more confusing
  than a stale question.

Freegle chat messages DO count towards the unread badge and DO push, like any other message.
That is a deliberate product decision: these are messages to you, about your posts, and burying
them in a quiet channel would mean nobody ever answers them.

## Schema

| Table | What |
|---|---|
| `rippling_reach.max_polygon` | the reach the post ends up with. NULL = not computed yet, and every reader falls back to current-reach behaviour |
| `rippling_reach.min_tick` | a floor the expander must not sit below, set when a matched member replies. NULL = expand on elapsed time alone, exactly as before |
| `users_searches_embeddings` | a saved search term as a vector, embedded as a DOCUMENT so it shares the post threshold |
| `chat_prompts` | options and answer for a `Prompt` chat message |
| `firstreply_scouts` | who was mailed about what, why, and whether they then replied (`replied_at`). Doubles as the fatigue ledger. Keeps its name: these rows are the evidence base |
| `firstreply_prompts_sent` | which prompts a MEMBER has had, with `postcount`. Keyed on the member rather than the post, because one message covers everything they have outstanding - so "have they been asked this lately" is a question about them |
| `firstreply_passthroughs` | one row per reply let through, plus how long it would otherwise have waited (`waited_hours`, NULL until the sweep runs and when unanswerable) |
| `firstreply_event_metrics` | daily counters, same shape as `rippling_event_metrics`. Still written; read by SQL now that the ModTools panel has gone (see [Measuring it](#measuring-it)) |

## Crons

All are registered in `iznik-batch/routes/console.php` inside
`if (config('freegle.firstreply.enabled'))`.

| Command | Cadence | What |
|---|---|---|
| `firstreply:maxreach` | every minute | fills in `max_polygon`, and sizes recorded passthroughs. Kept out of `ripple:expand`, which is the hot single-writer loop |
| `firstreply:matchmail` | every minute | attributes replies to earlier match mail - pulling the post's reach out to cover anyone who replied - then finds and mails new matches |
| `embeddings:searches` | hourly | embeds saved search terms so the `search` signal can match by vector. Also re-embeds after a model change |
| `firstreply:engage` | every 5 min, **not currently registered** | sends the next due prompt. Nested inside a second `if (config('freegle.firstreply.chat.enabled'))`, which is off, because `EngagementService` returns immediately when the chat is off and a cron whose only job is to rediscover that is a process spawn every five minutes for nothing |

Each takes `--dry-run`.

## Turning it on

`FIRSTREPLY_ENABLED` is the master switch; `FIRSTREPLY_PASSTHROUGH_ENABLED` and
`FIRSTREPLY_MATCHMAIL_ENABLED` gate those two levers independently. The chat's flag is **not**
env-driven any more - it is hard `false` in `config/freegle.php` (see the top of this page).
**`FIRSTREPLY_ROLLOUT_PERCENT` decides how much of the network sees any of it, and defaults to
0 - set it or nothing happens.**
The Go API needs `FIRSTREPLY_ENABLED` and `FIRSTREPLY_PASSTHROUGH_ENABLED` too, since the
in-app reply path is enforced there.

Sensible order: turn on `FIRSTREPLY_ENABLED` alone first so `firstreply:maxreach` can drain,
then the passthrough (which needs `max_polygon` to do anything), then match mail.

### One cap, and it should never bind

Everyone reaching this point **asked** - an open post for this item, or a saved search that
matches it. There is no good reason to tell the first ten and not the eleventh, so
`max_per_post` (default **50**) is a backstop against something pathological, not a rationing
of the signal.

It is still needed. A common term like `sofa` is held by hundreds of members nationally, and
without a ceiling one post could mail all of them. When it does bind, that is logged and
counted as `matchmail_capped`, so a pathological post shows up rather than quietly mailing
everybody.

**How big is the population really?** Sized from live:

| Quantity | Live value |
|---|---|
| Freeglers a rippled post reaches | ~3,600 average, ~19,000 max |
| Share of the 2.5M-member network that is | **0.14%** |
| Members holding a common term (`sofa`) network-wide | single-digit thousands (~0.03% of 27M live rows) |
| **So, in-reach holders of a common term** | **order of ten people** |

Ten, before the not-yet-reached band, the 24h cooldown, the weekly cap, post-email consent and
the 0.85 threshold cut it further. The realistic per-post figure is single digits, which is why
strong is not rationed and the backstop should never fire.

> A previous version of this page justified the ceiling with "358 members hold Sofa". That was a
> **network-wide** count with no geography applied, so it said nothing about what a single post
> would send - `filterEligible()` bounds every candidate to the reach band. It made a non-problem
> look like a mailbomb. Keep per-post numbers per-post.

The ceiling stays because it costs nothing when it never fires, and `matchmail_capped` counts
the times it does, so a pathological post surfaces rather than quietly mailing everyone.

### Saved searches have to be recent

`users_searches` is never pruned - `deleted = 0` covers ~99% of a 27M-row table whose rows go back
to **2017** - and nothing else that reads it for matching bounds it by date. Unbounded, a member
gets mail because of something they searched for years ago, which reads as "why are you emailing
me about a sofa?" rather than as help.

`SearchMatchesForPost` therefore only considers searches from the last
**`FIRSTREPLY_SEARCH_MAX_AGE_MONTHS`** months (default 6). That is a judgement, not a measurement:
long enough for a slow-moving want to survive, short enough that the term still describes what
somebody is after. It does not starve the signal - live has ~120k searches in the last 3 months
and ~400k in the last year in the newest slice of the table alone.

### Changing the cap without a deploy

The cap is the whole mail bill of this lever, and the moment you want to move it is usually the
moment it is mailing too many people - the worst time to be waiting for a release. So it is
runtime-settable:

```sql
-- pull the safety ceiling in if matchmail_capped starts firing
INSERT INTO config (`key`, `value`) VALUES ('firstreply_matchmail_max_per_post', '20')
  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
```

**Zero is the stop button**: it halts the mail entirely without touching the enabled flags.
An absent row means "no opinion", so the env default stands and an empty `config` table behaves
exactly as a deploy-time default would.

Read by `MatchMailService::matchConfig()` on every run, so a change takes effect on the next
tick with no restart. The other knobs stay env-only - they shape who is chosen rather than how
much mail goes out, so they belong with a deploy and a think.

## Measuring it

**There is no dashboard.** ModTools → SysAdmin → First reply and the
`GET /api/firstreply/metrics` endpoint behind it were removed on 2026-08-11. Every ledger they
read is still written, so this is now SQL against those tables. What mattered was never the panel
but the way of reading them: a lever's own counter says a number went up without saying whether
the number was worth having, so each one is read against something.

### The KPI

**Does it get more items rehomed, and more posts replied to?** Every lever counter can rise while
this one does not. More mail sent is not more items rehomed.

Rippled posts in the window, split into the arm that got the treatment and the arm that did not,
counted on **got a reply** and on **Taken**:

```sql
SET @percent = 20;                     -- the live FIRSTREPLY_ROLLOUT_PERCENT
SET @from = '2026-08-05 00:00:00';     -- NOT before the trial went live: see below
SET @to = NOW();

SELECT CASE WHEN CRC32(CONCAT(rr.msgid, '|firstreply')) % 100 < @percent
            THEN 'trial' ELSE 'holdout' END AS arm,
       COUNT(*) AS posts,
       SUM(EXISTS(SELECT 1 FROM chat_messages cm
                   WHERE cm.refmsgid = rr.msgid AND cm.type = 'Interested'
                     AND cm.userid <> m.fromuser)) AS replied,
       SUM(EXISTS(SELECT 1 FROM messages_outcomes mo
                   WHERE mo.msgid = rr.msgid AND mo.outcome IN ('Taken', 'Received'))) AS taken
  FROM rippling_reach rr
  JOIN messages m ON m.id = rr.msgid
 WHERE rr.created_at BETWEEN @from AND @to
   AND m.deleted IS NULL
 GROUP BY arm;
```

The two `EXISTS` are per candidate post rather than joins with `COUNT(DISTINCT)`, so neither
`chat_messages` nor `messages_outcomes` is materialised; `rr.created_at` is indexed
(`rippling_reach_created_freeglers`).

**Floor `@from` at the moment the trial went live.** A wider window fills both arms with
pre-trial history - posts that got replies the ordinary way before the feature existed - and the
trial row then reads as a claim the feature never made (live case: "1,834 trial posts replied"
hours after switch-on). The dashboard did this from a `FIRSTREPLY_ENABLED_AT` env var, which
nothing reads any more; the floor is now yours to set, and the honest thing is to state it
alongside any number taken from this.

Split on `CRC32(CONCAT(msgid, '|firstreply')) % 100` against `FIRSTREPLY_ROLLOUT_PERCENT` - the
same rule and the same percentage the levers bucket on, on both the Go and Laravel doors, so the
arms really are the posts that did and did not get the treatment. The population is **rippled**
posts, not all posts: that is what the levers act on, and where the 44%-no-reply figure comes
from.

Three limits, stated because the output looks equally authoritative either way:

- **At 0% or 100% one arm is empty** and the comparison means nothing. Don't read the surviving
  row as an effect.
- **The arms are not equal in size.** Read the percentages, not the counts.
- `Taken` depends on the poster coming back to record an outcome, which is itself a behaviour
  the trial may change.

Note that the delivery and deadline questions go to **both** arms (see the prompt table above),
so the comparison is "passthrough + match mail + photo/views prompts" against "neither" - not
"chat versus nothing". With the chat off, it is "passthrough + match mail" against neither.

### Per lever

Read each lever against something, not on its own:

| Lever | Read it as | Where from |
|---|---|---|
| Passthrough | first replies let through, and **how much earlier the poster heard because of it** - measured per reply, not guessed from a population | `firstreply_passthroughs` (`source`, `waited_hours`), and `rippling_held_replies` for the holds that still happened |
| Matches | reply rate and rehome rate **per signal**, so `wanted` and `search` can be compared directly. A signal that does not convert should be switched off rather than left spending mail - which is exactly what happened to `frequent` | `firstreply_scouts` grouped by `reason`, joined to `messages_outcomes` |
| Freegle chat | answer rate per question, and how often the answer actually changed the post. "Collection only" and "no rush" are real answers that leave the post as it was, so count them separately from ones that did something | `chat_prompts` (`kind`, `answered_at`, `answer`) |

The daily `firstreply_event_metrics` counters are still written by both stacks, and are the only
record in the database of two things the row ledgers cannot reconstruct: `matchmail_capped` (the
per-post ceiling actually bound) and `matchmail_reply_expanded_reach` (a matched member's reply
pulled the reach out). Everything else in there duplicates a ledger, so prefer the ledger.

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
- a replier no tick covers is left **NULL**, not 0, so average only the rows that could be
  answered, and say how many could not. An unknown saving is not a zero saving.

Split out how many of the sized replies would have arrived within a day anyway - a passthrough
that saves twenty minutes is worth much less than one that saves three days, and a single average
hides which kind these are:

```sql
SELECT source,
       COUNT(waited_hours) AS sized,
       AVG(waited_hours) AS avg_hours_earlier,
       MAX(waited_hours) AS max_hours_earlier,
       SUM(waited_hours < 24) AS same_day,
       SUM(waited_hours IS NULL AND computed_at IS NOT NULL) AS unsized
  FROM firstreply_passthroughs
 WHERE created_at BETWEEN @from AND @to
 GROUP BY source;
```

`unsized` is counted from these rows rather than by subtracting `sized` from the daily counters -
those are a different table and can legitimately diverge, which would put a wrong number in front
of the reader.

### Which signal picked a recipient

`firstreply_scouts.reason` records the **strongest** signal that fired, so a member who had both
an open WANTED and a matching saved search is counted under `wanted`, where the credit belongs.
A `frequent` row is from the propensity trial and no new ones are written.

Replies are attributed by a sweep in `firstreply:matchmail` rather than a hook on the reply
path, because replies arrive through four doors (web, app, email, TrashNothing) and none of
them knows or should know the replier was mailed. It is correlation, not proof - they replied
after we mailed them - which is why the rate is read next to the rehome rate rather than alone.
