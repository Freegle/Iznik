# Content Check Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route all platform-submitted messages through a Laravel batch check pipeline before they become visible to moderators, running a unified set of content checks and surfacing specific failures in the pending queue.

**Architecture:** Every submitted message starts as Pending with `contentcheck_checked_at IS NULL` (invisible to mods, no push notification). The `messages:contentcheck` Laravel command runs every minute, executes all content checks against the message, then either promotes clean messages from non-moderated users to Approved (queuing freebiealerts) or leaves them in Pending (setting reasons in `messages_groups.contentcheck_reasons`, then queuing the mod push notification). The mod queue adds a 5-minute fallback so messages never disappear if the batch job is down.

**Tech Stack:** Go 1.21 (Fiber, GORM), PHP 8.3 (Laravel 12), MySQL 8, PHPUnit, Go testing package. Tests run via status container API (`curl -s -X POST http://localhost:8081/api/tests/go` and `/laravel`).

---

## File Map

### New files
- `iznik-batch/database/migrations/2026_05_08_000001_add_contentcheck_columns_to_messages_groups.php` — adds `contentcheck_checked_at` + `contentcheck_reasons` to `messages_groups`, backfills existing rows
- `iznik-batch/app/Services/ContentCheckService.php` — all content checks + promotion logic
- `iznik-batch/app/Console/Commands/Message/ContentCheckCommand.php` — artisan command wrapper
- `iznik-batch/tests/Feature/Message/ContentCheckTest.php` — PHPUnit tests

### Modified files
- `iznik-batch/routes/console.php` — schedule `messages:contentcheck` every minute
- `iznik-batch/app/Services/AutoApproveService.php` — skip messages not yet contentcheck-checked
- `iznik-server-go/message/messageGroup.go` — add `ContentcheckCheckedAt` + `ContentcheckReasons` fields
- `iznik-server-go/message/message.go` — submission: all messages start Pending, suppress go-side notification
- `iznik-server-go/message/message_list.go` — filter unprocessed messages from pending mod queue
- `iznik-server-go/test/message_test.go` — update submission tests, add contentcheck visibility tests
- `iznik-nuxt3/modtools/components/ModMessageWorry.vue` — show contentcheck failure reasons

---

## Task 1: DB Migration — content check columns on messages_groups

**Files:**
- Create: `iznik-batch/database/migrations/2026_05_08_000001_add_contentcheck_columns_to_messages_groups.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('contentcheck_checked_at')->nullable()->after('spamreason');
            $table->json('contentcheck_reasons')->nullable()->after('contentcheck_checked_at');
        });

        // Backfill all existing Pending rows so they remain visible to mods.
        // New rows submitted after this migration will have NULL (unprocessed).
        DB::statement("UPDATE messages_groups SET contentcheck_checked_at = NOW() WHERE collection = 'Pending' AND contentcheck_checked_at IS NULL");
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropColumn(['contentcheck_checked_at', 'contentcheck_reasons']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
docker exec freegle-batch php artisan migrate
```

Expected output: `Running migrations... 2026_05_08_000001_add_contentcheck_columns_to_messages_groups ........ DONE`

- [ ] **Step 3: Verify columns exist**

```bash
docker exec freegle-batch php artisan tinker --execute="DB::select('DESCRIBE messages_groups');" | grep contentcheck
```

Expected: two rows showing `contentcheck_checked_at` and `contentcheck_reasons`.

- [ ] **Step 4: Commit**

```bash
git add iznik-batch/database/migrations/2026_05_08_000001_add_contentcheck_columns_to_messages_groups.php
git commit -m "feat(contentcheck): add contentcheck_checked_at + contentcheck_reasons columns to messages_groups"
```

---

## Task 2: Go API — all submissions start Pending, no notification at submit time

**Files:**
- Modify: `iznik-server-go/message/message.go` (lines ~2096–2200)
- Modify: `iznik-server-go/test/message_test.go`

The Go API currently routes messages directly to Approved when `ourPostingStatus` is an explicit non-moderated value. We remove that branch. We also remove the `QueueTask(TaskPushNotifyGroupMods)` call from `handleSubmit` — the batch job fires that after checking.

- [ ] **Step 1: Write the failing Go test**

Add to `iznik-server-go/test/message_test.go`. This test currently passes (the message goes to Approved) — after the change it should go to Pending:

```go
// TestJoinAndPostUnmoderatedUserStartsPending verifies that even a user with
// an explicit non-moderated posting status starts in Pending (awaiting contentcheck).
func TestJoinAndPostUnmoderatedUserStartsPending(t *testing.T) {
    prefix := uniquePrefix("msgmod_jap_unmod")
    db := database.DBConn

    groupID := CreateTestGroup(t, prefix)
    userID := CreateTestUser(t, prefix+"_user", "User")
    _, token := CreateTestSession(t, userID)

    // Explicitly non-moderated posting status — previously this caused Approved.
    CreateTestMembership(t, userID, groupID, "Member")
    db.Exec("UPDATE memberships SET ourPostingStatus = 'DEFAULT' WHERE userid = ? AND groupid = ?", userID, groupID)

    db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) VALUES (?, 'Offer', 'Offer: Unmod chair', 'A chair', 'A chair', NOW(), NOW(), 'Platform')", userID)
    var msgID uint64
    db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
    require.NotZero(t, msgID)
    db.Exec("INSERT INTO messages_drafts (msgid, groupid, userid) VALUES (?, ?, ?)", msgID, groupID, userID)

    body := map[string]interface{}{"id": msgID, "action": "JoinAndPost"}
    bodyBytes, _ := json.Marshal(body)
    req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(bodyBytes))
    req.Header.Set("Content-Type", "application/json")
    resp, err := getApp().Test(req)
    require.NoError(t, err)
    require.Equal(t, 200, resp.StatusCode)

    // Must start Pending (contentcheck will promote to Approved after checking).
    var collection string
    db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&collection)
    assert.Equal(t, "Pending", collection, "all submissions must start Pending for contentcheck processing")

    // No push_notify_group_mods task should be queued at submit time.
    var taskCount int64
    db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'push_notify_group_mods' AND processed_at IS NULL AND data LIKE ?",
        fmt.Sprintf(`%%"group_id":%d%%`, groupID)).Scan(&taskCount)
    assert.Equal(t, int64(0), taskCount, "push notification must not be queued at submit time — batch job does that")
}
```

- [ ] **Step 2: Run to verify it fails**

```bash
curl -s -X POST http://localhost:8081/api/tests/go && sleep 5 && curl -s http://localhost:8081/api/tests/go/status
```

Expected: test run starts; `TestJoinAndPostUnmoderatedUserStartsPending` fails with `expected Pending got Approved`.

- [ ] **Step 3: Remove the Approved routing branch from handleSubmit in message.go**

