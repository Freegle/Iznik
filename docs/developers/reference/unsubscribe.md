---
last_reviewed: 2026-08-05
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/UnsubscribeService.php
  - iznik-batch/app/Mail/MjmlMailable.php
  - iznik-batch/app/Mail/Session/UnsubscribedNotice.php
  - iznik-server-go/user/unsubscribe.go
  - iznik-batch/tests/Unit/Mail/ListUnsubscribeHeaderTest.php
  - iznik-server-go/test/unsubscribe_test.go
  - scripts/check-unsubscribe-categories.mjs
  - iznik-nuxt3/server/middleware/oneClickUnsubscribe.js
  - iznik-nuxt3/tests/unit/server/oneClickUnsubscribe.spec.js
  - iznik-batch/tests/Unit/Mail/UnsubscribeCategoryCoverageTest.php
---

# Unsubscribing from email

What happens when someone clicks **Unsubscribe** in their mail client on a Freegle email.

This is the `List-Unsubscribe` path, not the `/unsubscribe` page on the site (that page is
about leaving communities and deleting the account, and is unchanged).

## The header

`MjmlMailable::addListUnsubscribeHeaders()` puts this on every bulk mailable:

```
List-Unsubscribe: <mailto:unsubscribe-{uid}-{key}-{category}@users.ilovefreegle.org>,
                  <{apiv2}/user/unsubscribe?u={uid}&k={key}&t={category}>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

`{key}` is the member's persistent auto-login credential (`users_logins` type `Link`,
via `LoginLinkService`) — the same key the login, forget and relevant-off links use. Both
arms carry it, so neither needs a session and neither can be forged from a member id alone.

Two rules that are easy to get wrong, and were both wrong until 2026-08:

- **The mailto: must be on `users.ilovefreegle.org`.** The MX for bare `ilovefreegle.org`
  is Google Workspace; only the `users.` and `groups.` subdomains route to our postfix and
  on to `IncomingMailService`. A mailto: on the bare domain never reaches us — the member
  gets a Google auto-reply saying the mailbox is not monitored and to contact support,
  which reads as "you did something wrong" in reply to a normal unsubscribe.
- **The https: must be an endpoint that actions the opt-out, not a page.** A front-end
  route answers the RFC 8058 POST with a 200 and does nothing, and mail clients report
  that 200 to the member as a successful unsubscribe. There is no error anywhere: the
  member believes they unsubscribed and keeps getting email.

Transactional mail — password resets, address verification, receipts, anything about the
member's own post — declares `unsubscribeType(): null` and carries no `List-Unsubscribe`
at all.

## Categories

`Unsubscribe` means "stop the kind of email I just got", not "delete my account". Each
mailable declares its category with `unsubscribeType()`; both arms of the header carry it.

| Category | Stops | Switch |
|---|---|---|
| `digest` | What's New digests | `memberships.emailfrequency = 0`, all their groups |
| `events` | community events | `memberships.eventsallowed = 0` |
| `volunteering` | volunteer opportunities | `memberships.volunteeringallowed = 0` |
| `newsletter` | newsletters, stories, community news | `users.newslettersallowed = 0` |
| `relevant` | matched/suggested posts | `users.relevantallowed = 0` |
| `chat` | chat message notifications | `settings.notifications.email = false` |
| `notifications` | ChitChat digest, notification chase-ups | `settings.notificationmails = false` |
| `engagement` | donation asks, gift-aid chase-ups, re-engagement | `settings.engagement = false` |
| `all` | everything above | all of the above |

An unknown or mangled category falls back to `all`, so a truncated address stops mail
rather than silently doing nothing.

`digest`, `events` and `volunteering` are per-membership, and the opt-out covers **every**
group the member belongs to. The unified digest spans communities, so turning it off for
one group would leave the same email arriving from the others.

**Every switch in that table has to be honoured by the sender.** An opt-out that no sender
reads is worse than none, because the member is told it worked. `settings.engagement` was
in exactly that state before this was built: the "Encouragement emails" toggle in Settings
wrote it and nothing read it.

### Adding a new mailable

`unsubscribeType()` has a default, so a mailable that says nothing still behaves safely — it
carries a working `List-Unsubscribe` that turns everything off. But safe is not right: a new
bulk mail would take away more than the member asked for, and a new transactional mail would
carry an unsubscribe link it should not have. Neither shows up as a failure anywhere, which
is how `EventsDigestMail` and `VolunteeringDigestMail` sat uncategorised for as long as they
did.

So `UnsubscribeCategoryCoverageTest` pins the full list of `MjmlMailable` subclasses and
their categories. **Add a mailable and it fails until you categorise it**, naming the class
and what to do:

```
New mailable(s) with no unsubscribe category:
  App\Mail\Tmp\ForgottenMail

Decide which UnsubscribeService::TYPE_* they belong to - or null if they are
transactional and should carry no List-Unsubscribe - declare it with
unsubscribeType(), and add them to self::EXPECTED.
```

It also fails if a declared category stops matching the pinned one, or if a listed mailable
is deleted — so the answer always lands in the diff where a reviewer sees it.

## The two implementations

The mailto: arm is handled by `IncomingMailService::handleOneClickUnsubscribe()` (PHP,
`UnsubscribeService`). The https: arm is handled by `user.Unsubscribe` (Go,
`iznik-server-go/user/unsubscribe.go`).

The category map is therefore written twice. That is deliberate: apiv2 and batch-prod run
on different hosts and batch-prod is outside the compose network, so neither can call the
other.

Each side's tests pin its own list, so a change to either is deliberate. Neither test
container can see the other language's tree (Laravel tests run in the batch container at
`/var/www/html`, Go tests in the apiv2 container at `/app`), so the actual cross-language
diff runs on the host:

```bash
node scripts/check-unsubscribe-categories.mjs
```

It compares both the category lists and the member-facing descriptions, and exits non-zero
if they differ. Run it if you add or rename a category.

## The old URL, and mail already in inboxes

A deploy does not change mail that has already been delivered. Anything sent before this
keeps the header it was sent with, so `/one-click-unsubscribe/{uid}/{key}` — which
`ChatNotification` used to point `List-Unsubscribe` at — has to keep working, and keep
working safely, for as long as that mail sits in people's inboxes.

It used to delete the account. `server/middleware/oneClickUnsubscribe.js` only intercepted
non-POST requests; a POST fell through to the page underneath, whose `<script setup>` runs
during SSR and calls `authStore.forget()`. So Gmail's one-click POST — a machine action
with nobody confirming anything — removed the member outright. Reproduced in a worktree:
one `curl -X POST` set `users.deleted`.

The middleware now handles the route entirely: GET still redirects to `/unsubscribe?u=&k=`,
and POST calls the apiv2 endpoint with `t=all` — "stop emailing me", account untouched. The
page has been deleted, so there is nothing to fall through to. The app's deep-link handler
in `stores/mobile.js` did the same thing on tap and now routes to `/unsubscribe` instead.

## Acknowledgement

The mailto: arm sends `UnsubscribedNotice`: what we turned off, which categories may still
email them, and a link to Settings. If everything in scope was already off it says so
rather than claiming to have changed something.

The https: one-click arm does not send an acknowledgement. The mail client has already
told the member it worked, and Gmail treats a one-click as final — emailing someone who
just asked to stop is the wrong instinct. A browser GET on the same URL gets a
confirmation page instead.
