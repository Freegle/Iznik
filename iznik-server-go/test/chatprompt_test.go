package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// ensureFirstReplyTables creates the tables the first-reply work adds, for test
// databases loaded from schema rather than migrated.
func ensureFirstReplyTables(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS chat_prompts (
		chatmsgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		msgid BIGINT UNSIGNED NULL,
		msgids JSON NULL,
		kind VARCHAR(32) NOT NULL,
		options JSON NULL,
		answer VARCHAR(64) NULL,
		answered_at TIMESTAMP NULL DEFAULT NULL,
		expires_at TIMESTAMP NULL DEFAULT NULL,
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		KEY chat_prompts_msgid (msgid)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	db.Exec(`CREATE TABLE IF NOT EXISTS firstreply_event_metrics (
		day DATE NOT NULL,
		event VARCHAR(32) NOT NULL,
		count BIGINT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (day, event)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	// max_polygon is added by migration; add it here too so a schema-loaded test
	// database has it. Errors are ignored: the column may already exist.
	db.Exec("ALTER TABLE rippling_reach ADD COLUMN max_polygon GEOMETRY NULL SRID 3857")
	db.Exec("ALTER TABLE rippling_reach ADD COLUMN max_cumulative_users INT UNSIGNED NULL")
	// max_polygon_cells (plans/2026-08-24-rippling-reach-raster-storage.md) -
	// same belt-and-braces reasoning as max_polygon above.
	db.Exec("ALTER TABLE rippling_reach ADD COLUMN max_polygon_cells MEDIUMBLOB NULL")

	// Likewise chat_prompts.msgids. The CREATE TABLE above only fires when the
	// table is absent, and the migration that creates it is guarded the same way -
	// so a database that was migrated BEFORE msgids existed keeps a chat_prompts
	// with no msgids column, and every prompt test fails on the insert. CI is
	// unaffected (it migrates fresh); long-lived dev databases are not.
	db.Exec("ALTER TABLE chat_prompts ADD COLUMN msgids JSON NULL")

	// The 'Prompt' enum value likewise.
	var colType string
	db.Raw("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() " +
		"AND table_name = 'chat_messages' AND column_name = 'type'").Scan(&colType)
	if colType != "" && !strings.Contains(colType, "Prompt") {
		db.Exec("ALTER TABLE chat_messages MODIFY COLUMN `type` ENUM('Default','System','ModMail'," +
			"'Interested','Promised','Reneged','ReportedUser','Completed','Image','Address','Nudge'," +
			"'Schedule','ScheduleUpdated','Reminder','Prompt') NOT NULL DEFAULT 'Default'")
	}
}

// seedRipplingReach gives msgid a current reach of tick 1 and an eventual reach of tick 3.

// seedPrompt creates a Freegle prompt message in a chat and returns its id.
func seedPrompt(t *testing.T, chatID uint64, freegleID uint64, msgID uint64, kind string, options string) uint64 {
	db := database.DBConn

	res := db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, "+
		"reviewrequired, processingrequired, processingsuccessful) "+
		"VALUES (?, ?, 'Could you deliver?', 'Prompt', ?, NOW(), 0, 0, 1)",
		chatID, freegleID, msgID)
	if res.Error != nil {
		t.Fatalf("could not insert prompt message: %v", res.Error)
	}

	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&chatMsgID)

	db.Exec("INSERT INTO chat_prompts (chatmsgid, msgid, msgids, kind, options, expires_at, created_at) "+
		"VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())",
		chatMsgID, msgID, fmt.Sprintf("[%d]", msgID), kind, options)

	return chatMsgID
}

const deliveryOptions = `[{"value":"maybe","label":"Maybe, if it works for me"},{"value":"no","label":"Collection only"}]`

// TestAnswerChatPrompt_DeliveryPatchesThePost: answering has to actually change
// the post, otherwise it is a survey rather than a feature.

// TestAnswerChatPrompt_DeliveryPatchesThePost: answering has to actually change
// the post, otherwise it is a survey rather than a feature.
func TestAnswerChatPrompt_DeliveryPatchesThePost(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frprompt")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: prompt test", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"maybe"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&body))
	assert.Equal(t, true, body["applied"])

	var deliveryPossible int
	db.Raw("SELECT deliverypossible FROM messages WHERE id = ?", msgID).Scan(&deliveryPossible)
	assert.Equal(t, 1, deliveryPossible, "answering 'maybe' should mark the post as possible to deliver")

	var answer string
	db.Raw("SELECT answer FROM chat_prompts WHERE chatmsgid = ?", chatMsgID).Scan(&answer)
	assert.Equal(t, "maybe", answer)
}

func TestAnswerChatPrompt_DeadlineSetsADate(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptdl")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: deadline test", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	options := `[{"value":"week","label":"Within a week"},{"value":"norush","label":"There's no rush"}]`
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "deadline", options)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"week"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var deadline string
	db.Raw("SELECT COALESCE(deadline, '') FROM messages WHERE id = ?", msgID).Scan(&deadline)
	assert.NotEmpty(t, deadline, "answering with a timescale should set a deadline on the post")
}

// TestAnswerChatPrompt_OnlyTheMemberAskedMayAnswer: the answer edits somebody's
// post, so it must not be answerable by anyone who happens to know the id.

// TestAnswerChatPrompt_OnlyTheMemberAskedMayAnswer: the answer edits somebody's
// post, so it must not be answerable by anyone who happens to know the id.
func TestAnswerChatPrompt_OnlyTheMemberAskedMayAnswer(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptauth")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	strangerID := CreateTestUser(t, prefix+"_stranger", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: prompt auth", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, strangerID)

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"maybe"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.NotEqual(t, 200, resp.StatusCode)

	var deliveryPossible int
	db.Raw("SELECT deliverypossible FROM messages WHERE id = ?", msgID).Scan(&deliveryPossible)
	assert.Equal(t, 0, deliveryPossible, "a stranger must not be able to edit someone else's post")
}

func TestAnswerChatPrompt_RejectsAnAnswerThatWasNotOffered(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptbad")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: prompt bad answer", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"whatever"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestAnswerChatPrompt_CannotBeAnsweredTwice(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frprompttwice")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: prompt twice", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)
	url := fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token)

	first := httptest.NewRequest("POST", url, strings.NewReader(`{"answer":"maybe"}`))
	first.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(first, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	second := httptest.NewRequest("POST", url, strings.NewReader(`{"answer":"no"}`))
	second.Header.Set("Content-Type", "application/json")
	resp2, err := getApp().Test(second, -1)
	assert.NoError(t, err)
	assert.Equal(t, 409, resp2.StatusCode)

	var deliveryPossible int
	db.Raw("SELECT deliverypossible FROM messages WHERE id = ?", msgID).Scan(&deliveryPossible)
	assert.Equal(t, 1, deliveryPossible, "the second answer must not overwrite the first")
}

// TestFetchChatMessages_PromptIsServedWithItsOptions: without this the client has
// a message it can render but nothing to tap.

// TestFetchChatMessages_PromptIsServedWithItsOptions: without this the client has
// a message it can render but nothing to tap.
func TestFetchChatMessages_PromptIsServedWithItsOptions(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptfetch")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: prompt fetch", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), nil)
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body []map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&body))

	found := false
	for _, m := range body {
		idFloat, ok := m["id"].(float64)
		if !ok || uint64(idFloat) != chatMsgID {
			continue
		}
		found = true

		prompt, ok := m["prompt"].(map[string]interface{})
		assert.True(t, ok, "a Prompt message must carry its prompt payload")
		assert.Equal(t, "delivery", prompt["kind"])
		assert.Equal(t, false, prompt["answered"])

		options, ok := prompt["options"].([]interface{})
		assert.True(t, ok)
		assert.Len(t, options, 2)
	}

	assert.True(t, found, "the prompt message should be in the fetch")
}

// TestAnswerChatPrompt_AcceptsAPickedDate: the deadline prompt takes a date
// rather than fixed timescales, so the answer cannot be checked against a list.

// TestAnswerChatPrompt_AcceptsAPickedDate: the deadline prompt takes a date
// rather than fixed timescales, so the answer cannot be checked against a list.
func TestAnswerChatPrompt_AcceptsAPickedDate(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptpick")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: picked date", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	options := `[{"value":"date","label":"Pick a date","input":"date"},{"value":"norush","label":"There's no rush"}]`
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "deadline", options)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)
	want := time.Now().AddDate(0, 0, 9).Format("2006-01-02")

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"`+want+`"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var deadline string
	db.Raw("SELECT COALESCE(DATE_FORMAT(deadline, '%Y-%m-%d'), '') FROM messages WHERE id = ?", msgID).Scan(&deadline)
	assert.Equal(t, want, deadline, "the date the poster picked should be the deadline")
}

// A date in the past, or absurdly far ahead, is not a date the poster meant.

// A date in the past, or absurdly far ahead, is not a date the poster meant.
func TestAnswerChatPrompt_RejectsAnOutOfRangeDate(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptbaddate")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: bad date", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	options := `[{"value":"date","label":"Pick a date","input":"date"}]`
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "deadline", options)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)
	past := time.Now().AddDate(0, 0, -3).Format("2006-01-02")

	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"`+past+`"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

// The rollout split has to agree with the batch side's msgid % 100 bucketing, or
// a post would be in the trial for an emailed reply and out of it for an in-app
// one - which would make the arms meaningless.

// An expired question still renders, but it must not still be answerable.
//
// A week-old "could you deliver?" under an item that has long gone is not
// merely stale: answering it would edit a finished post. The message stays in
// the thread so the conversation reads back in order - a gap where a question
// was is more confusing than a question with no buttons - but the answer is
// refused.
func TestAnswerChatPrompt_RefusesAnExpiredPrompt(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptexpired")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: expired prompt", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	db.Exec("UPDATE chat_prompts SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE chatmsgid = ?", chatMsgID)

	_, token := CreateTestSession(t, posterID)
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"maybe"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 410, resp.StatusCode)

	var deliveryPossible int
	db.Raw("SELECT deliverypossible FROM messages WHERE id = ?", msgID).Scan(&deliveryPossible)
	assert.Equal(t, 0, deliveryPossible, "an expired prompt must not still edit the post")
}

// "There's no rush" is a real answer that changes nothing.
//
// It matters because it is not a refusal: it stops us asking again, so it has to
// be recorded, but there is no date to write and the post must be left exactly
// as it was. Conflating "answered" with "changed the post" is what makes the
// sysadmin answer-rate numbers lie.

// "There's no rush" is a real answer that changes nothing.
//
// It matters because it is not a refusal: it stops us asking again, so it has to
// be recorded, but there is no date to write and the post must be left exactly
// as it was. Conflating "answered" with "changed the post" is what makes the
// sysadmin answer-rate numbers lie.
func TestAnswerChatPrompt_NoRushRecordsWithoutPatchingThePost(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpromptnorush")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: no rush", 51.5, -0.1)

	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	options := `[{"value":"date","label":"Pick a date","input":"date"},{"value":"norush","label":"There's no rush"}]`
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "deadline", options)
	defer db.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	_, token := CreateTestSession(t, posterID)
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"norush"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&body))
	assert.Equal(t, false, body["applied"], "nothing was patched, and the panel must not claim otherwise")

	var deadline *string
	db.Raw("SELECT deadline FROM messages WHERE id = ?", msgID).Scan(&deadline)
	assert.Nil(t, deadline, "no rush is not a date")

	// But it IS recorded, so the question is not asked again.
	var answer string
	db.Raw("SELECT answer FROM chat_prompts WHERE chatmsgid = ?", chatMsgID).Scan(&answer)
	assert.Equal(t, "norush", answer)
}

// A bare end date means midnight, which silently drops everything that happened
// TODAY - the most interesting part of the window and the least likely to be
// questioned, because the panel still looks perfectly plausible. The handler
// widens a date-only bound to cover its whole day.
//
// This is a regression test for a real one: the dashboard's own date filter
// sends bare dates, and the average "hours earlier" read 5.6 instead of 3.7
// because the most recent passthroughs were being excluded.