In `iznik-server-go/message/message.go`, find the block around line 2104 and remove the branch that sets `collection = utils.COLLECTION_APPROVED`. Change:

```go
// Determine collection based on user's posting status.
collection := utils.COLLECTION_PENDING
var ourPostingStatus *string
db.Raw("SELECT ourPostingStatus FROM memberships WHERE userid = ? AND groupid = ?", myid, groupid).Scan(&ourPostingStatus)

if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
    return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post on this group")
}

if ourPostingStatus != nil &&
    !strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_MODERATED) &&
    !strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) &&
    *ourPostingStatus != "" {
    // Explicit non-moderated status (e.g. set by mod after reviewing posts) → Approved.
    collection = utils.COLLECTION_APPROVED
}

// Allow the caller to force the message to Pending, e.g. for bulk posts
// that should always be moderated before becoming visible.
if req.ForcePending != nil && *req.ForcePending {
    collection = utils.COLLECTION_PENDING
}
```

To:

```go
// All messages start Pending — content check batch job promotes clean messages to Approved.
collection := utils.COLLECTION_PENDING
var ourPostingStatus *string
db.Raw("SELECT ourPostingStatus FROM memberships WHERE userid = ? AND groupid = ?", myid, groupid).Scan(&ourPostingStatus)

if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
    return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post on this group")
}
```

- [ ] **Step 4: Remove the push notification from handleSubmit**

Further down in `handleSubmit` (around line 2194), remove the notification block entirely:

```go
// DELETE this entire block:
if collection == utils.COLLECTION_PENDING {
    if err := queue.QueueTask(queue.TaskPushNotifyGroupMods, map[string]interface{}{
        "group_id": groupid,
    }); err != nil {
        log.Printf("Failed to queue push notification for group %d on submit: %v", groupid, err)
    }
}
```

The `TaskFreebieAlertsAdd` block below it (gated on `collection == utils.COLLECTION_APPROVED`) is also now dead code — remove it too:

```go
// DELETE this entire block:
if collection == utils.COLLECTION_APPROVED && msgType == "Offer" {
    if err := queue.QueueTask(queue.TaskFreebieAlertsAdd, map[string]interface{}{
        "msgid": req.ID,
    }); err != nil {
        log.Printf("Failed to queue freebie alerts add for message %d: %v", req.ID, err)
    }
}
```

Both notifications are now fired by the content check batch job after processing.

- [ ] **Step 5: Update the existing ForcePending test comment**

In `message_test.go` find `TestJoinAndPostForcePendingOverridesApproved` (line ~1763) and update its comment — the test still passes (message is Pending) but the reason changed:

```go
// TestJoinAndPostForcePendingOverridesApproved verifies that forcepending=true
// results in a Pending message. All messages now start Pending regardless, so
// forcepending is a no-op but must not cause errors.
```

- [ ] **Step 6: Rebuild the Go API**

```bash
cd iznik-server-go && ./build.sh
```

Expected: build succeeds with no errors.

- [ ] **Step 7: Run Go tests**

```bash
curl -s -X POST http://localhost:8081/api/tests/go
```

Wait for completion, then check:
```bash
curl -s http://localhost:8081/api/tests/go/status
```

Expected: all tests pass including `TestJoinAndPostUnmoderatedUserStartsPending`.

- [ ] **Step 8: Commit**

```bash
git add iznik-server-go/message/message.go iznik-server-go/test/message_test.go
git commit -m "feat(contentcheck): all submissions start Pending; remove go-side notification at submit"
```

---

## Task 3: Go API — filter unprocessed messages from mod queue + expose contentcheck_reasons

**Files:**
- Modify: `iznik-server-go/message/messageGroup.go`
- Modify: `iznik-server-go/message/message_list.go`
- Modify: `iznik-server-go/message/message.go` (GetMessagesByIds goroutine)
- Modify: `iznik-server-go/test/message_test.go`

The pending mod queue must hide messages with `contentcheck_checked_at IS NULL`. A 5-minute fallback ensures mods can see messages if the batch job is down. `contentcheck_reasons` must be returned in the message response so the frontend can display them.

- [ ] **Step 1: Add fields to MessageGroup struct**

In `iznik-server-go/message/messageGroup.go`, add:

```go
package message

import (
    "encoding/json"
    "time"
)

type Tabler interface {
    TableName() string
}

func (MessageGroup) TableName() string {
    return "messages_groups"
}

type MessageGroup struct {
    Groupid     uint64    `json:"groupid"`
    Msgid       uint64    `json:"msgid"`
    Arrival     time.Time `json:"arrival"`
    Collection  string    `json:"collection"`
    Autoreposts uint      `json:"autoreposts"`

    Approvedby       uint64           `json:"approvedby"`
    Heldby           *uint64          `json:"heldby,omitempty"`
    Spamtype         *string          `json:"spamtype,omitempty"`
    Spamreason       *string          `json:"spamreason,omitempty"`
    ContentcheckCheckedAt *time.Time       `json:"contentcheck_checked_at,omitempty"`
    ContentcheckReasons   *json.RawMessage `json:"contentcheck_reasons,omitempty"`
}
```

- [ ] **Step 2: Write failing tests for mod queue visibility**

Add to `iznik-server-go/test/message_test.go`:

