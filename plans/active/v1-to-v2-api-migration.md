# V1 to V2 API Migration Plan

## Overview

Migration of all PHP v1 API endpoints to Go v2 API for Freegle (FD) and ModTools (MT). This plan uses ralph TDD methodology with automated long-running execution, feature branch rotation, comprehensive test coverage, and adversarial code review.

### Principles

1. **TDD first** - Write failing tests before implementing Go handlers.
2. **FD first, MT alongside** - Focus on Freegle client switchover; update MT where sensible in the same task. Full MT v1 removal is a separate future task.
3. **Never retire v1 until deployed** - Flag obsolescence with deprecation comments but keep v1 code functional until v2 is confirmed live in production.
4. **High coverage** - Every new Go handler must have Go unit tests, at least one Playwright E2E test exercising the codepath, and Chrome MCP sanity check.
5. **Feature branch rotation** - New feature branch every 3-4 API call migrations to keep PRs reviewable.

### Key Architecture Change: Nested Objects vs IDs

**V1 Pattern (PHP):** Returns large nested objects in single calls.

**V2 Pattern (Go):** Returns IDs for related entities; client caches entities by ID and fetches nested data in parallel. V2 uses goroutines for parallel DB fetching, applies privacy filtering, and computes derived fields.

---

## V2 API Style Guide

All new Go API handlers **must** follow these patterns. This guide is derived from the existing codebase and should be referenced by ralph during implementation.

### Handler Structure

```go
// domain/domain.go

// @Summary Brief description
// @Tags DomainName
// @Router /api/domain/:id [get]
func GetDomain(c *fiber.Ctx) error {
    // 1. AUTH: Get authenticated user (0 if anonymous)
    myid := user.WhoAmI(c)

    // 2. PARSE: Extract and validate parameters
    id, err := strconv.ParseUint(c.Params("id"), 10, 64)
    if err != nil {
        return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
    }

    // 3. DB: Get database connection
    db := database.DBConn

    // 4. FETCH: Use goroutines for independent queries
    var wg sync.WaitGroup
    var mu sync.Mutex
    var domain DomainType
    var related []RelatedType

    wg.Add(2)
    go func() {
        defer wg.Done()
        db.Raw("SELECT ... FROM domain WHERE id = ?", id).Scan(&domain)
    }()
    go func() {
        defer wg.Done()
        db.Raw("SELECT ... FROM related WHERE domain_id = ?", id).Scan(&related)
    }()
    wg.Wait()

    // 5. PRIVACY: Filter sensitive data for non-owners
    if domain.Userid != myid {
        domain.Textbody = hideEmails(domain.Textbody)
        domain.Textbody = hidePhones(domain.Textbody)
    }

    // 6. ASSEMBLE: Build response
    domain.Related = related

    // 7. RESPOND: Return JSON (empty array not null for collections)
    return c.JSON(domain)
}
```

### Write Handler Pattern (POST/PUT/PATCH/DELETE)

```go
func CreateDomain(c *fiber.Ctx) error {
    // 1. AUTH: Require authentication
    myid := user.WhoAmI(c)
    if myid == 0 {
        return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
    }

    // 2. PARSE: Body parsing with validation
    type CreateRequest struct {
        Name    string `json:"name"`
        GroupID uint64 `json:"groupid"`
    }
    var req CreateRequest
    if err := c.BodyParser(&req); err != nil {
        return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
    }

    // 3. AUTHZ: Check permissions (moderator, owner, admin)
    // Use existing role checks from user package

    // 4. DB: Execute write operations
    db := database.DBConn
    result := db.Exec("INSERT INTO domain (...) VALUES (?)", ...)
    if result.Error != nil {
        return fiber.NewError(fiber.StatusInternalServerError, "Database error")
    }

    // 5. SIDE EFFECTS: Queue emails if needed (low volume, acceptable latency)
    email.QueueEmail("domain_created", myid, req.GroupID, 0, nil)

    // 6. RESPOND
    return c.JSON(fiber.Map{"ret": 0, "id": result.RowsAffected})
}
```

### Mandatory Patterns

| Pattern | Requirement |
|---------|-------------|
| **Goroutines** | Use `sync.WaitGroup` for 2+ independent DB queries. One goroutine per independent query. |
| **Mutex** | Use `sync.Mutex` when goroutines write to shared slices/maps. |
| **Empty arrays** | Return `make([]T, 0)` not `nil` for empty collections. |
| **Error responses** | Use `fiber.NewError(statusCode, message)` consistently. |
| **Logging** | Loki middleware handles request logging automatically. Add explicit logging only for business events. |
| **Privacy** | Always check `myid` vs resource owner before returning emails/phones/addresses. |
| **Swagger** | Add `@Summary`, `@Tags`, `@Router` annotations. Run `./generate-swagger.sh` after changes. |
| **Struct tags** | Use `json:"fieldname"` for API fields, `json:"-"` for internal-only, `gorm:"-"` for computed fields. |
| **Raw SQL** | Prefer `db.Raw()` over GORM query builder for performance-critical paths. |
| **Context** | Accept `*fiber.Ctx`, never store it or pass to goroutines. |

