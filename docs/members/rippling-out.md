---
last_reviewed: 2026-09-20
owner: Freegle dev team
covers:
  - iznik-nuxt3/components/DistanceSliders.vue
  - iznik-nuxt3/components/DistanceSliderRow.vue
  - iznik-nuxt3/composables/useReachDistance.js
  - iznik-batch/app/Services/Ripple/ExpandService.php
  - iznik-batch/app/Services/Ripple/DistancePreferenceFilter.php
  - iznik-server-go/utils/reachcap.go
---

# Rippling Out - A Guide for Members

## What is rippling out?

When you post something on Freegle, your item is shown to people nearby first. If nobody
close enough picks it up quickly, it is gradually shown to people a little further away -
and so on, working outwards in waves over time. This is rippling out.

The aim is to keep freegling as local as possible. Your neighbours get first chance,
which means less driving and a fairer go for everyone close by. If nobody nearby wants
it, it reaches further afield automatically - you do not need to do anything extra.

If enough people nearby reply to your post, it stops spreading further - it already has
plenty of interest, so there is no need to show it to people further away.

There is nothing to join and nothing to opt out of. Freegle is one national service, and
how far any single post reaches is worked out from distance alone - not from which
community, if any, you or anyone else belongs to.

---

## Your daily "What's New" email

Your daily "What's New" email lists posts that have reached you, nearest and freshest
first. To keep the email a sensible length - and to stop it being clipped partway through
by email providers like Gmail - it lists up to around 65 posts. If there are more than
that, the email shows the first batch and links you to the website to browse the rest, and
the subject line simply says "What's New" rather than giving an exact post count. Nothing
is missing - everything is always there on the browse page.

---

## Getting posts that ripple towards you

Rippling works in the other direction too: as other people's posts spread outwards, they
eventually reach you. If your emails are set to **immediate**, you get an alert the moment
a post first reaches close enough for you to reply. You only ever get **one** alert per
post, however far it later travels, and only once it is actually close enough for you to
reply.

If you would rather not hear about them straight away, set your emails to daily digest in
Settings - those posts then simply appear in your next "What's New" instead.

---

## Reminders about your post

If your item is still available after a while, we remind you shortly before we
automatically repost it ("Will Repost: ..."), and later we check in to ask what happened
("What happened to: ..."). You only ever get **one** reminder and **one** check-in for a
post, however far it has rippled. Whatever you choose - mark it taken, withdraw it, or
promise it to someone - applies everywhere the post has reached, so a single tap settles
it.

---

## Staying in control

### Changing your email settings

You control how often Freegle emails you - immediate, daily digest, or no emails - in
**Settings**. This one setting covers every post, wherever it comes from; there is no
per-community version of it to think about.

### Taking a post out of circulation

There is no community to leave to stop a post reaching further. If you want to stop a
post spreading, or stop it being shown at all, mark it **Taken** or **withdraw** it from
**My Posts** (`/myposts`). That removes it everywhere at once, however far it has already
rippled.

---

## Things you will notice on the browse page

### The most relevant posts first

On the browse page's "Nearby" view you see exactly the posts that have reached you - the
ones close enough that you can get in touch right now. Posts you have not seen before
appear first, then the ones you have already looked at; within each group, we bring the
most worthwhile posts to the top by weighing up how close a post is, how fresh it is, and
how much interest it has already had - not just which arrived most recently. So you should
not find a five-day-old post from several miles away sitting above a fresh one from just
up your street. The old travel-time slider and transport picker are gone: the system works
out the right distance for you and widens it automatically over time, so more posts appear
in your Nearby view as they ripple towards you. You will not see distant posts you cannot
yet reply to cluttering the list or map - Nearby stays genuinely nearby. You can still sort
by "Newest posted" or "Closest" if you prefer.

### A distance slider, if you want one

