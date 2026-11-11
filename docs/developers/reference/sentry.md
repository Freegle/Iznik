---
last_reviewed: 2026-11-11
owner: Freegle dev team
covers:
  - iznik-nuxt3/plugins/sentry.client.ts
  - iznik-nuxt3/composables/useSuppressException.js
---

# Sentry: what belongs there

Sentry is for faults a person should act on. If an event reaches Sentry, somebody is expected to
read it and do one of three things: fix the bug, add the data that would explain it next time, or
prove it is not ours and stop it being sent. On a typical day the number of new issues should be
zero, so that the first one is noticed.

That only works if everything else stays out. In November 2026 there were 2,200 open issues and
104,000 events a fortnight, and nobody was reading them: two thirds of the volume was our own
layout-shift measurement, and much of the rest was the app labelled `ci`, our own test stacks,
crawlers, and API answers that were working as designed.

## What goes where

| It is... | Where it goes |
|---|---|
| A bug in our code, or a failure we can do something about | Sentry, as an error |
| A measurement or a rate (layout shift, slow uploads, images a member's network cannot reach) | Loki, through `useClientLog` on the client, so it can be counted and charted |
| Something the member chose (cancelled a sign-in, refused the camera) | Loki at most |
| An answer the API meant to give (401, 403, 404, 409, 429) | Loki at most; the caller handles it |
| Not ours (a vendor script, a browser extension, a crawler, a link scanner, a local or CI stack) | Dropped on the client, with the proof in a comment beside the rule |

A failure that is only meaningful as a rate, such as members being unable to reach the API, is
one grouped issue with an alert on how often it fires, not one issue per endpoint.

## Where the client filters live

- `iznik-nuxt3/plugins/sentry.client.ts`: `ignoreErrors` (messages) and `beforeSend`.
  `beforeSend` drops events from automation (`navigator.webdriver`) and from a page served by a
  local or CI stack (`isLocalStack`), after logging the `[Exception for Sentry]` console line that
  the Playwright fixtures read.
- `iznik-nuxt3/composables/useSuppressException.js`: rules that look at frames.
  `thrownByThirdParty` drops an error thrown inside a vendor script (Google sign-in, ad scripts,
  extensions, in-app browser bridges). It tests only the frame the error was thrown from, so an
  error in our code that passes through a vendor script is still reported.

Every rule carries the Sentry issue it answers and the evidence that it is not ours. A rule
without that evidence is how a real fault gets hidden: do not add one on a hunch.

The environment comes from `sentryEnvironment` in `iznik-nuxt3/config.js`. The store builds of
the app are made on CircleCI, so `APP_ENV` is read before `CIRCLECI`; otherwise the app in
members' hands reports itself as `ci`.

## Working an issue

1. Read the breadcrumbs and the trace in Loki (the event's `trace_id` and `session_id` tags)
   before deciding anything. The exception is the symptom.
2. Check who it affects: release, environment, URL host, device, user count, and whether the
   events cluster in time. Thousands of events from one device or one test stack are a different
   problem from ten events from ten members.
3. Decide: fix, add diagnostics, or prove it is not ours. A frequent "not ours" can still be a
   real problem (an outage shows up as network failures), so say why it is not.
4. Resolve the issue in Sentry once the change is live. If it comes back, Sentry marks it as a
   regression, which is the signal you want.

Issues that have not fired for two weeks can be resolved in bulk; any that fires again reopens.