```go
// TestAutomodUnprocessedHiddenFromPendingQueue verifies that a message with
// contentcheck_checked_at IS NULL does not appear in the pending mod queue.
func TestAutomodUnprocessedHiddenFromPendingQueue(t *testing.T) {
    prefix := uniquePrefix("contentcheck_hidden")
    db := database.DBConn

    groupID := CreateTestGroup(t, prefix)
    modID := CreateTestUser(t, prefix+"_mod", "Moderator")
    CreateTestMembership(t, modID, groupID, "Moderator")
    _, modToken := CreateTestSession(t, modID)

    userID := CreateTestUser(t, prefix+"_user", "User")
    CreateTestMembership(t, userID, groupID, "Member")

    // Create a Pending message with contentcheck_checked_at IS NULL (unprocessed).
    db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) VALUES (?, 'Offer', 'Offer: Hidden chair', 'A chair', 'A chair', NOW(), NOW(), 'Platform')", userID)
    var msgID uint64
    db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
    require.NotZero(t, msgID)
    db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, 'Pending', NOW())", msgID, groupID)
    // contentcheck_checked_at is NULL — should be hidden.

    req := httptest.NewRequest("GET", fmt.Sprintf("/api/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil)
    resp, err := getApp().Test(req)
    require.NoError(t, err)
    require.Equal(t, 200, resp.StatusCode)

    var result map[string]interface{}
    json.NewDecoder(resp.Body).Decode(&result)
    messages, _ := result["messages"].([]interface{})
    for _, m := range messages {
        assert.NotEqual(t, float64(msgID), m, "unprocessed message must not appear in pending queue")
    }
}

// TestAutomodProcessedVisibleInPendingQueue verifies that a message with
// contentcheck_checked_at set IS visible in the pending mod queue.
func TestAutomodProcessedVisibleInPendingQueue(t *testing.T) {
    prefix := uniquePrefix("contentcheck_visible")
    db := database.DBConn

    groupID := CreateTestGroup(t, prefix)
    modID := CreateTestUser(t, prefix+"_mod", "Moderator")
    CreateTestMembership(t, modID, groupID, "Moderator")
    _, modToken := CreateTestSession(t, modID)

    userID := CreateTestUser(t, prefix+"_user", "User")
    CreateTestMembership(t, userID, groupID, "Member")

    db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) VALUES (?, 'Offer', 'Offer: Visible chair', 'A chair', 'A chair', NOW(), NOW(), 'Platform')", userID)
    var msgID uint64
    db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
    require.NotZero(t, msgID)
    // contentcheck_checked_at IS SET — should be visible.
    db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, contentcheck_checked_at) VALUES (?, ?, 'Pending', NOW(), NOW())", msgID, groupID)

    req := httptest.NewRequest("GET", fmt.Sprintf("/api/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil)
    resp, err := getApp().Test(req)
    require.NoError(t, err)
    require.Equal(t, 200, resp.StatusCode)

    var result map[string]interface{}
    json.NewDecoder(resp.Body).Decode(&result)
    messages, _ := result["messages"].([]interface{})
    found := false
    for _, m := range messages {
        if m == float64(msgID) {
            found = true
        }
    }
    assert.True(t, found, "processed message must appear in pending queue")
}

// TestAutomodFallbackVisibleAfter5Minutes verifies the safety-net: a message
// with contentcheck_checked_at IS NULL but arrival > 5 minutes ago IS shown.
func TestAutomodFallbackVisibleAfter5Minutes(t *testing.T) {
    prefix := uniquePrefix("contentcheck_fallback")
    db := database.DBConn

    groupID := CreateTestGroup(t, prefix)
    modID := CreateTestUser(t, prefix+"_mod", "Moderator")
    CreateTestMembership(t, modID, groupID, "Moderator")
    _, modToken := CreateTestSession(t, modID)

    userID := CreateTestUser(t, prefix+"_user", "User")
    CreateTestMembership(t, userID, groupID, "Member")

    db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source) VALUES (?, 'Offer', 'Offer: Old unprocessed chair', 'A chair', 'A chair', NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE, 'Platform')", userID)
    var msgID uint64
    db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)
    require.NotZero(t, msgID)
    // contentcheck_checked_at IS NULL but arrival is 10 minutes ago — safety fallback should show it.
    db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, 'Pending', NOW() - INTERVAL 10 MINUTE)", msgID, groupID)

    req := httptest.NewRequest("GET", fmt.Sprintf("/api/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil)
    resp, err := getApp().Test(req)
    require.NoError(t, err)
    require.Equal(t, 200, resp.StatusCode)

    var result map[string]interface{}
    json.NewDecoder(resp.Body).Decode(&result)
    messages, _ := result["messages"].([]interface{})
    found := false
    for _, m := range messages {
        if m == float64(msgID) {
            found = true
        }
    }
    assert.True(t, found, "unprocessed message older than 5 minutes must appear in pending queue as safety fallback")
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
curl -s -X POST http://localhost:8081/api/tests/go
```

Expected: the three new contentcheck tests fail.

- [ ] **Step 4: Add the filter to ListMessagesMT in message_list.go**

In `iznik-server-go/message/message_list.go`, in the default branch of `ListMessagesMT` (around line 509), add the contentcheck filter to the Pending collection query. The filter must be applied to ALL query paths (default, searchall, searchmemb) when `collection == "Pending"`.

The cleanest approach: add a helper variable after the collection validation:

```go
// contentcheckFilter is appended to all Pending queries: hide messages that haven't
// been processed by the content check batch job yet, unless they're >5 minutes old
// (safety fallback in case the batch job is down).
contentcheckFilter := ""
if collection == utils.COLLECTION_PENDING {
    contentcheckFilter = " AND (mg.contentcheck_checked_at IS NOT NULL OR mg.arrival < NOW() - INTERVAL 5 MINUTE)"
}
```

Then append `contentcheckFilter` to every `branchSQL` that queries `messages_groups` for the Pending collection. In the default branch (line ~509):

```go
branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
    "INNER JOIN messages m ON m.id = mg.msgid " +
    "INNER JOIN users u ON u.id = m.fromuser " +
    "WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
    "AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL " +
    contentcheckFilter + " "
```

Apply the same `+ contentcheckFilter + " "` to the `searchall` and `searchmemb` branch queries at lines ~458 and ~466 and ~497.

- [ ] **Step 5: Include contentcheck_reasons in message group fetch**

In `iznik-server-go/message/message.go`, find the goroutine that loads message groups for `GetMessagesByIds` (around the line with `SELECT groupid, collection, arrival, heldby FROM messages_groups`). Extend the SELECT to include the new columns:

```go
db.Raw("SELECT groupid, collection, arrival, heldby, spamtype, spamreason, contentcheck_checked_at, contentcheck_reasons FROM messages_groups WHERE msgid = ? AND deleted = 0", msgID).Scan(&groups)
```

(GORM will map the snake_case column names to the struct fields via the json tags.)

- [ ] **Step 6: Rebuild and run Go tests**

```bash
cd iznik-server-go && ./build.sh
curl -s -X POST http://localhost:8081/api/tests/go
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add iznik-server-go/message/messageGroup.go iznik-server-go/message/message_list.go iznik-server-go/message/message.go iznik-server-go/test/message_test.go
git commit -m "feat(contentcheck): filter unprocessed pending messages from mod queue; expose contentcheck_reasons in response"
```

---

## Task 4: Laravel — ContentCheckService with all content checks

**Files:**
- Create: `iznik-batch/app/Services/ContentCheckService.php`
- Create: `iznik-batch/tests/Feature/Message/ContentCheckTest.php`

This service runs all checks on a single (msgid, groupid) pair and returns an array of failure reasons. It does NOT change database state — that is the command's job (Task 5).

The `contentcheck_reasons` JSON array stores only failures, as objects: `{"check": "WorryWord", "detail": "gun"}`. Each check method returns `null` (clean) or `["check" => "...", "detail" => "..."]`.

- [ ] **Step 1: Write the failing tests**

Create `iznik-batch/tests/Feature/Message/ContentCheckTest.php`:

