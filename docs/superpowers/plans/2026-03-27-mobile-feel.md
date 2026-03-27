# Freegle Mobile Feel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a mobile-first chat-style Freegle prototype as a standalone Nuxt 3 app using real data from iznik-nuxt3.

**Architecture:** Standalone Nuxt 3 app (`freegle-mobile/`) that extends iznik-nuxt3 via Nuxt layers to inherit API, stores, and composables. Own pages/layouts/components override inherited ones. Client-side only (`ssr: false`). AI classification mocked with keyword heuristics. Demo video via Remotion.

**Tech Stack:** Nuxt 3, Vue 3 Composition API, Pinia, Vitest, Remotion (React), Playwright (screenshots)

**Spec:** `docs/superpowers/specs/2026-03-27-mobile-feel-design.md`

---

## File Structure

```
freegle-mobile/
├── nuxt.config.ts              # extends iznik-nuxt3, ssr:false, mobile meta
├── package.json                # minimal deps (vitest, @nuxt/test-utils)
├── vitest.config.ts            # test config
├── app.vue                     # mobile app shell
├── layouts/
│   └── default.vue             # mobile layout (header, content, composer, ad)
├── pages/
│   ├── index.vue               # location onboarding
│   └── feed.vue                # main feed + chat views
├── components/
│   ├── MobileHeader.vue        # logo, location indicator, chat badge
│   ├── FeedCard.vue            # post card (offer/wanted/discussion/taken)
│   ├── FeedComposer.vue        # bottom composer bar
│   ├── FeedSearch.vue          # sticky search bar
│   ├── FeedFilter.vue          # near-me/my-stuff toggle + type filters
│   ├── FeedEmpty.vue           # empty state
│   ├── ConfirmPost.vue         # AI confirm step overlay
│   ├── ChatSlideOver.vue       # private chat slide-over panel
│   ├── ChatBubble.vue          # message bubble (outgoing/incoming)
│   ├── ChatList.vue            # conversations list view
│   ├── ItemQuote.vue           # quoted item card in chat
│   ├── TakenPrompt.vue         # inline "did this get collected?" prompt
│   ├── ProfileSheet.vue        # minimal profile slide-up
│   ├── NotifyChoice.vue        # post-posting notification modal
│   ├── DonateCard.vue          # contextual donate prompt
│   ├── AdBanner.vue            # bottom ad banner
│   ├── SwipeableCard.vue       # swipe gesture wrapper
│   ├── ThreeDotMenu.vue        # report/hide/block popover
│   ├── SettingsDrawer.vue      # limited settings slide-out
│   └── LocationPicker.vue      # GPS + postcode input
├── composables/
│   ├── useAiClassifier.js      # offer/wanted/discussion classification
│   ├── useTitleExtractor.js    # title from freeform text
│   ├── useTakenDetector.js     # detect handover in chat messages
│   ├── useFeedFilter.js        # feed filtering + search logic
│   └── useSwipeGesture.js      # touch swipe handling
├── assets/
│   └── css/
│       └── mobile.scss         # mobile-specific styles, design tokens
├── plugins/
│   └── capacitor-stub.client.js # mock Capacitor for web-only
├── tests/
│   ├── composables/
│   │   ├── useAiClassifier.spec.js
│   │   ├── useTitleExtractor.spec.js
│   │   ├── useTakenDetector.spec.js
│   │   └── useFeedFilter.spec.js
│   └── components/
│       ├── FeedCard.spec.js
│       ├── FeedComposer.spec.js
│       ├── ChatSlideOver.spec.js
│       └── SwipeableCard.spec.js
└── video/
    ├── package.json
    ├── tsconfig.json
    ├── src/
    │   ├── index.ts
    │   ├── Root.tsx
    │   ├── DemoVideo.tsx
    │   ├── PhoneMockup.tsx
    │   └── Subtitle.tsx
    └── public/
        └── screenshots/        # captured from running prototype
```

---

## Task 1: Project Scaffold

**Files:**
- Create: `freegle-mobile/package.json`
- Create: `freegle-mobile/nuxt.config.ts`
- Create: `freegle-mobile/app.vue`
- Create: `freegle-mobile/plugins/capacitor-stub.client.js`
- Create: `freegle-mobile/vitest.config.ts`

- [ ] **Step 1: Create package.json**

```json
{
  "name": "freegle-mobile",
  "private": true,
  "scripts": {
    "dev": "nuxt dev --port 3001",
    "build": "nuxt build",
    "test": "vitest run",
    "test:watch": "vitest"
  },
  "dependencies": {
    "nuxt": "^3.17.0"
  },
  "devDependencies": {
    "vitest": "^3.2.1",
    "@nuxt/test-utils": "^3.17.0",
    "@vue/test-utils": "^2.4.6",
    "happy-dom": "^18.0.1"
  }
}
```

- [ ] **Step 2: Create nuxt.config.ts**

```typescript
export default defineNuxtConfig({
  extends: ['../iznik-nuxt3'],
  ssr: false,
  devtools: { enabled: true },

  app: {
    head: {
      viewport: 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no',
      meta: [
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'theme-color', content: '#1d6607' },
      ],
    },
  },

  // Override inherited pages — only use our own
  pages: true,

  // Disable inherited modules we don't need
  modules: [
    '@pinia/nuxt',
    'pinia-plugin-persistedstate/nuxt',
  ],
})
```

- [ ] **Step 3: Create app.vue**

```vue
<template>
  <NuxtLayout>
    <NuxtPage />
  </NuxtLayout>
</template>
```

- [ ] **Step 4: Create Capacitor stub plugin**

```javascript
// freegle-mobile/plugins/capacitor-stub.client.js
// Prevents Capacitor import errors from inherited auth/mobile stores
export default defineNuxtPlugin(() => {
  if (typeof window !== 'undefined' && !window.Capacitor) {
    window.Capacitor = { isNativePlatform: () => false }
  }
})
```

- [ ] **Step 5: Create vitest.config.ts**

```typescript
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '~': resolve(__dirname),
      '@': resolve(__dirname),
    },
  },
  test: {
    environment: 'happy-dom',
    include: ['tests/**/*.spec.js'],
  },
})
```

- [ ] **Step 6: Install dependencies and verify Nuxt layer extends**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npm install`
Expected: Clean install, no errors

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx nuxt prepare`
Expected: `.nuxt/` directory created, inherits iznik-nuxt3 types/auto-imports

- [ ] **Step 7: Commit**

```bash
git checkout -b feature/mobile-feel
git add freegle-mobile/
git commit -m "feat: scaffold freegle-mobile Nuxt app extending iznik-nuxt3"
```

---

## Task 2: AI Classifier Composable (TDD)

**Files:**
- Create: `freegle-mobile/composables/useAiClassifier.js`
- Create: `freegle-mobile/tests/composables/useAiClassifier.spec.js`

- [ ] **Step 1: Write the failing tests**

```javascript
// tests/composables/useAiClassifier.spec.js
import { describe, it, expect } from 'vitest'
import { classifyPost } from '~/composables/useAiClassifier'

describe('classifyPost', () => {
  // Offers
  it('classifies "got a sofa" as Offer', () => {
    expect(classifyPost('got a sofa, bit worn but comfy')).toBe('Offer')
  })
  it('classifies "offering a desk" as Offer', () => {
    expect(classifyPost('offering a desk, good condition')).toBe('Offer')
  })
  it('classifies "free to collect" as Offer', () => {
    expect(classifyPost('free to collect - washing machine')).toBe('Offer')
  })
  it('classifies "giving away books" as Offer', () => {
    expect(classifyPost('giving away box of books')).toBe('Offer')
  })

  // Wanteds
  it('classifies "looking for a bike" as Wanted', () => {
    expect(classifyPost('looking for a bike for my kid')).toBe('Wanted')
  })
  it('classifies "anyone got a table" as Wanted', () => {
    expect(classifyPost('anyone got a table they dont need?')).toBe('Wanted')
  })
  it('classifies "need a bookshelf" as Wanted', () => {
    expect(classifyPost('need a bookshelf, any size')).toBe('Wanted')
  })
  it('classifies "wanted: garden tools" as Wanted', () => {
    expect(classifyPost('wanted: garden tools')).toBe('Wanted')
  })

  // Discussion
  it('classifies general text as Discussion', () => {
    expect(classifyPost('has anyone used the tip lately?')).toBe('Discussion')
  })
  it('classifies empty string as Discussion', () => {
    expect(classifyPost('')).toBe('Discussion')
  })

  // Case insensitive
  it('is case insensitive', () => {
    expect(classifyPost('GOT A sofa')).toBe('Offer')
    expect(classifyPost('LOOKING FOR a bike')).toBe('Wanted')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useAiClassifier.spec.js`
Expected: FAIL — module not found

- [ ] **Step 3: Write minimal implementation**

```javascript
// composables/useAiClassifier.js

const OFFER_PATTERNS = [
  /\bgot an?\b/i,
  /\boffering\b/i,
  /\bfree to\b/i,
  /\bgiving away\b/i,
  /\bgive away\b/i,
  /\bfree[:\s]/i,
]

const WANTED_PATTERNS = [
  /\blooking for\b/i,
  /\banyone got\b/i,
  /\bneed an?\b/i,
  /\bwanted[:\s]?\b/i,
  /\bdoes anyone have\b/i,
  /\bin search of\b/i,
]

export function classifyPost(text) {
  if (!text) return 'Discussion'

  for (const pattern of OFFER_PATTERNS) {
    if (pattern.test(text)) return 'Offer'
  }

  for (const pattern of WANTED_PATTERNS) {
    if (pattern.test(text)) return 'Wanted'
  }

  return 'Discussion'
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useAiClassifier.spec.js`
Expected: All 12 tests PASS

- [ ] **Step 5: Commit**

```bash
git add freegle-mobile/composables/useAiClassifier.js freegle-mobile/tests/composables/useAiClassifier.spec.js
git commit -m "feat: add AI post classifier with keyword heuristics (TDD)"
```

---

## Task 3: Title Extractor Composable (TDD)

