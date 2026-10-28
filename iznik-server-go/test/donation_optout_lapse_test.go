package test

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	user2 "github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// "Don't ask me to donate" lapses a year after it is turned on. The server owns the date
// (settings.nodonationasksuntil): a client only sends the switch.

func patchSettings(t *testing.T, path string, token string, body map[string]interface{}) {
	s, _ := json.Marshal(body)
	request := httptest.NewRequest("PATCH", path+"?jwt="+token, bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(request)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func storedSettings(t *testing.T, userID uint64) map[string]interface{} {
	var stored string
	database.DBConn.Raw("SELECT settings FROM users WHERE id = ?", userID).Scan(&stored)
	var settings map[string]interface{}
	assert.NoError(t, json.Unmarshal([]byte(stored), &settings))
	return settings
}

func storedUntil(t *testing.T, userID uint64) time.Time {
	until, ok := storedSettings(t, userID)["nodonationasksuntil"].(string)
	assert.True(t, ok, "an opt-out carries the date it lapses")
	parsed, err := time.Parse(time.RFC3339, until)
	assert.NoError(t, err)
	return parsed
}

func TestDonationAsksPausedHonoursTheDate(t *testing.T) {
	now := time.Date(2026, 10, 23, 12, 0, 0, 0, time.UTC)
	future := "2027-10-23T12:00:00Z"
	past := "2026-10-22T12:00:00Z"
	junk := "not a date"
	empty := ""

	assert.False(t, user2.DonationAsksPaused(false, &future, now), "the switch off means asks are on")
	assert.True(t, user2.DonationAsksPaused(true, &future, now))
	assert.False(t, user2.DonationAsksPaused(true, &past, now), "a lapsed opt-out no longer holds asks back")
	assert.True(t, user2.DonationAsksPaused(true, nil, now), "an undated opt-out runs until the batch job dates it")
	assert.True(t, user2.DonationAsksPaused(true, &empty, now))
	assert.True(t, user2.DonationAsksPaused(true, &junk, now), "an unreadable date errs on not asking")
}

func TestTurningDonationAsksOffStartsAYear(t *testing.T) {
	prefix := uniquePrefix("donlapse")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	_, token := CreateTestSession(t, memberID)

	before := time.Now().UTC()
	patchSettings(t, "/api/user", token, map[string]interface{}{
		"settings": map[string]interface{}{
			"nodonationasks": true,
			// A date from the client is ignored.
			"nodonationasksuntil": "2099-01-01T00:00:00Z",
		},
	})

	until := storedUntil(t, memberID)
	expected := before.AddDate(0, 0, user2.DonationAskOptOutDays)
	assert.WithinDuration(t, expected, until, time.Minute, "the opt-out lasts a year from now")

	// The member sees the date on their own record.
	u := getUserAs(t, memberID, token)
	assert.True(t, u.NoDonationAsks)
	assert.NotNil(t, u.NoDonationAsksUntil)
}

func TestUnrelatedSaveDoesNotExtendTheOptOut(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donkeep")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	_, token := CreateTestSession(t, memberID)

	running := time.Now().UTC().AddDate(0, 2, 0).Format(time.RFC3339)
	db.Exec(`UPDATE users SET settings = JSON_OBJECT('nodonationasks', true, 'nodonationasksuntil', ?) WHERE id = ?`, running, memberID)

	// A client that sends the whole settings object, without the date, on an unrelated save.
	patchSettings(t, "/api/user", token, map[string]interface{}{
		"settings": map[string]interface{}{"nodonationasks": true, "somecustom": "x"},
	})
	assert.Equal(t, running, storedSettings(t, memberID)["nodonationasksuntil"], "the running opt-out keeps its date")

	// PatchSession replaces settings outright; the date must still survive.
	sessionSettings, _ := json.Marshal(map[string]interface{}{"nodonationasks": true, "somecustom": "y"})
	patchSettings(t, "/api/session", token, map[string]interface{}{"settings": json.RawMessage(sessionSettings)})
	assert.Equal(t, running, storedSettings(t, memberID)["nodonationasksuntil"], "a replacing save keeps the date too")
}

func TestTurningDonationAsksBackOnClearsTheDate(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donclear")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	_, token := CreateTestSession(t, memberID)

	running := time.Now().UTC().AddDate(0, 2, 0).Format(time.RFC3339)
	db.Exec(`UPDATE users SET settings = JSON_OBJECT('nodonationasks', true, 'nodonationasksuntil', ?) WHERE id = ?`, running, memberID)

	patchSettings(t, "/api/user", token, map[string]interface{}{
		"settings": map[string]interface{}{"nodonationasks": false},
	})

	settings := storedSettings(t, memberID)
	assert.Equal(t, false, settings["nodonationasks"])
	assert.Nil(t, settings["nodonationasksuntil"], "no date once asks are back on")
}

func TestLapsedOptOutReadsAsOffAndRestartsWhenTurnedOnAgain(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donlapsed")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	_, token := CreateTestSession(t, memberID)

	supportID := CreateTestUser(t, prefix+"_support", "User")
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", supportID)
	_, supportToken := CreateTestSession(t, supportID)

	lapsed := time.Now().UTC().AddDate(0, 0, -1).Format(time.RFC3339)
	db.Exec(`UPDATE users SET settings = JSON_OBJECT('nodonationasks', true, 'nodonationasksuntil', ?) WHERE id = ?`, lapsed, memberID)

	u := getUserAs(t, memberID, supportToken)
	assert.False(t, u.NoDonationAsks, "support sees a lapsed opt-out as off")
	assert.Nil(t, u.NoDonationAsksUntil)

	before := time.Now().UTC()
	patchSettings(t, "/api/user", token, map[string]interface{}{
		"settings": map[string]interface{}{"nodonationasks": true},
	})
	assert.WithinDuration(t, before.AddDate(0, 0, user2.DonationAskOptOutDays), storedUntil(t, memberID), time.Minute,
		"turning it on again after it lapsed starts a fresh year")
}

func TestEchoOfALapsedOptOutLetsItLapse(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donecho")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	_, token := CreateTestSession(t, memberID)

	lapsed := time.Now().UTC().AddDate(0, 0, -1).Format(time.RFC3339)
	db.Exec(`UPDATE users SET settings = JSON_OBJECT('nodonationasks', true, 'nodonationasksuntil', ?) WHERE id = ?`, lapsed, memberID)

	// A client still holding the old settings saves something unrelated before the batch job runs.
	patchSettings(t, "/api/user", token, map[string]interface{}{
		"settings": map[string]interface{}{"nodonationasks": true, "nodonationasksuntil": lapsed, "enterNewLine": true},
	})

	settings := storedSettings(t, memberID)
	assert.Equal(t, false, settings["nodonationasks"], "an unrelated save must not restart a lapsed opt-out")
	assert.Nil(t, settings["nodonationasksuntil"])
	assert.Equal(t, true, settings["enterNewLine"])
}
