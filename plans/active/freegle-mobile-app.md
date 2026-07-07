# Freegle Mobile App - Design & Implementation Plan

## Status: Phase 1 - Design & Architecture

## Task Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | V2 API analysis | ✅ Complete | 100+ endpoints mapped |
| 2 | UX research (competitor apps) | ✅ Complete | FB Marketplace, Olio, Gumtree, OfferUp, Vinted, WhatsApp, Buy Nothing |
| 3 | Technology stack decision | ✅ Complete | KMP + SwiftUI + Jetpack Compose |
| 4 | App architecture & UX design doc | ✅ Complete | KMP + native UI architecture designed |
| 5 | KMP shared module scaffold | ✅ Complete | Data models, API client, repositories, DI |
| 6 | Android app scaffold | ✅ Complete | 4 tabs, 7 screens, Material 3 theme, builds clean |
| 6a | V2 API model fix | ✅ Complete | Models updated to match bare JSON response format (not wrapped) |
| 6b | Emulator testing | ✅ Complete | All 7 screens verified in Android 35 emulator, API connected |
| 7 | iOS app scaffold | ⏸ Deferred | SwiftUI, Liquid Glass - build after Android validated |
| 8 | Shared API client (Ktor) | ✅ Complete | Ktor client with bare JSON responses, error handling |
| 9 | Shared data models & repositories | ✅ Complete | All models match V2 Go API response shapes |
| 10 | Authentication flow (shared + UI) | 🔄 Partial | Developer JWT login works; need real OAuth/email flow |
| 11 | Browse/search home feed | 🔄 Partial | UI complete, connects to API (empty DB shows empty state) |
| 12 | Post detail & reply | 🔄 Partial | UI scaffolded, needs real data testing |
| 13 | Chat/messaging | 🔄 Partial | UI scaffolded, needs auth for API access |
| 14 | Post creation (offer/wanted) | 🔄 Partial | Form UI complete, needs photo upload + API submission |
| 15 | User profile & settings | 🔄 Partial | UI scaffolded with logged-in/out states |
| 16 | Push notifications | ⬜ Pending | |
| 17 | Feature parity testing | ⬜ Pending | |

---

## V2 API Summary (Key Endpoints for Mobile App)

### Authentication
- JWT-based: `Authorization: Bearer <token>` or `?jwt=<token>`
- Session POST for LostPassword/Unsubscribe
- No dedicated login/signup endpoint in V2 - handled by V1 PHP API

### Core User Flows - Endpoints Needed

**Browse & Discover:**
- `GET /message/inbounds?swlat=&swlng=&nelat=&nelng=` - Location-based browse
- `GET /message/search/{term}` - Search with typo tolerance
- `GET /message/{ids}` - Get full message details
- `GET /group/{id}/message` - Messages by group
- `GET /isochrone/message` - Messages in user's travel zone
- `GET /activity` - Recent activity feed

**Replying to a Post:**
- `POST /chat/{id}/message` - Send chat message (creates chat if needed)
- `GET /chat` - List user's chats
- `GET /chat/{id}/message` - Get chat messages

**Creating a Post:**
- Via V1 API (message submission not yet in V2)
- `POST /image` - Upload images (Tus-based)

**Arranging Collection:**
- `PUT /tryst` - Create a meetup arrangement
- `POST /tryst` - Confirm/decline
- `GET /tryst` - List arrangements
- `POST /message` with action "Promise" - Promise item to user

**User Profile:**
- `GET /user/{id}` - Get user (self or other)
- `GET /user/{id}/message` - User's posts
- `POST /user` with action "Rate" - Rate user

**Notifications:**
- `GET /notification` - List notifications
- `GET /notification/count` - Badge count
- `POST /notification/seen` - Mark seen

**Groups/Communities (handled transparently):**
- `PUT /memberships` - Auto-join group
- `GET /group` - List groups
- `GET /location/latlng` - Resolve location to groups

---

## UX Research Findings

### Key Pain Points (Current Freegle App)
1. "Huge pictures and tiny words" - poor information density
2. Wrong item displayed when users respond - confusing chat linkage
3. Back button doesn't work properly
4. Photo upload failures
5. Community/group concept creates friction
6. Feels dated compared to modern apps
7. Too many concepts to understand (communities, chats, offers, wants)

### Competitor Best Practices

**Facebook Marketplace:**
- Location auto-detected, no manual group joining
- One-tap "Message Seller" from any listing
- Chat opens inline with item context shown
- Tab bar: Browse / Selling / Inbox
- Skeleton loading states for perceived speed

**Olio:**
- Very similar to Freegle (free items, local community)
- Photo-first grid layout
- Problems: multiple threads per person for different items
- Good: simple "Request" button on listings

