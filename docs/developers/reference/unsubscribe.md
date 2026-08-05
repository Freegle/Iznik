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

## Acknowledgement

The mailto: arm sends `UnsubscribedNotice`: what we turned off, which categories may still
email them, and a link to Settings. If everything in scope was already off it says so
rather than claiming to have changed something.

The https: one-click arm does not send an acknowledgement. The mail client has already
told the member it worked, and Gmail treats a one-click as final — emailing someone who
just asked to stop is the wrong instinct. A browser GET on the same URL gets a
confirmation page instead.
