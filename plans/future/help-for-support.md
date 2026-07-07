# Help for Support: Enhanced Diagnostic Information Collection

## Problem Statement

A common problem for Mods/Support is that users don't provide screenshots or enough information when reporting issues. This makes debugging difficult and creates back-and-forth communication that delays resolution.

## Current State

Freegle currently uses:
- **Sentry** for error tracking (already integrated via `plugins/sentry.client.ts`)
- **SomethingWentWrong component** that displays error messages with stack traces
- **Help page** with device info display (mobile version, deviceuserinfo) and "Copy app and device info" button
- **Debug logs** available in the mobile app via DebugLogsModal

The current "Something went wrong" message tells users to "take a screenshot and contact Support" - this could be improved.

## Recommended Solutions

### Option 1: Sentry Session Replay (Recommended - Already Have Sentry)

**Why this is the best option for Freegle:**
- Already using Sentry for error tracking
- No new vendor to manage
- Integrated with existing error tracking
- Can use "error-only" mode to minimize overhead

**Performance Impact:**
- ~36-50KB gzipped additional bundle size
- CPU overhead: ~10-20% increase during heavy interactions (imperceptible to most users)
- Network: compressed data, ~0.017-0.07 seconds added to interactions
- Has built-in safeguards: stops recording if >750 DOM mutations, limits at 10,000+ mutations

**Configuration for Error-Only Recording:**
```typescript
// In sentry.client.ts
import { replayIntegration } from '@sentry/vue'

Sentry.init({
  // ... existing config ...
  replaysSessionSampleRate: 0,      // Don't record normal sessions
  replaysOnErrorSampleRate: 1.0,    // Record 100% of sessions with errors
  integrations: [
    // ... existing integrations ...
    replayIntegration({
      maskAllText: true,            // Privacy: mask all text by default
      blockAllMedia: true,          // Privacy: block images/video
      networkDetailAllowUrls: [     // Only capture Freegle API calls
        /ilovefreegle\.org/,
      ],
    }),
  ],
})
```

**How it works:**
- Buffers up to 1 minute of events prior to an error
- When an error occurs, the replay is uploaded to Sentry
- Links directly to the error in Sentry dashboard
- Volunteers can see exactly what the user was doing when the error occurred

**Privacy Considerations:**
- Default masking obscures all text with asterisks
- Images and media blocked by default
- Can add CSS classes `sentry-block`, `sentry-ignore`, `sentry-mask` to specific elements
- Network requests show URL/method/status but body content is masked unless explicitly allowed
- GDPR: User consent may be required - integrate with CookieYes

**Cost:**
- **Freegle is on the Sentry Sponsored Plan** (open source/nonprofit)
- Includes **100,000 replays/month** - more than sufficient
- No additional cost for Session Replay
- Error-only mode will use a small fraction of this quota

**Implementation Effort:** Low - just configuration changes

---

### Option 2: Enhanced Device Info Collection (Lightweight Alternative)

For cases where session replay is overkill, collect comprehensive device/browser info automatically.

**What to collect:**
- Browser name and version
- Operating system and version
- Screen resolution
- Device type (mobile/tablet/desktop)
- Network connection type
- JavaScript errors from console
- Recent API calls and their status
- Current URL/route
- User actions (last 10-20 clicks/navigations)
- Memory usage
- Page performance metrics (LCP, FID, CLS)

**Libraries:**
- **UAParser.js** (~15KB): Parse user agent for browser/OS/device detection
- **Platform.js** (~3KB): Similar but more lightweight
- Custom collection script for console logs and performance

**Implementation:**
```javascript
// composables/useDeviceInfo.js
export function useDeviceInfo() {
  return {
    browser: navigator.userAgent,
    screenSize: `${window.screen.width}x${window.screen.height}`,
    viewport: `${window.innerWidth}x${window.innerHeight}`,
    platform: navigator.platform,
    language: navigator.language,
    cookiesEnabled: navigator.cookieEnabled,
    online: navigator.onLine,
    deviceMemory: navigator.deviceMemory,
    hardwareConcurrency: navigator.hardwareConcurrency,
    connection: navigator.connection?.effectiveType,
    timestamp: new Date().toISOString(),
    url: window.location.href,
  }
}
```

