---
paths:
  - "iznik-nuxt3/**/*.vue"
  - "iznik-nuxt3/**/*.js"
  - "iznik-nuxt3/**/*.mjs"
---

# Traps in the Nuxt frontend

## `process.client` is a Nuxt 3 define and Nuxt 4 does not set it

Nuxt 3's vite builder defines `process.client` / `process.server` / `process.browser`. Nuxt 4's
does not. So on Nuxt 3 these guards work and are not dead code; the moment Nuxt 4 lands, every
surviving guard evaluates undefined and **its body silently never runs**. No error, no warning,
no test failure.

Write `import.meta.client`. Unit tests cannot catch a missed conversion: `vitest.config.mts` has
a pre-transform that rewrites `import.meta.client` to the literal `true` in app source, so a
guard is always live under test whichever flag it reads. Find them by grep, not by testing.

## Three `await`s that can hang forever

1. **`api/BaseAPI.js` returns a promise that never settles** for a 401 on the chat list, and for
   any aborted request. It is deliberate, to keep abort-on-unload out of Sentry, but a caller
   awaiting it gets neither success nor catch. Aborts come from logout.
2. **`stores/misc.js waitForOnline()`** polls every second with no timeout and no reject, and
   `$requestv2` awaits it *before* fetching. If `online` sticks false, everything downstream
   stops.
3. Persisted upload state can leave a member permanently unable to post.

When a member reports "it just spins forever" rather than an error, look here before looking at
the endpoint.

## `SpinButton` spins for 20 seconds when a handler returns early

`SpinButton` emits `handle` with a `finishSpinner` callback and arms a 20 second forgotten
callback timer. A handler that returns without calling it spins for the full 20 seconds, then
reports to Sentry.

**The usual cause is a validation guard, not an async bug**: `if (!ok) { warning = ...; return }`
placed before the work. Moderators report it as the send having hung, with no idea what was
wrong. Call the callback on **every** early-return path.

## Do not remove `autocapitalize="none"` from the chat or comment boxes

It is not working around a fixed iOS bug. With `autocapitalize="sentences"`, iOS turns Shift on
at the start of a sentence, so Return reports `shiftKey: true` and our send-on-enter handlers
treat it as a newline. The keyboard is behaving as designed; the attribute is the fix.

## A setup function that registers a watcher per call

`modtools/composables/useModMessages.js` `setupModMessages()` carries a comment forbidding
watchers inside it, and then registers several. Each call adds another `watch`, and each firing
watch triggers a full clear-then-refetch. The cost is invisible locally and shows up as repeated
identical API calls in production logs.

When a composable's setup runs per component instance, anything it registers is per instance
too. Check before adding a watcher, an observer, or an interval.

## See also

- `docs/developers/` for how the feeds and stores are meant to work.
- Testing notes live in `docs/developers/testing.md`.
