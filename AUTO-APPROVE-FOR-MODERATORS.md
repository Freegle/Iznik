# Auto-Approve - A Guide for Moderators

> **Current reference document.** Explains post-moderation (auto-approve) for
> moderators. Read alongside [`RIPPLING-OUT-FOR-MODERATORS.md`](RIPPLING-OUT-FOR-MODERATORS.md) -
> the two features work together, and the section "How this works with rippling out"
> below is where they meet.

---

## The one big change

> ### Settled members' posts now go live first, and you review them after - not before
>
> Until now, almost every member post sat in **Pending** until a moderator approved it.
> With auto-approve, a content-checked, clean post from a **settled, auto-moderated
> member** goes live by itself after a short delay (about 20 minutes by default), and you
> review it **after** it is live, in a dedicated oversight queue.
>
> This is deliberate. It gets good posts moving quickly and focuses your attention on the
> small number that actually need a human, instead of rubber-stamping the majority that
> are obviously fine.

Nothing about a **suspect** post changes: if the content check flags it, or there is any
danger signal (a recent mod note, a held/unheld history, a known-spammer record, a pending
membership review, microvolunteer rejections), it **stays in Pending** exactly as before
and waits for you.

---

## What still comes to you first (Pending)

The Pending queue is still the full queue, and you can still stop anything here before it
goes live. Two things are new:

- **A live countdown.** Each post that is on the clean auto-approve path shows a small
  countdown to when it will auto-approve - a muted "~Nh" when it is more than an hour away,
  a prominent "Mm Ss" inside the last 30 minutes, and "Auto-approving..." at zero. Posts
  that are **not** auto-approving (suspect, spam, held) show no countdown.
- **A guaranteed review window.** The moment you open the Pending queue, every post on it
  is given **at least 10 more minutes** before it can auto-approve. Nothing ever
  auto-approves out from under you while you are looking at the page.

---

## The new queues

Alongside **Pending** and **Approved** you now have two oversight queues:

- **Checked** - posts that went live via the automatic checks (your auto-moderated
  members). This is your **oversight queue**: a chance to glance over what published
  without you.
- **Trusted** - posts that went live without moderation because they came from a
  **trusted** member (set in your group settings). Same idea, different reason they
  skipped review.

The counts on **Checked** and **Trusted** show **how many are still unchecked** (shown in
blue, not the red of Pending), and they drop as you check things off. A **"Mark all as
checked"** button clears the bucket in one go. Posts older than **7 days** fall off the
queue on their own, so it always reflects recent activity rather than growing forever.

---

## Rejecting a post after it has gone live

The oversight queues are not just for looking - they are **actionable**. If you find a
post in **Checked** or **Trusted** that should not be live, you can **reject it straight
from that queue**. It is pulled back out of the live feed and returned to Pending/held so
it can be dealt with properly, exactly as if it had never auto-approved.

This is the safety net for the rare post that slips through the automatic checks: it went
live quickly, but you can still take it down quickly.

---

## How this works with rippling out

This is where auto-approve and rippling out meet, so it is worth understanding.

A live post can **ripple out** to neighbouring communities (see the rippling guide). A
post that auto-approved has not yet been looked at by a human, so we do **not** let it
spread network-wide unchecked. Instead, **reach is earned by review**:

- **A head start before any spread.** A freshly auto-approved post waits about **an hour**
  before it starts rippling at all, giving its home community's reviewers first look.
- **Wider reach needs proportional review.** To ripple into more communities, a post has
  to earn review in step with how far it is going. **Each new community it reaches needs
  its own light sign-off: 2 microvolunteer approvals, or 1 moderator check, from within
  that community's reach.** So reaching **2 new communities needs 4 microvolunteer
  approvals or 2 moderator checks** (or a mix - a moderator check counts as two
  microvolunteer approvals).
- **No review means it stays local.** If a post does not earn that sign-off, it simply
  **stops spreading** and stays close to home. It is not withdrawn - it just does not go
  wider. Well-reviewed posts spread fast and far; unreviewed ones stay local.
- **Stopping it is easy and does not scale.** The opposite of approval is cheap: **2
  microvolunteer rejections, or 1 moderator rejection**, on *any* copy halts the spread and
  pulls that copy back. Easy to stop, proportionally hard to spread.

The short version: **a post's reach can only run as far ahead as its review.**

---

## Who does the reviewing

The review that earns reach comes from two pools, and **both are drawn from inside the
post's maximum ripple area, nearest first**:

- **Microvolunteers** inside the area the post can reach are asked to look, with the people
  **closest to the post** asked first - they are the ones most likely to have useful local
  context.
- **Moderators** of communities inside that same area provide the moderator sign-off. A
  moderator of a community the post will never reach does not gate it - which is only fair,
  since it was never going to land on them.

In short: we only ask the people the post can actually reach, closest first.

---

## What to expect, and the habits to build

1. **Don't be alarmed that posts are live before you saw them.** That is the design. Your
   job shifts from approving everything up front to keeping half an eye on the **Checked**
   and **Trusted** queues and acting on the rare one that is wrong.
2. **Use the reject action** for a genuinely unsuitable post you find live - it pulls it
   straight back.
3. **"Out of area" is still never a reason to reject** (see the rippling guide). A post
   reaching you from a neighbouring community is the system working, not a fault.

Everything else about your day-to-day moderation routine stays the same.

---

## Reference: how we know a post was checked

When you check an auto-published post (individually or via "Mark all as checked"), we
record **who** checked it and **when**. This is what makes the unchecked counts drop, and
it feeds the **Moderation** tab under SysAdmin, which shows where posts went (arrived,
approved or rejected by hand, auto-approved, trusted) and the **auto-publish error rate** -
how often an auto-published post later needed a moderator to step in. That number is how we
know auto-approve is safe, and how we tune it.
