# Report-a-chat spammer-team fallback — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a member reports a User2User chat with someone they share no Freegle group with, route the report to the central spam team instead of forcing a community choice; keep the community selector (restricted to genuinely common groups) when a group *is* in common.

**Architecture:** Live path only — Nuxt3 frontend → Go apiv2 → Laravel batch. A new `GET /chat/{id}/commongroups` tells the modal whether a common group exists. A new `ReportNoGroup` action on `POST /chatrooms` queues an `email_chat_spam_report` background task; the batch worker emails the spam team (new `spam_addr` config) with a ModTools link. PHP apiv1 is reference-only and untouched.

**Tech Stack:** Go (fiber, gorm), Laravel (Mailable + background-task worker), Vue 3 (`<script setup>`, bootstrap-vue-next), Go `testing`, PHPUnit, Vitest.

**Spec:** `docs/superpowers/specs/2026-06-25-report-chat-no-common-group-design.md`
**Discourse:** https://discourse.ilovefreegle.org/t/reporting-chat-with-no-group/9828

---

## Working environment

All work happens in the worktree `/home/edward/FreegleDocker-report-chat-no-group`
on branch `feature/report-chat-no-common-group`. The escape guard is already
pointed there (`./freegle switch report-chat-no-group` was run).

**Running tests (project rule: status API only, never direct runners).** From the
worktree directory, use the helper which reads `PORT_STATUS` from the worktree's
`./.env`:

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
~/.claude/bin/test-wait go --start --timeout 1200       # Go (apiv2)
~/.claude/bin/test-wait laravel --start --timeout 1200  # batch
~/.claude/bin/test-wait vitest --start --timeout 1200   # frontend
```

The worktree status port (e.g. 12150) is shown by `./freegle status`; the curl
form is `curl -s -X POST http://localhost:<PORT>/api/tests/go` then poll
`.../api/tests/go/status`. Worktree vitest may need the changed spec/component
copied into the modtools-dev container before the run — see
`finding_worktree_hostscripts_escape_and_vitest_runner`.

## File structure

- **Go** `iznik-server-go/`
  - `chat/chatroom.go` — add `CommonGroup` type, `GetCommonGroups` handler, extend
    `ChatRoomPostRequest`, add `handleReportNoGroup`, add the dispatcher case.
  - `router/routes.go` — register `GET /chat/:id/commongroups`.
  - `test/chat_test.go` — tests for both new pieces.
- **Batch** `iznik-batch/`
  - `config/freegle.php` — add `spam_addr`.
  - `app/Models/BackgroundTask.php` — add `TASK_EMAIL_CHAT_SPAM_REPORT`.
  - `app/Mail/Chat/ChatSpamReportMail.php` — new Mailable.
  - `app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php` — import the
    mail, add dispatch case + `handleEmailChatSpamReport`.
  - `tests/Unit/Queue/ProcessBackgroundTasksCommandTest.php` — handler test.
- **Frontend** `iznik-nuxt3/`
  - `api/ChatAPI.js` — `commonGroups()` + `reportNoGroup()`.
  - `stores/chat.js` — `commonGroups()` + `reportNoGroup()` actions.
  - `components/ChatReportModal.vue` — fetch common groups, branch the UI.
  - `tests/unit/components/ChatReportModal.spec.js` — rewrite for both branches.

---

## Task 1: Go — `GET /chat/{id}/commongroups`

**Files:**
- Modify: `iznik-server-go/chat/chatroom.go` (add type + handler)
- Modify: `iznik-server-go/router/routes.go` (register route, after the
  `rg.Get("/chat/:id/message", chat.GetChatMessages)` line ~291)
- Test: `iznik-server-go/test/chat_test.go` (append)

- [ ] **Step 1: Write the failing tests**

Append to `iznik-server-go/test/chat_test.go`:

