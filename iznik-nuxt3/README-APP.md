# Freegle Direct Mobile App

This document describes the mobile app version of Freegle, which is built using Capacitor to create native Android and iOS apps from the same Nuxt3 codebase.

---

## ✅ iOS and Android Automated

**Both iOS and Android releases are fully automated via CircleCI.** The CI/CD pipeline builds, version manages, and deploys both platforms in parallel using a shared version number to ensure consistency.

---

## Overview

The mobile app is built from the `production` branch (same as the web app) and includes native Android and iOS platform code. The app shares all Vue components and business logic with the web version but uses a different build configuration controlled by the `ISAPP` environment variable.

Understanding the differences between mobile and web builds helps when debugging platform-specific issues.

<details>
<summary><h2>Mobile App vs Web App</h2></summary>

The mobile and web apps are built from the **same codebase** (`production` branch) with build-time differences:

### Build Configuration Differences

- **SSR Disabled**: Mobile uses Static Site Generation instead of Server-Side Rendering
- **Build Target**: `static` (mobile) instead of `server` (web)
- **Environment Flag**: `ISAPP=true` to detect mobile app context
- **Build Pipeline**: CircleCI (mobile) vs Netlify (web)
- **Deploy Trigger**: Both deploy from `production` branch after tests pass on `master`

### Unified Codebase Benefits

- **Single Source of Truth**: All code in one place, no branch divergence
- **Automatic Sync**: Fixes automatically apply to both platforms
- **Consistent Testing**: Same tests validate both web and mobile code
- **Simplified Maintenance**: No need to manually sync branches

</details>

---

The app uses Capacitor to bridge web code with native device features. This section covers the core configuration and project structure.

<details>
<summary><h2>Core Mobile Infrastructure</h2></summary>

### Capacitor Framework

The app uses Capacitor 8 to bridge web code with native functionality:

- **App ID**: `org.ilovefreegle.direct`
- **App Name**: Freegle
- **Config File**: `capacitor.config.ts`
- **Web Directory**: `.output/public` (from Nuxt static build)
- **Minimum OS**: Android 8.0 (minSdk 26, `android/variables.gradle`); iOS 15.0
  (`ios/App/Podfile` + pbxproj) — the iOS floor moved from 14.0 with Capacitor 8,
  which requires iOS 15; existing installs on iOS 14 keep the last compatible
  build but stop receiving updates

### Android Native Files

Located in `android/` directory:

- Complete Android Studio project structure
- Gradle build configuration (`android/app/build.gradle`)
- App icons, splash screens, and resources
- Android manifest with permissions (Camera, Storage, etc.)
- **Version Management**:
  - `versionCode`: Integer build number (e.g., 1202)
  - `versionName`: User-facing version string (e.g., "3.2.0")

### iOS Native Files

Located in `ios/` directory:

- Complete Xcode project structure
- Podfile for CocoaPods dependencies
- iOS-specific icons and assets
- GoogleService-Info.plist for Firebase integration
- **Version Management**:
  - `CURRENT_PROJECT_VERSION`: Build number (e.g., 1200)
  - `MARKETING_VERSION`: User-facing version (e.g., "3.1.9")

</details>

---

These features use native device capabilities not available in web browsers.

<details>
<summary><h2>Mobile-Specific Features</h2></summary>

### Authentication Methods

The mobile app supports multiple authentication methods with native implementations:

1. **Google Sign-In and Facebook Login**
   - Package: `@capgo/capacitor-social-login`
   - Platform-specific client IDs for Android and iOS
   - Native sign-in UI; supports Facebook "Limited Login" on iOS

2. **Apple Sign In**
   - Package: `@capacitor-community/apple-sign-in` (iOS only)
   - Native Sign in with Apple integration
   - Identity token handling

### Push Notifications

Custom implementation using Freegle's fork:

- **Package**: `@freegle/capacitor-push-notifications-cap8` (cap8 branch of the capacitor-push-notifications-cap7 repo, based on upstream 8.1.2)
- **Features**:
  - Foreground and background push handling
  - Badge count management on home screen icon
  - Deep linking from notifications
  - Multiple notification channels (Android)
  - Sound and vibration control
  - Notification permissions handling

#### Notification types

- **Chat messages** (`CHAT_MESSAGE`, `chat_messages` channel) — reply/mark-read actions.
- **Daily new posts** (`NEW_POSTS`, `new_posts` channel) — one push per day summarising new
  OFFER/WANTED posts near the user (the push analogue of the daily email digest). Rendered
  richly: Android `InboxStyle` (a few items + "+N more") for several posts or `BigPictureStyle`
  (item photo) for a single post; iOS uses a Notification Service Extension to attach the photo.
  Sent **data-only** on Android so the native builder renders it. Opt-out via
  `settings.notifications.dailypostspush` (app-only toggle in Settings). Tapping opens `/browse`.
  Server side is `push:daily-posts` in iznik-batch, gated by `FREEGLE_POSTS_PUSH_ALLOWLIST`.

#### Android backup and FCM tokens

Android auto-backup must NOT restore Firebase's installation prefs: a restored
Firebase Installation ID makes the FCM SDK return the PREVIOUS install's token,
which FCM invalidated at uninstall — so a reinstalled app re-registers a dead
token, every push to it is rejected (`NotRegistered`), and the app believes
registration succeeded. `android/app/src/main/res/xml/backup_rules.xml`
(Android ≤11) and `data_extraction_rules.xml` (12+, cloud restore AND
device-to-device transfer) exclude `com.google.android.gms.appid.xml` and
`com.google.firebase.messaging.xml` from backup for this reason; keep the
exclusions if the backup config is ever reworked. The server purges tokens FCM
reports as dead (`PushNotificationService::isDeadTokenError` in iznik-batch),
but that cannot help a device that keeps re-registering a restored dead token —
the affected user's remedy is clearing the app's storage/data so a fresh token
is minted.