**Files:**
- Create: `freegle-mobile/composables/useTitleExtractor.js`
- Create: `freegle-mobile/tests/composables/useTitleExtractor.spec.js`

- [ ] **Step 1: Write the failing tests**

```javascript
// tests/composables/useTitleExtractor.spec.js
import { describe, it, expect } from 'vitest'
import { extractTitle } from '~/composables/useTitleExtractor'

describe('extractTitle', () => {
  it('strips "got a" prefix and takes text before comma', () => {
    expect(extractTitle('got a sofa, bit worn but comfy', 'Offer')).toBe('Sofa')
  })
  it('strips "offering" prefix', () => {
    expect(extractTitle('offering a desk, good condition', 'Offer')).toBe('Desk')
  })
  it('strips "free to collect" prefix', () => {
    expect(extractTitle('free to collect - washing machine', 'Offer')).toBe('Washing machine')
  })
  it('strips "giving away" prefix', () => {
    expect(extractTitle('giving away box of books', 'Offer')).toBe('Box of books')
  })
  it('strips "looking for" prefix', () => {
    expect(extractTitle('looking for a bike for my kid', 'Wanted')).toBe('Bike')
  })
  it('strips "anyone got" prefix', () => {
    expect(extractTitle('anyone got a table they dont need?', 'Wanted')).toBe('Table')
  })
  it('strips "need a" prefix', () => {
    expect(extractTitle('need a bookshelf, any size', 'Wanted')).toBe('Bookshelf')
  })
  it('strips "wanted:" prefix', () => {
    expect(extractTitle('wanted: garden tools', 'Wanted')).toBe('Garden tools')
  })
  it('truncates at 40 characters', () => {
    const long = 'got a really incredibly amazingly super duper long item name that goes on forever'
    const result = extractTitle(long, 'Offer')
    expect(result.length).toBeLessThanOrEqual(40)
  })
  it('capitalises first letter', () => {
    expect(extractTitle('got a sofa', 'Offer')).toBe('Sofa')
  })
  it('handles discussion type (no stripping)', () => {
    expect(extractTitle('has anyone used the tip lately?', 'Discussion')).toBe('Has anyone used the tip lately?')
  })
  it('handles empty string', () => {
    expect(extractTitle('', 'Offer')).toBe('')
  })
  it('strips article "a" after prefix', () => {
    expect(extractTitle('looking for a bike', 'Wanted')).toBe('Bike')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useTitleExtractor.spec.js`
Expected: FAIL — module not found

- [ ] **Step 3: Write minimal implementation**

```javascript
// composables/useTitleExtractor.js

const OFFER_PREFIXES = [
  /^got an?\s+/i,
  /^offering\s+(an?\s+)?/i,
  /^free to collect\s*[-–—]?\s*/i,
  /^giving away\s+(an?\s+)?/i,
  /^give away\s+(an?\s+)?/i,
  /^free[:\s]+/i,
]

const WANTED_PREFIXES = [
  /^looking for\s+(an?\s+)?/i,
  /^anyone got\s+(an?\s+)?/i,
  /^need\s+(an?\s+)?/i,
  /^wanted[:\s]+/i,
  /^does anyone have\s+(an?\s+)?/i,
  /^in search of\s+(an?\s+)?/i,
]

export function extractTitle(text, type) {
  if (!text) return ''

  let title = text.trim()

  if (type === 'Offer') {
    for (const prefix of OFFER_PREFIXES) {
      title = title.replace(prefix, '')
    }
  } else if (type === 'Wanted') {
    for (const prefix of WANTED_PREFIXES) {
      title = title.replace(prefix, '')
    }
  }

  // Take text up to first comma, full stop, or question mark
  const endMatch = title.match(/[,.\?]/)
  if (endMatch) {
    title = title.substring(0, endMatch.index)
  }

  // Truncate at 40 characters
  if (title.length > 40) {
    title = title.substring(0, 40).replace(/\s+\S*$/, '')
  }

  // Capitalise first letter
  title = title.trim()
  if (title.length > 0) {
    title = title.charAt(0).toUpperCase() + title.slice(1)
  }

  return title
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useTitleExtractor.spec.js`
Expected: All 13 tests PASS

- [ ] **Step 5: Commit**

```bash
git add freegle-mobile/composables/useTitleExtractor.js freegle-mobile/tests/composables/useTitleExtractor.spec.js
git commit -m "feat: add title extractor from freeform text (TDD)"
```

---

## Task 4: Taken Detector Composable (TDD)

**Files:**
- Create: `freegle-mobile/composables/useTakenDetector.js`
- Create: `freegle-mobile/tests/composables/useTakenDetector.spec.js`

- [ ] **Step 1: Write the failing tests**

```javascript
// tests/composables/useTakenDetector.spec.js
import { describe, it, expect } from 'vitest'
import { detectTaken } from '~/composables/useTakenDetector'

describe('detectTaken', () => {
  it('detects "collected"', () => {
    expect(detectTaken('All collected, thanks!')).toBe(true)
  })
  it('detects "picked up"', () => {
    expect(detectTaken('Picked up this morning')).toBe(true)
  })
  it('detects "got it"', () => {
    expect(detectTaken('Got it, thank you so much')).toBe(true)
  })
  it('detects "all done"', () => {
    expect(detectTaken('All done!')).toBe(true)
  })
  it('detects "thanks for the"', () => {
    expect(detectTaken('Thanks for the sofa')).toBe(true)
  })
  it('detects "thank you" standalone', () => {
    expect(detectTaken('Thank you!')).toBe(true)
  })
  it('does not trigger on "can I collect"', () => {
    expect(detectTaken('Can I collect tomorrow?')).toBe(false)
  })
  it('does not trigger on "I got your message"', () => {
    expect(detectTaken('I got your message about the table')).toBe(false)
  })
  it('does not trigger on casual thanks in negotiation', () => {
    expect(detectTaken('Thanks for getting back to me')).toBe(false)
  })
  it('does not trigger on empty string', () => {
    expect(detectTaken('')).toBe(false)
  })
  it('is case insensitive', () => {
    expect(detectTaken('COLLECTED thank you')).toBe(true)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useTakenDetector.spec.js`
Expected: FAIL — module not found

- [ ] **Step 3: Write minimal implementation**

```javascript
// composables/useTakenDetector.js

// Positive patterns — strong signals that a handover happened
const TAKEN_PATTERNS = [
  /\bcollected\b/i,
  /\bpicked up\b/i,
  /\ball done\b/i,
  /\bthanks for the\b/i,
]

// Weaker signals — only match if they appear to be standalone gratitude (end of conversation)
const GRATITUDE_PATTERNS = [
  /^(got it|thank you!?)$/i,
  /\bgot it,?\s*thank/i,
]

// Negative patterns — these override positive matches
const FALSE_POSITIVE_PATTERNS = [
  /\bcan i collect\b/i,
  /\bcollect (tomorrow|today|on|at|from)\b/i,
  /\bgot your (message|email|reply)\b/i,
  /\bthanks for (getting|replying|your|letting)\b/i,
]

export function detectTaken(text) {
  if (!text) return false

  // Check false positives first
  for (const pattern of FALSE_POSITIVE_PATTERNS) {
    if (pattern.test(text)) return false
  }

  // Check strong taken signals
  for (const pattern of TAKEN_PATTERNS) {
    if (pattern.test(text)) return true
  }

  // Check gratitude patterns
  for (const pattern of GRATITUDE_PATTERNS) {
    if (pattern.test(text)) return true
  }

  return false
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useTakenDetector.spec.js`
Expected: All 11 tests PASS

- [ ] **Step 5: Commit**

```bash
git add freegle-mobile/composables/useTakenDetector.js freegle-mobile/tests/composables/useTakenDetector.spec.js
git commit -m "feat: add taken/received detector heuristics (TDD)"
```

---

## Task 5: Feed Filter Composable (TDD)

**Files:**
- Create: `freegle-mobile/composables/useFeedFilter.js`
- Create: `freegle-mobile/tests/composables/useFeedFilter.spec.js`

- [ ] **Step 1: Write the failing tests**

```javascript
// tests/composables/useFeedFilter.spec.js
import { describe, it, expect } from 'vitest'
import { filterFeed, searchFeed } from '~/composables/useFeedFilter'

const mockItems = [
  { id: 1, type: 'Offer', title: 'Sofa', description: 'Comfy sofa', taken: false },
  { id: 2, type: 'Wanted', title: 'Bike', description: 'Kids bike', taken: false },
  { id: 3, type: 'Discussion', title: 'Tip hours?', description: 'Anyone know?', taken: false },
  { id: 4, type: 'Offer', title: 'Table', description: 'Pine table', taken: true },
  { id: 5, type: 'Offer', title: 'Books', description: 'Box of fiction books', taken: false },
]

describe('filterFeed', () => {
  it('returns all items for "All" filter', () => {
    expect(filterFeed(mockItems, 'All')).toHaveLength(5)
  })
  it('filters to Offers only', () => {
    const result = filterFeed(mockItems, 'Offer')
    expect(result.every(i => i.type === 'Offer' || i.taken)).toBe(true)
  })
  it('filters to Wanteds only', () => {
    const result = filterFeed(mockItems, 'Wanted')
    expect(result.every(i => i.type === 'Wanted')).toBe(true)
  })
  it('filters to Discussion only', () => {
    const result = filterFeed(mockItems, 'Discussion')
    expect(result.every(i => i.type === 'Discussion')).toBe(true)
  })
  it('always includes taken items in their parent type', () => {
    const result = filterFeed(mockItems, 'Offer')
    expect(result.find(i => i.id === 4)).toBeTruthy()
  })
})

describe('searchFeed', () => {
  it('filters by title match', () => {
    const result = searchFeed(mockItems, 'sofa')
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe(1)
  })
  it('filters by description match', () => {
    const result = searchFeed(mockItems, 'fiction')
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe(5)
  })
  it('is case insensitive', () => {
    expect(searchFeed(mockItems, 'BIKE')).toHaveLength(1)
  })
  it('returns all for empty search', () => {
    expect(searchFeed(mockItems, '')).toHaveLength(5)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useFeedFilter.spec.js`
