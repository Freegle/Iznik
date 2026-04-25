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
	now := time.Now().Truncate(time.Second)
	notif := Notification{
		ID:        1,
		Fromuser:  2,
		Touser:    3,
		Type:      "Chat",
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
	assert.Equal(t, notif.Fromuser, notif2.Fromuser)
	assert.Equal(t, notif.Touser, notif2.Touser)
	assert.Equal(t, notif.Type, notif2.Type)
	assert.Equal(t, notif.Title, notif2.Title)
	assert.Equal(t, notif.Text, notif2.Text)
	assert.Equal(t, notif.Seen, notif2.Seen)
}

func TestNotificationTypes(t *testing.T) {
	notif := Notification{
		Type: "Chat",
	}
	assert.Equal(t, "Chat", notif.Type)

	notif.Type = "Nudge"
	assert.Equal(t, "Nudge", notif.Type)

	notif.Type = "Alert"
	assert.Equal(t, "Alert", notif.Type)
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

func TestNotificationWithoutLinkedContent(t *testing.T) {
	notif := Notification{
		ID:     1,
		Touser: 2,
		Type:   "Alert",
		Title:  "Welcome",
		Text:   "Welcome to Freegle",
		Seen:   false,
	}

	assert.Equal(t, int64(0), notif.Newsfeedid)
	assert.Equal(t, "Alert", notif.Type)
	assert.NotEmpty(t, notif.Title)
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
		{
			ID:     1,
			Touser: 1,
			Type:   "Chat",
			Title:  "Msg 1",
		},
		{
			ID:     2,
			Touser: 1,
			Type:   "Nudge",
			Title:  "Msg 2",
		},
		{
			ID:     3,
			Touser: 2,
			Type:   "Alert",
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
	notif := Notification{
		ID:     1,
		Touser: 2,
		Type:   "Alert",
		Title:  "Empty",
		Text:   "",
	}

	assert.Equal(t, "", notif.Text)
	assert.NotEmpty(t, notif.Title)
}

func TestNotificationMailed(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	notif := Notification{
		Mailed: false,
	}
	assert.False(t, notif.Mailed)

	notif.Mailed = true
	assert.True(t, notif.Mailed)
}
