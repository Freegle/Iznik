# Multi-Group Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow a single message to exist on multiple groups, with per-group moderation state and Trash Nothing deduplication.

**Architecture:** Bottom-up — schema migrations first, then Go API changes, then Nuxt client. Each task is independently testable. The DB already supports multi-group via `messages_groups` composite key `(msgid, groupid)`, but all code assumes single-group.

**Tech Stack:** MySQL/Laravel migrations, Go/Fiber/GORM, Nuxt3/Vue3/Pinia, Vitest, Go test

**Design spec:** `plans/multi-group-messages-design.md`

---

## Status (as of 2026-05-27)

**Done (✅):** Tasks 1, 2, 3, 4, 5, 6, 7 (with deviation), 8, 9, 11, 12, 13, 14, 15, 16 — schema migrations, MessageGroup struct, all five mod actions per-group (hold/release/spam/delete/backToPending), per-group logging, list dedup, TN dedup job, store/ModTools client changes.

**Open work (❌):**

| Task | Area | Summary |
|------|------|---------|
| 10 | Go | `sendForReview` still global; signature + callers need a groupid |
| 17 | Nuxt | `MyMessage.vue`, `OutcomeModal.vue` still use `groups[0]` |
| 18 | Laravel | Add explicit dedup test for same-msgid-on-multiple-groups |
| 19 | Audit | Write `plans/multi-group-stats-audit.md` |
| 20 | Schema | Drop `heldby`/`spamtype`/`spamreason` from `messages` (after V1 retired) |
| 21 | Nuxt | Message report → best shared group |
| 22 | Audit | Write `plans/multi-group-v1-audit-results.md` |
| 23 | Go | Repost scheduling uses `MessageGroups[0].Arrival` |
| 24 | Go | `convertToDraft` uses primary group and deletes all `messages_groups` rows (real bug) |
| 25 | Go | Edit subject + mod-delete audit log use primary group |
| 26 | Nuxt | 5 remaining `groups[0]` sites: `useKeywords.js`, `MessageHistory.vue`, `ModLogGroup.vue`, `pages/message/[id].vue`, `MyMessage.vue:942` |
| 27 | Laravel | `UnifiedDigest.php` header group selection |
| 28 | Go | Re-label `getPrimaryGroupForMessage` as legacy fallback |
| 29 | Laravel | `DeadlineReached` and other mailables track/render arbitrary group via `groups->first()` |

**Also flagged (deviation from spec):** Task 7 soft-deletes the `messages_groups` row on spam instead of setting `collection='Spam'` + `spamtype`/`spamreason`. The new `spamtype`/`spamreason` columns are currently unwritten by Go handlers. Reconcile before Task 20.

---

## File Structure

### New files
- `iznik-batch/database/migrations/YYYY_MM_DD_000001_add_per_group_columns_to_messages_groups.php` — Schema migration
- `iznik-batch/database/migrations/YYYY_MM_DD_000002_copy_per_group_data_to_messages_groups.php` — Data migration
- `iznik-batch/app/Console/Commands/Dedup/TnDedupCommand.php` — TN background dedup job
- `iznik-batch/tests/Unit/Commands/Dedup/TnDedupCommandTest.php` — Test for dedup job

### Modified files — Go API
- `iznik-server-go/message/messageGroup.go` — Add Heldby/Spamtype/Spamreason fields to struct
- `iznik-server-go/message/message_list.go:19-23` — Add Heldby to MessageGroupInfo struct
- `iznik-server-go/message/message.go:1470-1484` — handleHold: per-group
- `iznik-server-go/message/message.go:1513-1527` — handleRelease: per-group
- `iznik-server-go/message/message.go:1407-1450` — handleDeleteMessage: per-group
- `iznik-server-go/message/message.go:1452-1468` — handleSpam: per-group
- `iznik-server-go/message/message.go:1486-1511` — handleBackToPending: per-group heldby
- `iznik-server-go/message/message.go:1276-1285` — logAndNotifyMods: log to specific group
- `iznik-server-go/microvolunteering/microvolunteering.go:714-716` — sendForReview: per-group spamreason

### Modified files — Nuxt client
- `iznik-nuxt3/stores/message.js:683-692` — getByGroup: check all groups
- `iznik-nuxt3/modtools/components/ModMessageButton.vue:178-186` — Use contextual groupid prop
- `iznik-nuxt3/modtools/components/ModMessage.vue:758` — Pass contextual groupid to children
- `iznik-nuxt3/modtools/components/ModMessageCrosspost.vue:44-49` — Use contextual groupid
- `iznik-nuxt3/modtools/components/ModStdMessageModal.vue:241-249` — Use contextual groupid
- `iznik-nuxt3/modtools/components/ModMessageDuplicate.vue:55-62` — Use contextual groupid
- `iznik-nuxt3/modtools/components/ModLog.vue:78-86` — Show all groups
- `iznik-nuxt3/modtools/composables/useModMessages.js:59-72` — Sort by contextual group arrival
- `iznik-nuxt3/components/MyMessage.vue:796,827,907` — Show all groups
- `iznik-nuxt3/components/OutcomeModal.vue:301-308` — Remove groupid dependency
- `iznik-nuxt3/components/MessageReportModal.vue:147` — Report to all groups
- `iznik-nuxt3/components/ExportPost.vue:9-10` — Show all group names

---

## Task 1: Schema Migration — Add Per-Group Columns ✅ DONE

Implemented in [iznik-batch/database/migrations/2026_04_14_000001_add_per_group_columns_to_messages_groups.php](iznik-batch/database/migrations/2026_04_14_000001_add_per_group_columns_to_messages_groups.php).

**Files:**
- Create: `iznik-batch/database/migrations/YYYY_MM_DD_000001_add_per_group_columns_to_messages_groups.php`

