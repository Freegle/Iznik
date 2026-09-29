---
last_reviewed: 2026-09-27
owner: Freegle dev team
covers:
  - iznik-server-go/lockdown
  - iznik-batch/app/Services/Lockdown
  - iznik-batch/app/Console/Commands/Lockdown
  - iznik-nuxt3/modtools/components/ModSupportLockdown.vue
---

# Lockdown: stopping a spam wave

The lockdown switch stops anything a member writes from reaching anyone else, while
Freegle stays up. Use it when a spam or phishing wave is going out faster than moderators
can remove it. It lasts hours, not days, and nothing lifts on its own.

The design and its reasoning are in
[`plans/active/2026-09-27-lockdown-switch.md`](../../../plans/active/2026-09-27-lockdown-switch.md)
section 10, with the build details in section 11.

## What it does

| surface | while held |
|---|---|
| chat | member-to-member messages are kept back and look sent to the sender; chat with volunteers keeps flowing |
| posts | new posts and edits stay pending; the poster sees them as live |
| ChitChat | new posts and replies are hidden from everyone but the author |
| events | community events, volunteering, noticeboards and stories wait for approval |
| mods | moderators can see everything and use only the basic Approve button; everything else is refused |
| push | no app notifications |
| email | member mail is not generated; sign-in, password, verification, unsubscribe and deletion emails still go |
| export | downloads are refused, for Support too |

Support and Admin are exempt from the moderator refusals, so they can still work the
incident. Sign-in, lost password, unsubscribing and deleting an account keep working.

## Pressing

- **From ModTools:** Support tools, the red Lockdown tab at the end. The page says what
  pressing does. Give a reason, choose whether members see a notice and write it (two
  wordings are offered to start from), then type `LOCKDOWN` to confirm.
- **From the batch host**, when ModTools or the API is broken or in the wrong hands:
  `php artisan lockdown:on --reason="..."`. `lockdown:status` shows the state.

Every change mails geeks@ and raises a Sentry event within a minute, and geeks@ gets the
numbers every hour until the lockdown is closed.

## Checking it has taken effect

The Lockdown tab shows a "Taking effect" list straight after the press. The API applies it
within five seconds. Each batch job ticks when it has acted on the change and shows how
long it took; the target is about ten seconds. A job still waiting after two minutes turns
amber, which usually means it is stopped or stuck on the batch host.

"Sent since the press" should read 0. Anything there got out between the press and the job
noticing it, and is the real cost of the delay.

Mail already accepted by the outbound relay is not reached by the switch. If the wave used
email, hold the relay queue by hand and remove what is from the marked accounts before
releasing it (the host-specific steps are in the ops team's operational notes).

## While it is on

The lockdown only holds. Working out who is behind the wave and dealing with them happens
with the Support tools that already exist, not in the Lockdown tab.

- "What is held" lists the held chat messages, posts and ChitChat posts, newest first, and
  searches their text and the sender's name and email. Each sender links to Support tools,
  where they can be marked as a spammer.
- "Held so far" counts each kind of thing held, and what was not sent or was refused.
- The member notice can be changed or removed at any time, and saved.

## Lifting

From the Lockdown tab, one area at a time. Each area has its own Lift button, and can be
held again on its own if the wave comes back.

1. Understand the cause and fix whatever the wave used.
2. Mark the accounts behind the wave as spammers in Support tools.
3. Lift **moderator actions**, so moderators are in their queues before they fill.
4. Lift **chat**, **posts**, **ChitChat** and **events**. Everything held goes through the
   checks that would have run on the day, oldest first, straight away. Messages
   from accounts marked as spammers are dropped; posts from moderated members or groups
   wait for a moderator as usual.
5. Lift **app notifications**.
6. Lift **email**. The send queue is first cleared of mail about anything removed, then
   digests and notifications are generated from where they stopped. Members get what they
   would have had, a few hours late. Nothing is dropped.
7. Lift **downloads**.
8. Change the member notice to "Things are back to normal", or remove it.
9. **Close**, with a note. geeks@ gets the closing report.

For a false alarm, "Lift everything" lifts every area in this order.