### Testing Pattern

```go
// test/domain_test.go
func TestGetDomain(t *testing.T) {
    // ARRANGE: Create test data using helpers
    groupID := CreateTestGroup(t, "testgroup")
    userID := CreateTestUser(t, "test", "Member")
    CreateMembership(t, userID, groupID, "Member")

    // ACT: Make HTTP request
    req := httptest.NewRequest("GET", "/api/domain/"+strconv.FormatUint(id, 10), nil)
    req.Header.Set("Authorization", "Bearer "+GetToken(userID, sessionID))
    resp, _ := getApp().Test(req)

    // ASSERT: Check response
    assert.Equal(t, fiber.StatusOK, resp.StatusCode)
    body := rsp(resp)
    assert.Equal(t, expectedValue, body["field"])
}

func TestGetDomainUnauthorized(t *testing.T) {
    // Test without auth token
    req := httptest.NewRequest("GET", "/api/domain/1", nil)
    resp, _ := getApp().Test(req)
    assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}
```

### Route Registration

```go
// router/routes.go
// Add under appropriate section, maintaining alphabetical order within groups

// @Summary Get domain by ID
// @Tags Domain
// @Param id path int true "Domain ID" example(12345)
// @Success 200 {object} domain.DomainType
// @Router /api/domain/:id [get]
app.Get("/api/domain/:id", domain.GetDomain)
app.Get("/apiv2/domain/:id", domain.GetDomain)  // Always register both paths
```

---

## Phase 0: Foundation (Prerequisites)

Before starting endpoint migrations, establish the infrastructure.

### 0A: Background Task Queue System

Renamed from "Email Queue" to "Background Task Queue" - handles all async side effects (emails, push notifications) via a generic `background_tasks` table. Go inserts rows, iznik-batch processes them.

| # | Task | Status | Notes |
|---|------|--------|-------|
| 0A.1 | Create `background_tasks` table migration | ✅ Done | Laravel migration + idempotent SQL. Go PR #14, FD PR #51 |
| 0A.2 | Implement `queue/queue.go` in Go | ✅ Done | `QueueTask()` function with typed constants. Go PR #14 |
| 0A.3 | Create `ProcessBackgroundTasksCommand` in Laravel | ✅ Done | `queue:background-tasks` artisan command. FD PR #51 |
| 0A.4 | Add to scheduler | ✅ Done | Runs every minute via scheduler with --spool |
| 0A.5 | Create ChitchatReportMail (MJML) | ✅ Done | Freegle-branded MJML email for newsfeed reports. FD PR #51 |
| 0A.6 | Create newsfeed entry helpers in Go | ✅ Done | `newsfeed.CreateNewsfeedEntry()` for addGroup side effects. Go PR #14 |
| 0A.7 | Test end-to-end | ⬜ Pending | Go inserts → batch processes → verify in MailPit |
| 0A.8 | Wire up Phase 2 Go handlers | ✅ Done | Newsfeed Report→email queue, Volunteering/CommunityEvent AddGroup→newsfeed+push queue |
| 0A.9 | Add spam/suppression check to CreateNewsfeedEntry | ✅ Done | Checks `newsfeedmodstatus=Suppressed` and spammer list, sets `hidden=NOW()`. |
| 0A.10 | Add duplicate protection to CreateNewsfeedEntry | ✅ Done | Skips if last entry by user was the same type. |
| 0A.11 | Set `location` display name in CreateNewsfeedEntry | ✅ Done | Uses group nameshort as location display name. |

**Queue Table Schema:**
```sql
CREATE TABLE IF NOT EXISTS background_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_type VARCHAR(50) NOT NULL,
    data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    INDEX idx_task_type (task_type),
    INDEX idx_pending (processed_at, created_at)
);
```

**Current Task Types:**

| Task Type | Triggered By | Data Fields |
|-----------|--------------|-------------|
| `push_notify_group_mods` | addGroup (volunteering/communityevent) | `{group_id}` |
| `email_chitchat_report` | POST /newsfeed (Report action) | `{user_id, user_name, user_email, newsfeed_id, reason}` |
| `email_donate_external` | PUT /donations (external donation) | `{user_id, user_name, user_email, amount}` |
| `email_invitation` | PUT /invitation (create invitation) | `{invite_id, sender_name, sender_email, to_email}` |

