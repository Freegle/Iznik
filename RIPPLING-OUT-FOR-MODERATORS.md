# Rippling Out - A Guide for Moderators

> **Current reference document.** Supersedes the draft at
> `plans/rippling-out-rollout/CHANGES-FOR-MODERATORS.md`.

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
- The join is recorded with reason **'Rippled'** in the membership log, so you can
  distinguish it from a manual or organic join if you need to.

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

### 3. A reminder when you edit a shared post

If you edit a post that is on more than one community, you will see a warning:

> *"This edit will apply to the post on all N groups it appears on."*

(N is the actual number of communities the post is on.) Edit as normal - just be aware
your change is seen everywhere the post appears.

### 4. Held replies in Chat Review

Occasionally a reply is held because the post has not yet rippled out to where that
member is. Chat Review shows you it is held because of rippling (not because you chose
to hold it). You do not need to do anything - the reply is released automatically once
the post reaches that member's area, or once the post has finished spreading as far as it
will go, so no reply is ever held indefinitely. Members are never shown this reason; only
moderators see it.

### 5. Members' Nearby feed is ordered by relevance, with their own distance preference

The "Nearby" browse feed no longer just shows the newest post first. Posts a member has
not seen yet are shown ahead of ones they have already seen, and within each of those two
groups, posts are ordered by a relevance score that balances how close a post is, how
fresh it is, and how much interest it has already had (views and replies) - the same kind
of scoring already used to order the rippling digest email. This changes the order a
member's own browse page shows posts in; it does not change what rippling approves, joins,
or who a post reaches.

Members also have a new distance slider in their filters, marked "Nearer" and "Further"
with no numbers shown. It is a **personal display preference each member sets for
themselves** - by default it is left at "Further" (no extra limit beyond their normal
rippling reach), and moving it towards "Nearer" simply narrows what appears on their own
screen. It has no effect on rippling itself, on what other members see, or on moderation -
and, like their other browse filters (which posts, which communities, sort order), their
choice is remembered between visits.

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

This is different from a secondary rejection above. When a post is **rejected, deleted,
withdrawn, or moved back to pending** on the community it was **originally posted to** (its
home community), it is no longer a live offer there - so:

1. **It is pulled from every community it had rippled into**, automatically. You do not have
   to chase it around the neighbouring communities; removing it at home cleans it up
   everywhere it had spread. This includes **Delete** and **Back to Pending** - taking a post
   off the board on its home community, for any reason, takes its rippled copies down with it.
2. **It stops spreading.** Its rippling is halted, so it will not appear on any further
   communities.

This is exactly what you want when you catch spam or a rule-breaking post on its home
community: dealing with it once removes it everywhere, instead of leaving live copies
stranded on the neighbouring communities it had already reached.

If you move a post **back to pending** and later **approve it again**, it starts rippling
out afresh from your community - so re-approving simply lets it spread again as normal.

The clean-up happens on the next rippling run (within about a minute), not the instant you
click - so a rippled copy may linger very briefly before it disappears.

The poster is also removed from any community they had been auto-joined to **only** to carry
that post (i.e. where they have no other posts). They keep their home community and any
community where they have other activity, and - because this is a tidy-up rather than them
choosing to leave - a later post of theirs can still ripple into those communities normally.

---

## TrashNothing posts

TrashNothing posts are currently **not** rippled out into new communities. TrashNothing
still cross-posts an item to several Freegle communities itself, so rippling deliberately
stays out of its way to avoid the same item appearing twice. They behave on your community
exactly as they always have. This exclusion is temporary - once TrashNothing posts to a
single origin community, those posts will ripple like any other.

---

## What to expect from members

The main thing members may ask you about is **"why can't I reply to this post?"** The
answer is reassuring:

> *The post has not rippled out to your area yet. It is being shown to people closest
> to it first. As soon as it reaches you, you will be able to reply - you do not need
> to do anything.*

Worth knowing: on their default Nearby view, a member only ever sees posts that have
already reached them, so this question usually only comes up if they have switched to
"All my communities" or otherwise moved away from the default view. It is fine to say so -
it should reassure them that what they normally see is always reply-able.

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

The membership log shows `reason='Rippled'` for any join created by rippling out. This
is purely for provenance and audit - you do not need to do anything with it. It lets us
distinguish ripple-joins from manual or organic joins, and it is used internally to
suppress the per-group welcome email (replaced by a single bundled intro) and to exclude
these joins from the "seen on many groups" spam check.