### Camera & Photo Management

- **Native camera access** for taking photos
- **Photo picker** for selecting from gallery (single/multiple)
- **Photo size optimization**: Reduces to 800x800 to manage app size
- **Permissions**: Automatic camera permission requests
- Special handling for Android/iOS differences

### Payment Integration (Stripe)

Mobile-specific Stripe implementation:

- **Google Pay** (Android - live)
- **Apple Pay** (iOS)
- Native payment sheets
- Donation flows optimized for mobile
- Test mode support for development

### Device Features

1. **Deep Linking**
   - Custom URL scheme support
   - Handle app links from external sources
   - One-click unsubscribe links
   - Push notification routing

2. **Native Share**
   - Share posts using native share sheet
   - Platform-specific share UI

3. **Share Into the App (photo → give flow)**
   - Android: `ACTION_SEND`/`ACTION_SEND_MULTIPLE` handled by `MainActivity`,
     which copies images to cache and exposes them via the
     `window.FreegleShare` bridge; iOS mirrors this with a
     `freegleshare://` deep link
   - `stores/mobile.js` consumes pending shares as soon as the App plugin
     import resolves (early in `initApp()`), and routes to
     `/give/mobile/photos`
   - The photos page attaches each shared image through the same
     `PhotoUploader.processPhoto()` path as manual picks; Next is disabled
     while any attachment is still uploading
   - The photo-quality image loaders are bounded (8s timeout) so a
     `capacitor://` file URL that never fires load/error cannot hang the
     upload

4. **Calendar Integration**
   - Add events to native calendar
   - Permission handling (iOS requires multiple permission types)
   - Uses Cordova Calendar plugin

5. **Pinch Zoom**
   - Enabled for Android
   - Native zoom gestures
   - Transient magnifier: scales the whole WebView viewport (navbars included)
     while zoomed; distinct from the permanent text-size preference below

5. **System Text Size (accessibility)**
   - `@capacitor/text-zoom`: on startup (and again on resume) the app reads
     the OS-preferred text zoom - iOS Dynamic Type / Android font scale - and
     applies it to the WebView (`stores/mobile.js` `initTextZoom()`)
   - Without this, WKWebView ignores iOS Dynamic Type entirely, so the app
     rendered at a fixed text size whatever the member set in Settings →
     Accessibility
   - Text grows with reflow, and the navbars are unaffected

6. **Device Information**
   - Collect device details for debugging
   - Persistent device ID
   - OS version tracking
   - Send to Sentry for error context

7. **App Updates**
   - Check for required updates
   - Check for available updates
   - Version comparison logic
   - Update prompts

8. **Rate App**
   - Native rating prompts
   - Timing logic to avoid annoying users
   - Platform-specific app store links

8. **Hardware Back Button (Android)**
   - `initBackButton()` in `stores/mobile.js` listens for Capacitor's
     `backButton` event (fired for both the back button and the back
     gesture)
   - Navigates back through webview history while there is any, then
     backgrounds the app with `App.minimizeApp()` at the root — the
     standard Android behaviour
   - Without this listener Capacitor swallows back presses once history
     is empty and the app cannot be exited

</details>

---

All mobile-specific state and functionality is managed through a dedicated Pinia store.

<details>
<summary><h2>Mobile Store (stores/mobile.js)</h2></summary>

A dedicated Pinia store handles all mobile-specific state and functionality:

### State

- `isApp`: Boolean flag for mobile app context
- `mobileVersion`: Current app version string
- `deviceinfo`: Device information object
- `devicePersistentId`: Unique device identifier
- `isiOS`: Platform detection
- `osVersion`: Operating system version
- `lastBadgeCount`: Last set notification badge count
- `appupdaterequired`: Flag for mandatory updates
- `appupdateavailable`: Flag for optional updates

### Actions

- `init()`: Initialize mobile app features
- `initApp()`: Set up device info, deep links, push notifications
- `getDeviceInfo()`: Collect device information
- `fixWindowOpen()`: Handle iOS window.open behavior
- `initDeepLinks()`: Set up deep link handling
- `initPushNotifications()`: Configure push notification system
- `checkForAppUpdate()`: Check for app updates
- `initWakeUpActions()`: Handle app resume/wake events
- `initBackButton()`: Android back button/gesture — history back, minimize at root

</details>

---

Several components have mobile-specific behavior to optimize for touch screens and native capabilities.

<details>
<summary><h2>UI/UX Adjustments</h2></summary>

### Modified Components

Several components have mobile-specific behavior:

1. **ExternalLink.vue**
   - Opens links in in-app browser instead of external browser
   - Uses Cordova InAppBrowser plugin

2. **AddToCalendar.vue**
   - Uses native calendar plugin
   - Handles iOS calendar permissions

3. **DraggableMap.vue**
   - Adjusted for mobile touch interactions

4. **EmailValidator.vue**
   - Mobile-optimized validation flow