```php
<?php

namespace Tests\Feature\Message;

use App\Services\ContentCheckService;
use App\Services\Mail\Incoming\SpamCheckService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentCheckTest extends TestCase
{
    private ContentCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentCheckService(new SpamCheckService());
    }

    // -------------------------------------------------------------------------
    // checkWorryWords
    // -------------------------------------------------------------------------

    public function test_worry_word_match_returns_reason(): void
    {
        DB::table('worrywords')->insert(['keyword' => 'testworryword_contentcheck', 'type' => 'Review']);

        $result = $this->service->checkWorryWords('OFFER: testworryword_contentcheck chair', 'A nice chair', null);

        $this->assertNotNull($result);
        $this->assertEquals('WorryWord', $result['check']);
        $this->assertStringContainsString('testworryword_contentcheck', $result['detail']);

        DB::table('worrywords')->where('keyword', 'testworryword_contentcheck')->delete();
    }

    public function test_clean_subject_and_body_returns_null_for_worry_words(): void
    {
        $result = $this->service->checkWorryWords('OFFER: Nice sofa', 'A lovely sofa in great condition', null);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkConcernKeywords
    // -------------------------------------------------------------------------

    public function test_concern_keyword_match_returns_reason(): void
    {
        DB::table('concern_keywords')->insert([
            'keyword' => 'testconcernkw_contentcheck',
            'type' => 'Spam',
            'action' => 'Spam',
        ]);

        $result = $this->service->checkConcernKeywords('OFFER: testconcernkw_contentcheck item', 'Some text');

        $this->assertNotNull($result);
        $this->assertEquals('ConcernKeyword', $result['check']);
        $this->assertStringContainsString('testconcernkw_contentcheck', $result['detail']);

        DB::table('concern_keywords')->where('keyword', 'testconcernkw_contentcheck')->delete();
    }

    // -------------------------------------------------------------------------
    // checkVagueItem
    // -------------------------------------------------------------------------

    public function test_vague_item_name_returns_reason(): void
    {
        $result = $this->service->checkVagueItem('stuff');
        $this->assertNotNull($result);
        $this->assertEquals('Vague', $result['check']);
    }

    public function test_vague_item_name_case_insensitive(): void
    {
        $result = $this->service->checkVagueItem('STUFF');
        $this->assertNotNull($result);
    }

    public function test_vague_item_too_short_returns_reason(): void
    {
        $result = $this->service->checkVagueItem('ab');
        $this->assertNotNull($result);
        $this->assertEquals('Vague', $result['check']);
    }

    public function test_specific_item_name_returns_null(): void
    {
        $result = $this->service->checkVagueItem('Oak dining table with four chairs');
        $this->assertNull($result);
    }

    public function test_null_item_name_returns_null(): void
    {
        $result = $this->service->checkVagueItem(null);
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkPII — phone numbers
    // -------------------------------------------------------------------------

    public function test_phone_number_in_body_with_restrict_rule_returns_reason(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['rules' => json_encode(['restrictpersonalinfo' => true])]);

        $result = $this->service->checkPII('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNotNull($result);
        $this->assertEquals('PhoneNumber', $result['check']);

        DB::table('groups')->where('id', $group->id)->update(['rules' => null]);
    }

    public function test_phone_number_without_restrict_rule_returns_null(): void
    {
        $group = $this->createTestGroup();
        // No restrictpersonalinfo rule set.

        $result = $this->service->checkPII('OFFER: Sofa', 'Call me on 07700 900123', $group->id);

        $this->assertNull($result);
    }

    public function test_no_phone_in_body_returns_null(): void
    {
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['rules' => json_encode(['restrictpersonalinfo' => true])]);

        $result = $this->service->checkPII('OFFER: Sofa', 'Collection only please', $group->id);

        $this->assertNull($result);

        DB::table('groups')->where('id', $group->id)->update(['rules' => null]);
    }

    // -------------------------------------------------------------------------
    // checkMessagingLinks
    // -------------------------------------------------------------------------

    public function test_whatsapp_invite_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Join our group: https://chat.whatsapp.com/abc123');
        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
        $this->assertStringContainsString('chat.whatsapp.com', $result['detail']);
    }

    public function test_telegram_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Contact me at https://t.me/mygroup');
        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_discord_invite_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Join https://discord.gg/xyz');
        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_signal_group_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'https://signal.group/abc');
        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_wa_me_link_returns_reason(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Message me: https://wa.me/447700900123');
        $this->assertNotNull($result);
        $this->assertEquals('MessagingLink', $result['check']);
    }

    public function test_clean_body_returns_null_for_messaging_links(): void
    {
        $result = $this->service->checkMessagingLinks('OFFER: Sofa', 'Collection from SW1A 1AA please');
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // checkMessage (integration: all checks together)
    // -------------------------------------------------------------------------

    public function test_check_message_returns_all_failures(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        DB::table('groups')->where('id', $group->id)
            ->update(['rules' => json_encode(['restrictpersonalinfo' => true])]);

        // Insert message with vague item name, phone number, and worry word.
        DB::table('worrywords')->insert(['keyword' => 'worrycheck_contentcheck', 'type' => 'Review']);
        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type' => 'Offer',
            'subject' => 'OFFER: stuff (Location) - worrycheck_contentcheck',
            'textbody' => 'Call on 07700 900456',
            'message' => 'Call on 07700 900456',
            'arrival' => now(),
            'date' => now(),
            'source' => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'stuff']);
        $itemId = DB::table('items')->where('name', 'stuff')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $reasons = $this->service->checkMessage($msgid, $group->id);

        $checkNames = array_column($reasons, 'check');
        $this->assertContains('Vague', $checkNames);
        $this->assertContains('PhoneNumber', $checkNames);
        $this->assertContains('WorryWord', $checkNames);

        DB::table('worrywords')->where('keyword', 'worrycheck_contentcheck')->delete();
        DB::table('groups')->where('id', $group->id)->update(['rules' => null]);
    }

    public function test_check_message_returns_empty_for_clean_message(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();

        $msgid = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'type' => 'Offer',
            'subject' => 'OFFER: Oak dining table (SW1A)',
            'textbody' => 'A solid oak dining table in great condition. Collection only.',
            'message' => 'A solid oak dining table in great condition. Collection only.',
            'arrival' => now(),
            'date' => now(),
            'source' => 'Platform',
        ]);
        DB::table('items')->insertOrIgnore(['name' => 'Oak dining table']);
        $itemId = DB::table('items')->where('name', 'Oak dining table')->value('id');
        DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

        $reasons = $this->service->checkMessage($msgid, $group->id);

        $this->assertEmpty($reasons);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
curl -s -X POST http://localhost:8081/api/tests/laravel
```

Expected: all `ContentCheckTest` tests fail with class-not-found or method-not-found errors.

- [ ] **Step 3: Create ContentCheckService**

Create `iznik-batch/app/Services/ContentCheckService.php`:

