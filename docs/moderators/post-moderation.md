# Post-Moderation (Auto-Approve) - What It Is, Honestly

> **Current reference document.** This is the single description of post-moderation:
> the problem it tries to solve, what it does, the case for and against, the risks and
> what bounds them, and what changes for moderators and for members. It replaces the
> earlier root-level moderator guide and the earned-reach design note. How it interacts
> with rippling out is summarised here and covered from the rippling side in
> [rippling-out.md](rippling-out.md) (moderators) and
> [../members/rippling-out.md](../members/rippling-out.md) (members).
>
> **Status: built, switched off.** Nothing changes on any community until it is
> deliberately switched on - the plan is a small number of volunteer trial groups with
> published error rates, and the decision to go further rests with the Board.
>
> Worth being precise about who chooses, because two different things are easily
> confused: **which communities post-moderation runs on is set centrally** (a master
> switch, plus a list of named communities for the trial phase), and **a community has
> no setting of its own to opt out of it**. The trial runs on volunteer communities
> because they volunteered, not because each community holds a switch. What each
> community does control is covered under "What changes for moderators". This was
> discussed at length by moderators in
> [Evolution of our approach](https://discourse.ilovefreegle.org/t/evolution-of-our-approach-improving-the-freegle-experience/9620);
> the objections raised there shaped what was built, and this document tries to state
> plainly which of them the design answers and which it does not.

---

## The problem it tries to solve

Most members have never been given an explicit posting status. Their posts sit in
Pending until a moderator acts, or until the 48-hour fallback publishes them anyway.
In practice around 97% of those posts are approved unchanged - the queue is mostly
rubber-stamping - but each post still waits, typically for hours, during the window
when interest in it would have been highest. Meanwhile roughly half of all Freegle
posts already publish with no human look at all (trusted members, unmoderated members,
and the 48-hour fallback), so pre-moderation is already partial, not the universal
safety net it feels like.

The cost of the wait is real: a freegler posts an offer, gets no visible result for
hours, and the moment of engagement passes. The cost of the rubber-stamping is also
real: volunteer attention is spent confirming the overwhelming majority of fine posts
instead of examining the small minority that need judgement.

Post-moderation flips the order for the clean majority: **publish first, review after**,
with the review effort concentrated on posts that show any reason for caution.

## What it does

Where it is running (see the status note above - centrally switched on, during the trial
for named communities only):

- A post from an **auto-moderated member** (no explicit posting status) that the
  automated content checks found **clean** goes live by itself after a short delay -
  about **20 minutes** by default, tunable per community.
- It does **not** go live automatically if there is any **danger signal**: a moderator
  note on the member, a microvolunteer rejection, a negative moderation action in the
  last 90 days (that window is configurable), a known or suspected spammer record (spam
  on any community blocks approval on all of them), or an outstanding membership review.
  Those posts stay in Pending for a human, exactly as now.
- Members you have explicitly set to **Moderated** or **Prohibited** are completely
  untouched - their posts always wait for you.
- A configurable **quality sample** of otherwise-clean posts is held back in Pending
  for a human verdict, so there is always a control group to measure the automation
  against.
- Moderators oversee auto-published posts **after** they are live, from dedicated
  queues (see "What changes for moderators"), and can pull one back with one click.
- The **error rate** - how often an auto-published post later needed a moderator to
  step in - is measured continuously, and is the yardstick for widening, holding or
  reversing the rollout.

### How it works with rippling out

A post that auto-published has not had a human look, so it is not allowed to spread
network-wide unchecked. When the companion "earned reach" switch is on:

- A freshly auto-published post waits about **an hour** before it starts rippling at
  all, giving its home community first look.
- After that, **reach is earned by clean exposure**: spreading into each additional
  community requires that a number of members (five per community, by default) have
  already seen the post with nobody objecting. Views accumulate naturally as people
  browse, so a normal post earns its spread simply by being seen; a post nobody is
  seeing stays local.
- **One complaint pauses all further spread** - a member reporting the post to the mods,
  or a microvolunteer rejecting it. Pausing means the post's visible reach area stops
  growing too, not just its community placements: nobody new starts seeing it in
  browse, search or emails while it is paused. What it has already reached stays put.
- **A moderator look settles it**: checking the post clears the gate entirely, and
  rejecting it pulls it back.
- **Posts a moderator approved by hand are exempt** - they have had their human look
  and ripple normally.

Requiring active reviewer sign-off for every community was considered and dropped:
review capacity could never keep pace with post volume, so it would have quietly
stopped rippling for most posts. Passive exposure with a complaint brake scales;
active ratification does not.

## What a community NOT taking part still sees

Worth being explicit, because "it is off for us" is not the same as "nothing changes for
us". Three effects reach a community that is not in the trial:

- **Posts from participating communities can ripple in.** This is the substantive one.
  A post that auto-published on a trial community, with no human look, can spread to
  yours - it is the earned-reach gate, not your moderation settings, that governs how
  far and how fast (see above: an hour's head start, spread earned by clean exposure,
  any complaint freezing it, a moderator look settling it). If you reject the copy on
  your community, that removes it from your area exactly as any secondary rejection
  does. The gate applies to any post no human has looked at, wherever it started.
- **The two oversight queues appear for everyone, and they are not empty.** Checked and
  Trusted are added to the ModTools menu for all moderators, not only trial communities,
  and they list posts that went live without a moderator's click - which already happens
  everywhere today: posts from **trusted** members, and posts published by the long
  standing **48-hour fallback** when nobody got to them. So on a community with
  post-moderation off, these queues surface oversight work that existed before this
  change and had no home; they do not mean auto-approve is running there.
- **The Pending countdown appears for everyone.** Where post-moderation is off, the
  countdown on a Pending post shows the 48-hour fallback rather than a 20-minute one -
  it is reporting the auto-approval that already existed, not a new one. Opening the
  Pending queue also holds its posts for a further 10 minutes on every community, which
  only ever delays an auto-approval, never causes one.

What does **not** cross over: no member of yours starts auto-publishing because a
neighbouring community joined the trial. Whether a post auto-publishes is decided by the
community it was posted to.

The trial analytics (error rate, quality sample, review-gate delay) are network-wide
figures under SysAdmin, so ordinary moderators - in or out of the trial - do not see
them unless they are published (see "Remaining problems").

## The case for (pros)

- **Posts move when interest is highest.** The first hour or two is when an offer gets
  its replies; a multi-hour Pending wait spends that window on a queue.
- **Volunteer attention goes where judgement is needed.** The ~3% of posts with a
  reason for caution still get a human; the ~97% that were being approved unchanged
  stop consuming clicks.
- **More safeguards than the existing instant tiers.** Trusted and unmoderated members
  already publish with no delay, no danger-signal check, and (until now) no oversight
  queue. The auto-moderated path gets all three.
- **It is measured, not asserted.** Error rate, quality-sample comparison, and
  review-gate delay are all tracked from day one, so the trial produces numbers rather
  than anecdotes.
- **It is reversible.** Dark by default, per-group trial list, a master switch that can
  be turned off again, and no schema or behaviour change while off.
- **One consistent member experience** network-wide once rolled out, rather than a
  patchwork of moderation models per community.

## The case against (cons)

- **A bad post that passes the filters is publicly visible until someone acts.**
  Pre-moderation would have caught it before anyone saw it. This is the fundamental
  trade, and it cannot be argued away - only bounded.
- **Vague-but-harmless rubbish goes live.** "Any", "Pond fish", incoherent one-word
  posts - no filter or danger signal catches these, and under post-moderation some
  will publish that a moderator would have queried in Pending. This is the weakest
  spot in the design.
- **A first-time bad actor with a clean record gets one post live for a while.** The
  danger signals are history-based; someone with no history has no signals.
- **The moderator role changes,** from gatekeeper to overseer. Some moderators object
  in principle, find the work less meaningful, or have said they would resign over it.
  People quitting is a real cost, already partly realised in the consultation thread.
- **The most permissive decision in the chain governs what reaches you.** Communities
  genuinely differ on what they allow, and rippling carries a post outwards from wherever
  it was published - so a post nobody queried locally, or one a single moderator
  elsewhere was happy with, can land on a community that would have handled it
  differently. Your response is limited to your own copy. See "different communities
  allow different posts" below; this is probably the sharpest practical objection.
- **A community cannot opt out.** Whether post-moderation applies is decided centrally,
  not community by community: during the trial it runs on the communities chosen for it,
  and the intent beyond the trial is that it becomes how Freegle works everywhere, so
  members get one consistent experience rather than a patchwork. A community tunes its
  own delay and quality-sample rate, and every explicit member posting status is honoured
  absolutely - but there is no setting that turns the feature off locally. Local autonomy
  is deliberately narrowed here, and that should be said plainly rather than softened.

## Risks and what bounds them

| Risk | What bounds it |
|---|---|
| Dangerous content goes live (safeguarding, personal data, illegal items) | The automated content checks still run **before** publication for every post, exactly as today; anything flagged stays in Pending. The gap is content dangerous in ways no filter recognises - which already reaches the board today via the roughly half of posts that publish instantly. |
| A bad post is seen before a moderator can act | The delay (default 20 minutes, per-community tunable upwards) keeps it in Pending first; opening the Pending queue holds everything on it for at least 10 more minutes; the oversight queues make it findable once live; reject is one click and pulls it back. |
| A bad post spreads to neighbouring communities | The earned-reach gate: about an hour before any rippling, exposure-earned spread after that, and any single complaint freezes further spread - including the visible reach area - until a moderator looks. Bounded, not sealed: the exposure it requires can be manufactured with a few accounts (below), and the ceiling is however far rippling would have carried the post anyway. |
| Someone games the gate to spread their own post | Needs several distinct accounts rather than repeat views, and at best restores the spread the post would have had with no gate at all - so it buys speed, not extra reach. |
| Someone uses the gate to stall other people's posts | One objection pauses a post until a moderator looks, so it is cheap to abuse - but it only pauses spread (nothing is hidden or removed), and the objection lands in the mod queue, so it is visible rather than silent. |
| Scammers exploit the automatic path | Known and suspected spammer records veto auto-approval everywhere; a Spam-collection post on any community blocks all of them; a negative moderation action in the last 90 days vetoes it; each caught post creates the history that stops the next one. |
| The moderators-know-their-members knowledge is lost | A moderator note on a member keeps their posts in Pending (any note, from any community - see the surprises section, which cuts both ways). Explicit Moderated/Prohibited statuses always win. The judgement calls stay human; only the rubber-stamping is automated. |
| The automation is worse than believed | The quality sample is a continuously-running control group, and the error rate is computed from day one. If auto-published posts need intervention more often than the held-back sample suggests is tolerable, the numbers show it and the switch comes off. |
| The rollout itself goes wrong | Nothing changes until switched on; the trial list limits it to volunteer communities; success and failure criteria are meant to be stated before the trial starts, not after. |

## Gaming, abuse, and things that will surprise you

Worth setting out plainly, because most of these are consequences of deliberate design
choices rather than oversights - but they are the sort of thing that is much worse to
discover in the wild than to be told in advance.

### The two ways the exposure gate can be played

- **Manufacturing approval (making a post spread faster).** Reach is earned by distinct
  members having seen the post, and "seen" means it passed through their browse list -
  not that anyone deliberately examined it. So the votes of confidence are cheap:
  somebody with a handful of accounts, or a few willing friends, can clear the exposure
  bar for their own post. Two things bound it: it needs *distinct accounts*, not repeat
  views, and the worst outcome is that the post spreads at the speed it would have
  spread at anyway if the gate did not exist - the gate can only ever slow a post down,
  never carry it further than rippling would have.
- **Freezing a post (stopping it spreading).** The brake is deliberately cheap: **one**
  message to a mod team referencing the post, or **one** microvolunteer rejection,
  pauses all further spread until a moderator looks. That asymmetry - five quiet views
  to go forward, one objection to stop - is the intended safety bias, but it does mean a
  single member can stall any auto-published post, including maliciously. What limits
  the damage: it only pauses spread (nothing is hidden or deleted, and everyone who
  could already see the post still can), the objection itself arrives in your mod queue
  so it is not silent, and your check releases it.

### The big one: different communities allow different posts

Moderators do not all draw the line in the same place, and they are not meant to - rules
about kerbside collections, bulk clearances, part-worn goods, business-ish offers, what
counts as free, and how much benefit of the doubt a vague post gets are genuinely local.
Rippling already means a post approved by one team can appear on another's community.
Post-moderation sharpens that in a way worth being blunt about:

- **The standard that governs what reaches you is the standard of wherever the post
  started** - and where post-moderation is on, that may be **no human standard at all**,
  just the automated checks plus enough quiet views. A post your team would have queried
  in Pending can arrive on your community already live, because the community it was
  posted to never queried it.
- **One moderator's "fine" unlocks it for everybody.** The gate asks whether *any* human
  has looked, so a check by a moderator of any community the post has reached - including
  one it merely rippled into, whose local rules differ from yours - clears the gate and
  lets it spread onwards everywhere.
- **Your disagreement only trims your own patch.** Rejecting a rippled-in post removes it
  from your area and nowhere else. You cannot apply your community's standard to the
  post generally, only locally - which is existing rippling behaviour, but it matters
  more once fewer posts have been through anyone's Pending queue.

None of this is accidental: a single network-wide experience is the stated aim, and a
patchwork of standards is what it is trading away. But the practical effect is that the
**most permissive decision in the chain wins**, and the honest summary is that your
community will see posts you would not have approved, more often than it does today.
The levers you keep are local: reject your copy, keep specific members moderated, write
notes, and lengthen your own delay.

### Things that will surprise moderators

- **"Reporting" is broader than the Report button.** Any message from a **member** to a
  mod team that references a particular post counts as an objection - including messages
  never meant as complaints ("is this one still going?"). Messages from the mod side of
  that conversation do not count, so discussing a post with a member is safe; but a
  member raising it in any wording will pause its spread until someone checks it.
- **Any moderator's check clears the gate for the whole post, everywhere.** The gate
  asks "has any human looked at this post?", not "has this community's moderator looked
  at it". A moderator of a community the post merely rippled into can clear it - after
  which it spreads normally for everyone. That is intentional (one human look is one
  human look) but it is a wider authority than it appears.
- **A paused post does not say so.** There is no badge on the post, and no queue of
  "posts frozen awaiting review" - only the aggregate figures on the SysAdmin page. If a
  member asks why their post seems stuck locally, nothing in ModTools will tell you it
  is gated.
- **Any moderator note turns off auto-approval for that member - everywhere, for good.**
  This one deserves emphasis, because many teams use notes as an **information** store
  rather than a warning: "collects after 6pm", "has a van", "please text rather than
  ring", "lovely, gave away a sofa last month". The danger-signal check does not read
  the note or judge its tone - it only asks whether one exists - so every one of those
  members keeps waiting for a human indefinitely. Two consequences worth knowing:
  - On a team that notes people routinely, auto-approve will hardly ever fire. The
    feature will look broken rather than switched on, and the difference is invisible
    unless you know this rule.
  - **The check is not scoped to your community.** A note written by *any* community's
    moderators - about anything, however friendly - suppresses auto-approval for that
    member on *every* community. So a note-heavy team quietly makes its members'
    posts wait everywhere else too.

  Safe by default, and deliberately so, but it also means the members who do
  auto-publish are "the ones nobody has ever written anything about", which is not the
  same as "the ones nobody has concerns about". Whether the check should look only at
  notes on your own community, or at what a note actually says, is a real design
  question the trial should settle rather than assume.
- **Having the Pending queue open delays auto-approval.** Each load pushes everything on
  the page at least 10 minutes further out. That is the intended guarantee, but it means
  a page left open on a second screen quietly holds posts back.
- **Rejecting a rippled-in copy is not done from the oversight queues.** Those actions
  only ever apply to a post's own community. A bad post that rippled in is rejected the
  ordinary way, which trims it from your area alone.
- **Reject from the oversight queue and Back to Pending do different things to the
  copies.** Reject stops the post's spread and the copies it rippled into other
  communities are withdrawn on the engine's next pass - the whole post comes down.
  Back to Pending freezes the spread but leaves every community's copy in its own
  Pending queue, for that community to approve or reject. Which of the two a neighbouring
  moderator sees depends on which button the post's own community pressed. That
  difference is under review.

### Things that will surprise members

- **A post can be live but not yet in Nearby.** For its first hour an auto-published
  post is on its community's page and in emails but not in the Nearby feed, so a poster
  checking "is it showing?" from the default view may not find it.
- **Reporting a post appears to do nothing.** It quietly freezes the post's spread; the
  member sees no acknowledgement of that and the post stays visible where it already is.
- **Nobody is told whether a human approved their post.** There is no visible difference
  between a mod-approved and an auto-published post, and a post pulled back afterwards
  behaves exactly like one rejected from Pending.

## Remaining problems, stated plainly

These are known and not fully solved:

- **The trial measures an unrepresentative population.** Because any moderator note
  anywhere vetoes auto-approval (see above), and note-writing habits vary enormously
  between teams, the posts that actually auto-publish are skewed towards members nobody
  has ever written about. A low error rate in the trial may partly reflect that
  selection rather than the automation being sound - worth stating alongside the
  numbers rather than discovering later.
- **The trial numbers live under SysAdmin,** which ordinary moderators cannot see.
  Trial results have to be actively published to moderators (on Discourse); "it's
  available" is not true for the people being asked to trust it. Widening access to
  that page is a fair ask and has not been done.
- **A post inside its one-hour rippling hold is not yet in the reach-based Nearby
  browse feed.** It is live on its own community's page and in "All my communities",
  and its community's email digests (immediate and daily) go out as normal - but the
  Nearby feed (and the search that mirrors it) only picks the post up once rippling
  initialises. For that first hour an auto-published post is less visible than a
  mod-approved one.
- **The review-delay instrumentation measures pauses, not the initial hold.** The
  SysAdmin panel shows time posts spent paused by the exposure gate; the fixed
  one-hour hold is not included in those figures.
- **The exposure gate advances a whole expansion step at once.** If the next step of
  spread would add three communities, the post waits until it has enough views to
  cover all three; it does not trickle into them one at a time.
- **A flag is deliberately crude** (see "Gaming, abuse and surprises"): any message from
  a member to a mod team referencing the post counts, so innocent messages pause posts.
  Conservative by design - a false pause costs a delay, a false pass spreads a bad post -
  but it is also the cheapest available way to stall someone else's post.
- **A paused post is not visible as paused anywhere per-post.** Only the SysAdmin
  aggregate shows how much is being held; there is no queue of gated posts and no badge,
  so a moderator cannot see that a particular post is waiting on them.
- **"Seen" is not "scrutinised".** Views are counted from anyone whose browse list the
  post passed through, with no check that they are near enough to collect the item or
  that they read it. A post can therefore earn its spread from people who were never
  going to act on it, and the crowd signal is weaker than "five people vouched for it"
  sounds. Tightening it (in-reach viewers only, dwell time, trusted accounts) is a
  known lever that is not built.
- **Wanted-versus-offer error rates may differ,** and restricting auto-approve by
  post type (Offers first) is an obvious lever that is **not built**. The analytics
  split exists so the trial can show whether it is needed.
- **Fastest-finger-first.** Faster publication rewards quick responders, and leaning
  on moderation delay to blunt that was always indirect. A better fairness mechanism
  (for example a short reply-gathering window before the offerer chooses) deserves
  designing in its own right; it is not part of this change.

## What changes for moderators, and where you see it

- **Pending** is still the full queue, and you can still stop anything before it goes
  live. New on each post that is on the auto-approve path: a **live countdown** to
  when it will publish (muted "~Nh" when far off, prominent minutes-and-seconds inside
  the last half hour). Posts that are not going to auto-approve (suspect, spam, held,
  sampled for quality, or the feature is off for your community) show no countdown.
- **Opening Pending guarantees you a look.** Every post on the queue gets at least 10
  more minutes before it can auto-approve, extended each time you load the page.
  Nothing publishes out from under you while you are reading it.
- **Two oversight queues** sit alongside Pending and Approved (on every community, in
  the trial or not - see "What a community NOT taking part still sees"):
  - **Checked** - posts that went live via the automatic checks.
  - **Trusted** - posts that went live because the member is trusted.
  Both show a blue count of what you have not yet looked at, offer **"Mark all as
  checked"**, and age posts out after **7 days** so they never pile up. Posts that
  merely rippled in from another community do not appear - overseeing those belongs
  to the community they were posted on.
- **Reject from the oversight queue** pulls a live post straight back to Pending and
  held, stops its rippling immediately, withdraws the copies it had rippled into other
  communities, and records the rejection - which both feeds the error rate and becomes
  a danger signal on that member's next post.
- **Your notes and statuses matter more, not less.** A note on a member keeps their
  posts in Pending. Explicit statuses always win. If you know a reason someone needs
  a human eye, write it down.
- **Analytics** (currently SysAdmin-only, see above): where posts went, the
  auto-publish error rate, quality-sample verdicts against it, and the review-gate
  delay figures.
- **Your day-to-day habits are unchanged otherwise** - including the rippling ones:
  "out of area" is still never a reason to reject, and a post arriving from a
  neighbouring community is still the system working.

## What changes for members, and what they notice

- **Posting feels faster.** On a community with post-moderation on, a settled member's
  clean post is live in about 20 minutes, at any hour, rather than when a moderator is
  next at their screen. That is the entire point, and it is the main visible change.
- **Nothing marks a post as auto-approved.** Members do not see a badge, and a poster
  is not told whether a human approved their post. A post pulled back by a moderator
  behaves exactly like one rejected from Pending today.
- **Suspect posts behave exactly as now.** A member whose post trips the checks or
  carries a danger signal sees the same "your post is awaiting approval" experience
  as today.
- **Auto-published posts start local and spread as they are seen.** A member's
  auto-published post waits about an hour, then ripples outwards at the pace of its
  clean exposure. For most posts this is invisible - nearby people see it immediately
  and reach grows within hours - but it is slower to travel than a mod-approved post,
  and for its first hour it appears on its own community's page but not yet in the
  Nearby feed (see "Remaining problems").
- **Reporting a post quietly freezes its spread.** A member who reports a post will
  not see anything happen immediately - the post stays where it already is - but it
  stops reaching new people until a moderator has looked.
- **Microvolunteers** ("Does this look OK?") are drawn from within the area a post can
  reach, nearest first, so the people asked to look at a post are the ones with local
  context - and their rejection is one of the brakes on its spread.
