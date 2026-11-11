# app-e2e: the Freegle app on an Android emulator

Runs the real Android app, built from the checkout, on a headless emulator against the
local Docker stack, and drives it with the Playwright `app` project
(`iznik-nuxt3/playwright.config.js`). It is the gate in front of the promote workflow:
`freegle/app-emulator-tests` runs in `manual-promote-submit` before the Freegle app moves
to a live Play or App Store track, and on demand with the `run_app_emulator_test` pipeline
parameter. It does not run on every push. The ModTools submission in the same workflow
does not wait for it: the tests do not exercise ModTools.

What it exercises that the browser suite cannot:

| Spec (`iznik-nuxt3/tests/e2e/app/`) | Checks |
|---|---|
| `plugins.spec.js` | every plugin in `capacitor.config.ts` `includePlugins` is registered natively, every plugin `cap sync` bundled survived R8, screen orientation and device info come from the native plugins, the bundle mounts |
| `notifications.spec.js` | an FCM data message (the payload `PushNotificationService` sends) reaches the WebView in the foreground and the shade; one that reaches the backgrounded app has it on `/chats` when the tap brings it back; a message without `channel_id` is ignored |
| `deeplink.spec.js` | an `ilovefreegle.org` link opened from outside lands on that page in the app |
| `photos.spec.js` | a picture taken in the system camera, and a photo chosen in the system photo picker, reach the give flow and upload to tusd; Android offers the app as a share target for images; an image shared from another app opens the give flow with it and uploads, both with the app in the background and with it not running |
| `test-post-flow.spec.js`, `test-reply-flow-logged-in.spec.js` | the web specs, unchanged, on the phone-sized WebView (`APP_SHARED_SPECS`) |

## What a run does

