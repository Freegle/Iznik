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
	// Test Notification marshals/unmarshals correctly
	now := time.Now().Truncate(time.Second)
	notif := Notification{
		ID:         1,
		Touser:     2,
		Type:       "NEW_MESSAGE",
		Newsfeedid: 3,
		Title:      "New message",
		Text:       "You have a new message",
		Seen:       false,
		Timestamp:  now,
	}

	data, err := json.Marshal(notif)
	require.NoError(t, err)

	var notif2 Notification
	err = json.Unmarshal(data, &notif2)
	require.NoError(t, err)
	assert.Equal(t, notif.ID, notif2.ID)
	assert.Equal(t, notif.Touser, notif2.Touser)
	assert.Equal(t, notif.Type, notif2.Type)
	assert.Equal(t, notif.Newsfeedid, notif2.Newsfeedid)
	assert.Equal(t, notif.Title, notif2.Title)
	assert.Equal(t, notif.Text, notif2.Text)
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
	// Test notification seen flag (Seen is bool)
	unseenNotif := Notification{
		Seen: false,
	}
	assert.Equal(t, false, unseenNotif.Seen)

	seenNotif := Notification{
		Seen: true,
	}
	assert.Equal(t, true, seenNotif.Seen)
}

func TestNotificationWithoutNewsfeedID(t *testing.T) {
	// Some notifications (e.g. system) may not have a newsfeed ID
	notif := Notification{
		ID:     1,
		Touser: 2,
		Type:   "SYSTEM",
		Title:  "Welcome",
		Text:   "Welcome to Freegle",
		Seen:   false,
	}

	assert.Equal(t, int64(0), notif.Newsfeedid)
	assert.Equal(t, "SYSTEM", notif.Type)
	assert.NotEmpty(t, notif.Title)
}

func TestNotificationTimestamp(t *testing.T) {
	// Test that notification preserves creation timestamp
	now := time.Now()
	notif := Notification{
		Timestamp: now,
	}

	// Time should be preserved (allowing for some rounding)
	assert.WithinDuration(t, now, notif.Timestamp, time.Second)
}

func TestMultipleNotifications(t *testing.T) {
	// Test marshaling multiple notifications
	notifs := []Notification{
		{
			ID:     1,
			Touser: 1,
			Type:   "MESSAGE",
			Title:  "Msg 1",
		},
		{
			ID:     2,
			Touser: 1,
			Type:   "REPLY",
			Title:  "Msg 2",
		},
		{
			ID:     3,
			Touser: 2,
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
	assert.Equal(t, notifs[2].Touser, notifs2[2].Touser)
}

func TestNotificationEmptyText(t *testing.T) {
	// Test notification with empty text
	notif := Notification{
		ID:     1,
		Touser: 2,
		Type:   "SYSTEM",
		Title:  "Empty",
		Text:   "",
	}

	assert.Equal(t, "", notif.Text)
	assert.NotEmpty(t, notif.Title)
}
