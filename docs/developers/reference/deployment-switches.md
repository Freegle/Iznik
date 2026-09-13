---
last_reviewed: 2026-09-06
owner: Freegle dev team
covers:
  - iznik-batch/config/freegle.php
  - iznik-batch/routes/console.php
  - iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php
  - iznik-batch/app/Mail/Session/LoginLinkMail.php
  - iznik-batch/resources/views/emails/mjml/session/login-link.blade.php
  - iznik-batch/app/Mail/Traits/TrackableEmail.php
  - iznik-batch/app/Mail/Traits/FeatureFlags.php
  - iznik-batch/tests/Unit/Mail/UnsubscribeCategoryCoverageTest.php
  - iznik-batch/tests/Unit/Console/ScheduleProfileTest.php
  - iznik-batch/tests/Fixtures/console.deployment.php
  - iznik-batch/database/migrations/2026_09_06_000001_add_agreement_columns_to_messages_promises.php
  - iznik-server-go/message/messageOutcome.go
  - iznik-server-go/test/message_agreement_test.go
  - iznik-nuxt3/eslint.config.mjs
---

# Deployment switches

Freegle's code is also the base for other, separate services (the first is a garden-sharing
site, built as a Nuxt layer on top of `iznik-nuxt3` with its own database and its own copy
of the Go API and the batch jobs). A separate service like that wants to keep pulling
Freegle's fixes and improvements. It can only do that cheaply if it never has to edit the
files it shares with Freegle.

So the things such a service needs to do differently are **switches in the shared code**,
not edits to it. Every switch here defaults to how Freegle already behaves. Leave them all
unset and nothing changes. A deployment sets the ones it needs in its environment, keeps
its own code in files Freegle does not ship, and merges from Freegle whenever it likes.

## The switches

All of these are read through `config('freegle.*')`, so they are set with environment
variables like every other Freegle setting.

| Setting | Env var | Freegle default | What it changes when set |
|---|---|---|---|
| `auth.passwordless` | `FREEGLE_PASSWORDLESS_LOGIN` | `false` | A "forgot password" request sends a **sign-in link** email (`LoginLinkMail`) instead of the "set a new password" one. |
| `auth.login_link_path` | `FREEGLE_LOGIN_LINK_PATH` | `/` | Where that sign-in link lands on the site. The page there must consume `?u=&k=` to sign the member in; the root app already does. |
| `mail.tracking_enabled` | `FREEGLE_MAIL_TRACKING_ENABLED` | `true` | Off, no `email_tracking` row is written and every tracked link, image and pixel helper returns the plain destination. For a deployment whose API does not serve the tracking redirect and pixel. |
| `mail.enabled_types` | `FREEGLE_MAIL_ENABLED_TYPES` | (as before) | New: a `*` in the list enables every type, so a deployment with its own mailables need not re-list Freegle's whole catalogue. |
| `schedule.profile` | `FREEGLE_SCHEDULE_PROFILE` | `full` | `overlay-only` runs nothing from `routes/console.php` except what the overlay file below schedules. Any other value behaves as `full`, so a typo can never quietly stop the schedule. |
| `schedule.overlay` | `FREEGLE_SCHEDULE_OVERLAY` | `routes/console.deployment.php` | A schedule file loaded **if it exists** (relative to the app root, or absolute). Freegle ships none. A deployment puts its own jobs there. |

### Sign-in links

The Go API already mints a one-click `u`/`k` credential pair whenever someone asks to
reset their password; the batch job then emails a link carrying it. With
`auth.passwordless` on, the same job sends `LoginLinkMail` instead, pointing the same
credentials at `auth.login_link_path` on the configured `sites.user` host rather than at
the settings page. Nothing about how the credentials are made or checked changes. The
email is transactional (no unsubscribe link), like the password one it replaces.

### The schedule overlay and profile

`routes/console.php` is one long file, and a deployment that wants six jobs out of a
hundred used to have to edit it. Now it drops a file at `routes/console.deployment.php`
(or wherever `schedule.overlay` points) written exactly like the main file, and that is
loaded first. With the default `full` profile the overlay simply adds to Freegle's
schedule. With `overlay-only` the rest of the file is skipped, so only the overlay runs.

## Promises that become agreements

A Freegle promise is one-sided: the item's owner promises it to someone, and that is the
whole story. A deployment may need a two-sided version, where the owner proposes terms and
the other person accepts them. That is now available to any client, and unused by Freegle:

- The `Promise` action on `POST /api/message` accepts an optional `terms` field (any JSON),
  stored on `messages_promises.terms`.
- A new `AcceptAgreement` action lets the person a message was promised **to** accept it,
  which stamps `acceptedat` and `acceptedby` on their promise row. Only they can accept,
  only once, and only while the promise exists; anything else is a 404 and writes nothing.
- The message's `promises` array carries `terms`, `acceptedat` and `acceptedby` **only when
  set**, so a plain Freegle promise serialises exactly as it always did.
- Two further nullable JSON columns, `checkins` and `checkin_reminders_sent`, are there for
  a deployment's own follow-up jobs after an agreement. The Go API does not touch them.

All five columns are nullable and added with an instant (metadata-only) `ALTER`.

## Two more things a deployment gets for free

- **Unsubscribe categories for its own mailables.** `UnsubscribeCategoryCoverageTest`
  refuses any mailable that has not declared its unsubscribe category. A deployment lists
  its own in `iznik-batch/tests/Unit/Mail/unsubscribe-categories.deployment.php`, a file
  Freegle does not ship, returning the same `class => category` array. Freegle's own
  entries always win.
- **Lint for its Nuxt layer.** The ESLint rule that allows single-word page and layout
  names now matches `*/layouts/*.vue` and `*/pages/**/*.vue`, so any layer directory is
  covered, not just `modtools/`.

## What a deployment should never do

Edit a file Freegle ships. If it needs a behaviour Freegle does not have, the right move is
another switch here, defaulting to Freegle's current behaviour, with a test that pins that
default. That is what keeps the merge cheap in both directions.
