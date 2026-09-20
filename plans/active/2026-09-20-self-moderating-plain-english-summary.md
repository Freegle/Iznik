# A Freegle that runs itself: what the experiment changes, in plain English

**This is an experiment, not a plan.** Nothing here has been agreed. We built it to find out
where Freegle's local communities, and its habit of waiting for a volunteer, get in members'
way. It lives on a branch of the code that nobody has merged, so the real site is untouched.

## One Freegle, not hundreds of communities

Today every member belongs to one or more local communities, each with its own volunteers,
settings, rules and email options. In the experiment there is one Freegle. You see posts near
you because they are near you, not because you joined the right community. Nobody joins,
picks or leaves a community, there is one set of rules for everyone, and one choice about how
often you want email. Posts still spread outwards over time to reach more people; that part
of "rippling out" stays exactly as it is.

## The approach: the system always reaches an outcome; volunteers only add to it

The rule behind every change is this. Freegle must reach an outcome on its own, with nobody
there: a post goes live or comes down, a report is settled, a message is delivered, and the
member is told what happened and why. Volunteers are welcome, and can improve any of those
outcomes, but nothing waits for one and nothing depends on one being there. So "no volunteer
needed" and "a volunteer can step in" are both true at once: the site does not need them,
and it is better when they are around.

## Nothing waits for a volunteer

Today a post can sit for hours until a volunteer approves it, a message can be held for review
without the person it was sent to ever knowing, and a report can wait for someone to look. In
the experiment:

- A post that passes the checks is live at once. One that looks doubtful waits a few minutes
  and then goes live. One that breaks the rules is taken down straight away, and the poster is
  told which post, why, and how to fix it and post again.
- A chat message that looks risky (asking for money, a link to another site) is delivered with
  a warning the reader taps through, rather than held back.
- A report is dealt with within a minute. Two members reporting the same post, or one
  volunteer, is enough to take it down. The poster and the people who reported it are told.
- Someone who has replied to five posts in one day has to pass a quick check, a small
  moderation task with a known right answer, before their next reply.

None of this needs a volunteer. If one is around, they can undo any of it with one tap, and the member is told again.

## The rules are judged by AI, not by lists of words

Today posts are checked against lists of suspicious words, kept by volunteers. In the
experiment an AI reads each post and answers the rules as questions: "Is this a live animal,
rather than something for an animal?", "Is the poster asking for money?", "Is this a medicine?".
It gives a reason a person can read, which is what the poster sees if the post comes down.
When it is not sure, the post waits a few minutes instead of being removed. If the AI is
unavailable, nothing is held back and nothing is removed because of it. Exact things, like
phone numbers and web links, are still spotted by ordinary code.

## What volunteers do instead

A national group of no more than ten volunteers, instead of local teams. Their tool stops being
a queue of things that must be cleared and becomes a list of things they could add today: fix a
title, restore a post the system took down by mistake, welcome a new member, reply to someone
who wrote in, remove an event that should not be there. If nobody opens the tool for a month,
nothing on the site stalls or stays hidden.

## What stays the same

Offering and asking for things, chatting, the nearby feed, search, the app, email digests, and
the link with TrashNothing, which keeps sending and receiving posts as before.

## The risks, honestly

- The AI will sometimes be wrong. The design accepts that and makes every mistake visible,
  reversible and explained to the member, rather than trying to make the AI perfect.
- Post text and photos are sent to an outside AI company. No names, emails or locations go
  with them, but this is a privacy decision people would need to take before it went further.
- Local knowledge is lost: a rule one community had for a good local reason has no home.
- Fewer people are looking. That is deliberate, but it is a change in what Freegle is.
- The AI costs money for every post it reads. The cost is measured and the model is a setting.

## Where it is

A branch with the code, screenshots of each change, and a page for developers listing every
task a person used to do and what replaced it. Humans decide whether any of it goes further.
