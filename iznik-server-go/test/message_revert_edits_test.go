package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Reverting puts the post back as it was before the pending edits: the oldest pending edit's
// old values win, and an edit that left a field alone does not hide the earlier one's.
func TestRevertEditsRestoresStateBeforeTheFirstPendingEdit(t *testing.T) {
	prefix := uniquePrefix("revert_two")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, groupID, prefix+" first subject", 52.5, -1.8)
	db.Exec("UPDATE messages SET textbody = 'first text' WHERE id = ?", msgID)

	// First edit changes the subject. Second edit changes only the text.
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldsubject, newsubject, reviewrequired) VALUES (?, ?, ?, ?, 1)",
		msgID, posterID, prefix+" first subject", prefix+" second subject")
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtext, newtext, reviewrequired) VALUES (?, ?, 'first text', 'second text', 1)",
		msgID, posterID)
	db.Exec("UPDATE messages SET subject = ?, textbody = 'second text', editedby = ? WHERE id = ?",
		prefix+" second subject", posterID, msgID)

	status, body := postModAction(t, token, msgID, "RevertEdits", groupID)
	require.Equal(t, 200, status, body)

	var subject, text string
	db.Raw("SELECT subject FROM messages WHERE id = ?", msgID).Scan(&subject)
	db.Raw("SELECT COALESCE(textbody, '') FROM messages WHERE id = ?", msgID).Scan(&text)
	assert.Equal(t, prefix+" first subject", subject, "the subject goes back to what it was before the first edit")
	assert.Equal(t, "first text", text)
}

func TestRevertEditsRestoresTheType(t *testing.T) {
	prefix := uniquePrefix("revert_type")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, groupID, prefix+" offer", 52.5, -1.8)
	db.Exec("INSERT INTO messages_edits (msgid, byuser, oldtype, newtype, reviewrequired) VALUES (?, ?, 'Offer', 'Wanted', 1)",
		msgID, posterID)
	db.Exec("UPDATE messages SET type = 'Wanted', editedby = ? WHERE id = ?", posterID, msgID)
	db.Exec("UPDATE messages_groups SET msgtype = 'Wanted' WHERE msgid = ?", msgID)
	db.Exec("UPDATE messages_spatial SET msgtype = 'Wanted' WHERE msgid = ?", msgID)

	status, body := postModAction(t, token, msgID, "RevertEdits", groupID)
	require.Equal(t, 200, status, body)

	var msgType, groupType, spatialType string
	db.Raw("SELECT type FROM messages WHERE id = ?", msgID).Scan(&msgType)
	db.Raw("SELECT msgtype FROM messages_groups WHERE msgid = ?", msgID).Scan(&groupType)
	db.Raw("SELECT msgtype FROM messages_spatial WHERE msgid = ?", msgID).Scan(&spatialType)
	assert.Equal(t, "Offer", msgType, "the type goes back")
	assert.Equal(t, "Offer", groupType)
	assert.Equal(t, "Offer", spatialType)
}
