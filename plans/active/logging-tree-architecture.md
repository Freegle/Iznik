# Logging Context Architecture

## Goal

Capture hierarchical context at log time so logs naturally group and display with useful breadcrumbs. Find things by searching, not by toggling views.

## Context Model

```
Session (browser tab / app instance)
  └── Page (navigation)
        └── Modal (can nest)
              └── Log Entry
```

Logs without session context (background jobs, cron, webhooks) appear at their timestamp position, ungrouped.

## What Gets Captured

| Field | Source | Example |
|-------|--------|---------|
| `session_id` | Generated on tab/app open | `sess_a1b2c3` |
| `page_id` | Generated on navigation | `page_x7y8z9` |
| `page_url` | Route path | `/browse` |
| `page_title` | Document title | `Browse Posts - Freegle` |
| `modal_stack` | Array of modal IDs | `["modal_abc"]` |
| `modal_names` | Human-readable names | `["ChatModal"]` |
| `site` | Build-time constant | `FD` or `MT` |

## Display

### With Context (browser/app logs)
```
┌─────────────────────────────────────────────────────────────────┐
│ 11:30:15  Session 11:30 > /browse > ChatModal                   │
│           User clicked "Reply"                                   │
├─────────────────────────────────────────────────────────────────┤
│ 11:30:16  Session 11:30 > /browse > ChatModal                   │
│           POST /api/chat - 45ms                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Without Context (server logs)
```
┌─────────────────────────────────────────────────────────────────┐
│ 11:30:17  [batch] autorepost                                    │
│           Reposted message #12345 for user #67890               │
└─────────────────────────────────────────────────────────────────┘
```

### Interleaved by Timestamp
Logs display in timestamp order. Server logs appear at their natural position regardless of surrounding session context.

```
11:30:15  Session 11:30 > /browse         User clicked "Post"
11:30:16  Session 11:30 > /browse         POST /api/message - 120ms
11:30:17  [batch] autorepost              Reposted #12345
11:30:18  Session 11:42 > /chats          User opened chat
11:30:19  Session 11:30 > /browse         Upload complete
```

### Grouping Duplicates
Consecutive identical logs (like job ad impressions) collapse:
```
11:30:15  Session 11:30 > /browse         Job Ad impressions (x47)
```

## Implementation

### 1. Client Context Store

**File**: `stores/loggingContext.js`

```javascript
import { defineStore } from 'pinia'

export const useLoggingContextStore = defineStore('loggingContext', {
  state: () => ({
    sessionId: null,
    pageId: null,
    pageUrl: null,
    pageTitle: null,
    modalStack: [],  // [{ id, name }]
    site: null,
  }),

  actions: {
    init(runtimeConfig) {
      if (process.server) return
      this.site = runtimeConfig.public.site  // 'FD' or 'MT'

      // Session persists within tab
      this.sessionId = sessionStorage.getItem('freegle_session')
      if (!this.sessionId) {
        this.sessionId = 'sess_' + Math.random().toString(36).slice(2, 10)
        sessionStorage.setItem('freegle_session', this.sessionId)
      }
    },

    startPage(route) {
      this.pageId = 'page_' + Math.random().toString(36).slice(2, 10)
      this.pageUrl = route.path
      this.modalStack = []

      // Capture title after render
      nextTick(() => {
        this.pageTitle = document.title
      })
    },

    pushModal(name) {
      const id = 'modal_' + Math.random().toString(36).slice(2, 8)
      this.modalStack.push({ id, name })
      return id
    },

    popModal() {
      return this.modalStack.pop()?.id
    },

    // Headers for API calls
    getHeaders() {
      return {
        'X-Freegle-Session': this.sessionId,
        'X-Freegle-Page': this.pageId,
        'X-Freegle-Modal': this.modalStack.map(m => m.id).join(','),
        'X-Freegle-Site': this.site,
      }
    },

    // Full context for logging
    getContext() {
      return {
        session_id: this.sessionId,
        page_id: this.pageId,
        page_url: this.pageUrl,
        page_title: this.pageTitle,
        modal_stack: this.modalStack.map(m => m.id),
        modal_names: this.modalStack.map(m => m.name),
        site: this.site,
      }
    },
  },
})
```

### 2. Navigation Plugin

**File**: `plugins/loggingContext.client.js`

```javascript
export default defineNuxtPlugin((nuxtApp) => {
  const router = useRouter()

  nuxtApp.hook('app:created', () => {
    const ctx = useLoggingContextStore()
    ctx.init(useRuntimeConfig())

    router.afterEach((to) => {
      ctx.startPage(to)
    })
  })
})
```

### 3. API Integration

Add headers to all API calls:

```javascript
// In API fetch wrapper
const ctx = useLoggingContextStore()
const response = await $fetch(url, {
  headers: {
    ...ctx.getHeaders(),
    ...otherHeaders,
  },
})
```

### 4. Modal Composable

```javascript
export function useModalContext(name) {
  const ctx = useLoggingContextStore()

  onMounted(() => ctx.pushModal(name))
  onUnmounted(() => ctx.popModal())
}