**How to share with Support:**
1. Generate a unique "debug token" when error occurs
2. Upload device info + recent console logs to server
3. Show user the token and include in email/chat
4. Volunteers can look up the token to see full context

**Performance Impact:** Minimal - just reading browser APIs

**Implementation Effort:** Medium

---

### Option 3: Third-Party Feedback Tools

**Jam.dev** (Free browser extension)
- One-click bug reports with automatic capture of:
  - Screenshot or screen recording
  - Console logs
  - Network requests
  - Device info
- Integrates with GitHub Issues, Jira, Linear
- No installation needed on website - users install extension
- **Limitation:** Requires users to install extension

**BugHerd** ($41/month)
- Point-and-click feedback on live site
- Automatic screenshot + technical metadata
- Screen recording capability
- Kanban board for task management
- **Limitation:** Cost, requires installation on site

**Usersnap** ($52/month starter)
- Annotated screenshots
- Console error capture
- Environment data (URL, browser, screen size)
- **Limitation:** Cost

**Microsoft Clarity** (Free)
- Session recordings and heatmaps
- Built-in privacy features (data masking, IP anonymization)
- GDPR-compliant with proper consent
- Unlimited recordings
- **Limitation:** Data stored in US, requires consent banner integration

---

### Option 4: Custom Lightweight Session Recording (rrweb)

If Sentry replay pricing becomes an issue, build custom solution with rrweb.

**What is rrweb?**
- Open-source session replay library (~50KB)
- Used by Sentry, PostHog, Highlight internally
- Records DOM mutations efficiently (incremental snapshots)

**Performance:**
- Initial snapshot: slight CPU spike
- Ongoing: minimal - uses MutationObserver API
- Throttles high-frequency events automatically

**Implementation Complexity:** High
- Need to build data pipeline
- Need storage backend
- Need replay viewer UI
- Need privacy controls

**Recommendation:** Only if Sentry costs become prohibitive

---

## Recommended Implementation Plan

### Phase 1: Sentry Session Replay (Error-Only Mode)

1. **Enable Replay Integration**
   - Add `replayIntegration` to existing Sentry config
   - Set `replaysSessionSampleRate: 0` (no normal session recording)
   - Set `replaysOnErrorSampleRate: 1.0` (capture all error sessions)

2. **Privacy Configuration**
   - Enable `maskAllText: true`
   - Enable `blockAllMedia: true`
   - Add `sentry-mask` class to sensitive form fields (passwords, etc.)

3. **Update CookieYes Integration**
   - May need consent for session replay
   - Only enable replay after consent

4. **Update Error Display**
   - Modify `SomethingWentWrong.vue` to show:
     - "We've automatically captured diagnostic information"
     - Link to the Sentry issue (if available)
     - Still allow manual reporting for non-error issues

### Phase 2: Enhanced Device Info for Non-Error Cases

1. **Create diagnostic info composable**
   - Collect device/browser info
   - Store recent console messages
   - Track recent user actions

2. **Integrate with Help Page**
   - Enhance existing "Copy app and device info" button
   - Include more comprehensive data
   - Option to "Generate Support Report"

3. **Create server-side storage**
   - API endpoint to store diagnostic reports
   - Generate shareable token/URL
   - Auto-expire after 30 days

4. **Integration Points**
   - "Something went wrong" component
   - Help page contact form
   - Support email template

### Phase 3: ModTools Integration

1. **Display in Chat**
   - When user shares diagnostic token, auto-fetch and display info
   - Show device/browser, error details, replay link

2. **Support Email Enhancement**
   - Include diagnostic link in support emails
   - Auto-attach device info when available

---

## Cost Comparison

| Solution | Monthly Cost | Performance Impact | Implementation Effort |
|----------|-------------|-------------------|----------------------|
| Sentry Replay (error-only) | $0 (100K replays on sponsored plan) | Low (~36KB, 10-20% CPU on error) | Low |
| Enhanced Device Info | $0 | Minimal | Medium |
| Microsoft Clarity | $0 | Medium | Low |
| Jam.dev | $0 (extension) | None | None (user install) |
| BugHerd | $41/month | Low | Low |
| Usersnap | $52/month+ | Low | Low |
| Custom rrweb | $0 + hosting | Low-Medium | High |

---

## Recommendation

