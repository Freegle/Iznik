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

When a post ripples into your community, **the poster is automatically joined as a full
Member/Approved**. This is intentional - moderators need to be able to contact posters
and moderate their posts in the usual way, so they must be real members.

What this means in practice:

- You can contact the poster, moderate their post, and use all the normal moderation
  tools, exactly as you would for any other member.
- The poster appears in your member list.
- Your community's member count may include rippled members.
- The join is recorded as **'Rippled'** in the membership log, so you can distinguish it
  from a manual or organic join if you need to.

---

## What you will see that is new

### 1. Posts arriving from other communities

Some posts on your community will have started on another community and rippled into
yours. You will see a small notice on the post making this clear ("This post has rippled
in from a neighbouring community").

A post only ripples after it has already been approved on the community where it started,
so it has already been vetted. We therefore do not make it sit out a full review again.
**With the default settings it is approved on your community straight away** - it appears
among your approved posts with the rippled-in notice, not in your pending list. You can
still reject it for the usual reasons (spam, breaks the rules, wrong sort of thing) - see
"Rejecting a rippled-in post" below.

(If your instance is configured with a mod-veto window - `RIPPLE_RIPPLED_IN_PENDING_HOURS`
set above zero - a rippled-in post instead waits in your pending list for that many hours
and auto-approves if nobody rejects it first. Either way, "they're not from round here" is
never a reason to reject.)

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
- **How far away other people see their own posts** (new). Setting it "Nearer" also caps who
  their own offers and wanteds reach: someone far away won't see the post in their Nearby feed
  or be emailed about it, even though rippling had carried it that far. Left at "Further" (the
  default), there is no such cap and the post reaches as far as rippling takes it.

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

TrashNothing posts are currently **not** rippled out into new communities. TrashNothing
still cross-posts an item to several Freegle communities itself, so rippling deliberately
stays out of its way to avoid the same item appearing twice. They behave on your community
exactly as they always have. This exclusion is temporary - once TrashNothing posts to a
single origin community, those posts will ripple like any other.

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