// Usage:
// useModalContext('ChatModal')
```

### 5. Server Extraction

**PHP**:
```php
$context = [
    'session_id' => $_SERVER['HTTP_X_FREEGLE_SESSION'] ?? null,
    'page_id' => $_SERVER['HTTP_X_FREEGLE_PAGE'] ?? null,
    'modal_stack' => array_filter(explode(',', $_SERVER['HTTP_X_FREEGLE_MODAL'] ?? '')),
    'site' => $_SERVER['HTTP_X_FREEGLE_SITE'] ?? null,
];
// Include in log entry
```

**Go**:
```go
context := LogContext{
    SessionID:  r.Header.Get("X-Freegle-Session"),
    PageID:     r.Header.Get("X-Freegle-Page"),
    ModalStack: strings.Split(r.Header.Get("X-Freegle-Modal"), ","),
    Site:       r.Header.Get("X-Freegle-Site"),
}
```

### 6. Log Entry Structure

```json
{
  "timestamp": "2025-12-19T11:30:15.000Z",
  "source": "api",
  "context": {
    "session_id": "sess_a1b2c3",
    "page_id": "page_x7y8z9",
    "page_url": "/browse",
    "modal_stack": ["modal_abc"],
    "modal_names": ["ChatModal"],
    "site": "FD"
  },
  "user_id": 12345,
  "action": "POST /api/chat",
  "duration_ms": 45
}
```

Server logs without context:
```json
{
  "timestamp": "2025-12-19T11:30:17.000Z",
  "source": "batch",
  "job": "autorepost",
  "message": "Reposted message #12345"
}
```

## Display Logic

```javascript
// Simple display - just sort by timestamp
const sortedLogs = logs.toSorted((a, b) =>
  new Date(b.timestamp) - new Date(a.timestamp)
)

