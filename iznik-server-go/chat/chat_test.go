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
	// ChatMessageQuery struct should map to correct table
	var cmq ChatMessageQuery
	assert.Equal(t, "chat_messages", cmq.TableName())
}

func TestChatRoomTableName(t *testing.T) {
	// ChatRoom struct should map to correct table
	var cr ChatRoom
	assert.Equal(t, "chat_rooms", cr.TableName())
}

func TestChatRosterEntryTableName(t *testing.T) {
	// ChatRosterEntry struct should map to correct table
	var cre ChatRosterEntry
	assert.Equal(t, "chat_roster", cre.TableName())
}

func TestCanSeeChatRoomSameUser(t *testing.T) {
	// User should be able to see their own chat room
	result := canSeeChatRoom(1, 1, 2, 1)
	assert.True(t, result)
}

func TestCanSeeChatRoomOtherUser(t *testing.T) {
	// User should not be able to see chat room they're not part of
	result := canSeeChatRoom(1, 2, 3, 1)
	assert.False(t, result)
}

func TestCanSeeChatRoomSecondUser(t *testing.T) {
	// Second user should be able to see chat room they're part of
	result := canSeeChatRoom(2, 1, 2, 1)
	assert.True(t, result)
}

func TestCanSeeChatRoomZeroUser(t *testing.T) {
	// User ID 0 (group chat) should always return true
	result := canSeeChatRoom(0, 1, 2, 1)
	assert.True(t, result)
}

func TestCheckHoldConflictNilMessage(t *testing.T) {
	// Nil message should return false
	result := checkHoldConflict(nil, 1)
	assert.False(t, result)
}

func TestCheckHoldConflictNoHold(t *testing.T) {
	// Message without hold should return false
	msg := &reviewMessage{
		HeldBy: uint64(0),
	}
	result := checkHoldConflict(msg, 1)
	assert.False(t, result)
}

func TestCheckHoldConflictDifferentUser(t *testing.T) {
	// Message held by different user should return true (conflict)
	msg := &reviewMessage{
		HeldBy: uint64(2),
	}
	result := checkHoldConflict(msg, 1)
	assert.True(t, result)
}

func TestCheckHoldConflictSameUser(t *testing.T) {
	// Message held by same user should return false (no conflict)
	msg := &reviewMessage{
		HeldBy: uint64(1),
	}
	result := checkHoldConflict(msg, 1)
	assert.False(t, result)
}

func TestChatRoomJSONMarshal(t *testing.T) {
	// Test ChatRoom marshals/unmarshals correctly
	cr := ChatRoom{
		ID:       1,
		Chattype: "User2User",
		User1:    2,
		User2:    3,
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
	// Test ChatMessage marshals/unmarshals correctly
	now := time.Now().Truncate(time.Second)
	msg := ChatMessage{
		ID:      1,
		Chatid:  2,
		Userid:  3,
		Type:    "Chat",
		Date:    now,
		Message: "Test message",
	}

	data, err := json.Marshal(msg)
	require.NoError(t, err)

	var msg2 ChatMessage
	err = json.Unmarshal(data, &msg2)
	require.NoError(t, err)
	assert.Equal(t, msg.ID, msg2.ID)
	assert.Equal(t, msg.Chatid, msg2.Chatid)
	assert.Equal(t, msg.Message, msg2.Message)
	assert.Equal(t, msg.Type, msg2.Type)
}

func TestChatMessageQueryJSONMarshal(t *testing.T) {
	// Test ChatMessageQuery marshals/unmarshals correctly
	now := time.Now().Truncate(time.Second)
	cmq := ChatMessageQuery{
		ChatMessage: ChatMessage{
			ID:      1,
			Chatid:  2,
			Userid:  3,
			Date:    now,
			Message: "Query message",
			Type:    "Chat",
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
	// Test ChatRosterEntry marshals/unmarshals correctly
	now := time.Now().Truncate(time.Second)
	cre := ChatRosterEntry{
		Id:     1,
		Chatid: 2,
		Userid: 3,
		Date:   &now,
	}

	data, err := json.Marshal(cre)
	require.NoError(t, err)

	var cre2 ChatRosterEntry
	err = json.Unmarshal(data, &cre2)
	require.NoError(t, err)
	assert.Equal(t, cre.Id, cre2.Id)
	assert.Equal(t, cre.Chatid, cre2.Chatid)
	assert.Equal(t, cre.Userid, cre2.Userid)
}

func TestFetchChatMessagesEmpty(t *testing.T) {
	// Fetching messages for non-existent chat should return empty slice
	messages := FetchChatMessages(999999999, 1, 10, 0, false, false)
	assert.NotNil(t, messages)
	assert.Len(t, messages, 0)
}

func TestUpdateMessageCountsEmpty(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	// Should not error on non-existent chat
	updateMessageCounts(db, 999999999)
	// If we got here without panicking, test passes
	assert.True(t, true)
}

func TestFetchReviewMessageNotFound(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	// Fetching non-existent message should return nil
	msg := fetchReviewMessage(db, 999999999)
	assert.Nil(t, msg)
}

func TestChatRoomListEntryStatusValues(t *testing.T) {
	// Test common status values on ChatRoomListEntry (which has a Status field)
	cr := ChatRoomListEntry{
		Status: "ACTIVE",
	}
	assert.Equal(t, "ACTIVE", cr.Status)

	cr.Status = "LEFT"
	assert.Equal(t, "LEFT", cr.Status)

	cr.Status = "BLOCKED"
	assert.Equal(t, "BLOCKED", cr.Status)
}

func TestChatRosterEntryStatusValues(t *testing.T) {
	// Test common status values on ChatRosterEntry (which has a Status field)
	cre := ChatRosterEntry{
		Status: "ONLINE",
	}
	assert.Equal(t, "ONLINE", cre.Status)

	cre.Status = "OFFLINE"
	assert.Equal(t, "OFFLINE", cre.Status)
}

func TestChatMessageReviewFlags(t *testing.T) {
	// Test review flags on ChatMessage
	msg := ChatMessage{
		Reviewrequired: true,
		Reviewrejected: false,
	}
	assert.True(t, msg.Reviewrequired)
	assert.False(t, msg.Reviewrejected)

	msg.Reviewrejected = true
	assert.True(t, msg.Reviewrejected)
}
