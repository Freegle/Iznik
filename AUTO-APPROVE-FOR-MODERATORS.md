# Auto-Approve - A Guide for Moderators

> **Current reference document.** Explains post-moderation (auto-approve) for
> moderators. Read alongside [`RIPPLING-OUT-FOR-MODERATORS.md`](RIPPLING-OUT-FOR-MODERATORS.md) -
> the two features interact, and the section "How this works with rippling out"
> below is where they meet.

**The change in one sentence:** a content-checked, clean post from a settled, auto-moderated
member goes live by itself after a short delay (about 20 minutes by default), and you review
it **after** it is live in a dedicated oversight queue, instead of approving it beforehand.

**The state of the decision:** this is **built but switched off**. Nothing changes on any
group until it is deliberately switched on, and the plan is to start with a small number of
volunteer trial groups and published error rates, not a network-wide flip. The final
decision rests with the Board, informed by the trial.

---

## The objections, taken seriously

This change was discussed at length by moderators in
[Evolution of our approach](https://discourse.ilovefreegle.org/t/evolution-of-our-approach-improving-the-freegle-experience/9620)
(over 300 posts). Those objections shaped what was actually built. Before anything else,
here is each substantial objection raised there and an honest statement of how the design
answers it - or does not. Post numbers refer to that thread.

| The objection | Honest response |
|---|---|
| **"You've already decided - this consultation is fake."** (many moderators; posts 31, 78, 118, 138, 290) | The criticism of the process is fair: progress was announced in a passing comment on another thread, and that was handled badly. The only honest test of whether consultation is real is whether input changes outcomes, so judge it on that: the objections in this thread are why explicitly-moderated members stay moderated, why member notes veto auto-approval, why oversight is a queue rather than a board-scan, why there is a quality-check control sample and a published error rate at all, and why the rollout is a small opt-in trial rather than a network-wide switch. The decision to go beyond a trial has not been made, "no" remains an available answer, and it should be made against error-rate results shared with moderators - with the success and failure criteria stated before the trial starts, not after. |
| **"Where's the evidence this is a problem? 97% approval could just mean moderation works."** (many moderators; posts 26, 30, 51, 137) | Partly conceded: we cannot prove in advance that faster posting grows Freegle. That is exactly why the build includes measurement rather than faith: every auto-published post is tracked, a random sample can be held back for human checking as a control, and the **auto-publish error rate** (how often a mod later had to step in) is computed continuously. If the trial shows the error rate is too high or the benefit too small, it gets switched off again and the numbers will show why. One honest limitation: the analytics page currently sits under SysAdmin, which ordinary moderators cannot see - so trial results must be actively published to moderators (on Discourse), not just "available". Widening who can see that page is a fair ask. |
| **"A bad post is live before anyone can stop it - post-hoc review can't undo harm."** (several moderators; posts 5, 8, 29, 68, 132) | True, and not spun away: with auto-approve on, a bad post that passes the filters is publicly visible until someone acts. What bounds the harm: every post still goes through the content checks **before** publication; a suspect post stays in Pending exactly as now; there is a hold window (default 20 minutes) before anything goes live, during which it sits in your Pending queue and can be stopped; and a live post can be pulled straight back from the oversight queue with one click. Wider spread is also gated - see the rippling row below. The exposure is bounded, not zero. That is the trade being made, and the error rate will tell us its real size. |
| **"The filters already miss dangerous things - phone numbers, addresses, child-safety risks."** (several moderators; posts 2, 4, 54, 277) | Also true - no filter is perfect, and specific misses reported in the thread (like multi-word phrases such as "discounted price") have already led to filter fixes. But note what does *not* change: the content checks run before publication for everyone, exactly as today, and a flagged post never auto-approves. The gap is posts that are dangerous in ways no filter recognises. Those get through **today** for the roughly half of posts that already publish instantly (trusted and unmoderated members). Auto-approve extends instant-ish publishing to more members, with *more* safeguards than the trusted tier has ever had: a delay, a danger-signal check on the poster, and an oversight queue. |
| **"New members post incoherent rubbish - 'Any', 'Pond fish' - and no worry-word list catches that."** (several moderators; posts 13, 95, 112, 209, 306, 309) | The weakest spot in the design, said plainly. Vague-but-harmless posts are not what the filters or danger signals catch, so under auto-approve some will go live that you would have caught in Pending. What softens it: they appear in your Checked queue so they are findable without trawling the board, and your group can lengthen its own delay (for example to a few hours) to widen the catch window. If the trial shows Wanteds dominate the error rate, restricting auto-approve by post type (Offers first, as suggested in posts 13 and 308) is the obvious next lever - that is not built yet, and the error-rate split by type will show whether it is needed. |
| **"I keep certain members on moderation for good reasons. Automation can't know that."** (several moderators; posts 100, 114, 123, 134, 162) | This one is directly and fully honoured. A member you have explicitly set to **Moderated** (or Prohibited) is completely untouched - their posts wait for you, forever, exactly as now. Auto-approve only applies to members with **no explicit setting**. And even for those, it declines to act when there is any recorded reason for caution: a note on the member, a microvolunteer rejection on the post, a negative moderation action in the last 90 days, a spammer record, or an outstanding membership review. If you have ever written a note about someone, that note keeps them in Pending. |
| **"Scammers and rule-breakers will exploit unchecked posting - they already reword to dodge filters."** (several moderators; posts 114, 122, 213, 216) | Known spammers and suspected spammers never auto-approve (any spammer record vetoes it, and a post in the Spam collection on *any* group blocks approval on all of them). A repeat offender your team has actioned in the last 90 days does not auto-approve either. The honest gap: a **first-time** bad actor with a clean record can get one post live for a while. That post is in the oversight queue, can be rejected in one click, and doing so creates exactly the negative history that stops their next one. |
| **"Members don't report bad posts. The 'eagle-eyed members' safety net is imaginary."** (posts 30, 110, 114) | Agreed, and the design does not rely on member reports. The active safety nets are the ones we run: your oversight queue, microvolunteer checks, the quality-check sample, and the automated checks. Member reports remain a bonus, not the mechanism. |
| **"This means MORE work: wading through live posts, fixing errant ones, thanking reporters."** (several moderators; posts 5, 87, 184, 306, 311, 312) | You do not have to wade through the board - that is what the **Checked** and **Trusted** queues are for. They contain exactly the posts that went live without a human, newest first, with a count of how many you have not yet seen, a one-click "mark all as checked", and a 7-day window so they never pile up into a mountain. The point made in post 311 - that rippling has already made board-scanning impractical - is precisely why oversight is a queue and not a scan. Whether *total* work goes up or down is measured in the trial, not asserted: if 97% of held posts genuinely need nothing, clicks should fall. If the fallout work exceeds the saved clicks - one moderator reported exactly that from an earlier local experiment (post 41) - the numbers will show it. |
| **"Groups set their rules deliberately. This overrides local autonomy with central control."** (several moderators; posts 25, 87, 90, 130, 165) | Stated plainly rather than softened: post-moderation is intended as how Freegle works, network-wide - groups will not have a setting to opt out of it, because a patchwork of moderation models would confuse members and undo the point of the change. What stays under local control: every explicit member posting status you set is honoured absolutely (a member you keep on Moderated waits for you, forever), your notes on members veto auto-approval, your group tunes its own delay and quality-sample percentage, and your rules are enforced by you exactly as now - rejecting a rule-breaking post is unchanged. Rippling questions belong to the rippling guide. |
| **"This is really about making moderators redundant. Take away the work we enjoy and we leave."** (posts 237, 245, 279 - and several moderators said they would resign, posts 34, 41, 141) | No code change answers a feeling of being devalued, and people quitting over this is a real, already-partly-realised cost. What is factually true: the judgment work stays yours - suspect posts, flagged members, reports, rejections, member disputes all still come to you, and the routine 97% is what goes. The stated aim is redirecting volunteer attention, not removing it. Whether that is how it plays out is fair to judge by results, and the trial-first, switched-off-by-default rollout exists so that judgment can happen while it is still reversible. |
| **"Human pre-moderation is Freegle's trust differentiator - dropping it risks members' and funders' confidence."** (several moderators; posts 27, 97, 103, 158) | A judgment call, honestly labelled as such. Two facts for the scales: about half of Freegle posts already publish without pre-moderation today (trusted and unmoderated members), and that has been true for years without being the thing members or funders notice; and human oversight does not disappear - it moves after publication and stays visible and provable (every check is recorded, see the last section). Freegle remains far more moderated than any of the platforms it is compared to. |
| **"Instant posting breeds fastest-finger-first and undermines Fair Chance."** (posts 35, 114) | The delay means posting is not instant, and nothing in this change alters reply handling or allocation. But the underlying point is fair: faster publication does reward quick responders, and leaning on a moderation delay to blunt that was always indirect. Fair Chance itself is a very old model, and a better one is worth designing in its own right - for example a short reply-gathering window on each post before the offerer chooses, so being first matters less than being suitable. Faster publication makes that work more worthwhile, not less; it deserves its own proposal rather than a footnote here. |
| **"Pilot it properly or not at all."** (many moderators; posts 37, 49, 73, 170, 280) | That is the plan, and the code enforces it: switched off everywhere by default, an explicit trial-group list for a phased start, live error-rate measurement, and a reversible switch. No big bang. |
| **"A bad auto-approved post won't just hit my group - rippling will spread it to my neighbours."** (posts 302, 306) | Addressed by the **earned-reach gate**: an auto-published post (one no human has looked at) waits about an hour before it starts rippling at all, and it then spreads only as fast as clean exposure accumulates - each additional community it reaches requires that a number of members have already seen the post with nobody flagging it. A single member report, or a microvolunteer rejection, pauses further spread until a moderator looks; a moderator check clears it, and a rejection pulls it back. Posts a moderator approved by hand are exempt, because they have had their human look. (Requiring active reviewer sign-off per community was considered and dropped - review capacity could never keep up with post volume, so it would have quietly stopped rippling for most posts.) (The observation in post 302 - instant rippling with no delay - is right, and worth being precise about: by the thread's own figures (post 1), only around 46% of posts are held for human approval, so roughly half of what ripples today was never human-approved either - it passed the automated checks and went. The hold and the exposure gate arrive with auto-approve precisely to give unreviewed posts a brake that today's rippling does not have.) |
| **"Something illegal goes live - flytipped goods, kerbside free-for-alls. Who is liable? Me?"** (posts 4, 226, 238, 258) | Legal responsibility for a post sits with the person who posted it - that principle is what lets any user-content platform operate, and it does not change here. What Freegle controls is how fast a problem post is caught: the checks before publication, the hold window, your oversight queue and the one-click reject are that machinery. Groups with rules about kerbside posts keep enforcing them exactly as now - nothing about auto-approve weakens a rejection. |

If you raised something in that thread that is not represented above, or the response
misstates your point, say so on the thread - this table is meant to be kept honest.

---

## The one big change

> ### Settled members' posts go live first, and you review them after - not before
>
> Until now, a post from an **auto-moderated member** (one with no explicit posting
> status) sat in **Pending** until a moderator approved it. With auto-approve switched
> on, a content-checked, clean post from such a member goes live by itself after a short
> delay (about 20 minutes by default), and you review it **after** it is live, in a
> dedicated oversight queue.
>
> This is deliberate. It gets good posts moving quickly and focuses your attention on the
> small number that actually need a human, instead of rubber-stamping the majority that
> are approved unchanged.

Nothing about a **suspect** post changes: if the content check flags it, or there is any
danger signal (a mod note on the member, a negative moderation action in the last 90 days,
a known-spammer record, a pending membership review, a microvolunteer rejection), it
**stays in Pending** exactly as before and waits for you.

For scale: roughly half of all posts already publish without pre-moderation today, because
they come from members you (or defaults) have marked trusted. Auto-approve extends
quick publishing to the auto-moderated tier, with more safeguards attached than the
trusted tier has.

---

## Switched off until deliberately switched on

The feature is **dark by default**. Merging and deploying the code changes nothing.
Rollout, when it comes, is phased:

- A **trial-group list** lets it run on a handful of volunteer groups first, everywhere
  else untouched.
- Per group, the **delay** and the **quality-check sample percentage** are configurable.
- There is no per-group setting to opt out of post-moderation itself: once rolled out it
  is how Freegle works everywhere, so members get one consistent experience. Member-level
  control (explicit posting statuses, notes) is untouched.
- The **error rate is measured from day one** (see the last section), so the decision to
  widen, hold or reverse is made on numbers.

---

## What still comes to you first (Pending)

The Pending queue is still the full queue, and you can still stop anything here before it
goes live. Two things are new:

- **A live countdown.** Each post that is on the clean auto-approve path shows a small
  countdown to when it will auto-approve - a muted "~Nh" when it is more than an hour away,
  a prominent "Mm Ss" inside the last 30 minutes, and "Auto-approving..." at zero. Posts
  that are **not** auto-approving (suspect, spam, held, or feature off for your group) show
  no 20-minute countdown.
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

Posts that merely **rippled in** from another community do not appear in your oversight
queues - overseeing those is the job of the community they were posted to, plus the
review that gates their spread (below).

---

## Rejecting a post after it has gone live

The oversight queues are not just for looking - they are **actionable**. If you find a
post in **Checked** or **Trusted** that should not be live, you can **reject it straight
from that queue**. It is pulled back out of the live feed and returned to Pending/held so
it can be dealt with properly, exactly as if it had never auto-approved.

This is the safety net for the rare post that slips through the automatic checks: it went
live quickly, but you can take it down just as quickly.

---

## How this works with rippling out

This is where auto-approve and rippling out meet, so it is worth understanding.

A live post can **ripple out** to neighbouring communities (see the rippling guide). A
post that auto-approved has not been looked at by a human, so we do **not** let it
spread network-wide unchecked. Instead, **reach is earned by exposure without
complaint** - the people who have already seen the post vouch for it by not objecting:

- **A head start before any spread.** A freshly auto-approved post waits about **an hour**
  before it starts rippling at all, giving its home community first look.
- **Wider reach needs more quiet eyes.** Each additional community a post ripples into
  requires that a number of members have **already seen it and nobody has flagged it**.
  Views accumulate naturally as people browse, so a normal post earns its spread simply
  by being seen; a post nobody is seeing stays local.
- **One complaint pauses it.** A member reporting the post to the mods, or a
  microvolunteer rejecting it, **pauses all further spread** until a moderator looks.
  Copies it has already reached stay put; it just goes no further.
- **A moderator look settles it.** Your check clears the gate entirely (a human look
  outranks the crowd signal); your rejection pulls it back. Requiring active
  microvolunteer or moderator sign-off for every community was considered and dropped:
  review capacity could never keep pace with post volume, and rippling would have
  quietly stopped working for most posts.
- **Mod-approved posts are exempt.** A post you approved by hand has had its human look
  and ripples normally.

The short version: **a post spreads as far as the quiet approval of the people who have
already seen it - and one objection stops it until a human decides.**

---

## Who does the reviewing

The earning is passive - members seeing the post as they browse. The active roles are
the brakes and the all-clear, and both are drawn from **inside the post's maximum ripple
area, nearest first**:

- **Microvolunteers** inside the area the post can reach are asked to look, with the
  people **closest to the post** asked first - they are the ones most likely to have
  useful local context. Their rejection pauses the post's spread.
- **Moderators** of communities inside that same area can check a post (clearing its
  gate) or reject it (pulling it back). A moderator of a community the post will never
  reach is never involved - which is only fair, since it was never going to land on them.

In short: everyone who sees the post quietly vouches for it; the people it can actually
reach, closest first, hold the brake.

---

## What to expect, and the habits to build

1. **Nothing changes until it is switched on for your group.**
2. **Posts being live before you saw them is the design, not a fault.** Your job shifts
   from approving everything up front to keeping half an eye on the **Checked** and
   **Trusted** queues and acting on the rare one that is wrong.
3. **Use the reject action** for a genuinely unsuitable post you find live - it pulls it
   straight back.
4. **Your member notes matter more than ever.** A note on a member keeps their posts in
   Pending. If you know a reason someone needs a human eye, write it down.
5. **"Out of area" is still never a reason to reject** (see the rippling guide). A post
   reaching you from a neighbouring community is the system working, not a fault.

Everything else about your day-to-day moderation routine stays the same.

---

## Reference: how we know a post was checked

When you check an auto-published post (individually or via "Mark all as checked"), we
record **who** checked it and **when**. This is what makes the unchecked counts drop, and
it feeds the **Moderation** analytics under SysAdmin: where posts went (arrived, approved
or rejected by hand, auto-approved, trusted), the **auto-publish error rate** - how often
an auto-published post later needed a moderator to step in - and how the held-back
quality-check sample compares against it. Those numbers are how the trial gets judged,
how auto-approve is tuned, and how it would be caught out if the objections above turn
out to be right.