**Start with Sentry Session Replay in error-only mode:**

1. Lowest implementation effort (already using Sentry)
2. No additional cost if within 500 replays/month (likely for error-only)
3. Best debugging value - see exactly what happened
4. Privacy-safe with default masking
5. Can expand to broader recording if needed

**Add Enhanced Device Info for non-error support requests:**
- Improves Help page contact workflow
- No external dependencies
- Lightweight overhead

This combination gives volunteers:
- **For errors:** Full session replay showing exactly what went wrong
- **For general support:** Comprehensive device/browser info and recent activity

---

## Mobile App Support

### Sentry Capacitor Support

Sentry Session Replay supports Capacitor apps (which Freegle uses):
- Minimum SDK version required: 0.11.0
- Works the same as web - records DOM state since it's a WebView
- Same privacy controls apply

### Native Mobile (iOS/Android)

For native mobile apps, Sentry now offers Session Replay in open beta:
- Uses screenshot-based recording (1 frame/second by default)
- Automatic redaction of text boxes, images, labels, buttons
- Includes device info: battery, OS version, network requests

---

## Exposing Replays to Mods/Support

### The Challenge

**Sentry does NOT currently support:**
- Public embed links or iframes
- Downloading replays as video files
- Sharing replays with non-Sentry users

**Sentry DOES support:**
- Sharing replay links with Sentry organization members
- API to retrieve replay metadata (not the actual replay content)
- Building replay URLs programmatically: `https://<org-slug>.sentry.io/replays/<replay-id>/`

### Options for Exposing Replays

#### Option A: Give Mods/Support Sentry Access (Simplest)

**Pros:**
- No development needed
- Full Sentry UI with all debugging tools
- Can see console logs, network requests, DOM state

**Cons:**
- Requires Sentry account for each volunteer
- Sentry Team plan has limited seats
- Volunteers see all errors, not just relevant ones

**Implementation:**
1. Create Sentry team for Support volunteers
2. Add them to the Freegle project with limited permissions
3. Store replay ID when error occurs
4. Display direct Sentry link in ModTools/Support interface

#### Option B: PostHog Instead of Sentry Replay (Best Embedding Support)

PostHog offers what Sentry doesn't - embeddable iframes:

```html
<iframe width="100%" height="450" frameborder="0" allowfullscreen
  src="https://app.posthog.com/embedded/{accessToken}"></iframe>
```

**API to enable sharing:**
```javascript
// Backend call to PostHog API
PATCH https://us.posthog.com/api/projects/${projectID}/session_recordings/${sessionID}/sharing
// Returns accessToken for embedding
```

**Pros:**
- Embeddable iframe - can show directly in ModTools chat
- Public links for Support emails
- Good privacy controls

**Cons:**
- Additional service to Sentry (though could replace Sentry)
- Different pricing model
- Migration effort

**PostHog Pricing:**
- Free: 5,000 recordings/month
- Paid: $0.005/recording after that

#### Option C: OpenReplay Self-Hosted (Full Control)

Self-host session replay for complete control over data and embedding:

**Pros:**
- Full control over data
- Can build custom embedding/sharing
- No external data transfer
- "Assist" feature for live co-browsing with users

**Cons:**
- Significant infrastructure to maintain
- High implementation effort
- Separate from Sentry error tracking

#### Option D: Hybrid - Sentry + Custom Diagnostic Storage (Recommended)

Keep Sentry for error tracking + replay, but build a lightweight system for sharing context:

1. **When error occurs:**
   - Sentry captures replay automatically
   - Also capture replay ID + diagnostic info to Freegle backend
   - Store: user ID, error message, timestamp, replay ID, device info

2. **In ModTools Chat:**
   - When viewing chat with a user, show "Recent Errors" panel
   - Display: error message, timestamp, device info
   - "View in Sentry" button opens Sentry replay (for those with access)
   - For volunteers without Sentry access: show device info + error details

3. **In Support Tools:**
   - Link support emails to user ID
   - Show diagnostic history for that user
   - "View Replay" links for Sentry users

