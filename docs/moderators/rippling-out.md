# Rippling Out - A Guide for Moderators

> For the technical detail of how the algorithm works (and the approaches that were
> rejected), see [../developers/reference/rippling-algorithm.md](../developers/reference/rippling-algorithm.md).

---

## The one big "please don't"

> ### Don't reject a post just because it looks "out of area"
>
> With rippling out, a post that started on a neighbouring community can appear on
> **your** community, even if the poster lives some distance away (by default it arrives
> already approved; see below). **That is expected and correct.** It means the system is
> deliberately spreading a post that has not been collected locally so your members get a
> chance at it.
>
> Only reject it for the usual reasons - spam, breaks the rules, wrong sort of thing,
> and so on. "They're not from round here" is no longer a reason to reject.

### The same applies to replies

If someone replies to a post from outside what you think of as your area, that is the
system working as intended. Do not ban or block a member simply because they replied to
a post you think is out of their area.

---

## How posters appear in your community

When a post ripples into your community, **the poster is automatically joined as a
Member/Approved**. That is what lets the post live on your community and lets you
moderate it.

It is not a relationship with your community, though, and it does not make the poster
yours to write to. They posted somewhere else and may never have heard of you - see
[Talking to the poster](#talking-to-the-poster) below.

What this means in practice:

- You moderate their post exactly as you would any other: approve it, or take it off
  your community.
- The poster appears in your member list, but with no Chat button and no standard
  messages that only write to a member, because there is no conversation for you to
  start. If they write to you first, that chat works as normal.
- You can still take them off your community or ban them, and that happens as usual. It
  is done quietly: they never joined you, so a message about it would be as confusing as
  one about their post.
- Your community's member count may include rippled members.
- The join is recorded as **'Rippled'** in the membership log, so you can distinguish it
  from a manual or organic join if you need to.
- If they later join your community themselves, or move house into your area, the
  membership becomes an ordinary one and none of the above applies any more.

### Talking to the poster

Anything we send a poster about their post comes from the community they posted it on.
If a post only reached you by rippling, **your moderation of it is silent to them**:
taking it off your community removes it here and tells them nothing, because it is still
live where they put it.

So on a rippled-in post you will not see Blank Reply, or the standard messages whose only
job is to write to the poster. Approving it and taking it off your community are still
yours to do. This holds however you remove it - the Reject button, a standard message
that rejects, or a standard message that deletes.

---

## What you will see that is new

### 1. Posts arriving from other communities

Some posts on your community will have started on another community and rippled into
yours. You will see a small notice on the post making this clear ("This post has rippled
in from a neighbouring community").

A post only ripples after it has already gone live on the community where it started, so
it has already been vetted - against **that** community's rules, by a moderator or (where
[post-moderation](post-moderation.md) is switched on) by the automated checks plus the
exposure gate described below. We therefore do not make it sit out a full review again: **with the default settings it is approved on your
community straight away**, appearing among your approved posts with the rippled-in notice
rather than in your pending list.

Your own rules still get a say. As the post ripples in it is checked against **your
community's own keywords and worry words**, and if it matches, that copy goes to your
**pending list** instead - your copy, on your community, leaving every other community's
copy alone. What matched is recorded on the post so you can see why, and it waits for one
of you to decide rather than auto-approving when the usual window passes.

Freegle-wide keywords are not applied again here. Those were weighed on the community the
post was made on, where a moderator may have approved it knowing exactly what it said. So
this catches the thing **your** community bans and others allow - which is the case worth
keeping your group's word list up to date for.

You can still reject it for the usual reasons (spam, breaks the rules, wrong sort of
thing) - see "Rejecting a rippled-in post" below.

(If your instance is configured with a mod-veto window - `RIPPLE_RIPPLED_IN_PENDING_HOURS`
set above zero - a rippled-in post instead waits in your pending list for that many hours
and auto-approves if nobody rejects it first. Either way, "they're not from round here" is
never a reason to reject.)

#### Posts that published without a human look ripple on a leash

Where **[post-moderation](post-moderation.md)** is switched on, some posts go live via
the automated checks rather than a moderator's click. Those posts do not ripple freely
(this part is controlled by its own switch, off by default):

- They wait about **an hour** after publishing before they start rippling at all, so
  their home community gets first look.
- After that, **reach is earned by clean exposure**: spreading into each additional
  community requires that a number of members have already seen the post with nobody
  objecting - a member report to the mods or a microvolunteer rejection **pauses all
  further spread**, including the visible reach area, until a moderator looks.
- **Your look settles it**: checking the post (see the oversight queues in the
  post-moderation guide) clears the gate entirely; rejecting it pulls it back.
- Posts a moderator approved by hand are exempt and ripple exactly as this guide
  describes.

So a rippled-in post on your community has either been approved by a human on its origin
community, or has earned its way to you by being seen without complaint - and if anyone
objects on the way, it stops spreading until a moderator decides. The practical rule for
you is unchanged: reject it for real reasons, never for being out of area.

### 2. A reach/map view on posts

On posts in your moderation screens there is a **"View rippling reach"** button (with a
map-marker icon) that shows you on a map the area a post is currently visible in. This is
useful for checking how far a post has spread or why it turned up on your list.

The **blue area** is where the post has *actually* rippled out to, read from the engine
rather than modelled from the schedule. That matters because the two can differ: if the
reach was frozen (for example the origin copy went back to Pending) a warning above the map
says so, and the reach can also be trimmed where members have left a community or capped by
the poster's own distance preference. A post that has not rippled out yet says so instead of
drawing an area.

A post in a very rural spot may show one to three narrow **spurs** reaching out further than
the main area, towards a nearby town. See "Reaching a town from a very rural spot" below.

Any moderator can open this, not just moderators of the communities the post is on - since
rippling is what carries posts beyond their original community, the person wondering how far
one travelled is often not a mod of where it started.

### 3. A reminder when you edit a shared post

If you edit a post that is on more than one community, you will see a warning:

> *"This edit will apply to the post on all N groups it appears on."*

(N is the actual number of communities the post is on.) Edit as normal - just be aware
your change is seen everywhere the post appears.

### 4. Held replies in Chat Review

Sometimes a reply is held because the post has not yet rippled out to where that member
is. This now applies to replies made on the **website and app** as well as by email or
TrashNothing - wherever a member replies from outside the post's current reach, the reply
is accepted and held rather than turned away. Chat Review shows you it is held because of
rippling (not because you chose to hold it).

You do not need to do anything - the reply releases itself. It goes either when the post
ripples out far enough to cover that member, or when its own **due time** comes round,
whichever is sooner. If the reach stops growing before either, every reply still waiting is
let through rather than stranded.

The due time is worked out from how far the member is **past the edge of the post's current
reach** - not from how far they are from the item: roughly a quarter of an hour plus a few
minutes per mile beyond the boundary, capped at three hours. Someone barely outside the line
is treated as the near-miss they are, whether the item itself is two miles away or twenty.
In practice a held reply now lands in well under an hour on average, and none waits longer
than three.

That is a recent change, so **older holds you may still be looking at took far longer** -
before every hold carried a due time, they waited for the reach itself, which averaged over a
day with a tail running into weeks. If a member asks why their reply went unanswered for two
days in early August 2026 or before, "it was held by rippling" is a real and likely answer.

A hold can also end **without** the reply ever being delivered: if the item is taken while the
reply is held, the reply is dropped and the replier is told the item has gone. The owner never
sees it. This is now rare, because the wait is short enough that the item is usually still
there, but it is what the third row below means.

Chat Review tells you which of these happened, and how long it took:

| What you see | What it means |
| --- | --- |
| **Held: rippling out** (with how long it has been waiting) | Still waiting. It will go out on its own. |
| **Held as too far for:** (with how long it was held) | It did arrive, but only after that delay, because the sender was outside the post's reach when they replied. This is what to look for when someone reports a reply that seemed to be ignored - the delay is on our side, not theirs. |
| **Never reached the recipient** | The item was taken by someone else while the reply was held, so the owner never saw it. |

That last one matters for tone: neither person did anything wrong, and the replier may be
upset with the owner for "ignoring" them. It is worth saying plainly that our system held the
message.

The wait exists to give closer people a head start, not to hold anybody back indefinitely -
which matters, because most held repliers live somewhere the post would never have reached on
its own.

The member who sent it sees their own message marked as "waiting to send"; the owner is not
shown it until it is released.

### 5. Members' Nearby feed is ordered by relevance, with their own distance preference

The "Nearby" browse feed no longer just shows the newest post first. Posts a member has
not seen yet are shown ahead of ones they have already seen, and within each of those two
groups, posts are ordered by a relevance score that balances how close a post is, how
fresh it is, and how much interest it has already had (views and replies) - the same kind
of scoring already used to order the rippling digest email. This changes the order a
member's own browse page shows posts in; it does not change what rippling approves, joins,
or who a post reaches.

Members also have a distance slider marked "Nearer" and "Further" with no numbers shown,
in two places: their **browse filters**, and (new) their **Settings**, under a "Feed"
section above Email Settings. It is a **personal preference each member sets for
themselves** - by default it is left at "Further" (no extra limit beyond their normal
rippling reach), and moving it towards "Nearer" narrows how far away posts can be.

**"Further" is not the same distance for everyone**, and this is deliberate. How far the slider
reaches at its "Further" end depends on how spread out freeglers are around that member's
postcode: in the countryside it goes further than in a city. The reason is that a rural
member's nearest town is usually somewhere they already drive to, so its posts are exactly the
ones worth showing them - whereas in a busy area there is nearly always someone closer, and
reaching across town mostly generates email for journeys nobody makes. So if a rural member
and a city member both say they are set to "Further", they are correctly seeing different
distances, and the "Max ... miles by road" note under the slider will differ between them.

Importantly, this preference now applies in **three** ways:

- **What they see when browsing** - moving it "Nearer" hides posts from further away.
- **The posts we email them** (their daily digest and immediate emails) - the same narrowing.
- **How far away other people see their own posts**. Setting it "Nearer" also caps who
  their own offers and wanteds reach: someone far away won't see the post in their Nearby feed
  or be emailed about it, even though rippling had carried it that far. Left at "Further" (the
  default), there is no such cap and the post reaches as far as rippling takes it.

Members who want those to differ can now say so. Under the slider there is a **Set separately**
link, which splits it into two: "Posts I see" and "Who sees my posts". Until they use it the two
move together exactly as before, so nothing changes for the great majority. The second slider can
go further than the first - a member may reasonably want to browse only their own town while
still letting their giveaways travel as far as rippling will carry them.

It still has **no effect on the rippling engine itself** (what it approves or joins, or how far
it carries a post) **or on moderation**, and their choice is remembered between visits.

**The third one only applies when the member moved the slider themselves.** This catches people
out, so it is worth being clear about:

| | What they see and are emailed | How far their own posts go |
|---|---|---|
| Member moved the slider | limited by their choice | limited by their choice |
| Member never touched it | limited by the starting position Freegle works out for them | not limited |

The starting position is Freegle's reading of the member's *area* - how spread out freeglers
are around their postcode - not something the member has told us about themselves. So we use it
to decide what is worth putting in front of them, but not to hold their own offers back. If we
did, a member in a busy town would find their posts stopping a few miles out simply because
they have a lot of neighbours, which would mean fewer things getting reused and would help
nobody.

So if a member asks "why do people further away see my post when I only see nearby ones?", the
answer is that they have never set the slider: Freegle narrowed what it shows them, and left
their giving alone. If they would rather their posts stayed local too, moving the slider to
"Nearer" does exactly that, in both directions.

A practical consequence worth knowing: a rippled-in post is shown to the members of your
community who are **closest to the poster, whose own distance preference reaches that far, and
whom the poster's own distance preference reaches - not necessarily everyone**. If a member
says a post "isn't showing for them", either their own slider *or the poster's* slider being
set to "Nearer" is a likely reason.

---

## Reaching a town from a very rural spot

Rippling normally grows a post outwards in every direction at once, up to a limit on how far
someone could reasonably travel. That works badly for a genuinely remote poster: the circle
fills up with empty countryside long before it touches anywhere many people live. Measured on
a real case, a post from Hawes in the Yorkshire Dales could be seen by about 430 members at
the maximum, while Kendal - the nearest town, and the one people there actually drive to -
sat just outside the limit at about 47 minutes.

So for posts whose whole reachable audience is small, the ripple now also looks a little
further out for a genuine cluster of members - a town - and extends towards up to three of
them as narrow spurs, rather than pushing the whole circle outwards over more empty ground.
In that Hawes example, taking in Kendal roughly doubles the number of people who can see the
post.

Deliberately clusters, not "the nearest community centre": a community covering two towns has
its middle in the fields between them, so aiming there would reach neither.

Members inside one of those spurs are treated exactly as everyone else the post has reached.
They see it on Browse and in search, they are told about it in digests, emails and phone
notifications, and they can reply. There is no separate category of member who can see a post
but is never told about it, or who is told about a post they then cannot reply to.

---

## When a poster leaves your community

If a poster leaves your community (or is removed), their rippled-in post is **pulled
from your community** automatically. The post continues to appear on all other communities
where it was approved. The poster is not re-added to your community, even if the post
carries on rippling elsewhere.

---

## Email settings for rippled members

For your awareness, when a poster is auto-joined to a community via rippling, their email
settings for that community are set as follows:

- **Immediate email** - downgraded to daily digest.
- **Daily digest or no emails** - preserved exactly as-is.
- **Community events and volunteering** - copied from their home community settings (no extra emails).

The poster receives one bundled intro email explaining all of this, rather than a separate
welcome email from each community they were joined to.

Because rippling moves more members onto the daily digest and joins them to more
communities, those digests can gather posts from several communities at once. The daily
"What's New" email therefore lists up to around 65 posts, to avoid being clipped by email
providers like Gmail. If there are more, it shows the first batch with a link to browse
the rest, and the subject line says simply "What's New" instead of a post count.

---

## Repost reminders and chase-ups happen once per item

Freegle reminds a poster shortly before it auto-reposts their item ("Will Repost: ...")
and, once reposting is exhausted, chases them up to ask what happened ("What happened
to: ..."). Under rippling a single item can be live on several communities at once, but
the poster still gets **one** reminder and **one** chase-up per cycle - not one per
community. Whatever they do in response (mark it taken, withdraw it, or promise it)
applies to the item everywhere it has rippled, so one email is enough to settle it.

Behind the scenes the item is still reposted on each community independently, so it stays
near the top of every local browse list - that part is invisible to the poster and costs
them no extra email.

---

## An auto-repost does not start the ripple again

A repost does **not** send the item rippling out afresh from its home community. The
ripple runs on its own clock, fixed when the item first entered rippling, and a repost
neither resets that clock nor puts the item back through the process. An item that has
already rippled keeps the reach it earned: the communities it reached still have their
copy, and the repost by itself neither adds new ones nor takes any away.

So to answer the obvious question: a reposted item effectively **starts rippled**. It
does not crawl outwards again from scratch each time.

What the repost does do is lift the item back to the top of **that community's** browse
list. Reposting runs per community, on each community's own repost interval, so a rippled
item is kept fresh in each place independently. It does not bounce to the top everywhere
at once: two communities with different repost settings will repost the same item at
different times, and each one's copy is refreshed only when that community's own interval
comes round.

The poster's mail is the exception to that. They still get one "Will Repost" reminder and
one chase-up per cycle for the item as a whole, not one per community, as above.

An item that has not rippled yet is not shut out by having been reposted: rippling picks
up items that have not yet started, and reposting does not disqualify one. Equally, a
repost cannot force an item to ripple. If the community has rippling turned off, or the
item already has enough separate people replying to it, it stays where it is. An item
with plenty of interest already does not need spreading further, so it is left alone.

---

## The spam-flag and "seen on many groups"

Rippling joins a poster to multiple communities. This does **not** trigger the "seen on
many groups" spam review flag. Ripple-joins are excluded from that count, so a poster
whose item has rippled widely will not be incorrectly flagged as a spammer.

---

## Rejecting a rippled-in post

If you reject a post that rippled in from another community, two things happen:

1. **The poster is not told.** They posted to their own community and the post is still
   visible there. A faraway community saying "not for us" is not something they need to
   hear about.
2. **The post stops showing in your community's area.** It carries on being visible
   everywhere else it has been approved. Your rejection simply trims your patch off the map.

A secondary rejection is low-stakes: it quietly removes the post from your area and
nobody is upset. Use it if a post genuinely is not suitable locally - but not simply
because the poster is from out of area.

---

## Rejecting or removing a post on its home community

This is different from a secondary rejection above. When a post is **rejected, deleted, or
withdrawn** on the community it was **originally posted to** (its home community), it is no
longer a live offer there - so:

1. **It is pulled from every community it had rippled into**, automatically. You do not have
   to chase it around the neighbouring communities; removing it at home cleans it up
   everywhere it had spread.
2. **It stops spreading.** Its rippling is halted, so it will not appear on any further
   communities.

This is exactly what you want when you catch spam or a rule-breaking post on its home
community: dealing with it once removes it everywhere, instead of leaving live copies
stranded on the neighbouring communities it had already reached.

The clean-up happens on the next rippling run (within about a minute), not the instant you
click - so a rippled copy may linger very briefly before it disappears.

(**Back to Pending** now works differently from Delete/Reject: it keeps each community's
copy for per-group review and does **not** re-ripple on re-approval - see **Reporting a
post** below.)

While a post is in that frozen state we stop advertising it: it is not included in daily
digests, immediate emails or phone notifications, because it is under review and we should
not be pushing something we may be about to reject. It also stays out of the Nearby feed and
search. Members who are outside the area it had reached when it froze are still told it has
not reached them yet, and a reply from one of them is still held, exactly as it would be on a
post that is simply still spreading - freezing changes what we send out, not who the post had
actually got to.

The poster is also removed from any community they had been auto-joined to **only** to carry
that post (i.e. where they have no other posts). They keep their home community and any
community where they have other activity, and - because this is a tidy-up rather than them
choosing to leave - a later post of theirs can still ripple into those communities normally.

---

## Reporting a post

There are two different actions here, on two different sites, and it helps to keep them
straight:

- **Reporting** is a **Freegle-site (FD)** action - the **Report this post** link members
  (and you) use while browsing the Freegle website.
- **Approve / Reject / Back to Pending** are **ModTools (MT)** actions - what you do to a
  post in your moderation queue.

Reporting now has teeth: enough member reports, or a single moderator's report, pull the
post back into your **Pending** queue in ModTools for review, rather than only sending a
message to the volunteers.

### When members report (on the Freegle site)

A member's report is a **review vote**. Reports made with **Report this post** on the
Freegle site and the in-app "Does this look OK?" microvolunteering checks count the same
way - they are both members saying "something's not right" about a post.

When **two** different members flag the same post, it is moved **back to Pending on every
community it is on** - its home community *and* every community it had rippled into. From
that point:

- The post is **hidden from members** and **stops rippling** any further while it is under
  review.
- The copies are **not deleted** - each community keeps its own copy in its **Pending**
  queue.
- **Each community's moderators decide for themselves** whether to approve or reject their
  own copy. One community's decision does not affect another's.

> Two members flagging a post is treated as "worth a look everywhere", not "definitely
> bad". It simply puts the post in front of every affected community's moderators - you
> still make the call.

### When you (a moderator) report (also on the Freegle site)

When you use **Report this post** on the Freegle site, the report dialog shows **every
community the post is on** and lets you **choose which mod teams to notify** - with an
**All communities** option to select them all at once.

Be clear about what that choice does, because it is narrower than it looks:

- **The tick boxes control who gets told.** Each community you pick gets a message to its
  mod team, so the right people see your reasons.
- **The pend is always everywhere.** Whichever communities you tick, your report moves the
  post to **Pending on every community it is on** - its home community and every rippled
  copy. Your moderator judgement counts on its own, with no quorum needed, and it is *not*
  scoped to the communities you picked.
- Scoping the pend to just the reported community was tried and did not work: the other
  copies stayed live in browse and went out in the next digest, so a post one moderator had
  judged bad was still circulating everywhere else.
- So a moderator's report has the **same reach as Back to Pending in ModTools**. Each
  community's moderators then decide their own copy, and a copy that is fine locally is
  simply approved again.

If a post is fine for one community but not another, do not use Report to trim it -
**reject your own community's copy** instead (the low-stakes secondary rejection described
above). That removes it from your area and leaves every other community's copy live.

### Approving or rejecting a reported post (in ModTools)

Once a post is Pending from a report, you handle your community's copy in ModTools exactly
as normal:

- **Approve** puts it back live on your community. Members are **not** emailed again - it
  simply reappears; there are no duplicate "new post" notifications, and it does **not**
  re-ripple out from scratch.
- **Reject** removes it from your community only (the usual low-stakes secondary rejection;
  the poster is not told). Other communities are unaffected.

A post being **held** (shown as "held by" a moderator) is **per community**, on that
community's own Pending copy, and only applies while the copy is Pending. It is
**independent**: a copy being held on one community has no effect on the same post's copy on
any other community. You approve or reject your own community's copy regardless of whether
another community has held, approved, or rejected theirs.

Because each community's copy is independent and stays put, a reported post **cannot
flip-flop**: one community approving it will never re-create a copy on a community that has
rejected it.

### Moving a post back to pending in ModTools

You don't have to go via the Freegle site at all. Moving a post from **Approved back to
Pending** in **ModTools** - the ordinary moderation action - now does the same thing as a
report: it pulls the post to Pending on **every** community it is on (its home community and
every rippled copy), not just yours.

So one moderator catching a problem in ModTools takes the post off the board **everywhere**
for review, instead of leaving live copies stranded on the neighbouring communities you
cannot see. As with a report, the copies are **kept** (not deleted), each community approves
or rejects its own, and **re-approving brings a copy back without re-notifying members or
re-rippling from scratch**.

---

## TrashNothing posts

TrashNothing posts **ripple like any other post**, with one exception that exists to stop
members seeing the same item twice. TrashNothing has historically cross-posted an item to
several Freegle communities itself, so one item can exist as more than one Freegle post.
While an item exists as several copies, none of them ripples out into new communities -
otherwise each copy would carry the same item further on its own account. Once those
copies have been merged into a single post, it ripples normally. A TrashNothing item
posted to one community ripples straight away, exactly like a member's post.

TrashNothing ingestion is moving to taking a single post per item, which will make this
exception rarer and rarer. On your own community, TrashNothing posts behave exactly as
they always have, and replies to them follow the same reach rules as everything else.

---

## What to expect from members

Members can now reply to any post they can see, even one that has not yet rippled out to
their area. If they do, we **hold** the reply for a short while and then deliver it - so
instead of "why can't I reply?", a member might ask why their reply hasn't been answered
yet, or notice their message marked **"waiting to send"**. The answer is reassuring:

> *Your reply has been saved. The post hasn't quite reached your area yet, so we're giving
> people closer to it a short head start - usually less than an hour - and then passing
> your message on. You do not need to do anything.*

Worth knowing: on their default Nearby view, a member only ever sees posts that have
already reached them, so a held reply usually only happens if they have switched to "All
my communities" or otherwise widened their view. What they normally see is delivered
straight away.

Members may also ask about the order posts appear in, or about the new distance slider in
the filters - see "Members' Nearby feed is ordered by relevance" above for the mechanism.

Members on **immediate emails** may also notice they get an alert when a post that
started on a neighbouring community ripples close enough to reach them. That is expected:
they get one immediate alert per such post, the moment it reaches their area - exactly as
they would for a post made directly on the community. If they would rather not, they can
switch that community to daily digest in Settings.

Some members may also ask why they have been joined to communities they did not sign up
for. The answer is that their post has been travelling to reach more people, and the
membership is what makes replies and contact work properly. They can leave any of those
communities in Settings, which will remove their post from that community.

---

## The two habits to build

1. **Do not reject a post just for being out of area** - that is the system working.
2. **Do not ban a member just for replying from out of area** - same reason.

Everything else about your day-to-day moderation routine stays the same.

---

## Reference: what the 'Rippled' log entry means

Any join created by rippling out is written to the membership log as a **Group / Joined**
entry whose text is `Rippled` (`logs.text = 'Rippled'`, with no acting user). This
is purely for provenance and audit - you do not need to do anything with it. It lets us
distinguish ripple-joins from manual or organic joins, and it is used internally to
suppress the per-group welcome email (replaced by a single bundled intro) and to exclude
these joins from the "seen on many groups" spam check.
