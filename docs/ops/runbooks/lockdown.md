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

- **From ModTools:** Support tools, the red Lockdown tab. Give a reason, choose whether
  members see a notice (none, "running slowly", or the spam warning), read the consequences
  and type `LOCKDOWN`.
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

- Held chat is sorted every minute into spam, low risk and risky. Nobody reads a held chat
  message unless it is classed risky or the recipient reports it.
- Switch chat from hard to soft once the sorting looks right: low-risk messages are then
  delivered with a minute's delay, risky ones go to moderators, spam is dropped.
- Add the wave's phrases and addresses under incident phrases; they sharpen the sorting and
  are cleared when the lockdown is closed.

## Lifting

In this order, from the Lockdown tab. Each step is its own switch with its count beside it,
and a surface can be held again on its own if the wave resumes.

1. Understand the cause and fix whatever the wave used.
2. Check the spam and risky samples, and add phrases until the spam set looks right.
3. **Mark spam set.** Its senders become spammers and their held items are removed.
4. Lift **mods**, so moderators are in the queues before they fill.
5. Lift **chat**, **posts**, **ChitChat** and **events**. Low-risk items are released at a
   paced rate, a few hundred a minute; risky ones wait for a moderator. Nothing held is
   released without a person deciding.
6. Lift **push**.
7. **Resume email.** The send queue is first filtered against what was removed in step 3,
   then digests and notifications are generated from where they stopped. Members get what
   they would have had, a few hours late, without the removed content. Nothing is dropped.
8. Lift **export**.
9. Change the notice to "Things are back to normal", or turn it off.
10. **Close**, with a note. geeks@ gets the closing report.

For a false alarm, "Lift everything" runs steps 4 to 8 in order.