**Database Schema:**
```sql
CREATE TABLE user_diagnostics (
  id INT PRIMARY KEY,
  userid INT,
  timestamp DATETIME,
  error_message TEXT,
  sentry_replay_id VARCHAR(255),
  sentry_event_id VARCHAR(255),
  device_info JSON,  -- browser, OS, screen size, etc.
  user_actions JSON, -- last 10-20 clicks/navigations
  console_errors JSON,
  url VARCHAR(500),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**API Endpoints:**
```
POST /api/diagnostic - Store diagnostic info when error occurs
GET /api/diagnostic/user/{userid} - Get diagnostics for a user
GET /api/diagnostic/{id} - Get specific diagnostic report
```

**ModTools Integration:**
```vue
<!-- In chat view, show diagnostics panel -->
<DiagnosticPanel v-if="hasRecentErrors" :userid="chatUser.id">
  <template #error="{ diagnostic }">
    <div class="diagnostic-item">
      <span class="timestamp">{{ diagnostic.timestamp }}</span>
      <span class="error">{{ diagnostic.error_message }}</span>
      <span class="device">{{ diagnostic.device_info.browser }}</span>
      <a v-if="hasSentryAccess" :href="sentryReplayUrl(diagnostic)" target="_blank">
        View Replay
      </a>
    </div>
  </template>
</DiagnosticPanel>
```

---

## Known Sentry Replay Issues

### Common Problems to Watch For

1. **CSP Blocking** - Must add `worker-src 'self' blob:` to Content-Security-Policy
2. **Custom Fonts** - If fonts don't load in replay, mouse clicks appear in wrong location
3. **Text Masking with Variable Fonts** - Masked text may have different dimensions
4. **Large DOM Mutations** - SDK stops recording after 10,000+ mutations (protection)
5. **Videos/SVGs** - Streamed videos not captured; SVGs with `<use>` tags may not render

### Nuxt-Specific Issues

There have been reported module resolution errors with rrweb in Nuxt:
- "Cannot find module './node_modules/rrweb/es/rrweb/packages/rrweb/src/entries/all.js'"
- Usually resolved by updating SDK versions

---

## Revised Recommendation

Given the requirements for:
1. Works on both web and mobile app
2. Expose replays to Mods in chat
3. Expose replays to Support via email links

**Recommended Approach: Option D (Hybrid)**

1. **Keep Sentry for error tracking + replay** (already integrated)
2. **Build diagnostic storage in Freegle backend**
3. **Give key Support volunteers Sentry access** for full replay viewing
4. **Show diagnostic summaries in ModTools** for volunteers without Sentry access

**Why not PostHog?**
- Would require migrating away from Sentry or running both
- Sentry already integrated and working
- The iframe embedding is nice but not essential if we build diagnostic display

**Implementation Phases:**

1. **Phase 1:** Enable Sentry Replay, store replay IDs in Freegle DB
2. **Phase 2:** Build diagnostic display in ModTools chat sidebar
3. **Phase 3:** Add "View Replay" for volunteers with Sentry access
4. **Phase 4:** Include diagnostic links in Support emails

---

---

## Detailed Implementation Plan

### Phase 1: Enable Sentry Session Replay

#### 1.1 Update Sentry Client Plugin

**File:** `iznik-nuxt3/plugins/sentry.client.ts`

Add the replay integration to existing Sentry config:

```typescript
import { replayIntegration } from '@sentry/vue'

// Inside Sentry.init():
Sentry.init({
  // ... existing config ...
  replaysSessionSampleRate: 0,      // Don't record normal sessions
  replaysOnErrorSampleRate: 1.0,    // Record 100% of sessions with errors
  integrations: [
    // ... existing integrations ...
    replayIntegration({
      maskAllText: true,
      blockAllMedia: true,
      networkDetailAllowUrls: [/ilovefreegle\.org/],
    }),
  ],
})
```

#### 1.2 Update CSP Headers

**File:** `iznik-nuxt3/server/middleware/csp.js`

Add `worker-src 'self' blob:` to allow Sentry replay web workers.

#### 1.3 Get Replay ID for Storage

After an error, get the replay ID to store:

```typescript
// In the beforeSend hook or error handler
import { getReplay } from '@sentry/vue'

