# A Freegle that runs itself: what the experiment changes, in plain English

**This is an experiment, not a plan.** Nothing here has been agreed. We built it to find out
where Freegle's local communities, and its habit of waiting for a volunteer, get in members'
way, and how much simpler the software becomes without them. It lives on a branch of the code
that nobody has merged, so the real site is untouched. Humans decide whether any of it goes
further.

## The approach: the system always reaches an outcome; volunteers only add to it

The rule behind every change is this. Freegle must reach an outcome on its own, with nobody
there: a post goes live or comes down, a report is settled, a message is delivered, and the
member is told what happened and why. Volunteers are welcome, and can improve any of those
outcomes, but nothing waits for one and nothing depends on one being there. So "no volunteer
needed" and "a volunteer can step in" are both true at once: the site does not need them, and
it is better when they are around.

Two habits follow from that rule and run through everything below. First, every automatic
decision is reversible: a post that comes down can be put back with one tap, and the member
hears both times. Second, nothing is silently hidden. If the system does something to your
post or your message, it tells you.

## One Freegle, not hundreds of communities

Today every member belongs to one or more local communities, each with its own volunteers,
settings, rules and email options. A post is copied into each community it reaches, and a
great deal of the software exists to manage those copies: which community approved it, which
community's rules applied, which community's volunteers see the report.

In the experiment there is one Freegle. You see posts near you because they are near you, not
because you joined the right community. Nobody joins, picks or leaves a community. There is one
set of rules for everyone, one page that states them, and one choice about how often you want
email. A post exists once, with one location and one state.

Posts still spread outwards over time to reach more people. That part of "rippling out" stays
exactly as it is: each post has an area that grows on a schedule, and you see a post when your
location is inside its area. What goes is only the layer on top: copies per community,
automatically joining you to a community a post reached, and community opt-outs.

## What a member sees day to day

- **Browsing** is by distance. The nearby feed, search and the map work as now.
- **Posting**: you write the post as now. A clean post is live at once. If a check is unsure
  about it, it waits a few minutes and then goes live. If it breaks a rule it comes down and
  you get a message in your Freegle chat saying which post, why, in a sentence you can read,
  and that you can fix it and post again. Your "My posts" page shows it as taken down with the
  same reason.
- **Chat** works as now. A message that looks risky (asking for money, a link off the site,
  abuse) is delivered with a warning the reader taps through, rather than held back where
  neither of you can see it.
- **Settings**: one email frequency (immediately, daily, never), one switch for events and one
  for volunteering, and one rules page.
- **Reporting**: the report button no longer asks which community to tell. You are told the
  outcome.
- **Replying a lot**: after five replies in one day, your next reply asks you first to answer a
  short check: a post other members have already judged, where you say whether it follows the
  rules. A right answer lets you carry on. Nobody sets this up, and a wrong answer does not
  open it.

## The rules are judged by AI, not by lists of words

Today posts are checked against lists of suspicious words kept by volunteers, community by
community. Word lists are blunt: "cat" flags a cat bed, "gun" flags a glue gun, and nobody can
keep the lists complete.

In the experiment an AI reads each post and answers the rules as questions, twelve of them:

- Is the poster asking for money, selling, or swapping for payment?
- Is the item illegal to give away or own, or counterfeit?
- Is this a medicine or a medical device meant for one patient?
- Is it age-restricted: alcohol, tobacco, vapes, fireworks, adult material?
- Is it a live animal, rather than food, bedding or equipment for an animal?
- Is it a weapon or ammunition?
- Is it a ticket, voucher, gift card or anything with cash value?
- Is it something other than an item: a service, a job advert, a business advert, a survey?
- Does it look like a scam, or an attempt to move people off Freegle?
- Is the text abusive or threatening?
- Does the poster describe a safety defect that makes the item dangerous?
- Does the title fail to say what the item actually is ("stuff", "various items")?

For each question the AI answers yes or no, says how sure it is, and gives a one-sentence
reason a person can read. The first ten are rules; a confident yes takes the post down and the
poster sees that sentence. The last two are only ever a short wait, with a friendly suggestion
to the poster. When the AI is not sure, the post waits a few minutes rather than being removed.
If the AI is unavailable, nothing is held back and nothing is removed because of it. Exact
things, like phone numbers, email addresses and web links, are still spotted by ordinary code,
which is cheaper and never wrong about them.

Chat messages get three of the questions (money, scam, abuse). Events, volunteering
opportunities and chit-chat posts get two (scam, abuse). Only the words of the post and its
photos are sent to the AI; no names, email addresses or locations go with them.

## How reports work

A report is dealt with within a minute, by the system. The AI is asked one extra question:
"A member reported this post saying X. Is that justified?" Then:

- Two members reporting the same post is enough to take it down.
- One report is enough if the AI agrees with the reporter.
- Three are needed if the AI is confident the report is unjustified, so two people cannot
  remove a post they simply dislike.
