---
last_reviewed: 2026-10-26
owner: Freegle dev team
covers:
  - iznik-batch/app/Console/Commands/Message/FamiliarPostsCommand.php
  - iznik-batch/app/Services/FamiliarPostsService.php
  - iznik-batch/app/Mail/Message/FamiliarPosts.php
  - iznik-batch/app/Mail/Traits/PostThumbnail.php
  - iznik-batch/resources/views/emails/mjml/message/familiar-posts.blade.php
  - iznik-batch/app/Services/Ux/Sources/FamiliarReplySource.php
  - iznik-batch/tests/Feature/Message/FamiliarPostsCommandTest.php
  - iznik-batch/tests/Feature/Ux/FamiliarReplySourceTest.php
---

# New posts from people you have chatted with

An email experiment, `familiar-poster`. The idea: a member may be more likely to reply to a post when they
know the person who posted it. So when someone posts a new OFFER or WANTED, members who have chatted with
them recently, and not badly, are told about it.

It is an experiment decided on the server, so it runs on the machinery in
[UX experiments](ux-experiments.md#experiments-in-mail-push-and-the-server). Nothing is sent unless the
experiment is running.

## Who is told

`FamiliarPostsService`. A member is told about a new post when all of these hold:

- **They know the poster.** The two have a chat in which each wrote within the last 90 days
  (`lookback_days`).
- **It did not go badly.** Neither has rated the other down, and nobody blocked the chat. A rating up is not
  needed.
- **They could reply.** They are an approved member of a community the post is approved on, and have not
  already replied to it.
- **They want this kind of mail.** `users.relevantallowed` is 1, the consent the matched-posts mail uses,
  and `sendOurMails()` is true (not deleted, inactive, bouncing, on holiday or with mail off).

A new post is an OFFER or WANTED whose home community copy (not rippled in, never reposted) was approved in
the run's window, or arrived then when no approval time is recorded, and which is still open: no outcome and
nothing promised.

## Not too many

The limits are settings in `freegle.familiar_posts`, each with an environment variable (see
[deployment-switches.md](deployment-switches.md)):

| Setting | Default | Meaning |
|---|---|---|
| `max_recipients_per_post` | 20 | members told about one post, those chatted with most recently first |
| `max_posts_per_email` | 3 | posts in one email |
| `min_gap_hours` | 24 | a member is reached at most once in this many hours |
| `max_days_per_week` | 2 | and on at most this many of the last seven days |
| `lookback_days` | 90 | how recent the chat must be |
| `window_hours` | 6 | a run never reads posts older than this |

The member limits are counted from the exposures, which both arms write, so both arms are held to the same
limits.

## The run

`messages:familiar-posts`, hourly at 43 minutes past. It first asks `UxArm::serving('familiar-poster')` and
stops if the experiment is not live. Otherwise it reads the posts since the last run (config row
`familiar_posts_cursor`), groups them by member, and for each member:

- records a `post` exposure for each post (the subject is `messages.id`), in either arm;
- in the `notify` arm, sends one `FamiliarPosts` email naming those posts.

A failed send takes its exposures back (`UxArm::retract`).

## The email

`FamiliarPosts`, email type `FamiliarPosts`. One post: "Jane is offering: Sofa", with the heading "Jane has a
new post". Several: "New posts from people you have chatted with". Each card shows the photo, OFFER or
WANTED, the item, "From Jane" and a Reply button that opens the post with the reply pane open. The line above
the footer says how often it can come and links to the one-click opt-out that the matched-posts mail uses
(`relevantoff`, turning off relevant mail only). The footer is the usual one. It is in the relevant
unsubscribe category.

## How it is judged

`FamiliarReplySource` (`design.source` `familiar_reply`, window 48 hours). Each member is counted once, on
their first exposure.

- **Converted:** they replied to that post within 48 hours.
- **Guardrail:** they clicked an unsubscribe link in any of our mail within 48 hours, margin 0.3 points. Any
  mail, because control is sent none of these.

## Starting it

On the batch host, once this code is deployed and `ux_server_arms` is `1`:

```
php artisan ux:experiment create --name=familiar-poster --flow=reply \
  --title="New posts from people you have chatted with" \
  --hypothesis="Members reply more to a post when they know the person who posted it." \
  --change="Members who chatted with a poster in the last 90 days, and not badly, get an email about the new post." \
  --expected="More of them reply to the post within 48 hours." \
  --pr=https://github.com/Freegle/Iznik/pull/1927 \
  --arms='{"control":[0,49],"notify":[50,99]}' \
  --design='{"channel":"email","type":"FamiliarPosts","source":"familiar_reply","window_hours":48,"intake_hours":120,"max_hours":168,"expected_points":2}'
php artisan ux:experiment start familiar-poster
```
