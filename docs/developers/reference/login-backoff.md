---
last_reviewed: 2026-11-16
owner: Freegle dev team
covers:
  - iznik-server-go/session/loginbackoff.go
  - iznik-server-go/session/loginbackoff_test.go
  - iznik-server-go/test/login_backoff_test.go
---

# Waiting after failed logins

After repeated failed logins the next attempt has to wait, and the wait doubles with each further
failure. It makes guessing a password slow. Nobody is locked out for good.

The code is `iznik-server-go/session/loginbackoff.go`. The numbers below are constants there.

## What is counted

A failed login is a wrong password, an email address we do not know, a wrong link key, or a link
login for a member id that does not exist. Each one is counted against the address, and against
the member when there is one (an email address we do not know has no member to count against):

| Counted against | Failures that cost nothing | Why |
|---|---|---|
| The member being tried. All of a member's email addresses and their login link share one count. | 5 | Stops many addresses guessing at one member. |
| The address trying. For IPv6 that is the /64, since one customer can pick any address in it. | 20 | Stops one address trying one password on many members. A school or library is many members behind one address, so it gets more room. |

Past the free failures, the next attempt waits 30 seconds. Each further failure doubles the wait,
up to 15 minutes. A count is forgotten an hour after its last failure. A successful login forgets
the member's count, and leaves the address's count alone.

## What a held login gets

HTTP 403, a `Retry-After` header, and a body with `ret: 13`, `retryafter` in seconds and a
`status` the login box shows as it is:

> Too many failed attempts. Please wait 30 seconds and try again, or use Forgot password.

It is a 403, like a wrong password, and not a 429. The website and the app builds already
released retry a 429 by themselves for minutes (`iznik-nuxt3/composables/useFetchRetry.js`) and
then show nothing, where they show a 403's `status` to the member.

The check comes before the password is looked at, so the right password is held too. Otherwise a
guesser could tell a right guess from a wrong one. A held attempt is not itself counted, so
waiting always works.

## What is not held

- "Forgot password", and login with Google, Facebook or Apple. A member who is stuck has those.
- An address that is not public. The address comes from `misc.ClientAddress` (the `X-Real-IP`
  header haproxy sets). A private address is a proxy or an internal caller, never one client. If
  haproxy ever stopped passing the header, every member would arrive from the proxy's address, and
  counting that would hold everyone back because of one guesser.

## Limits to know about

- Two logins sent at the same moment are both checked before either failure is counted, so a
  burst gets a few more tries than the table says.
- The table of counts is bounded. When it is full it drops counts over an hour old, then single
  failures, and after that leaves new keys uncounted until there is room.
- The counts are in memory. A restart of the API forgets them, and API processes do not share
  them, so with N processes a guesser gets N times the allowance.
- Someone who knows a member's email address can make that member wait, for up to 15 minutes at a
  time, by failing on purpose. The member can still use "Forgot password" or a social login.
