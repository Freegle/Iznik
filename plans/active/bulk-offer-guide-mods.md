# Clearance offers: a guide for moderators

A **clearance offer** is an ordinary Offer post that happens to carry a list of items rather than
just one thing. A member offering a house clearance, an office move or a big clearout posts a single
message, and people browsing it can tick the specific items they want and say how many. The giver
then allocates items to people and marks them collected.

For you as a moderator, the important thing to know is: **a clearance offer is moderated exactly like
any other offer.** The catalogue of items is extra information attached to the post; it does not
change the moderation workflow. There is no special "bulk" message type and no separate queue.

---

## Spotting one in the queue

In the Pending or Approved list a clearance offer looks like any other Offer. There is no special icon
in the list view. You only see that it is a clearance once you open (expand) the post.

## What you see when you expand the post

On top of the normal subject, body and photos, a clearance offer shows three extra things:

1. **A blue information alert:** "Bulk clearance - N items. This is a single post on the group, but
   when a member opens it they can see each item and turn on the ones they'd like (and how many)."
   The number tells you how many items are in the catalogue.

2. **A "See how members see it" button.** This opens a read-only preview (see below).

3. **A collapsible "Show access instructions" section** - only if the giver added access
   instructions. (See "Access instructions" below.)

## Previewing the member catalogue

Click **See how members see it** to open a preview modal. It shows the post title and body, then the
list of items - each with a thumbnail, item number, name, condition, quantity and size. The toggle
switches are shown but disabled, so you see exactly what a member sees without being able to interact.

The preview shows **no** interest or replier information. It is purely "this is what a member browsing
the post will see".

## Access instructions

If the giver entered access instructions (a pickup address, a gate code, parking notes and so on),
they appear in the collapsible section. This text is **private**: it is shown to the giver and to
moderators, and is sent automatically to each person the giver allocates an item to. Ordinary members
browsing the post never see it. Please do not share it publicly.

## Approving or rejecting

Use the normal **Approve / Reject / Spam / Edit** buttons. Approving a clearance post works exactly
the same as approving any other offer. The item catalogue has no bearing on the decision - judge the
post on the same basis you always would.

---

## The Freegle Helper (AI concierge) and the AI badge

A giver can opt in to the **Freegle Helper**, an AI concierge that chats with interested members on
the giver's behalf - for example to gather collection details. When the Helper sends a chat message,
it is clearly marked.

- In **Chat Review**, a message sent by the Helper carries a small **AI / Helper** badge (a robot
  icon). This lets you tell at a glance that a message was written by the AI, so you can judge it
  appropriately. You moderate it the same way you would any chat message.
- The Helper can only send conversational messages and register interest on the giver's behalf. It
  **cannot** allocate items, change outcomes, or take any moderation action.

### Helper flow diagram

There is a reference page at **`/modtools/helper-flow`** showing a diagram of the Helper's conversation
states (NEW, GATHERING, QUALIFIED, ESCALATED, ALLOCATED, CONFIRMED, COLLECTED, PARKED, TIMED_OUT,
REJECTED). Two states are worth knowing:

- **ESCALATED** (yellow) - the Helper has decided a human needs to step in before it can continue (for
  example a subjective question it should not answer itself).
- **QUALIFIED** (green) - the Helper has gathered everything it needs and a human allocation decision
  is due.

This page is for understanding what the Helper is doing. It is not linked from the navigation, so you
reach it by going to the address directly.

---

## Current limits worth knowing

These are known gaps in the current version, so you are not surprised by them:

- **You cannot see the interest list in ModTools.** The full list of who wants which item (with
  quantities and blurred locations) is available to the giver in the main Freegle app, but it is not
  shown on any ModTools screen. If you are handling a complaint that needs this detail, you would need
  to ask the development team.
- **Whether the Helper is switched on** for a post is not shown on the post card.
- **There is no "Escalated" queue.** When the Helper escalates a conversation, there is currently no
  ModTools screen that lists those cases; the conversation continues in the normal chat thread.
- **The Helper flow page is not in the navigation** - you reach `/modtools/helper-flow` by typing the
  address.
