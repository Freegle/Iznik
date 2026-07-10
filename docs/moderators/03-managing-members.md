---
last_reviewed: 2026-07-09
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/members/**
  - iznik-nuxt3/modtools/pages/chats/**
  - iznik-nuxt3/modtools/pages/spammers.vue
  - iznik-nuxt3/modtools/components/ModMember*.vue
  # cross-stack behaviour tests (change when the behaviour changes)
  - iznik-nuxt3/tests/e2e/test-modtools-member-review.spec.js
  - iznik-nuxt3/tests/e2e/test-modtools-spammers.spec.js
---

# Managing members

Most of moderation is about people, not posts. ModTools gives you tools to welcome, help,
review and, when you must, remove members.

## The members list

**Members > Approved** (`/members/approved`) lists a community's members.

From here you can:

- **Filter** by type (with notes, moderators, bouncing email, banned, mod-mails) and
  **search** by name, email or id.
- **Add** a member by email (this sends the standard welcome).
- **Ban** a member by id, with a reason.
- **Merge** two accounts that are the same person (irreversible; you choose which email
  survives).
- **Export** the members list.
- Change a member's **role** (Member, Moderator, Owner).

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
same person (same device, similar details, shared communities). You can **ignore** the
pair, or send the member a friendly "let us know" email so **they** decide whether and how
to merge their own accounts.

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

## Banning and removing

On a member you can:

- **Remove** them from the community (they simply leave), or
- **Ban** them, which requires a reason and is confirmed with an extra step. A note is
  recorded automatically.

Removing or banning is a last resort. A friendly word usually solves the problem.

## Feedback and micro-volunteering

- **Members > Feedback** (`/members/feedback`) collects members' free-text feedback and
  happy/unhappy ratings, with charts, so you can see how the community feels.
- **Members > Micro-volunteering** (`/members/microvolunteering`) shows the members who
  help moderate through lightweight review tasks, with an accuracy score. These members
  are a real help; a thank-you goes a long way.

## Next steps

- The queues these members' posts flow through: [Moderating posts](02-moderating-posts.md).
- Community-level configuration: [Running your community](04-running-your-community.md).