**Buy Nothing:**
- Hyper-local by default
- "Gives" and "Asks" as primary concepts (maps to Offer/Wanted)
- Home feed surfaced by proximity
- Challenge: groups still create friction

**OfferUp/Mercari:**
- List in under 60 seconds
- "Make Offer" / "Message" as single-tap actions
- Clean mobile-first UX
- Quick reply chips in chat ("Is this still available?")

**WhatsApp/iMessage (messaging gold standard):**
- Optimistic message sending (appears instantly)
- Read receipts (blue ticks)
- Typing indicators
- Image previews in-line
- Haptic feedback on interactions

### Design Principles for New App

1. **Location-first, not community-first** - Auto-detect location, show nearby items. Communities exist in the backend but users don't need to think about them.

2. **One-tap actions** - "I'm interested" button on every listing. No intermediate steps.

3. **Unified inbox** - All conversations in one place, each tied to a specific item with visual context.

4. **Minimal concepts** - User only needs to understand: Browse items -> Express interest -> Chat -> Arrange pickup

5. **Photo-first** - Large, high-quality images in grid layout. Text secondary.

6. **Optimistic UI** - Messages appear sent instantly. Loads feel instant with skeleton states.

7. **Progressive disclosure** - Simple by default, complexity available when needed.

8. **Platform-native feel** - iOS app looks and feels like an iOS app (Liquid Glass, SF Symbols, haptics). Android app looks and feels like an Android app (Material 3 Expressive, predictive back, dynamic colour).

---

## Technology Stack Decision

### Decision: KMP Shared Logic + Native UI (SwiftUI + Jetpack Compose)

**Why this architecture:**

1. **AI has collapsed the cost of native** - Two native apps with AI assistance costs ~1.3x one platform, not 2x. OpenAI built Sora Android in 28 days with 85% AI-written code.

2. **Guaranteed functional sync** - The shared Kotlin layer enforces feature parity. Both apps consume the same API client, data models, and business logic.

3. **Best possible UX on each platform** - iOS gets Liquid Glass, SF Symbols, native haptics. Android gets Material 3 Expressive, dynamic colour, predictive back gesture. No compromises.

4. **No framework risk** - SwiftUI and Jetpack Compose are the official, permanent UI frameworks. No third-party framework to depend on.

5. **First-class AI tooling** - Xcode 26.3 with Claude Agent SDK. Android Studio with Gemini. Purpose-built for native development.

### Architecture

```
freegle-app/
├── shared/                         # KMP shared module (Kotlin)
│   ├── src/commonMain/kotlin/
│   │   ├── api/                    # Ktor HTTP client
│   │   ├── model/                  # Data models (Message, Chat, User, Tryst)
│   │   ├── repository/             # Repositories (data access layer)
│   │   ├── usecase/                # Business logic / use cases
│   │   └── auth/                   # JWT token management
│   ├── src/androidMain/kotlin/     # Android-specific (platform expect/actual)
│   ├── src/iosMain/kotlin/         # iOS-specific (platform expect/actual)
│   └── build.gradle.kts
│
├── androidApp/                     # Android (Jetpack Compose)
│   ├── src/main/kotlin/
│   │   ├── ui/screens/             # Compose screens
│   │   ├── ui/components/          # Reusable Compose components
│   │   ├── ui/theme/               # Material 3 Expressive theme
│   │   └── navigation/             # Navigation graph
│   └── build.gradle.kts
│
├── iosApp/                         # iOS (SwiftUI)
│   ├── Sources/
│   │   ├── Screens/                # SwiftUI views
│   │   ├── Components/             # Reusable SwiftUI components
│   │   ├── Theme/                  # Design tokens, colours
│   │   └── Navigation/             # Tab/navigation structure
│   └── iosApp.xcodeproj
│
├── build.gradle.kts                # Root build
├── settings.gradle.kts
└── gradle.properties
```

### Shared Layer Libraries (Kotlin)
- **Ktor** - HTTP client (cross-platform, suspend functions)
- **kotlinx.serialization** - JSON serialization
- **kotlinx.coroutines** - Async/concurrent operations
- **Koin** - Dependency injection (lightweight, KMP-compatible)

### Android Libraries
- **Jetpack Compose 1.10+** - Declarative UI
- **Material 3 Expressive** - Design system
- **Compose Navigation** - Type-safe navigation
- **Coil 3** - Image loading with Compose integration
- **Accompanist** - Permissions, system UI controller

