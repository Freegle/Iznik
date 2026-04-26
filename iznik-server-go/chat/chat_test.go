package chat

import (
	"encoding/json"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/freegle/iznik-server-go/database"
)

func init() {
	database.InitDatabase()
}

func TestChatMessageQueryTableName(t *testing.T) {
	var cmq ChatMessageQuery
	assert.Equal(t, "chat_messages", cmq.TableName())
}

func TestChatRoomTableName(t *testing.T) {
	var cr ChatRoom
	assert.Equal(t, "chat_rooms", cr.TableName())
}

func TestChatRosterEntryTableName(t *testing.T) {
	var cre ChatRosterEntry
	assert.Equal(t, "chat_roster", cre.TableName())
}

func TestCanSeeChatRoomSameUser(t *testing.T) {
	result := canSeeChatRoom(1, 1, 2, 1)
	assert.True(t, result)
}

func TestCanSeeChatRoomOtherUser(t *testing.T) {
	result := canSeeChatRoom(1, 2, 3, 1)
	assert.False(t, result)
}

func TestCanSeeChatRoomSecondUser(t *testing.T) {
	result := canSeeChatRoom(2, 1, 2, 1)
	assert.True(t, result)
}

func TestCanSeeChatRoomZeroUser(t *testing.T) {
	// User ID 0 is not a participant and has no mod role, so returns false
	result := canSeeChatRoom(0, 1, 2, 1)
	assert.False(t, result)
}

func TestCheckHoldConflictNilMessage(t *testing.T) {
	result := checkHoldConflict(nil, 1)
	assert.False(t, result)
}

func TestCheckHoldConflictNoHold(t *testing.T) {
	msg := &reviewMessage{
		HeldBy: uint64(0),
	}
	result := checkHoldConflict(msg, 1)
	assert.False(t, result)
}

func TestCheckHoldConflictDifferentUser(t *testing.T) {
	msg := &reviewMessage{
		HeldBy: uint64(2),
	}
	result := checkHoldConflict(msg, 1)
	assert.True(t, result)
}

func TestCheckHoldConflictSameUser(t *testing.T) {
	msg := &reviewMessage{
		HeldBy: uint64(1),
	}
	result := checkHoldConflict(msg, 1)
	assert.False(t, result)
}

func TestChatRoomJSONMarshal(t *testing.T) {
	cr := ChatRoom{
		ID:       1,
		User1:    2,
		User2:    3,
		Chattype: "User2User",
	}

	data, err := json.Marshal(cr)
	require.NoError(t, err)

	var cr2 ChatRoom
	err = json.Unmarshal(data, &cr2)
	require.NoError(t, err)
	assert.Equal(t, cr.ID, cr2.ID)
	assert.Equal(t, cr.User1, cr2.User1)
	assert.Equal(t, cr.User2, cr2.User2)
	assert.Equal(t, cr.Chattype, cr2.Chattype)
}

func TestChatMessageJSONMarshal(t *testing.T) {
	now := time.Now()
	msg := ChatMessage{
		ID:      1,
		Chatid:  2,
		Userid:  3,
		Message: "Test message",
		Date:    now,
	}

	data, err := json.Marshal(msg)
	require.NoError(t, err)

	var msg2 ChatMessage
	err = json.Unmarshal(data, &msg2)
	require.NoError(t, err)
	assert.Equal(t, msg.ID, msg2.ID)
	assert.Equal(t, msg.Chatid, msg2.Chatid)
	assert.Equal(t, msg.Message, msg2.Message)
}

func TestChatMessageQueryJSONMarshal(t *testing.T) {
	now := time.Now()
	cmq := ChatMessageQuery{
		ChatMessage: ChatMessage{
			ID:      1,
			Chatid:  2,
			Userid:  3,
			Message: "Query message",
			Date:    now,
		},
	}

	data, err := json.Marshal(cmq)
	require.NoError(t, err)

	var cmq2 ChatMessageQuery
	err = json.Unmarshal(data, &cmq2)
	require.NoError(t, err)
	assert.Equal(t, cmq.ID, cmq2.ID)
	assert.Equal(t, cmq.Chatid, cmq2.Chatid)
	assert.Equal(t, cmq.Message, cmq2.Message)
}

func TestChatRosterEntryJSONMarshal(t *testing.T) {
	now := time.Now()
	cre := ChatRosterEntry{
		Id:     1,
		Chatid: 2,
		Userid: 3,
		Date:   &now,
		Status: "online",
	}

	data, err := json.Marshal(cre)
	require.NoError(t, err)

	var cre2 ChatRosterEntry
	err = json.Unmarshal(data, &cre2)
	require.NoError(t, err)
	assert.Equal(t, cre.Id, cre2.Id)
	assert.Equal(t, cre.Chatid, cre2.Chatid)
	assert.Equal(t, cre.Userid, cre2.Userid)
	assert.Equal(t, cre.Status, cre2.Status)
}

func TestFetchChatMessagesEmpty(t *testing.T) {
	messages := FetchChatMessages(999999999, 1, 10, 0, false, false)
	assert.NotNil(t, messages)
	assert.Len(t, messages, 0)
}

func TestUpdateMessageCountsEmpty(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	updateMessageCounts(db, 999999999)
	assert.True(t, true)
}

func TestFetchReviewMessageNotFound(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	msg := fetchReviewMessage(db, 999999999)
	assert.Nil(t, msg)
}

func TestChatRoomStatusValues(t *testing.T) {
	cr := ChatRoom{
		Chattype: "User2User",
	}
	assert.Equal(t, "User2User", cr.Chattype)

	cr.Chattype = "User2Mod"
	assert.Equal(t, "User2Mod", cr.Chattype)
}

func TestChatMessageReviewFlags(t *testing.T) {
	msg := ChatMessage{
		Reviewrequired: true,
		Reviewrejected: false,
	}
	assert.True(t, msg.Reviewrequired)
	assert.False(t, msg.Reviewrejected)
}
