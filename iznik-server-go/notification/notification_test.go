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
	database.InitDatabase()
}

func TestNotificationJSONMarshal(t *testing.T) {
	now := time.Now()
	notif := Notification{
		ID:        1,
		Touser:    2,
		Type:      "NEW_MESSAGE",
		Title:     "New message",
		Text:      "You have a new message",
		Seen:      false,
		Timestamp: now,
	}

	data, err := json.Marshal(notif)
	require.NoError(t, err)

	var notif2 Notification
	err = json.Unmarshal(data, &notif2)
	require.NoError(t, err)
	assert.Equal(t, notif.ID, notif2.ID)
	assert.Equal(t, notif.Touser, notif2.Touser)
	assert.Equal(t, notif.Type, notif2.Type)
	assert.Equal(t, notif.Title, notif2.Title)
	assert.Equal(t, notif.Text, notif2.Text)
	assert.Equal(t, notif.Seen, notif2.Seen)
}

func TestNotificationTypes(t *testing.T) {
	notif := Notification{
		Type: "NEW_MESSAGE",
	}
	assert.Equal(t, "NEW_MESSAGE", notif.Type)

	notif.Type = "REPLY"
	assert.Equal(t, "REPLY", notif.Type)

	notif.Type = "MENTION"
	assert.Equal(t, "MENTION", notif.Type)
}

func TestNotificationSeen(t *testing.T) {
	unseenNotif := Notification{
		Seen: false,
	}
	assert.False(t, unseenNotif.Seen)

	seenNotif := Notification{
		Seen: true,
	}
	assert.True(t, seenNotif.Seen)
}

func TestNotificationTimestamp(t *testing.T) {
	now := time.Now()
	notif := Notification{
		Timestamp: now,
	}
	assert.WithinDuration(t, now, notif.Timestamp, time.Second)
}

func TestMultipleNotifications(t *testing.T) {
	notifs := []Notification{
		{ID: 1, Touser: 1, Type: "MESSAGE", Title: "Msg 1"},
		{ID: 2, Touser: 1, Type: "REPLY", Title: "Msg 2"},
		{ID: 3, Touser: 2, Type: "MENTION", Title: "Msg 3"},
	}

	data, err := json.Marshal(notifs)
	require.NoError(t, err)

	var notifs2 []Notification
	err = json.Unmarshal(data, &notifs2)
	require.NoError(t, err)
	assert.Len(t, notifs2, 3)
	assert.Equal(t, notifs[0].ID, notifs2[0].ID)
	assert.Equal(t, notifs[1].Type, notifs2[1].Type)
	assert.Equal(t, notifs[2].Touser, notifs2[2].Touser)
}