**Future Task Types (to be added as endpoints migrate):**

| Task Type | Triggered By | Data Fields |
|-----------|--------------|-------------|
| `email_forgot_password` | POST /session (LostPassword) | `{user_id}` |
| `email_verify` | PATCH /user | `{user_id, email}` |
| `email_welcome` | PUT /user | `{user_id}` |
| `email_modmail` | POST /user, /memberships, /message | `{user_id, group_id, subject, body}` |

**Design Principles:**
- All emails use MJML templates with Freegle or ModTools branding (no plain text)
- Push notifications route through existing `PushNotificationService`
- Max 3 retry attempts before permanent failure
- Daemon mode via scheduler (every minute, 60 iterations per run)

### 0B: Test Audit & Gap Analysis

Before migrating any endpoint, audit existing test coverage to identify gaps. This produces a definitive list of what needs testing.

| # | Task | Status | Notes |
|---|------|--------|-------|
| 0B.1 | Audit Go test coverage per endpoint | ⬜ Pending | Map each v2 endpoint to test functions |
| 0B.2 | Audit PHP test coverage per endpoint | ⬜ Pending | Map each v1 endpoint to test functions |
| 0B.3 | Audit Playwright coverage of API flows | ⬜ Pending | Which user flows exercise which APIs |
| 0B.4 | Build coverage gap matrix | ⬜ Pending | Endpoint × {Go test, PHP test, Playwright, FD v1/v2, MT v1/v2} |
| 0B.5 | Write missing Go tests for existing v2 endpoints | ⬜ Pending | TDD: write tests, verify they pass against existing code |
| 0B.6 | Write missing Playwright tests for existing v2 endpoints | ⬜ Pending | At least one E2E test per migrated endpoint |

**Output:** A coverage gap matrix markdown file at `plans/active/api-test-coverage-matrix.md`.

### 0C: V2 API Coding Guide

Produce a standalone guide file that ralph references during implementation.

| # | Task | Status | Notes |
|---|------|--------|-------|
| 0C.1 | Extract patterns from existing Go handlers | ✅ Done | Analysed message.go, address.go, user.go, authority.go, volunteering.go, newsfeed.go |
| 0C.2 | Write `iznik-server-go/API-GUIDE.md` | ✅ Done | Covers auth, parsing, DB, goroutines, responses, privacy, writes, testing, routes |
| 0C.3 | Add guide reference to `iznik-server-go/CLAUDE.md` | ✅ Done | Added prominent reference at top of file |

---

## Phase 1: Quick Wins - Client Switchovers (No Go Changes)

These endpoints already have v2 Go implementations. Only client code changes needed. Lowest risk, highest velocity.

### 1A: FD Endpoints Already on V2 (Verify & Complete)

| # | Endpoint | V2 Status | FD Status | RALPH Task |
|---|----------|-----------|-----------|------------|
| 1 | /job | ✅ Go done | ✅ FD done | - |
| 2 | /donations GET | ✅ Go done | ✅ FD done | - |
| 3 | /giftaid | ✅ Go done | ✅ FD done | - |
| 4 | /logo | ✅ Go done | ✅ FD done | - |
| 5 | /microvolunteering GET | ✅ Go done | ✅ FD done | - |
| 6 | /user/byemail | ✅ Go done | ✅ FD done | - |

### 1B: MT Switchovers (V2 Exists, MT Still Uses V1)

| # | Endpoint | MT v1 Calls | Status | RALPH Task |
|---|----------|-------------|--------|------------|
| 7 | /chat GET | 16 | ⏳ Deferred | `Switch MT chat GETs to v2` - Deferred to dedicated phase. 16+ v1 calls, complex UNION SQL with hardcoded chattypes, review system, unseen counts. Too complex for this PR. |
| 8 | /config GET | 1 | ✅ Done | ConfigAPI.js already uses `$getv2` |
| 9 | /location GET | 5 | 🔄 Partial | `Switch MT location GETs to v2` - LatLng + typeahead switched. Added ontn to ClosestGroup, area info to Typeahead. Bounds/dodgy spatial queries remain on v1 (complex). |
| 10 | /story GET | 8 | ✅ Done | `Switch MT story GETs to v2` - Go: added reviewed/public/newsletterreviewed filters, dynamic SQL. Nuxt: fetchMT uses v2 list→fetch pattern, ModStoryReview fetches user separately. |
| 11 | /authority GET | FD+MT | ✅ Done | `Update FD+MT to use /authority v2` - Switched fetch() to $getv2, updated store to handle unwrapped response. Branch: feature/v2-mt-switchovers |