```php
<?php

namespace App\Services;

use App\Services\Mail\Incoming\SpamCheckService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentCheckService
{
    private const VAGUE_KEYWORDS = [
        'stuff', 'things', 'items', 'junk', 'bits', 'various', 'misc',
        'miscellaneous', 'anything', 'loads', 'bundle', 'random', 'assorted',
        'collection', 'lots', 'free stuff', 'free items', 'bits and pieces',
        'this and that', 'unwanted', 'clutter', 'rubbish', 'tat',
    ];

    private const MESSAGING_LINK_DOMAINS = [
        'chat.whatsapp.com',
        'wa.me',
        't.me',
        'telegram.me',
        'discord.gg',
        'discord.com/invite',
        'signal.group',
    ];

    public function __construct(private SpamCheckService $spamCheck) {}

    /**
     * Run all content checks for a single (msgid, groupid) pair.
     *
     * Returns array of failure reasons — empty means clean.
     * Each reason: ['check' => string, 'detail' => string]
     */
    public function checkMessage(int $msgid, int $groupid): array
    {
        $row = DB::table('messages')
            ->select('subject', 'textbody')
            ->where('id', $msgid)
            ->first();

        if (!$row) {
            return [];
        }

        $subject  = $row->subject ?? '';
        $textbody = $row->textbody ?? '';
        $combined = $subject . ' ' . $textbody;

        $itemName = DB::table('items')
            ->join('messages_items', 'items.id', '=', 'messages_items.itemid')
            ->where('messages_items.msgid', $msgid)
            ->value('items.name');

        $reasons = [];

        if ($r = $this->checkWorryWords($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkConcernKeywords($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkSpamKeywords($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkReview($subject, $textbody)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkVagueItem($itemName)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkPII($subject, $textbody, $groupid)) {
            $reasons[] = $r;
        }
        if ($r = $this->checkMessagingLinks($subject, $textbody)) {
            $reasons[] = $r;
        }

        return $reasons;
    }

    // -------------------------------------------------------------------------
    // Worry words (global + per-group from settings.spammers.worrywords)
    // -------------------------------------------------------------------------

    public function checkWorryWords(string $subject, string $textbody, ?int $groupid): ?array
    {
        $words = DB::table('worrywords')->get();

        $groupWords = [];
        if ($groupid) {
            $raw = DB::table('groups')
                ->where('id', $groupid)
                ->value(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.spammers.worrywords'))"));
            if ($raw && $raw !== 'null') {
                foreach (explode(',', $raw) as $w) {
                    $w = trim($w);
                    if ($w !== '') {
                        $groupWords[] = (object)['keyword' => strtolower($w), 'type' => 'Review'];
                    }
                }
            }
        }

        $allWords = array_merge($words->all(), $groupWords);
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach ($allWords as $word) {
            $kw = strtolower($word->keyword);
            if (str_contains($haystack, $kw)) {
                return ['check' => 'WorryWord', 'detail' => "Matched worry word '{$kw}' (type: {$word->type})"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Concern keywords (global — regulated/spam items)
    // -------------------------------------------------------------------------

    public function checkConcernKeywords(string $subject, string $textbody): ?array
    {
        $keywords = DB::table('concern_keywords')->get();
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach ($keywords as $kw) {
            $word = strtolower($kw->keyword);
            if (str_contains($haystack, $word)) {
                return ['check' => 'ConcernKeyword', 'detail' => "Matched concern keyword '{$word}' (type: {$kw->type}; action: {$kw->action})"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Spam keywords (global spam_keywords table)
    // -------------------------------------------------------------------------

    public function checkSpamKeywords(string $subject, string $textbody): ?array
    {
        $result = $this->spamCheck->checkSpamKeywords($subject . ' ' . $textbody, [
            SpamCheckService::ACTION_SPAM,
            SpamCheckService::ACTION_REVIEW,
        ]);

        if ($result === null) {
            return null;
        }

        [, $reason, $detail] = $result;
        return ['check' => 'SpamKeyword', 'detail' => $detail];
    }

    // -------------------------------------------------------------------------
    // Review checks — money symbols, untrusted links, external email addresses
    // -------------------------------------------------------------------------

    public function checkReview(string $subject, string $textbody): ?array
    {
        $reason = $this->spamCheck->checkReview($subject . ' ' . $textbody, false);
        if ($reason === null) {
            return null;
        }

        return ['check' => 'Review', 'detail' => "Content review triggered: {$reason}"];
    }

    // -------------------------------------------------------------------------
    // Vague item name
    // -------------------------------------------------------------------------

    public function checkVagueItem(?string $itemName): ?array
    {
        if ($itemName === null) {
            return null;
        }

        $lower = strtolower(trim($itemName));

        if (mb_strlen($lower) < 3) {
            return ['check' => 'Vague', 'detail' => "Item name '{$itemName}' is too short"];
        }

        foreach (self::VAGUE_KEYWORDS as $keyword) {
            if ($lower === $keyword || str_starts_with($lower, $keyword . ' ') || str_ends_with($lower, ' ' . $keyword)) {
                return ['check' => 'Vague', 'detail' => "Item name '{$itemName}' is too generic"];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // PII — phone numbers and email addresses (when group rule restrictpersonalinfo)
    // -------------------------------------------------------------------------

    public function checkPII(string $subject, string $textbody, int $groupid): ?array
    {
        $rulesJson = DB::table('groups')->where('id', $groupid)->value('rules');
        if (!$rulesJson) {
            return null;
        }
        $rules = is_string($rulesJson) ? json_decode($rulesJson, true) : $rulesJson;
        if (empty($rules['restrictpersonalinfo'])) {
            return null;
        }

        $haystack = $subject . ' ' . $textbody;

        // UK phone number detection — broad pattern covering mobile and landline formats.
        // Matches: 07700 900123, +447700900123, 0044 7700 900123, 01234 567890, etc.
        if (preg_match('/\b(?:(?:\+44|0044)\s?|0)(?:\d[\s\-]?){9,10}\b/', $haystack)) {
            return ['check' => 'PhoneNumber', 'detail' => 'Post contains what looks like a phone number'];
        }

        // External email address detection (reuse SpamCheckService pattern).
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $haystack, $m)) {
            $email = $m[0];
            $ourDomains = config('freegle.mail.internal_domains', []);
            $isOurs = str_contains($email, '@ilovefreegle.org') ||
                      str_contains($email, 'trashnothing') ||
                      str_contains($email, 'yahoogroups');
            foreach ($ourDomains as $d) {
                if (str_contains($email, '@' . $d)) {
                    $isOurs = true;
                }
            }
            if (!$isOurs) {
                return ['check' => 'EmailAddress', 'detail' => 'Post contains an external email address'];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Messaging app invite links
    // -------------------------------------------------------------------------

    public function checkMessagingLinks(string $subject, string $textbody): ?array
    {
        $haystack = strtolower($subject . ' ' . $textbody);

        foreach (self::MESSAGING_LINK_DOMAINS as $domain) {
            if (str_contains($haystack, $domain)) {
                return ['check' => 'MessagingLink', 'detail' => "Post contains a messaging app link ({$domain})"];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
curl -s -X POST http://localhost:8081/api/tests/laravel
```

