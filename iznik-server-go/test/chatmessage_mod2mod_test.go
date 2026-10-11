package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A Mod2Mod room has user1 and user2 NULL and belongs to its group. Existence is the row, not
// the participants, and access is the group's active moderators.
func mod2modFixture(t *testing.T, prefix string) (groupID, chatID, modID, backupID, memberID uint64) {
	db := database.DBConn
	groupID = CreateTestGroup(t, prefix)
	modID = CreateTestUser(t, prefix+"_mod", "User")
	backupID = CreateTestUser(t, prefix+"_backup", "User")
	memberID = CreateTestUser(t, prefix+"_member", "User")

	CreateTestMembership(t, modID, groupID, "Owner")
	db.Exec("UPDATE memberships SET settings = '{\"active\":1}' WHERE userid = ? AND groupid = ?", modID, groupID)
	CreateTestMembership(t, backupID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET settings = '{\"active\":0}' WHERE userid = ? AND groupid = ?", backupID, groupID)
	CreateTestMembership(t, memberID, groupID, "Member")

	chatID = CreateTestChatRoom(t, 0, nil, &groupID, "Mod2Mod")
	CreateTestChatMessage(t, chatID, modID, "Hello volunteers")
	return
}

func getMod2ModMessages(t *testing.T, chatID, userID uint64) *http.Response {
	_, token := CreateTestSession(t, userID)
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/chat/%d/message?jwt=%s&modtools=true", chatID, token), nil)
	resp, _ := getApp().Test(req, -1)
	return resp
}

func postMod2ModMessage(t *testing.T, chatID, userID uint64, text string) *http.Response {
	_, token := CreateTestSession(t, userID)
	body, _ := json.Marshal(map[string]interface{}{"message": text})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, -1)
	return resp
}

func TestGetChatMessagesMod2ModActiveModerator(t *testing.T) {
	_, chatID, modID, _, _ := mod2modFixture(t, uniquePrefix("m2mget"))

	resp := getMod2ModMessages(t, chatID, modID)
	assert.Equal(t, http.StatusOK, resp.StatusCode)

	var messages []map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&messages)
	assert.Equal(t, 1, len(messages))
}

func TestGetChatMessagesMod2ModNonModerator(t *testing.T) {
	_, chatID, _, _, memberID := mod2modFixture(t, uniquePrefix("m2mgetnm"))

	assert.Equal(t, http.StatusNotFound, getMod2ModMessages(t, chatID, memberID).StatusCode)
}

func TestGetChatMessagesMod2ModBackupModerator(t *testing.T) {
	// listChats leaves a backup moderator (settings active:0) out of Mod2Mod chats; the
	// message fetch must agree.
	_, chatID, _, backupID, _ := mod2modFixture(t, uniquePrefix("m2mgetbk"))

	assert.Equal(t, http.StatusNotFound, getMod2ModMessages(t, chatID, backupID).StatusCode)
}

func TestGetChatMessagesMissingRoom(t *testing.T) {
	modID := CreateTestUser(t, uniquePrefix("m2mnoroom"), "User")
	assert.Equal(t, http.StatusNotFound, getMod2ModMessages(t, 999999999, modID).StatusCode)
}

func TestPostChatMessageMod2ModActiveModerator(t *testing.T) {
	db := database.DBConn
	_, chatID, modID, _, _ := mod2modFixture(t, uniquePrefix("m2mpost"))

	resp := postMod2ModMessage(t, chatID, modID, "Anyone free on Saturday?")
	assert.Equal(t, http.StatusOK, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ? AND message = ?", chatID, "Anyone free on Saturday?").Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestPostChatMessageMod2ModNonModerator(t *testing.T) {
	db := database.DBConn
	_, chatID, _, _, memberID := mod2modFixture(t, uniquePrefix("m2mpostnm"))

	assert.Equal(t, http.StatusNotFound, postMod2ModMessage(t, chatID, memberID, "Let me in").StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ? AND message = ?", chatID, "Let me in").Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestPostChatMessageMod2ModBackupModerator(t *testing.T) {
	_, chatID, _, backupID, _ := mod2modFixture(t, uniquePrefix("m2mpostbk"))

	assert.Equal(t, http.StatusNotFound, postMod2ModMessage(t, chatID, backupID, "Backup here").StatusCode)
}
