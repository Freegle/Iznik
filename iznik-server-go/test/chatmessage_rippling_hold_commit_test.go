package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/gorm"
)

// outOfReachReply posts an in-app reply to a post whose reach does not cover the replier, so that the reply is held
// by rippling. It returns the response status and the room, so that a test can look at what was written.
func outOfReachReply(t *testing.T, prefix string, beforeSend func(chatID uint64, replierID uint64)) (int, uint64, uint64) {
	t.Helper()
	db := database.DBConn

	// The same stand-in tables as the other reach tests: whichever test runs first creates them.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL, chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL, replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE, lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL,
		INDEX (msgid), INDEX (chatid), INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	replierID := CreateTestUser(t, prefix+"_replier", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, replierID, groupID, utils.ROLE_MEMBER)
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, replierID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: hold commit test item", 51.5, -0.1)
	polygon := "POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))"
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound) VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText('"+
		polygon+"', 3857)))", msgID, mustRasterize(t, polygon))
	t.Cleanup(func() {
		db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)
	})

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)
	stubReachEvalMax(t, "out")

	if beforeSend != nil {
		beforeSend(chatID, replierID)
	}

	var payload chat.ChatMessage
	payload.Message = "I'd like this please"
	payload.Refmsgid = &msgID
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)

	return resp.StatusCode, chatID, replierID
}

// chats:process-incoming takes every committed reply with processingrequired = 1, so a reply that rippling holds must
// commit together with its hold. The hold is written while the reply is still uncommitted, which a read from the pool
// (it sees only what is committed) cannot see.
func TestCreateChatMessage_RipplingHoldIsWrittenInTheRepliesTransaction(t *testing.T) {
	fired := false
	committedWhenHeld := int64(-1)

	status, chatID, replierID := outOfReachReply(t, uniquePrefix("holdtx"), func(cid uint64, rid uint64) {
		beforeStatement(t, "rippling_held_replies", false, func(tx *gorm.DB) {
			fired = true
			database.DBConn.Table("chat_messages").Where("chatid = ? AND userid = ?", cid, rid).Count(&committedWhenHeld)
		})
	})

	require.Equal(t, 200, status)
	require.True(t, fired, "an out-of-reach reply writes a rippling_held_replies row")
	assert.Equal(t, int64(0), committedWhenHeld,
		"the reply must not be committed before its hold is written, or a processing run in the gap delivers it to the poster")

	var held int64
	database.DBConn.Table("rippling_held_replies").Where("chatid = ? AND replieruserid = ?", chatID, replierID).Count(&held)
	assert.Equal(t, int64(1), held, "the hold is there once the reply is")
}

// A hold that cannot be written must not leave the reply behind as an ordinary one: the poster would be sent a reply
// that was meant to wait, with nothing recording that it should have.
func TestCreateChatMessage_ReplyIsNotKeptWhenItsRipplingHoldCannotBeWritten(t *testing.T) {
	status, chatID, replierID := outOfReachReply(t, uniquePrefix("holdfail"), func(cid uint64, rid uint64) {
		beforeStatement(t, "rippling_held_replies", false, func(tx *gorm.DB) {
			_ = tx.AddError(fmt.Errorf("injected: lock wait timeout exceeded"))
		})
	})

	assert.Equal(t, 500, status, "the member is told the reply did not go, and can send it again")

	var kept int64
	database.DBConn.Table("chat_messages").Where("chatid = ? AND userid = ?", chatID, replierID).Count(&kept)
	assert.Equal(t, int64(0), kept, "no reply is left that the poster could be sent without its hold")
}
