# DESIGN DIRECTION - READ THIS FIRST

**CRITICAL: This file contains the design philosophy for the entire Freegle app. Every screen must follow these principles. This overrides any assumptions about conventional UI patterns.**

## The Core Problem

The current app looks like every other classifieds/marketplace app: grids of cards, scrolling lists, standard forms, conventional navigation. It's functional but forgettable. We need the entire app - every screen, every interaction, every transition - to feel fresh, interesting, and distinctly mobile-native.

## Philosophy: "This Doesn't Feel Like An App I've Used Before"

Every screen should make the user think "oh, that's nice" or "that's different". Not different for the sake of it, but different because mobile screens deserve interactions designed FOR them, not desktop patterns shrunk down.

This is NOT about being flashy or gimmicky. It's about thoughtful, warm, human interactions that feel natural on a phone. Think about how it feels to flip through photos, to swipe through stories, to pinch and zoom a map. Those feel RIGHT on a phone. A scrolling list of rectangles does not.

---

## EVERY SCREEN - Specific Direction

### Home / Browse Items
**Kill the grid. Kill the list.**

Instead, consider:
- **Card stack (Tinder-style)**: Large beautiful cards you swipe through. RIGHT = interested, LEFT = skip, UP = save. Spring physics. Peek at next card underneath. Each card fills most of the screen with a gorgeous photo, title overlaid with frosted glass at bottom.
- **Full-screen vertical feed (TikTok-style)**: Items snap into place full-screen. Swipe up/down between items. Photo fills screen, details overlay at bottom. Double-tap to express interest.
- **3D carousel / Cover Flow**: Items in a curved 3D arc. Center item large and prominent, sides recede with perspective. Swipe with momentum/inertia.
- **Map-first**: Items as beautiful bubbles on a map. Tap to see detail in a bottom sheet. The geography IS the browsing.

Whichever pattern: the item PHOTO should dominate. Text is secondary. The interaction should feel physical and satisfying.

### Give / Post an Item
**Not a form. A conversation.**

Instead of form fields on a screen:
- **Camera-first flow**: The FIRST thing is pointing your camera at the item. Photo is taken, then details are overlaid/edited on top of the photo itself (like Instagram story text editing).
- **Progressive disclosure**: Don't show all fields at once. One question at a time, full-screen, with beautiful transitions between steps. "What is it?" -> "Got a photo?" -> "Where are you?" -> done. Like a friendly chatbot guiding you through.
- **Drag-to-categorize**: After photo, show category bubbles that you drag your item into (playful physics).
- The success moment should feel CELEBRATORY - confetti, a warm animation, a community message like "3 Freeglers nearby might want this!"

### Chat / Messages
**Not a WhatsApp clone.**

- **Contextual chat**: The item being discussed should be visually present - perhaps as a persistent header card or a parallax background behind the conversation.
- **Quick actions as gestures**: Swipe a message to reply, long-press for reactions (thumbs up, heart, "On my way!", "Thanks!"), not just text bubbles.
- **Chat list as stories**: Instead of a plain list of conversations, show them as overlapping circular avatars with activity indicators, or as a horizontal carousel of active conversations at the top with recent items discussed.
- **Warm, human touches**: Typing indicators that feel personal, read receipts as gentle checkmarks, subtle sound/haptic feedback.
- **Collection flow built in**: When arranging pickup, offer a native map pin drop and time picker inline in the chat, not as separate screens.

### Profile / Me
**Not a settings page.**

- **Visual impact dashboard**: Show the user's giving journey visually - a growing garden/tree metaphor, or a map showing where their items went, or a timeline/river of their giving history.
- **Community connection**: Show the user as part of their local community - "You're one of 847 Freeglers in Edinburgh" with a subtle network visualization.
- **Stats that feel good**: Not just "5 items given" but "You saved 23kg from landfill" with a satisfying animated counter. Show environmental impact with beautiful infographics.
- **Achievements/milestones**: Gentle gamification - first item given, first month, helped 10 people. Not badge-heavy but warm acknowledgments.

### Login / Onboarding
**Not a form with email/password fields.**