```go
// =============================================================================
// CommonGroups tests
// =============================================================================

func TestCommonGroupsShared(t *testing.T) {
	prefix := uniquePrefix("commongroups")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix+"_g")
	CreateTestMembership(t, user1ID, groupID, "Member")
	CreateTestMembership(t, user2ID, groupID, "Member")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	_, token := CreateTestSession(t, user1ID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/chat/"+fmt.Sprint(chatid)+"/commongroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var groups []chat.CommonGroup
	json2.Unmarshal(rsp(resp), &groups)
	assert.Equal(t, 1, len(groups))
	assert.Equal(t, groupID, groups[0].ID)
}

func TestCommonGroupsNone(t *testing.T) {
	prefix := uniquePrefix("commongroupsnone")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	g1 := CreateTestGroup(t, prefix+"_g1")
	g2 := CreateTestGroup(t, prefix+"_g2")
	CreateTestMembership(t, user1ID, g1, "Member")
	CreateTestMembership(t, user2ID, g2, "Member")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	_, token := CreateTestSession(t, user1ID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/chat/"+fmt.Sprint(chatid)+"/commongroups?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var groups []chat.CommonGroup
	json2.Unmarshal(rsp(resp), &groups)
	assert.Equal(t, 0, len(groups))
}

func TestCommonGroupsNotMember(t *testing.T) {
	prefix := uniquePrefix("commongroupsnm")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	outsiderID := CreateTestUser(t, prefix+"_out", "User")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	_, token := CreateTestSession(t, outsiderID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/chat/"+fmt.Sprint(chatid)+"/commongroups?jwt="+token, nil))
	assert.Equal(t, 403, resp.StatusCode)
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait go --start --timeout 1200`
Expected: FAIL — `chat.CommonGroup` undefined / route 404.

- [ ] **Step 3: Add the type and handler in `chat/chatroom.go`**

Add near the other request/response types (e.g. just above `PostChatRoom`):

```go
// CommonGroup is a group that both participants of a chat belong to.
type CommonGroup struct {
	ID          uint64 `json:"id"`
	Namedisplay string `json:"namedisplay"`
}

// GetCommonGroups handles GET /chat/{id}/commongroups - the groups the two
// participants of a chat have in common. The report flow uses this to decide
// whether to route a report to a community's moderators (a common group exists)
// or to the central spam team (none). Caller must be a participant.
func GetCommonGroups(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid chat ID")
	}

	db := database.DBConn

	var room struct {
		ID    uint64
		User1 uint64
		User2 uint64
	}
	db.Raw("SELECT id, user1, user2 FROM chat_rooms WHERE id = ?", id).Scan(&room)
	if room.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Chat not found")
	}
	if room.User1 != myid && room.User2 != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not a member of this chat")
	}

	groups := []CommonGroup{}
	db.Raw("SELECT g.id, COALESCE(NULLIF(g.namefull, ''), g.nameshort) AS namedisplay "+
		"FROM `groups` g "+
		"INNER JOIN memberships m1 ON m1.groupid = g.id AND m1.userid = ? "+
		"INNER JOIN memberships m2 ON m2.groupid = g.id AND m2.userid = ? "+
		"ORDER BY namedisplay",
		room.User1, room.User2).Scan(&groups)

	return c.JSON(groups)
}
```

If `strconv` is not already imported in `chatroom.go`, add it to the import block.

- [ ] **Step 4: Register the route in `router/routes.go`**

Immediately after `rg.Get("/chat/:id/message", chat.GetChatMessages)`:

```go
		// @Router /chat/{id}/commongroups [get]
		// @Summary Groups in common between the two chat participants
		// @Tags chat
		// @Produce json
		// @Param id path integer true "Chat ID"
		// @Success 200 {array} chat.CommonGroup
		rg.Get("/chat/:id/commongroups", chat.GetCommonGroups)
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait go --start --timeout 1200`
Expected: PASS for `TestCommonGroupsShared`, `TestCommonGroupsNone`, `TestCommonGroupsNotMember`.

- [ ] **Step 6: Commit**

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
git add iznik-server-go/chat/chatroom.go iznik-server-go/router/routes.go iznik-server-go/test/chat_test.go
git commit -m "$(cat <<'EOF'
feat(chat): GET /chat/{id}/commongroups for the report flow

