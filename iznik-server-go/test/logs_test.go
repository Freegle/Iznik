package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/stretchr/testify/assert"
)

func TestGetLogsMessages(t *testing.T) {
	prefix := uniquePrefix("LogsMsg")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Owner")
	_, token := CreateTestSession(t, userID)

	// Create a log entry.
	db := database.DBConn
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'test log')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED, groupID, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?logtype=messages&groupid=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "logs")
	assert.Contains(t, result, "context")
}

func TestGetLogsMemberships(t *testing.T) {
	prefix := uniquePrefix("LogsMem")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Owner")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'test join')",
		flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED, groupID, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?logtype=memberships&groupid=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestGetLogsNotModerator(t *testing.T) {
	prefix := uniquePrefix("LogsNoMod")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?logtype=messages&groupid=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(2), result["ret"])
}

func TestGetLogsNotLoggedIn(t *testing.T) {
	req := httptest.NewRequest("GET", "/api/modtools/logs?logtype=messages&groupid=1", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(2), result["ret"])
}

func TestGetLogsPagination(t *testing.T) {
	prefix := uniquePrefix("LogsPag")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Owner")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	for i := 0; i < 5; i++ {
		db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), ?)",
			flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED, groupID, userID, fmt.Sprintf("page test %d", i))
	}

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?logtype=messages&groupid=%d&limit=2&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	logs := result["logs"].([]interface{})
	assert.LessOrEqual(t, len(logs), 2)

	ctx := result["context"].(map[string]interface{})
	assert.Contains(t, ctx, "id")
}

func TestGetLogsV2Path(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/modtools/logs", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestGetLogsModmailsonly(t *testing.T) {
	// Verify that modmailsonly=true filters to only modmail-related logs.
	// V1 includes: Message (Rejected, Deleted, Replied) and User (Mailed, Rejected, Deleted).
	prefix := uniquePrefix("LogsModmail")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, modID, groupID, "Owner")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, modID)

	db := database.DBConn

	// 1. Message/Rejected (SHOULD be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'rejected')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REJECTED, groupID, userID)

	// 2. Message/Deleted (SHOULD be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'deleted')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, groupID, userID)

	// 3. Message/Replied (SHOULD be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'replied')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPLIED, groupID, userID)

	// 4. User/Mailed (SHOULD be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'mailed')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_MAILED, groupID, userID)

	// 5. User/Rejected (SHOULD be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'user rejected')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_REJECTED, groupID, userID)

	// 6. User/Deleted (SHOULD be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'user deleted')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_DELETED, groupID, userID)

	// 7. Message/Received (SHOULD NOT be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'received')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED, groupID, userID)

	// 8. Message/Approved (SHOULD NOT be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'approved')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_APPROVED, groupID, userID)

	// 9. Group/Joined (SHOULD NOT be included)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'joined')",
		flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED, groupID, userID)

	// Query with modmailsonly=true
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?groupid=%d&modmailsonly=true&limit=100&jwt=%s",
		groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	logs, ok := result["logs"].([]interface{})
	assert.True(t, ok, "logs should be an array")

	// Collect the type/subtype pairs returned.
	type logKey struct {
		Type    string
		Subtype string
	}
	found := map[logKey]bool{}
	for _, entry := range logs {
		e := entry.(map[string]interface{})
		logType, _ := e["type"].(string)
		logSubtype := ""
		if s, ok := e["subtype"].(string); ok {
			logSubtype = s
		}
		found[logKey{logType, logSubtype}] = true
	}

	// Count of included logs should be exactly 6 (the modmail-related ones).
	assert.Equal(t, 6, len(found), "modmailsonly should return exactly 6 modmail logs")

	// Verify the 6 included logs
	assert.True(t, found[logKey{flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REJECTED}],
		"Message/Rejected should be included")
	assert.True(t, found[logKey{flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED}],
		"Message/Deleted should be included")
	assert.True(t, found[logKey{flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPLIED}],
		"Message/Replied should be included")
	assert.True(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_MAILED}],
		"User/Mailed should be included")
	assert.True(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_REJECTED}],
		"User/Rejected should be included")
	assert.True(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_DELETED}],
		"User/Deleted should be included")

	// Verify the non-modmail logs are excluded
	assert.False(t, found[logKey{flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED}],
		"Message/Received should NOT be included")
	assert.False(t, found[logKey{flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_APPROVED}],
		"Message/Approved should NOT be included")
	assert.False(t, found[logKey{flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED}],
		"Group/Joined should NOT be included")
}

