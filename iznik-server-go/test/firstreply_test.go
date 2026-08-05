package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/stretchr/testify/assert"
)

// Tick 1 is the reach the post has now; tick 3 is where it ends up. A point in
// tick 3 but not tick 1 is somebody the current rules make wait days.
const frTick1 = "POLYGON((-0.15 51.45, -0.05 51.45, -0.05 51.55, -0.15 51.55, -0.15 51.45))"
const frTick3 = "POLYGON((-1.0 51.0, 1.0 51.0, 1.0 52.0, -1.0 52.0, -1.0 51.0))"

// ensureFirstReplyTables creates the tables the first-reply work adds, for test
// databases loaded from schema rather than migrated.
func ensureFirstReplyTables(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS chat_prompts (
		chatmsgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		msgid BIGINT UNSIGNED NULL,
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
func seedRipplingReach(t *testing.T, msgID uint64, withMax bool) {
	db := database.DBConn

	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	res := db.Exec("INSERT INTO rippling_reach "+
		"(msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, total_freeglers, "+
		" max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "+
		"VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), "+
		" NOW(), 'drive', 1, 3, 4000, 30, NULL, NOW(), 'expanding', NOW(), NOW())",
		msgID, frTick1, frTick1)
	if res.Error != nil {
		t.Fatalf("could not seed reach: %v", res.Error)
	}

	if withMax {
		db.Exec("UPDATE rippling_reach SET max_polygon = ST_GeomFromText(?, 3857), "+
			"max_cumulative_users = 4000 WHERE msgid = ?", frTick3, msgID)
	}
}

// TestFirstReplyPassthrough_FirstReplyInsideEventualReach: the whole point of the
// feature. Somebody the ripple has not got to yet, but will, replies to a post
// that has nothing. Holding them would delay the poster's only reply to protect
// an ordering that replier crosses in a few days anyway.
func TestFirstReplyPassthrough_FirstReplyInsideEventualReach(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough test", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	// (lng, lat) inside tick 3 only.
	assert.True(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))
}

func TestFirstReplyPassthrough_SecondReplyIsStillHeld(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass2")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough second", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, type, refmsgid, date, "+
		"reviewrequired, processingrequired, processingsuccessful) "+
		"VALUES (?, ?, 'I would like this', 'Interested', ?, NOW(), 0, 0, 1)",
		chatID, replierID, msgID)
	defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)

	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9),
		"once a post has a reply, local-first ordering is worth the delay again")
}

func TestFirstReplyPassthrough_OutsideEventualReachIsHeld(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass3")

	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough far", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	seedRipplingReach(t, msgID, true)

	// Aberdeen: the post never gets there.
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, -2.1, 57.1))
}

func TestFirstReplyPassthrough_DisabledAndUnpopulatedBothHold(t *testing.T) {
	ensureFirstReplyTables(t)
	db := database.DBConn
	prefix := uniquePrefix("frpass4")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: passthrough off", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	// Switched off: unchanged behaviour.
	t.Setenv("FIRSTREPLY_ENABLED", "false")
	seedRipplingReach(t, msgID, true)
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))

	// Switched on but the eventual reach has not been computed yet - the state
	// every row is in until the backfill drains. Must also be unchanged.
	t.Setenv("FIRSTREPLY_ENABLED", "true")
	t.Setenv("FIRSTREPLY_PASSTHROUGH_ENABLED", "true")
	seedRipplingReach(t, msgID, false)
	assert.False(t, firstreply.ShouldPassThrough(db, msgID, 0.8, 51.9))
}

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

	db.Exec("INSERT INTO chat_prompts (chatmsgid, msgid, kind, options, expires_at, created_at) "+
		"VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())",
		chatMsgID, msgID, kind, options)

	return chatMsgID
}

const deliveryOptions = `[{"value":"maybe","label":"Maybe, if it works for me"},{"value":"no","label":"Collection only"}]`

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