Expected: FAIL — module not found

- [ ] **Step 3: Write minimal implementation**

```javascript
// composables/useFeedFilter.js

export function filterFeed(items, typeFilter) {
  if (!typeFilter || typeFilter === 'All') return items

  return items.filter((item) => {
    // Taken items show in their parent type filter
    if (item.taken) return item.type === typeFilter
    return item.type === typeFilter
  })
}

export function searchFeed(items, query) {
  if (!query) return items

  const q = query.toLowerCase()
  return items.filter((item) => {
    const title = (item.title || '').toLowerCase()
    const desc = (item.description || '').toLowerCase()
    return title.includes(q) || desc.includes(q)
  })
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/composables/useFeedFilter.spec.js`
Expected: All 9 tests PASS

- [ ] **Step 5: Commit**

```bash
git add freegle-mobile/composables/useFeedFilter.js freegle-mobile/tests/composables/useFeedFilter.spec.js
git commit -m "feat: add feed filter and search composables (TDD)"
```

---

## Task 6: Mobile Layout + Header + Ad Banner

**Files:**
- Create: `freegle-mobile/layouts/default.vue`
- Create: `freegle-mobile/components/MobileHeader.vue`
- Create: `freegle-mobile/components/AdBanner.vue`
- Create: `freegle-mobile/assets/css/mobile.scss`

- [ ] **Step 1: Create mobile.scss with design tokens**

```scss
// assets/css/mobile.scss
// Mobile-specific styles extending iznik-nuxt3 design tokens

:root {
  --mobile-header-height: 48px;
  --mobile-search-height: 40px;
  --mobile-composer-height: 48px;
  --mobile-ad-height: 50px;
  --mobile-filter-height: 36px;

  // Feed card colours
  --feed-offer-bg: #f0f9e8;
  --feed-offer-badge: #338808;
  --feed-wanted-bg: #e8f0fd;
  --feed-wanted-badge: #2563eb;
  --feed-discussion-bg: #f5f5f5;
  --feed-taken-bg: #f5f5f5;

  // Chat colours
  --chat-header-bg: #1e40af;
  --chat-outgoing-bg: #f0f9e8;
  --chat-incoming-bg: #f0f0f0;

  // Header
  --header-bg: #1d6607;
}

* {
  box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
}

html, body {
  margin: 0;
  padding: 0;
  overflow: hidden;
  height: 100%;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

#__nuxt {
  height: 100%;
}

.mobile-app {
  display: flex;
  flex-direction: column;
  height: 100%;
  max-width: 428px;
  margin: 0 auto;
  background: #fff;
  position: relative;
}

.mobile-content {
  flex: 1;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}
```

- [ ] **Step 2: Create MobileHeader.vue**

```vue
<template>
  <header class="mobile-header">
    <div class="mobile-header__logo">
      <img src="/icon.png" alt="Freegle" class="mobile-header__icon" />
    </div>
    <div class="mobile-header__location">
      {{ locationLabel }}
    </div>
    <button class="mobile-header__chat" @click="$emit('open-chats')">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span v-if="unreadCount > 0" class="mobile-header__badge">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
    </button>
    <button class="mobile-header__menu" @click="$emit('open-settings')">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
        <circle cx="12" cy="5" r="2"/>
        <circle cx="12" cy="12" r="2"/>
        <circle cx="12" cy="19" r="2"/>
      </svg>
    </button>
  </header>
</template>

<script setup>
defineProps({
  locationLabel: { type: String, default: '' },
  unreadCount: { type: Number, default: 0 },
})

defineEmits(['open-chats', 'open-settings'])
</script>

<style scoped lang="scss">
.mobile-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  height: var(--mobile-header-height);
  background: var(--header-bg);
  color: white;
  flex-shrink: 0;

  &__icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
  }

  &__location {
    flex: 1;
    font-size: 0.85rem;
    opacity: 0.9;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__chat {
    position: relative;
    background: none;
    border: none;
    color: white;
    padding: 4px;
    cursor: pointer;
  }

  &__badge {
    position: absolute;
    top: -2px;
    right: -4px;
    background: #ef4444;
    color: white;
    font-size: 0.6rem;
    font-weight: 700;
    padding: 1px 4px;
    border-radius: 8px;
    min-width: 16px;
    text-align: center;
  }

  &__menu {
    background: none;
    border: none;
    color: white;
    padding: 4px;
    cursor: pointer;
  }
}
</style>
```

- [ ] **Step 3: Create AdBanner.vue**

```vue
<template>
  <div class="ad-banner">
    <div class="ad-banner__placeholder">
      Ad
    </div>
  </div>
</template>

<style scoped>
.ad-banner {
  height: var(--mobile-ad-height);
  background: #f9f9f9;
  border-top: 1px solid #e0e0e0;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.ad-banner__placeholder {
  font-size: 0.7rem;
  color: #999;
  text-transform: uppercase;
  letter-spacing: 0.1em;
}
</style>
```

- [ ] **Step 4: Create default layout**

```vue
<template>
  <div class="mobile-app">
    <slot />
    <AdBanner />
  </div>
</template>

<script setup>
import AdBanner from '~/components/AdBanner.vue'
</script>
```

- [ ] **Step 5: Verify dev server starts**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx nuxt dev --port 3001`
Expected: Server starts on port 3001, shows the layout shell

- [ ] **Step 6: Commit**

```bash
git add freegle-mobile/layouts/ freegle-mobile/components/MobileHeader.vue freegle-mobile/components/AdBanner.vue freegle-mobile/assets/
git commit -m "feat: add mobile layout, header with logo/chat badge, ad banner"
```

---

## Task 7: Location Picker Page

**Files:**
- Create: `freegle-mobile/pages/index.vue`
- Create: `freegle-mobile/components/LocationPicker.vue`

- [ ] **Step 1: Create LocationPicker.vue**

```vue
<template>
  <div class="location-picker">
    <div class="location-picker__hero">
      <img src="/icon.png" alt="Freegle" class="location-picker__logo" />
      <h1 class="location-picker__title">Freegle</h1>
      <p class="location-picker__subtitle">Free stuff near you</p>
    </div>

    <div class="location-picker__actions">
      <button class="location-picker__gps" @click="useGps" :disabled="locating">
        <span v-if="locating">Finding you...</span>
        <span v-else>Use my location</span>
      </button>

      <div class="location-picker__divider">or</div>

      <div class="location-picker__postcode">
        <input
          v-model="postcode"
          type="text"
          placeholder="Enter your postcode"
          class="location-picker__input"
          @keydown.enter="submitPostcode"
        />
        <button class="location-picker__go" @click="submitPostcode" :disabled="!postcode.trim()">
          Go
        </button>
      </div>
    </div>

    <p v-if="error" class="location-picker__error">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const emit = defineEmits(['located'])

const postcode = ref('')
const locating = ref(false)
const error = ref('')

async function useGps() {
  if (!navigator.geolocation) {
    error.value = 'Location not available on this device'
    return
  }

  locating.value = true
  error.value = ''

  navigator.geolocation.getCurrentPosition(
    (position) => {
      locating.value = false
      emit('located', {
        lat: position.coords.latitude,
        lng: position.coords.longitude,
        type: 'gps',
      })
    },
    (err) => {
      locating.value = false
      error.value = 'Could not get your location. Try entering a postcode instead.'
    },
    { timeout: 10000 }
  )
}

function submitPostcode() {
  if (!postcode.value.trim()) return
  error.value = ''
  emit('located', {
    postcode: postcode.value.trim(),
    type: 'postcode',
  })
}
</script>

<style scoped lang="scss">
.location-picker {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - var(--mobile-ad-height));
  padding: 2rem 1.5rem;
  text-align: center;

  &__hero {
    margin-bottom: 2rem;
  }

  &__logo {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin-bottom: 1rem;
  }

  &__title {
    font-size: 1.8rem;
    font-weight: 700;
    color: #338808;
    margin: 0;
  }

  &__subtitle {
    font-size: 1rem;
    color: #666;
    margin: 0.5rem 0 0;
  }

  &__actions {
    width: 100%;
    max-width: 300px;
  }

  &__gps {
    width: 100%;
    padding: 0.75rem;
    background: #338808;
    color: white;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;

    &:disabled {
      opacity: 0.6;
    }
  }

  &__divider {
    color: #999;
    margin: 1rem 0;
    font-size: 0.85rem;
  }

  &__postcode {
    display: flex;
    gap: 8px;
  }

  &__input {
    flex: 1;
    padding: 0.6rem 0.75rem;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
    outline: none;

    &:focus {
      border-color: #338808;
    }
  }

  &__go {
    padding: 0.6rem 1rem;
    background: #338808;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;

    &:disabled {
      opacity: 0.4;
    }
  }

  &__error {
    color: #ef4444;
    font-size: 0.85rem;
    margin-top: 1rem;
  }
}
</style>
```

- [ ] **Step 2: Create index page**

```vue
<template>
  <LocationPicker @located="onLocated" />
</template>

<script setup>
import LocationPicker from '~/components/LocationPicker.vue'

const router = useRouter()

function onLocated(location) {
  // Store location in a cookie or localStorage for persistence
  localStorage.setItem('freegle-mobile-location', JSON.stringify(location))
  router.push('/feed')
}
</script>
```

- [ ] **Step 3: Verify page renders**

Open Chrome MCP at `http://localhost:3001` on a 375px viewport. Verify: Freegle logo, "Where are you?" flow, GPS button, postcode input, ad banner at bottom.

- [ ] **Step 4: Commit**

```bash
git add freegle-mobile/pages/index.vue freegle-mobile/components/LocationPicker.vue
git commit -m "feat: add location picker onboarding page"
```

---

## Task 8: Feed Page + Post Cards

**Files:**
- Create: `freegle-mobile/pages/feed.vue`
- Create: `freegle-mobile/components/FeedCard.vue`
- Create: `freegle-mobile/components/FeedEmpty.vue`
- Create: `freegle-mobile/components/FeedSearch.vue`
- Create: `freegle-mobile/components/FeedFilter.vue`
- Create: `freegle-mobile/components/ThreeDotMenu.vue`
- Create: `freegle-mobile/tests/components/FeedCard.spec.js`

