package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/config"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/group"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// Repost delays and counts are one system-wide setting, the config row "reposts", the same for
// every community. A community's own settings.reposts is ignored.

// setSystemRepostsForTest writes the config row, forgets the API's cached copy, and puts the row
// back (and forgets the copy again) when the test ends.
func setSystemRepostsForTest(t *testing.T, value string) {
	db := database.DBConn

	var old []string
	db.Raw("SELECT value FROM config WHERE `key` = 'reposts'").Scan(&old)

	db.Exec("INSERT INTO config (`key`, value) VALUES ('reposts', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", value)
	group.ResetSystemRepostSettingsCache()

	t.Cleanup(func() {
		if len(old) > 0 {
			db.Exec("UPDATE config SET value = ? WHERE `key` = 'reposts'", old[0])
		} else {
			db.Exec("DELETE FROM config WHERE `key` = 'reposts'")
		}
		group.ResetSystemRepostSettingsCache()
	})
}

func TestSystemRepostSettingsReadsTheRow(t *testing.T) {
	setSystemRepostsForTest(t, `{"offer":4,"wanted":9,"max":2,"chaseups":6}`)

	assert.Equal(t, group.RepostSettings{Offer: 4, Wanted: 9, Max: 2, Chaseups: 6}, group.SystemRepostSettings())
}

func TestSystemRepostSettingsMigrationRow(t *testing.T) {
	// The row the batch migration inserts and the test database has had since setup.
	group.ResetSystemRepostSettingsCache()

	assert.Equal(t, group.RepostSettings{Offer: 3, Wanted: 7, Max: 3, Chaseups: 5}, group.SystemRepostSettings())
	assert.Equal(t, group.DefaultRepostSettings(), group.SystemRepostSettings(), "the built-in fallback matches the row")
}

func TestSystemRepostSettingsKeepsZero(t *testing.T) {
	setSystemRepostsForTest(t, `{"offer":0,"wanted":7,"max":0,"chaseups":5}`)

	s := group.SystemRepostSettings()
	assert.Equal(t, 0, s.Offer, "0 means off and is kept")
	assert.Equal(t, 0, s.Max)
}

func TestSystemRepostSettingsMissingRowFallsBack(t *testing.T) {
	setSystemRepostsForTest(t, `{"offer":4,"wanted":9,"max":2,"chaseups":6}`)
	database.DBConn.Exec("DELETE FROM config WHERE `key` = 'reposts'")
	group.ResetSystemRepostSettingsCache()

	assert.Equal(t, group.DefaultRepostSettings(), group.SystemRepostSettings())
}

func TestSystemRepostSettingsMalformedRowFallsBack(t *testing.T) {
	setSystemRepostsForTest(t, `not json`)

	assert.Equal(t, group.DefaultRepostSettings(), group.SystemRepostSettings())
}

func TestSystemRepostSettingsBadValueFallsBackForThatKeyOnly(t *testing.T) {
	setSystemRepostsForTest(t, `{"offer":"soon","wanted":9,"max":-1,"chaseups":2.5}`)

	assert.Equal(t, group.RepostSettings{Offer: 3, Wanted: 9, Max: 3, Chaseups: 5}, group.SystemRepostSettings())
}

func TestSystemRepostSettingsIsCachedUntilReset(t *testing.T) {
	setSystemRepostsForTest(t, `{"offer":4,"wanted":9,"max":2,"chaseups":6}`)
	assert.Equal(t, 4, group.SystemRepostSettings().Offer)

	database.DBConn.Exec("UPDATE config SET value = ? WHERE `key` = 'reposts'", `{"offer":5,"wanted":9,"max":2,"chaseups":6}`)
	assert.Equal(t, 4, group.SystemRepostSettings().Offer, "a copy is believed for a few minutes")

	group.ResetSystemRepostSettingsCache()
	assert.Equal(t, 5, group.SystemRepostSettings().Offer)
}

func TestConfigGetRepostsIsPublic(t *testing.T) {
	// The frontend reads the repost settings without signing in, to show them to moderators and
	// to fill them into standard messages.
	setSystemRepostsForTest(t, `{"offer":4,"wanted":9,"max":2,"chaseups":6}`)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/reposts", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var results []config.ConfigItem
	json2.Unmarshal(rsp(resp), &results)

	if assert.Len(t, results, 1) {
		assert.Equal(t, `{"offer":4,"wanted":9,"max":2,"chaseups":6}`, results[0].Value)
	}
}

func TestBrowseOwnExpiryUsesTheSystemRepostLifetime(t *testing.T) {
	// The community's display window is 20 days. A 45 day old own OFFER is past it, and past the
	// system lifetime of 3*(3+1) = 12 days, so it is hidden. With a system lifetime of 15*(3+1) = 60
	// days it is still inside, so it stays: expiry follows the system row, not the community's.
	db := database.DBConn

	prefix := uniquePrefix("ownexprow")
	groupID := setupOwnExpiryGroup(t, prefix)

	posterID, token := CreateFullTestUser(t, prefix+"_poster")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 53.0, 'lng', -2.0)) WHERE id = ?", posterID)

	old := CreateTestMessageWithArrival(t, posterID, groupID, "OFFER: aged own post ("+prefix+")", 53.0, -2.0, 45)
	db.Exec("UPDATE messages SET lat = 53.0, lng = -2.0 WHERE id = ?", old)

	visible := func() bool {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)

		var msgs []message.MessageSummary
		json2.Unmarshal(rsp(resp), &msgs)

		for _, m := range msgs {
			if m.ID == old {
				return true
			}
		}

		return false
	}

	setSystemRepostsForTest(t, `{"offer":3,"wanted":7,"max":3,"chaseups":5}`)
	assert.False(t, visible(), "45 days is past both the 20 day window and the 12 day repost lifetime")

	setSystemRepostsForTest(t, `{"offer":15,"wanted":7,"max":3,"chaseups":5}`)
	assert.True(t, visible(), "45 days is inside a 60 day repost lifetime")
}
