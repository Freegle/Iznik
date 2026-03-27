# Freegle Mobile Feel — Design Spec

## Overview

A standalone Nuxt 3 app reimagining Freegle as a mobile-first chat-style experience. Single unified feed of offers/wanteds/discussion, scoped geographically by isochrone. Chat-style posting with automatic classification. Private per-user conversations. Client-side prototype with real data from existing iznik-nuxt3 API layer and Pinia stores.

## Onboarding Flow

1. **Location** — "Where are you?" screen. GPS button + postcode fallback. Freegle logo prominent. No account creation at this stage.
2. **Feed** — Immediately shown. Items ripple out geographically (no explicit distance shown). No explicit group joining — group name appears as subtle metadata on each post.

### Authentication

Unauthenticated users can browse the feed. Login is gated at the point of interaction (posting, replying). A lightweight login/signup prompt appears when the user first tries to post or reply. Existing iznik-nuxt3 auth methods (Google, Facebook, email) are available.

## The Feed (Main Screen)

The home screen. A single chronological stream of activity near the user.

### Layout
- **Header**: Freegle logo (from `iznik-nuxt3/public/icon.png`), location indicator (postcode area, no distance), chat bubble icon with unread badge count
- **Search bar**: Sticky below header. Filters feed in real-time by text matching against titles and descriptions
- **Filter toggle**: "Near me" (default) | "My stuff". "My stuff" shows user's active posts with reply count badges
- **Feed content**: Scrollable list of post cards
- **Composer bar**: Fixed at bottom, above ad banner. Text input + camera/attach icon. "Got something to offer or need?"
- **Ad banner**: Fixed at bottom of screen. Standard banner ad (same as current iznik-nuxt3). Jobs may appear in this slot.

### Post Cards

Three types, colour-coded:

- **Offer** — Green tint (`#f0f9e8`), green OFFER badge. Photo thumbnail (if available), title, short description, poster name, group name (subtle), relative time. Reply button.
- **Wanted** — Blue tint (`#e8f0fd`), blue WANTED badge. Same layout as Offer.
- **Discussion** — Neutral grey tint (`#f5f5f5`), no type badge. Text-focused. Maps to existing newsfeed/chitchat items from the API.
- **Taken/Received** — Collapsed single line: "Party-popper [Title] — TAKEN by [Name] · [time]". Greyed out. Social proof.

### Post Actions
- **Three-dot menu** (top-right of each post card): Report, Hide, Block user. Standard reporting flow.
- **Swipe gestures**: Swipe left on a post to dismiss/filter it. Swiping a taken/received item removes it from your feed. Swiping a discussion item filters out discussion posts (with undo toast). Contextual — the swipe action adapts to the post type.

### Feed Scoping
Uses existing isochrone/browse filter logic from iznik-nuxt3. Items ripple out geographically. No explicit distance numbers shown. Group names shown as subtle metadata, not navigation.

### Feed Filters
Type filter to show/hide: Offers, Wanteds, Discussion, All.

### Empty State
When no items are near the user: friendly illustration + "Nothing nearby yet. Be the first to offer something!" with a prompt to widen the area or post.

## Posting (Composer)

Tap the composer bar at the bottom. Type a message, optionally attach a photo via camera/gallery.

### Flow
1. User types "got a sofa, bit worn but comfy" and optionally attaches a photo
2. Hits send
3. **Confirm step**: The system restructures into an Offer/Wanted card. Shows preview: type badge, title, cleaned description, photo. "Does this look right?" with Edit / Post buttons
4. On confirm → appears in the feed

### Post Classification (Client-side Mock)
Simple keyword heuristics for the prototype:
- Contains "offering" / "got a" / "free to" / "giving away" → Offer
- Contains "looking for" / "anyone got" / "need a" / "wanted" → Wanted
- Otherwise → Discussion (with option to reclassify)

### Title Extraction (Client-side Mock)
Extract the key item from the text:
1. Strip the classification keyword prefix (e.g., "got a " from "got a sofa, bit worn")
2. Take text up to the first comma, full stop, or 40 characters — whichever comes first
3. Capitalise first letter
4. Example: "got a sofa, bit worn but comfy" → "Sofa"

### Taken Detection Heuristics (Client-side Mock)
Pattern matching on chat messages to trigger the "Did this get collected?" prompt:
- "collected" / "picked up" / "got it" / "all done"
- "thanks for the [item]" / "thank you"
- "on my way" + (30 min delay) → suggest check-in
- Only triggers in conversations that reference an active item

## Reply / Private Chat

Tap Reply on any post → slide-over panel from the right. Blue header with lock icon. Conversations are **per-user**.

### Layout
- **Header**: Back arrow, user avatar (circle), user name, "Private conversation" with lock icon
- **Quoted post**: At the top of the conversation, small card showing the item that prompted this chat (thumbnail + title + type badge)
- **Message bubbles**: Outgoing (green-tinted, right-aligned), incoming (light grey, left-aligned). Timestamps inside bubbles.
- **Composer**: Text input + send button at bottom

### Per-User Conversations (UI Grouping)
The underlying API creates separate chat rooms per item interaction. The mobile UI groups these visually under a single user. Each item discussed appears as a quoted reference card within the conversation timeline. The user sees one conversation per person, with item context cards interspersed.