- [ ] **Step 1: Write FeedCard tests**

```javascript
// tests/components/FeedCard.spec.js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FeedCard from '~/components/FeedCard.vue'

describe('FeedCard', () => {
  const offerPost = {
    id: 1,
    type: 'Offer',
    title: 'Sofa',
    description: 'Comfy sofa, collection BA1',
    userName: 'Alice',
    groupName: 'Freegle Bath',
    timeAgo: '2h',
    imageUrl: null,
    taken: false,
    takenBy: null,
  }

  it('renders offer card with green tint', () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    expect(wrapper.find('.feed-card--offer').exists()).toBe(true)
    expect(wrapper.find('.feed-card__badge').text()).toBe('OFFER')
  })

  it('renders wanted card with blue tint', () => {
    const wanted = { ...offerPost, type: 'Wanted' }
    const wrapper = mount(FeedCard, { props: { post: wanted } })
    expect(wrapper.find('.feed-card--wanted').exists()).toBe(true)
    expect(wrapper.find('.feed-card__badge').text()).toBe('WANTED')
  })

  it('renders taken card as collapsed line', () => {
    const taken = { ...offerPost, taken: true, takenBy: 'Carol' }
    const wrapper = mount(FeedCard, { props: { post: taken } })
    expect(wrapper.find('.feed-card--taken').exists()).toBe(true)
    expect(wrapper.text()).toContain('TAKEN')
    expect(wrapper.text()).toContain('Carol')
  })

  it('renders discussion card with no badge', () => {
    const discussion = { ...offerPost, type: 'Discussion' }
    const wrapper = mount(FeedCard, { props: { post: discussion } })
    expect(wrapper.find('.feed-card--discussion').exists()).toBe(true)
    expect(wrapper.find('.feed-card__badge').exists()).toBe(false)
  })

  it('shows reply button for non-taken posts', () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    expect(wrapper.find('.feed-card__reply').exists()).toBe(true)
  })

  it('emits reply event on button click', async () => {
    const wrapper = mount(FeedCard, { props: { post: offerPost } })
    await wrapper.find('.feed-card__reply').trigger('click')
    expect(wrapper.emitted('reply')).toBeTruthy()
    expect(wrapper.emitted('reply')[0][0]).toBe(1)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/FeedCard.spec.js`
Expected: FAIL

- [ ] **Step 3: Create FeedCard.vue**

```vue
<template>
  <!-- Taken: collapsed single line -->
  <div v-if="post.taken" class="feed-card feed-card--taken">
    <span class="feed-card__taken-icon">🎉</span>
    <span class="feed-card__taken-title">{{ post.title }}</span>
    <span class="feed-card__taken-sep">&mdash;</span>
    <span class="feed-card__taken-label">TAKEN by {{ post.takenBy }}</span>
    <span class="feed-card__taken-time">&bull; {{ post.timeAgo }}</span>
  </div>

  <!-- Normal post card -->
  <div v-else class="feed-card" :class="`feed-card--${post.type.toLowerCase()}`">
    <div class="feed-card__body">
      <div v-if="post.imageUrl" class="feed-card__thumb">
        <img :src="post.imageUrl" :alt="post.title" />
      </div>
      <div class="feed-card__content">
        <div class="feed-card__top">
          <span v-if="post.type !== 'Discussion'" class="feed-card__badge" :class="`feed-card__badge--${post.type.toLowerCase()}`">
            {{ post.type.toUpperCase() }}
          </span>
          <span class="feed-card__title">{{ post.title }}</span>
          <button class="feed-card__dots" @click.stop="showMenu = !showMenu">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/>
            </svg>
          </button>
        </div>
        <p class="feed-card__desc">{{ post.description }}</p>
        <div class="feed-card__meta">
          {{ post.userName }} &bull; {{ post.groupName }} &bull; {{ post.timeAgo }}
        </div>
      </div>
    </div>
    <div class="feed-card__actions">
      <button class="feed-card__reply" @click="$emit('reply', post.id)">Reply</button>
    </div>

    <ThreeDotMenu v-if="showMenu" @close="showMenu = false" @report="$emit('report', post.id)" @hide="$emit('hide', post.id)" />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import ThreeDotMenu from './ThreeDotMenu.vue'

defineProps({
  post: { type: Object, required: true },
})

defineEmits(['reply', 'report', 'hide'])

const showMenu = ref(false)
</script>

<style scoped lang="scss">
.feed-card {
  border-radius: 8px;
  padding: 10px 12px;
  margin: 6px 12px;

  &--offer { background: var(--feed-offer-bg); }
  &--wanted { background: var(--feed-wanted-bg); }
  &--discussion { background: var(--feed-discussion-bg); }

  &--taken {
    background: var(--feed-taken-bg);
    color: #999;
    font-size: 0.8rem;
    padding: 6px 12px;
    margin: 2px 12px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
  }

  &__taken-title { font-weight: 600; }
  &__taken-label { font-weight: 600; }
  &__taken-time { font-size: 0.75rem; }

  &__body {
    display: flex;
    gap: 8px;
  }

  &__thumb {
    width: 56px;
    height: 56px;
    border-radius: 6px;
    overflow: hidden;
    flex-shrink: 0;

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  }

  &__content {
    flex: 1;
    min-width: 0;
  }

  &__top {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  &__badge {
    font-size: 0.6rem;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: 3px;
    color: white;
    flex-shrink: 0;

    &--offer { background: var(--feed-offer-badge); }
    &--wanted { background: var(--feed-wanted-badge); }
  }

  &__title {
    font-weight: 600;
    font-size: 0.9rem;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__dots {
    background: none;
    border: none;
    color: #999;
    padding: 2px;
    cursor: pointer;
    flex-shrink: 0;
  }

  &__desc {
    font-size: 0.8rem;
    color: #555;
    margin: 2px 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  &__meta {
    font-size: 0.7rem;
    color: #999;
  }

  &__actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 6px;
  }

  &__reply {
    font-size: 0.75rem;
    padding: 3px 14px;
    border-radius: 12px;
    background: white;
    cursor: pointer;
    font-weight: 500;
  }

  &--offer &__reply {
    border: 1px solid var(--feed-offer-badge);
    color: var(--feed-offer-badge);
  }

  &--wanted &__reply {
    border: 1px solid var(--feed-wanted-badge);
    color: var(--feed-wanted-badge);
  }

  &--discussion &__reply {
    border: 1px solid #999;
    color: #666;
  }
}
</style>
```

- [ ] **Step 4: Create ThreeDotMenu.vue**

```vue
<template>
  <div class="dot-menu" @click.stop>
    <div class="dot-menu__backdrop" @click="$emit('close')"></div>
    <div class="dot-menu__panel">
      <button @click="$emit('report'); $emit('close')">Report</button>
      <button @click="$emit('hide'); $emit('close')">Hide</button>
      <button @click="$emit('close')">Cancel</button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['close', 'report', 'hide'])
</script>

<style scoped>
.dot-menu__backdrop {
  position: fixed; inset: 0; z-index: 99;
}
.dot-menu__panel {
  position: absolute; right: 0; top: 100%; z-index: 100;
  background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  overflow: hidden; min-width: 140px;
}
.dot-menu__panel button {
  display: block; width: 100%; padding: 10px 16px; border: none; background: none;
  text-align: left; font-size: 0.85rem; cursor: pointer;
}
.dot-menu__panel button:hover { background: #f5f5f5; }
</style>
```

- [ ] **Step 5: Create FeedSearch.vue, FeedFilter.vue, FeedEmpty.vue**

```vue
<!-- components/FeedSearch.vue -->
<template>
  <div class="feed-search">
    <input
      :value="modelValue"
      @input="$emit('update:modelValue', $event.target.value)"
      type="text"
      placeholder="Search items..."
      class="feed-search__input"
    />
  </div>
</template>

<script setup>
defineProps({ modelValue: String })
defineEmits(['update:modelValue'])
</script>

<style scoped>
.feed-search {
  padding: 6px 12px;
  background: #f9f9f9;
  flex-shrink: 0;
}
.feed-search__input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #e0e0e0;
  border-radius: 20px;
  font-size: 0.85rem;
  outline: none;
  background: white;
}
.feed-search__input:focus { border-color: #338808; }
</style>
```

```vue
<!-- components/FeedFilter.vue -->
<template>
  <div class="feed-filter">
    <div class="feed-filter__view">
      <button :class="{ active: view === 'feed' }" @click="$emit('update:view', 'feed')">Near me</button>
      <button :class="{ active: view === 'mine' }" @click="$emit('update:view', 'mine')">My stuff</button>
    </div>
    <div class="feed-filter__type">
      <button v-for="t in types" :key="t" :class="{ active: typeFilter === t }" @click="$emit('update:typeFilter', t)">{{ t }}</button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  view: String,
  typeFilter: String,
})
defineEmits(['update:view', 'update:typeFilter'])

const types = ['All', 'Offer', 'Wanted', 'Discussion']
</script>

<style scoped>
.feed-filter {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px;
  flex-shrink: 0;
  overflow-x: auto;
}
.feed-filter__view {
  display: flex;
  background: #f0f0f0;
  border-radius: 8px;
  overflow: hidden;
  flex-shrink: 0;
}
.feed-filter__view button {
  padding: 5px 12px;
  border: none;
  background: none;
  font-size: 0.75rem;
  font-weight: 500;
  cursor: pointer;
  white-space: nowrap;
}
.feed-filter__view button.active {
  background: #338808;
  color: white;
}
.feed-filter__type {
  display: flex;
  gap: 4px;
}
.feed-filter__type button {
  padding: 4px 10px;
  border: 1px solid #ddd;
  border-radius: 14px;
  background: white;
  font-size: 0.7rem;
  cursor: pointer;
  white-space: nowrap;
}
.feed-filter__type button.active {
  background: #338808;
  color: white;
  border-color: #338808;
}
</style>
```

