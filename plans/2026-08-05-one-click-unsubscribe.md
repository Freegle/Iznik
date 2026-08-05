# One-click unsubscribe is broken in both arms

Reported by Jacky 2026-08-05: clicked Unsubscribe in a daily digest, believed she had
unsubscribed, then received "Replies to this mailbox are not monitored Re: unsubscribe"
from noreply@ilovefreegle.org — which reads as "you did something wrong, contact support".

## Evidence

User 44500773 (freeglelibrary@gmail.com), digest sent 2026-08-05 06:53:32 UTC
(`UnifiedDigestDaily`, spool `mail_6a72db7872d397.00275260_7389f2f6`).

1. `MjmlMailable::addListUnsubscribeHeaders()` emits, for every mailable with a known
   recipient user id:

   ```
   List-Unsubscribe: <mailto:noreply@ilovefreegle.org?subject=unsubscribe>, <https://www.ilovefreegle.org/unsubscribe/{userId}>
   List-Unsubscribe-Post: List-Unsubscribe=One-Click
   ```

   Confirmed identical in the deployed prod code (`freegledocker-batch-prod`).

2. **mailto arm is a dead end.** `ilovefreegle.org` MX is Google Workspace
   (`aspmx.l.google.com`), not our postfix — only `users.` and `groups.` subdomains route
   to the Laravel mail handler. So the "email here to unsubscribe" address we advertise
   lands in a Google mailbox whose auto-responder replies "Replies to this mailbox aren't
   monitored… please mail support@". Nothing unsubscribes anyone. No `incoming_mail` or
   `email` Loki entry exists for that message in either direction — it never touched our
   infrastructure.

3. **https arm is a silent no-op.** `/unsubscribe/{userId}` is a `<client-only>` Nuxt page
   with no server handler and no auth key. Probed live:

   ```
   POST /unsubscribe/999999999 -> HTTP 200
   ```

   Gmail reads 200 as "unsubscribe succeeded" and tells the user so. Nothing changes.
   Confirmed against the live DB 90 minutes after Jacky's attempt: `memberships.emailfrequency
   = 24` on both her groups, `relevantallowed = 1`, `newslettersallowed = 1`, no `logs` rows.
   She was never unsubscribed from anything.

   This affects every recipient of every digest, i.e. RFC 8058 / Gmail bulk-sender
   compliance is broken across the board, not just for this user.

4. Two adjacent hazards found while tracing:
   - `IncomingMailService::handleOneClickUnsubscribe()` handles
     `unsubscribe-{userid}-{key}-{type}@users.ilovefreegle.org` — correctly key-authenticated,
     but it **ignores `$type` and soft-deletes the whole account**. Nothing generates that
     address any more, so it is dead code.
   - `ChatNotification` overrides the header with `/one-click-unsubscribe/{id}/{key}`, whose
     page script calls `authStore.forget()` — account deletion — and runs during SSR.

   So every unsubscribe route we own either does nothing or deletes the account. There is no
   "turn off this kind of email" path at all.

## What Jacky asked for

> Shouldn't this be an acknowledgement of my unsubscription from x, a gentle reminder that
> I might still get other types of email from Freegle and please go to Settings if I wish to
> subscribe again or adjust all my preferences?

i.e. per-category opt-out, not account deletion, plus an acknowledgement.

## Design

Introduce an unsubscribe **category** that each mailable declares, and make both arms of
`List-Unsubscribe` action that category.

`MjmlMailable::unsubscribeType()` — defaults to `ALL` (preserves today's "every mailable
carries the header" coverage); transactional mailables override to `null`, which suppresses
the headers entirely (password resets etc. should not carry List-Unsubscribe).

| type | stops | mechanism |
|---|---|---|
| `digest` | What's New digests | `memberships.emailfrequency = 0` (all their groups) |
| `events` | community events | `memberships.eventsallowed = 0` |
| `volunteering` | volunteer opportunities | `memberships.volunteeringallowed = 0` |
| `newsletter` | newsletters, stories, community news | `users.newslettersallowed = 0` |
| `relevant` | matched/suggested posts | `users.relevantallowed = 0` |
| `chat` | chat message notifications | `settings.notifications.email = false` |
| `notifications` | ChitChat digest, notification chase-ups | `settings.notificationmails = false` |
| `engagement` | nudges, donation asks, re-engagement | `settings.engagement = false` |
| `all` | everything above | all of the above |

Header becomes:

```
List-Unsubscribe: <mailto:unsubscribe-{uid}-{key}-{type}@users.ilovefreegle.org>, <{apiv2}/user/unsubscribe?u={uid}&k={key}&t={type}>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

Both arms are key-authenticated with the existing `users_logins` type=`Link` credential —
the same mechanism `RelevantOff` already uses, so no new auth surface.

**mailto arm** (`users.ilovefreegle.org`, routed to Laravel by our own postfix):
`handleOneClickUnsubscribe` applies the category opt-out and sends `UnsubscribedNotice` —
the acknowledgement Jacky described: what was turned off, what may still arrive, and a link
to Settings.

**https arm**: new Go endpoint `GET|POST /user/unsubscribe`, mirroring `RelevantOff`.
POST (RFC 8058 one-click) applies the opt-out and returns 200 JSON; GET applies it and
renders a confirmation page. No ack email on this path — the mail client has already told
the user it worked, and Gmail treats a one-click as final.

apiv2 and batch-prod are on different hosts and batch-prod is outside the compose network,
so the category map is implemented on both sides rather than one calling the other. It is a
small table; both sides are covered by tests that assert the same category list.

## Out of scope (flagged, not changed here)

- `/one-click-unsubscribe/{id}/{key}` deleting accounts from an SSR POST. This change stops
  mailables pointing at it, which removes the exposure, but the route itself is left alone.
- `freegledocker-postfix` is reporting `unhealthy` on prod.