**Task pattern for 1B:**
1. Read MT API wrapper to identify v1 calls.
2. Check v2 response format matches what MT expects (may need adapter).
3. Switch `$get` to `$getv2` in MT API wrapper.
4. Chrome MCP: login to MT, navigate to page using the endpoint, verify it works.
5. Run Playwright tests.
6. Do NOT modify v1 PHP code yet.

---

## Phase 2: Simple Write Endpoints (No Email)

These endpoints perform DB writes but don't send email. Straightforward Go implementation.

**Branch strategy:** New feature branch per 3-4 endpoints. Branches may depend on other branches (e.g., `feature/v2-volunteering-writes` depends on `feature/v2-background-tasks`). Merge order matters at release time.

| # | Endpoint | Verbs | FD Usages | Status | RALPH Task |
|---|----------|-------|-----------|--------|------------|
| 12 | /address | PATCH, PUT | 5 | 🔄 PR Ready | Go PR #8, FD PR #45, Nuxt3 PR #149. CI green. Awaiting merge. |
| 13 | /isochrone | PUT, POST, PATCH | 2 | ⏳ Deferred | Create/Edit need Mapbox/ORS API calls for polygon generation. DELETE is simple but useless alone. Move to Phase 4. |
| 14 | /notification POST | Seen, AllSeen | 3 | ✅ Done | Already migrated (2025-12-13). See "Already Migrated" section. |
| 15 | /messages POST | MarkSeen | 1 | 🔄 PR Ready | Go PR #7, FD PR #44, Nuxt3 PR #148. CI green. Awaiting merge. |
| 16 | /newsfeed POST | Love, Unlove, Report, etc | 10 | 🔄 PR Ready | Go PR #11, FD PR #48, Nuxt3 PR #152. CI green. Awaiting merge. |
| 17 | /volunteering | POST, PATCH, DELETE | 5 | 🔄 PR Ready | Go PR #9, FD PR #46, Nuxt3 PR #150. CI green. Awaiting merge. |
| 18 | /communityevent | POST, PATCH, DELETE | FD+MT | 🔄 PR Ready | Go PR #10, FD PR #47, Nuxt3 PR #151. CI green. Awaiting merge. |
| 19 | /image | POST | FD file upload | 🔄 PR Ready | Go PR #15, FD PR #52, Nuxt3 PR #155. External UID + rotate. |
| 20 | /comment | POST, PATCH, DELETE | MT | 🔄 PR Ready | Go PR #12, FD PR #49, Nuxt3 PR #153. CI green. Awaiting merge. |

---

## Phase 3: Email-Dependent Endpoints

These require the email queue (Phase 0A) to be complete first.

| # | Endpoint | Email Types | Status | RALPH Task |
|---|----------|-------------|--------|------------|
| 21 | /session | forgot_password, unsubscribe | ⬜ Pending | `Migrate /session to v2 with email queue` |
| 22 | /user writes | welcome, verify_email | ⬜ Pending | `Migrate /user write ops to v2` |
| 23 | /memberships | modmail | ⬜ Pending | `Migrate /memberships to v2` |
| 24 | /message writes | modmail, welcome | ⬜ Pending | `Migrate /message write ops to v2` |
| 25 | /chatmessages POST | chat_notification | ⬜ Pending | `Migrate /chatmessages POST to v2` |
| 26 | /chatrooms POST | Various actions | ⬜ Pending | `Migrate /chatrooms POST to v2` |
| 27 | /merge | merge_offer | ⬜ Pending | `Migrate /merge to v2` |
| 28 | /invitation | invitation | 🔄 PR Ready | Go PR #17, FD PR #54, Nuxt3 PR #157. GET/PUT/PATCH migrated. DELETE stays v1 (rarely used). |
| 29 | /donations PUT | donate_external | 🔄 PR Ready | Go PR #16, FD PR #53, Nuxt3 PR #156. External donation + GiftAid notif + email queue. |

**Task pattern for Phase 3:**
1. Verify Laravel Mailable exists for the email type (create if not).
2. Add email type handler to `ProcessEmailQueueCommand`.
3. Write Go test for the endpoint (TDD: test fails first).
4. Implement Go handler using `email.QueueEmail()`.
5. Verify test passes.
6. Chrome MCP: trigger the action, check MailPit for email delivery.
7. Switch FD client to `$postv2` / `$patchv2`.
8. Switch MT client if sensible (same API wrapper method).
9. Add deprecation comment to PHP.

---

## Phase 4: Complex/MT-Heavy Endpoints

These are more complex, often MT-specific, or have intricate business logic.

