---
last_reviewed: 2026-08-27
owner: Freegle dev team
covers:
  - iznik-nuxt3/android/app/build.gradle
  - iznik-nuxt3/android/app/proguard-rules.pro
  - iznik-nuxt3/android/app/src/main/java/org/freegle/blockstore/**
  - iznik-nuxt3/android/app/src/main/java/org/ilovefreegle/direct/MainActivity.java
  - iznik-nuxt3/composables/useSessionRestore.js
---

# Google Play technical quality: DEX, memory, zero-tap sign-in

Google Play announced three technical-quality thresholds on 2026-08-26
([support article](https://support.google.com/googleplay/android-developer/answer/17492799)).
Missing them costs Store visibility and, eventually, the ability to publish.

Freegle and ModTools are built from the one native project (`iznik-nuxt3/android`) and the one
Nuxt codebase (ModTools is a Nuxt layer with `extends: ['../']`), so each item here is a single
change that covers both apps. Play measures the uploaded AAB, so none of this affects the web
site or the iOS builds.

| Requirement | Enforced from | Applies to us | Where we stand |
|---|---|---|---|
| DEX optimized >=25% (shrink, optimize, obfuscate), if DEX > 10MB | February 2027 | Freegle 40.4MB, ModTools 14.9MB in the shipped builds | R8 on: 54.5MB -> 10.0MB |
| Memory + bitmap thresholds per app state | February 2027 | Both | Not yet measured, see below |
| Zero-Tap Sign-In restoration | April 2027 | Both (Freegle and ModTools both have sign-in) | Met via Block Store |

The IRCOBI conference app (separate repo) was measured at the same time: 9.6MB DEX, so under
the floor, and it has no user sign-in, so only the memory thresholds can apply to it.

## DEX optimization

Both apps shipped with `minifyEnabled false`, so both were at 0% on all three metrics, and
both are over the 10MB DEX floor at which the requirement bites. Freegle's 40MB is mostly
Stripe and the Compose it drags in; ModTools has no Stripe (its `includePlugins` omits it) and
is dominated by the Facebook SDK, play-services-auth and androidx.credentials.

Release builds now run R8. Two details are load-bearing:

- **`proguard-android-optimize.txt`, not `proguard-android.txt`.** The plain file carries
  `-dontoptimize`, which would leave Play's optimization metric at zero even with minification
  switched on.
- **Keep rules never name our own package.** The ModTools build rewrites
  `org.ilovefreegle.direct` to `org.ilovefreegle.modtools` in `build.gradle`, the manifest and
  `MainActivity.java` (orb job `build-android-modtools`, step "Configure ModTools Android
  Identity") but does **not** touch `proguard-rules.pro`. A rule naming the Freegle package
  would silently stop applying to ModTools. Rules match on annotation, superclass or
  interface instead.

Resource shrinking stays off: Play measures code, and the bulk of the APK is `assets/public`,
the Nuxt bundle, which resource shrinking cannot touch.

Measured on the current tree, all plugins included: **54.5MB of DEX unminified, 10.0MB
minified**, an 82% cut, so the 25% thresholds are cleared with room to spare. (The 40.4MB and
14.9MB figures above come from the shipped APKs, which predate the Capacitor 8 upgrade.) The
same build with the ModTools package rewrite applied also succeeds and keeps the same classes,
which is the check that matters for the package-agnostic rules. `mapping.txt` confirms what
survived: `BlockStorePlugin` and `MainActivity` unrenamed, and `ShareIntentBridge.consume()`
keeping its name even though R8 renames the class around it, which is what the WebView needs
since the interface name is bound at runtime.

For reference, `proguard-android.txt` really does carry `-dontoptimize` (line 16 of the copy AGP
8.13 extracts into `android/app/build/intermediates/default_proguard_files/global/`), so the
choice of default file is not cosmetic.

R8 breaks things by removing or renaming what only reflection or JS reaches. Capacitor,
Firebase, Facebook, Stripe and play-services all ship their own consumer rules;
`proguard-rules.pro` adds the WebView share bridge, the social-login activity interface and
the push plugin's `Class.forName` on MainActivity. **Before a release goes out, smoke-test on a
device:** Google, Facebook and Apple sign-in; push and the home-screen badge; camera and
share-into-app; the Stripe donation flow.

The mapping file travels inside the AAB, so Play deobfuscates native crash reports itself.
Sentry only ever sees WebView JS stacks, which R8 does not touch.

## Zero-tap sign-in restoration

Play requires an app with sign-in to restore the session when someone moves to a new Android
device. The support article names the Restore Credentials API as the primary route, but also
says an app "integrating with Block Store, on or before September 30, 2026, to restore a
user's sign-in state" is compliant. We took the Block Store route: Restore Credentials is
WebAuthn-shaped and would need challenge issuance and assertion verification that the API does
not have, whereas Block Store just needs somewhere to put a token we already hold.

How it fits together:

- `stores/auth.js` already holds a long-lived `persistent` token, sent as
  `Authorization: Iznik <json>`, and `bootSession()` already treats it alone as credentials.
  Nothing server-side changed.
- `setAuth()` hands that token to `saveSessionForRestore()`
  (`composables/useSessionRestore.js`), which writes a versioned envelope into Block Store.
  Not awaited: `setAuth` runs on every session refresh. It skips a write when Block Store
  already holds the same token.
- On boot, `authStore.adoptRestoredSession()` runs before we conclude there are no
  credentials, from `bootSession()` for Freegle and from `modtools/layouts/default.vue` for
  ModTools. On a new device it finds the transferred token and sets it with no JWT; the next
  `GET /session` mints one.
- `logout()` deletes the Block Store entry. Without that, a later device restore would sign a
  user back in after they deliberately signed out.

The native side is `android/app/src/main/java/org/freegle/blockstore/BlockStorePlugin.java`,
registered in `MainActivity.onCreate` before `super.onCreate` (a plugin declared in this
project rather than pulled from node_modules is not in `capacitor.plugins.json`, so it is not
auto-registered, and `includePlugins` in the capacitor configs does not need an entry). It
lives in its own package so the ModTools package rewrite cannot break the import.

Cloud backup of the entry is enabled only when Block Store reports end-to-end encryption
available, which means the device has a screen lock. Without it the token would sit on
Google's servers merely encrypted at rest, and this token is a credential; direct
device-to-device transfer still carries it either way.

Android only. iOS carries a session across a device restore in the encrypted keychain backup,
and the Play rule is an Android one, so `sessionRestoreSupported()` is false everywhere else
and every call is a no-op on web and iOS.

## Memory and bitmaps

Thresholds are 90th-percentile anonymous RSS plus swap, per app state and per device RAM tier
(2GB foreground and 1GB background on a 4GB device, rising to 4.25GB and 2GB on 16GB), plus
bitmap caps that apply only outside the foreground: 200MB background, 400MB cached.

Nothing has been changed for this yet, deliberately: the right first step is reading the new
Android Vitals memory panels and the OOM crash filter for `org.ilovefreegle.direct` and
`org.ilovefreegle.modtools`, which are collecting data now. Two things make it worth actually
looking rather than assuming a WebView app passes: the manifest sets
`android:largeHeap="true"`, which raises the ceiling rather than the usage but says the app has
pushed against heap limits before; and Capacitor keeps the WebView and its rendered image
buffers alive when the app is backgrounded, which is exactly what the background and cached
bitmap thresholds target. Freegle's photo-heavy browse feed is the plausible offender.