const replay = getReplay()
const replayId = replay?.getReplayId()
```

---

### Phase 2: Database Schema

#### 2.1 SQL for Manual Execution on Live

```sql
-- Create table for storing user diagnostic sessions
CREATE TABLE users_diagnostics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  userid BIGINT UNSIGNED NOT NULL,
  sessionid VARCHAR(64) DEFAULT NULL COMMENT 'Sentry session ID',
  replayid VARCHAR(64) DEFAULT NULL COMMENT 'Sentry replay ID',
  eventid VARCHAR(64) DEFAULT NULL COMMENT 'Sentry event ID',
  error_message TEXT DEFAULT NULL,
  error_stack TEXT DEFAULT NULL,
  device_info JSON DEFAULT NULL COMMENT 'Browser, OS, screen size, etc',
  user_actions JSON DEFAULT NULL COMMENT 'Recent user actions before error',
  url VARCHAR(2048) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_userid (userid),
  KEY idx_replayid (replayid),
  KEY idx_created_at (created_at),
  CONSTRAINT fk_diagnostics_user FOREIGN KEY (userid) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Index for cleanup of old records
CREATE INDEX idx_diagnostics_cleanup ON users_diagnostics (created_at);
```

#### 2.2 Update schema.sql

**File:** `iznik-server/install/schema.sql`

Add the same CREATE TABLE statement to the schema file for new installations.

---

### Phase 3: Go API Endpoints

#### 3.1 Create New Package

**File:** `iznik-server-go/diagnostic/diagnostic.go`

```go
package diagnostic

import (
	"encoding/json"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

type Diagnostic struct {
	ID           uint64          `json:"id" gorm:"primary_key"`
	Userid       uint64          `json:"userid"`
	Sessionid    *string         `json:"sessionid"`
	Replayid     *string         `json:"replayid"`
	Eventid      *string         `json:"eventid"`
	ErrorMessage *string         `json:"error_message"`
	ErrorStack   *string         `json:"error_stack"`
	DeviceInfo   json.RawMessage `json:"device_info" gorm:"type:json"`
	UserActions  json.RawMessage `json:"user_actions" gorm:"type:json"`
	Url          *string         `json:"url"`
	CreatedAt    time.Time       `json:"created_at"`
}

func (Diagnostic) TableName() string {
	return "users_diagnostics"
}

type CreateDiagnosticRequest struct {
	Sessionid    *string         `json:"sessionid"`
	Replayid     *string         `json:"replayid"`
	Eventid      *string         `json:"eventid"`
	ErrorMessage *string         `json:"error_message"`
	ErrorStack   *string         `json:"error_stack"`
	DeviceInfo   json.RawMessage `json:"device_info"`
	UserActions  json.RawMessage `json:"user_actions"`
	Url          *string         `json:"url"`
}

// RequireSupportOrAdminMiddleware - reuse from config package or move to shared location
func RequireSupportOrAdminMiddleware() fiber.Handler {
	return func(c *fiber.Ctx) error {
		userID, sessionID, _ := user.GetJWTFromRequest(c)
		if userID == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "Authentication required")
		}

		db := database.DBConn

		var userInfo struct {
			ID         uint64 `json:"id"`
			Systemrole string `json:"systemrole"`
		}

		db.Raw("SELECT users.id, users.systemrole FROM sessions INNER JOIN users ON users.id = sessions.userid WHERE sessions.id = ? AND users.id = ? LIMIT 1", sessionID, userID).Scan(&userInfo)

		if userInfo.ID == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "Invalid session")
		}

		if userInfo.Systemrole != "Support" && userInfo.Systemrole != "Admin" {
			return fiber.NewError(fiber.StatusForbidden, "Support or Admin role required")
		}

		return c.Next()
	}
}

// CreateDiagnostic - POST /diagnostic
// Creates a new diagnostic record for the logged-in user
func CreateDiagnostic(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req CreateDiagnosticRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	diagnostic := Diagnostic{
		Userid:       myid,
		Sessionid:    req.Sessionid,
		Replayid:     req.Replayid,
		Eventid:      req.Eventid,
		ErrorMessage: req.ErrorMessage,
		ErrorStack:   req.ErrorStack,
		DeviceInfo:   req.DeviceInfo,
		UserActions:  req.UserActions,
		Url:          req.Url,
		CreatedAt:    time.Now(),
	}

	db := database.DBConn
	result := db.Create(&diagnostic)

	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create diagnostic")
	}

	return c.Status(fiber.StatusOK).JSON(diagnostic)
}