| # | Endpoint | Verbs | Complexity | Status | RALPH Task |
|---|----------|-------|------------|--------|------------|
| 30 | /group PATCH | Update settings | High (many fields) | ⬜ Pending | `Migrate /group PATCH to v2` |
| 31 | /modconfig | GET, PATCH, POST, DELETE | MT-specific | ⬜ Pending | `Migrate /modconfig to v2` |
| 32 | /stdmsg | GET, PATCH, POST, DELETE | MT-specific | ⬜ Pending | `Migrate /stdmsg to v2` |
| 33 | /spammers | All | Complex MT moderation | ⬜ Pending | `Migrate /spammers to v2` |
| 34 | /socialactions | POST | FD social | ⬜ Pending | `Migrate /socialactions to v2` |
| 35 | /shortlink | POST | URL shortening | ⬜ Pending | `Migrate /shortlink to v2` |
| 36 | /noticeboard | POST, PATCH, DELETE | FD+MT | ⬜ Pending | `Migrate /noticeboard write ops to v2` |
| 37 | /stripe* | POST | Payment integration | ⬜ Pending | `Migrate /stripe endpoints to v2` |

---

## Phase 5: Remaining Endpoints & Cleanup

### 5A: Low-Traffic/Niche Endpoints

| # | Endpoint | Status | Notes |
|---|----------|--------|-------|
| 38 | /dashboard | ⬜ Pending | MT admin dashboard |
| 39 | /logs | ⬜ Pending | System logs (may keep v1 for compatibility) |
| 40 | /team | ⬜ Pending | Team management |
| 41 | /tryst | ⬜ Pending | User meetup scheduling |
| 42 | /abtest | ⬜ Pending | A/B testing |
| 43 | /visualise | ⬜ Pending | Data visualisation |
| 44 | /usersearch | ⬜ Pending | MT user search |
| 45 | /status | ⬜ Pending | System status |

### 5B: Candidates for Removal (No Client Usage Found)

These v1 endpoints appear unused. Verify before removing.

| Endpoint | Notes |
|----------|-------|
| bulkop.php | No API wrapper or direct calls. Check batch jobs. |
| changes.php | ✅ MIGRATED to V2 Go (5902b87). Partner key auth. CI GREEN on master. |
| error.php | Logging utility. Check if still receives requests. |
| export.php | May be used via direct browser access. |
| item.php | Items are accessed via message endpoint. |
| mentions.php | No API wrapper. |
| poll.php | No API wrapper. |
| profile.php | Profiles handled via UserAPI. |
| request.php | No API wrapper. |
| src.php | Already migrated to v2. Safe to deprecate. |

**Verification method:** Check Loki logs for v1 API hits to these endpoints over 30 days before removal.

### 5C: Adversarial Code Review

A dedicated review phase to catch missed functionality.

| # | Task | Status | Notes |
|---|------|--------|-------|
| 5C.1 | Compare v1 vs v2 for each migrated endpoint | ⬜ Pending | Line-by-line PHP→Go comparison |
| 5C.2 | Check for side effects in v1 not replicated in v2 | ⬜ Pending | DB updates, cache invalidation, logging |
| 5C.3 | Check for permission/authorization differences | ⬜ Pending | Mod-only, admin-only, owner-only checks |
| 5C.4 | Check for data transformation differences | ⬜ Pending | Date formats, null handling, privacy filtering |
| 5C.5 | Write adversarial tests | ⬜ Pending | Edge cases: empty data, large payloads, concurrent requests |
| 5C.6 | Verify email side effects | ⬜ Pending | Ensure all emails sent by v1 are also queued by v2 |
| 5C.7 | Check MT-specific behaviours | ⬜ Pending | Moderation actions, bulk operations, review queues |

---

## Phase 6: Migration Review & Validation Gate

This phase runs **after all endpoint migrations** and acts as a go/no-go gate before retiring v1 code. Execute this systematically for every migrated endpoint. Nothing is considered done until it passes this phase.

### 6A: Per-Endpoint Migration Checklist

For **each** migrated endpoint, complete every item. Track in a separate file `plans/active/api-migration-review-log.md` with a section per endpoint.

#### Functional Completeness
- [ ] Every `case` in the PHP `switch($action)` has a corresponding Go handler or documented reason for omission
- [ ] Every PHP method/function called within the endpoint has been accounted for
- [ ] All query parameters accepted by v1 are accepted by v2 (compare `$_REQUEST` keys)
- [ ] All POST/PATCH body fields accepted by v1 are accepted by v2
- [ ] Default values match (when parameter omitted, same behaviour)
- [ ] Return value structure matches (field names, nesting, types)
- [ ] Empty/null return handling matches (empty array `[]` not `null`, etc.)