Returns the groups the two chat participants share, so the report modal can
decide between community-routed and central-spam-team-routed reports.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01M56vbv9WabnZAkrfmeY4vL
EOF
)"
```

---

## Task 2: Go — `ReportNoGroup` action

**Files:**
- Modify: `iznik-server-go/chat/chatroom.go` (extend `ChatRoomPostRequest`,
  add `handleReportNoGroup`, add dispatcher case in `PostChatRoom`)
- Test: `iznik-server-go/test/chat_test.go` (append)

- [ ] **Step 1: Write the failing tests**

Append to `iznik-server-go/test/chat_test.go`:

```go
// =============================================================================
// ReportNoGroup tests
// =============================================================================

func TestReportNoGroup(t *testing.T) {
	prefix := uniquePrefix("reportnogroup")
	db := database.DBConn
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	CreateTestChatMessage(t, chatid, user2ID, "Want a girlfriend?")
	_, token := CreateTestSession(t, user1ID)

	payload := map[string]interface{}{
		"id": chatid, "action": "ReportNoGroup", "reason": "Spam", "comment": "creepy",
	}
	s, _ := json2.Marshal(payload)
	request := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(request)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_chat_spam_report' AND JSON_EXTRACT(data, '$.chatid') = ?", chatid).Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0))
}

func TestReportNoGroupRejectedWhenCommonGroup(t *testing.T) {
	prefix := uniquePrefix("reportnogroupcg")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	groupID := CreateTestGroup(t, prefix+"_g")
	CreateTestMembership(t, user1ID, groupID, "Member")
	CreateTestMembership(t, user2ID, groupID, "Member")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	_, token := CreateTestSession(t, user1ID)

	payload := map[string]interface{}{"id": chatid, "action": "ReportNoGroup", "reason": "Spam"}
	s, _ := json2.Marshal(payload)
	request := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(request)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestReportNoGroupNotMember(t *testing.T) {
	prefix := uniquePrefix("reportnogroupnm")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	outsiderID := CreateTestUser(t, prefix+"_out", "User")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	_, token := CreateTestSession(t, outsiderID)

	payload := map[string]interface{}{"id": chatid, "action": "ReportNoGroup", "reason": "Spam"}
	s, _ := json2.Marshal(payload)
	request := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(request)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait go --start --timeout 1200`
Expected: FAIL — `ReportNoGroup` action falls through to roster update; no task row.

- [ ] **Step 3: Extend the request struct and add the handler in `chat/chatroom.go`**

Add two fields to `ChatRoomPostRequest`:

```go
type ChatRoomPostRequest struct {
	ID          uint64 `json:"id"`
	Action      string `json:"action"`
	Status      string `json:"status"`
	Lastmsgseen uint64 `json:"lastmsgseen"`
	Allowback   bool   `json:"allowback"`
	Reason      string `json:"reason"`
	Comment     string `json:"comment"`
}
```

Add the handler (next to `handleReferToSupport`):

```go
// handleReportNoGroup queues a report to the central spam team for a User2User
// chat whose participants share no Freegle group (so it can't be routed to a
// community's moderators). The server re-checks "no common group" so a client
// cannot misuse this to bypass community routing.
func handleReportNoGroup(c *fiber.Ctx, db *gorm.DB, myid uint64, req ChatRoomPostRequest) error {
	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Chat ID required")
	}
	if req.Reason == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Reason required")
	}

	var room ChatRoom
	db.Raw("SELECT id, chattype, user1, user2 FROM chat_rooms WHERE id = ?", req.ID).Scan(&room)
	if room.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Chat not found")
	}
	if room.User1 != myid && room.User2 != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not a member of this chat")
	}

	var common int64
	db.Raw("SELECT COUNT(*) FROM memberships m1 "+
		"INNER JOIN memberships m2 ON m1.groupid = m2.groupid "+
		"WHERE m1.userid = ? AND m2.userid = ?",
		room.User1, room.User2).Scan(&common)
	if common > 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Groups in common exist; use the normal report flow")
	}

	db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('chatid', ?, 'userid', ?, 'reason', ?, 'comment', ?))",
		"email_chat_spam_report", req.ID, myid, req.Reason, req.Comment)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