### iOS Libraries
- **SwiftUI (iOS 26+)** - Declarative UI with Liquid Glass
- **Swift Concurrency** - async/await for KMP interop
- **Kingfisher** - Image loading and caching
- **KMP-NativeCoroutines** - Bridge Kotlin coroutines to Swift async

### Feature Parity Enforcement

The shared layer defines **feature contracts** - interfaces that specify what each screen must do:

```kotlin
// shared/src/commonMain/kotlin/usecase/BrowseUseCase.kt
interface BrowseUseCase {
    suspend fun getLocalItems(lat: Double, lng: Double, radiusKm: Double): List<MessageSummary>
    suspend fun searchItems(query: String): List<MessageSummary>
    suspend fun getItemDetail(id: Long): MessageDetail
    suspend fun expressInterest(messageId: Long): ChatRoom
}
```

Both iOS and Android UIs implement the same use cases, guaranteeing identical functionality.
Shared integration tests verify business logic works correctly on both platforms.

---

## App Architecture

### Screen Structure (4 tabs)

Both platforms share the same 4-tab structure, but each uses its native design language:

**iOS**: Tab bar at bottom, SF Symbols, Liquid Glass material, swipe-back navigation
**Android**: Bottom navigation bar, Material Icons, Material 3 surfaces, predictive back gesture

### Tab 1: Home (Browse)
- Search bar at top
- Filter chips: All / Offers / Wanted
- 2-column photo grid of nearby items
- Each card: photo, title, distance, time ago
- Pull-to-refresh
- Infinite scroll
- Location shown at top (tappable to change)

### Tab 2: Give (Post)
- Camera opens directly OR photo picker
- After photo: auto-suggest title
- Minimal form: title, description (optional), category
- Location auto-filled
- "Post" button
- Supports both Offer and Wanted

### Tab 3: Chat (Inbox)
- List of conversations
- Each row: user avatar, item thumbnail, last message, timestamp
- Unread badge
- Tap to open full chat
- Chat shows item context at top
- Quick action buttons: "Arrange pickup", "Mark as promised"

### Tab 4: Me (Profile)
- User avatar and name
- My active posts (offers/wanted)
- Settings
- Notification preferences
- About Freegle

### Key User Flows

#### Flow 1: Browse -> Reply -> Arrange Pickup
```
Home feed -> Tap item -> Item detail (full photo, description, poster info)
-> "I'm interested" button -> Chat opens with auto-message
-> Chat back and forth -> "Arrange pickup" -> Set time/location
-> Confirm collection -> Mark as collected
```

#### Flow 2: Post an Item
```
Tap "Give" tab -> Camera/gallery -> Take/select photo(s)
-> Enter title -> Optional description -> Confirm location
-> "Post" -> Item goes live -> See responses in Chat tab
```

#### Flow 3: Search for Something
```
Tap search bar -> Type query -> Results appear instantly
-> Tap item -> Same detail/reply flow as browsing
```

---

## Implementation Phases

### Phase 1: Foundation (Tasks 5-9)
- KMP project scaffold with Gradle
- Shared data models matching V2 API
- Shared Ktor HTTP client with JWT auth
- Shared repositories and use cases
- Android app shell with Compose + Material 3
- iOS app shell with SwiftUI

### Phase 2: Core Browse (Tasks 10-11)
- Login/signup flow (shared auth + native UI)
- Home feed with photo grid (both platforms)
- Location detection
- Search
- Pull-to-refresh, infinite scroll

### Phase 3: Interaction (Tasks 12-13)
- Post detail screen (both platforms)
- "I'm interested" -> Chat creation
- Chat messaging UI (both platforms)
- Chat list (inbox)
- Quick actions in chat

### Phase 4: Creation (Task 14)
- Photo capture/selection (native camera APIs)
- Post creation form
- Image upload

### Phase 5: Polish (Tasks 15-17)
- User profile
- Push notifications
- Settings
- Feature parity testing
- Platform-specific animations and transitions

---

## Visual Design Tokens

### Colours (shared values, applied per-platform)
```
Primary:      #00B050 (Freegle green)
Primary Dark: #008A3E
Accent:       #FF6B35 (warm orange for CTAs)
Error:        #D32F2F
Success:      #00B050
```

iOS applies these within Liquid Glass material system.
Android applies these within Material 3 dynamic colour, with Freegle green as seed colour.

### Typography
Both platforms use their native system fonts:
- **iOS**: SF Pro (automatically via SwiftUI)
- **Android**: Roboto / system default (automatically via Compose)

Scale: Title (24), Subtitle (18), Body (16), Caption (14), Small (12)

### Spacing Scale
```
xs: 4dp/pt
sm: 8dp/pt
md: 16dp/pt
lg: 24dp/pt
xl: 32dp/pt
```
