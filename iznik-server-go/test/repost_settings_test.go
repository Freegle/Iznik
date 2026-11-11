package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/config"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/group"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
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

// The Repost button (canrepost) is for a member's own repost by hand: it needs the interval to have
// passed and nothing else, so it ignores the number of auto-reposts. repostat is when the next
// AUTO-repost fires, which the member sees as "Will auto-repost ...", so it exists only while the
// post still has auto-reposts left (autoreposts below max, and max above 0).
func TestMessageRepostatStopsAtTheAutoRepostLimit(t *testing.T) {
	prefix := uniquePrefix("repost_max")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix+"_group")
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	_, token := CreateTestSession(t, posterID)

	setSystemRepostsForTest(t, `{"offer":3,"wanted":7,"max":3,"chaseups":5}`)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: Repost limit test", 55.9533, -3.1883)
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE msgid = ?", msgID)

	get := func() message.Message {
		resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil))
		assert.NoError(t, err)
		var msg message.Message
		json2.Unmarshal(rsp(resp), &msg)
		return msg
	}

	db.Exec("UPDATE messages_groups SET autoreposts = 2 WHERE msgid = ?", msgID)
	msg := get()
	assert.NotNil(t, msg.Repostat, "two of three auto-reposts used: one is still to come")
	assert.False(t, msg.Canrepost, "a day is inside the 3 day interval")

	db.Exec("UPDATE messages_groups SET autoreposts = 3 WHERE msgid = ?", msgID)
	msg = get()
	assert.Nil(t, msg.Repostat, "all three auto-reposts used: nothing more will fire")

	// A member can still repost by hand once the interval has passed.
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE msgid = ?", msgID)
	msg = get()
	assert.True(t, msg.Canrepost, "the Repost button does not depend on the auto-repost count")
	assert.Nil(t, msg.Repostat)

	// Auto-reposting off (max 0): no auto-repost time, but the button still follows the interval.
	setSystemRepostsForTest(t, `{"offer":3,"wanted":7,"max":0,"chaseups":5}`)
	db.Exec("UPDATE messages_groups SET autoreposts = 0 WHERE msgid = ?", msgID)
	msg = get()
	assert.Nil(t, msg.Repostat, "max 0 turns auto-reposting off")
	assert.True(t, msg.Canrepost)
}

// A poster who has turned auto-reposting off in their own settings (settings.autorepostsdisable,
// the rule AutoRepostService skips on) is never auto-reposted, so there is no auto-repost time to
// show them. Their Repost button still follows the interval.
func TestMessageRepostatIsNilForAPosterWhoTurnedAutoRepostOff(t *testing.T) {
	prefix := uniquePrefix("repost_optout")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix+"_group")
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	_, token := CreateTestSession(t, posterID)

	setSystemRepostsForTest(t, `{"offer":3,"wanted":7,"max":3,"chaseups":5}`)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: Repost opt-out test", 55.9533, -3.1883)
	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 1 DAY), autoreposts = 0 WHERE msgid = ?", msgID)

	get := func() message.Message {
		resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil))
		assert.NoError(t, err)
		var msg message.Message
		json2.Unmarshal(rsp(resp), &msg)
		return msg
	}

	assert.NotNil(t, get().Repostat, "auto-repost on (the default): a time is shown")

	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.autorepostsdisable', false) WHERE id = ?", posterID)
	assert.NotNil(t, get().Repostat, "autorepostsdisable false: a time is shown")

	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.autorepostsdisable', true) WHERE id = ?", posterID)
	msg := get()
	assert.Nil(t, msg.Repostat, "the poster turned auto-reposting off: nothing will fire")
	assert.False(t, msg.Canrepost, "a day is inside the 3 day interval")

	db.Exec("UPDATE messages_groups SET arrival = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE msgid = ?", msgID)
	msg = get()
	assert.True(t, msg.Canrepost, "the Repost button still follows the interval")
	assert.Nil(t, msg.Repostat)
}