// ListDiagnosticsForUser - GET /diagnostic/user/:id
// Returns diagnostics for a specific user (Support/Admin only)
func ListDiagnosticsForUser(c *fiber.Ctx) error {
	userid, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid user ID")
	}

	db := database.DBConn

	var diagnostics []Diagnostic
	// Get last 30 days of diagnostics, most recent first
	db.Where("userid = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)", userid).
		Order("created_at DESC").
		Limit(50).
		Find(&diagnostics)

	return c.JSON(diagnostics)
}

// GetDiagnostic - GET /diagnostic/:id
// Returns a single diagnostic record (Support/Admin only)
func GetDiagnostic(c *fiber.Ctx) error {
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}

	db := database.DBConn

	var diagnostic Diagnostic
	result := db.First(&diagnostic, id)

	if result.Error != nil {
		return fiber.NewError(fiber.StatusNotFound, "Diagnostic not found")
	}

	return c.JSON(diagnostic)
}
```

#### 3.2 Add Routes

**File:** `iznik-server-go/router/routes.go`

Add to the imports:
```go
"github.com/freegle/iznik-server-go/diagnostic"
```

Add routes inside the `for _, rg := range` loop:

```go
// Diagnostics - user can create their own
rg.Post("/diagnostic", diagnostic.CreateDiagnostic)

// Diagnostics - Support/Admin protected routes
diagnosticAdmin := rg.Group("/diagnostic")
diagnosticAdmin.Use(diagnostic.RequireSupportOrAdminMiddleware())

// @Router /diagnostic/user/{id} [get]
// @Summary Get diagnostics for a user
// @Description Returns diagnostic records for a specific user (Support/Admin only)
// @Tags diagnostic
// @Produce json
// @Param id path integer true "User ID"
// @Security BearerAuth
// @Success 200 {array} diagnostic.Diagnostic
// @Failure 401 {object} fiber.Error "Authentication required"
// @Failure 403 {object} fiber.Error "Support or Admin role required"
diagnosticAdmin.Get("/user/:id", diagnostic.ListDiagnosticsForUser)

// @Router /diagnostic/{id} [get]
// @Summary Get diagnostic by ID
// @Description Returns a single diagnostic record (Support/Admin only)
// @Tags diagnostic
// @Produce json
// @Param id path integer true "Diagnostic ID"
// @Security BearerAuth
// @Success 200 {object} diagnostic.Diagnostic
// @Failure 401 {object} fiber.Error "Authentication required"
// @Failure 403 {object} fiber.Error "Support or Admin role required"
// @Failure 404 {object} fiber.Error "Diagnostic not found"
diagnosticAdmin.Get("/:id", diagnostic.GetDiagnostic)
```

---

### Phase 4: Client-Side Diagnostic Collection

#### 4.1 Create Diagnostic Composable

**File:** `iznik-nuxt3/composables/useDiagnostic.js`

```javascript
import { getReplay } from '@sentry/vue'

// Circular buffer for recent user actions
const MAX_ACTIONS = 20
let recentActions = []

export function trackUserAction(action) {
  recentActions.push({
    action,
    timestamp: new Date().toISOString(),
    url: window.location.href,
  })
  if (recentActions.length > MAX_ACTIONS) {
    recentActions.shift()
  }
}

export function useDiagnostic() {
  const runtimeConfig = useRuntimeConfig()

  function getDeviceInfo() {
    return {
      userAgent: navigator.userAgent,
      platform: navigator.platform,
      language: navigator.language,
      screenSize: `${window.screen.width}x${window.screen.height}`,
      viewport: `${window.innerWidth}x${window.innerHeight}`,
      deviceMemory: navigator.deviceMemory,
      hardwareConcurrency: navigator.hardwareConcurrency,
      connection: navigator.connection?.effectiveType,
      online: navigator.onLine,
      cookiesEnabled: navigator.cookieEnabled,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      timestamp: new Date().toISOString(),
    }
  }

  function getReplayInfo() {
    try {
      const replay = getReplay()
      return {
        replayId: replay?.getReplayId() || null,
        sessionId: replay?.getSessionId?.() || null,
      }
    } catch (e) {
      return { replayId: null, sessionId: null }
    }
  }

  async function saveDiagnostic(error, eventId = null) {
    const { replayId, sessionId } = getReplayInfo()

    const diagnostic = {
      sessionid: sessionId,
      replayid: replayId,
      eventid: eventId,
      error_message: error?.message || String(error),
      error_stack: error?.stack || null,
      device_info: getDeviceInfo(),
      user_actions: [...recentActions],
      url: window.location.href,
    }

    try {
      await $fetch(`${runtimeConfig.public.APIv2}/diagnostic`, {
        method: 'POST',
        body: diagnostic,
        credentials: 'include',
      })
    } catch (e) {
      console.error('Failed to save diagnostic:', e)
    }
  }

  return {
    getDeviceInfo,
    getReplayInfo,
    saveDiagnostic,
    trackUserAction,
  }
}
```

#### 4.2 Integrate with Error Handler

**File:** `iznik-nuxt3/plugins/something-went-wrong.client.js`

Add call to save diagnostic when errors occur:

```javascript
import { useDiagnostic } from '~/composables/useDiagnostic'