1. **Build** (`build-apk.sh`): `npm run generate` with the release job's exports (the orb's
   `build-android` step) and the stack's URLs in place of the live ones, then
   `FREEGLE_APP_E2E=true npx cap sync android` and `gradlew assembleE2e`. The `e2e` build
   type (`android/app/build.gradle`) is the release configuration, R8 included, signed with
   the debug key; `src/e2e/AndroidManifest.xml` permits cleartext for the stack's http
   API, and the Capacitor switch turns on WebView remote debugging and mixed content.
   `GOOGLE_SERVICES_JSON_BASE64` (the CI secret) becomes `google-services.json`; without
   it a placeholder is used so Firebase initialises (push *registration* then fails with
   the plugin's `registrationError`; push *delivery* is tested without Firebase). The
   stack URLs can be overridden only as `APP_E2E_API_V2`, `APP_E2E_TUS_UPLOADER`,
   `APP_E2E_IMAGE_DELIVERY` and `APP_E2E_USER_SITE`. The build reports to no Sentry
   project and uploads no source maps. Afterwards the placeholder is removed and the
   native config rewritten without the e2e WebView settings, so a later build that skips
   `cap sync` does not ship them.
2. **Boot** (`start-emulator.sh`): a cold headless boot of the `freegle-app-e2e` AVD
   (Pixel 6, API 34, `google_apis`, not the Play Store image, because the run needs
   `adb root`). Animations off, screen kept on, adbd restarted as root.
3. **Launch** (`launch-app.sh`): `adb reverse` from the device's traefik port to the host's,
   so the URLs baked into the bundle (`http://apiv2.localhost:<port>/api`) reach the stack
   (Chromium resolves `*.localhost` to loopback itself); a fresh install with runtime
   permissions granted; `am start`; wait for the WebView devtools socket.
4. **Test**: `attach-check.cjs` first attaches to the WebView exactly as the fixtures do
   and fails with the reason if it cannot (Playwright reports a worker fixture failure only
   at the end of a run). Then `npx playwright test --project app --workers 1`, with the
   line reporter (failures printed as they happen) and a global timeout
   (`APP_E2E_GLOBAL_TIMEOUT_MIN`, default 25). The fixtures attach to the app's WebView
   through Playwright's Android support (`_android`), reset web storage and reload from the
   app's origin before each test, and expose the device through `tests/e2e/app/device.js`
   (adb shell, uiautomator, the notification shade, fake FCM, deep links, the camera, the
   photo picker, share intents).
5. **Evidence** in `~/.local/share/freegle-app-e2e/out/run-<timestamp>/` (`latest` points at
   it): Playwright's report and junit, screenshots and failure evidence, `logcat.txt`,
   `emulator.log`, `final-screen.png`. CI stores it under the `app-emulator` artifact.
6. **Stop** what the run started. `--keep` leaves the emulator up.

## Running it locally

```bash
scripts/app-e2e/doctor.sh                 # what this host is missing, with the fix for each
scripts/app-e2e/setup-sdk.sh              # ~2 GB into ~/.local/share/freegle-android-sdk (JDK 17+ needed)
scripts/app-e2e/create-avd.sh
scripts/app-e2e/run.sh                    # build, boot, install, test, stop
scripts/app-e2e/run.sh --no-build --keep -- tests/e2e/app/plugins.spec.js
```

The stack must be up (`apiv2` and the status API answer). The scripts read
`PORT_TRAEFIK_HTTP` and `PORT_STATUS` from the environment, then from the checkout's
`.env`, then default to 80 and 8081. A machine with an Android Studio SDK can point
`ANDROID_SDK_ROOT` at it; the build also needs the compile platform and build-tools,
which Gradle downloads once the licences are accepted by `setup-sdk.sh`.

`/dev/kvm` is required. Under WSL2 that means `nestedVirtualization=true` in
`.wslconfig`. Hetzner Cloud VMs (the ephemeral CI runners) never expose it, which is why
the CI job runs on CircleCI's Android machine image instead.

## In CI

Two orb jobs, in the promote workflow and the on-demand one:

| Job | Executor | Does |
|---|---|---|
| `freegle/app-emulator-apk` | Docker Android (`cimg/android`, large) | `build-apk.sh` against the CI stack's port 9080; hands the APK on through the workspace |
| `freegle/app-emulator-tests` | Android machine image, large (4 vCPU, 15 GB; `xlarge` is not in the plan), four in parallel | each brings up the stack, boots the emulator, runs `run.sh --no-build -- --shard=N/4` |

A CircleCI cloud job is stopped after one hour, which shapes the emulator job:

- The APK is built elsewhere: on the same four cores it doubled both builds.
- The stack is build-and-test's minus the services the app never uses, swapped for idle
  placeholders by `docker-compose.app-e2e.yml` (ModTools, both dev servers, the Playwright
  image, the AI support helper). It still takes about 30 minutes, uncached, before the
  first test starts. Built in full it took 31 minutes on its own.
- The emulator renders in software on the cores the stack is using, so a test takes 5 to
  10 times as long as on a workstation: deep links 28 seconds against 3, the foreground
  push 91 against 8. All 24 tests did not fit one machine's remaining half hour.
- So the job runs on four machines (`shards`, default 4), each its own Playwright shard.
  Playwright keeps a spec file in one shard and balances by test count, which today gives
  deep links, push and photos (10 tests); plugins (6); the post flow (5); the reply flow
  (3). Each shard's global timeout is what the hour leaves once its stack is up (56
  minutes from the job's start, less 4 for the emulator), so a slow stack start shortens
  the run and its reports are still written before CircleCI ends the job.
- Each machine samples its load once a minute into `load.txt`, the data for retuning the
  shards or the stack.

Artifacts: `freegle-e2e.apk` on the APK job; `app-emulator` (the run directory and
`load.txt`) on each emulator machine.

## Traps

- **Replies need `chats:process-incoming`, and nothing runs it in CI.** A reply stays
  hidden in /chats until that command marks it processed. The batch scheduler is off in CI
  and the web suite relies on the status API running it every five seconds; `run.sh` calls
  Playwright directly, so it runs the same commands in the batch container itself
  (`batch-ticker.log` in the run directory). Without it the reply specs pass on a
  workstation, whose scheduler is on, and hang in CI.
- **`chromium.connectOverCDP` through `adb forward` drops the connection** as soon as
  Playwright attaches to the WebView's browser target. `playwright._android` (device,
  `webView()`, `page()`) is the path that works, and it needs no forward.
- **Select the WebView by `socketName`, never by `pkg`.** Playwright names a WebView's
  package from the last line of `ps -A | grep <pid>`, so any process whose figures happen
  to contain the app's pid can take the name, and `webView({ pkg })` then waits out its
  timeout beside a WebView that is there. The socket is `webview_devtools_remote_<pid>`
  with the pid from `pidof`.