- **Story-driven onboarding**: 3-4 beautiful full-screen panels that tell the Freegle story through illustrations/photos. Swipe through like stories. The community feel starts HERE.
- **Location-first**: After the story, ask for location with a beautiful map interaction, not a text field. Show nearby activity: "42 items were shared near you this week!"
- **Social proof**: Show real recent giveaways happening nearby as the background to the login screen. "Sarah just gave away a sofa in Leith" scrolling gently.
- **Frictionless entry**: Browse WITHOUT logging in. Only prompt for login when they want to do something (reply to an item, post something). And make that prompt feel inviting, not blocking.

### Post Detail
**Not a product page.**

- **Immersive photo experience**: Photo(s) fill the screen edge-to-edge. Content slides up as an overlapping sheet. Pinch to zoom. Swipe between photos with parallax.
- **The reply action should feel significant**: Not a boring "Send message" button but something that feels like reaching out - perhaps a pull-up gesture that reveals the message composer, or a glowing floating action that pulses gently.
- **Social context**: "3 people are looking at this" or "Jane from your area posted this" - make it feel LOCAL and HUMAN.
- **Similar items**: At the bottom, don't just list "related items" in a grid. Show them as a gently curved horizontal scroll with depth, or as floating bubbles.

### Navigation
**Rethink the tab bar.**

- Standard bottom navigation with 4 icons is fine functionally but consider:
  - **Animated transitions** between tabs - not just swap content but meaningful transitions (the give tab could zoom in from the + button, chat could slide in from the side).
  - **The "Give" action is special** - it's the most important action in the app. Make it prominent. A larger floating button? A distinctive color? A subtle pulse animation when the app first opens?
  - **Contextual navigation**: When browsing, the nav could subtly transform - items discovered could leave breadcrumbs.

---

## Design Feel Across Everything

- **Warm community feel** - neighbors helping neighbors, not a marketplace transaction
- **Playful micro-interactions** - satisfying haptic feedback, gentle bounces, spring physics
- **Generous whitespace** - don't cram, let things breathe
- **Photo-first everywhere** - images dominate, text supports
- **Delight in small moments** - loading states that are charming not boring, transitions that feel crafted
- **Physical metaphors** - things have weight, momentum, elasticity. Cards flip, items stack, conversations flow
- **Native feel** - use platform gestures, haptics, shared element transitions between screens
- **Accessibility always** - creative UIs must remain accessible (content descriptions, sufficient touch targets, screen reader support)

## Technical Toolkit

- `HorizontalPager` / `VerticalPager` for paging/swiping UIs
- `graphicsLayer` with `rotationY`, `scaleX/Y`, `translationZ` for 3D transforms
- `Modifier.pointerInput` + `detectDragGestures` for custom swipe handling
- `Animatable` and `spring()` for natural physics animations
- `BottomSheetScaffold` for overlay detail views
- `SharedTransitionLayout` for hero animations between screens
- `Canvas` and `drawBehind` for custom visual effects
- `Modifier.blur` for frosted glass effects
- `HapticFeedback` for tactile responses

## Research These Apps

Study these for interaction inspiration (NOT to copy their visual design):
1. **Olio** - closest to Freegle's mission, food/item sharing
2. **Too Good To Go** - beautiful card-based discovery
3. **Depop** - young, social, photo-first marketplace
4. **Pinterest** - visual discovery and masonry layout
5. **Tinder** - the swipe interaction pattern done perfectly
6. **TikTok** - full-screen vertical feed, addictive browsing
7. **Airbnb** - map-first discovery, beautiful detail pages
8. **Monzo** - a banking app that feels warm and human (proves ANY domain can feel delightful)

## What Success Looks Like

When someone opens the Freegle app, they should feel:
- "Oh, this is different!" (not another boring app)
- "This is fun to use!" (satisfying interactions everywhere)
- "I feel connected to my community" (warm, human, local)
- "I want to keep exploring" (engaging, inviting)
- "I want to show this to my friends" (remarkable, worth talking about)

The app should feel like it was designed by someone who loves mobile UX and loves community, not like a web app squeezed into a phone frame.
