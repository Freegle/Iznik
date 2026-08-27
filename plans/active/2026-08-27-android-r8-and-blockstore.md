# Play technical-quality compliance: R8 for FD/MT, Block Store sign-in restore

Google Play, announced 2026-08-26, sets three new thresholds
(https://support.google.com/googleplay/android-developer/answer/17492799):

- **DEX optimization** (Feb 2027): apps whose DEX exceeds 10 MB need >=25% shrinking,
  optimization and obfuscation. Measured DEX today: FD **40.4 MB**, MT **14.9 MB**, both
  at 0% because `minifyEnabled false`. IRCOBI is 9.6 MB (under the floor) and is out of
  this piece of work by decision.
- **Memory / bitmap** (Feb 2027): 90th-percentile RSS+swap per app state, plus bitmap caps
  of 200 MB background / 400 MB cached. Read from Play Console vitals, no code change
  chosen blind.
- **Zero-Tap Sign-In restoration** (Apr 2027): apps with sign-in must restore session on a
  new device. **A Block Store integration shipped on or before 30 September 2026 counts as
  compliant**, which avoids the Restore Credentials API and its WebAuthn-shaped server work.

FD and MT share one native project (`iznik-nuxt3/android`) and one Nuxt codebase (MT is a
Nuxt layer with `extends: ['../']`), so both requirements are one change each.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Plan + branch | ✅ | Edit in main checkout (clean, detached), PR from temp clone |
| 2 | R8 on for release, keep rules | ✅ | `minifyEnabled true`, optimize file, package-agnostic rules |
| 3 | Verify R8 locally (DEX size + kept classes) | ✅ | 54.5MB -> 10.0MB; keep targets confirmed in mapping.txt; ModTools-shaped build too |
| 4 | Block Store native plugin | ✅ | `org.freegle.blockstore.BlockStorePlugin`, E2EE-gated cloud backup |
| 5 | JS wiring: save / restore / clear | ✅ | `useSessionRestore.js`; setAuth saves, logout clears, both boot paths adopt |
| 6 | Unit tests | ✅ | 22 composable + 5 store + 2 boot; fixed 3 mocks the new action broke |
| 7 | Docs | ✅ | new `docs/developers/reference/play-technical-quality.md` + README-APP section |
| 8 | Play Console memory + OOM panels | ⬜ | Needs Edward logged in to the headed Chrome |

## Design decisions

**Keep rules must be package-agnostic.** The MT build rewrites
`org.ilovefreegle.direct` -> `org.ilovefreegle.modtools` in `build.gradle`, the manifest and
`MainActivity.java` (orb `build-android-modtools`, "Configure ModTools Android Identity"),
but it does **not** touch `proguard-rules.pro`. Any rule naming the FD package would
silently stop applying to the MT build. Rules therefore match on annotation, superclass or
interface, never on `org.ilovefreegle.*`.

**Block Store, not Restore Credentials.** The app already holds a long-lived `persistent`
token (`stores/auth.js`, sent as `Authorization: Iznik <json>`), and `bootSession()` already
accepts `persistent` alone as credentials. Block Store just needs to carry that token to the
new device: no server change, no challenge/assertion verification. Restore Credentials would
need a passkey-capable backend Freegle does not have.

**Local plugin, not an npm package.** `BlockStorePlugin` lives in the app module under a
neutral package (`org.freegle.blockstore`) so the MT package rewrite cannot break its
imports, and is registered explicitly in `MainActivity.onCreate` before `super.onCreate`.
`includePlugins` in the capacitor configs only filters node_modules plugins, so neither
config needs a new entry.

**Clear on logout.** Without a delete on logout, a restored device signs a logged-out user
back in. `logout()` clears the Block Store entry before `$reset()`.

## Verification

R8: `./gradlew assembleRelease` with the debug keystore injected, then compare DEX size and
confirm the reflection targets survive (Capacitor plugin classes and their `@PluginMethod`
methods, the `@JavascriptInterface` share bridge, the social-login MainActivity interface,
the Block Store plugin).

Runtime paths that R8 can break and that need a device smoke test before release: Google /
Facebook / Apple sign-in, push + badge, camera and share-into-app, Stripe donation.

## Notes from doing it

**The local checkout's node_modules was a major version behind** (Capacitor 7 installed against
Capacitor 8 in package.json), which broke the first two release builds:
`:capacitor-community-stripe:compileReleaseKotlin` failed with an internal compiler error,
because the stale plugin (7.2.1, Kotlin 2.1) cannot read Stripe SDK 23.1.0's Kotlin 2.3
metadata. `npm ci` then `npx cap sync android` fixes it. `cap sync` also needs
`FREEGLE_NUXT3_KEYSTORE_*` set (capacitor.config.ts throws without them) and a
`.output/public/index.html` to copy.

**Three test mocks had to learn the new store action.** `authStore.adoptRestoredSession()` is
called from both boot paths, so `tests/unit/mocks/auth-store.js`,
`tests/unit/composables/useBootSession.spec.js` and
`tests/unit/components/modtools/ModtoolsDefaultLayout.spec.js` all failed with
"not a function" until their fakes gained it. The ModTools layout failure showed up as
`.leftmenu` missing, because the throw in `<script setup>` stopped the layout rendering at all.

**32 unrelated-looking failures in `useReachDistance.spec.js` were the container, and are
fixed, not dismissed.** `DISTANCE_AXES[axis]` was undefined because the vitest container's
baked `/app/constants.js` (121 lines) predates the host's (154 lines) - the known
never-syncs-into-the-container trap. `docker cp constants.js` into
`freegle-modtools-dev-local` and all 32 pass.