#### Authentication & Authorization
- [ ] Anonymous access: v1 allows it ↔ v2 allows it (or both deny)
- [ ] Logged-in user: same permissions required
- [ ] `$me->isAdminOrSupport()` checks replicated as `user.IsAdmin(c)` or equivalent
- [ ] `$me->isModerator()` checks replicated with correct group scope
- [ ] Owner-only checks: `$me->getId() == $resource->getUserId()` → `myid == resource.Userid`
- [ ] Session/JWT: v2 correctly reads auth from both cookie sessions and Bearer tokens

#### Database Operations
- [ ] Every `$dbhm->exec()` / `$dbhm->preExec()` write operation exists in Go
- [ ] Every `$dbhr->preQuery()` read operation exists in Go
- [ ] Transaction boundaries match (v1 transaction → v2 transaction, not split)
- [ ] Auto-increment ID return: if v1 returns `$dbhm->lastInsertId()`, v2 returns equivalent

#### Side Effects
- [ ] Every `Mail::send()` / `$this->mailer->send()` → `email.QueueEmail()` with correct type
- [ ] Every `error_log()` or logging call → Loki log or equivalent
- [ ] Cache invalidation: if v1 clears cache, v2 does too
- [ ] External API calls (e.g., Stripe, Google) → replicated in v2
- [ ] Notification creation (push, email digest triggers) → replicated
- [ ] Activity logging (logs table entries) → replicated

#### Data Transformation & Privacy
- [ ] Date/time formats match in response JSON
- [ ] Null vs missing field handling matches
- [ ] Privacy filtering: emails/phones/addresses hidden from non-owners
- [ ] HTML encoding/escaping: same treatment of user-generated content
- [ ] Pagination: offset/limit/context parameters produce same results

#### Error Handling
- [ ] Error HTTP status codes match (400, 401, 403, 404, 500)
- [ ] Error response body format matches (clients may parse error messages)
- [ ] Specific error strings match if clients depend on them (e.g., "Not logged in")
- [ ] Rate limiting / abuse prevention is equivalent or better

#### Client Integration
- [ ] FD client switched to `$getv2`/`$postv2` (or confirmed not using this endpoint)
- [ ] MT client switched or documented as deferred to separate task
- [ ] No remaining `$get`/`$post` calls to this endpoint in client code (grep verified)
- [ ] Response adapter not needed (or adapter in place and tested if format differs)

#### Testing
- [ ] Go unit tests cover happy path, auth failure, validation errors, edge cases
- [ ] Go test coverage ≥90% on new handler code
- [ ] At least one Playwright E2E test exercises the v2 codepath end-to-end
- [ ] Chrome MCP sanity check completed and documented (screenshot or notes)
- [ ] Swagger annotations present and `generate-swagger.sh` run

#### Deprecation
- [ ] PHP file has deprecation comment with date and v2 endpoint paths
- [ ] PHP code is **not** deleted (kept functional until production confirmation)
- [ ] Plan status table updated to show completion

### 6B: Cross-Cutting Review

After individual endpoint reviews, check system-wide concerns.

| # | Task | Status | Notes |
|---|------|--------|-------|
| 6B.1 | Grep FD codebase for remaining `$get(` / `$post(` v1 calls | ⬜ Pending | Should be zero for migrated endpoints |
| 6B.2 | Grep MT codebase for remaining v1 calls | ⬜ Pending | Document any intentionally deferred |
| 6B.3 | Check Loki logs for v1 traffic to migrated endpoints | ⬜ Pending | 30-day window post-deploy |
| 6B.4 | Verify email queue processes all email types end-to-end | ⬜ Pending | Send test email for each type |
| 6B.5 | Run full Playwright suite against v2-only config | ⬜ Pending | Disable v1 fallback temporarily |
| 6B.6 | Load test key endpoints (message, chat, user) | ⬜ Pending | Verify goroutine parallelism delivers |
| 6B.7 | Check for orphaned v1 routes still registered | ⬜ Pending | Review PHP router config |
| 6B.8 | Verify Swagger docs are complete and accurate | ⬜ Pending | Every v2 endpoint documented |
| 6B.9 | Review error monitoring (Sentry) for v2 errors post-deploy | ⬜ Pending | New error patterns? |
| 6B.10 | Confirm no hardcoded v1 URLs in external integrations | ⬜ Pending | TN, webhooks, email links |

### 6C: Sign-Off