```vue
<!-- components/FeedEmpty.vue -->
<template>
  <div class="feed-empty">
    <div class="feed-empty__icon">🌱</div>
    <h2>Nothing nearby yet</h2>
    <p>Be the first to offer something!</p>
  </div>
</template>

<style scoped>
.feed-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  text-align: center;
  color: #666;
  padding: 2rem;
}
.feed-empty__icon { font-size: 3rem; margin-bottom: 1rem; }
.feed-empty h2 { font-size: 1.1rem; margin: 0 0 0.5rem; color: #333; }
.feed-empty p { font-size: 0.9rem; margin: 0; }
</style>
```

- [ ] **Step 6: Create feed.vue page connecting everything**

```vue
<template>
  <div class="feed-page">
    <MobileHeader
      :location-label="locationLabel"
      :unread-count="unreadCount"
      @open-chats="showChats = true"
      @open-settings="showSettings = true"
    />

    <FeedSearch v-model="searchQuery" />
    <FeedFilter v-model:view="currentView" v-model:type-filter="typeFilter" />

    <div class="feed-page__content mobile-content">
      <FeedEmpty v-if="filteredItems.length === 0" />
      <template v-else>
        <FeedCard
          v-for="item in filteredItems"
          :key="item.id"
          :post="item"
          @reply="openReply"
        />
      </template>
    </div>

    <FeedComposer v-if="currentView === 'feed'" @submit="onCompose" />

    <!-- Chat slide-over (Task 10) -->
    <!-- Settings drawer (Task 12) -->
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import MobileHeader from '~/components/MobileHeader.vue'
import FeedSearch from '~/components/FeedSearch.vue'
import FeedFilter from '~/components/FeedFilter.vue'
import FeedCard from '~/components/FeedCard.vue'
import FeedEmpty from '~/components/FeedEmpty.vue'
import { filterFeed, searchFeed } from '~/composables/useFeedFilter'

const searchQuery = ref('')
const currentView = ref('feed')
const typeFilter = ref('All')
const showChats = ref(false)
const showSettings = ref(false)

// TODO: Wire up real data from message store + location
const locationLabel = ref('BA1')
const unreadCount = ref(0)

// Placeholder items — will be replaced with store data
const feedItems = ref([])

const filteredItems = computed(() => {
  let items = feedItems.value
  items = filterFeed(items, typeFilter.value)
  items = searchFeed(items, searchQuery.value)
  return items
})

function openReply(postId) {
  // TODO: Task 10 — open ChatSlideOver
}

function onCompose(data) {
  // TODO: Task 9 — handle compose flow
}
</script>

<style scoped>
.feed-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}
.feed-page__content {
  flex: 1;
  overflow-y: auto;
  padding-bottom: 8px;
}
</style>
```

- [ ] **Step 7: Run FeedCard tests**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/FeedCard.spec.js`
Expected: All 6 tests PASS

- [ ] **Step 8: Verify in browser with Chrome MCP**

Open `http://localhost:3001/feed` at 375px width. Verify: header with logo, search bar, filter toggles, empty state.

- [ ] **Step 9: Commit**

```bash
git add freegle-mobile/pages/feed.vue freegle-mobile/components/FeedCard.vue freegle-mobile/components/FeedSearch.vue freegle-mobile/components/FeedFilter.vue freegle-mobile/components/FeedEmpty.vue freegle-mobile/components/ThreeDotMenu.vue freegle-mobile/tests/components/FeedCard.spec.js
git commit -m "feat: add feed page with post cards, search, filters, three-dot menu"
```

---

## Task 9: Feed Composer + Confirm Step

**Files:**
- Create: `freegle-mobile/components/FeedComposer.vue`
- Create: `freegle-mobile/components/ConfirmPost.vue`
- Create: `freegle-mobile/tests/components/FeedComposer.spec.js`

- [ ] **Step 1: Write FeedComposer tests**

```javascript
// tests/components/FeedComposer.spec.js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FeedComposer from '~/components/FeedComposer.vue'

describe('FeedComposer', () => {
  it('renders composer bar with input and camera button', () => {
    const wrapper = mount(FeedComposer)
    expect(wrapper.find('.composer__input').exists()).toBe(true)
    expect(wrapper.find('.composer__camera').exists()).toBe(true)
  })

  it('emits submit with text when send is pressed', async () => {
    const wrapper = mount(FeedComposer)
    await wrapper.find('.composer__input').setValue('got a sofa')
    await wrapper.find('.composer__send').trigger('click')
    expect(wrapper.emitted('submit')).toBeTruthy()
    expect(wrapper.emitted('submit')[0][0]).toEqual({ text: 'got a sofa', image: null })
  })

  it('shows send button only when text is entered', async () => {
    const wrapper = mount(FeedComposer)
    expect(wrapper.find('.composer__send').exists()).toBe(false)
    await wrapper.find('.composer__input').setValue('hello')
    expect(wrapper.find('.composer__send').exists()).toBe(true)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/FeedComposer.spec.js`
Expected: FAIL

- [ ] **Step 3: Create FeedComposer.vue**

```vue
<template>
  <div class="composer">
    <button class="composer__camera" @click="openCamera">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="13" r="4"/><path d="M5 3v2"/>
      </svg>
    </button>
    <input
      v-model="text"
      type="text"
      class="composer__input"
      placeholder="Got something to offer or need?"
      @keydown.enter="submit"
    />
    <button v-if="text.trim()" class="composer__send" @click="submit">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
        <path d="M2 21l21-9L2 3v7l15 2-15 2z"/>
      </svg>
    </button>
    <input ref="fileInput" type="file" accept="image/*" capture="environment" style="display: none" @change="onFileSelected" />
  </div>
</template>

<script setup>
import { ref } from 'vue'

const emit = defineEmits(['submit'])

const text = ref('')
const image = ref(null)
const fileInput = ref(null)

function openCamera() {
  fileInput.value?.click()
}

function onFileSelected(e) {
  const file = e.target.files?.[0]
  if (file) {
    image.value = file
  }
}

function submit() {
  if (!text.value.trim()) return
  emit('submit', { text: text.value.trim(), image: image.value })
  text.value = ''
  image.value = null
}
</script>

<style scoped lang="scss">
.composer {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-top: 1px solid #e0e0e0;
  background: white;
  flex-shrink: 0;

  &__camera {
    background: none;
    border: none;
    color: #666;
    padding: 4px;
    cursor: pointer;
  }

  &__input {
    flex: 1;
    padding: 8px 14px;
    border: 1px solid #e0e0e0;
    border-radius: 20px;
    font-size: 0.85rem;
    outline: none;
    background: #f5f5f5;

    &:focus { border-color: #338808; background: white; }
  }

  &__send {
    background: #338808;
    color: white;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
  }
}
</style>
```

- [ ] **Step 4: Create ConfirmPost.vue**

```vue
<template>
  <div class="confirm-overlay">
    <div class="confirm-panel">
      <h2 class="confirm-panel__title">Does this look right?</h2>

      <div class="confirm-panel__card" :class="`confirm-panel__card--${type.toLowerCase()}`">
        <span class="confirm-panel__badge" :class="`confirm-panel__badge--${type.toLowerCase()}`">
          {{ type.toUpperCase() }}
        </span>
        <h3 class="confirm-panel__item-title">{{ title }}</h3>
        <img v-if="imagePreview" :src="imagePreview" class="confirm-panel__image" />
        <p class="confirm-panel__desc">{{ description }}</p>
      </div>

      <div class="confirm-panel__actions">
        <button class="confirm-panel__edit" @click="$emit('edit')">Edit</button>
        <button class="confirm-panel__post" @click="$emit('confirm')">Post it!</button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  type: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  imagePreview: { type: String, default: null },
})

defineEmits(['edit', 'confirm'])
</script>

<style scoped lang="scss">
.confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 200;
  padding: 1rem;
}

.confirm-panel {
  background: white;
  border-radius: 16px;
  padding: 1.5rem;
  max-width: 340px;
  width: 100%;

  &__title {
    text-align: center;
    font-size: 1.1rem;
    margin: 0 0 1rem;
  }

  &__card {
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 1rem;

    &--offer { background: var(--feed-offer-bg); border: 2px solid var(--feed-offer-badge); }
    &--wanted { background: var(--feed-wanted-bg); border: 2px solid var(--feed-wanted-badge); }
    &--discussion { background: var(--feed-discussion-bg); border: 2px solid #999; }
  }

  &__badge {
    font-size: 0.65rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 3px;
    color: white;

    &--offer { background: var(--feed-offer-badge); }
    &--wanted { background: var(--feed-wanted-badge); }
    &--discussion { background: #999; }
  }

  &__item-title {
    font-size: 1rem;
    margin: 6px 0;
  }

  &__image {
    width: 100%;
    border-radius: 6px;
    margin-bottom: 8px;
    max-height: 200px;
    object-fit: cover;
  }

  &__desc {
    font-size: 0.85rem;
    color: #555;
    margin: 0;
  }

  &__actions {
    display: flex;
    gap: 8px;
  }

  &__edit {
    flex: 1;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #f5f5f5;
    font-size: 0.9rem;
    cursor: pointer;
  }

  &__post {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 8px;
    background: #338808;
    color: white;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
  }
}
</style>
```

- [ ] **Step 5: Run tests**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/FeedComposer.spec.js`
Expected: All 3 tests PASS

- [ ] **Step 6: Commit**

```bash
git add freegle-mobile/components/FeedComposer.vue freegle-mobile/components/ConfirmPost.vue freegle-mobile/tests/components/FeedComposer.spec.js
git commit -m "feat: add feed composer bar and AI confirm overlay"
```

---

## Task 10: Chat Slide-Over + Messages

**Files:**
- Create: `freegle-mobile/components/ChatSlideOver.vue`
- Create: `freegle-mobile/components/ChatBubble.vue`
- Create: `freegle-mobile/components/ItemQuote.vue`
- Create: `freegle-mobile/components/TakenPrompt.vue`
- Create: `freegle-mobile/tests/components/ChatSlideOver.spec.js`

- [ ] **Step 1: Write ChatSlideOver test**

```javascript
// tests/components/ChatSlideOver.spec.js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ChatSlideOver from '~/components/ChatSlideOver.vue'