// In the error handler:
const { saveDiagnostic } = useDiagnostic()
await saveDiagnostic(err)
```

#### 4.3 Track User Actions

Add tracking to key user interactions (clicks, navigation):

```javascript
// In a plugin or layout component
import { trackUserAction } from '~/composables/useDiagnostic'

// Track route changes
router.afterEach((to) => {
  trackUserAction({ type: 'navigation', path: to.path })
})

// Track clicks on important elements (optional, add where needed)
```

---

### Phase 5: Support Tools Integration

#### 5.1 Create Diagnostic Display Component

**File:** `iznik-nuxt3-modtools/modtools/components/ModSupportDiagnostics.vue`

```vue
<template>
  <div v-if="diagnostics.length" class="mt-3">
    <h3>Recent Errors / Diagnostics</h3>
    <div
      v-for="diag in diagnostics"
      :key="diag.id"
      class="diagnostic-item mb-2 p-2 border rounded"
    >
      <div class="d-flex justify-content-between">
        <span class="text-muted small">{{ timeago(diag.created_at) }}</span>
        <span v-if="diag.replayid" class="badge bg-info">Has Replay</span>
      </div>
      <div v-if="diag.error_message" class="text-danger">
        {{ diag.error_message }}
      </div>
      <div v-if="diag.url" class="small text-muted">
        {{ diag.url }}
      </div>
      <div v-if="diag.device_info" class="small">
        <strong>Device:</strong> {{ formatDeviceInfo(diag.device_info) }}
      </div>
      <div v-if="diag.replayid" class="mt-1">
        <a
          :href="sentryReplayUrl(diag.replayid)"
          target="_blank"
          rel="noopener noreferrer"
          class="btn btn-sm btn-outline-primary"
        >
          <v-icon icon="play" /> View Replay in Sentry
        </a>
      </div>
      <b-button
        v-if="!expanded[diag.id]"
        variant="link"
        size="sm"
        @click="expanded[diag.id] = true"
      >
        Show details
      </b-button>
      <div v-if="expanded[diag.id]" class="mt-2">
        <div v-if="diag.error_stack" class="small">
          <strong>Stack:</strong>
          <pre class="error-stack">{{ diag.error_stack }}</pre>
        </div>
        <div v-if="diag.user_actions?.length" class="small">
          <strong>Recent Actions:</strong>
          <ul class="mb-0">
            <li v-for="(action, idx) in diag.user_actions" :key="idx">
              {{ action.action?.type || action.action }} - {{ action.url }}
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <div v-else-if="loaded" class="text-muted mt-3">
    No recent diagnostics for this user.
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const props = defineProps({
  userid: {
    type: Number,
    required: true,
  },
})

const runtimeConfig = useRuntimeConfig()
const diagnostics = ref([])
const loaded = ref(false)
const expanded = ref({})

// Sentry org slug - configure this
const SENTRY_ORG = 'freegle' // Update with actual org slug

function sentryReplayUrl(replayId) {
  return `https://${SENTRY_ORG}.sentry.io/replays/${replayId}/`
}