In the browse filters - and in your **Settings**, under "Feed" - there is a simple slider
marked **"Nearer"** at one end and **"Further"** at the other - no numbers, just a feel for
how far you would like posts to come from. It starts set to "Further", which means no extra
limit beyond the posts that have naturally reached you. If you would rather only see things
from close by, drag it towards "Nearer" at any time; drag it back whenever you like.

**"Further" means further in the countryside than it does in a city**, because it should. If
you live somewhere with few people nearby, the nearest town is probably somewhere you already
drive to, and its posts are the ones most worth showing you - so the slider reaches further out
for you, and the "Max ... miles by road" note under it will say a bigger number than it would
for someone in a city centre. In a busy area there is usually someone much closer, so reaching
half an hour across town mostly means being emailed about things you would not travel for.
Freegle works out which of these applies to you from how spread out other freeglers are around
your postcode; you do not have to tell it anything.

The same setting works **both ways**. As well as narrowing which posts you see and get
emailed about, moving it towards "Nearer" also limits **how far away other people see your
own posts** - handy if you would rather only hear from people who can easily get to you.
Left at "Further", your posts reach as far as rippling naturally takes them. (It measures
distance by road distance and travel time, not a straight line, so it follows real geography
like estuaries and coastlines.)

That both-ways rule applies to the slider **when you move it yourself**. It is your decision
about how far you are willing to go, so it seems only fair that it says how far your own
offers travel too.

If you want the two to be different, choose **Set separately** under the slider. You then get
two: "Posts I see" and "Who sees my posts". The second can go further than the first, which
suits people who only want to look at their own town while still being happy for someone a few
towns away to collect something they are giving away. **Link them again** goes back to one
slider. Until you use it, the single slider covers both, so there is nothing you need to do
here to limit who can answer your offers.

The starting position Freegle works out for you is different, and it only affects **what you
see**. It never shortens how far your own posts go. That is deliberate, and the reason is that
the two are not really the same question. Working out that you have plenty of freeglers close
by is a statement about your neighbourhood, not a promise from you about how far you would
drive. Using it to stop your spare sofa reaching somebody twenty minutes away would just mean
one less thing gets reused, and it would not help anybody. So Freegle only holds your own
posts back when you have actually asked it to.

Freegle remembers your choice, along with your other filters and sort order, so you do not
need to set them again next time you visit.

### Where your post goes

When you create a post you do not pick where it goes. Your post simply reflects where the
item actually is (based on the postcode you give), and rippling then shows it to the right
people, closest first. You do not need to post twice for more reach - the system handles
that for you.

### "You can always reply - we'll pass it on"

On the default Nearby view, every post you see has already reached you, so your reply
goes straight to the owner. Only if you move away from that default - for example by
widening the distance you are browsing at - might you come across a post that has not
rippled out to your area yet. You can still reply: we simply **hold your message for a
short while, then pass it on**. That gives people closer to the item a head start, which
is the whole point of rippling out, but it is a short wait rather than an open-ended one -
usually under an hour, and never more than three. The wait depends on how far outside the
post's current reach you are, so if you are only just outside it, it is very short. If the
post ripples out to you sooner than that, your reply goes straight through at that moment
instead.

You do not need to do anything or come back. It is delivered automatically, and your reply
shows as "waiting to send" until then. Nothing is lost.

---

## Quick summary

| What | How it works |
|---|---|
| Your post | Shown to people nearby first, then gradually further away |
| Nearby browse order | Unseen posts first, then seen; each ordered by closeness, freshness and interest, not just newest |
| Distance slider | Optional "Nearer/Further" control in Browse and Settings; narrows what you see and are emailed about, and how far away other people see your own posts; starts at "Further" (no extra limit) |
| Browse filters | Remembered between visits, including the distance slider |
| Email frequency | One setting - immediate, daily digest, or no emails - covers every post, wherever it comes from |
| Repost reminders / check-ins | One per item, however far it has rippled |
| Taking a post out of circulation | Mark it Taken or withdraw it from My Posts; that removes it everywhere at once |
