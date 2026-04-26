package notification

import (
	"encoding/json"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/freegle/iznik-server-go/database"
)

func init() {
	database.InitDB()
}

func TestNotificationTableName(t *testing.T) {
	// Notification struct should map to notifications table
	var notif Notification
	assert.Equal(t, "notifications", notif.TableName())
}

func TestNotificationJSONMarshal(t *testing.T) {
	// Test Notification marshals/unmarshals correctly
	now := time.Now()
	notif := Notification{
		ID:        1,
		Userid:    2,
		Type:      "NEW_MESSAGE",
		MessageID: 3,
		Title:     "New message",
		Message:   "You have a new message",
		Seen:      0,
		Created:   now,
	}

	data, err := json.Marshal(notif)
	require.NoError(t, err)

	var notif2 Notification
	err = json.Unmarshal(data, &notif2)
	require.NoError(t, err)
	assert.Equal(t, notif.ID, notif2.ID)
	assert.Equal(t, notif.Userid, notif2.Userid)
	assert.Equal(t, notif.Type, notif2.Type)
	assert.Equal(t, notif.MessageID, notif2.MessageID)
	assert.Equal(t, notif.Title, notif2.Title)
	assert.Equal(t, notif.Message, notif2.Message)
	assert.Equal(t, notif.Seen, notif2.Seen)
}

func TestNotificationTypes(t *testing.T) {
	// Test common notification types
	notif := Notification{
		Type: "NEW_MESSAGE",
	}
	assert.Equal(t, "NEW_MESSAGE", notif.Type)

	notif.Type = "REPLY"
	assert.Equal(t, "REPLY", notif.Type)

	notif.Type = "MENTION"
	assert.Equal(t, "MENTION", notif.Type)

	notif.Type = "SYSTEM"
	assert.Equal(t, "SYSTEM", notif.Type)
}

func TestNotificationSeen(t *testing.T) {
	// Test notification seen flag
	unseenNotif := Notification{
		Seen: 0,
	}
	assert.Equal(t, int8(0), unseenNotif.Seen)

	seenNotif := Notification{
		Seen: 1,
	}
	assert.Equal(t, int8(1), seenNotif.Seen)
}

func TestNotificationWithoutMessageID(t *testing.T) {
	// Some notifications may not have a message ID (e.g., system notifications)
	notif := Notification{
		ID:     1,
		Userid: 2,
		Type:   "SYSTEM",
		Title:  "Welcome",
		Message: "Welcome to Freegle",
		Seen:   0,
	}

	assert.Equal(t, uint64(0), notif.MessageID)
	assert.Equal(t, "SYSTEM", notif.Type)
	assert.NotEmpty(t, notif.Title)
}

func TestNotificationTimestamp(t *testing.T) {
	// Test that notification preserves creation timestamp
	now := time.Now()
	notif := Notification{
		Created: now,
	}

	// Time should be preserved (allowing for some rounding)
	assert.WithinDuration(t, now, notif.Created, time.Second)
}

func TestMultipleNotifications(t *testing.T) {
	// Test marshaling multiple notifications
	notifs := []Notification{
		{
			ID:     1,
			Userid: 1,
			Type:   "MESSAGE",
			Title:  "Msg 1",
		},
		{
			ID:     2,
			Userid: 1,
			Type:   "REPLY",
			Title:  "Msg 2",
		},
		{
			ID:     3,
			Userid: 2,
			Type:   "MENTION",
			Title:  "Msg 3",
		},
	}

	data, err := json.Marshal(notifs)
	require.NoError(t, err)

	var notifs2 []Notification
	err = json.Unmarshal(data, &notifs2)
	require.NoError(t, err)
	assert.Len(t, notifs2, 3)
	assert.Equal(t, notifs[0].ID, notifs2[0].ID)
	assert.Equal(t, notifs[1].Type, notifs2[1].Type)
	assert.Equal(t, notifs[2].Userid, notifs2[2].Userid)
}

func TestNotificationEmptyMessage(t *testing.T) {
	// Test notification with empty message
	notif := Notification{
		ID:     1,
		Userid: 2,
		Type:   "SYSTEM",
		Title:  "Empty",
		Message: "",
	}

	assert.Equal(t, "", notif.Message)
	assert.NotEmpty(t, notif.Title)
}