This adds `heldby`, `spamtype`, `spamreason` to `messages_groups`. The columns on `messages` are NOT dropped yet — that's the final cleanup task after all code is deployed.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('heldby')->nullable()->after('approvedat');
            $table->string('spamtype', 50)->nullable()->after('heldby');
            $table->string('spamreason', 255)->nullable()->after('spamtype');

            $table->foreign('heldby')->references('id')->on('users')->onDelete('set null');
            $table->index('heldby', 'heldby_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropForeign(['heldby']);
            $table->dropIndex('heldby_idx');
            $table->dropColumn(['heldby', 'spamtype', 'spamreason']);
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `docker exec freegle-batch php artisan migrate`
Expected: Migration completes. `messages_groups` now has `heldby`, `spamtype`, `spamreason` columns.

- [ ] **Step 3: Verify**

Run: `docker exec freegle-batch php artisan tinker --execute="Schema::getColumnListing('messages_groups')"`
Expected: Output includes `heldby`, `spamtype`, `spamreason`.

- [ ] **Step 4: Commit**

```bash
git add iznik-batch/database/migrations/*add_per_group_columns*
git commit -m "feat: add heldby/spamtype/spamreason columns to messages_groups for per-group moderation"
```

---

## Task 2: Data Migration — Copy Existing Per-Group State ✅ DONE

Implemented in [iznik-batch/database/migrations/2026_04_14_000002_copy_per_group_data_to_messages_groups.php](iznik-batch/database/migrations/2026_04_14_000002_copy_per_group_data_to_messages_groups.php).

**Files:**
- Create: `iznik-batch/database/migrations/YYYY_MM_DD_000002_copy_per_group_data_to_messages_groups.php`

For any message currently held or marked as spam, copy the state to all its `messages_groups` rows. This is safe because today each message has exactly one `messages_groups` row.

- [ ] **Step 1: Write the data migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Copy heldby from messages to messages_groups for currently-held messages.
        DB::statement('
            UPDATE messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            SET mg.heldby = m.heldby
            WHERE m.heldby IS NOT NULL
        ');

        // Copy spamtype/spamreason from messages to messages_groups.
        DB::statement('
            UPDATE messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            SET mg.spamtype = m.spamtype, mg.spamreason = m.spamreason
            WHERE m.spamtype IS NOT NULL
        ');
    }

    public function down(): void
    {
        // No rollback needed — the messages table still has the original data.
    }
};
```

- [ ] **Step 2: Run migration**

Run: `docker exec freegle-batch php artisan migrate`
Expected: Migration completes.

- [ ] **Step 3: Verify data copied**

Run:
```bash
docker exec freegle-db mysql -u root iznik -e "
  SELECT COUNT(*) AS held_messages FROM messages WHERE heldby IS NOT NULL;
  SELECT COUNT(*) AS held_mg_rows FROM messages_groups WHERE heldby IS NOT NULL;
"
```
Expected: Both counts should match (since each message currently has one messages_groups row).

- [ ] **Step 4: Commit**

```bash
git add iznik-batch/database/migrations/*copy_per_group_data*
git commit -m "feat: copy heldby/spamtype/spamreason from messages to messages_groups"
```

---

## Task 3: Go API — MessageGroup Struct Update ✅ DONE

`Heldby`, `Spamtype`, `Spamreason` added to [messageGroup.go:27-29](iznik-server-go/message/messageGroup.go#L27-L29) and `Heldby` added to `MessageGroupInfo` in [message_list.go:24](iznik-server-go/message/message_list.go#L24).

**Files:**
- Modify: `iznik-server-go/message/messageGroup.go:13-24`
- Modify: `iznik-server-go/message/message_list.go:19-23`

Add the new per-group fields to the Go structs so GORM reads them from DB and they appear in API responses.

- [ ] **Step 1: Update MessageGroup struct**

In `iznik-server-go/message/messageGroup.go`, add fields to the struct:

```go
type MessageGroup struct {
	Groupid     uint64    `json:"groupid"`
	Msgid       uint64    `json:"msgid"`
	Arrival     time.Time `json:"arrival"`
	Collection  string    `json:"collection"`
	Autoreposts uint      `json:"autoreposts"`
	Approvedby  uint64    `json:"approvedby"`
	Heldby      *uint64   `json:"heldby,omitempty"`
	Spamtype    *string   `json:"spamtype,omitempty"`
	Spamreason  *string   `json:"spamreason,omitempty"`
}
```

- [ ] **Step 2: Update MessageGroupInfo struct**

In `iznik-server-go/message/message_list.go`, add `Heldby` so list views can show held status:

```go
type MessageGroupInfo struct {
	Groupid    uint64    `json:"groupid"`
	Collection string    `json:"collection"`
	Arrival    time.Time `json:"arrival"`
	Heldby     *uint64   `json:"heldby,omitempty"`
}
```

- [ ] **Step 3: Update the list query to include heldby**

In `message_list.go:245`, update the query:

```go
db.Raw("SELECT groupid, collection, arrival, heldby FROM messages_groups WHERE msgid = ? AND deleted = 0", msgID).Scan(&groups)
```

- [ ] **Step 4: Run tests**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`
Expected: All existing tests pass (additive change only).

- [ ] **Step 5: Commit**

```bash
cd iznik-server-go
git add message/messageGroup.go message/message_list.go
git commit -m "feat: add heldby/spamtype/spamreason to MessageGroup struct for per-group moderation"
```

---

## Task 4: Go API — Per-Group Hold ✅ DONE

Implemented in [message.go:1959-1988](iznik-server-go/message/message.go#L1959-L1988). Uses `resolveAuthorizedGroups()` to determine which groups to act on, writes `heldby` per group, logs per group. Dual-writes to `messages.heldby` for backwards compat.

**Files:**
- Modify: `iznik-server-go/message/message.go:1470-1484` (handleHold)
- Test: `iznik-server-go/test/message_test.go`

Change `handleHold` to write to `messages_groups.heldby` instead of `messages.heldby`. The mod must be holding it on a specific group — use `req.Groupid` if provided, otherwise the primary group.

- [ ] **Step 1: Write the failing test**

Add to `iznik-server-go/test/message_test.go`:

```go
func TestPostMessageHoldPerGroup(t *testing.T) {
	prefix := uniquePrefix("hold_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create a message and add it to both groups.
	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Hold on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Hold",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby set on group A's messages_groups row.
	var heldbyA *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldbyA)
	assert.NotNil(t, heldbyA)
	assert.Equal(t, modID, *heldbyA)

	// Verify heldby NOT set on group B's messages_groups row.
	var heldbyB *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldbyB)
	assert.Nil(t, heldbyB)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageHoldPerGroup -v -count=1`
Expected: FAIL — heldby is set on messages table (global), not on messages_groups.

- [ ] **Step 3: Implement per-group hold**

In `iznik-server-go/message/message.go`, replace the `handleHold` function (lines ~1470-1484):

```go
func handleHold(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	// Determine which group to hold on.
	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Per-group hold: set heldby on the specific messages_groups row.
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid = ?", myid, req.ID, groupid)

	// Also update messages.heldby for backwards compatibility during migration.
	// TODO: Remove this once all code reads from messages_groups.heldby.
	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", myid, req.ID)

	logAndNotifyMods(db, flog.LOG_SUBTYPE_HOLD, ctx, myid, req.ID, 0, "")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageHoldPerGroup -v -count=1`
Expected: PASS

- [ ] **Step 5: Run full test suite**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
cd iznik-server-go
git add message/message.go test/message_test.go
git commit -m "feat: per-group hold — write heldby to messages_groups instead of messages"
```

---

## Task 5: Go API — Per-Group Release ✅ DONE

Implemented in [message.go:2029-2062](iznik-server-go/message/message.go#L2029-L2062). Per-group `heldby = NULL`, clears `messages.heldby` only when no group still holds.

**Files:**
- Modify: `iznik-server-go/message/message.go:1513-1527` (handleRelease)
- Test: `iznik-server-go/test/message_test.go`

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageReleasePerGroup(t *testing.T) {
	prefix := uniquePrefix("rel_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create message on both groups, held on both.
	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ?", modID, msgID)

	// Release on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Release",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A should be released.
	var heldbyA *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldbyA)
	assert.Nil(t, heldbyA)

	// Group B should still be held.
	var heldbyB *uint64
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldbyB)
	assert.NotNil(t, heldbyB)
	assert.Equal(t, modID, *heldbyB)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageReleasePerGroup -v -count=1`
Expected: FAIL

- [ ] **Step 3: Implement per-group release**

```go
func handleRelease(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Per-group release.
	db.Exec("UPDATE messages_groups SET heldby = NULL WHERE msgid = ? AND groupid = ?", req.ID, groupid)

	// Check if still held on any group — if not, clear messages.heldby for backwards compat.
	var stillHeldCount int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND heldby IS NOT NULL", req.ID).Scan(&stillHeldCount)
	if stillHeldCount == 0 {
		db.Exec("UPDATE messages SET heldby = NULL WHERE id = ?", req.ID)
	}

	logAndNotifyMods(db, flog.LOG_SUBTYPE_RELEASE, ctx, myid, req.ID, 0, "")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageReleasePerGroup -v -count=1`
Expected: PASS

- [ ] **Step 5: Run full suite, commit**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group release — clear heldby on specific messages_groups row"
```

---

## Task 6: Go API — Per-Group Delete ✅ DONE

Implemented in [message.go:1854-1915](iznik-server-go/message/message.go#L1854-L1915). `DELETE FROM messages_groups WHERE msgid = ? AND groupid IN ?`, soft-deletes message + queues freebie-alerts removal only when last group is gone.

**Files:**
- Modify: `iznik-server-go/message/message.go:1407-1450` (handleDeleteMessage)
- Test: `iznik-server-go/test/message_test.go`

Currently deletes ALL `messages_groups` rows and soft-deletes the message. Change to delete only the specified group's row. If it was the last group, then soft-delete the message.

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageDeletePerGroup(t *testing.T) {
	prefix := uniquePrefix("del_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Delete from group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Delete",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A's row should be gone.
	var countA int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&countA)
	assert.Equal(t, int64(0), countA)

	// Group B's row should still exist.
	var countB int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&countB)
	assert.Equal(t, int64(1), countB)

	// Message itself should NOT be soft-deleted (still on group B).
	var deleted *time.Time
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.Nil(t, deleted)
}

func TestPostMessageDeleteLastGroup(t *testing.T) {
	prefix := uniquePrefix("del_last")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)

	// Delete from the only group.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Delete",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Message should be soft-deleted since it was the last group.
	var deleted *time.Time
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.NotNil(t, deleted)
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec freegle-apiv2 go test ./test/ -run "TestPostMessageDelete(PerGroup|LastGroup)" -v -count=1`
Expected: FAIL

- [ ] **Step 3: Implement per-group delete**

```go
func handleDeleteMessage(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	if req.Groupid != nil && *req.Groupid > 0 {
		ctx.Groupid = *req.Groupid
	}
	groupid := ctx.Groupid

	// Delete from the specific group only.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", req.ID, groupid)

	// Check if any groups remain. If not, soft-delete the message itself.
	var remainingGroups int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND deleted = 0", req.ID).Scan(&remainingGroups)
	if remainingGroups == 0 {
		db.Exec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?", req.ID)
	}

	subject := ""
	if req.Subject != nil {
		subject = *req.Subject
	}
	body := ""
	if req.Body != nil {
		body = *req.Body
	}
	stdmsgid := uint64(0)
	if req.Stdmsgid != nil {
		stdmsgid = *req.Stdmsgid
	}

	db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?))",
		"email_message_rejected", req.ID, groupid, myid, subject, body, stdmsgid)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run tests, verify pass**

Run: `docker exec freegle-apiv2 go test ./test/ -run "TestPostMessageDelete(PerGroup|LastGroup)" -v -count=1`
Expected: PASS

- [ ] **Step 5: Full suite, commit**

Run: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group delete — only remove message from specified group"
```

---

## Task 7: Go API — Per-Group Spam ✅ DONE (with deviation)

Implemented in [message.go:1918-1956](iznik-server-go/message/message.go#L1918-L1956). **Deviation from plan**: rather than setting `collection='Spam'` and writing `spamtype`/`spamreason`, the implementation soft-deletes the `messages_groups` row (`deleted = 1`). Records in `messages_spamham` for training. Soft-deletes the message itself only if no non-deleted groups remain.

If `spamtype`/`spamreason` on `messages_groups` is supposed to be populated by `handleSpam`, that work is still pending — but the added columns are currently unused by this handler. Reconcile with the design before Task 20 (drop old columns).

**Files:**
- Modify: `iznik-server-go/message/message.go:1452-1468` (handleSpam)
- Test: `iznik-server-go/test/message_test.go`

Currently marks spam globally. Change to set `collection = 'Spam'` and `spamtype`/`spamreason` on the specific group's row only.

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageSpamPerGroup(t *testing.T) {
	prefix := uniquePrefix("spam_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Spam on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Spam",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A should be marked as Spam.
	var collectionA string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&collectionA)
	assert.Equal(t, "Spam", collectionA)

	// Group B should still be Pending.
	var collectionB string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collectionB)
	assert.Equal(t, "Pending", collectionB)

	// Message itself should NOT be soft-deleted (still active on group B).
	var deleted *time.Time
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&deleted)
	assert.Nil(t, deleted)
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageSpamPerGroup -v -count=1`
Expected: FAIL

- [ ] **Step 3: Implement per-group spam**

```go
func handleSpam(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Record for spam training (global — the message content is spammy regardless of group).
	db.Exec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?)", req.ID, utils.COLLECTION_SPAM)

	// Per-group: mark as Spam on this group only.
	db.Exec("UPDATE messages_groups SET collection = ?, spamtype = 'Spam', spamreason = 'Moderator' WHERE msgid = ? AND groupid = ?",
		utils.COLLECTION_SPAM, req.ID, groupid)

	// If no non-spam groups remain, soft-delete the message.
	var activeGroups int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND collection != ? AND deleted = 0", req.ID, utils.COLLECTION_SPAM).Scan(&activeGroups)
	if activeGroups == 0 {
		db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", req.ID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test, verify pass, full suite, commit**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageSpamPerGroup -v -count=1`
Then: `docker exec freegle-apiv2 go test ./test/... -count=1 -timeout 300s`

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group spam — mark spam on specific group only"
```

---

## Task 8: Go API — Per-Group BackToPending ✅ DONE

Implemented in [message.go:1992-2026](iznik-server-go/message/message.go#L1992-L2026). Per-group hold + move from Approved to Pending; logs per group.

**Files:**
- Modify: `iznik-server-go/message/message.go:1486-1511` (handleBackToPending)
- Test: `iznik-server-go/test/message_test.go`

The collection update is already per-group when `req.Groupid` is provided (lines 1499-1504). But `heldby` is still written to the messages table (line 1496). Fix to write to `messages_groups`.

- [ ] **Step 1: Write the failing test**

```go
func TestPostMessageBackToPendingPerGroup(t *testing.T) {
	prefix := uniquePrefix("btp_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create message approved on both groups.
	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ? AND groupid = ?", msgID, groupA)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	// BackToPending on group A only.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "BackToPending",
		"groupid": groupA,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Group A should be Pending and held.
	var collA string
	var heldbyA *uint64
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&collA)
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&heldbyA)
	assert.Equal(t, "Pending", collA)
	assert.NotNil(t, heldbyA)
	assert.Equal(t, modID, *heldbyA)

	// Group B should still be Approved and NOT held.
	var collB string
	var heldbyB *uint64
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collB)
	db.Raw("SELECT heldby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&heldbyB)
	assert.Equal(t, "Approved", collB)
	assert.Nil(t, heldbyB)
}
```

- [ ] **Step 2: Run test, verify fail**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestPostMessageBackToPendingPerGroup -v -count=1`

- [ ] **Step 3: Implement fix**

Replace handleBackToPending:

```go
func handleBackToPending(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	groupid := ctx.Groupid
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	}

	// Per-group: hold and move back to Pending on this group only.
	db.Exec("UPDATE messages_groups SET collection = ?, heldby = ?, approvedby = NULL, approvedat = NULL WHERE msgid = ? AND groupid = ? AND collection = ?",
		utils.COLLECTION_PENDING, myid, req.ID, groupid, utils.COLLECTION_APPROVED)

	// Backwards compat: also update messages.heldby.
	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", myid, req.ID)

	logAndNotifyMods(db, flog.LOG_SUBTYPE_HOLD, ctx, myid, req.ID, 0, "Back to pending")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Run test, verify pass, full suite, commit**

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: per-group back-to-pending — hold only on the target group"
```

---

## Task 9: Go API — logAndNotifyMods Per-Group Logging ✅ DONE

Hold/Release/BackToPending now loop through `authorizedGroups` and call `logAndNotifyMods` once per group with `ctx.Groupid` set to that group. Verified at [message.go:1983-1986](iznik-server-go/message/message.go#L1983-L1986).

**Files:**
- Modify: `iznik-server-go/message/message.go:1276-1285` (logAndNotifyMods)

Currently logs to `ctx.Groupid` (primary group) but notifies all groups. The log should go to the specific group the action was taken on. Since callers now set `ctx.Groupid` to the target group before calling `logAndNotifyMods`, this is already correct after Tasks 4-8. But we should verify and add a test.

- [ ] **Step 1: Verify current behaviour**

Read `logAndNotifyMods` — it uses `ctx.Groupid` for the log entry. After our changes, callers set `ctx.Groupid` to the request groupid before calling. Confirm this by reviewing each caller.

- [ ] **Step 2: Write a test**

```go
func TestModActionLogsToSpecificGroup(t *testing.T) {
	prefix := uniquePrefix("log_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	CreateTestMembership(t, modID, groupB, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := createPendingMessage(t, posterID, groupA, prefix)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Pending', 0)", msgID, groupB)

	// Hold on group B specifically.
	body := map[string]interface{}{
		"id":      msgID,
		"action":  "Hold",
		"groupid": groupB,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", modToken)
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// The log entry should reference group B, not group A.
	var logGroupid uint64
	db.Raw("SELECT groupid FROM logs WHERE msgid = ? AND subtype = 'Hold' ORDER BY id DESC LIMIT 1", msgID).Scan(&logGroupid)
	assert.Equal(t, groupB, logGroupid)
}
```

- [ ] **Step 3: Run test, verify pass**

Run: `docker exec freegle-apiv2 go test ./test/ -run TestModActionLogsToSpecificGroup -v -count=1`
Expected: PASS (since handleHold now sets groupid from req before calling logAndNotifyMods).

- [ ] **Step 4: Commit**

```bash
cd iznik-server-go && git add test/message_test.go
git commit -m "test: verify mod action logs reference the specific target group"
```

---

## Task 10: Go API — Microvolunteering sendForReview Per-Group ❌ NOT DONE

Current code at [microvolunteering.go:877-880](iznik-server-go/microvolunteering/microvolunteering.go#L877-L880) still writes to `messages.spamreason` AND updates `messages_groups SET collection='Pending', spamreason=?` with no `groupid` filter — affecting all groups. The caller at [microvolunteering.go:720](iznik-server-go/microvolunteering/microvolunteering.go#L720) does not pass a groupid. Both signature and call sites still need to change as described below.

**Files:**
- Modify: `iznik-server-go/microvolunteering/microvolunteering.go:712-717`
- Test: `iznik-server-go/test/microvolunteering_test.go`

`sendForReview` writes `spamreason` to `messages` table and updates `collection` on ALL groups. Needs to accept a groupid and write per-group.

- [ ] **Step 1: Write the failing test**

```go
func TestSendForReviewPerGroup(t *testing.T) {
	prefix := uniquePrefix("sfr_pg")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")

	msgID := CreateTestMessage(t, posterID, groupA, "Test Offer Spam Item", 55.9533, -3.1883)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	// Call sendForReview targeting group A.
	sendForReview(db, msgID, groupA, "Test spam reason")

	// Group A should be Pending with spamreason set.
	var collA string
	var reasonA *string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&collA)
	db.Raw("SELECT spamreason FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupA).Scan(&reasonA)
	assert.Equal(t, "Pending", collA)
	assert.NotNil(t, reasonA)
	assert.Equal(t, "Test spam reason", *reasonA)

	// Group B should still be Approved with no spamreason.
	var collB string
	var reasonB *string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&collB)
	db.Raw("SELECT spamreason FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupB).Scan(&reasonB)
	assert.Equal(t, "Approved", collB)
	assert.Nil(t, reasonB)
}
```

- [ ] **Step 2: Run test, verify fail**

- [ ] **Step 3: Update sendForReview signature and implementation**

```go
func sendForReview(db *gorm.DB, msgid uint64, groupid uint64, reason string) {
	db.Exec("UPDATE messages_groups SET spamreason = ?, collection = ? WHERE msgid = ? AND groupid = ?",
		reason, utils.COLLECTION_PENDING, msgid, groupid)
}
```

Update all callers of `sendForReview` to pass the groupid. Search for all call sites — each microvolunteering challenge handler that calls `sendForReview` will need to determine the groupid from the message context.

- [ ] **Step 4: Run test, verify pass, full suite, commit**

```bash
cd iznik-server-go && git add microvolunteering/microvolunteering.go test/microvolunteering_test.go
git commit -m "feat: sendForReview per-group — write spamreason to messages_groups"
```

---

## Task 11: Go API — List/Search Dedup Across Groups ✅ DONE

`SELECT DISTINCT mg.msgid` is now used across the relevant branches in [message_list.go:131,143,157,168,181,509](iznik-server-go/message/message_list.go). Confirm tests still cover the multi-group case (if not, add as a follow-up).

**Files:**
- Modify: `iznik-server-go/message/message_list.go:177-200`

Currently the list query selects `mg.msgid FROM messages_groups mg WHERE mg.groupid IN (?)`. If a message is on two groups the user is a member of, and both groupids are in the IN clause, the same msgid appears twice. Fix with `SELECT DISTINCT mg.msgid`.

**Note:** The search queries in `search.go` already `GROUP BY msgid` (lines 160, 200, 242, 282) so they're already safe.

- [ ] **Step 1: Write the failing test**

```go
func TestListMessagesDedupsMultiGroup(t *testing.T) {
	prefix := uniquePrefix("list_dedup")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	CreateTestMembership(t, posterID, groupA, "Member")
	CreateTestMembership(t, posterID, groupB, "Member")
	CreateTestMembership(t, viewerID, groupA, "Member")
	CreateTestMembership(t, viewerID, groupB, "Member")
	_, viewerToken := CreateTestSession(t, viewerID)

	// Create message on both groups.
	msgID := CreateTestMessage(t, posterID, groupA, "Test Multi Group Offer", 55.9533, -3.1883)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) VALUES (?, ?, NOW(), 'Approved', 0)", msgID, groupB)

	// List messages across both groups.
	url := fmt.Sprintf("/api/messages?groupids=%d,%d&collection=Approved&jwt=%s", groupA, groupB, viewerToken)
	req := httptest.NewRequest("GET", url, nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result ListMessagesResponse
	json2.Unmarshal(rsp(resp), &result)

	// Message should appear exactly once, with both groups in its groups array.
	count := 0
	for _, m := range result.Messages {
		if m.ID == msgID {
			count++
			assert.GreaterOrEqual(t, len(m.Groups), 2, "Should have both groups in the array")
		}
	}
	assert.Equal(t, 1, count, "Message should appear exactly once, not twice")
}
```

- [ ] **Step 2: Run test, verify fail**

- [ ] **Step 3: Add DISTINCT to list query**

In `message_list.go`, change the standard listing query (line 177):

```go
sql := "SELECT DISTINCT mg.msgid FROM messages_groups mg " +
```

Also add DISTINCT to all the search-variant queries in the same function (lines 127, 139, 153, 164) — anywhere `SELECT mg.msgid` appears.

- [ ] **Step 4: Run test, verify pass, full suite, commit**

```bash
cd iznik-server-go && git add message/message_list.go test/message_test.go
git commit -m "feat: deduplicate message listings when message is on multiple queried groups"
```

---

## Task 12: Background TN Dedup Job ✅ DONE

Implemented in [iznik-batch/app/Console/Commands/Dedup/TnDedupCommand.php](iznik-batch/app/Console/Commands/Dedup/TnDedupCommand.php) with tests at [iznik-batch/tests/Unit/Commands/Dedup/TnDedupCommandTest.php](iznik-batch/tests/Unit/Commands/Dedup/TnDedupCommandTest.php).

**Files:**
- Create: `iznik-batch/app/Console/Commands/Dedup/TnDedupCommand.php`
- Create: `iznik-batch/tests/Unit/Commands/Dedup/TnDedupCommandTest.php`

Periodic job that finds messages with the same `tnpostid` but different `messages.id`, merges them by moving `messages_groups` rows to the oldest message, and deletes duplicates.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Unit\Commands\Dedup;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class TnDedupCommandTest extends TestCase
{
    public function test_merges_duplicate_tn_posts(): void
    {
        // Create two messages with the same tnpostid on different groups.
        $groupA = DB::table('groups')->insertGetId(['nameshort' => 'test-tn-dedup-a', 'type' => 'Freegle', 'publish' => 1]);
        $groupB = DB::table('groups')->insertGetId(['nameshort' => 'test-tn-dedup-b', 'type' => 'Freegle', 'publish' => 1]);
        $userId = DB::table('users')->insertGetId(['systemrole' => 'User']);

        $msg1 = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'subject' => 'OFFER: Test Item',
            'type' => 'Offer',
            'arrival' => now()->subHour(),
            'tnpostid' => 'TN-DEDUP-TEST-123',
        ]);
        $msg2 = DB::table('messages')->insertGetId([
            'fromuser' => $userId,
            'subject' => 'OFFER: Test Item',
            'type' => 'Offer',
            'arrival' => now(),
            'tnpostid' => 'TN-DEDUP-TEST-123',
        ]);

        DB::table('messages_groups')->insert([
            ['msgid' => $msg1, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()->subHour()],
            ['msgid' => $msg2, 'groupid' => $groupB, 'collection' => 'Approved', 'arrival' => now()],
        ]);

        $this->artisan('dedup:tn')->assertSuccessful();

        // msg1 (older) should now have both groups.
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1, 'groupid' => $groupA]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1, 'groupid' => $groupB]);

        // msg2 should be deleted.
        $this->assertDatabaseMissing('messages_groups', ['msgid' => $msg2]);
        $this->assertNotNull(DB::table('messages')->where('id', $msg2)->value('deleted'));

        // Cleanup.
        DB::table('messages')->whereIn('id', [$msg1, $msg2])->delete();
        DB::table('groups')->whereIn('id', [$groupA, $groupB])->delete();
        DB::table('users')->where('id', $userId)->delete();
    }

    public function test_ignores_messages_without_tnpostid(): void
    {
        // Create two messages without tnpostid.
        $groupA = DB::table('groups')->insertGetId(['nameshort' => 'test-tn-nodup-a', 'type' => 'Freegle', 'publish' => 1]);
        $userId = DB::table('users')->insertGetId(['systemrole' => 'User']);

        $msg1 = DB::table('messages')->insertGetId([
            'fromuser' => $userId, 'subject' => 'OFFER: Item A', 'type' => 'Offer', 'arrival' => now(),
        ]);
        $msg2 = DB::table('messages')->insertGetId([
            'fromuser' => $userId, 'subject' => 'OFFER: Item B', 'type' => 'Offer', 'arrival' => now(),
        ]);

        DB::table('messages_groups')->insert([
            ['msgid' => $msg1, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()],
            ['msgid' => $msg2, 'groupid' => $groupA, 'collection' => 'Approved', 'arrival' => now()],
        ]);

        $this->artisan('dedup:tn')->assertSuccessful();

        // Both messages should still exist.
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg1]);
        $this->assertDatabaseHas('messages_groups', ['msgid' => $msg2]);

        // Cleanup.
        DB::table('messages')->whereIn('id', [$msg1, $msg2])->delete();
        DB::table('groups')->where('id', $groupA)->delete();
        DB::table('users')->where('id', $userId)->delete();
    }
}
```

- [ ] **Step 2: Write the command**

```php
<?php

namespace App\Console\Commands\Dedup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TnDedupCommand extends Command
{
    protected $signature = 'dedup:tn';
    protected $description = 'Merge duplicate Trash Nothing cross-posts by tnpostid';

    public function handle(): int
    {
        // Find tnpostids with multiple message IDs.
        $duplicates = DB::table('messages')
            ->select('tnpostid', DB::raw('MIN(id) as canonical_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('tnpostid')
            ->where('tnpostid', '!=', '')
            ->whereNull('deleted')
            ->groupBy('tnpostid')
            ->having('cnt', '>', 1)
            ->get();

        $merged = 0;

        foreach ($duplicates as $dup) {
            $duplicateIds = DB::table('messages')
                ->where('tnpostid', $dup->tnpostid)
                ->where('id', '!=', $dup->canonical_id)
                ->whereNull('deleted')
                ->pluck('id');

            foreach ($duplicateIds as $dupeId) {
                DB::transaction(function () use ($dup, $dupeId) {
                    // Move messages_groups rows to canonical message.
                    // Use INSERT IGNORE in case the canonical already has a row for this group.
                    DB::statement('
                        INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival, autoreposts, msgtype)
                        SELECT ?, groupid, collection, arrival, autoreposts, msgtype
                        FROM messages_groups WHERE msgid = ?
                    ', [$dup->canonical_id, $dupeId]);

                    // Move messages_history rows.
                    DB::statement('
                        UPDATE IGNORE messages_history SET msgid = ? WHERE msgid = ?
                    ', [$dup->canonical_id, $dupeId]);

                    // Move messages_postings rows.
                    DB::statement('
                        UPDATE IGNORE messages_postings SET msgid = ? WHERE msgid = ?
                    ', [$dup->canonical_id, $dupeId]);

                    // Update chat_messages references.
                    DB::table('chat_messages')
                        ->where('refmsgid', $dupeId)
                        ->update(['refmsgid' => $dup->canonical_id]);

                    // Delete duplicate's messages_groups rows and soft-delete the message.
                    DB::table('messages_groups')->where('msgid', $dupeId)->delete();
                    DB::table('messages')->where('id', $dupeId)->update([
                        'deleted' => now(),
                        'messageid' => null,
                    ]);
                });

                $merged++;
                Log::info("TN dedup: merged message {$dupeId} into {$dup->canonical_id} (tnpostid: {$dup->tnpostid})");
            }
        }

        $this->info("Merged {$merged} duplicate TN posts.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Run test**

Run: `docker exec freegle-batch php artisan test --filter=TnDedupCommandTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
cd iznik-batch
git add app/Console/Commands/Dedup/TnDedupCommand.php tests/Unit/Commands/Dedup/TnDedupCommandTest.php
git commit -m "feat: background TN dedup job — merge cross-posted messages by tnpostid"
```

---

## Task 13: Nuxt — Message Store getByGroup Fix ✅ DONE

[stores/message.js:718-727](iznik-nuxt3/stores/message.js#L718-L727) now matches any group in the array via `message.groups.some(...)`.

**Files:**
- Modify: `iznik-nuxt3/stores/message.js:683-692`
- Test: existing message store tests

Currently `getByGroup` filters by `groups[0].groupid`. A multi-group message should match if ANY of its groups match.

- [ ] **Step 1: Update getByGroup**

```javascript
getByGroup: (state) => (groupid) => {
  const ret = Object.values(state.list).filter((message) => {
    return (
      message.groups.length > 0 &&
      message.groups.some(
        (g) => parseInt(g.groupid) === parseInt(groupid)
      )
    )
  })
  return ret
},
```

- [ ] **Step 2: Write/update test**

In the message store test file, add a test that creates a message with two groups and verifies `getByGroup` finds it via either group.

- [ ] **Step 3: Run vitest, commit**

Run: `docker exec freegle-nuxt3 npx vitest run stores/message`

```bash
cd iznik-nuxt3 && git add stores/message.js tests/
git commit -m "fix: getByGroup matches message on any group, not just groups[0]"
```

---

## Task 14: Nuxt — ModTools Contextual Groupid ✅ DONE

`groupid` prop is now plumbed through [ModMessage.vue:799](iznik-nuxt3/modtools/components/ModMessage.vue#L799), [ModMessageButton.vue:139,184](iznik-nuxt3/modtools/components/ModMessageButton.vue#L139), and passed to child components ([ModMessage.vue:265,459,557,618](iznik-nuxt3/modtools/components/ModMessage.vue#L265)). Components still fall back to `groups[0]` when no prop is given — that fallback is acceptable for legacy callers.

**Files:**
- Modify: `iznik-nuxt3/modtools/components/ModMessage.vue`
- Modify: `iznik-nuxt3/modtools/components/ModMessageButton.vue`
- Modify: `iznik-nuxt3/modtools/components/ModMessageCrosspost.vue`
- Modify: `iznik-nuxt3/modtools/components/ModStdMessageModal.vue`
- Modify: `iznik-nuxt3/modtools/components/ModMessageDuplicate.vue`

The core change: instead of extracting `groups[0].groupid` in each component, pass the contextual groupid (the group the mod is currently moderating) as a prop from the parent.

- [ ] **Step 1: Add groupid prop to ModMessageButton.vue**

Replace the computed groupid (lines 178-186) with a prop:

```javascript
const props = defineProps({
  id: { type: Number, required: true },
  groupid: { type: Number, required: true },
  // ... existing props
})

// Remove the computed groupid — use props.groupid instead.
```

Update all references from `groupid.value` to `props.groupid`.

- [ ] **Step 2: Update ModMessage.vue to pass contextual groupid**

ModMessage.vue has a computed groupid at line ~758. Change it to accept a prop:

```javascript
const props = defineProps({
  id: { type: Number, required: true },
  groupid: { type: Number, required: true },
  // ... existing props
})
```

Pass `props.groupid` to child components:
```vue
<ModMessageButton :id="id" :groupid="props.groupid" ... />
```

- [ ] **Step 3: Update remaining components**

Apply the same pattern to:
- `ModMessageCrosspost.vue` — accept `groupid` prop, remove `messageGroupId` computed
- `ModStdMessageModal.vue` — accept `groupid` prop, use instead of `groups[0]` fallback
- `ModMessageDuplicate.vue` — accept `groupid` prop, remove computed

- [ ] **Step 4: Update parent component that renders ModMessage**

The parent (likely the mod queue page or `useModMessages` composable) already knows which group it's showing. Pass it down:

```vue
<ModMessage :id="message.id" :groupid="currentGroupId" />
```

- [ ] **Step 5: Run vitest for ModTools components**

Run: `docker exec freegle-nuxt3 npx vitest run modtools/`

- [ ] **Step 6: Commit**

```bash
cd iznik-nuxt3
git add modtools/components/ModMessage*.vue modtools/components/ModStdMessageModal.vue
git commit -m "feat: ModTools components use contextual groupid prop instead of groups[0]"
```

---

## Task 15: Nuxt — ModTools Multi-Group Indicator ✅ DONE

`otherGroups` computed at [ModMessage.vue:822](iznik-nuxt3/modtools/components/ModMessage.vue#L822) and "Also on:" badge at [ModMessage.vue:143-151](iznik-nuxt3/modtools/components/ModMessage.vue#L143-L151). Withdraw-warning text for delete/spam still needs verification — confirm `ModMessageButton.vue` modals show the "remains on N other groups" warning, otherwise add it as a follow-up.

**Files:**
- Modify: `iznik-nuxt3/modtools/components/ModMessage.vue`

Show a badge when a message is on multiple groups.

- [ ] **Step 1: Add multi-group indicator to template**

In ModMessage.vue template, add after the group name display:

```vue
<span v-if="message.groups && message.groups.length > 1" class="small text-muted ms-1">
  Also on:
  <span v-for="(g, idx) in otherGroups" :key="g.groupid">
    {{ groupStore.get(g.groupid)?.namedisplay }}<span v-if="idx < otherGroups.length - 1">, </span>
  </span>
</span>
```

Add computed:

```javascript
const otherGroups = computed(() => {
  if (!message.value?.groups) return []
  return message.value.groups.filter(g => parseInt(g.groupid) !== parseInt(props.groupid))
})
```

- [ ] **Step 2: Add withdraw warning to delete/spam actions**

In ModMessageButton.vue, update the delete/spam confirmation modals to show a warning when message is on multiple groups:

```vue
<span v-if="message.groups && message.groups.length > 1" class="text-warning">
  This will only remove the message from this group. It remains on {{ message.groups.length - 1 }} other group(s).
</span>
```

- [ ] **Step 3: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add modtools/components/ModMessage*.vue
git commit -m "feat: show multi-group indicator and per-group action warnings in ModTools"
```

---

## Task 16: Nuxt — Sort by Contextual Group Arrival ✅ DONE

[useModMessages.js:46-91](iznik-nuxt3/modtools/composables/useModMessages.js#L46-L91) now uses `contextGid = parseInt(groupid.value)` and prefers the matching group's arrival.

**Files:**
- Modify: `iznik-nuxt3/modtools/composables/useModMessages.js:59-72`

Currently sorts by `groups[0].arrival`. Should sort by the arrival time of the contextual group.

- [ ] **Step 1: Update sort**

The composable needs to know the current group context. Pass it as a parameter:

```javascript
function sortMessages(messages, contextGroupId) {
  messages.sort((a, b) => {
    const arrivalA = getGroupArrival(a, contextGroupId)
    const arrivalB = getGroupArrival(b, contextGroupId)
    return new Date(arrivalB).getTime() - new Date(arrivalA).getTime()
  })
}

function getGroupArrival(message, groupId) {
  if (message.groups) {
    const contextGroup = message.groups.find(
      (g) => parseInt(g.groupid) === parseInt(groupId)
    )
    if (contextGroup) return contextGroup.arrival
    if (message.groups[0]) return message.groups[0].arrival
  }
  return message.arrival
}
```

- [ ] **Step 2: Update callers to pass contextGroupId**

- [ ] **Step 3: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add modtools/composables/useModMessages.js
git commit -m "feat: sort mod messages by contextual group arrival time"
```

---

## Task 17: Nuxt — Non-Mod Components ❌ NOT DONE

Three of the four sites still use `groups[0]`:
- [MyMessage.vue:942](iznik-nuxt3/components/MyMessage.vue#L942) — `composeStore.group = msg.groups[0].groupid`
- [OutcomeModal.vue:304](iznik-nuxt3/components/OutcomeModal.vue#L304) — `ret = message.value.groups[0].groupid`
- [MessageReportModal.vue:140](iznik-nuxt3/components/MessageReportModal.vue#L140) — also tracked by Task 21
- `ExportPost.vue:9-10` and `ModLog.vue:78-86` — re-verify before crossing off.

**Files:**
- Modify: `iznik-nuxt3/components/MyMessage.vue:796,827,907`
- Modify: `iznik-nuxt3/components/OutcomeModal.vue:301-308`
- Modify: `iznik-nuxt3/components/MessageReportModal.vue:147`
- Modify: `iznik-nuxt3/components/ExportPost.vue:9-10`
- Modify: `iznik-nuxt3/modtools/components/ModLog.vue:78-86`

- [ ] **Step 1: MyMessage.vue — show all groups**

Lines 796, 827, 907 reference `groups[0].groupid`. For MyMessage (the poster's view), the poster sees all their groups. Update the edit flow to use the first group (global edit doesn't need a specific group), but display all groups.

- [ ] **Step 2: OutcomeModal.vue — remove groupid dependency**

Outcomes are global. The groupid computed (lines 301-308) is used for... check what it's used for. If it's only passed to the API, and the API doesn't need it for outcomes, remove it.

- [ ] **Step 3: MessageReportModal.vue — report to all groups**

Line 147 opens a chat to mods of `groups[0].groupid`. For multi-group, the report should notify all groups' mods. The API `handleReport` should be updated to queue notifications for all groups (Task 9 area). The client just needs to trigger the report — the backend handles notification fanout.

- [ ] **Step 4: ExportPost.vue — show all group names**

```vue
<span v-for="(g, idx) in post.groups" :key="g.groupid">
  {{ g.namedisplay }}<span v-if="idx < post.groups.length - 1">, </span>
</span>
```

- [ ] **Step 5: ModLog.vue — show all groups**

Replace `groups[0].collection === 'Pending'` check (line 80-85) with a check that shows the collection for the contextual group, or shows all groups' statuses.

- [ ] **Step 6: Run vitest, commit**

```bash
cd iznik-nuxt3
git add components/MyMessage.vue components/OutcomeModal.vue components/MessageReportModal.vue components/ExportPost.vue modtools/components/ModLog.vue
git commit -m "feat: non-mod components handle multi-group messages"
```

---

## Task 18: Digest Dedup ⚠ NEEDS VERIFICATION

`UnifiedDigestService::deduplicatePosts()` exists and uses `tnpostid`/`postedToGroups`. Multi-group tests exist for other services (`MessageExpiryServiceTest::test_multi_group_message_processed_once`, `AutoApproveServiceTest::test_multi_group_message_approved_independently`) but no explicit `UnifiedDigestService` test for the same-`msgid`-on-multiple-groups case was found — add that test, then mark done.

**Files:**
- Modify: `iznik-batch/app/Services/UnifiedDigestService.php`

The `UnifiedDigestService` already has `deduplicatePosts()` with TN dedup via `tnpostid` (line 288). This should already handle multi-group messages correctly since it deduplicates by `tnpostid` or `fromuser|subject|location`. Verify and add a test.

- [ ] **Step 1: Verify existing dedup handles multi-group**

Read `deduplicatePosts()` — it groups by dedup key and collects `postedToGroups`. When the same `messages.id` appears for multiple groups (because of multi-group `messages_groups` rows), the dedup key will match and groups will be aggregated. This should work.

- [ ] **Step 2: Write test for same-message multi-group dedup**

```php
public function test_deduplication_with_same_message_on_multiple_groups(): void
{
    // Create a single message on two groups (the new multi-group model).
    // The digest query joins messages with messages_groups, so the same message
    // appears twice with different groupids. deduplicatePosts should merge them.
    
    // ... test setup creating a message with two messages_groups rows
    // ... verify deduplicatePosts returns it once with both groups in postedToGroups
}
```

- [ ] **Step 3: Run test, commit**

```bash
cd iznik-batch && git add app/Services/UnifiedDigestService.php tests/
git commit -m "test: verify digest dedup handles multi-group messages"
```

---

## Task 19: Stats Audit ❌ NOT DONE

`plans/multi-group-stats-audit.md` does not exist. The audit hasn't been written up.

**Files:** Various — this is an investigation task.

Audit all stats queries to determine impact of multi-group messages on counts.

- [ ] **Step 1: Find all stats queries**

Search for `COUNT(*)` and `COUNT(DISTINCT` in Go, PHP, and Laravel code that reference `messages` or `messages_groups`.

- [ ] **Step 2: Categorise each query**

For each: does it count `messages.id` (would undercount after dedup) or `messages_groups` rows (correct for per-group stats)?

- [ ] **Step 3: Document findings**

Write findings to `plans/multi-group-stats-audit.md`. Flag any queries that need changing.

- [ ] **Step 4: Fix any broken queries**

- [ ] **Step 5: Commit**

```bash
git add plans/multi-group-stats-audit.md
git commit -m "docs: stats audit for multi-group messages impact"
```

---

## Task 20: Schema Cleanup — Drop Old Columns ❌ NOT DONE

No `drop_per_group_columns_from_messages` migration exists yet. Several handlers still dual-write to `messages.heldby` for backwards compat, so this can't run until those writes are removed (and V1 PHP is retired).

**Files:**
- Create: `iznik-batch/database/migrations/YYYY_MM_DD_000001_drop_per_group_columns_from_messages.php`

**Only run this after all code in Tasks 3-17 is deployed and stable.** This is the final cleanup.

- [ ] **Step 1: Verify no code reads from messages.heldby/spamtype/spamreason**

Search Go, PHP, and Laravel for `messages.heldby`, `messages.spamtype`, `messages.spamreason`. Should find zero hits outside of the migration itself.

- [ ] **Step 2: Write the cleanup migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['heldby']);
            $table->dropColumn(['heldby', 'spamtype', 'spamreason']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('heldby')->nullable();
            $table->string('spamtype', 50)->nullable();
            $table->string('spamreason', 255)->nullable();
            $table->foreign('heldby')->references('id')->on('users')->onDelete('set null');
        });
    }
};
```

- [ ] **Step 3: Run migration, verify, commit**

```bash
cd iznik-batch && git add database/migrations/*drop_per_group_columns*
git commit -m "cleanup: drop heldby/spamtype/spamreason from messages table (now on messages_groups)"
```

---

## Task 21: Nuxt — Message Report Uses Best Shared Group ❌ NOT DONE

[MessageReportModal.vue:140](iznik-nuxt3/components/MessageReportModal.vue#L140) still uses `message.value.groups[0].groupid`. No `reportGroupId` computed exists.

**Files:**
- Modify: `iznik-nuxt3/components/MessageReportModal.vue:147`

When a user reports a message, the report should go to a single group — the group that both the user and the message share, choosing the most recently posted one. Currently it uses `groups[0].groupid` which may not be a group the reporter is on.

- [ ] **Step 1: Update MessageReportModal.vue**

Replace line 147's `message.value.groups[0].groupid` with logic to find the best shared group:

```javascript
const reportGroupId = computed(() => {
  if (!message.value?.groups?.length) return null
  const myGroups = meStore.me?.groups?.map(g => g.id) || []
  // Find groups that both the user and the message are on, sorted by most recent arrival.
  const shared = message.value.groups
    .filter(g => myGroups.includes(parseInt(g.groupid)))
    .sort((a, b) => new Date(b.arrival) - new Date(a.arrival))
  return shared.length > 0 ? shared[0].groupid : message.value.groups[0].groupid
})
```

Use `reportGroupId.value` in the `openChatToMods` call.

- [ ] **Step 2: Write vitest**

Test that when a message is on groups A and B, and the user is only on group B, the report goes to group B's mods.

- [ ] **Step 3: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add components/MessageReportModal.vue tests/
git commit -m "feat: message report targets the shared group with most recent posting"
```

---

## Task 22: V1 PHP Audit Confirmation ❌ NOT DONE

`plans/multi-group-v1-audit-results.md` does not exist.

**Files:** None — this is a verification task.

Cross-reference the V1 PHP audit (from the design spec) against V2 Go code to confirm all identified gaps are covered.

- [ ] **Step 1: Verify each V1 gap has V2 coverage**

| V1 Issue | V2 Task | Status |
|----------|---------|--------|
| `reject()` updates ALL groups | Already correct (takes groupid) | Verify |
| `sendForReview()` updates ALL groups | Task 10 | Verify |
| `autoapprove()` deletes all groups | Search for autoapprove in Go | Verify |
| `move()` deletes all, inserts one | Task 6 redesign | Verify |
| `spam()` is global delete | Task 7 | Verify |
| `ModBot` uses first group for rules | Search for modbot/automod in Go | Verify |

- [ ] **Step 2: Search for autoapprove logic in Go**

```bash
docker exec freegle-apiv2 grep -rn "autoapprove\|autoApprove\|auto.approve" --include="*.go" .
```

If found, verify it handles per-group correctly. If not found, note as not-yet-migrated.

- [ ] **Step 3: Search for modbot/automod logic in Go**

```bash
docker exec freegle-apiv2 grep -rn "modbot\|ModBot\|automod\|AutoMod" --include="*.go" .
```

- [ ] **Step 4: Document findings**

Write to `plans/multi-group-v1-audit-results.md`.

- [ ] **Step 5: Commit**

```bash
git add plans/multi-group-v1-audit-results.md
git commit -m "docs: V1 audit confirmation for multi-group messages"
```

---

## Task 23: Go API — Per-Group Repost Scheduling ❌ NOT DONE

[message.go:727](iznik-server-go/message/message.go#L727) still computes `repostAt` from `MessageGroups[0].Arrival`.

**Files:**
- Modify: `iznik-server-go/message/message.go:727`

The repost scheduling computes `repostAt` from `MessageGroups[0].Arrival`. For a multi-group message each group has its own arrival and own `autoreposts` counter, so the repost decision must be evaluated per-group.

- [ ] **Step 1: Replace the loop body**

Iterate `message.MessageGroups` and compute `repostAt`/`canRepost` per group. Either:
- Return the earliest `repostAt` across groups (so the caller still has a single value), OR
- Return a per-group struct so the caller can decide which group to repost on

The repost action itself is already per-group (`messages_groups.autoreposts`, `lastautopostwarning`), so the natural answer is per-group output. Check the caller of this block (search `repostAt` upwards in message.go) to confirm what shape it expects.

- [ ] **Step 2: Add a test**

Create a message on Group A (arrival 30 days ago) and Group B (arrival yesterday). Verify the repost calculation flags A as eligible but not B.

- [ ] **Step 3: Run tests, commit**

```bash
cd iznik-server-go && git add message/message.go test/message_test.go
git commit -m "feat: evaluate repost schedule per-group instead of using first group's arrival"
```

---

## Task 24: Go API — Per-Group convertToDraft ❌ NOT DONE

[message.go:2233](iznik-server-go/message/message.go#L2233) still uses `getPrimaryGroupForMessage`; line 2243 still `DELETE FROM messages_groups WHERE msgid = ?` without `groupid` filter.

**Files:**
- Modify: `iznik-server-go/message/message.go:2225-2250` (convertToDraft handler)

Two bugs in the current code:
1. Line 2233 picks the first group via `getPrimaryGroupForMessage` — should use `req.Groupid` (the group the user is withdrawing from) when provided.
2. Line 2243 does `DELETE FROM messages_groups WHERE msgid = ?` without a `groupid` filter, removing the message from ALL groups. For multi-group withdrawal this is wrong — should only delete the targeted group's row, and only soft-delete the message itself if it was the last group.

- [ ] **Step 1: Write failing test**

Create a message on Groups A and B. Withdraw from Group A. Assert: Group A row gone, Group B row still present, `messages_drafts` has a row for Group A only, `messages.deleted` is null.

- [ ] **Step 2: Implement**

- Use `req.Groupid` if provided; fall back to primary only when caller hasn't supplied one (e.g. owner with single-group message).
- Change DELETE to include `AND groupid = ?`.
- After delete, count remaining `messages_groups` rows for this msgid. If zero, soft-delete the message; otherwise leave it alone.
- Decide whether `messages_drafts` should contain one row per withdrawn group (probably yes) — the unique key on the table will tell us.

- [ ] **Step 3: Update the Nuxt side**

The client must send `groupid` on the withdraw request. Audit `MyMessage.vue:942` (`composeStore.group = msg.groups[0].groupid`) and surrounding flow to ensure the withdrawn group is communicated. If a poster has the message on multiple groups, the UI should let them pick (or default to current context group).

- [ ] **Step 4: Run tests, commit**

---

## Task 25: Go API — Per-Group Edit Subject Reconstruction ❌ NOT DONE

[message.go:2695](iznik-server-go/message/message.go#L2695) and [message.go:3067](iznik-server-go/message/message.go#L3067) still call `getPrimaryGroupForMessage`.

**Files:**
- Modify: `iznik-server-go/message/message.go:2695` (edit handler — subject rebuild)
- Modify: `iznik-server-go/message/message.go:3067` (mod-delete audit log)

Line 2695 uses `getPrimaryGroupForMessage` to pick the group whose keyword is used to rebuild the subject (e.g. "OFFER:" vs "WANTED:"). For multi-group this should use the contextual group from the request when available. Same with line 3067's audit log — should log to the specific group the action was taken on.

- [ ] **Step 1: Update edit handler**

In the edit handler around line 2695, prefer `req.Groupid` when present, falling back to `getPrimaryGroupForMessage` for legacy callers. Keywords vary between groups, so picking the wrong group produces a wrong subject prefix.

- [ ] **Step 2: Update mod-delete audit log**

Around line 3067, take a `groupid` from the request body (this `deleteMessage` handler is the GET-style legacy or owner-delete path — confirm by reading the route binding). If the caller can supply a group, log against that; otherwise the primary fallback is acceptable for owner-initiated delete (which is global).

- [ ] **Step 3: Reduce `getPrimaryGroupForMessage` usage**

Audit remaining call sites (lines 1638, 3361). Add a comment on the function explaining it's a legacy fallback to be used only when no contextual group is available — never as the primary lookup for mod actions.

- [ ] **Step 4: Tests, commit**

---

## Task 26: Nuxt — Remaining `groups[0]` Sites ❌ NOT DONE

All five sites still match `groups[0]` — see grep results in the audit. Each needs the contextual-group treatment.

**Files:**
- Modify: `iznik-nuxt3/modtools/composables/useKeywords.js:47`
- Modify: `iznik-nuxt3/components/MessageHistory.vue:204`
- Modify: `iznik-nuxt3/modtools/components/ModLogGroup.vue:69`
- Modify: `iznik-nuxt3/pages/message/[id].vue:35`
- Modify: `iznik-nuxt3/components/MyMessage.vue:942`

These were not covered by Task 14 (which focused on ModMessage/ModMessageButton/etc).

- [ ] **Step 1: `useKeywords.js:47`** — returns `message.groups[0].groupid` as the keyword-source group. Take a `groupid` argument so the caller passes contextual group; fall back to `groups[0]` if not given.

- [ ] **Step 2: `MessageHistory.vue:204`** — `timeago(message.value?.groups[0]?.arrival)`. Should show arrival for the current display context group, not `groups[0]`. Accept a `groupid` prop and look it up via `message.groups.find(g => g.groupid === groupid)`.

- [ ] **Step 3: `ModLogGroup.vue:69`** — Falls back to `message.groups[0]` when `log.group` is null. Acceptable as a fallback, but add a comment explaining it's a last-resort. Verify with the existing test at `tests/unit/components/modtools/ModLogGroup.spec.js:114` (which currently locks in `groups[0]` behaviour — update the assertion if changing behaviour).

- [ ] **Step 4: `pages/message/[id].vue:35`** — checks `message.groups[0]?.collection === 'Rejected'` to decide visibility. For multi-group, the message is rejected only if ALL groups are rejected (or, depending on intent, if the contextual group is rejected). Use `message.groups.every(g => g.collection === 'Rejected')` for the safer interpretation.

- [ ] **Step 5: `MyMessage.vue:942`** — `composeStore.group = msg.groups[0].groupid` when entering edit/repost. This is the poster's flow. If the message is on multiple groups, edit is global (per design) but the compose target group still has to be one; pick the most recent posted group or let the user choose. Document the choice in the design table.

- [ ] **Step 6: Run vitest, commit**

```bash
cd iznik-nuxt3 && git add modtools/composables/useKeywords.js components/MessageHistory.vue modtools/components/ModLogGroup.vue pages/message/[id].vue components/MyMessage.vue
git commit -m "feat: remaining client sites use contextual group instead of groups[0]"
```

---

## Task 27: Laravel — Digest Header Group Selection ❌ NOT DONE

[UnifiedDigest.php:123,264](iznik-batch/app/Mail/Digest/UnifiedDigest.php) still reads `$firstPost['postedToGroups'][0]`.

**Files:**
- Modify: `iznik-batch/app/Mail/Digest/UnifiedDigest.php:123,264`

Both sites read `$firstPost['postedToGroups'][0]` to choose the header group for a digest section. For multi-group posts the `postedToGroups` array is non-deterministic; this should pick the group whose digest this is (the recipient's group for that section) or the most-recently-arrived group.

- [ ] **Step 1: Identify the right group**

The digest is built per recipient, who has a primary group context. Thread that group ID through `deduplicatePosts()` output and use it here. If not available, fall back to the most recent arrival.

- [ ] **Step 2: Update both sites**

```php
$groupId = $this->preferredGroupForPost($firstPost, $this->recipientGroupId);
```

- [ ] **Step 3: Test**

Build a digest for a user where a multi-group post appears. Verify the header references the user's group, not an arbitrary one.

- [ ] **Step 4: Commit**

```bash
cd iznik-batch && git add app/Mail/Digest/UnifiedDigest.php tests/
git commit -m "feat: digest header uses recipient's group for multi-group posts"
```

---

## Task 29: Laravel — Mailable Tracking & Body Use Recipient's Group ❌ NOT DONE

**Files:**
- Modify: `iznik-batch/app/Mail/Message/DeadlineReached.php:51,69`
- Audit/modify: `iznik-batch/app/Console/Commands/Mail/TestMailCommand.php:918`
- Audit: any other mailable that passes `$message->groups->first()` to `TrackableEmail::initTracking()` or uses it to render group-specific copy

`DeadlineReached::__construct` calls `$message->groups->first()` to derive `$groupId` for `initTracking()`, and `build()` does the same to pick `$groupName`. For multi-group posts both pick an arbitrary group, which:
1. Files the `EmailTracking` row against the wrong group — skewing per-group open/click stats.
2. Renders an arbitrary group's name in the email body even when the recipient is on a different one.

`TrackableEmail::initTracking()` itself is generic (takes `?int $groupId`) — the fix lives in the callers.

- [ ] **Step 1: Pick the right group**

For the `DeadlineReached` email the recipient is `$user`. Filter `$message->groups` to groups the recipient is a member of, sorted by most-recent arrival; fall back to `groups->first()` only if the user has no overlap (defensive — shouldn't happen).

```php
$userGroupIds = $user->memberships()->pluck('groupid')->all();
$group = $message->groups
    ->filter(fn ($g) => in_array($g->id, $userGroupIds, true))
    ->sortByDesc(fn ($g) => $g->pivot->arrival ?? null)
    ->first()
    ?? $message->groups->first();
```

Use the result for both `initTracking()` and the body `$groupName`.

- [ ] **Step 2: Audit other mailables**

Search `iznik-batch/app/Mail` for any other class that:
- Passes a group ID into `initTracking()` derived from `groups->first()`, or
- Renders a group-specific name/branding using `groups->first()`.

Update each to use the recipient-shared-group rule from Step 1, or document why a single arbitrary group is acceptable (e.g. moderator-targeted emails where the moderator may be on only one of the message's groups — in which case use that group).

- [ ] **Step 3: Test**

Create a message on Groups A and B with a recipient who is only on Group B. Send `DeadlineReached`. Assert: `EmailTracking.group_id` = B, and the rendered body references Group B's `nameshort`.

- [ ] **Step 4: Commit**

```bash
cd iznik-batch && git add app/Mail/Message/DeadlineReached.php tests/
git commit -m "fix: multi-group mailables track and render the recipient's group"
```

---

## Task 28: Comment Cleanup — `getPrimaryGroupForMessage` ❌ NOT DONE

[message.go:1478-1479](iznik-server-go/message/message.go#L1478-L1479) still has the original one-line comment.

**Files:**
- Modify: `iznik-server-go/message/message.go:1478-1479`

Update the comment on `getPrimaryGroupForMessage` to make clear it is a legacy fallback, not the canonical lookup. After Tasks 4-11 and 23-25, the remaining callers should be: legacy owner-initiated paths that have no group context, and ModBot/audit fallbacks. Document this.

- [ ] **Step 1: Update the comment**

```go
// getPrimaryGroupForMessage returns one groupid for a message.
//
// LEGACY FALLBACK ONLY — use a request-supplied groupid when available.
// Multi-group messages have N groups; this function picks one arbitrarily.
// For per-group moderation actions (hold/release/spam/delete) always use the
// groupid the mod is acting on, not this fallback.
func getPrimaryGroupForMessage(db *gorm.DB, msgid uint64) uint64 {
```

- [ ] **Step 2: Commit**

```bash
cd iznik-server-go && git add message/message.go
git commit -m "docs: clarify getPrimaryGroupForMessage is a legacy fallback for multi-group"
```

---

## Execution Notes

**Backwards compatibility during rollout:** Tasks 4, 5, and 8 include a dual-write to both `messages.heldby` and `messages_groups.heldby`. This keeps V1 PHP code working during the transition. Task 20 removes the old columns only after V1 is fully retired.

**Testing note:** The Go tests cannot be run from WSL directly. Use `docker exec freegle-apiv2 go test ./test/...` to run inside the container.

**Dependencies between tasks:**
- Tasks 1-2 (schema) must be done first
- Tasks 3-11 (Go API) depend on Tasks 1-2 but are independent of each other
- Task 12 (TN dedup) depends on Tasks 1-2
- Tasks 13-17 (Nuxt) can be done in parallel with Go tasks, but should be tested after Go changes are deployed
- Task 18 (digest) depends on Tasks 1-2
- Task 19 (stats) can be done at any time
- Task 20 (cleanup) must be last — after all code deployed and V1 retired
- Task 21 (reports) depends on Tasks 1-2, independent of other Go tasks
- Task 22 (V1 audit) can be done at any time, good to do early as a sanity check
- Tasks 23-25 (Go API repost/draft/edit per-group) depend on Tasks 1-2; independent of each other
- Task 26 (remaining Nuxt `groups[0]` sites) depends on Task 13 store change; otherwise independent
- Task 27 (digest header group) depends on Task 18
- Task 28 (`getPrimaryGroupForMessage` comment) should be done after Tasks 23-25 reduce its callers