```

- [ ] **Step 4: Add the dispatcher case in `PostChatRoom`**

In the `switch req.Action` block, after the `ReferToSupport` case:

```go
	case "ReportNoGroup":
		return handleReportNoGroup(c, db, myid, req)
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait go --start --timeout 1200`
Expected: PASS for the three `TestReportNoGroup*` tests (and Task 1 tests still pass).

- [ ] **Step 6: Commit**

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
git add iznik-server-go/chat/chatroom.go iznik-server-go/test/chat_test.go
git commit -m "$(cat <<'EOF'
feat(chat): ReportNoGroup action queues a spam-team report

For a User2User chat whose participants share no group, queue an
email_chat_spam_report background task. Server re-checks there is genuinely no
common group so the action can't bypass community routing.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01M56vbv9WabnZAkrfmeY4vL
EOF
)"
```

---

## Task 3: Batch — `spam_addr` config

**Files:**
- Modify: `iznik-batch/config/freegle.php`

- [ ] **Step 1: Add the config entry**

In `iznik-batch/config/freegle.php`, immediately after the `chitchat_support_addr`
line, add:

```php
        // Spam team - receives chat reports where the two users share no group.
        'spam_addr' => env('FREEGLE_SPAM_ADDR', env('FREEGLE_SUPPORT_ADDR', 'support@ilovefreegle.org')),
```

- [ ] **Step 2: Verify it loads (no dedicated test; covered by Task 4's test which reads the address)**

This entry is exercised end-to-end by the Task 4 handler test (it asserts the mail
is sent, which requires `config('freegle.mail.spam_addr')` to resolve). No separate
step.

- [ ] **Step 3: Commit**

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
git add iznik-batch/config/freegle.php
git commit -m "$(cat <<'EOF'
feat(config): add spam_addr for chat-with-no-group reports

Defaults to the support address; production can point FREEGLE_SPAM_ADDR at the
real spam-team inbox without a code change.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01M56vbv9WabnZAkrfmeY4vL
EOF
)"
```

---

## Task 4: Batch — `ChatSpamReportMail` + background-task handler

**Files:**
- Create: `iznik-batch/app/Mail/Chat/ChatSpamReportMail.php`
- Modify: `iznik-batch/app/Models/BackgroundTask.php` (add constant)
- Modify: `iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php`
  (import, dispatch case, handler method)
- Test: `iznik-batch/tests/Unit/Queue/ProcessBackgroundTasksCommandTest.php` (append)

- [ ] **Step 1: Write the failing test**

Add `use App\Mail\Chat\ChatSpamReportMail;` near the top of
`ProcessBackgroundTasksCommandTest.php` (beside the existing
`use App\Mail\Chat\ReferToSupportMail;`), then append this test method to the class:

```php
    public function test_processes_email_chat_spam_report_task(): void
    {
        Mail::fake();

        $reporter = $this->createTestUser(['fullname' => 'Melissa Reporter']);
        $other = $this->createTestUser(['fullname' => 'Creepy Guy']);

        $chatId = DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $reporter->id,
            'user2' => $other->id,
        ]);

        DB::table('background_tasks')->insert([
            'task_type' => 'email_chat_spam_report',
            'data' => json_encode([
                'chatid' => $chatId,
                'userid' => $reporter->id,
                'reason' => 'Spam',
                'comment' => 'asked me out',
            ]),
            'created_at' => now(),
        ]);

        $this->mock(PushNotificationService::class);

        $this->artisan('queue:background-tasks', ['--max-iterations' => 1, '--sleep' => 0])
            ->assertSuccessful();
        $this->artisan('mail:spool:process')->assertSuccessful();

        Mail::assertSent(ChatSpamReportMail::class, function (ChatSpamReportMail $mail) use ($reporter, $other, $chatId) {
            $this->assertEquals('Melissa Reporter', $mail->reporterName);
            $this->assertEquals($reporter->id, $mail->reporterId);
            $this->assertEquals('Creepy Guy', $mail->otherUserName);
            $this->assertEquals($chatId, $mail->chatId);
            $this->assertEquals('Spam', $mail->reason);
            $this->assertEquals('asked me out', $mail->comment);
            return TRUE;
        });

        $task = DB::table('background_tasks')->first();
        $this->assertNotNull($task->processed_at);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait laravel --start --timeout 1200`
Expected: FAIL — `ChatSpamReportMail` class not found / task type unhandled.

- [ ] **Step 3: Create the Mailable**

Create `iznik-batch/app/Mail/Chat/ChatSpamReportMail.php`:

```php
<?php