Expected: all `ContentCheckTest` tests pass.

- [ ] **Step 5: Commit**

```bash
git add iznik-batch/app/Services/ContentCheckService.php iznik-batch/tests/Feature/Message/ContentCheckTest.php
git commit -m "feat(contentcheck): add ContentCheckService with full content check suite"
```

---

## Task 5: Laravel — ContentCheckCommand, promotion logic, notifications, schedule

**Files:**
- Create: `iznik-batch/app/Console/Commands/Message/ContentCheckCommand.php`
- Modify: `iznik-batch/app/Services/ContentCheckService.php` (add `processUnprocessed`)
- Modify: `iznik-batch/app/Services/AutoApproveService.php` (skip unprocessed messages)
- Modify: `iznik-batch/routes/console.php`
- Modify: `iznik-batch/tests/Feature/Message/ContentCheckTest.php`

- [ ] **Step 1: Write failing tests for processUnprocessed**

Add to `ContentCheckTest.php`:

```php
// -------------------------------------------------------------------------
// processUnprocessed — promotion and notification logic
// -------------------------------------------------------------------------

public function test_clean_unmoderated_message_is_promoted_to_approved(): void
{
    $group  = $this->createTestGroup();
    $user   = $this->createTestUser();
    $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

    $msgid = DB::table('messages')->insertGetId([
        'fromuser' => $user->id,
        'type'     => 'Offer',
        'subject'  => 'OFFER: Solid oak table (SW1A)',
        'textbody' => 'Beautiful table. Collection only.',
        'message'  => 'Beautiful table. Collection only.',
        'arrival'  => now(),
        'date'     => now(),
        'source'   => 'Platform',
    ]);
    DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
    $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
    DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

    // Start as Pending + unprocessed.
    DB::table('messages_groups')->insert([
        'msgid'      => $msgid,
        'groupid'    => $group->id,
        'collection' => 'Pending',
        'arrival'    => now(),
        // contentcheck_checked_at NULL
    ]);

    $stats = $this->service->processUnprocessed();

    $this->assertEquals(1, $stats['approved']);

    $collection = DB::table('messages_groups')
        ->where('msgid', $msgid)->value('collection');
    $this->assertEquals('Approved', $collection);

    $checkedAt = DB::table('messages_groups')
        ->where('msgid', $msgid)->value('contentcheck_checked_at');
    $this->assertNotNull($checkedAt);
}

public function test_moderated_user_message_stays_pending_with_checked_at_set(): void
{
    $group = $this->createTestGroup();
    $user  = $this->createTestUser();
    // NULL ourPostingStatus = MODERATED (default for new users).
    $this->createMembership($user, $group);

    $msgid = DB::table('messages')->insertGetId([
        'fromuser' => $user->id,
        'type'     => 'Offer',
        'subject'  => 'OFFER: Solid oak table (SW1A)',
        'textbody' => 'Beautiful table. Collection only.',
        'message'  => 'Beautiful table. Collection only.',
        'arrival'  => now(),
        'date'     => now(),
        'source'   => 'Platform',
    ]);
    DB::table('items')->insertOrIgnore(['name' => 'Solid oak table']);
    $itemId = DB::table('items')->where('name', 'Solid oak table')->value('id');
    DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);

    DB::table('messages_groups')->insert([
        'msgid'      => $msgid,
        'groupid'    => $group->id,
        'collection' => 'Pending',
        'arrival'    => now(),
    ]);

    $stats = $this->service->processUnprocessed();

    $this->assertEquals(1, $stats['kept_pending']);

    $collection = DB::table('messages_groups')->where('msgid', $msgid)->value('collection');
    $this->assertEquals('Pending', $collection);

    $checkedAt = DB::table('messages_groups')->where('msgid', $msgid)->value('contentcheck_checked_at');
    $this->assertNotNull($checkedAt, 'contentcheck_checked_at must be set even when kept pending');
}

public function test_message_with_check_failure_stays_pending_with_reasons(): void
{
    $group = $this->createTestGroup();
    $user  = $this->createTestUser();
    $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

    $msgid = DB::table('messages')->insertGetId([
        'fromuser' => $user->id,
        'type'     => 'Offer',
        'subject'  => 'OFFER: stuff (SW1A)',
        'textbody' => 'Call 07700 900999',
        'message'  => 'Call 07700 900999',
        'arrival'  => now(),
        'date'     => now(),
        'source'   => 'Platform',
    ]);
    DB::table('items')->insertOrIgnore(['name' => 'stuff']);
    $itemId = DB::table('items')->where('name', 'stuff')->value('id');
    DB::table('messages_items')->insert(['msgid' => $msgid, 'itemid' => $itemId]);
    DB::table('groups')->where('id', $group->id)
        ->update(['rules' => json_encode(['restrictpersonalinfo' => true])]);

    DB::table('messages_groups')->insert([
        'msgid'      => $msgid,
        'groupid'    => $group->id,
        'collection' => 'Pending',
        'arrival'    => now(),
    ]);

    $stats = $this->service->processUnprocessed();

    $this->assertEquals(1, $stats['kept_pending']);

    $row = DB::table('messages_groups')->where('msgid', $msgid)->first();
    $this->assertEquals('Pending', $row->collection);
    $this->assertNotNull($row->contentcheck_reasons);
    $reasons = json_decode($row->contentcheck_reasons, true);
    $checkNames = array_column($reasons, 'check');
    $this->assertContains('Vague', $checkNames);
    $this->assertContains('PhoneNumber', $checkNames);

    DB::table('groups')->where('id', $group->id)->update(['rules' => null]);
}

public function test_already_processed_messages_are_skipped(): void
{
    $group = $this->createTestGroup();
    $user  = $this->createTestUser();
    $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

    $msgid = DB::table('messages')->insertGetId([
        'fromuser' => $user->id,
        'type'     => 'Offer',
        'subject'  => 'OFFER: table (SW1A)',
        'textbody' => 'Nice table',
        'message'  => 'Nice table',
        'arrival'  => now(),
        'date'     => now(),
        'source'   => 'Platform',
    ]);
    // Already processed — contentcheck_checked_at IS SET.
    DB::table('messages_groups')->insert([
        'msgid'              => $msgid,
        'groupid'            => $group->id,
        'collection'         => 'Pending',
        'arrival'            => now(),
        'contentcheck_checked_at' => now(),
    ]);

    $stats = $this->service->processUnprocessed();

    $this->assertEquals(0, $stats['approved']);
    $this->assertEquals(0, $stats['kept_pending']);
}
```

- [ ] **Step 2: Add processUnprocessed to ContentCheckService**

