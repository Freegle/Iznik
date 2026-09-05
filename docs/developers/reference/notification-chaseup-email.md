---
last_reviewed: 2026-09-01
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/NotificationChaseUpService.php
  - iznik-batch/app/Mail/Notification/ChaseUpMail.php
  - iznik-batch/app/Console/Commands/Notification/ChaseUpNotificationsCommand.php
  - iznik-batch/app/Console/Commands/Mail/TestMailCommand.php
  - iznik-batch/resources/views/emails/mjml/notification/chaseup.blade.php
  - iznik-batch/resources/views/emails/text/notification/chaseup.blade.php
  - iznik-batch/tests/Unit/Mail/Notification/ChaseUpMailCardTest.php
  - iznik-batch/tests/Unit/Mail/Notification/ChaseUpMailPreheaderTest.php
  - iznik-batch/tests/Unit/Mail/Notification/ChaseUpMailUrlTest.php
  - iznik-batch/tests/Unit/Services/NotificationChaseUpServiceTest.php
  - iznik-batch/tests/Feature/Console/TestMailCommandTest.php
---

# Notification chase-up email

Tells a member about on-site notifications they have not looked at: comments and
replies on ChitChat, loves on their posts and comments, Exhort nudges, and
membership outcomes. One email covers up to ten of them.

This is **not** `App\Mail\Message\ChaseUp`, which chases the *outcome* of a
member's own post. Two different mails, similar class names.

## How it flows

1. `mail:notifications:chaseup` runs every five minutes
   (`iznik-batch/routes/console.php`).
2. `NotificationChaseUpService::sendEmails()` picks `users_notifications` rows
   that are `seen = 0` and `mailed = 0`, at least `--before` minutes old (default
   5, so someone who is on the site sees it there first) and no older than
   `--since` hours (default 24). Rows from a spammer or pending-add sender, and
   the `EXCLUDED_TYPES` that never earn an email, are dropped in the query.
3. Per member, `isUserEligible()` plus `wantsNotificationEmails()` apply the
   usual gates: not deleted, not bouncing, not on holiday, active in the last
   183 days, `settings.notificationmails` not false.
4. `prepareForUser()` returns the rows the email renders, newest first, capped at
   ten, each with the sender's name and avatar, a formatted timestamp, and the
   newsfeed item plus its parent thread. Deleted newsfeed items are skipped, so
   this can come back empty and the send is abandoned.
5. `getNotifTitle()` builds the subject from the highest-priority row. It can
   return an empty string (membership outcomes on their own do), in which case
   nothing is sent.
6. Rows are marked `mailed = 1` **before** the spool call, deliberately: a
   transient mail-host failure costs this one chase-up rather than
   re-sending on the next run.

## What the email looks like

`emails/mjml/notification/chaseup.blade.php`, in the house style shared with
Community News: a green brand band with the headline on the left and the Freegle
logo on the right, a pale intro card, then one white card per notification
separated by background gaps.

The band is built inline rather than through `partials/header.blade.php`, which
has no logo. Its vertical padding sits on the `<mj-section>`, because
`vertical-align="middle"` on a column has nothing to centre within if the
padding is on the text instead.

Each card carries the sender's avatar, a one-line statement of what happened, the
timestamp, the message text quoted behind a light green rule, and an inline link.
The statement and the link wording are worked out in a single `@php` block at the
top of the loop, so the card markup below it is written once:

| Type | Card reads | Link |
|---|---|---|
| `CommentOnCommented` | *Alice* replied on "parent thread" | View thread |
| `CommentOnYourPost` | *Alice* commented on your post | View thread |
| `LovedPost` | *Alice* loved your post | View thread |
| `LovedPost` on a noticeboard | *Alice* loved your noticeboard post | View thread |
| `LovedComment` | *Alice* loved your comment | View thread |
| `Exhort` | the nudge's own title and text | Take a look |
| `MembershipPending` / `Approved` / `Rejected` | the outcome for that community | Go to Freegle |
| anything else | You have a notification from *Alice* | Go to Freegle |

The last row matters: the notification type enum is longer than the list above,
so an unhandled type still renders a usable card instead of an empty one.

One green button closes the email, pointing at ChitChat. Nine of them, one per
card, was the old design and it read as a wall of buttons.

The plain-text twin is `emails/text/notification/chaseup.blade.php`. Both views
get the same data array, so any wording change has to be made in both;
`ChaseUpMailCardTest` and `TestMailCommandTest` assert on the HTML and the text
part of the same spooled message.

Two traps the tests pin:

- **Both card columns need an explicit width.** An `<mj-column>` with no `width`
  gets `containerWidth / numberOfColumns`, not what is left over beside a
  fixed-width sibling, so the text column next to the avatar column renders at
  300px unless it says how wide it is. The two widths are 84px and 516px, which
  add up to the 600px body. The avatar column is 84px for a 44px picture with
  25px of padding on its left, which leaves 15px of clear space before the text
  starts.
- **The template may only use the variables `ChaseUpMail` passes.** They are
  pinned by `ChaseUpMailPreheaderTest::renderChaseUpView()`; adding a variable
  to the view without adding it to the mailable renders in a test and throws in
  production.

## Previewing it

```bash
docker exec freegle-batch php artisan mail:test notifications --user=<id> --send-to=you@example.com
```

It lands in Mailpit (`http://localhost:8025`). If that member has no unseen
notifications the command says so and falls back to sample rows covering every
type above, which is the useful case for looking at the layout. Nothing is
changed: the command runs in a transaction that is rolled back, and nothing is
marked `mailed`.

**Avatars only load if `FREEGLE_AVATAR_SERVER_URL` ends in `/avatar`.** The Go
route is registered at the app root (`iznik-server-go/router/routes.go`), not under `/api`, so a
value with `/api/avatar` gives every card a broken image in local previews. Set
correctly for both batch services in `docker-compose.yml`.

## Unsubscribing

`unsubscribeType()` is `notifications`, the same category as the ChitChat digest,
which maps to `settings.notificationmails`. This mail keeps the shared footer
partial rather than a bespoke one, because that is the right control here: the
member is asking to stop hearing about site notifications, and the setting says
exactly that. See [./unsubscribe.md](./unsubscribe.md).