namespace App\Mail\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text email sent to the central spam team when a user reports a chat with
 * someone they share no Freegle group with, so it can't be routed to a
 * community's moderators.
 *
 * Discourse: https://discourse.ilovefreegle.org/t/reporting-chat-with-no-group/9828
 */
class ChatSpamReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reporterName,
        public readonly int $reporterId,
        public readonly string $otherUserName,
        public readonly int $otherUserId,
        public readonly int $chatId,
        public readonly string $reason,
        public readonly string $comment,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: "{$this->reporterName} (#{$this->reporterId}) reported {$this->otherUserName} (#{$this->otherUserId}) - chat #{$this->chatId} (no group in common)",
        );
    }

    public function build(): static
    {
        $modSite = config('freegle.sites.mod');

        $lines = [];
        $lines[] = "This chat was reported, but the two people share no Freegle group, so it can't go to a community's volunteers - it has come to the central spam team.";
        $lines[] = "";
        $lines[] = "Reason: {$this->reason}";
        if ($this->comment !== '') {
            $lines[] = "Comment: {$this->comment}";
        }
        $lines[] = "";
        $lines[] = "Review the chat at {$modSite}/modtools/support/refer/{$this->chatId}";

        return $this->text('emails.plain.refer-to-support', ['body' => implode("\n", $lines)]);
    }
}
```

(The `emails.plain.refer-to-support` text view is the generic `{{ $body }}`
wrapper already used by `ReferToSupportMail`.)

- [ ] **Step 4: Add the BackgroundTask constant**

In `iznik-batch/app/Models/BackgroundTask.php`, beside the other `TASK_EMAIL_*`
constants, add:

```php
    public const TASK_EMAIL_CHAT_SPAM_REPORT    = 'email_chat_spam_report';
```

- [ ] **Step 5: Wire the dispatch + handler in `ProcessBackgroundTasksCommand.php`**

Add the import near the top (beside `use App\Mail\Chat\ReferToSupportMail;`):

```php
use App\Mail\Chat\ChatSpamReportMail;
```

In the `match ($taskType)` block, after the `TASK_REFER_TO_SUPPORT` line:

```php
            BackgroundTask::TASK_EMAIL_CHAT_SPAM_REPORT  => $this->handleEmailChatSpamReport($data, $spooler, $shouldSpool),
