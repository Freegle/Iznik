# App startup white screen - research and options

Date: 2026-07-17. Research only - nothing implemented. Prompted by slow app startup
(white screen on launch) and Discourse 9928 (member: app fine in the morning, worse
after 5pm).

## TL;DR

The white screen is real and has three stacked causes, all fixable independently:

1. **The native splash dismisses onto a blank page.** No `@capacitor/splash-screen`
   plugin is installed, so the OS splash goes away on the WebView's first (empty)
   frame instead of staying up until the app is ready.
2. **The web app paints nothing until the whole SPA boots.** App builds are
   `ssr: false` with `spaLoadingTemplate: false`: the shipped `index.html` has an
   empty body, one 515KB entry module, and ~92 JS files (~1.4MB) execute before
   first render. Prior profiling (PR #282, Apr 2026) measured ~700ms of white
   between splash dismiss and first Vue render on Android; older/mid devices pay
   several seconds.
3. **First paint is blocked on the network - much more than expected.** The app
   always boots on `/`. Nuxt's root Suspense waits for BOTH of these parallel
   chains before painting anything:
   - Chain A: `layouts/default.vue:71` `await fetchUser()` = GET `/apiv2/session`,
     then a chained group fetchBatch (2 sequential round trips).
   - Chain B: `pages/index.vue:156-174` - `await groupStore.fetch()` (uncached),
     then `await messageStore.fetchInBounds()` (the UK-wide-bbox query: p50 ~1.4s,
     p95 ~3s in prod), then a batched message-detail fetch (+50ms debounce timer).
     **3 sequential round trips that run unconditionally on every cold start, even
     for logged-in users who are redirected to /browse and never see this data.**
4. **The work is then done twice.** After paint, logged-in users are redirected to
   `/browse`, which swaps to `layouts/login.vue:86-99` - re-running the identical
   `await fetchUser()` (second GET `/apiv2/session` + group batch within ~1s; the
   dedup wrapper `composables/useMe.js` `fetchMe()` exists but neither layout uses
   it), plus a third uncached `groupStore.fetch()` in browse's onMounted.

## Current cold-start sequence (verified in code + measured)

| Step | What the user sees | Cost |
|---|---|---|
| Native launch | OS splash (iOS: full-screen logo storyboard; Android 12+: icon splash via `Theme.SplashScreen`, `AppTheme.NoActionBarLaunch`) | ~0.3-0.8s |
| WebView first frame | **White** - splash dismisses immediately (no splash plugin, empty index.html body) | - |
| JS parse + boot | **White** - 515KB entry (`mmlgqI5E.js`) + module graph, 31 Pinia store inits in `app.vue`; mobile init (push channels, device info, update check) fire-and-forget but competing for the main thread | ~0.7s (measured Apr 2026, modern Android) to multi-second on older devices; 0.8s warm / 3.4s cold in Chrome at 4x CPU throttle |
| Root Suspense: max(Chain A, Chain B) | **White** - nothing paints until both resolve | Chain A: `/apiv2/session` (p50 98ms, p95 632ms, max 1.2s; prod Loki Wed 17:00Z) + chained group batch. Chain B: `/apiv2/group` + `/apiv2/message/inbounds` (UK-wide bbox p50 ~1.4s, p95 ~3s, max 8s; prod Loki + direct samples) + batched message fetch, all sequential. Chain B dominates. |
| Landing page paints, then redirect | Logged-in users see the logged-out landing page flash, then `/browse` | onMounted → router.push('/browse') |
| /browse | Second `await fetchUser()` in `layouts/login.vue` before browse renders (old page stays visible, so delay not white) + third uncached group fetch | second `/apiv2/session` + group batch |

Measured end-to-end in Chrome (built app bundle served locally, 4x CPU throttle,
production API): first-paint 752ms (white), first contentful paint 4,280ms. The
paint was blocked by JS boot, then by `message/inbounds` which took 2.36s server-side.
The app's own telemetry ('interactive' = `app:suspense:resolve` in
`plugins/pageload-tracking.client.js:37`) uses exactly this gate, so if clientlog
phases were recorded with timings we could measure this in the field.

Key facts with sources:

- `nuxt.config.ts:114-117` - `target: 'static'`, `ssr: false`, `spaLoadingTemplate: false` for ISAPP builds.
- `capacitor.config.ts:34` - `webDir: '.output/public'`, bundled locally; no remote server.url.
- No `@capacitor/splash-screen` anywhere (package.json, includePlugins, plugins block).
- `layouts/default.vue:64-94` - two top-level `await authStore.fetchUser()` calls before layout renders.
- `stores/auth.js:340+` - fetchUser = GET `/apiv2/session` then `await groupStore.fetchBatch(memberships)` (chunked, 25/call).
- `app.vue:203-265` - 31 Pinia stores created + init'd synchronously in root setup.
- `hooks['build:manifest']` in `nuxt.config.ts:323-331` strips ALL preload/prefetch links (helps web FCP, but for the app it removes hints that would let the local WebView parse chunks earlier).
- Entry chunk analysis: `DQYUI595.js` (467KB) is almost entirely the Sentry SDK; leaflet, stripe, uppy etc. are route-lazy.
- ~16MB of the 23MB `_nuxt` payload is sourcemaps (`sourcemap.client: true` unconditionally, `nuxt.config.ts:618`; no CI step strips `.map` before `npx cap sync`) - install-size cost, not runtime, but worth fixing.
- Real-user startup telemetry: none today. `[STARTUP]` console instrumentation (commit cc8d930f6, Dec 2025) was added then removed. `pageload-tracking.client.js` only tags API calls with a phase; it does not record startup duration.
- Prior art: PR #282 (monorepo 316705805, Apr 2026) - EmojiCompat disabled, duplicate Stripe registration removed, Sentry plugin made sync. Commit 72364748d - entryImportMap disabled for app builds (iOS < 16.4 white-screen fix).
- No route middleware exists; the app has no "start on /browse when logged in" logic - the redirect happens in `pages/index.vue:239-245` onMounted, i.e. only AFTER the full blocked boot.
- `pages/index.vue:156` and `pages/browse/[[term]].vue:718` both call `groupStore.fetch()` (no-id variant, `stores/group.js:134-146`) which has NO cache check - the full group list is fetched twice within ~1s on a logged-in cold start.
- `composables/useMe.js:7-66` already contains a `fetchingPromise` dedup wrapper (`fetchMe()`) built to prevent duplicate session fetches, but neither `layouts/default.vue` nor `layouts/login.vue` uses it - both call `authStore.fetchUser()` directly.
- API layer: `api/BaseAPI.js` + `composables/useFetchRetry.js` retry up to 10 times with linear backoff on network/5xx errors - on a flaky mobile connection a single failed boot call can extend the white screen by many seconds.
- Evening slowness (Discourse 9928): API latency is load-dependent, but samples show the big boot-path costs (inbounds UK-wide bbox ~1.5-3s) are bad at ALL times of day; morning samples hit 8s. The fixed costs dominate; evening variance comes on top.

## Options

Grouped by what they attack. They are independent; the biggest wins are B1/B2
(stop blocking paint on the network) and A1/A2 (make the remaining wait look
intentional).

### A. Perceived startup - kill the white flash (cheap, low risk)

**A1. Install `@capacitor/splash-screen` and hold the native splash until the app
is ready.** `launchAutoHide: false`, then call `SplashScreen.hide({ fadeOutDuration })`
from the web app when it has painted something real (e.g. on `app:suspense:resolve`,
with a hard timeout fallback so a hung API can never strand the splash). This is
the single cheapest fix for "white screen while the app loads its code" - the
user sees the branded splash the whole time instead.

Verified facts (all 3-0 adversarial verification, official sources):
- Without the plugin, Android dismisses the splash "as soon as your app draws its
  first frame" (official Android docs) - the blank WebView frame counts. This is
  the confirmed direct cause of our white screen appearing so early.
- Plugin defaults are `launchAutoHide: true` + `launchShowDuration: 500ms`;
  `launchAutoHide: false` is the documented way to hold the splash until
  `SplashScreen.hide()` - there is no OS-imposed max duration, so we must add our
  own fallback timer.
- On Android 12+ the OS SplashScreen API takes over the launch splash and ignores
  most plugin options at launch (backgroundColor, scale type, spinner, fullscreen);
  our theme already has the required `Theme.SplashScreen` parent. The plugin wires
  up `installSplashScreen()` itself - no MainActivity change needed.
