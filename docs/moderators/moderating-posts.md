---
last_reviewed: 2026-09-20
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/**
  - iznik-nuxt3/modtools/components/ModMessage*.vue
  - iznik-nuxt3/modtools/components/ModStdMessageModal.vue
  - iznik-nuxt3/modtools/utils/stdMessageDirectives.js
  - iznik-batch/app/Services/Judgement/**
  - iznik-batch/app/Services/TakedownService.php
  - iznik-batch/app/Services/Ripple/**
  # cross-stack behaviour tests (change when the behaviour changes)
  - iznik-nuxt3/tests/e2e/test-modtools-pending-messages.spec.js
  - iznik-nuxt3/tests/e2e/test-modtools-edits.spec.js
  - iznik-server-go/test/modtools_edits_rippled_in_test.go
  - iznik-batch/tests/Unit/Services/Ripple/**
---

# Moderating posts

Freegle checks every post itself before it goes live: a set of deterministic checks, and
a judge that reads the post and answers a fixed set of questions about it (is it free, is
it legal, is it safe to hand over, and so on). A post only waits if one of those checks
finds a real problem; otherwise it goes live after a short wait. ModTools does not sit in
front of that decision. It is where you look at what the system already did, and add the
one thing a person is better at: judgement on the cases that are genuinely unclear.

## Just published

Recently published posts. Freegle checked each one and let it through. Skim this list for
anything the checks would not catch, such as a hidden brand in a photo. You can:

- **Edit** it inline: fix the subject, description, item, location, photos or post type.
  For a well-formed OFFER or WANTED, editing the item and location rebuilds the subject
  automatically.
- **Take it down**, with a friendly standard message explaining why. The poster is told,
  and can fix the problem and repost.

## Taken down

Posts the system has already taken down, each with the reason shown: which deterministic
check fired, or which of the judge's questions it answered "no" to. Read the reason before
you act. You can:

- **Restore** it, if the takedown looks wrong. This is the main reason to look at this
  list at all: catching a post the checks were too cautious about.
- Leave it taken down, and add a note if it helps the next person who looks.

## Held chat messages

Chat messages the system held for a look, because they mention money, a phone number, an
email address, or other worry words, or because the judge flagged them. You can:

- **Release** the message, so it reaches the other person.
- Add a moderator note that both people in the chat can see.
- **Delete all** to clear the list.

Some messages are held for a different reason: the post they reply to has not yet reached
that far in its reach. Those release themselves once the reach catches up; you do not need
to do anything. See [how far a post travels](../members/rippling-out.md).

### Putting one member's chat under review

A member's own **Support** record has a **Chat Moderation** setting, which decides what
happens to every message they send:

- **Moderated** - the default. Messages are checked and held if they match.
- **Unmoderated** - those checks are skipped.
- **Fully moderated** - every message they send is held for review before it reaches the
  other person.

"Fully moderated" is effectively a shadow ban: the member sees their message sent as normal
and gets no indication that it is waiting for a moderator. Use it for someone whose messages
all need reading before they go out. Set it on each account you have linked to them; it
follows the account, not the person. The setting is recorded in the member's logs, with who
changed it.

Once a member is under review, later messages in the same conversation stay held while an
earlier one is unreviewed, so a chat cannot skip ahead while an earlier message is still
waiting for you.

Approving a held message with **approve all future** turns the setting off for that member,
so use plain approve if you want them to stay under review.

(This is different from moderating the **ChitChat** discussion feed, which is done on the
main Freegle site by the ChitChat Moderation team, not in ModTools.)

## Messages to Freegle

Messages members send to the volunteer team, rather than to another member: a question, a
complaint, or an appeal against a takedown or a ban. Reply from here, in your own words or
with a standard message.

## Events and volunteering

Local events and volunteering opportunities members have added. Freegle already checked
them the same way it checks posts. You can edit or take one down if it is wrong or
out of date.

## Completed freegles

Posts recently marked **Taken**, **Received** or **Withdrawn**, whether the member did it
themselves or Freegle inferred it from a chat. You can mark one on a member's behalf if
they ask you to and cannot do it themselves.

## Standard messages

Most actions send a **standard message** - a canned, editable reply for a common
situation (edit, take down, and so on). Standard message sets are national, not per
community. Each action can be marked:

- **rarely used**, so it hides behind a "more" expander, and
- **autosend**, so one click sends it, versus opening it for editing first. You can flip
  between "autosend" and "edit first" for a session.

Keep them friendly and personal. A short human note lands far better than a corporate one.

### Fill-in boxes and optional bits

A standard message can mark parts of its wording with `<editthis>...</editthis>` (something
you must write yourself each time) or `<optional>...</optional>` (something that only
applies sometimes). You add those tags when you write the message under Settings.

When a message uses either of them, sending it opens the message laid out in order rather
than as one block of text:

- An **`<editthis>`** part is a highlighted box. Fill it in before you send.
- An **`<optional>`** part comes with **Keep** and **Remove**. You must choose one, and you
  can change your mind afterwards either way. The wording itself stays editable, so you can
  reword it as well as take it or leave it.
- The rest of the wording is editable too.
- Between the parts you will see whether there is a **blank line between these paragraphs**,
  with a click to add or remove one. That is what controls the spacing the member sees, so
  if a message arrives with its paragraphs run together, this is where you fix it.

If something is still outstanding, sending is refused: nothing goes to the member, the
outstanding boxes are outlined in red, and the message tells you what is left to do.

## Suggested edits

Members can suggest edits to their own posts. **Messages > Edits** (`/messages/edits`)
shows these with an old-to-new difference, and you **Accept Edit** or **Reject Edit**.

## Marking as spam versus taking down

If a post really is spam, use **Take down as spam** rather than a plain takedown. Marking
it as spam feeds Freegle's checks so similar posts are caught in future. You can also
**Report Spammer** straight from a post - see [Managing members](managing-members.md).

## How reports and restores work

Anyone can report a live post. Freegle resolves reports itself, every minute, without a
moderator queue:

- Two different members reporting the same post is enough on its own to take it down.
- One report is enough if the judge, asked separately, agrees there is a problem.
- It takes three reports if the judge disagrees, since a single objector should not be
  able to take down a post the judge thinks is fine.

Once resolved, the post shows up in **Taken down** with the reason, or stays live with
nothing for you to do. A moderator can also take a post down directly, or restore one,
from **Just published** or **Taken down** - your action is not a vote and does not need a
quorum.

## Next steps

- Looking after the people behind the posts: [Managing members](managing-members.md).
- Other tools in ModTools: [README](README.md#other-modtools-pages).
