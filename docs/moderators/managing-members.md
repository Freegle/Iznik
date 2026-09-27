---
last_reviewed: 2026-09-25
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/members/**
  - iznik-nuxt3/modtools/pages/chats/**
  - iznik-nuxt3/modtools/pages/spammers.vue
  - iznik-nuxt3/modtools/components/ModMember*.vue
  - iznik-nuxt3/modtools/components/ModRelatedMember.vue
  - iznik-nuxt3/modtools/components/ModSupportUser.vue
  - iznik-batch/app/Services/ChatProcessService.php
  - iznik-batch/app/Console/Commands/User/DetectRelatedAccountsCommand.php
  # cross-stack behaviour tests (change when the behaviour changes)
  - iznik-nuxt3/tests/e2e/test-modtools-member-review.spec.js
  - iznik-nuxt3/tests/e2e/test-modtools-spammers.spec.js
  - iznik-server-go/test/modmessaging_test.go
---

# Managing members

Most of moderation is about people, not posts. ModTools gives you tools to welcome, help,
review and, when you must, remove members.

## The members list

![The members list](assets/members.png)

**Members > Approved** (`/members/approved`) lists a community's members.

From here you can:

- **Filter** by type (with notes, moderators, bouncing email, banned, mod-mails) and
  **search** by name, email or id.
- **Add** a member by email (this sends the standard welcome).
- **Ban** a member by id, with a reason.
- **Merge** two accounts that are the same person (irreversible; you choose which email
  survives).
- Change a member's **role** (Member, Moderator, Owner).

### How far a search reaches

A search only ever looks at the communities **you** moderate. That is true whichever way
you search - by name, by email, or by the member's number.

- With a community chosen in the box at the top left, you search that community.
- With **-- Please choose --** selected, you search every community you moderate at once.
  This is the one to use when somebody reports a member and you do not know which
  community they are on.

So a search that finds nobody does not mean the account does not exist. It usually means
that freegler has only ever joined communities you do not moderate, and rippling makes
that more common: a post can reach your community from a freegler who is not on it.

Someone with **Support** access can look up any freegler on the system, on any community,
so ask them when you need to see an account that is outside your own communities. See
[Support Tools](https://wiki.ilovefreegle.org/Support_Tools) on the wiki.

You can type the member's number with or without a `#` in front of it. Both work.

## "Email delayed" is not the same as "bouncing"

You may see a blue notice on a member saying **Email delayed since <date> - <provider> is
not currently accepting our mail**.

This is not a problem with that member's email address, and it is nothing they or you can
fix. It means the company that runs their email - Yahoo, Microsoft, whoever - has
temporarily stopped accepting mail from Freegle's sending servers. It affects everyone at
that provider at once, so if you see it on one member you will usually see it on many.

While that is going on we deliberately stop generating their emails rather than pile up
mail that cannot be delivered, and the notice tells you roughly how many we have held
back. When the provider starts accepting us again, they get a catch-up digest and a
summary of any unread chats. Stale post notifications are not resent, because by then the
item has usually gone.

So: nothing to do. Do not chase the member, and do not remove them.

Contrast this with the red **bouncing** notice, which really does mean their address is
rejecting mail - a closed account, a typo, a full mailbox - and where reactivating after
they have fixed it is the right move.

## Member review

**Members > Review** (`/members/review`) is a queue of members automatically flagged by
Freegle's unusual-behaviour checks, across all your communities. For each, you see notes,
spammer status, whether they are active in places far apart, whether they have changed
location repeatedly, bouncing-email status and ban history, plus a postcode tester.

Treat these as prompts to look, not verdicts. There is no rule against joining several
communities or posting enthusiastically. Ban only with clear evidence of harm, and prefer
leaving well-meaning members alone.

## Related members

**Members > Related** (`/members/related`) surfaces pairs of accounts that look like the
same person, or the same household. Each card says why the pair was picked up:

- both accounts were signed in from the same browser
- both gave the same mobile number in chat
- both gave the same street address in chat

The note names the accounts and says how many messages the details appeared in, and when, so
you can usually judge the pair without opening either chat.

If the two accounts have also replied to the same post, the note says so. That one is worth
a closer look. Someone with two accounts normally replies to different posts, so replying to
the same one means either they lost track of which account they were in, or they are putting
themselves forward twice for the same item.

Most pairs are innocent. People forget a password and register again, or a couple share a
phone. Nobody is blocked or flagged by appearing here. You can **ignore** the pair, or send
the member a friendly "let us know" email so **they** decide whether and how to merge their
own accounts. Merging helps, because replies sent to an account somebody has stopped using
are never read.

A shared postcode on its own is deliberately not enough to pair two accounts. A UK postcode
covers around fifteen homes, so it would pair neighbours.

## Spammers

**Spammers** (`/spammers`) is Freegle's shared spammer list. Everyone can search and view
confirmed spammers; with the Spam admin permission you also handle pending additions,
safelisting and removals. You can add a member to the list, safelist someone wrongly
flagged, or request a removal.

Reporting a genuine spammer helps every community, not just yours.

## Chat review

**Chats > Review** (`/chats/review`) shows member-to-member chat messages automatically
flagged for **worry words** (money, phone numbers, email addresses, bad language) or by a
community's "quicker chat review" setting. You can approve a message, or add a moderator
note that both people in the chat can see. "Delete All" clears the queue.

Some chats are held because a post has not yet rippled out to the member who replied.
Those release automatically; you do not need to do anything. See
[./rippling-out.md](./rippling-out.md).

A message that matches a Freegle-wide **block** keyword does not come to this queue at
all. It is dropped: the other person never receives it, and it is marked rejected with
the reason recorded, exactly as if you had rejected it yourself. That applies whatever
the sender's chat moderation setting, apart from Unmoderated. Block keywords are
Freegle-wide scam signatures kept by support; the words that bring a message here for
you to judge are flag keywords.

### Putting one member's chat under review

A member's own **Support** record has a **Chat Moderation** setting, which decides what
happens to every message they send:

- **Moderated** - the default. Messages are checked for worry words and held if they match.
- **Unmoderated** - those checks are skipped.
- **Fully moderated** - every message they send is held for review before it reaches the
  other person.

"Fully moderated" is effectively a shadow ban: the member sees their message sent as normal
and gets no indication that it is waiting for a moderator. Use it for someone whose messages
all need reading before they go out - for example a member who keeps returning under new
accounts. Set it on each account you have linked to them; it follows the account, not the
person. The setting is recorded in the member's logs, with who changed it.

Once a member is under review, later messages in the same conversation stay held while an
earlier one is unreviewed, so a chat cannot get ahead of the queue.

Approving a held message with **approve all future** turns the setting off for that member,
so use plain approve if you want them to stay under review.

(This is different from moderating the **ChitChat** discussion feed, which is done on the
main Freegle site by the ChitChat Moderation team, not in ModTools.)

## Notes about members

**Members > Notes** (`/members/notes`) is a feed of moderator notes. Flagged notes are
always visible; others are scoped to the selected community. You can add a note from many
places - a member row, the sender panel on a post, or the ban dialog, which records who
banned whom and why. Notes are how the team keeps a shared memory of a member.

## Messaging a member

- Use the **Mail** or **Leave** standard-message buttons on a member row.
- Or open **Chats** (`/chats`), which lists your conversations with members and with other
  moderators, with a chat pane and search.

### Trash Nothing members who didn't choose your community

Some members exist here only because posts of theirs came in from Trash Nothing and we
matched them to a community from where they are - they never chose your community. Those
members carry a warning on their member row saying so, and the **Chat**, **Mail** and
standard-message buttons are not offered, because there is no relationship to use and no
way for them to reply to you. You can still remove or ban them.

If the same person also posts to a community they've chosen, the warning disappears on its own and
they become an ordinary member you can contact as usual.

## Banning and removing

On a member you can:

- **Remove** them from the community (they simply leave), or
- **Ban** them, which requires a reason and is confirmed with an extra step. A note is
  recorded automatically.

Removing or banning is a last resort. A friendly word usually solves the problem.

A ban stops someone posting on your community and replying to posts there. It does **not**
stop them writing to your volunteers, by email or with the Contact button on your community
page, and that is deliberate: it is how someone appeals a ban. Their message arrives in
their chat with your volunteers, which every moderator on the community can see.

The **spammer list** is the stronger measure. Someone on it is banned everywhere, and
nothing they send reaches us at all: their email is dropped, the volunteers address
included, and a message to volunteers from the site or app is never delivered. It does not
lock their account, so they can still sign in. Someone only *proposed* for the list, and
still waiting on a second moderator, can write to volunteers as normal, so they can put
their case before the decision is made.

Some members are on your list only because a post of theirs **rippled in**: rippling
joins the poster so the post can live on your community. That is not a relationship with
you, so such a member has no **Chat** button and no standard messages that only write to
them, and the removal standard message shows a plain confirmation instead of a compose
box. You can still remove or ban them, and it is logged as usual - quietly, since they
never joined you. If they later join, or move into your area, the membership becomes an
ordinary one. See [rippling out](rippling-out.md).

## Feedback and micro-volunteering

- **Members > Feedback** (`/members/feedback`) collects members' free-text feedback and
  happy/unhappy ratings, with charts, so you can see how the community feels.
- **Members > Micro-volunteering** (`/members/microvolunteering`) shows the members who
  help moderate through lightweight review tasks, with an accuracy score. These members
  are a real help; a thank-you goes a long way.

## Next steps

- The queues these members' posts flow through: [Moderating posts](moderating-posts.md).
- Community-level configuration: [Running your community](running-your-community.md).