- Hazard (confirmed by a Capacitor maintainer, capacitor-plugins#1856): calling
  `hide()` while the app is backgrounded crashes natively on Android 12 (and some
  OEM Android 13 devices - Oppo/OnePlus). Guard the hide call with a foreground
  check. (The claim that the auto-hide timeout path also crashes was refuted.)
- iOS note: Apple's HIG says launch screens should mirror the app's first screen,
  not be a branding moment - our current full-logo storyboard is technically
  against HIG (common in practice, and unlikely to be an issue, but the HIG-purist
  alternative is a launch screen that looks like the app's navbar/skeleton, which
  dovetails with A2).

**A2. Give the SPA a branded loading shell via `spaLoadingTemplate`.** We
explicitly set it to `false`; instead, point it at a small HTML template (logo +
spinner + app background colour, critical CSS inlined) so the very first WebView
frame is branded rather than blank. Works for `ssr:false` static builds - the
template is baked into the shipped `index.html` and replaced when Vue mounts.
This also fixes the web-SPA routes' white flash for free.

Verified facts: this is Nuxt's official app-shell mechanism for exactly our setup -
the `app/spa-loading-template.html` contents are "inserted into any HTML page
rendered with ssr: false" (official Nuxt docs, 3-0). Caveats: it must be enabled
explicitly in nuxt.config (auto-discovery without config was refuted; Nuxt 3.7+
defaults it OFF), and there is an unresolved question whether the old
"template hides on app mount, before lazy page components render" gap
(nuxt/nuxt#21721) affects our Nuxt version - needs a quick local test. If we also
do B1/B2 the gap is moot: the shell hides straight into a rendered page skeleton.
Belt-and-braces: A1's splash covers the template period anyway; A2 mainly protects
web users and the app's second-navigation white flashes.

**A3. Dynamic loading shell (the "store the HTML" idea, done safely).** The
template file itself is static - baked into the shipped `index.html` at build
time, and the app bundle can't be rewritten on device. But the template can
contain a tiny inline `<script>` that runs as soon as the HTML parses - hundreds
of ms before the 515KB entry compiles - and that script can synchronously read
`localStorage` (same WebView origin; it's where pinia-persistedstate already
lives) and build the shell DOM on the fly. Verified in our Nuxt (3.21.5): with the
default `spaLoadingTemplateLocation: 'body'`, the loader div is a sibling of the
app root and Nuxt removes it on `app:suspense:resolve`
(`nuxt/dist/app/entry.js:54-58`) - it persists through the entire boot and is
swapped out exactly when the real app is ready. Three levels:
- **Static skeleton** (A2 as-is).
- **Parameterised shell**: inline script picks logged-in vs logged-out chrome,
  fills in user name/avatar, last route's skeleton shape - a few KB of logic,
  big perceived win, low risk.
- **Full DOM snapshot**: on `pause`/`visibilitychange`, save a sanitized HTML
  snapshot of the current shell to localStorage (stamped with build hash +
  user id); on launch, re-inject it instantly. Closest to "app reopens where
  it was" - but sharpest edges.
Hard limits (why this is a facade, not SSR): Vue in `ssr:false` mode mounts fresh
and CANNOT hydrate/adopt the stored DOM - the snapshot is thrown away when the
app mounts. So it cuts *perceived* latency to ~WebView-init time but does not
make the app interactive any sooner; taps on the facade do nothing. If boot still
takes seconds (blocking awaits unfixed), a real-looking dead UI frustrates more
than a skeleton - so A3-full pairs with B1/B2, never replaces them. Other risks
for the full-snapshot level: stale content (wrong unread badges, shows
logged-in UI after logout), sensitive chat text persisted in plaintext on disk
(snapshot only chrome/skeleton, never message bodies), and hashed CSS classes
changing between builds (build-hash guard, fall back to generic skeleton).

### B. Stop blocking first paint on the network (the big structural win)

**B1. Don't run the landing-page data cascade in the app.** `pages/index.vue`
unconditionally awaits group list → UK-wide `message/inbounds` (p50 ~1.4s) →
message batch before ANYTHING paints, even for logged-in users who are
immediately redirected to `/browse`. Options, cheapest first:
- Make those awaits conditional (`if (!me && !mobileStore.isApp)`), or move them
  out of the Suspense path (`onMounted` + skeleton) so paint isn't gated.
- For app builds, boot straight into `/browse` (client-side: check persisted
  `auth.jwt`/`loggedInEver` in a tiny route middleware or entry plugin before the
  index page's setup ever runs).

**B2. Don't `await fetchUser()` before painting the shell.** Render navbar +
skeleton immediately; fetch the session in the background and let the UI update
reactively (`loginStateKnown` already exists for exactly this kind of gating).

⚠ History check (Edward, 2026-07-17): in principle "assume logged in, let the
server call disprove it" is acceptable, but it opens many timing windows, so it
needs extremely careful testing - and it HAS been lived with before. Verified
from the archived iznik-nuxt (Nuxt 2) history:

**The Nuxt 2 app ran optimistic boot for its whole life.** `target: 'static'`
meant the shipped HTML was generic; the full user object was persisted client-side
(Vuex + localForage/IndexedDB) and the UI rendered from that cache immediately,
with a deliberately deferred (`setTimeout 100ms`) fire-and-forget `fetchMe()` to
reconcile ("Defer it as we can get snappier rendering"). It was never a named
experiment that got reverted - it was the architecture. The documented costs:
- **`0e5165d4` (Mar 2022) "Possible fix for app being logged out"**: API calls
  raced ahead of the IndexedDB credential restore, went out with no auth header,
  the server answered "not logged in", and the axios interceptor treated that as
  a genuine logout and wiped the store - a race that FORCED real users out.
  Fixed with a hard sync point: `await this.store.restored` inside
  `BaseAPI.$request` so no call path could outrun the restore.
- **`d59936be`/`9bfd1d9c` (Oct 2021)**: stale pre-rendered content visibly
  jumping when the client corrected it (Google-flagged CLS) - fixed by rendering
  neutral content when the state was uncertain.
- **`c4ebf919` (Oct 2023)**: the ModTools app flashed the Freegle landing page at
  startup; fixed by abandoning the optimistic guess for that case and showing a
  neutral loading spinner instead.
- The Feb 2022 localStorage→IndexedDB persistence migration was rocky ("Fix up
  corrupt store", "Clear new store when we log out", ~30 commits) - the cache
  the optimistic render depends on is itself a failure surface.
- No cross-user leakage bug was ever recorded (searched for it explicitly).

Lessons if we retry it in nuxt3: (1) gate the API CLIENT, not the render - no
request may leave before persisted auth is loaded (nuxt3's synchronous
localStorage persistence makes this cheaper than Nuxt 2's async IndexedDB);
(2) never let a boot-window 401/"not logged in" response clear stored credentials;
(3) render neutral (skeleton) rather than guessing wherever the guess can be
reliably wrong; (4) suppress layout-shifting corrections (CLS) when the truth
arrives.

**And the iznik-nuxt3 history confirms this ground has been walked repeatedly.**
Why the blocking await exists (documented in commit history):
- Jun 2022 `12a327e0`: first fetchUser was an ACCIDENTALLY non-blocking
  `beforeCreate()` call. Jul 2022 `205f82cc` made it a deliberate top-level await.
- Feb 2023 `2172a58d`/`75c60f6f`: the rationale, verbatim in a comment that
  survives today: "We need this so that we don't trigger API calls without a JWT
  when we are in fact logged in" - the same correctness concern as Nuxt 2's
  forced-logout race. Also: the await was chosen as the FASTER of two delays
  (vs waiting for GoogleOneTap), not because blocking was desired.

Every nuxt3-era attempt at a non-blocking reveal mechanism failed:
- `<NuxtPage :key="loginCount">` remount (2023): reverted in `b32d36d4` - "using
  a key on NuxtPage results in errors which can cause the JS to bomb out".
- `<Suspense>` in 5 components (2022-2023): removed codebase-wide in `017444de`
  as "flaky".
- `reloadNuxtApp` hard reload on login (Jul 2023): commented out Apr 2025
  (`db794f4ec`, the dead block at app.vue:277-285); ModTools independently
  reinvented it and killed it again in Apr 2026 (`3a344b3c`) after it raced
  Playwright navigation ("Execution context was destroyed") - twice-proven bad.
- Mar 2026 `51615e57`/`cac650cb` (marketing-optout page): live demonstration of
  the blocking pattern's own failure mode - "Top-level await in script setup ...
  causes the component to suspend until the API call completes. If the login API
  hangs (e.g. in CI), the h1 never renders." Fixed with **onMounted + hard 10s
  safety timeout** - the one surviving, recent, successful non-blocking pattern,
  NOT yet backported to layouts/default.vue or login.vue.

Read together, the two histories say: the OPTIMISTIC ASSUMPTION itself is
workable (Nuxt 2 shipped it for years; the killer bugs were API calls racing
credentials, and bad reveal mechanisms), but the REVEAL mechanism must be the
already-proven reactive `loginStateKnown`/`bump` watcher (now also adopted by
ModTools as "same pattern as Freegle") plus the Mar 2026 onMounted+timeout
pattern - never Suspense, key-remounts of NuxtPage, or hard reloads. Timing
windows to test exhaustively: API calls before credential load, boot-window 401
handling, login flip mid-interaction, Playwright navigation races, CLS on
correction, logout-then-relaunch staleness.

Also found: `layouts/login.vue` only has the first (jwt-gated) fetchUser branch,
not the second `!loginStateKnown` one default.vue gained in Dec 2025 - accidental
drift worth tidying whichever way we go. And there has NEVER been a route
middleware in this repo; gating has always lived in layout/app-root script setup.

At minimum (these two are safe regardless of the optimistic-boot question):
- Use the existing `fetchMe()` dedup (`composables/useMe.js`) in both layouts so
  the session isn't fetched twice on every logged-in cold start.
- Move the chained `groupStore.fetchBatch()` out of `fetchUser()`'s critical path
  (nothing painted at boot needs full group details).

**B3. Optimistic render from a persisted snapshot.** Persist a minimal user
snapshot (id, name, group ids, unread counts) alongside the already-persisted JWT;
on boot, hydrate stores from it and render the real UI instantly, then revalidate
with `/apiv2/session` in the background and reconcile (logout/kill-switch paths
must still work - ret 123 handling stays). This is the "how other apps feel
instant" pattern: they render last-known state and refresh quietly.

Research status: the external pass found essentially NO verified community
guidance on optimistic Pinia-persisted session boot in Ionic/Capacitor apps -
this angle survived on first principles only, so treat it as sound-but-unsourced
engineering. Supporting precedent: iOS itself fakes instant resume by showing a
cached screenshot of last state before the process is even alive (confirmed 3-0,
matches Apple's `ignoreSnapshotOnNextApplicationLaunch` API). Design the
reconciliation path carefully (stale unread counts, mid-flight logout, account
switch via `userlist`).

**B4. Server-side: fix or avoid the UK-wide `message/inbounds` query.** p50 ~1.4s,
p95 ~3s, max 8s at all times of day for the whole-UK bbox. Even off the critical
path it burns server capacity on data mostly never seen. Candidate: cached
response for the default bbox, or a cheap "recent posts sample" endpoint.

### C. Cut JS boot cost

**C1. Lazy-load the Sentry SDK.** `DQYUI595.js` (467KB) is essentially the Sentry
bundle; capture-and-replay early errors with a tiny queue, import the SDK after
first paint.
**C2. Restore modulepreload hints for app builds.** The `build:manifest` hook
strips all preload/prefetch links for web-FCP reasons; for the app, assets are
local files - preloading is nearly free and lets the WebView fetch/parse the
92-file module graph in parallel instead of discovering it serially.
**C3. Defer non-critical store/plugin work.** 31 stores init in root setup;
mobile init (6 push channels, permissions, update check) competes for the main
thread during boot. Delay to idle/post-paint.
**C4. Housekeeping: stop shipping ~16MB of sourcemaps in the APK/IPA** (gate
`sourcemap.client` on !ISAPP or strip `.map` before `npx cap sync`; Sentry upload
happens at build time anyway). Install-size/download win, not runtime.

External-research status for C: V8's bytecode cache is real (needs the same script
loaded twice, and is gated on HTTP caching semantics - 304 keeps it, 200 clears
it; official V8 blog, 3-0) but **whether Capacitor's custom local scheme
(androidScheme https / capacitor://localhost) engages it at all is unverified** -
an open question worth a 1-day experiment (relaunch twice, profile
compile-vs-execute in DevTools) before betting on it. No verified WebView
"warm-up" technique and no verified mid-range parse-cost figures survived the
adversarial pass; popular blog prescriptions (capgo's "40% faster launch",
nextnative's "170KB budget") were explicitly refuted as unsupported. C1-C3 stand
on our own measurements (467KB Sentry chunk; 92 files before first API call).

### D. Snapshot / screenshot-restore techniques (the "retain a screenshot" idea)

Research verdict: **no maintained Capacitor or Cordova plugin exists** that shows
a screenshot of the app's last state at cold launch - the pass found none, and
flagged it as an open question. What IS confirmed (3-0):
- iOS natively fakes instant resume with a cached snapshot of the last app state
  (task switcher / warm relaunch); the OS handles it, and it can even make a
  killed app look alive. We get this behaviour for free on iOS warm launches
  already - it's cold starts where the white screen bites.
- Nothing analogous is exposed on Android for app-controlled cold-start snapshots.
Building it ourselves (native code: capture WebView bitmap on pause, show it as
an overlay at launch until the web app signals ready) is feasible but is a custom
native project with real risks: stale data on screen, showing logged-in content
after logout, screenshots of sensitive chats persisted to disk. Given A1 gives us
a held branded splash for a fraction of the effort, the snapshot trick is poor
value; consider only if we later want "resume where you were" polish. iOS's own
snapshot mechanism plus B3 (instant real UI from persisted state) achieves the
same perceived effect more honestly.

### E. Measure it for real

**E1. Add startup telemetry.** The phases already exist
(`pageload-tracking.client.js`; 'interactive' = suspense resolve). Record
`performance.now()` at entry-script start, suspense resolve, and first API
completion, send once via `/apiv2/clientlog`, so we can see real-device startup
distributions and verify improvements (and the "worse after 5pm" claim) instead
of relying on anecdote.

### F. Ideas considered and rejected

- **Prerendered logged-in shell HTML + hydration ("store the HTML like SSR").**
  No verified evidence exists that a prerendered logged-in shell can be hydrated
  by Nuxt ssr:false without mismatch; the research pass killed every claim in
  this angle. Nuxt's supported answer for ssr:false is exactly
  `spaLoadingTemplate` (A2) - same visual benefit, no hydration risk. A truly
  hydratable personalised shell would mean SSR-style payload management we've
  deliberately avoided in app builds.
- **Screenshot-restore plugin** - doesn't exist; custom native build is poor
  value vs A1 (see D).
- **Relying on WebView bytecode caching** - unverified for Capacitor's local
  scheme (open question; cheap experiment possible, but don't plan around it).
- **Native "suspend first frame until ready" API** (ViewTreeObserver preDraw
  trick) - refuted as a documented mechanism in this context; the splash plugin
  achieves the same outcome supported.

## Recommendation

1. **A1 + A2 (splash held until app-ready + branded SPA loading shell).** Kills
   the white screen outright for a few days' work, no structural risk. Include
   the foreground-guard on `hide()` and a fallback timer.
2. **B1 first, then B2 carefully.** B1 (stop `pages/index.vue`'s 3-round-trip
   cascade blocking paint, skip it entirely for logged-in/app users) is safe and
   uncontroversial - it doesn't touch the login-state machinery at all and
   removes the biggest cost (the ~1.4-3s inbounds chain). B2 (non-blocking
   `fetchUser()`) is approved in principle but history-laden: use ONLY the
   proven patterns (reactive `loginStateKnown`/`bump` flip + Mar 2026
   onMounted-with-timeout precedent, API client gated on credential load), never
   Suspense/key-remount/hard-reload, and budget serious test effort for the
   timing windows listed under B2. The `fetchMe()` dedup and moving group
   fetchBatch off the critical path are safe regardless.
3. **E1 (startup telemetry via clientlog)** in the same batch, so the wins are
   measured on real devices and regressions get caught.
4. Then: C1 (lazy Sentry), C2 (restore modulepreload for app builds), C3 (defer
   store/mobile init), C4 (sourcemap stripping), B3 (optimistic boot from
   persisted snapshot) as a second wave; B4 (inbounds server cost) as a
   server-side ticket regardless.

## Key sources (adversarially verified)

- https://capacitorjs.com/docs/apis/splash-screen (plugin defaults, Android 12 option caveats)
- https://developer.android.com/develop/ui/views/launch/splash-screen (dismiss-on-first-frame)
- https://github.com/ionic-team/capacitor/discussions/5816 (maintainer guidance: launchAutoHide/theme/installSplashScreen)
- https://github.com/ionic-team/capacitor-plugins/issues/1856 (backgrounded hide() crash)
- https://nuxt.com/docs/3.x/guide/concepts/rendering + /api/nuxt-config (spaLoadingTemplate mechanism + explicit-config requirement)
- https://v8.dev/blog/code-caching-for-devs (bytecode cache mechanics; applicability to Capacitor local scheme unverified)
- https://developer.apple.com/design/human-interface-guidelines/launching (launch screen = first-screen mirror)
- Workflow stats: 21 sources fetched, 78 claims extracted, 25 verified with 3-vote
  adversarial panels, 14 confirmed / 11 refuted. Refuted claims are listed above
  so they don't get relied on later.