- **A just-booted emulator's Play services can take the app down.** On CI, about 30
  seconds after launch, Android killed the app because "it depends on provider
  `com.google.android.gms/.fonts.provider.FontsProvider` in dying proc", then restarted it
  for the push plugin's boot broadcast: a process with no activity, so no WebView. Attach
  (fixtures and `attach-check.cjs`) therefore runs `am start` for the activity and waits
  for its devtools socket before attaching.
- **The emulator console port must be in adb's scan range** (5554-5584). On a loaded
  machine the adb server can drop the emulator; inside the range it finds it again in
  seconds, outside it the device is gone for the rest of the run.
- **The WebView has no cookie management over CDP** (`Browser context management is not
  supported`). Storage is cleared in the page and the app reloaded; `clearSessionData`
  tolerates the error.
- **A Play Store image refuses `adb root`**, which the run needs twice: to bind device
  port 80 for the reverse tunnel, and to deliver the FCM broadcast (its receiver is
  guarded by a Google-only permission). Use `google_apis`. On a busy runner `adb root`
  can also fail just after boot on the right image, so
  `start-emulator.sh` retries it and only blames the image when adbd says "production".
- **Without any `google-services.json` the app dies on launch**: the push plugin's
  `register()` throws `Default FirebaseApp is not initialized` on a plugin thread. Hence
  the placeholder.
- **An `adb logcat` left running holds the emulator lock**: `run.sh` starts adb itself
  (not a subshell) with the lock descriptor closed, and kills it on exit.
- **Quoting through `adb shell`**: values with spaces must be quoted for the device shell,
  not only the local one, or the extras after the first space are lost. `device.js` does
  this; a hand-typed `am broadcast` needs the whole command in one quoted string.
- **A share sent with `am start` needs two things a gallery app gets for free.** The read
  grant has to come from a user that can read the image (`su shell`; root's grant does not
  take), and the URI has to be in the intent data as well as `EXTRA_STREAM`, because `am`
  does not copy the stream into the clip data the grant covers. Without either, the app logs
  a `SecurityException` and the give flow never opens.
- **A root `adb push` is invisible to the gallery** until the media scanner is told about
  the file (`MEDIA_SCANNER_SCAN_FILE`); `addImageToGallery` also touches it, so the photo
  picker lists it first.
- **An uploaded photo's thumbnail is a 404 on a stack with offset ports.** The client asks
  the delivery service to fetch `tusd.localhost:<host port>`, which the delivery container
  cannot reach, so the tests stop at the tusd upload instead of the rendered thumbnail.
  The web suite has the same limitation and allows the 404.
- **The release build's variable names are already set in CI.** CircleCI sets
  `TUS_UPLOADER` to the public tus demo server, and an e2e build that let the environment
  win uploaded its test photos there: every photo test failed on `net::ERR_FAILED`, and
  their retries ate the run. `lib.sh` reads overrides only under `APP_E2E_*` names.
- **`config.js` falls back to the live web Sentry DSN when `SENTRY_DSN` is empty**, so
  unsetting it sends the e2e build's errors to the web project, where the monitor would
  take them as work. `NUXT_PUBLIC_SENTRY_DSN=""` empties the runtime value, which
  `plugins/sentry.client.ts` reads as disabled; `build-apk.sh` fails if a DSN survives.
- **The camera drops a shutter tap that comes before it is ready.** The shutter is on
  screen while the camera is still opening; on a CI emulator the one tap landed then and
  the camera sat on its preview until the test gave up. `takePictureWithSystemCamera`
  taps again each round until the review screen's done button appears.
- **An "isn't responding" dialog covers every screen until someone answers it.** On a
  loaded CI emulator the app or System UI stalls past Android's five seconds, and the
  dialog then stays up after the app has recovered: in job 39544 it sat over the camera
  and the photo picker for seven minutes, so every `uiautomator dump` was of the dialog
  and both photo tests timed out. `uiNodes` taps Wait (`android:id/aerr_wait`) and logs
  `[device] answered Wait to ...`. Hiding the dialogs (`hide_error_dialogs`) is not the
  fix: Android then kills the stalled app instead.
- **Opening the shade right after Home can be lost** while the launcher animates in.
  `tapNotification` asks again each round until the notification is on screen.
- **The release build has no Capacitor logging**: R8 builds drop it, so logcat says nothing
  about a plugin call. Read the WebView console, or the stores (`compose.messages` holds
  each photo's native preview path and its tusd id).