func TestGetLogsUserReturnsAllTypes(t *testing.T) {
	// Verify that logtype=user returns logs of ALL types (not just Message/User),
	// but excludes User/Created and User/Merged subtypes.
	prefix := uniquePrefix("LogsUserAll")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	targetUserID := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, modID, groupID, "Owner")
	CreateTestMembership(t, targetUserID, groupID, "Member")
	_, token := CreateTestSession(t, modID)

	db := database.DBConn

	// 1. Group/Joined log — previously excluded by the type filter bug.
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'joined group')",
		flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED, groupID, targetUserID)

	// 2. Message/Received log — always included.
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'received msg')",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED, groupID, targetUserID)

	// 3. User/Created log — should be EXCLUDED by the fix.
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'user created')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_CREATED, groupID, targetUserID)

	// 4. User/Merged log — should be EXCLUDED by the fix.
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'user merged')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_MERGED, groupID, targetUserID)

	// 5. Config/Edit log (byuser) — should be included via the byuser match.
	db.Exec("INSERT INTO logs (type, subtype, groupid, byuser, timestamp, text) VALUES (?, ?, ?, ?, NOW(), 'config edited')",
		flog.LOG_TYPE_CONFIG, flog.LOG_SUBTYPE_EDIT, groupID, targetUserID)

	// 6. User/Suspect log — flagged as spam (Discourse #293: must be visible).
	db.Exec("INSERT INTO logs (type, subtype, user, timestamp, text) VALUES (?, ?, ?, NOW(), 'possible spammer')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_SUSPECT, targetUserID)

	// 7. Group/Left log with byuser (removal by mod — Discourse #293: must show as "Removed").
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, byuser, timestamp, text) VALUES (?, ?, ?, ?, ?, NOW(), 'removed by mod')",
		flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_LEFT, groupID, targetUserID, modID)

	// 8. User/Deleted log — user removed from platform.
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, byuser, timestamp, text) VALUES (?, ?, ?, ?, ?, NOW(), 'removed member')",
		flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_DELETED, groupID, targetUserID, modID)

	// Query with logtype=user for the target user (no groupid — matches frontend behavior).
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?logtype=user&userid=%d&limit=100&jwt=%s",
		targetUserID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	logs, ok := result["logs"].([]interface{})
	assert.True(t, ok, "logs should be an array")

	// Collect the type/subtype pairs returned.
	type logKey struct {
		Type    string
		Subtype string
	}
	found := map[logKey]bool{}
	for _, entry := range logs {
		e := entry.(map[string]interface{})
		logType, _ := e["type"].(string)
		logSubtype := ""
		if s, ok := e["subtype"].(string); ok {
			logSubtype = s
		}
		found[logKey{logType, logSubtype}] = true
	}

	// Group/Joined MUST be returned (this was the bug — it was previously filtered out).
	assert.True(t, found[logKey{flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED}],
		"Group/Joined log should be returned for logtype=user")

	// Message/Received MUST be returned.
	assert.True(t, found[logKey{flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED}],
		"Message/Received log should be returned for logtype=user")

	// Config/Edit MUST be returned (matched via byuser).
	assert.True(t, found[logKey{flog.LOG_TYPE_CONFIG, flog.LOG_SUBTYPE_EDIT}],
		"Config/Edit log should be returned for logtype=user")

	// User/Created MUST NOT be returned (excluded by the fix).
	assert.False(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_CREATED}],
		"User/Created log should NOT be returned for logtype=user")

	// User/Merged MUST NOT be returned (excluded by the fix).
	assert.False(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_MERGED}],
		"User/Merged log should NOT be returned for logtype=user")

	// User/Suspect MUST be returned (Discourse #293: "flagged" entries missing).
	assert.True(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_SUSPECT}],
		"User/Suspect log should be returned for logtype=user")

	// Group/Left MUST be returned (Discourse #293: "removed" entries missing).
	assert.True(t, found[logKey{flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_LEFT}],
		"Group/Left log should be returned for logtype=user")

	// User/Deleted MUST be returned.
	assert.True(t, found[logKey{flog.LOG_TYPE_USER, flog.LOG_SUBTYPE_DELETED}],
		"User/Deleted log should be returned for logtype=user")
}

