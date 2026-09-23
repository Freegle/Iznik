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

## A range input moves its value on a press anywhere on the track

`<input type="range">` jumps the handle to wherever the track is pressed. On a phone that makes
the beginning of a scroll indistinguishable from a deliberate adjustment, so members found their
ChitChat distance had changed and nothing said why.

It is easy to fix the wrong half of this. `touch-action: pan-y` and a `preventDefault` on wheel
both look like the cause, both are worth having, and neither stops a plain press on the track
from moving the handle. The suite passed after that first attempt and the complaint stayed.

`RangeSlider.vue` covers the track either side of the handle with two inert divs, leaving a gap
around the handle, so the only press the input can receive is one on the handle itself. The gap
is positioned from the same fraction the browser lays the handle out with, and sized from the
same custom property that sizes the handle, so the two cannot drift apart. Change the handle
size in `--range-slider-thumb` and nowhere else.

Unit tests cannot see any of this: jsdom has no layout, so the covers are in the DOM in the
right order whatever their geometry would really be. Check it in a browser, with
`document.elementFromPoint` at the handle and at both ends of the track.

## Enter bound twice sends twice

A comment box bound `keydown.enter` on the wrapping element **and** `keyup.enter` on the
textarea inside it. One key press fired the send twice, and because the box is cleared only
after the send resolves, both calls passed the non-empty check and both posted.

It surfaced as an **editing** bug: the member saw their original and edited text side by side,
because they had unknowingly posted twice and then edited one copy. Two rows with the same
author, the same parent and the same timestamp is the signature.

Bind the key once, and clear or disable the input before awaiting.

## A setup function that registers a watcher per call

`modtools/composables/useModMessages.js` `setupModMessages()` carries a comment forbidding
watchers inside it, and then registers several. Each call adds another `watch`, and each firing
watch triggers a full clear-then-refetch. The cost is invisible locally and shows up as repeated
identical API calls in production logs.

When a composable's setup runs per component instance, anything it registers is per instance
too. Check before adding a watcher, an observer, or an interval.

## Stores, SSR and registration

- **Calling `useXStore()` after an `await` in SSR** throws "no active Pinia", most visibly
  during prerender. Capture the store before the first await.
- **A new store used by a ModTools page must be registered in that app's init**, or it is simply
  absent at runtime.
- **An unguarded top-level await in a component silently fails the thing it was opening.** A
  rejected fetch left a reply overlay that never appeared, with no error shown.

## Editing the chat has three headers to choose from

Chat has three header implementations and the one members actually see is inline markup, not the
component named `ChatHeader`, which is ModTools only. Changing the obvious file changes nothing
for members.

## A widget a third party draws for us can fail without saying so

`LoginModal` leaves an empty element for Google to draw the sign-in button into, and Facebook's
SDK decides whether its button is usable. Neither tells us when it goes wrong, and both go wrong
in the field: Facebook login was dead on the iOS app for five days in August 2026, and in
September 2026 a member's login screen had no Google button at all, because Google's script never
delivered one.

The shape to avoid is asking a third party to draw something and then trusting that it did.
**Check the result, retry, and report**: `drawGoogleButton` measures the element afterwards, so a
button never drawn and a button drawn too small to use are both caught. Code that only checks the
call returned cannot tell either from success.

Three things make this worse than it sounds:

- **An empty container is not necessarily blank.** `.social-button--google` carries
  `border: 1px solid` and `min-height: 42px`, and sits in a centred column, so an empty one
  shrink-wraps to a **1px wide, 42px tall grey line**. That is what the member photographed. It
  reads as a broken button rather than a missing one, which sends you looking for why Google drew
  it wrong instead of why Google drew nothing. Reproduce the empty state and measure it before
  believing either story.

- **A flag that nothing ever sets reads as "never blocked".** `showSocialLoginBlocked` was
  declared, read in two computed properties, and assigned nowhere, so the "social sign in
  blocked" warning could not appear for Google however broken it was.

- **Waiting is not failing.** The script is deliberately held back until the browser is idle for
  a first-time visitor, so a retry loop that counts those ticks as attempts will report every
  slow visitor as broken. Only count an attempt when we actually asked.

## Libraries and packaging

- **vue-leaflet imports a bare `leaflet`** when the global is unset, producing a second Leaflet
  instance and two maps that do not agree.
- **Lockfiles are not interchangeable between npm 10 and npm 11.** A lockfile written by one
  fails a clean install under the other.
- **Capacitor's plugin allowlist overrides dependency scanning**, so adding a dependency is not
  enough; it must be listed.
- **A white screen on app launch** is usually the combination of no splash plugin, an empty body
  in the entry HTML, and a root component that suspends.

## See also

- `docs/developers/` for how the feeds and stores are meant to work.
- Testing notes live in `docs/developers/testing.md`.