Add to `iznik-batch/app/Services/ContentCheckService.php`:

```php
/**
 * Process all unprocessed pending messages.
 *
 * Returns stats: ['approved' => int, 'kept_pending' => int, 'errors' => int]
 */
public function processUnprocessed(bool $dryRun = false): array
{
    $stats = ['approved' => 0, 'kept_pending' => 0, 'errors' => 0];

    $candidates = DB::table('messages_groups as mg')
        ->join('messages as m', 'm.id', '=', 'mg.msgid')
        ->join('users as u', 'u.id', '=', 'm.fromuser')
        ->select('mg.msgid', 'mg.groupid', 'm.type as msgtype')
        ->where('mg.collection', 'Pending')
        ->whereNull('mg.contentcheck_checked_at')
        ->whereNull('mg.deleted')
        ->where('mg.deleted', 0)
        ->whereNull('m.deleted')
        ->whereNotNull('m.fromuser')
        ->whereNull('u.deleted')
        ->get();

    foreach ($candidates as $row) {
        try {
            $reasons  = $this->checkMessage((int) $row->msgid, (int) $row->groupid);
            $isModerated = $this->isUserModerated((int) $row->msgid, (int) $row->groupid)
                        || $this->isGroupModerated((int) $row->groupid);
            $promote  = empty($reasons) && !$isModerated;

            if ($dryRun) {
                $promote ? $stats['approved']++ : $stats['kept_pending']++;
                continue;
            }

            if ($promote) {
                DB::table('messages_groups')
                    ->where('msgid', $row->msgid)
                    ->where('groupid', $row->groupid)
                    ->update([
                        'collection'         => 'Approved',
                        'approvedby'         => null,
                        'approvedat'         => now(),
                        'arrival'            => now(),
                        'contentcheck_checked_at' => now(),
                        'contentcheck_reasons'    => null,
                    ]);

                // Queue freebiealerts for Offer types.
                if ($row->msgtype === 'Offer') {
                    DB::table('background_tasks')->insert([
                        'task_type' => 'freebie_alerts_add',
                        'data'      => json_encode(['msgid' => $row->msgid]),
                    ]);
                }

                $stats['approved']++;
                Log::info("ContentCheck: approved message #{$row->msgid} on group #{$row->groupid}");
            } else {
                DB::table('messages_groups')
                    ->where('msgid', $row->msgid)
                    ->where('groupid', $row->groupid)
                    ->update([
                        'contentcheck_checked_at' => now(),
                        'contentcheck_reasons'    => empty($reasons) ? null : json_encode($reasons),
                    ]);

                // Notify group mods now that the message is visible in the queue.
                DB::table('background_tasks')->insert([
                    'task_type' => 'push_notify_group_mods',
                    'data'      => json_encode(['group_id' => $row->groupid]),
                ]);

                $stats['kept_pending']++;
                Log::info("ContentCheck: kept pending message #{$row->msgid} on group #{$row->groupid}", ['reasons' => $reasons]);
            }
        } catch (\Exception $e) {
            Log::error("ContentCheck: error processing message #{$row->msgid}: " . $e->getMessage());
            $stats['errors']++;
        }
    }

    return $stats;
}

/**
 * Return true if the message's author has a moderated posting status on this group.
 * NULL or 'MODERATED' → moderated. Any explicit non-moderated value → not moderated.
 */
public function isUserModerated(int $msgid, int $groupid): bool
{
    $fromuser = DB::table('messages')->where('id', $msgid)->value('fromuser');
    if (!$fromuser) {
        return true;
    }

    $status = DB::table('memberships')
        ->where('userid', $fromuser)
        ->where('groupid', $groupid)
        ->value('ourPostingStatus');

    if ($status === null || $status === '' || strtoupper($status) === 'MODERATED') {
        return true;
    }
    if (strtoupper($status) === 'PROHIBITED') {
        return true;
    }

    return false;
}

/**
 * Return true if the group's rules have fullymoderated = true.
 */
public function isGroupModerated(int $groupid): bool
{
    $rulesJson = DB::table('groups')->where('id', $groupid)->value('rules');
    if (!$rulesJson) {
        return false;
    }
    $rules = is_string($rulesJson) ? json_decode($rulesJson, true) : $rulesJson;
    return !empty($rules['fullymoderated']);
}
```

- [ ] **Step 3: Create ContentCheckCommand**

Create `iznik-batch/app/Console/Commands/Message/ContentCheckCommand.php`:

```php
<?php

namespace App\Console\Commands\Message;

use App\Services\ContentCheckService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ContentCheckCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:contentcheck
                            {--dry-run : Show decisions without making changes}';

    protected $description = 'Process unprocessed pending messages through content checks';

    public function handle(ContentCheckService $service): int
    {
        $this->registerShutdownHandlers();
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('ContentCheck: starting run', ['dry_run' => $dryRun]);
        $this->info('Processing unprocessed pending messages...');

        $stats = $service->processUnprocessed($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Approved: {$stats['approved']}, Kept pending: {$stats['kept_pending']}, Errors: {$stats['errors']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors encountered: {$stats['errors']}");
        }

        Log::info('ContentCheck: run complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Add AutoApproveService guard**

In `iznik-batch/app/Services/AutoApproveService.php`, add `whereNotNull('messages_groups.contentcheck_checked_at')` to the candidates query to prevent 48-hour auto-approval of messages that somehow never got processed:

In the `process()` method, find:
```php
->whereRaw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) > ?', [self::PENDING_HOURS])
->get()
```

Change to:
```php
->whereRaw('TIMESTAMPDIFF(HOUR, messages_groups.arrival, NOW()) > ?', [self::PENDING_HOURS])
->whereNotNull('messages_groups.contentcheck_checked_at')
->get()
```

- [ ] **Step 5: Schedule the command**

In `iznik-batch/routes/console.php`, add after the `messages:auto-approve` block:

```php
// ContentCheck: process unprocessed pending messages through content checks.
// Runs every minute — promotes clean messages from non-moderated users to Approved,
// keeps others in Pending with failure reasons stored, then notifies group mods.
Schedule::command('messages:contentcheck')
    ->everyMinute()
    ->withoutOverlapping()
    ->sendOutputTo(cronLog('messages:contentcheck'))
    ->runInBackground();
```

- [ ] **Step 6: Run Laravel tests**

```bash
curl -s -X POST http://localhost:8081/api/tests/laravel
```

Expected: all `ContentCheckTest` tests pass.

- [ ] **Step 7: Smoke test the command**

```bash
docker exec freegle-batch php artisan messages:contentcheck --dry-run
```

Expected output: `DRY RUN — no changes will be made. Processing unprocessed pending messages... Approved: X, Kept pending: Y, Errors: 0`

- [ ] **Step 8: Commit**

```bash
git add iznik-batch/app/Services/ContentCheckService.php \
        iznik-batch/app/Console/Commands/Message/ContentCheckCommand.php \
        iznik-batch/app/Services/AutoApproveService.php \
        iznik-batch/routes/console.php \
        iznik-batch/tests/Feature/Message/ContentCheckTest.php