- One report from a volunteer is always enough.

When a post comes down, the poster is told why and how to fix it, and everyone who reported it
is told the outcome. A volunteer can restore it, and the poster is told again.

## What volunteers do instead

A national group of no more than ten volunteers, instead of local teams for each community.
Their tool stops being a set of queues that must be cleared and becomes a page of things they
could add today, each described as what the system already did and one thing a person could do
on top:

| The system already | A volunteer could |
|---|---|
| Published these posts | Tidy a title, add a category, or take one down with a reason the poster sees |
| Took these posts down and told the posters | Put one back |
| Delivered these chat messages behind a warning | Hide one again |
| Sent an automatic reply to members who wrote in | Add a human reply |
| Welcomed these new members | Add a personal welcome |
| Recorded these completed freegles | Send a thank you |
| Noted a signal about these members, and blocked nothing | Look, and ban if warranted |
| Published these events and volunteering opportunities | Remove or improve one |
| Blocked known spammers | Confirm or clear a pending spammer report |

If nobody opens the tool for a month, nothing on the site stalls or stays hidden. There are no
counts of things waiting, and no emails chasing volunteers to do work. Member search, banning,
notes, merging duplicate accounts and the support tools stay.

## How TrashNothing keeps working without communities

TrashNothing is a partner site that shows Freegle posts and sends its members' posts to
Freegle. It talks to Freegle in the language of communities: it sends a post "to
cambridge-freegle", it subscribes or unsubscribes a member "from cambridge-freegle", and it
identifies each of its members by a community.

The experiment keeps a small hidden table for exactly this purpose: the short name of each
community TrashNothing used, and the map area that community covered. Nothing a member sees
reads that table. When a TrashNothing post arrives addressed to a community, Freegle looks up
the name and gives the post a location: the postcode in the post if there is one, otherwise the
centre of that community's old area. When TrashNothing subscribes or unsubscribes a member, that
sets the member's one email choice (daily, or never). When it creates a member, the member gets
a location from the area.

TrashNothing also reads from Freegle, not just writes to it. Every few minutes it asks our
API "what has changed since I last looked?" and then fetches the posts it is told about, and
it files each post under one of its own groups by reading which Freegle community the post
belongs to. Those replies keep naming a community: when the request comes from a partner,
Freegle works out which old community area the post's location falls in (the smallest one,
or the nearest within twenty miles) and puts that name and number in the reply, exactly as
before. Requests from ordinary members never see it. TrashNothing also tells us about its
members' profile changes and the ratings they give, which never involved communities and
carries on unchanged. So TrashNothing keeps speaking about communities, in both directions,
and Freegle quietly translates.

## What stays the same

Offering and asking for things, chatting, the nearby feed, search, the app, email digests
(now by where you live rather than what you joined), stories, donations, and the link with
TrashNothing.

## What changes in the database

One change, in three steps that can be run on the live database without downtime: first add
the new places for information (a post's state on the post, a member's email choice on the
member), then move the information across, then drop the community tables and every column
that pointed at them. The new software is deployed between the second and third steps. The
third step cannot be undone, which is one more reason this is an experiment.

## How much simpler is the software?

The point of the exercise is to measure this, not to assert it. The branch carries a script
that compares the code before and after: how many lines, how many functions, how many places
the code has to make a decision, how many web addresses the servers answer, how many pages and
screens, how many tables and columns in the database. The final numbers go in the pull request
when the work is complete; the early figures show every part of the system shrinking, with the
volunteer tools shrinking most.

## The risks, honestly

- **The AI will sometimes be wrong.** The design accepts that and makes every mistake visible,
  reversible and explained to the member, rather than trying to make the AI perfect. A
  confident wrong answer takes a good post down for a while; an unsure wrong answer costs a few
  minutes' wait.
- **Post text and photos go to an outside AI company.** No names, emails or locations go with
  them, and chat messages from members in good standing are not sent at all, but this is a
  privacy decision that people, not the code, would have to take before it went further.
- **Local knowledge is lost.** A rule one community had for a good local reason has no home. A
  rule that matters everywhere is one edit to the questions.
- **Fewer people are looking.** That is deliberate, but it changes what Freegle is, and it
  changes what volunteering at Freegle means.
- **Two members could still gang up on a post** unless the AI is confident the report is unfair.
- **The AI costs money for every post it reads.** The cost is measured for every call and the
  choice of model is a setting; a cheaper model can be used for chat.
- **TrashNothing depends on the hidden table staying accurate.** If a community boundary was
  wrong before, it stays wrong for TrashNothing posts, and a post that falls outside every old
  area is filed under the nearest one.

## Where it is

A branch with the code, screenshots of each change, the before-and-after measurements, and a
page for developers listing every task a person used to do, what replaced it, how, the risk
and the mitigation.