5. **Chat Components**
   - Optimized for mobile screens
   - Native sharing integration
   - The chat box and ChitChat comment box use
     `autocapitalize="sentences"` except on iOS (app and Safari), where
     auto-capitalise engages the virtual Shift key so Return arrives as
     shift+enter and breaks send-on-enter (`composables/useIsIOS.js`;
     angular/angular#32963 — iOS keyboard design, not a fixed bug)

### Ads & Analytics

- **CookieYes**: App-specific version (`cookieyesapp.js`)
- **Google AdSense**: Modified for HTTPS enforcement
- **Sentry**: Error reporting with device context
- **Ad Behavior**: Some ads disabled or modified for mobile

### Status Bar

- Android and iOS status bar handling
- Light/dark theme support
- Overlay configuration

</details>

---

The mobile app requires specific Capacitor plugins and dependencies for native functionality.

<details>
<summary><h2>Dependencies</h2></summary>

### Capacitor Core Packages

```json
{
  "@capacitor/core": "^8.x",
  "@capacitor/cli": "^8.x",
  "@capacitor/android": "^8.x",
  "@capacitor/ios": "^8.x"
}
```

### Capacitor Plugins

```json
{
  "@capacitor/app": "Native app lifecycle",
  "@capacitor/app-launcher": "Launch other apps",
  "@capacitor/camera": "Camera and photo picker",
  "@capacitor/device": "Device information",
  "@capacitor/share": "Native share sheet",
  "@capawesome/capacitor-badge": "App icon badge management"
}
```

### Custom Freegle Plugins

```json
{
  "@freegle/capacitor-push-notifications-cap7": "Push notifications"
}
```

### Social Login

```json
{
  "@codetrix-studio/capacitor-google-auth": "Google Sign-In"
}
```

### Cordova Plugins

```json
{
  "cordova-plugin-inappbrowser": "In-app browser for OAuth",
  "cordova-plugin-calendar": "Calendar integration"
}
```

**Add to Calendar** (`components/AddToCalendar.vue`): uses `window.plugins.calendar`
(cordova-plugin-calendar) to add a handover to the device calendar. If the plugin is not
exposed in the WebView, or the native call errors, it falls back to downloading a `.ics`
file (built by `composables/useCalendarEvent.js`) so the button is never a silent no-op — the
symptom reported in Discourse 9927. On the web it always uses the `.ics`.

</details>

---

Version numbers are managed automatically by CircleCI to ensure consistency across platforms.

<details>
<summary><h2>Version Management</h2></summary>

### Unified Version Management (Both Platforms)

**Shared Version Strategy**: Both iOS and Android use the **same version number** to ensure consistency across platforms. A single `increment-version` job runs before both builds, calculates the new version, and shares it via CircleCI workspace.

**Version Name** (e.g., "3.2.38"): Auto-incremented once, shared between platforms
- Stored in CircleCI environment variable `CURRENT_VERSION`
- CircleCI reads current version, increments patch version (3.2.37 → 3.2.38)
- Creates `.new_version` file in workspace with new version
- Both Android and iOS jobs read from this shared file
- **No race conditions** - version calculated once before parallel builds
- Build will FAIL if `CURRENT_VERSION` is not set or has invalid format (must be X.Y.Z)
- **No manual intervention needed** - fully automated

**Version Code/Build Number**: Platform-specific, auto-incremented from stores
- **Android**: Queries Google Play Console (all tracks) for latest version code
- **iOS**: Queries TestFlight for latest build number
- Automatically increments by 1 for each build
- **Minimum version code: 1272** (jumps from lower values if needed, then increments normally)
- Build will FAIL if store APIs are unavailable

### Android Version Management

**Version Code** (e.g., 1272): Auto-incremented from Google Play
- Queries Google Play Console across ALL tracks (internal, beta, production)
- Finds maximum version code across all tracks
- Automatically increments by 1 for each build (1272 → 1273)
- Minimum enforced: if calculated code < 1272, jumps to 1272
- Build will FAIL if Google Play API is unavailable or no releases exist
- **No manual intervention needed** - fully automated

**How Android build works**:
1. CircleCI `increment-version` job reads `CURRENT_VERSION` (e.g., "3.2.37")
2. Auto-increments patch version (3.2.37 → 3.2.38)
3. Saves to `.new_version` in workspace
4. Android job attaches workspace and reads `.new_version`
5. **Updates `config.js` MOBILE_VERSION** with new version (ensures Help page shows correct version)
6. Builds Nuxt app with updated version
7. Queries Google Play for latest version code (e.g., 1271)
8. Auto-increments version code (1271 → 1272, or enforces minimum 1272)
9. Builds AAB and APK with new version (3.2.38 / 1272)
10. Uploads to Google Play Beta Testing (Open Testing)

### iOS Version Management

**Build Number** (e.g., 1272): Auto-incremented from TestFlight
- Queries TestFlight for latest build number for this version
- Automatically increments by 1 for each build (1272 → 1273)
- Minimum enforced: if calculated build < 1272, jumps to 1272
- If no builds exist for this version, starts at 1272
- **No manual intervention needed** - fully automated

**How iOS build works**:
1. CircleCI `increment-version` job creates shared `.new_version` (e.g., "3.2.38")
2. iOS job attaches workspace and reads `.new_version`
3. **Updates `config.js` MOBILE_VERSION** with new version (same as Android)
4. Builds Nuxt app with updated version
5. Queries TestFlight for latest build number for version 3.2.38
6. Auto-increments build number (or enforces minimum 1272)
7. Sets version in Xcode project: `MARKETING_VERSION = 3.2.38`, `CURRENT_PROJECT_VERSION = 1272`
8. Builds IPA with new version (3.2.38 / 1272)
9. Uploads to TestFlight
10. **Auto-submit to App Store review after 24 hours** (scheduled job)

### Config Version

The `MOBILE_VERSION` in `config.js` is **automatically updated** by BOTH CircleCI jobs before each build:

```javascript
MOBILE_VERSION: '3.2.38'  // Auto-updated by both jobs from shared workspace version
```

This ensures the version shown in the app's Help page matches the actual build version on both platforms. **No manual updates needed** - the version is synchronized automatically during the build process.

### Initial Setup (one-time)

1. Go to CircleCI Project Settings → Environment Variables
2. Add `CURRENT_VERSION` = `3.2.37` (starting version)

**To manually bump major/minor version**:
- Update `CURRENT_VERSION` in CircleCI: `3.2.37` → `4.0.0` or `3.3.0`
- Next build will auto-increment from there: `4.0.0` → `4.0.1`
- Both platforms will use the same version number

</details>

---

CircleCI builds require various environment variables for signing, store APIs, and service integrations.

<details>
<summary><h2>Environment Variables</h2></summary>

### Required for CircleCI Builds

#### Android-Specific

```bash
# Android Signing
ANDROID_KEYSTORE_BASE64=...          # Base64-encoded keystore file
ANDROID_KEYSTORE_PASSWORD=...        # Keystore password
ANDROID_KEY_ALIAS=...                # Key alias (e.g., "Freegle Ltd Chris")
ANDROID_KEY_PASSWORD=...             # Key password

# Google Play API
GOOGLE_PLAY_JSON_KEY=...             # Base64-encoded service account JSON

# Firebase Configuration (Android)
GOOGLE_SERVICES_JSON_BASE64=...      # Base64-encoded google-services.json
```

#### iOS-Specific

```bash
# App Store Connect API
APP_STORE_CONNECT_API_KEY_KEY_ID=... # API Key ID from App Store Connect
APP_STORE_CONNECT_API_KEY_ISSUER_ID=...  # Issuer ID from App Store Connect
APP_STORE_CONNECT_API_KEY_KEY=...    # Base64-encoded .p8 private key file

# iOS Code Signing
IOS_DISTRIBUTION_CERT=...            # Base64-encoded iOS distribution certificate (.p12)
IOS_CERTIFICATE_PASSWORD=...         # Password for the .p12 certificate
IOS_PROVISIONING_PROFILE=...         # Base64-encoded provisioning profile (.mobileprovision)

# Keychain Configuration
KEYCHAIN_PASSWORD=...                # Password for temporary keychain (e.g., "circleci")
KEYCHAIN_NAME=...                    # Name of temporary keychain (e.g., "temp.keychain-db")

# Firebase Configuration (iOS)
GOOGLE_SERVICE_INFO_PLIST_BASE64=... # Base64-encoded GoogleService-Info.plist
```

#### Shared Configuration

```bash
# Sentry Error Tracking
SENTRY_DSN_APP_FD=...                # Sentry DSN for app error tracking (optional)

# App Configuration
ISAPP=true                           # Enable mobile app mode
APP_ENV=production                   # Build environment

# Google
GOOGLE_CLIENT_ID=...                 # Android client ID
GOOGLE_IOS_CLIENT_ID=...            # iOS client ID

# Facebook
FACEBOOK_APPID=...
FACEBOOK_CLIENTID=...

# Stripe
STRIPE_PUBLISHABLE_KEY=...

# Other
USE_COOKIES=false                    # Cookie behavior for mobile
```

### Setting Up CircleCI Environment Variables

1. Go to CircleCI Project Settings: https://app.circleci.com/settings/project/github/Freegle/iznik-nuxt3
2. Click "Environment Variables"
3. Add the required variables listed above
4. For base64 encoding:

   **Android:**
   ```bash
   # Encode Android keystore
   base64 -w 0 your-keystore.jks > keystore_base64.txt

   # Encode Google Play JSON key
   base64 -w 0 google-play-api-key.json > play_key_base64.txt

   # Encode Firebase google-services.json (Android)
   base64 -w 0 android/app/google-services.json > google_services_base64.txt
   ```

   **iOS:**
   ```bash
   # Encode iOS distribution certificate
   base64 -w 0 ios_distribution.p12 > ios_cert_base64.txt

   # Encode iOS provisioning profile
   base64 -w 0 ios_appstore.mobileprovision > ios_profile_base64.txt

   # Encode App Store Connect API key (.p8 file)
   base64 -w 0 AuthKey_XXXXXXXXXX.p8 > appstore_key_base64.txt

   # Encode Firebase GoogleService-Info.plist (iOS)
   base64 -w 0 ios/App/App/GoogleService-Info.plist > google_service_info_base64.txt
   ```

**Notes:**
- **Firebase files** (`google-services.json` for Android, `GoogleService-Info.plist` for iOS) are required for Firebase/Push Notifications. Download from [Firebase Console](https://console.firebase.google.com/) → Project Settings → Your app → Download config file
- **App Store Connect API Key**: Create at [App Store Connect](https://appstoreconnect.apple.com/) → Users and Access → Keys → App Store Connect API
- **iOS Certificates**: Export from Xcode or Apple Developer portal as .p12 with a password
- **SENTRY_DSN_APP_FD** is optional but recommended for error tracking. Get from [Sentry](https://sentry.io/) → Project Settings → Client Keys (DSN)

### Verifying GOOGLE_PLAY_JSON_KEY

The `GOOGLE_PLAY_JSON_KEY` environment variable is **CRITICAL** for:
- Auto-incrementing version codes from Google Play Console
- Uploading builds to Google Play Internal Testing

**Status**: ✅ Properly configured and working (as of build #596)

**Build Behavior**:
- The build will **FAIL** if `GOOGLE_PLAY_JSON_KEY` is not set, empty, or invalid
- The build will **FAIL** if it cannot fetch version CODES from Google Play API
- The build will **FAIL** if `CURRENT_VERSION` is not set or has invalid format
- Version NAME is auto-incremented from `CURRENT_VERSION` env var (3.2.29 → 3.2.30)
- Version CODE is auto-incremented from Google Play API (1300 → 1301)
- `CURRENT_VERSION` is updated via CircleCI API after successful build

**Debug Output**: The decode step includes extensive validation:
- ✅ Environment variable is set
- ✅ File created successfully with valid size
- ✅ Valid JSON structure
- 📧 Service account email (for debugging)

**Verification**: Check recent CircleCI builds at https://app.circleci.com/pipelines/github/Freegle/iznik-nuxt3?branch=production
- Look for "✅ Google Play API key file validated" in decode step
- Look for "📱 Current version from CircleCI: X.Y.Z" in deploy step
- Look for "📱 Auto-incremented version name: X.Y.Z → X.Y.(Z+1)" in deploy step
- Look for "📊 Using Play Console internal version code: XXXX" in deploy step
- Look for "📊 New version code: XXXX" in deploy step
- Look for "✅ Successfully uploaded to Google Play Internal Testing!" at end
- Look for "✅ Updated CURRENT_VERSION to X.Y.(Z+1)" in update version step

</details>

---

Google Play's technical-quality thresholds land in 2027 and both apps are in scope.

<details>
<summary><h2>Google Play Technical Quality (R8, Zero-Tap Sign-In)</h2></summary>

Full write-up, including the measured numbers and the memory thresholds:
[`docs/developers/reference/play-technical-quality.md`](../docs/developers/reference/play-technical-quality.md).

### Release builds are minified (R8)

`android/app/build.gradle` sets `minifyEnabled true` on the release build type, with
`proguard-android-optimize.txt` (the plain `proguard-android.txt` carries `-dontoptimize`, which
would leave Play's optimization metric at zero). Play requires >=25% shrinking, optimization and
obfuscation from February 2027 for apps whose DEX exceeds 10MB: Freegle's was 40.4MB and
ModTools' 14.9MB, both at 0%.

Keep rules live in `android/app/proguard-rules.pro` and deliberately never name
`org.ilovefreegle.direct`, because the ModTools build rewrites that package everywhere except
that file.

**R8 removes or renames whatever only reflection or JS reaches, so smoke-test on a device
before a release goes out**: Google / Facebook / Apple sign-in, push and the home-screen badge,
camera and share-into-app, and the Stripe donation flow. Native crash reports stay readable
because the mapping file travels inside the AAB.

### Sign-in survives a new device (Block Store)

From April 2027 Play requires apps with sign-in to restore the session when someone moves to a
new Android device. An integration with Block Store shipped by 30 September 2026 counts as
compliant, and that is the route taken: the `persistent` token the app already holds goes into
Block Store, which Android carries to the new device.

- Native: `android/app/src/main/java/org/freegle/blockstore/BlockStorePlugin.java`, registered
  in `MainActivity.onCreate` before `super.onCreate`.
- Web layer: `composables/useSessionRestore.js`, called from `stores/auth.js` (`setAuth` saves,
  `logout` clears, `adoptRestoredSession` reads) and from both boot paths.
- Android only. iOS restores a session from the encrypted keychain backup already.

</details>

---

Production builds are fully automated via CircleCI. Local builds are useful for testing.

<details>
<summary><h2>Build Process</h2></summary>

### CircleCI Automated Builds (Both Platforms)

**Triggered on**: Pushes to `production` branch (after tests pass on `master`)

**Jobs Workflow**:

1. **increment-version** (runs first):
   - Reads `CURRENT_VERSION` from environment
   - Increments patch version (3.2.37 → 3.2.38)
   - Saves to `.new_version` in workspace

2. **build-android** and **build-ios** (run in parallel):
   - Both require `increment-version` to complete first
   - Both attach workspace to read shared `.new_version`

**Android Build Steps**:
1. Install Node.js 22 dependencies
2. Read version from workspace `.new_version` file
3. Update `config.js` MOBILE_VERSION with new version
4. Build Nuxt app with `npm run generate` (static site)
5. Decode and place Firebase `google-services.json`
6. Sync Capacitor to Android project
7. Query Google Play Console for latest version code (across all tracks)
8. Build signed AAB with auto-incremented version code (minimum 1272)
9. Build signed APK for direct installation
10. Upload AAB to Google Play Beta (Open Testing) track
11. Store AAB and APK as CircleCI artifacts

**iOS Build Steps**:
1. Install Node.js 22 (via nvm on macOS)
2. Read version from workspace `.new_version` file
3. Update `config.js` MOBILE_VERSION with new version
4. Build Nuxt app with `npm run generate` (static site)
5. Sync Capacitor to iOS project
6. Decode and place Firebase `GoogleService-Info.plist`
7. Install Fastlane and dependencies (Ruby gems)
8. Set up iOS certificates and provisioning profile
9. Query TestFlight for latest build number
10. Build IPA with auto-incremented build number (minimum 1272)
11. Upload to TestFlight
12. Store IPA as CircleCI artifact

**Artifacts**:
- **Android**: `android-bundle/app-release.aab`, `android-apk/app-release.apk`
- **iOS**: IPA file in artifacts

**Download artifacts**: https://app.circleci.com/pipelines/github/Freegle/iznik-nuxt3?branch=production

### Local Development

```bash
# Install dependencies
npm install

# Sync web code to native projects
npx cap sync

# Open in Android Studio
npx cap open android

# Open in Xcode
npx cap open ios
```

### Manual Production Build

```bash
# Build Nuxt app as static site
npm run build

# Sync to native projects
npx cap sync

# Build Android (via Android Studio or Gradle)
cd android
./gradlew bundleRelease

# Build iOS (via Xcode or xcodebuild)
cd ios/App
xcodebuild -workspace App.xcworkspace -scheme App -configuration Release
```

</details>

---

A separate development app allows rapid iteration by loading code from your local dev server instead of bundled assets.

<details>
<summary><h2>Freegle Dev App (Live Reload)</h2></summary>

### Overview

The "Freegle Dev" app is a separate Android app that:
- Has a different package ID (`org.ilovefreegle.dev`) so it can coexist with the production app
- Connects to `freegle-app-dev.local` via mDNS (no IP address needed)
- Supports hot module reloading (HMR) for instant code updates
- Only needs rebuilding when Capacitor plugins change

### Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Phone                                                      │
│  ┌───────────────────┐  ┌───────────────────┐              │
│  │ Freegle           │  │ Freegle Dev       │              │
│  │ (Production)      │  │ (Development)     │              │
│  │                   │  │                   │              │
│  │ Bundled assets    │  │ Connects via      │              │
│  │ Works offline     │  │ mDNS hostname     │              │
│  └───────────────────┘  └─────────┬─────────┘              │
└───────────────────────────────────┼─────────────────────────┘
                                    │ HTTP (WiFi) + WebSocket (HMR)
                                    │ freegle-app-dev.local:3004
                                    │ freegle-app-dev.local:24678
                                    ▼
┌─────────────────────────────────────────────────────────────┐
│  Developer Machine                                          │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ freegle-dev-live container                           │   │
│  │ Port 3004: Nuxt app server                          │   │
│  │ Port 24678: Vite HMR WebSocket                      │   │
│  └─────────────────────────────────────────────────────┘   │
│  mDNS broadcast: freegle-app-dev.local                     │
└─────────────────────────────────────────────────────────────┘
```

### Setup

1. **Build the dev APK** (one-time or when Capacitor plugins change):
   - Trigger the `build-dev-app` job in CircleCI
   - Download `freegle-dev.apk` from artifacts
   - Install on your Android device (enable "Install from unknown sources")

2. **Start the dev-live container**:
   - Go to `http://status.localhost`
   - Click "Start" on the freegle-dev-live container (requires confirmation as it uses live APIs)

3. **Connect phone to dev server** - choose ONE of these methods:

   **Option A: ADB Reverse (Recommended - simpler)**

   If you have ADB connected (USB or wireless ADB):
   ```cmd
   REM Run in Windows CMD/PowerShell
   adb reverse tcp:3004 tcp:3004
   adb reverse tcp:24678 tcp:24678
   ```
   This makes `localhost:3004` on the phone forward to your PC's port 3004.

   For WSL users, also set up port forwarding (one-time, run as Admin):
   ```powershell
   netsh interface portproxy add v4tov4 listenport=3004 listenaddress=0.0.0.0 connectport=3004 connectaddress=127.0.0.1
   netsh interface portproxy add v4tov4 listenport=24678 listenaddress=0.0.0.0 connectport=24678 connectaddress=127.0.0.1
   ```

   **Option B: mDNS (WiFi without ADB)**

   If not using ADB, set up mDNS hostname broadcast (Windows with Bonjour):
   ```cmd
   dns-sd -P "Freegle App Dev" _http._tcp local 3004 freegle-app-dev.local YOUR_IP
   ```
   Replace `YOUR_IP` with your LAN IP (e.g., `192.168.1.50`). Keep this window open.

   For WSL users, also add firewall rules (one-time, run as Admin):
   ```powershell
   New-NetFirewallRule -DisplayName "WSL Freegle Dev App" -Direction Inbound -LocalPort 3004 -Protocol TCP -Action Allow
   New-NetFirewallRule -DisplayName "WSL Freegle Dev HMR" -Direction Inbound -LocalPort 24678 -Protocol TCP -Action Allow
   ```

4. **Connect the app**:
   - Open Freegle Dev on your phone
   - App connects to `freegle-app-dev.local:3004` (works with both ADB reverse and mDNS)
   - If connection fails, check your chosen setup method

5. **Develop**:
   - Make code changes → app hot reloads via HMR
   - No rebuild needed for Vue/JS/CSS changes
   - Only rebuild APK when Capacitor plugins change

### App Comparison

| Aspect | Freegle (Production) | Freegle Dev |
|--------|---------------------|-------------|
| **Package ID** | `org.ilovefreegle.direct` | `org.ilovefreegle.dev` |
| **App Name** | Freegle | Freegle Dev |
| **Icon** | Normal | Orange tint |
| **Assets** | Bundled | From dev server |
| **Connection** | N/A | mDNS auto-connect |
| **APIs** | Production | Production (live data!) |
| **Play Store** | Published | Never published |

### Network Requirements

**With ADB Reverse (recommended):**
- ADB connected (USB or wireless)
- For WSL: netsh port forwarding configured

**With mDNS:**
- Phone and dev machine on same WiFi network
- mDNS broadcast running (`dns-sd` command)
- Port 3004 (app) and 24678 (HMR) accessible
- For WSL: port forwarding and firewall rules configured

### Troubleshooting

**ADB reverse not working:**
- Check ADB is connected: `adb devices`
- Re-run `adb reverse` commands after reconnecting
- For WSL: ensure netsh port forwarding is set up

**Cannot resolve freegle-app-dev.local (mDNS):**
- Ensure Bonjour is installed and `dns-sd` command is running
- Check phone is on same WiFi as dev machine
- Some corporate networks block mDNS - try ADB reverse instead

**App loads but HMR not working:**
- Check port 24678 is forwarded: `adb reverse tcp:24678 tcp:24678`
- Check firewall allows port 24678
- Check container logs for HMR errors

**Cannot connect to dev server:**
- Ensure freegle-dev-live container is running
- For ADB: verify with `adb reverse --list`
- For mDNS: check broadcast is running

**Changes not appearing:**
- Nuxt dev server should auto-reload
- Try refreshing the app or reconnecting

</details>

---

Testing the mobile app requires checking native features that cannot be tested via browser automation.

<details>
<summary><h2>Testing</h2></summary>

### App-Specific Test Checklist

From `capacitor.config.ts` comments:

- [ ] Status bar shows correctly on Android pre-A15, A15+ and iOS
- [ ] Camera: take photo and select one or more photos
- [ ] Yahoo login works
- [ ] Google login works (Android & iOS)
- [ ] Facebook login works (Android & iOS)
- [ ] Apple login works (iOS only)
- [ ] Stripe payment flows work
- [ ] Push notifications received
- [ ] Home screen badge count updates
- [ ] Share functionality works
- [ ] Deep links open correctly
- [ ] Android pinch zoom works
- [ ] Add to calendar works
- [ ] Device info collected properly

### Testing Donations

Enable donation modal for testing:

```javascript
// In pages/myposts.vue:
showDonationAskModal.value = true
```

</details>

---

Some features are excluded from the mobile build to reduce app size and complexity.

<details>
<summary><h2>Removed/Disabled for Mobile</h2></summary>

To reduce app size and complexity:

- **CircleCI config**: Different deployment process for mobile
- **Playwright tests**: Not applicable for native apps
- **Docker files**: Mobile apps don't use Docker
- **ModTools folder**: Removed to reduce app file size
- **Some councils data**: Reduced to minimize app size
- **Prebid ads**: Simplified ad system for mobile

</details>

---

Common issues encountered during development and their solutions.

<details>
<summary><h2>Known Issues & Workarounds</h2></summary>

### npm Install Issues

If npm reinstall needed, comment out this line:
```
node_modules/@capacitor/cli/dist/android/run.js:40
// await common_1.runTask
```

### Android Manifest

Ensure camera permission is present:
```xml
<uses-permission android:name="android.permission.CAMERA" />
```

### Package Overrides

Some packages require specific versions for compatibility. Check `package.json` overrides section.

</details>

---

Production releases are fully automated with scheduled promotions to app stores.

<details>
<summary><h2>Deployment</h2></summary>

### Fully Automated Dual-Platform Deployment

Both iOS and Android are built and deployed in parallel with shared version numbers. The workflow ensures consistency across platforms.

**Build Workflow**:

1. **Version Increment Job** (runs first):
   - Reads `CURRENT_VERSION` environment variable (e.g., "3.2.37")
   - Auto-increments patch version (3.2.37 → 3.2.38)
   - Saves to workspace file `.new_version`
   - Both platform jobs read from this shared file

2. **Android and iOS Jobs** (run in parallel):
   - Both attach workspace to read shared version
   - Both update `config.js` MOBILE_VERSION with new version
   - Both build Nuxt app with identical version
   - Both query their respective stores for build numbers
   - Both build and upload to beta/testing tracks
   - **Artifacts stored** for both platforms

**Deployment Workflow**:

1. **Development**:
   - Code committed to `master` branch
   - Tests run automatically (Playwright, PHPUnit, Go tests)

2. **Production Merge**:
   - When tests pass, `master` is auto-merged to `production` branch
   - Only tested code reaches production

3. **Deployment Triggers** (from `production` branch):
   - **Web**: Netlify deploys web application
   - **Mobile**: CircleCI builds and deploys iOS/Android apps
   - Both platforms deploy from same tested code

2. **Build and Deploy (11:00-11:30 PM UTC)**:
   - **Android**: Uploads to Google Play Beta (Open Testing)
   - **iOS**: Uploads to TestFlight
   - Both use version X.Y.Z (shared) with platform-specific build numbers (minimum 1272)
   - Release notes: "Version X.Y.Z - Bug fixes and improvements"

3. **Auto-Promote/Submit (24 hours later)**:
   - **Android**: Auto-promotes Beta → Production (if not already promoted)
   - **iOS**: Auto-submits latest TestFlight build to App Store review (if not already submitted)
   - Only the LATEST build from last 24 hours is submitted/promoted

### ModTools Release Cadence

ModTools releases differ from FD deliberately:

- **No per-push builds and no Play beta track** — nothing builds ModTools when
  `production` moves. The only builders are the weekly schedule and a manual
  trigger.
- **Weekly schedule**: CircleCI scheduled pipeline
  `weekly-modtools-release-schedule`, Wednesday 20:00 UTC, runs the
  `modtools-production` workflow (parameter `build_modtools_production: true`
  on `production`): version increment → Android straight to the Play
  **production** track + iOS to TestFlight → App Store submit attempt.
- **Why 20:00**: two hours before the FD `weekly-promote-schedule` (22:00).
  The inline iOS submit usually runs before Apple has processed the fresh
  build and exits gracefully; the promote workflow's
  `auto-submit-ios-modtools` then submits it the same night once processed.
- **Manual release**: set `build_modtools_production: true` in the CircleCI
  pipeline parameters UI (or API) on `production`.
- **Version bookkeeping**: the Android job writes the released version back to
  the `CURRENT_MODTOOLS_VERSION` project env var via `CIRCLECI_API_TOKEN`. If
  that write fails, fix the token and set the variable by hand before the next
  release — a stale value makes the next release rebuild the SAME version,
  which Apple rejects (the train closes once a version is approved).

### Android-Specific

**Build Process**:
- Queries Google Play for max version code across all tracks
- Auto-increments version code (enforces minimum 1272)
- Builds AAB (for Play Store) and APK (for direct install)
- Uploads to Beta (Open Testing) track
- Auto-promotes to Production after 24 hours

**Google Play Console**:
- Beta Testing: https://play.google.com/console → Your App → Testing → Open testing
- Production: https://play.google.com/console → Your App → Production

**Play App Signing**:
- Enrolled in Google Play App Signing
- Upload key (CircleCI) signs AABs
- App signing key (Google) signs final APKs

**Artifacts**:
- `android-bundle/app-release.aab`
- `android-apk/app-release.apk`

### iOS-Specific

**Build Process**:
- Queries TestFlight for latest build number
- Auto-increments build number (enforces minimum 1272)
- Sets Xcode version: `MARKETING_VERSION` and `CURRENT_PROJECT_VERSION`
- Builds IPA with manual code signing
- Uploads to TestFlight
- Auto-submits to App Store review after 24 hours (latest build only)

**App Store Connect**:
- TestFlight: https://appstoreconnect.apple.com → Your App → TestFlight
- App Store: https://appstoreconnect.apple.com → Your App → App Store

**Submission Notes**:
- **One submission per day is safe** - well within Apple's limits
- TestFlight has no daily submission issues
- App Store review typically takes 24 hours (90% of submissions)
- Auto-submit checks for blocking versions before submission
- Auto-submit will skip if another version is in review/approved
- If submission fails, check App Store Connect for versions blocking submission
- The auto_submit lane provides detailed error messages for troubleshooting

**Artifacts**:
- IPA file stored in CircleCI artifacts

### Manual Triggers

**Build Workflow:**
- Push to `production` branch: triggers full build workflow
- Rerun CircleCI workflow: rebuilds current commit

**Manual Promotion/Submission (via CircleCI):**
To manually trigger promotion/submission before the scheduled time:

1. Go to [CircleCI Pipelines](https://app.circleci.com/pipelines/github/Freegle/iznik-nuxt3?branch=production)
2. Click "Trigger Pipeline" (top right)
3. Select branch: `production`
4. Click "Add Parameters" (expand the parameters section)
5. Add parameter:
   - Name: `run_manual_promote`
   - Type: `boolean`
   - Value: `true`
6. Click "Trigger Pipeline"
7. The `manual-promote-submit` workflow will start immediately
8. Both `auto-promote-production` (Android) and `auto-submit-ios` will run in parallel

This allows you to promote/submit releases early without waiting for the midnight scheduled run, and without consuming CircleCI concurrency slots while waiting for approval.

**Alternative - Direct Fastlane:**
- Android: `bundle exec fastlane android auto_promote`
- iOS: `bundle exec fastlane ios auto_submit`
- Manual promotion/submission via store consoles (Google Play Console / App Store Connect)

### Timeline

```
Day 1, 11:00 PM: Build triggered (production merge or manual push)
Day 1, 11:30 PM: Builds complete
                 → Android uploaded to Beta (Open Testing)
                 → iOS uploaded to TestFlight

Day 2, 11:30 PM: Auto-promotion check (24 hours later)
                 → Android: Beta promoted to Production
                 → iOS: Latest build submitted to App Store review

Day 3-4:         iOS app review by Apple (typically 24 hours)
                 → iOS approved and available on App Store
```

### Fastlane Lanes

Available Fastlane lanes for manual operations:

**Android:**
```bash
# Build and deploy to Beta (automated in CI)
bundle exec fastlane android beta

# Promote from Beta to Production (automated in CI)
bundle exec fastlane android promote_production

# Auto-promote check (automated in CI, runs daily)
bundle exec fastlane android auto_promote
```

**iOS:**
```bash
# Build and deploy to TestFlight (automated in CI)
bundle exec fastlane ios beta

# Submit to App Store review (manual if needed)
bundle exec fastlane ios release

# Auto-submit latest build (automated in CI, runs daily)
bundle exec fastlane ios auto_submit
```

</details>

---

Guidelines for keeping the mobile app codebase up to date.

<details>
<summary><h2>Maintenance</h2></summary>

### Development Workflow

The production branch receives tested code automatically from master after tests pass. Manual merges are rarely needed, but if required:

```bash
git checkout production
git merge master  # Only merge after tests pass on master
# Resolve conflicts, test thoroughly
git push origin production
```

**Note**: The mobile app now builds from the same `production` branch as the web app. There is no longer a separate `app-ci-fd` branch.

### Capacitor Updates

When updating Capacitor major versions:

1. Update all `@capacitor/*` packages
2. Run `npx cap sync`
3. Review breaking changes in Capacitor release notes
4. Test all native features thoroughly
5. Update this README with any changes

</details>

---

Useful links for mobile app development.

<details>
<summary><h2>Resources</h2></summary>

- **Capacitor Docs**: https://capacitorjs.com/docs
- **App Release Plan**: `/plans/app-releases.md`
- **Freegle Push Plugin**: https://github.com/Freegle/capacitor-push-notifications
- **Google Play Console**: https://play.google.com/console
- **App Store Connect**: https://appstoreconnect.apple.com

</details>

---

Steps for debugging mobile app issues.

<details>
<summary><h2>Support</h2></summary>

For mobile app specific issues:

1. Check device info in app (Help → Copy app info)
2. Check Sentry for error reports with device context
3. Test on physical devices (simulators may behave differently)
4. Verify all environment variables are set correctly
5. Check native logs in Android Studio / Xcode

</details>

---

**Last Updated**: 2025-11-29
**Current Version**: 3.2.x (production branch)
**Capacitor Version**: 7.x
**CI/CD**: CircleCI with Fastlane (iOS and Android fully automated)