### Item Thumbnails
Each conversation in the chat list shows a tiny thumbnail of the most recent item discussed, so you can tell conversations apart.

## Mark as Taken/Received

No explicit button on posts. Handled naturally:

1. **In-chat prompt**: When chat messages match taken-detection heuristics (see above), a subtle inline prompt appears: "Did this get collected? [Yes] [Not yet]"
2. **Next-day notification**: If not yet marked, a gentle push/email: "Was the [item] collected yesterday?"
3. On confirmation → item collapses to the taken line in the feed

## Unread Badges / Messages

- Chat bubble icon in header shows total unread count
- Tapping it shows the private conversations list (grouped per-user)
- Each conversation row: user avatar, name, last message preview, unread count badge, tiny item thumbnail
- Sorted by most recent activity

## "My Stuff" View

Toggle at top of feed: "Near me" | "My stuff"

"My stuff" shows:
- Your active posts (offers/wanteds) as cards
- Each card shows reply count badge (e.g., "3 replies")
- Tapping a card shows the replies — which are your private conversations about that item
- Taken/received items shown as collapsed lines

## Minimal Profile

Tap any user's name or avatar → profile card (slide-up sheet):
- Avatar (circle)
- Display name
- Short description/about text
- No ratings shown (future automated ratings)

## Notification Choice

Shown after the user's first post:
- "How should we tell you when someone replies?"
- Push notifications (instant alerts)
- Email (message when someone replies)
- "You can change this anytime"

## Donate

Contextual prompts at natural moments:
- After marking an item as taken: "You just saved a [item] from landfill! Buy Freegle a coffee?"
- Periodically in the feed as a native-feeling card (not intrusive)
- Not a separate page

## Discussion / Chitchat

Newsfeed/chitchat items from the existing API appear as discussion items in the feed. Grey-tinted cards. Can be filtered via feed type filter.

## Limited Settings

Accessible from header menu (hamburger or avatar tap):
- Address book (preserved from current iznik-nuxt3)
- Notification preferences (push/email toggle, quiet hours)
- Location change
- GDPR data export
- About / Help

## Advertising

- Bottom banner ad: fixed position, below composer bar. Standard ad format matching current iznik-nuxt3 implementation.
- Jobs may appear in the ad slot as a revenue format.

## Visual Design

### Colour System
- **Public/Offer**: Green (`#338808` badges, `#f0f9e8` card backgrounds)
- **Public/Wanted**: Blue (`#2563eb` badges, `#e8f0fd` card backgrounds)
- **Discussion**: Grey (`#f5f5f5` background)
- **Private chat**: Blue header (`#1e40af`), outgoing bubbles green-tinted, incoming light grey
- **Taken**: Grey, collapsed

### Typography & Spacing
- Follow existing iznik-nuxt3 design tokens (radii, shadows, transitions)
- Mobile-first: designed for 375px viewport, responsive up

### Branding
- Freegle logo (`icon.png`) in header
- Green header bar (`#1d6607`)
- Consistent with existing Freegle identity

## Technical Architecture

### Standalone Nuxt 3 App via Layers
- New directory: `freegle-mobile/` at repo root
- Uses **Nuxt layers** (`extends: ['../iznik-nuxt3']`) to inherit the API layer, Pinia stores, composables, and module configuration from iznik-nuxt3
- This resolves `~/` alias paths correctly — they point to the iznik-nuxt3 layer for inherited code
- The mobile app's own components/pages/layouts override the inherited ones
- `ssr: false` (client-side only prototype)
- Mobile viewport meta, no desktop layout

### Store Dependencies
- Nuxt layers inherit module config including `pinia-plugin-persistedstate`, `@bootstrap-vue-next/nuxt`, runtime config, and auto-imports
- Capacitor-specific imports in `auth.js` and `mobile.js` need conditional loading — wrap in `if (typeof Capacitor !== 'undefined')` guards or provide mock stubs in the mobile app's plugins
- Store initialisation happens via the inherited `api.js` plugin

### Real Data
- Uses existing `BaseAPI`, domain-specific APIs (MessageAPI, ChatAPI, etc.)
- Uses existing Pinia stores (message, chat, user, group, auth, location, compose, newsfeed)
- Connects to the same dev API endpoints

### Classification Mock (Client-side)
- Simple keyword classifier for offer/wanted/discussion
- Title extraction from freeform text (see algorithm above)
- Taken detection from chat message patterns (see heuristics above)
- All client-side heuristics, no server calls

### TDD
- Vitest tests for: post classifier, title extraction, taken detection, feed filtering, post card rendering, chat slide-over behaviour
- Component tests for each major UI piece

## Demo Video

Remotion-based (same approach as repair logger at `/home/edward/device-logging/video/`):
- Phone mockup frame component
- Screenshots captured from the running prototype via Playwright
- Subtitle narration for each scene
- 30fps, 1280x720
- Scenes: Location → Feed → Post → Confirm → Reply → Taken prompt → My Stuff → Notification choice

## Out of Scope

- Events, volunteering (future: in feed with automatic classification)
- Map view
- Stats, noticeboards
- Micro-volunteering
- Promote / spread the word
- Full settings
- Moderation tools
- Desktop layout
