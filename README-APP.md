# Freegle Mobile App

Native mobile app for Freegle using Kotlin Multiplatform (KMP) shared logic with platform-native UI.

## Architecture

- **Shared module** (`freegle-app/shared/`): Kotlin Multiplatform code containing data models, API client (Ktor), repositories, and business logic. Consumed by both Android and iOS apps.
- **Android app** (`freegle-app/androidApp/`): Jetpack Compose UI with Material 3 theming.
- **iOS app** (`freegle-app/iosApp/`): SwiftUI (deferred - not yet implemented).

## Building

### Prerequisites

- Java 17+ (OpenJDK recommended)
- Android SDK with platform 35 and build-tools 35.0.0

### Build Debug APK

```bash
cd freegle-app
export ANDROID_HOME=/path/to/android-sdk
./gradlew assembleDebug
```

The APK will be at `androidApp/build/outputs/apk/debug/androidApp-debug.apk`.

### API Configuration

The app connects to the Freegle V2 API (Go) for most operations and V1 API (PHP) for user creation/session management.

Default URLs in `androidApp/build.gradle.kts`:
- **V2**: `https://api.ilovefreegle.org/apiv2` (production Go API)
- **V1**: `https://fdapilive.ilovefreegle.org/api` (production PHP API)

To use local APIs for development:
```kotlin
buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:18193/api\"")
buildConfigField("String", "API_V1_BASE_URL", "\"http://10.0.2.2:18181/api\"")
```

### V2 API Response Format

The Go V2 API returns **bare JSON arrays and objects** (not wrapped in `{ret: 0, status: "", ...}` envelopes like the V1 PHP API). For example, `GET /api/message/inbounds` returns `[{...}, {...}]` directly, and `GET /api/user/{id}` returns `{...}` directly.

## Features

### UI Design - Daily 5 + Discovery Deck
- **Daily 5 curated picks (Home)**: 5 items picked for you each day across categories: Just Listed, Near You, Needs a Home, Popular, Surprise Find. Deterministic per-day selection (reopening shows same 5). Category badges on each card. Progress dots (coloured by category) replace numeric counter. Completion celebration screen with streak display after viewing all 5.
- **Streak tracking**: Duolingo-style consecutive-day streaks. Flame icon badge shows current streak count. Best streak tracked. Streak increments automatically when all 5 daily picks are viewed.
- **Sharing**: Share button on every card (Home, Explore, PostDetail). Uses Android share sheet with Freegle link. "Share via" any messaging app.
- **Activity level masking**: Raw reply counts replaced with qualitative text: "No interest yet", "A few people interested", "Popular item". No raw numbers exposed.
- **Discovery Deck feed (Home)**: Tinder-style swipeable cards showing one item at a time. Full-bleed photos fill the card with a gradient overlay at the bottom showing item title, distance, giver info, and qualitative interest. Swipe right = interested (opens detail), swipe left = skip, tap = view detail. Spring physics animations for card movement with rotation on drag.
- **Explore browse (Explore tab)**: Traditional searchable/filterable list for deliberate browsing. Thumbnail + detail rows with distances, search bar, filter chips, and share button per item. Complements the Daily 5 with intentional search.
- **Walking-time distances**: Distances shown with Haversine formula for distance calculation.
- **Swipe direction indicators**: Green heart overlay appears on swipe right (interested), red X on swipe left (skip). Opacity proportional to swipe distance.
- **Card stack effect**: Next card visible behind current card at 95% scale for depth.
- **Other items from poster**: PostDetailScreen shows horizontally scrollable thumbnails of other items from the same person.
- **Camera-first Post flow**: Progressive disclosure one question at a time with slide transitions
- **Story circles chat list**: Horizontal avatar carousel for active chats with gradient activity rings
- **Immersive post detail**: Full-screen photos with BottomSheetScaffold, pinch-to-zoom, parallax paging
- **Impact dashboard profile**: Animated counters, achievements/milestones, environmental impact stats
- **Pulsing Post button**: Custom Freegle heart-arrows motif in nav bar with infinite pulse animation
- **Shimmer skeleton loading**: Animated single-card skeleton matching deck layout while feed loads
- **AI illustrations**: Automatic fallback to AI-generated line drawings for items without photos
- **NEW badge**: Orange badge on items posted in the last 24 hours

### Core Functionality
- **5-tab navigation**: Daily 5 (curated picks), Explore (search/browse list), Post, Chat, Me
- **Search**: Search bar on Explore screen with debounced live results and loading indicator
- **In-chat item context**: Persistent header showing which item is being discussed
- **Message sending**: User types their own message to express interest (no auto-send)
- **Error handling**: Visible error states with retry actions on all screens (Home, Chat, ChatList)
- **Pull-to-refresh**: On Explore list and chat list
- **Location**: GPS auto-detection with manual postcode fallback, saved to DataStore
- **Onboarding**: 4-page intro with real Freegle community photos + feature tour overlay
- **WCAG AA colours**: Green darkened to #008040 (5:1 contrast with white), blue for Wanted items
- **Material 3**: Freegle brand colour scheme, light and dark theme support
- **Progress tracking**: Coloured progress dots show position in daily 5 picks (filled = seen, empty = remaining)

### Authentication
- **Auto-login**: Device-based anonymous account creation via V1 API on first launch
- **Persistent credentials**: JWT + persistent token stored in DataStore, restored on app restart
- **Email verification**: Optional email linking in profile settings (two-step verification flow)
- **No explicit sign-in**: Users can browse, chat, and post without manual login

### Help & Settings
- **Help menu**: Accessible from profile screen with replay welcome tour option
- **Account settings**: Email management, notifications, about page
- **Welcome carousel**: Replayable from help menu at any time

### Test Data
Run `create-test-data.php` inside the apiv1 container to populate 20 realistic Edinburgh-area items:
```bash
docker exec freegle-apiv1 php /var/www/iznik/install/create-mobile-test-data.php
```

## Testing with Android Emulator

### Setup

```bash
# Install emulator and system image
ANDROID_HOME=/home/edward/android-sdk
$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager "emulator" "system-images;android-35;google_apis;x86_64"

# Ensure KVM access (for hardware acceleration)
sudo gpasswd -a $USER kvm

# Create AVD
echo "no" | $ANDROID_HOME/cmdline-tools/latest/bin/avdmanager create avd \
  -n freegle_test -k "system-images;android-35;google_apis;x86_64" -d pixel_6 --force

# Launch emulator (headless)
sg kvm -c "$ANDROID_HOME/emulator/emulator -avd freegle_test -no-window -no-audio -gpu swiftshader_indirect"

# Install APK
$ANDROID_HOME/platform-tools/adb install androidApp/build/outputs/apk/debug/androidApp-debug.apk

# Launch app
$ANDROID_HOME/platform-tools/adb shell am start -n org.freegle.app/.android.MainActivity

# Take screenshot
$ANDROID_HOME/platform-tools/adb exec-out screencap -p > screenshot.png
```

## Design Decisions

See `plans/active/freegle-mobile-app.md` for the full design document including:
- V2 API endpoint mapping
- UX research findings from competitor apps (Olio, Geev, TGTG, Buy Nothing, Depop, Vinted, OfferUp, Nextdoor, FB Marketplace)
- Technology stack decision rationale (KMP + native UI)
- Screen architecture and user flows
- Discovery Deck design rationale (Tinder-style vs grid vs social feed)