// Generate breadcrumb for each log
function getBreadcrumb(log) {
  if (!log.context?.session_id) {
    // No context - show source
    return `[${log.source}] ${log.job || ''}`
  }

  const parts = []

  // Session time
  const sessionTime = formatTime(log.context.session_start || log.timestamp)
  parts.push(`Session ${sessionTime}`)

  // Page
  if (log.context.page_url) {
    parts.push(log.context.page_url)
  }

  // Modals
  for (const name of log.context.modal_names || []) {
    parts.push(name)
  }

  return parts.join(' > ')
}
```

## Search

Everything found by search:
- User ID
- Session ID
- Page URL
- Action/message text
- IP address
- Error text

No tree/flat toggle needed - search narrows to what you want.

## Files to Change

### iznik-nuxt3
- New: `stores/loggingContext.js`
- New: `plugins/loggingContext.client.js`
- New: `composables/useModalContext.js`
- Modify: API fetch to include headers
- Modify: `nuxt.config.js` - add `site` to runtimeConfig

### iznik-server (PHP)
- Modify: Extract context headers in API handler
- Modify: Include context in log entries

### iznik-server-go
- Modify: Extract context headers
- Modify: Include context in logs

### iznik-nuxt3-modtools
- Modify: `stores/systemlogs.js` - simplified display logic
- Modify: Log components - show breadcrumbs

## Interaction Capture

### Research Summary

| Approach | Pros | Cons |
|----------|------|------|
| [PostHog autocapture](https://posthog.com/docs/product-analytics/autocapture) | Mature, elements_chain, $el_text | External dependency, not Vue-aware |
| [rrweb session replay](https://github.com/rrweb-io/rrweb) | Full replay, used by PostHog/LogRocket | Heavy, overkill for logging |
| [autocapture.js](https://github.com/seeratawan01/autocapture.js) | Lightweight, open source | Not Vue-aware |
| [nuxt-posthog module](https://nuxt.com/modules/nuxt-posthog) | SSR-ready, Vue directives | Tied to PostHog service |
| Custom Vue-aware | Full control, component names | Build ourselves |

### Recommended: Custom Vue-Aware Capture

Build a lightweight capture system that:
1. Uses capture phase listeners (non-interfering)
2. Extracts Vue component names from DOM elements
3. Gets human-readable labels from text/aria-label
4. Tracks all interaction types
5. Integrates with our context system

### Implementation

**File**: `plugins/interactionCapture.client.js`

```javascript
export default defineNuxtPlugin((nuxtApp) => {
  if (process.server) return

  const ctx = useLoggingContextStore()

  // Capture phase - sees events before handlers, passive = non-blocking
  const options = { capture: true, passive: true }

  document.addEventListener('click', (e) => captureInteraction('click', e), options)
  document.addEventListener('dblclick', (e) => captureInteraction('dblclick', e), options)
  document.addEventListener('contextmenu', (e) => captureInteraction('rightclick', e), options)

  // Touch events
  document.addEventListener('touchstart', (e) => captureInteraction('touch', e), options)

  // Form interactions
  document.addEventListener('change', (e) => captureInteraction('change', e), options)
  document.addEventListener('submit', (e) => captureInteraction('submit', e), options)

  // Focus tracking
  document.addEventListener('focusin', (e) => captureInteraction('focus', e), options)

  // Scroll (throttled)
  let scrollTimeout
  document.addEventListener('scroll', () => {
    if (!scrollTimeout) {
      scrollTimeout = setTimeout(() => {
        captureInteraction('scroll', {
          target: document.scrollingElement,
          scrollY: window.scrollY,
          scrollPercent: Math.round((window.scrollY /
            (document.body.scrollHeight - window.innerHeight)) * 100)
        })
        scrollTimeout = null
      }, 500)
    }
  }, options)

  function captureInteraction(type, event) {
    const target = event.target
    if (!target || target === document.body) return

    const info = extractElementInfo(target)
    if (!info) return  // Ignore non-meaningful elements

    const entry = {
      type,
      ...info,
      timestamp: Date.now(),
      context: ctx.getContext(),
    }

    // Send to logging (non-blocking)
    queueMicrotask(() => sendToLog(entry))
  }
})
```

### Element Info Extraction

```javascript
function extractElementInfo(el) {
  // Find meaningful interactive element
  const interactive = el.closest(
    'button, a, [role="button"], input, select, textarea, ' +
    '[onclick], [tabindex]:not([tabindex="-1"]), label, ' +
    '.btn, [data-bs-toggle]'  // Bootstrap specifics
  )

  const target = interactive || el

  // Skip non-meaningful elements
  if (target === document.body || target === document.documentElement) {
    return null
  }

  return {
    // Vue component info
    component: getVueComponentName(target),

    // Human-readable label (priority order)
    label: getLabel(target),

    // Element identification
    tag: target.tagName.toLowerCase(),
    id: target.id || null,
    classes: getCleanClasses(target),

    // For links/buttons
    href: target.href ? stripQueryParams(target.href) : null,
    type: target.type || null,

    // Position info
    rect: getElementRect(target),
  }
}

function getVueComponentName(el) {
  // Vue 3: walk up to find component
  let current = el
  while (current && current !== document.body) {
    // Vue 3 attaches component to __vueParentComponent
    const vueComponent = current.__vueParentComponent
    if (vueComponent) {
      // Get component name from type
      const name = vueComponent.type?.name ||
                   vueComponent.type?.__name ||
                   vueComponent.type?.__file?.match(/([^/]+)\.vue$/)?.[1]
      if (name && !name.startsWith('_')) {
        return name
      }
    }
    current = current.parentElement
  }
  return null
}

function getLabel(el) {
  // Priority order for human-readable label
  return (
    el.getAttribute('aria-label') ||
    el.getAttribute('title') ||
    el.getAttribute('data-label') ||
    getVisibleText(el) ||
    el.getAttribute('alt') ||
    el.getAttribute('placeholder') ||
    el.getAttribute('name') ||
    null
  )
}

function getVisibleText(el) {
  // Get inner text, but smart about it
  const text = el.innerText?.trim()
  if (!text) return null

  // Clean up whitespace
  const clean = text.replace(/\s+/g, ' ')

  // Skip if too long (probably not a label)
  if (clean.length > 100) return null

  // Skip if looks like content not a label
  if (clean.includes('\n') && clean.length > 50) return null

  return clean
}