func TestGetLogsModmailTextIsEmailSubject(t *testing.T) {
	// Verify that modmail logs (Message/Replied) return log.text containing the email subject
	// as constructed from the stdmsg (subjpref + ': ' + post subject + subjsuff).
	// The V2 path stores this in logs.text via the background task; log.text is what ModTools
	// displays to show what was actually sent in the modmail.
	prefix := uniquePrefix("LogsModmailText")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, modID, groupID, "Owner")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, modID)

	db := database.DBConn

	// Create a test message (simulating a post like "add a photo")
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, arrival, date, source) VALUES (?, 'Wanted', 'add a photo', 'Please add a photo', NOW(), NOW(), 'Platform')",
		userID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)

	// The stdmsg-constructed email subject: subjpref + ': ' + post_subject
	emailSubject := "Pending: add a photo"

	// Create a modmail log with the email subject in log.text (as the V2 batch processor does)
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, msgid, byuser, timestamp, text) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPLIED, groupID, userID, msgID, modID, emailSubject)

	// Fetch logs and verify the email subject is returned in log.text
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?groupid=%d&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result2 map[string]interface{}
	json2.Unmarshal(rsp(resp), &result2)
	assert.Equal(t, float64(0), result2["ret"])

	logs, ok := result2["logs"].([]interface{})
	assert.True(t, ok, "logs should be an array")
	assert.Greater(t, len(logs), 0, "should have at least one log")

	logEntry := logs[0].(map[string]interface{})
	assert.Equal(t, "Message", logEntry["type"])
	assert.Equal(t, "Replied", logEntry["subtype"])

	// log.text must contain the stdmsg-constructed email subject, not just "Re: [post subject]"
	assert.Equal(t, emailSubject, logEntry["text"], "log.text should contain the stdmsg-constructed email subject")

	// msgsubject must be the message's own subject (from the messages JOIN), distinct from log.text
	// which holds the modmail email subject
	assert.Equal(t, "add a photo", logEntry["msgsubject"], "msgsubject should be the message's own subject")
}

func TestGetLogsMsgsubjectHistoricalIsPreserved(t *testing.T) {
	// Regression test for Discourse topic #9518 post 215:
	// The message log was showing the edited item name retroactively for events that
	// occurred before the edit.  After this fix, each log event stores and returns the
	// message subject as it was at the time of that event (logs.msgsubject), taking
	// precedence over the current (possibly edited) messages.subject.
	prefix := uniquePrefix("LogsMsgS")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Owner")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn

	// Check the msgsubject column exists (requires the 2026_04_28_000001 migration).
	var hasColumn int64
	db.Raw("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'logs' AND column_name = 'msgsubject'").Scan(&hasColumn)
	if hasColumn == 0 {
		t.Skip("msgsubject column not in schema; run: php artisan migrate")
	}

	// Create a message with the original subject.
	db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, arrival, date, source) VALUES (?, 'Offer', 'Offer: Original Item', 'body', NOW(), NOW(), 'Platform')", userID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", userID).Scan(&msgID)

	// Insert a Received log entry that stores the original subject at the time of the event.
	db.Exec("INSERT INTO logs (type, subtype, groupid, user, msgid, timestamp, msgsubject) VALUES (?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), ?)",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED, groupID, userID, msgID, "Offer: Original Item")

	// Simulate the message being edited to a new name after the Received event.
	db.Exec("UPDATE messages SET subject = ? WHERE id = ?", "Offer: Edited Item", msgID)

	// Fetch the message log.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/logs?logtype=messages&groupid=%d&limit=10&jwt=%s", groupID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	logs, ok := result["logs"].([]interface{})
	assert.True(t, ok, "logs should be an array")
	assert.Greater(t, len(logs), 0, "should have at least one log entry")

	// Find the Received log entry for our message.
	var logEntry map[string]interface{}
	for _, l := range logs {
		e := l.(map[string]interface{})
		if subtype, ok := e["subtype"].(string); ok && subtype == flog.LOG_SUBTYPE_RECEIVED {
			if mid, ok := e["msgid"].(float64); ok && uint64(mid) == msgID {
				logEntry = e
				break
			}
		}
	}
	assert.NotNil(t, logEntry, "should find the Received log entry for the test message")

	// The stored historical msgsubject must take precedence over the current (edited) subject.
	assert.Equal(t, "Offer: Original Item", logEntry["msgsubject"],
		"log event should show the subject as it was at the time of the event, not the current edited subject")
}