```

Add the handler method (next to `handleReferToSupport`):

```php
    protected function handleEmailChatSpamReport(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $chatId = (int) ($data['chatid'] ?? 0);
        $userId = (int) ($data['userid'] ?? 0);

        if ($chatId === 0 || $userId === 0) {
            throw new \RuntimeException('email_chat_spam_report requires chatid and userid');
        }

        $chat = DB::table('chat_rooms')->where('id', $chatId)->first();
        if (! $chat) {
            Log::warning("Chat not found for email_chat_spam_report: {$chatId}");
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            Log::warning("User not found for email_chat_spam_report: {$userId}");
            return;
        }

        $otherUserId = $chat->user1 == $userId ? $chat->user2 : $chat->user1;
        $otherUser = $otherUserId ? User::find($otherUserId) : null;
        $otherUserName = $otherUser ? ($otherUser->fullname ?: 'Unknown') : 'Unknown';

        $mail = new ChatSpamReportMail(
            reporterName: $user->fullname ?: 'Unknown',
            reporterId: $userId,
            otherUserName: $otherUserName,
            otherUserId: (int) ($otherUserId ?? 0),
            chatId: $chatId,
            reason: (string) ($data['reason'] ?? ''),
            comment: (string) ($data['comment'] ?? ''),
        );

        $recipients = array_map('trim', explode(',', config('freegle.mail.spam_addr')));
        $spooler->spool($mail, $recipients);

        Log::info('Sent chat spam report email', [
            'reporter_id' => $userId,
            'chat_id' => $chatId,
        ]);
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait laravel --start --timeout 1200`
Expected: PASS for `test_processes_email_chat_spam_report_task`.

- [ ] **Step 7: Commit**

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
git add iznik-batch/app/Mail/Chat/ChatSpamReportMail.php iznik-batch/app/Models/BackgroundTask.php iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php iznik-batch/tests/Unit/Queue/ProcessBackgroundTasksCommandTest.php
git commit -m "$(cat <<'EOF'
feat(batch): email the spam team for chat reports with no common group

Handle the email_chat_spam_report background task: build ChatSpamReportMail with
the reason, optional comment, and a ModTools link to the chat, and spool it to
spam_addr.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01M56vbv9WabnZAkrfmeY4vL
EOF
)"
```

---

## Task 5: Frontend — API + store methods

**Files:**
- Modify: `iznik-nuxt3/api/ChatAPI.js` (after `referToSupport`)
- Modify: `iznik-nuxt3/stores/chat.js` (after `report`)

- [ ] **Step 1: Add the API methods in `ChatAPI.js`**

After the `referToSupport(chatid) { ... }` method:

```js
  commonGroups(chatid) {
    return this.$getv2('/chat/' + chatid + '/commongroups')
  }

  reportNoGroup(chatid, reason, comment) {
    return this.$postv2('/chatrooms', {
      id: chatid,
      action: 'ReportNoGroup',
      reason,
      comment,
    })
  }
```

- [ ] **Step 2: Add the store actions in `stores/chat.js`**

Immediately after the `report(chatid, reason, comments, refchatid)` action:

```js
    async commonGroups(chatid) {
      return await api(this.config).chat.commonGroups(chatid)
    },
    async reportNoGroup(chatid, reason, comment) {
      await api(this.config).chat.reportNoGroup(chatid, reason, comment)
    },
```

- [ ] **Step 3: Commit (verified together with the modal in Task 6)**

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
git add iznik-nuxt3/api/ChatAPI.js iznik-nuxt3/stores/chat.js
git commit -m "$(cat <<'EOF'
feat(chat): commonGroups + reportNoGroup client methods

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01M56vbv9WabnZAkrfmeY4vL
EOF
)"
```

---

## Task 6: Frontend — `ChatReportModal.vue` branches + tests

**Files:**
- Modify: `iznik-nuxt3/components/ChatReportModal.vue`
- Test: `iznik-nuxt3/tests/unit/components/ChatReportModal.spec.js` (rewrite)

- [ ] **Step 1: Rewrite the spec for both branches**

Replace the contents of `iznik-nuxt3/tests/unit/components/ChatReportModal.spec.js`:

```js
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import { modalBootstrapStubs } from '../mocks/bootstrap-stubs'
import ChatReportModal from '~/components/ChatReportModal.vue'

const mockHide = vi.fn()
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: ref(null), hide: mockHide }),
}))

const mockOpenChatToMods = vi.fn()
const mockReport = vi.fn()
const mockReportNoGroup = vi.fn()
const mockCommonGroups = vi.fn()
vi.mock('~/stores/chat', () => ({
  useChatStore: () => ({
    openChatToMods: mockOpenChatToMods,
    report: mockReport,
    reportNoGroup: mockReportNoGroup,
    commonGroups: mockCommonGroups,
  }),
}))