function formatDeviceInfo(info) {
  if (!info) return 'Unknown'
  const parsed = typeof info === 'string' ? JSON.parse(info) : info
  // Extract browser from user agent (simplified)
  const ua = parsed.userAgent || ''
  let browser = 'Unknown'
  if (ua.includes('Chrome')) browser = 'Chrome'
  else if (ua.includes('Firefox')) browser = 'Firefox'
  else if (ua.includes('Safari')) browser = 'Safari'
  else if (ua.includes('Edge')) browser = 'Edge'

  return `${browser} - ${parsed.screenSize || 'unknown'} - ${parsed.platform || 'unknown'}`
}

onMounted(async () => {
  try {
    diagnostics.value = await $fetch(
      `${runtimeConfig.public.APIv2}/diagnostic/user/${props.userid}`,
      { credentials: 'include' }
    )
  } catch (e) {
    console.error('Failed to fetch diagnostics:', e)
  }
  loaded.value = true
})
</script>

<style scoped>
.error-stack {
  background: #f8f9fa;
  padding: 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  max-height: 150px;
  overflow-y: auto;
}
</style>
```

#### 5.2 Add to ModSupportUser

**File:** `iznik-nuxt3-modtools/modtools/components/ModSupportUser.vue`

Add the diagnostics component after the ChitChat section:

```vue
<!-- Add import -->
<script>
import ModSupportDiagnostics from './ModSupportDiagnostics.vue'
// ... rest of imports
</script>

<!-- Add in template, after ChitChat section -->
<ModSupportDiagnostics :userid="user.id" />
```

---

### Phase 6: Testing

#### 6.1 Go API Tests

**File:** `iznik-server-go/test/diagnostic_test.go`

```go
package test

import (
	"encoding/json"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestDiagnosticCreateRequiresAuth(t *testing.T) {
	req := httptest.NewRequest("POST", "/api/diagnostic", strings.NewReader(`{}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := app.Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestDiagnosticListRequiresSupportRole(t *testing.T) {
	// Test with regular user JWT - should fail
	req := httptest.NewRequest("GET", "/api/diagnostic/user/1", nil)
	req.Header.Set("Authorization", "Bearer "+regularUserJWT)
	resp, _ := app.Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestDiagnosticListWithSupportRole(t *testing.T) {
	// Test with support user JWT - should succeed
	req := httptest.NewRequest("GET", "/api/diagnostic/user/1", nil)
	req.Header.Set("Authorization", "Bearer "+supportUserJWT)
	resp, _ := app.Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}
```

---

### Implementation Order

1. **Database** (Manual - you do this)
   - Run the SQL on live database

2. **Schema file** (Claude does this)
   - Update `iznik-server/install/schema.sql`

3. **Go API** (Claude does this)
   - Create `diagnostic/diagnostic.go`
   - Update `router/routes.go`
   - Rebuild and test API container

4. **Sentry Replay** (Claude does this)
   - Update `plugins/sentry.client.ts`
   - Update CSP if needed

5. **Client Diagnostic Collection** (Claude does this)
   - Create `composables/useDiagnostic.js`
   - Integrate with error handler

6. **Support Tools UI** (Claude does this)
   - Create `ModSupportDiagnostics.vue`
   - Add to `ModSupportUser.vue`

7. **Testing**
   - Test error capture in dev
   - Verify replay appears in Sentry
   - Verify diagnostics appear in Support Tools

---

## Technical References

### Sentry Replay Docs
- Setup: https://docs.sentry.io/platforms/javascript/guides/vue/session-replay/
- Nuxt-specific: https://docs.sentry.io/platforms/javascript/guides/nuxt/session-replay/
- Capacitor: https://docs.sentry.io/platforms/javascript/guides/capacitor/session-replay/
- Privacy: https://docs.sentry.io/platforms/javascript/guides/nuxt/session-replay/privacy/
- Performance: https://docs.sentry.io/product/session-replay/performance-overhead/
- API: https://docs.sentry.io/api/replays/retrieve-a-replay-instance/

### rrweb (underlying library)
- GitHub: https://github.com/rrweb-io/rrweb
- Docs: https://www.rrweb.io/

### Alternative Tools
- Microsoft Clarity: https://clarity.microsoft.com/
- Jam.dev: https://jam.dev/
- OpenReplay (self-hosted): https://openreplay.com/
- PostHog: https://posthog.com/ (best embedding support)

### Device Detection Libraries
- UAParser.js: https://uaparser.dev/
- Platform.js: https://github.com/bestiejs/platform.js/