git commit -m "feat(contentcheck): add ContentCheckCommand, processUnprocessed, schedule, AutoApproveService guard"
```

---

## Task 6: ModTools frontend — show contentcheck failure reasons in pending queue

**Files:**
- Modify: `iznik-nuxt3/modtools/components/ModMessageWorry.vue`

The `contentcheck_reasons` array arrives in the message response inside `message.messagegroups[n].contentcheck_reasons`. We add a computed property to surface reasons from the first non-empty group entry, then display them in addition to the existing live worry-word matches.

- [ ] **Step 1: Update ModMessageWorry.vue**

Replace the full contents of `iznik-nuxt3/modtools/components/ModMessageWorry.vue`:

```vue
<template>
  <div v-if="message">
    <!-- ContentCheck failure reasons (stored, from batch processing) -->
    <NoticeMessage
      v-for="(reason, i) in contentcheckReasons"
      :key="'contentcheck-' + message.id + '-' + i"
      variant="warning"
      class="mb-1"
    >
      <span v-if="reason.check === 'Vague'">
        <strong>Vague post:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'PhoneNumber'">
        <strong>Phone number:</strong> This group restricts personal info in posts.
        {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'EmailAddress'">
        <strong>Email address:</strong> This group restricts personal info in posts.
        {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'MessagingLink'">
        <strong>Messaging app link:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'WorryWord'">
        <strong>Flagged word:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'ConcernKeyword'">
        <strong>Concern keyword:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'SpamKeyword'">
        <strong>Spam keyword:</strong> {{ reason.detail }}
      </span>
      <span v-else-if="reason.check === 'Review'">
        <strong>Flagged for review:</strong> {{ reason.detail }}
      </span>
      <span v-else>
        <strong>Flagged:</strong> {{ reason.detail }}
      </span>
    </NoticeMessage>

    <!-- Live worry-word matches (computed on fetch, shown when no stored reasons) -->
    <template v-if="contentcheckReasons.length === 0">
      <NoticeMessage
        v-for="word in message.worry"
        :key="'worry-' + message.id + '-' + word.worryword.id"
        variant="warning"
        class="mb-1"
      >
        <p>
          Flagged for review: "<span class="text-danger fw-bold">{{
            word.worryword.keyword
          }}</span>".
          <b-button
            v-if="!expand"
            variant="link"
            class="p-0 align-top"
            @click="expand = true"
          >
            Click for more info
          </b-button>
          <b-button
            v-else
            variant="link"
            class="p-0 align-top"
            @click="expand = false"
          >
            Hide more info
          </b-button>
        </p>
        <div v-if="expand">
          <p v-if="word.worryword.type === 'Review'">
            This post contains a keyword which means it's flagged up for review.
            If you can't see anything wrong with it, then it's fine.
          </p>
          <p v-if="word.worryword.type === 'Regulated'">
            This post looks as though it might contain a regulated substance.
            These are not legal on Freegle. If in doubt please check on
            <ExternalLink href="https://discourse.ilovefreegle.org/">
              Central
            </ExternalLink>
            first.
          </p>
          <p v-if="word.worryword.type === 'Reportable'">
            This post looks as though it might contain a reportable substance.
            These may need to be reported to the police. Please ask the member
            about it to see what their reason is, and if in doubt discuss on
            <ExternalLink href="https://discourse.ilovefreegle.org/">
              Central </ExternalLink
            >.
          </p>
          <p v-if="word.worryword.type === 'Medicine'">
            This post looks as though it might contain a drug, medicine or
            supplement. These are not legal on Freegle. Please do not approve this
            without checking on
            <ExternalLink href="https://discourse.ilovefreegle.org/">
              Central
            </ExternalLink>
            first.
          </p>
          <p>
            You can find more information
            <ExternalLink href="https://wiki.ilovefreegle.org/Worry_Words">
              here </ExternalLink
            >.
          </p>
        </div>
      </NoticeMessage>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'

const props = defineProps({
  messageid: {
    type: Number,
    required: true,
  },
})

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.messageid))

watch(
  () => props.messageid,
  (id) => {
    if (id && !messageStore.byId(id)) messageStore.fetch(id)
  },
  { immediate: true }
)

const expand = ref(false)

// Extract contentcheck_reasons from the first message group that has them.
const contentcheckReasons = computed(() => {
  if (!message.value?.messagegroups) return []
  for (const mg of message.value.messagegroups) {
    const reasons = mg.contentcheck_reasons
    if (reasons && Array.isArray(reasons) && reasons.length > 0) {
      return reasons
    }
  }
  return []
})
</script>
```

- [ ] **Step 2: Lint the changed file**

```bash
cd iznik-nuxt3 && npx eslint --fix modtools/components/ModMessageWorry.vue
```

Expected: no errors.

- [ ] **Step 3: Visually verify in the browser**

Navigate to the ModTools pending queue for a group that has messages with known failures. Confirm:
- Messages with `contentcheck_reasons` show the typed badges (Vague, PhoneNumber, etc.)
- Messages without stored reasons still show live worry-word matches as before
- Messages pending purely because user is moderated (no failures) show nothing

- [ ] **Step 4: Commit**

```bash
git add iznik-nuxt3/modtools/components/ModMessageWorry.vue
git commit -m "feat(contentcheck): display contentcheck failure reasons in pending queue"
```

---

## Spec Coverage Self-Review

| Requirement | Task |
|---|---|
| All messages start Pending at submit | Task 2 |
| No mod notification at submit time | Task 2 |
| Unprocessed messages hidden from mod queue | Task 3 |
| 5-minute fallback if batch down | Task 3 |
| contentcheck_reasons stored in messages_groups | Task 1 + 5 |
| Worry words check | Task 4 |
| Spam keywords check | Task 4 |
| Concern keywords check | Task 4 |
| Review checks (money, links, email) | Task 4 |
| Vague item name check | Task 4 |
| PII check (phone + email, per-group) | Task 4 |
| Messaging app invite links | Task 4 |
| Non-moderated + clean → Approved | Task 5 |
| Moderated → stays Pending | Task 5 |
| Failed check → stays Pending with reasons | Task 5 |
| freebiealerts queued on Approved | Task 5 |
| Mod push notification after processing | Task 5 |
| AutoApproveService skips unprocessed | Task 5 |
| Batch scheduled every minute | Task 5 |
| Display reasons in pending queue | Task 6 |
| No reason shown for user-moderated (no failures) | Task 6 |
| Existing pending rows remain visible after deploy | Task 1 (backfill) |
