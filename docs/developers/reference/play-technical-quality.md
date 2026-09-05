---
last_reviewed: 2026-08-28
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
| Memory + bitmap thresholds per app state | February 2027 | Both | Measured 2026-08-27: ~10x inside the limits |
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
`proguard-rules.pro` adds the WebView share bridge, the social-login activity interface, the
push plugin's `Class.forName` on MainActivity, and the Block Store plugin. **Before a release
goes out, smoke-test on a device:** Google, Facebook and Apple sign-in; push and the
home-screen badge; camera and share-into-app; the Stripe donation flow.

### What the minified build was measured to keep

Measured 2026-08-28 on release (R8) builds of both identities, from the R8 outputs in
`android/app/build/outputs/mapping/release/` and from running the APKs on an emulator:

| Kept | Freegle | ModTools |
|---|---|---|
| `org.freegle.blockstore.BlockStorePlugin` class name | unrenamed | unrenamed |
| its `setSession` / `getSession` / `clearSession` | unrenamed | unrenamed |
| 7 `ee.forgr.capacitor.social.login.*` classes | unrenamed | unrenamed |
| `MainActivity` and `IHaveModifiedTheMainActivityForTheUseWithSocialLoginPlugin()` | kept | kept |
| `ShareIntentBridge.consume()` | unrenamed (class renamed) | unrenamed (class renamed) |

At runtime, in the minified build, a full Block Store round trip (`setSession` -> `getSession`
-> `clearSession` -> `getSession`) and `SocialLogin.initialize` both succeed, and the emulator
reaches `com.google.android.gms.auth.blockstore.service.START`. Two things this measurement
settles:

- Capacitor's own consumer rule (`-keep public class * extends com.getcapacitor.Plugin { *; }`)
  already covered the plugin before we named it: a build with the explicit rules removed keeps
  the same names. The rules in `proguard-rules.pro` are insurance against a consumer-rule
  regression, not a fix, and R8 was never the cause of a lost session.
- The `-keep class org.freegle.blockstore.**` rule survives the ModTools package rewrite,
  which only ever touches `org.ilovefreegle.*`.

Reproducing it needs no Nuxt build: put any `index.html` in the configured `webDir`, `npx cap
sync android`, then `./gradlew :app:assembleRelease` with the four `ANDROID_KEYSTORE_*` gradle
properties pointed at `~/.android/debug.keystore`. A page calling
`window.Capacitor.nativePromise('BlockStore', 'getSession', {})` exercises the minified native
code through the real bridge. Note that a debug-signed build makes Play Services refuse the
end-to-end-encryption check (`GoogleCertificatesRslt: not allowed`), so `cloudBackup` comes
back false; the plugin's failure path handles that and stores locally, which is the same thing
that happens on a device with no screen lock.

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
- So does an **implicit** logout, via `authStore.wipeAuth()`: a 401 on the authoritative
  `/session` check, or `fetchUser` finding auth genuinely invalid. Only explicit logout used to
  clear it, which left a dead token in Block Store that boot would re-adopt.

That last one matters because the two stores have different lifetimes. Measured on an emulator
(2026-08-28): deleting the WebView's `app_webview/Default/Local Storage` leaves the Block Store
entry intact, and the next cold start reads it straight back. A dead token there therefore
outlives the localStorage wipe that was supposed to remove it, and the app re-adopts it, gets
401, wipes localStorage again, and returns to the login screen - which is what Discourse
#10072 reported. (A full app-data clear is different: Play Services drops the Block Store entry
too, on `PACKAGE_DATA_CLEARED`.)

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

**Read from Play Console on 2026-08-27, 28-day window. Nothing needs doing.** Freegle sits an
order of magnitude inside every threshold:

| App state | Memory P50 | Memory P90 | Bitmap P90 | Tightest threshold |
|---|---|---|---|---|
| Foreground | 125MB | 165MB | - | 2GB (4GB device) |
| Cached | 101MB | 142MB | 2MB | 1GB memory, 400MB bitmap |
| Background | no data | no data | no data | 1GB memory, 200MB bitmap |

By RAM tier the foreground P90 is 153MB on 4GB devices, 184MB on 8GB and 150MB on 12GB, against
thresholds of 2GB, 2.25GB and 3.25GB. Bitmap P90 is 27MB overall and falling (94MB lower than 28
days earlier). Only Android 16 devices report these metrics, so the sample is a few hundred
sessions rather than the whole install base.

Two things that write-up needed and now has answers for:

- **The WebView renderer process is counted as ours.** The per-process breakdown lists
  `com.google.android.webview:sandboxed_process` alongside `org.ilovefreegle.direct`, and in the
  cached state the renderer is the larger of the two (P90 149MB vs 136MB). So a Capacitor app
  cannot assume its WebView memory sits outside Play's accounting. It is still tiny in absolute
  terms, and `android:largeHeap="true"` is not causing a problem.
- **The OOM crash filter has not reached this account.** The Crashes and ANRs type filter offers
  only user-perceived crashes and ANRs, all crashes, all ANRs and all non-fatal. The nearest
  existing signal, user-perceived LMK rate, reports no data. Re-check when Google finishes
  rolling it out.

ModTools reports no vitals data at all - crash rate, ANR rate, memory and bitmap are all blank,
which its 89 installs explain. The DEX requirement still applies to it, because that is measured
on the uploaded bundle rather than on field data.

## What Play says about our bundles today

The App bundle explorer confirms the starting point for the DEX work, on the newest bundle of
each app (Freegle 2349 / 100.0.983, ModTools 1303 / 1.0.29):

> App optimization: **Low**. Optimization percentage `-`, Obfuscation percentage 2%, Shrinking
> percentage `-`, R8 configuration `-`, Total uncompressed DEX size Unknown.

Identical for both, which is what `minifyEnabled false` looks like from Play's side. The panel
also suggests AGP 9.0 for best results; we are on 8.13, and the thresholds do not require it.
This is the page to check after the first minified release ships.

## Also outstanding: target API level, by 31 August 2026

Not one of the three thresholds, but found while reading the console on 2026-08-27 and far more
urgent. Both apps carry the policy warning "App must target Android 16 (API level 36) or higher",
with "App updates with these issues will be rejected" from 31 August. Production is fine on both
(target SDK 36). The blocker is a stale artifact still Active on a testing track:

- **Freegle**: internal testing serves 3.2.30 (bundle 1301, uploaded 21 October 2025), target SDK 35.
- **ModTools**: open testing serves 0.4.7 (507.apk, uploaded 17 August 2025), target SDK 35.

Play takes the highest non-compliant target across active tracks, which is why an old test-track
release blocks an app whose production build already targets 36.

**Both were superseded on 2026-08-27.** Freegle internal testing now serves 2350 (100.0.984,
target 36), live immediately. ModTools open testing has 1303 (1.0.29, target 36) submitted and in
Google review, so 0.4.7 keeps serving until that clears; if it has not cleared by 30 August,
pausing the open testing track removes the stale artifact without needing a review. Note that
moving that track from 0.4.7 to 1.0.29 drops 1,405 device models, because minSdk went 24 to 26 -
production dropped them long ago, so those devices were already frozen.