describe('ChatSlideOver', () => {
  const props = {
    visible: true,
    userName: 'Alice',
    userAvatar: null,
    quotedItem: { title: 'Sofa', type: 'Offer', imageUrl: null },
    messages: [
      { id: 1, text: 'Is the sofa still available?', outgoing: false, time: '14:30' },
      { id: 2, text: 'Yes! Any time after 3pm', outgoing: true, time: '14:32' },
    ],
  }

  it('shows blue header with lock icon', () => {
    const wrapper = mount(ChatSlideOver, { props })
    expect(wrapper.find('.chat-slide__header').exists()).toBe(true)
    expect(wrapper.text()).toContain('Private conversation')
  })

  it('shows quoted item at top', () => {
    const wrapper = mount(ChatSlideOver, { props })
    expect(wrapper.text()).toContain('Sofa')
    expect(wrapper.text()).toContain('OFFER')
  })

  it('renders message bubbles', () => {
    const wrapper = mount(ChatSlideOver, { props })
    const bubbles = wrapper.findAll('.chat-bubble')
    expect(bubbles).toHaveLength(2)
  })

  it('emits close on back button', async () => {
    const wrapper = mount(ChatSlideOver, { props })
    await wrapper.find('.chat-slide__back').trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('emits send on message submit', async () => {
    const wrapper = mount(ChatSlideOver, { props })
    await wrapper.find('.chat-slide__input').setValue('Great, see you then!')
    await wrapper.find('.chat-slide__send').trigger('click')
    expect(wrapper.emitted('send')).toBeTruthy()
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/ChatSlideOver.spec.js`
Expected: FAIL

- [ ] **Step 3: Create ItemQuote.vue**

```vue
<template>
  <div class="item-quote" :class="`item-quote--${item.type.toLowerCase()}`">
    <img v-if="item.imageUrl" :src="item.imageUrl" class="item-quote__thumb" />
    <div class="item-quote__text">
      <span class="item-quote__badge">{{ item.type.toUpperCase() }}</span>
      <span class="item-quote__title">{{ item.title }}</span>
    </div>
  </div>
</template>

<script setup>
defineProps({ item: { type: Object, required: true } })
</script>

<style scoped>
.item-quote {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 12px; margin: 8px 12px; border-radius: 6px;
  font-size: 0.8rem;
}
.item-quote--offer { background: #f0f9e8; border-left: 3px solid #338808; }
.item-quote--wanted { background: #e8f0fd; border-left: 3px solid #2563eb; }
.item-quote__thumb { width: 32px; height: 32px; border-radius: 4px; object-fit: cover; }
.item-quote__badge { font-weight: 700; font-size: 0.65rem; }
.item-quote__title { font-weight: 600; margin-left: 4px; }
</style>
```

- [ ] **Step 4: Create ChatBubble.vue**

```vue
<template>
  <div class="chat-bubble" :class="{ 'chat-bubble--outgoing': outgoing }">
    <p class="chat-bubble__text">{{ text }}</p>
    <span class="chat-bubble__time">{{ time }}</span>
  </div>
</template>

<script setup>
defineProps({
  text: String,
  time: String,
  outgoing: Boolean,
})
</script>

<style scoped>
.chat-bubble {
  max-width: 80%;
  padding: 8px 12px;
  border-radius: 12px;
  margin: 3px 12px;
  background: var(--chat-incoming-bg);
  align-self: flex-start;
  position: relative;
}
.chat-bubble--outgoing {
  background: var(--chat-outgoing-bg);
  align-self: flex-end;
  margin-left: auto;
}
.chat-bubble__text { font-size: 0.85rem; margin: 0; }
.chat-bubble__time { font-size: 0.6rem; color: #999; display: block; text-align: right; margin-top: 2px; }
</style>
```

- [ ] **Step 5: Create TakenPrompt.vue**

```vue
<template>
  <div class="taken-prompt">
    <span>Did this get collected?</span>
    <div class="taken-prompt__actions">
      <button class="taken-prompt__yes" @click="$emit('taken')">Yes</button>
      <button class="taken-prompt__no" @click="$emit('dismiss')">Not yet</button>
    </div>
  </div>
</template>

<script setup>
defineEmits(['taken', 'dismiss'])
</script>

<style scoped>
.taken-prompt {
  background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
  padding: 8px 12px; margin: 6px 12px; font-size: 0.8rem;
  display: flex; align-items: center; justify-content: space-between;
}
.taken-prompt__actions { display: flex; gap: 6px; }
.taken-prompt__yes, .taken-prompt__no {
  padding: 4px 12px; border-radius: 12px; border: none; font-size: 0.75rem; cursor: pointer;
}
.taken-prompt__yes { background: #338808; color: white; }
.taken-prompt__no { background: #f0f0f0; color: #666; }
</style>
```

- [ ] **Step 6: Create ChatSlideOver.vue**

```vue
<template>
  <Transition name="slide">
    <div v-if="visible" class="chat-slide">
      <header class="chat-slide__header">
        <button class="chat-slide__back" @click="$emit('close')">←</button>
        <div class="chat-slide__avatar">
          <img v-if="userAvatar" :src="userAvatar" />
          <div v-else class="chat-slide__avatar-placeholder" />
        </div>
        <div class="chat-slide__user">
          <div class="chat-slide__name">{{ userName }}</div>
          <div class="chat-slide__private">🔒 Private conversation</div>
        </div>
      </header>

      <ItemQuote v-if="quotedItem" :item="quotedItem" />

      <div class="chat-slide__messages">
        <template v-for="msg in messages" :key="msg.id">
          <ChatBubble :text="msg.text" :time="msg.time" :outgoing="msg.outgoing" />
          <TakenPrompt
            v-if="msg.showTakenPrompt"
            @taken="$emit('mark-taken', msg.relatedItemId)"
            @dismiss="msg.showTakenPrompt = false"
          />
        </template>
      </div>

      <div class="chat-slide__composer">
        <input v-model="newMessage" class="chat-slide__input" :placeholder="`Message ${userName}...`" @keydown.enter="send" />
        <button v-if="newMessage.trim()" class="chat-slide__send" @click="send">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>
        </button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref } from 'vue'
import ChatBubble from './ChatBubble.vue'
import ItemQuote from './ItemQuote.vue'
import TakenPrompt from './TakenPrompt.vue'

defineProps({
  visible: Boolean,
  userName: String,
  userAvatar: String,
  quotedItem: Object,
  messages: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'send', 'mark-taken'])

const newMessage = ref('')

function send() {
  if (!newMessage.value.trim()) return
  emit('send', newMessage.value.trim())
  newMessage.value = ''
}
</script>

<style scoped lang="scss">
.chat-slide {
  position: fixed;
  inset: 0;
  background: white;
  z-index: 300;
  display: flex;
  flex-direction: column;

  &__header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 12px;
    height: 48px;
    background: var(--chat-header-bg);
    color: white;
    flex-shrink: 0;
  }

  &__back {
    background: none; border: none; color: white;
    font-size: 1.2rem; cursor: pointer; padding: 4px;
  }

  &__avatar img, &__avatar-placeholder {
    width: 28px; height: 28px; border-radius: 50%;
  }
  &__avatar-placeholder { background: #3b82f6; }

  &__name { font-weight: 600; font-size: 0.85rem; }
  &__private { font-size: 0.65rem; opacity: 0.8; }

  &__messages {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    padding: 8px 0;
  }

  &__composer {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-top: 1px solid #e0e0e0;
    flex-shrink: 0;
  }

  &__input {
    flex: 1;
    padding: 8px 14px;
    border: 1px solid #e0e0e0;
    border-radius: 20px;
    font-size: 0.85rem;
    outline: none;
    background: #f5f5f5;

    &:focus { border-color: #2563eb; background: white; }
  }

  &__send {
    background: #2563eb; color: white; border: none;
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
  }
}

.slide-enter-active, .slide-leave-active {
  transition: transform 0.25s ease;
}
.slide-enter-from, .slide-leave-to {
  transform: translateX(100%);
}
</style>
```

- [ ] **Step 7: Run tests**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/ChatSlideOver.spec.js`
Expected: All 5 tests PASS

- [ ] **Step 8: Commit**

```bash
git add freegle-mobile/components/ChatSlideOver.vue freegle-mobile/components/ChatBubble.vue freegle-mobile/components/ItemQuote.vue freegle-mobile/components/TakenPrompt.vue freegle-mobile/tests/components/ChatSlideOver.spec.js
git commit -m "feat: add chat slide-over panel with message bubbles and taken prompt"
```

---

## Task 11: Chat List + Profile Sheet + Notification Choice + Donate Card

**Files:**
- Create: `freegle-mobile/components/ChatList.vue`
- Create: `freegle-mobile/components/ProfileSheet.vue`
- Create: `freegle-mobile/components/NotifyChoice.vue`
- Create: `freegle-mobile/components/DonateCard.vue`

- [ ] **Step 1: Create ChatList.vue**

```vue
<template>
  <Transition name="slide">
    <div v-if="visible" class="chat-list">
      <header class="chat-list__header">
        <button class="chat-list__back" @click="$emit('close')">←</button>
        <h2>Messages</h2>
      </header>
      <div class="chat-list__items">
        <div
          v-for="conv in conversations"
          :key="conv.userId"
          class="chat-list__row"
          @click="$emit('open-chat', conv.userId)"
        >
          <div class="chat-list__avatar">
            <img v-if="conv.avatar" :src="conv.avatar" />
            <div v-else class="chat-list__avatar-placeholder" />
          </div>
          <div class="chat-list__info">
            <div class="chat-list__name">{{ conv.userName }}</div>
            <div class="chat-list__preview">{{ conv.lastMessage }}</div>
          </div>
          <div class="chat-list__right">
            <div class="chat-list__time">{{ conv.timeAgo }}</div>
            <span v-if="conv.unread > 0" class="chat-list__badge">{{ conv.unread }}</span>
          </div>
          <img v-if="conv.itemThumb" :src="conv.itemThumb" class="chat-list__item-thumb" />
        </div>
        <div v-if="conversations.length === 0" class="chat-list__empty">
          No messages yet
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({
  visible: Boolean,
  conversations: { type: Array, default: () => [] },
})
defineEmits(['close', 'open-chat'])
</script>

<style scoped lang="scss">
.chat-list {
  position: fixed; inset: 0; background: white; z-index: 300;
  display: flex; flex-direction: column;

  &__header {
    display: flex; align-items: center; gap: 8px; padding: 0 12px;
    height: 48px; background: var(--chat-header-bg); color: white; flex-shrink: 0;
    h2 { font-size: 1rem; margin: 0; }
  }
  &__back { background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; }

  &__items { flex: 1; overflow-y: auto; }

  &__row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-bottom: 1px solid #f0f0f0; cursor: pointer;
    &:active { background: #f9f9f9; }
  }

  &__avatar img, &__avatar-placeholder { width: 40px; height: 40px; border-radius: 50%; }
  &__avatar-placeholder { background: #ddd; }

  &__info { flex: 1; min-width: 0; }
  &__name { font-weight: 600; font-size: 0.85rem; }
  &__preview { font-size: 0.75rem; color: #999; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  &__right { text-align: right; flex-shrink: 0; }
  &__time { font-size: 0.65rem; color: #999; }
  &__badge { background: #338808; color: white; font-size: 0.6rem; padding: 2px 6px; border-radius: 8px; margin-top: 2px; display: inline-block; }

  &__item-thumb { width: 32px; height: 32px; border-radius: 4px; object-fit: cover; flex-shrink: 0; }

  &__empty { text-align: center; color: #999; padding: 3rem; font-size: 0.9rem; }
}
.slide-enter-active, .slide-leave-active { transition: transform 0.25s ease; }
.slide-enter-from, .slide-leave-to { transform: translateX(100%); }
</style>
```

- [ ] **Step 2: Create ProfileSheet.vue**

```vue
<template>
  <Transition name="sheet">
    <div v-if="visible" class="profile-sheet" @click.self="$emit('close')">
      <div class="profile-sheet__panel">
        <div class="profile-sheet__avatar">
          <img v-if="user.avatar" :src="user.avatar" />
          <div v-else class="profile-sheet__avatar-placeholder" />
        </div>
        <h3 class="profile-sheet__name">{{ user.displayName }}</h3>
        <p class="profile-sheet__about">{{ user.aboutMe || 'Freegle member' }}</p>
        <button class="profile-sheet__close" @click="$emit('close')">Close</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({ visible: Boolean, user: { type: Object, default: () => ({}) } })
defineEmits(['close'])
</script>

<style scoped>
.profile-sheet { position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 400; display: flex; align-items: flex-end; }
.profile-sheet__panel { background: white; width: 100%; border-radius: 16px 16px 0 0; padding: 1.5rem; text-align: center; }
.profile-sheet__avatar img, .profile-sheet__avatar-placeholder { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 0.5rem; }
.profile-sheet__avatar-placeholder { background: #ddd; }
.profile-sheet__name { font-size: 1.1rem; margin: 0 0 0.25rem; }
.profile-sheet__about { font-size: 0.85rem; color: #666; margin: 0 0 1rem; }
.profile-sheet__close { padding: 8px 24px; border: 1px solid #ddd; border-radius: 8px; background: white; cursor: pointer; }
.sheet-enter-active, .sheet-leave-active { transition: transform 0.25s ease; }
.sheet-enter-from, .sheet-leave-to { transform: translateY(100%); }
</style>
```

- [ ] **Step 3: Create NotifyChoice.vue**

```vue
<template>
  <div class="notify-overlay">
    <div class="notify-panel">
      <div class="notify-panel__icon">🎉</div>
      <h2>Posted!</h2>
      <p>People nearby can see your post now</p>

      <div class="notify-panel__question">How should we tell you when someone replies?</div>

      <button class="notify-panel__option" @click="$emit('choose', 'push')">
        <span class="notify-panel__emoji">🔔</span>
        <div>
          <div class="notify-panel__label">Push notifications</div>
          <div class="notify-panel__desc">Instant alerts on your phone</div>
        </div>
      </button>

      <button class="notify-panel__option" @click="$emit('choose', 'email')">
        <span class="notify-panel__emoji">📧</span>
        <div>
          <div class="notify-panel__label">Email</div>
          <div class="notify-panel__desc">Get a message when someone replies</div>
        </div>
      </button>

      <p class="notify-panel__later">You can change this anytime in settings</p>
    </div>
  </div>
</template>

<script setup>
defineEmits(['choose'])
</script>

<style scoped>
.notify-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 500; padding: 1rem; }
.notify-panel { background: white; border-radius: 16px; padding: 1.5rem; max-width: 340px; width: 100%; text-align: center; }
.notify-panel__icon { font-size: 2rem; }
.notify-panel h2 { margin: 0.5rem 0 0.25rem; font-size: 1.2rem; }
.notify-panel p { color: #666; font-size: 0.85rem; margin: 0 0 1rem; }
.notify-panel__question { font-weight: 600; font-size: 0.9rem; margin-bottom: 0.75rem; }
.notify-panel__option { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; background: white; cursor: pointer; margin-bottom: 8px; text-align: left; }
.notify-panel__emoji { font-size: 1.3rem; }
.notify-panel__label { font-weight: 600; font-size: 0.85rem; }
.notify-panel__desc { font-size: 0.7rem; color: #666; }
.notify-panel__later { font-size: 0.7rem; color: #999; margin-top: 0.5rem; }
</style>
```

- [ ] **Step 4: Create DonateCard.vue**

```vue
<template>
  <div class="donate-card">
    <div class="donate-card__text">
      <span class="donate-card__icon">☕</span>
      {{ message }}
    </div>
    <button class="donate-card__btn" @click="$emit('donate')">Donate</button>
    <button class="donate-card__dismiss" @click="$emit('dismiss')">✕</button>
  </div>
</template>

<script setup>
defineProps({ message: { type: String, default: 'You saved something from landfill! Buy Freegle a coffee?' } })
defineEmits(['donate', 'dismiss'])
</script>

<style scoped>
.donate-card { display: flex; align-items: center; gap: 8px; background: #fef3c7; border-radius: 8px; padding: 8px 12px; margin: 6px 12px; }
.donate-card__icon { font-size: 1.1rem; }
.donate-card__text { flex: 1; font-size: 0.8rem; color: #92400e; }
.donate-card__btn { padding: 4px 12px; border-radius: 12px; border: none; background: #338808; color: white; font-size: 0.75rem; cursor: pointer; }
.donate-card__dismiss { background: none; border: none; color: #999; cursor: pointer; font-size: 0.9rem; }
</style>
```

- [ ] **Step 5: Commit**

```bash
git add freegle-mobile/components/ChatList.vue freegle-mobile/components/ProfileSheet.vue freegle-mobile/components/NotifyChoice.vue freegle-mobile/components/DonateCard.vue
git commit -m "feat: add chat list, profile sheet, notification choice, donate card"
```

---

## Task 12: Swipe Gestures + Settings Drawer

**Files:**
- Create: `freegle-mobile/composables/useSwipeGesture.js`
- Create: `freegle-mobile/components/SwipeableCard.vue`
- Create: `freegle-mobile/components/SettingsDrawer.vue`
- Create: `freegle-mobile/tests/components/SwipeableCard.spec.js`

- [ ] **Step 1: Create useSwipeGesture.js**

```javascript
// composables/useSwipeGesture.js
import { ref } from 'vue'

export function useSwipeGesture(options = {}) {
  const { threshold = 80, onSwipeLeft, onSwipeRight } = options

  const startX = ref(0)
  const currentX = ref(0)
  const swiping = ref(false)
  const offsetX = ref(0)

  function onTouchStart(e) {
    startX.value = e.touches[0].clientX
    swiping.value = true
  }

  function onTouchMove(e) {
    if (!swiping.value) return
    currentX.value = e.touches[0].clientX
    offsetX.value = currentX.value - startX.value
  }

  function onTouchEnd() {
    if (!swiping.value) return
    swiping.value = false

    if (offsetX.value < -threshold && onSwipeLeft) {
      onSwipeLeft()
    } else if (offsetX.value > threshold && onSwipeRight) {
      onSwipeRight()
    }

    offsetX.value = 0
  }

  return { offsetX, swiping, onTouchStart, onTouchMove, onTouchEnd }
}
```

- [ ] **Step 2: Create SwipeableCard.vue with test**

```javascript
// tests/components/SwipeableCard.spec.js
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SwipeableCard from '~/components/SwipeableCard.vue'

describe('SwipeableCard', () => {
  it('renders slot content', () => {
    const wrapper = mount(SwipeableCard, { slots: { default: '<div class="child">Hello</div>' } })
    expect(wrapper.find('.child').text()).toBe('Hello')
  })

  it('emits swipe-left on left swipe', async () => {
    const wrapper = mount(SwipeableCard)
    const el = wrapper.find('.swipeable')
    await el.trigger('touchstart', { touches: [{ clientX: 200 }] })
    await el.trigger('touchmove', { touches: [{ clientX: 50 }] })
    await el.trigger('touchend')
    expect(wrapper.emitted('swipe-left')).toBeTruthy()
  })
})
```

```vue
<!-- components/SwipeableCard.vue -->
<template>
  <div
    class="swipeable"
    :style="{ transform: `translateX(${offsetX}px)`, transition: swiping ? 'none' : 'transform 0.2s ease' }"
    @touchstart="onTouchStart"
    @touchmove="onTouchMove"
    @touchend="onTouchEnd"
  >
    <slot />
  </div>
</template>

<script setup>
import { useSwipeGesture } from '~/composables/useSwipeGesture'

const emit = defineEmits(['swipe-left', 'swipe-right'])

const { offsetX, swiping, onTouchStart, onTouchMove, onTouchEnd } = useSwipeGesture({
  onSwipeLeft: () => emit('swipe-left'),
  onSwipeRight: () => emit('swipe-right'),
})
</script>
```

- [ ] **Step 3: Create SettingsDrawer.vue**

```vue
<template>
  <Transition name="drawer">
    <div v-if="visible" class="settings-drawer">
      <header class="settings-drawer__header">
        <button @click="$emit('close')">←</button>
        <h2>Settings</h2>
      </header>
      <div class="settings-drawer__items">
        <button class="settings-drawer__item" @click="$emit('navigate', 'address-book')">Address Book</button>
        <button class="settings-drawer__item" @click="$emit('navigate', 'notifications')">Notification Preferences</button>
        <button class="settings-drawer__item" @click="$emit('navigate', 'location')">Change Location</button>
        <button class="settings-drawer__item" @click="$emit('navigate', 'data-export')">Export My Data</button>
        <button class="settings-drawer__item" @click="$emit('navigate', 'help')">Help</button>
        <button class="settings-drawer__item" @click="$emit('navigate', 'about')">About Freegle</button>
      </div>
    </div>
  </Transition>
</template>

<script setup>
defineProps({ visible: Boolean })
defineEmits(['close', 'navigate'])
</script>

<style scoped>
.settings-drawer { position: fixed; inset: 0; background: white; z-index: 300; display: flex; flex-direction: column; }
.settings-drawer__header { display: flex; align-items: center; gap: 8px; padding: 0 12px; height: 48px; background: var(--header-bg); color: white; flex-shrink: 0; }
.settings-drawer__header button { background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; }
.settings-drawer__header h2 { font-size: 1rem; margin: 0; }
.settings-drawer__items { flex: 1; overflow-y: auto; }
.settings-drawer__item { display: block; width: 100%; padding: 14px 16px; border: none; border-bottom: 1px solid #f0f0f0; background: none; text-align: left; font-size: 0.9rem; cursor: pointer; }
.settings-drawer__item:active { background: #f5f5f5; }
.drawer-enter-active, .drawer-leave-active { transition: transform 0.25s ease; }
.drawer-enter-from, .drawer-leave-to { transform: translateX(100%); }
</style>
```

- [ ] **Step 4: Run tests**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run tests/components/SwipeableCard.spec.js`
Expected: All 2 tests PASS

- [ ] **Step 5: Commit**

```bash
git add freegle-mobile/composables/useSwipeGesture.js freegle-mobile/components/SwipeableCard.vue freegle-mobile/components/SettingsDrawer.vue freegle-mobile/tests/components/SwipeableCard.spec.js
git commit -m "feat: add swipe gestures and settings drawer"
```

---

## Task 13: Wire Feed Page to Real Data

**Files:**
- Modify: `freegle-mobile/pages/feed.vue`

- [ ] **Step 1: Update feed.vue to use real stores**

Wire up the message store, chat store, location composables, and connect all the component slots (ChatSlideOver, ChatList, ConfirmPost, NotifyChoice, SettingsDrawer, ProfileSheet). Use the existing `useMessageStore`, `useChatStore`, `useNewsfeedStore` to fetch real data. Map API message objects to the FeedCard `post` prop format.

Key mappings:
- `message.type === 'Offer'` / `'Wanted'` → card type
- `message.subject` → title
- `message.textbody` → description
- `message.attachments?.[0]?.paththumb` → imageUrl
- `message.fromuser` → userName (fetch from user store)
- `message.groups?.[0]?.namedisplay` → groupName
- `message.outcomes?.length > 0` → taken
- Newsfeed items → Discussion type

- [ ] **Step 2: Connect location onboarding to API**

Use stored location from localStorage to call the message store browse/search endpoint with lat/lng or postcode. Redirect to `/` if no location stored.

- [ ] **Step 3: Connect chat slide-over to real chat store**

When Reply is tapped, use `useChatStore` to open/create a chat room, fetch messages, render in ChatSlideOver.

- [ ] **Step 4: Connect composer to real post API**

When ConfirmPost emits confirm, use `useComposeStore` to create the actual post via the API.

- [ ] **Step 5: Run all tests**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile && npx vitest run`
Expected: All tests PASS

- [ ] **Step 6: Verify in browser**

Open `http://localhost:3001` at 375px viewport. Verify: real data loads in feed, posts have photos, chat slide-over works.

- [ ] **Step 7: Commit**

```bash
git add freegle-mobile/pages/feed.vue
git commit -m "feat: wire feed page to real message, chat, and newsfeed stores"
```

---

## Task 14: Visual Polish with Chrome MCP

**Files:**
- Modify: various component CSS

- [ ] **Step 1: Screenshot all screens at 375px**

Use Chrome MCP to navigate through each screen and capture screenshots:
- Location picker (`/`)
- Feed with posts (`/feed`)
- Composer focused
- Confirm post overlay
- Chat slide-over
- Chat list
- Profile sheet
- Notification choice
- Settings drawer

- [ ] **Step 2: Fix spacing, typography, and colour issues**

Review screenshots and fix any visual issues. Verify:
- Cards have consistent padding
- Badges are readable
- Transitions are smooth
- Touch targets are minimum 44px
- Ad banner doesn't overlap composer
- Header logo is crisp

- [ ] **Step 3: Test at multiple widths**

Chrome MCP at 320px (iPhone SE), 375px (iPhone), 428px (iPhone Pro Max). Verify nothing breaks.

- [ ] **Step 4: Commit**

```bash
git add -A freegle-mobile/
git commit -m "fix: visual polish across all mobile screens"
```

---

## Task 15: Capture Screenshots + Remotion Video

**Files:**
- Create: `freegle-mobile/video/package.json`
- Create: `freegle-mobile/video/tsconfig.json`
- Create: `freegle-mobile/video/src/index.ts`
- Create: `freegle-mobile/video/src/Root.tsx`
- Create: `freegle-mobile/video/src/DemoVideo.tsx`
- Create: `freegle-mobile/video/src/PhoneMockup.tsx`
- Create: `freegle-mobile/video/src/Subtitle.tsx`

- [ ] **Step 1: Capture screenshots via Playwright**

Write a quick Playwright script that navigates the running prototype at 375px viewport, captures PNG screenshots for each scene:
1. Location picker
2. Feed with posts
3. Composer with text entered
4. Confirm post overlay
5. Chat slide-over with messages
6. Taken prompt in chat
7. "My stuff" view
8. Notification choice modal

Save to `freegle-mobile/video/public/screenshots/`.

- [ ] **Step 2: Create Remotion project**

```json
// video/package.json
{
  "name": "freegle-mobile-video",
  "scripts": {
    "preview": "remotion studio src/index.ts",
    "render": "remotion render src/index.ts FreegleMobileDemo out/freegle-mobile-demo.mp4"
  },
  "dependencies": {
    "@remotion/bundler": "^4.0.440",
    "@remotion/cli": "^4.0.440",
    "react": "^19.2.4",
    "react-dom": "^19.2.4",
    "remotion": "^4.0.440"
  },
  "devDependencies": {
    "@types/react": "^19.2.14",
    "typescript": "^6.0.2"
  }
}
```

- [ ] **Step 3: Create PhoneMockup.tsx** (reuse from repair logger)

Copy and adapt `/home/edward/device-logging/video/src/PhoneMockup.tsx`.

- [ ] **Step 4: Create Subtitle.tsx** (reuse from repair logger)

Copy and adapt `/home/edward/device-logging/video/src/Subtitle.tsx`.

- [ ] **Step 5: Create DemoVideo.tsx with scene definitions**

```typescript
const scenes = [
  { subtitle: '', durationSec: 3 },  // Title card
  { subtitle: 'Where are you?', img: '01-location.png', durationSec: 3 },
  { subtitle: 'See what\'s free near you', img: '02-feed.png', durationSec: 4 },
  { subtitle: 'Type what you want to give away...', img: '03-compose.png', durationSec: 3 },
  { subtitle: 'Confirm your post', img: '04-confirm.png', durationSec: 3 },
  { subtitle: 'Chat privately with people who reply', img: '05-reply.png', durationSec: 4 },
  { subtitle: 'Mark it as taken right in the chat', img: '06-taken.png', durationSec: 3 },
  { subtitle: 'Track your posts', img: '07-mystuff.png', durationSec: 3 },
  { subtitle: 'Choose how to get notified', img: '08-notify.png', durationSec: 3 },
  { subtitle: '', durationSec: 3 },  // Closing card
]
```

- [ ] **Step 6: Create Root.tsx and index.ts**

```typescript
// src/Root.tsx
import { Composition } from 'remotion'
import { DemoVideo } from './DemoVideo'

export const RemotionRoot = () => (
  <Composition id="FreegleMobileDemo" component={DemoVideo} durationInFrames={960} fps={30} width={1280} height={720} />
)
```

```typescript
// src/index.ts
import { registerRoot } from 'remotion'
import { RemotionRoot } from './Root'
registerRoot(RemotionRoot)
```

- [ ] **Step 7: Install deps and render video**

Run: `cd /home/edward/FreegleDockerWSL/freegle-mobile/video && npm install && npm run render`
Expected: `out/freegle-mobile-demo.mp4` generated

- [ ] **Step 8: Commit**

```bash
git add freegle-mobile/video/
git commit -m "feat: add Remotion demo video with phone mockup scenes"
```

---

## Summary

| Task | Description | Tests |
|------|-------------|-------|
| 1 | Project scaffold | - |
| 2 | AI classifier (TDD) | 12 |
| 3 | Title extractor (TDD) | 13 |
| 4 | Taken detector (TDD) | 11 |
| 5 | Feed filter (TDD) | 9 |
| 6 | Layout + header + ad banner | - |
| 7 | Location picker | - |
| 8 | Feed page + cards (TDD) | 6 |
| 9 | Composer + confirm (TDD) | 3 |
| 10 | Chat slide-over (TDD) | 5 |
| 11 | Chat list + profile + notify + donate | - |
| 12 | Swipe gestures + settings (TDD) | 2 |
| 13 | Wire to real data | - |
| 14 | Visual polish | - |
| 15 | Screenshots + Remotion video | - |

**Total: 15 tasks, 61 tests**