| # | Task | Status | Notes |
|---|------|--------|-------|
| 6C.1 | All per-endpoint checklists 100% complete | ⬜ Pending | No unchecked items |
| 6C.2 | Cross-cutting review items all pass | ⬜ Pending | |
| 6C.3 | CI green on all 4 test suites | ⬜ Pending | Final run on master |
| 6C.4 | Production deploy of v2 confirmed | ⬜ Pending | Swagger accessible at prod URL |
| 6C.5 | 30-day monitoring period complete | ⬜ Pending | No regressions |
| 6C.6 | v1 retirement approved by human | ⬜ Pending | Only then remove PHP code |

---

## RALPH Task Procedure (Per Endpoint Migration)

### TDD Workflow

```
1. ANALYSE: Read v1 PHP endpoint. Document all actions, permissions, side effects.
2. TEST (RED): Write Go test for the endpoint. Test MUST fail (endpoint doesn't exist yet).
3. IMPLEMENT (GREEN): Write Go handler. Minimal code to pass the test.
4. TEST (GREEN): Verify test passes.
5. EXPAND: Write additional tests (edge cases, auth, errors). Verify all pass.
6. COVERAGE: Check code coverage is 90%+ on new code.
7. SWAGGER: Add annotations, run generate-swagger.sh.
8. LOCAL VERIFY: Run all 4 test suites via status container.
9. CHROME MCP: Login to FD, exercise the endpoint via the UI, verify correct behaviour.
10. CLIENT SWITCH (FD): Update iznik-nuxt3/api/XxxAPI.js: $get → $getv2 etc.
11. CLIENT SWITCH (MT): If same API wrapper method used, update MT too.
12. PLAYWRIGHT: Ensure at least one E2E test exercises the new v2 codepath.
13. DEPRECATE v1: Add deprecation comment to PHP file (keep code functional).
14. COMMIT & PUSH: Wait for CI green.
15. UPDATE PLAN: Mark endpoint status in this document.
16. REVIEW CHECKLIST: Complete Phase 6A checklist for this endpoint in api-migration-review-log.md.
```

### Deprecation Comment Format

```php
// DEPRECATED - Migrated to v2 Go API.
// Migrated: YYYY-MM-DD
// V2 endpoints: GET /apiv2/endpoint, POST /apiv2/endpoint
// DO NOT REMOVE until v2 confirmed live in production.
```

### Chrome MCP Sanity Check

After implementing each endpoint, use Chrome DevTools MCP to verify:

1. Navigate to the relevant FD/MT page that uses the endpoint.
2. Open Network tab, filter to the endpoint URL.
3. Verify the request goes to `/apiv2/` (not `/api/`).
4. Verify the response contains expected data.
5. Verify the UI renders correctly with v2 data.

---

## Branch & PR Management

### Feature Branch Strategy

```
feature/v2-migration-phase1-mt-switchovers     (tasks 7-11)
feature/v2-migration-phase2a-simple-writes      (tasks 12-15)
feature/v2-migration-phase2b-simple-writes      (tasks 16-20)
feature/v2-migration-email-queue                (tasks 0A.1-0A.6)
feature/v2-migration-phase3a-email-endpoints    (tasks 21-24)
feature/v2-migration-phase3b-email-endpoints    (tasks 25-29)
feature/v2-migration-phase4a-complex            (tasks 30-33)
feature/v2-migration-phase4b-complex            (tasks 34-37)
```

**Rules:**
- Max 3-4 endpoint migrations per branch/PR.
- Each PR must pass all 4 CI test suites before merge.
- Backend (Go) and frontend (client switch) can be in the same PR if the Go changes are backwards-compatible (i.e. new endpoints, not replacing).
- If Go changes require deployment before client switch, split into separate PRs.

**No v2 API work on master (CRITICAL):**
- NEVER commit v2 API migration work directly to master. Only bug fixes required to keep master CI green belong on master.
- All v2 migration work (Go endpoints, client switches, tests for new endpoints) stays on feature branches until the full migration is complete and reviewed.
- Deprecation comments and plan/docs updates are OK on master since they don't change runtime behaviour.

**Branch Chaining (CRITICAL):**
- Each feature branch MUST be based on the previous successful branch, not independently on master.
- Chain: `master` → `migration-foundation` → `phase1` → `phase2a` → `phase2b` → etc.
- When a fix is needed: fix on the earliest affected branch (ideally master), then merge forward through the chain.
- This ensures no fixes are lost and each branch includes all prior work.
- NEVER create a new feature branch from master if there's an existing chain - always branch from the tip.

### Long-Running Ralph Execution

For unattended multi-hour sessions, use ralph's `-t` flag with high iteration counts:

```bash
# Run Phase 2 endpoints unattended
./ralph.sh plans/active/v1-to-v2-api-migration.md 30

# Or create focused task files for each batch
./ralph.sh -t "Migrate /address, /isochrone, /notification write ops to v2 API. Follow the V2 API Style Guide in iznik-server-go/API-GUIDE.md. Use TDD: write failing tests first. Switch FD client. Chrome MCP verify. Update plan status." 20
```

---

## Already Migrated (Complete)

| # | Endpoint | Verbs | Completed | Deprecated |
|---|----------|-------|-----------|------------|
| 1 | /job | GET, POST | 2025-09-30 | ✅ donations.php |
| 2 | /donations GET | GET | 2025-10-01 | ✅ donations.php |
| 3 | /giftaid | GET | 2025-10-13 | ✅ giftaid.php |
| 4 | /logo | GET | 2025-10-13 | ✅ logo.php |
| 5 | /microvolunteering GET | GET | 2025-10-14 | ✅ microvolunteering.php |
| 6 | /user/byemail | GET | 2025-10-17 | ✅ user.php (partial) |
| - | /notification POST | Seen, AllSeen | 2025-12-13 | ✅ notification.php |
| - | /src | POST | Migrated | ✅ src.php |

---

## Statistics

- **Total v1 PHP endpoints**: 59 files
- **Already migrated to v2**: 8 endpoints (GET operations)
- **V2 endpoints exist but MT still uses v1**: 4 (chat, config, location, story GETs)
- **Simple write endpoints (no email)**: 9 endpoints
- **Email-dependent endpoints**: 9 endpoints
- **Complex/MT-heavy endpoints**: 8 endpoints
- **Low-traffic/niche**: 8 endpoints
- **Candidates for removal**: 10 endpoints
- **Estimated total RALPH tasks**: ~45 (excluding removals)

---

## Complete PHP Endpoint List

All 59 endpoints in `/iznik-server/http/api/`:

```
abtest.php         authority.php      bulkop.php         changes.php
chatmessages.php   chatrooms.php      comment.php        communityevent.php
config.php         dashboard.php      domains.php        donations.php
error.php          export.php         giftaid.php        group.php
groups.php         image.php          invitation.php     isochrone.php
item.php           jobs.php           locations.php      logo.php
logs.php           memberships.php    mentions.php       merge.php
message.php        messages.php       microvolunteering.php modconfig.php
newsfeed.php       notification.php   noticeboard.php    poll.php
profile.php        request.php        session.php        shortlink.php
socialactions.php  spammers.php       src.php            status.php
stdmsg.php         stories.php        stripecreateintent.php
stripecreatesubscription.php          team.php           tryst.php
user.php           usersearch.php     visualise.php      volunteering.php
```

---

## V1 vs V2 Transformation Patterns

### Pattern 1: Simple Data (No Nesting)
```go
func GetDomain(c *fiber.Ctx) error {
    var data DomainType
    db.Raw("SELECT ... FROM domain WHERE id = ?", id).Scan(&data)
    return c.JSON(data)
}
```

### Pattern 2: Parallel Fetch with Goroutines
```go
func GetDomain(c *fiber.Ctx) error {
    var wg sync.WaitGroup
    var domain DomainBase
    var items []Item

    wg.Add(2)
    go func() { defer wg.Done(); db.Raw("...").Scan(&domain) }()
    go func() { defer wg.Done(); db.Raw("...").Scan(&items) }()
    wg.Wait()

    domain.Items = items
    return c.JSON(domain)
}
```

### Pattern 3: Privacy Filtering
```go
func GetDomain(c *fiber.Ctx) error {
    myid := user.WhoAmI(c)
    // ... fetch data ...
    if data.OwnerID != myid {
        data.Email = ""
        data.Phone = ""
        data.Textbody = hideEmails(data.Textbody)
    }
    return c.JSON(data)
}
```

### Pattern 4: Write with Email Queue
```go
func PostDomain(c *fiber.Ctx) error {
    myid := user.WhoAmI(c)
    if myid == 0 {
        return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
    }
    // ... validate & write to DB ...
    email.QueueEmail("domain_action", myid, groupID, 0, map[string]interface{}{
        "action": "created",
    })
    return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

### Pattern 5: Action-Based POST (Multiple Actions in One Endpoint)
```go
// V1 PHP uses switch ($action) { case 'Seen': ... case 'AllSeen': ... }
// V2 Go splits into separate routes for cleaner REST:
app.Post("/api/notification/seen", notification.Seen)
app.Post("/api/notification/allseen", notification.AllSeen)

// OR if actions are simple, use query parameter:
func PostDomain(c *fiber.Ctx) error {
    action := c.Query("action")
    switch action {
    case "Seen":
        return handleSeen(c)
    case "AllSeen":
        return handleAllSeen(c)
    default:
        return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
    }
}
```