function getCleanClasses(el) {
  // Return meaningful classes, skip utility classes
  const skip = /^(d-|p-|m-|col-|row|container|flex|text-|bg-|border-)/
  return [...el.classList]
    .filter(c => !skip.test(c))
    .slice(0, 3)  // Limit to 3
    .join(' ') || null
}

function stripQueryParams(url) {
  try {
    const u = new URL(url)
    return u.pathname
  } catch {
    return url
  }
}

function getElementRect(el) {
  const rect = el.getBoundingClientRect()
  return {
    x: Math.round(rect.x),
    y: Math.round(rect.y),
    w: Math.round(rect.width),
    h: Math.round(rect.height),
  }
}
```

### Log Entry Example

```json
{
  "timestamp": "2025-12-19T11:30:15.123Z",
  "type": "click",
  "component": "ChatReplyButton",
  "label": "Reply",
  "tag": "button",
  "classes": "btn-primary",
  "rect": { "x": 450, "y": 320, "w": 80, "h": 36 },
  "context": {
    "session_id": "sess_a1b2c3",
    "page_id": "page_x7y8z9",
    "page_url": "/chats/12345",
    "modal_stack": [],
    "site": "FD"
  }
}
```

### Visual Component Preview (Future)

For the details modal, we could:
1. Store element rect at capture time
2. Take a viewport screenshot periodically (rrweb-style)
3. Overlay a highlight box on the stored rect
4. Or: Store component name + props, render a mini preview

Simpler approach for now: Show component name + label prominently in the log display.

### Privacy Considerations

```javascript
// Safelist - never capture values from these
const sensitiveSelectors = [
  'input[type="password"]',
  'input[type="email"]',
  'input[name*="card"]',
  'input[name*="cvv"]',
  '[data-sensitive]',
]

function isSensitive(el) {
  return sensitiveSelectors.some(sel => el.matches(sel))
}

// In captureInteraction:
if (isSensitive(target)) {
  info.label = '[redacted]'
  info.value = null
}
```

### Swipe/Gesture Detection

```javascript
let touchStart = null

document.addEventListener('touchstart', (e) => {
  touchStart = {
    x: e.touches[0].clientX,
    y: e.touches[0].clientY,
    time: Date.now(),
  }
}, options)

document.addEventListener('touchend', (e) => {
  if (!touchStart) return

  const dx = e.changedTouches[0].clientX - touchStart.x
  const dy = e.changedTouches[0].clientY - touchStart.y
  const dt = Date.now() - touchStart.time

  // Detect swipe (>50px in <300ms)
  if (dt < 300 && (Math.abs(dx) > 50 || Math.abs(dy) > 50)) {
    const direction = Math.abs(dx) > Math.abs(dy)
      ? (dx > 0 ? 'right' : 'left')
      : (dy > 0 ? 'down' : 'up')

    captureInteraction('swipe', {
      target: e.target,
      direction,
      distance: Math.round(Math.sqrt(dx*dx + dy*dy)),
    })
  }

  touchStart = null
}, options)
```

## Not Changing

- Matomo (independent, does its own thing)
- Loki config (just JSON fields, no schema)
- Log retention policies

## Implementation Progress

### ✅ Completed

**Server Changes** (pushed to master):

1. **PHP API** (`iznik-server/include/misc/Loki.php`):
   - Added `x-freegle-session`, `x-freegle-page`, `x-freegle-modal`, `x-freegle-site` to allowed headers
   - Rewrote `getTraceHeaders()` to extract headers with mapping approach
   - Headers included as `freegle_session`, `freegle_page`, `freegle_modal`, `freegle_site` in logs

2. **Go API** (`iznik-server-go/misc/`):
   - Added headers to `allowedRequestHeaders` in `loki.go`
   - Added extraction in `lokiMiddleware.go` (lines 130-142)
   - Headers included in log entries sent to Loki

### ⏳ Pending

3. Client context store (`stores/loggingContext.js`)
4. Navigation plugin (`plugins/loggingContext.client.js`)
5. Interaction capture plugin (`plugins/interactionCapture.client.js`)
6. API fetch integration (add headers to all calls)
7. Modal lifecycle tracking (open/close/cancel events)
8. ModTools log display updates

## Migration Notes

- Old client logs without new context are ignored
- Server logs without session context (batch jobs, cron) still display by timestamp