async function createWrapper(props = {}) {
  const wrapper = mount(ChatReportModal, {
    props: { user: { displayname: 'Test User' }, chatid: 123, ...props },
    global: { stubs: { ...modalBootstrapStubs } },
  })
  await flushPromises()
  return wrapper
}

describe('ChatReportModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockOpenChatToMods.mockResolvedValue(999)
    mockCommonGroups.mockResolvedValue([])
  })

  describe('common group exists', () => {
    beforeEach(() => {
      mockCommonGroups.mockResolvedValue([{ id: 1, namedisplay: 'Group 1' }])
    })

    it('shows the community selector', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.text()).toContain('Which community is this about?')
      expect(wrapper.find('[data-testid="group-select"]').exists()).toBe(true)
    })

    it('routes the report to the community mods', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('[data-testid="reason-select"]').setValue('Spam')
      await wrapper.find('textarea').setValue('creepy')
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockOpenChatToMods).toHaveBeenCalledWith(1)
      expect(mockReport).toHaveBeenCalled()
      expect(mockReportNoGroup).not.toHaveBeenCalled()
    })
  })

  describe('no common group (spam-team fallback)', () => {
    it('hides the selector and shows the central-volunteers note', async () => {
      const wrapper = await createWrapper()
      expect(wrapper.text()).not.toContain('Which community is this about?')
      expect(wrapper.text()).toContain('central volunteers')
      expect(wrapper.find('[data-testid="group-select"]').exists()).toBe(false)
    })

    it('routes the report to the spam team with an optional empty comment', async () => {
      const wrapper = await createWrapper()
      await wrapper.find('[data-testid="reason-select"]').setValue('Spam')
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockReportNoGroup).toHaveBeenCalledWith(123, 'Spam', '')
      expect(mockOpenChatToMods).not.toHaveBeenCalled()
    })

    it('does not send without a reason', async () => {
      const wrapper = await createWrapper()
      const sendBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Send Report'))
      await sendBtn.trigger('click')
      await flushPromises()
      expect(mockReportNoGroup).not.toHaveBeenCalled()
    })
  })

  describe('close action', () => {
    it('calls hide when Close is clicked', async () => {
      const wrapper = await createWrapper()
      const closeBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('Close'))
      await closeBtn.trigger('click')
      expect(mockHide).toHaveBeenCalled()
    })
  })
})
```

- [ ] **Step 2: Run the spec to verify it fails**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait vitest --start --timeout 1200`
Expected: FAIL — modal still renders `GroupSelect`, has no `reason-select` testid,
calls neither `commonGroups` nor `reportNoGroup`. (If the worktree runner can't see
the changed files, `docker cp` the component + spec into the modtools-dev container
first per `finding_worktree_hostscripts_escape_and_vitest_runner`.)

- [ ] **Step 3: Rewrite the component**

Replace the contents of `iznik-nuxt3/components/ChatReportModal.vue`:

```vue
<template>
  <b-modal
    ref="modal"
    scrollable
    title="Oh dear..."
    size="lg"
    no-stacking
    modal-class="confirm-modal"
  >
    <template #default>
      <b-row>
        <b-col>
          <p>Sorry you're having trouble.</p>
          <div v-if="loading" class="text-center my-3">
            <b-spinner />
          </div>
          <template v-else>
            <template v-if="commonGroups.length">
              <h4>Which community is this about?</h4>
              <b-form-select
                v-model="groupid"
                class="mt-1 mb-1"
                data-testid="group-select"
              >
                <option :value="null">-- Please choose --</option>
                <option v-for="g in commonGroups" :key="g.id" :value="g.id">
                  {{ g.namedisplay }}
                </option>
              </b-form-select>
            </template>
            <p v-else class="text-muted">
              We'll pass this to our central volunteers who deal with this kind of
              thing.
            </p>
            <h4>Why are you reporting this?</h4>
            <b-form-select
              v-model="reason"
              class="mt-1 mb-1"
              data-testid="reason-select"
            >
              <option :value="null">-- Please choose --</option>
              <option value="Spam">It's Spam</option>
              <option value="Other">Something else</option>
            </b-form-select>
            <h4>What's wrong?</h4>
            <b-form-textarea
              v-model="comments"
              placeholder="Please tell us what's wrong.  This will go to our lovely volunteers, who will try to help you."
            />
          </template>
        </b-col>
      </b-row>
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Close </b-button>
      <b-button variant="primary" :disabled="loading" @click="send">
        Send Report
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useChatStore } from '~/stores/chat'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  user: {
    type: Object,
    required: true,
  },
  chatid: {
    type: Number,
    required: true,
  },
})

const chatStore = useChatStore()
const { modal, hide } = useOurModal()

const groupid = ref(null)
const reason = ref(null)
const comments = ref(null)
const commonGroups = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const groups = await chatStore.commonGroups(props.chatid)
    commonGroups.value = Array.isArray(groups) ? groups : []
    if (commonGroups.value.length === 1) {
      groupid.value = commonGroups.value[0].id
    }
  } catch (e) {
    commonGroups.value = []
  } finally {
    loading.value = false
  }
})

async function send() {
  if (!reason.value) {
    return
  }

  if (commonGroups.value.length) {
    // Route to the chosen community's moderators (existing flow).
    if (!groupid.value || !comments.value) {
      return
    }
    const chatid = await chatStore.openChatToMods(groupid.value)
    await chatStore.report(chatid, reason.value, comments.value, props.chatid)
  } else {
    // No group in common: route to the central spam team. Comment optional.
    await chatStore.reportNoGroup(props.chatid, reason.value, comments.value || '')
  }

  hide()
}
</script>
```

- [ ] **Step 4: Run the spec to verify it passes**

Run: `cd /home/edward/FreegleDocker-report-chat-no-group && ~/.claude/bin/test-wait vitest --start --timeout 1200`
Expected: PASS for all `ChatReportModal` cases.

- [ ] **Step 5: Commit**

```bash
cd /home/edward/FreegleDocker-report-chat-no-group
git add iznik-nuxt3/components/ChatReportModal.vue iznik-nuxt3/tests/unit/components/ChatReportModal.spec.js
git commit -m "$(cat <<'EOF'
feat(chat): spammer-team fallback in the report modal

Fetch groups in common on open: keep the community selector (restricted to the
genuinely common groups) when one exists; otherwise hide it and route the report
to the central spam team with a simple click. Fixes the silent no-op when no
group was selectable.

Discourse: https://discourse.ilovefreegle.org/t/reporting-chat-with-no-group/9828

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01M56vbv9WabnZAkrfmeY4vL
EOF
)"
```

---

## Final verification

- [ ] Run the three suites green from the worktree:
  `~/.claude/bin/test-wait go --start --timeout 1200`,
  `~/.claude/bin/test-wait laravel --start --timeout 1200`,
  `~/.claude/bin/test-wait vitest --start --timeout 1200`.
- [ ] Manually sanity-check in the worktree browser (URL from `./freegle status`,
  Chrome MCP `isolatedContext: "report-chat-no-group"`): open a User2User chat with
  someone in no common group, click Report — no community dropdown, simple submit;
  open one with a common group — dropdown present, lists only common groups.
- [ ] Push the branch and open a PR (allowed once all suites pass locally). PR body
  must embed the Discourse link. Do **not** merge.

## Spec coverage check

- "Fallback to spam team when no common group" → Tasks 2, 4, 6.
- "Community selector stays when a group is in common" → Task 6 (common-group branch).
- "No destination choice / simple click / note for the team" → Task 4 (server-built
  note in the mail), Task 6 (no selector, comment optional).
- "Fix the silent no-op" → Task 6 `send()`.
- "Restrict dropdown to genuinely common groups" → Tasks 1 + 6.
- "Email delivery reusing ReferToSupport pattern; config address" → Tasks 3, 4.
- apiv1 explicitly not modified → noted in spec; no task.
