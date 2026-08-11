package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// /rippling/density compares posts grouped by the local-density band that chose
// their reach budget. Every test here is about a way the comparison could look
// true and be wrong, because a band's row is only ever read against the other
// bands' rows.

// Test-only band names. density_band is VARCHAR(8), and each test needs a band
// of its own so another test's fixtures cannot be aggregated into its
// assertions. These are not values the batch side ever writes.
const (
	bandOnce    = "tdonce"
	bandGap     = "tdgap"
	bandWindow  = "tdwin"
	reachPoly   = "ST_GeomFromText('POLYGON((-1 51, 1 51, 1 52, -1 52, -1 51))', 3857)"
	reachInsert = "INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status, " +
		"total_freeglers, max_drive_min, density_band, density_radius_miles, max_minutes_cap, created_at) " +
		"VALUES (?, 51.5, -0.1, " + reachPoly + ", ST_Envelope(" + reachPoly + "), 'expanding', ?, ?, ?, ?, ?, ?)"
)

// insideWindow is "recent" for a fixture: an hour ago rather than this instant.
//
// created_at is TIMESTAMP(0), so a row written at .6 of a second ROUNDS UP to
// the next second, while the handler truncates its window end to the second. A
// fixture stamped exactly now therefore lands past the end of its own window
// often enough to be flaky. An hour is still comfortably inside every window
// these tests ask for.
func insideWindow() time.Time {
	return time.Now().Add(-time.Hour)
}

// mustExec runs a fixture statement and fails the test if it did not apply.
//
// Every INSERT here is a fixture, and a rejected one leaves the assertion to
// fail on a missing row - which reads as "the handler dropped my band" and sends
// you looking at the SQL in the handler instead of at the column list here.
func mustExec(t *testing.T, sql string, args ...interface{}) {
	t.Helper()

	res := database.DBConn.Exec(sql, args...)
	if res.Error != nil {
		t.Fatalf("fixture failed: %v", res.Error)
	}
	if res.RowsAffected == 0 {
		t.Fatalf("fixture affected no rows: %s", sql)
	}
}

// bandRow finds one band in the response. Returns nil when it is absent, so a
// missing band fails on the assertion that names it rather than on a panic.
func bandRow(t *testing.T, token string, band string) map[string]interface{} {
	t.Helper()

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/rippling/density?days=30&jwt=%s", token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	bands, _ := result["bands"].([]interface{})
	seen := []string{}
	for _, b := range bands {
		m, ok := b.(map[string]interface{})
		if !ok {
			continue
		}
		if m["band"] == band {
			return m
		}
		seen = append(seen, fmt.Sprint(m["band"]))
	}

	// Naming what DID come back turns "the handler dropped my band" into a
	// readable difference, which is the only useful thing to know at this point.
	t.Logf("band %q not in the response; window %v to %v; bands present: %v",
		band, result["start"], result["end"], seen)

	return nil
}

// A post with several held replies must still count as ONE post. Joining the
// held replies instead of counting them in a subquery would multiply the reach
// row before the aggregate ran, inflating the post count and every average
// derived from it - and the result would still look entirely plausible.
func TestRipplingDensityCountsAPostOnceHoweverManyHeldReplies(t *testing.T) {
	prefix := uniquePrefix("densityonce")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	groupID := CreateTestGroup(t, prefix+"_group")
	msgID := CreateTestMessage(t, poster, groupID, prefix+" bookcase", 51.5, -0.1)

	db := database.DBConn
	db.Exec("DELETE FROM rippling_reach WHERE density_band = ?", bandOnce)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE density_band = ?", bandOnce)

	mustExec(t, reachInsert, msgID, 4000, 19, bandOnce, 1.25, 20, insideWindow())

	// Three separate repliers, each with their own chat room and message: the
	// held-reply row hangs off a real reply, so inventing ids would fail the
	// insert rather than the assertion.
	for i := 0; i < 3; i++ {
		replier := CreateTestUser(t, fmt.Sprintf("%s_r%d", prefix, i), "User")
		chatID := CreateTestChatRoom(t, replier, &poster, nil, "User2User")
		chatMsgID := CreateTestChatMessage(t, chatID, replier, "Is this still available?")
		mustExec(t, "INSERT INTO rippling_held_replies "+
			"(chatid, chatmsgid, msgid, replieruserid, source, status, created_at) "+
			"VALUES (?, ?, ?, ?, 'web', 'held', NOW())", chatID, chatMsgID, msgID, replier)
	}

	row := bandRow(t, token, bandOnce)
	assert.NotNil(t, row, "the band must appear")
	if row == nil {
		return
	}
	assert.Equal(t, float64(1), row["posts"], "one post, however many replies it held")
	assert.Equal(t, float64(3), row["held"], "all three held replies counted")
	assert.Equal(t, float64(20), row["capminutes"], "the cap that shaped the post, as stored")
	assert.Equal(t, float64(4000), row["avgaudience"], "audience is not multiplied by the held replies")
}

// The cap asked for and the drive time reached answer different questions, and
// the gap between them is the diagnosis: a band whose reach sits well under its
// cap was never constrained by the cap at all. Reporting one in place of the
// other would make an unconstrained band look like a working lever.
func TestRipplingDensityReportsCapAskedAndDriveTimeReachedSeparately(t *testing.T) {
	prefix := uniquePrefix("densitygap")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	groupID := CreateTestGroup(t, prefix+"_group")
	msgID := CreateTestMessage(t, poster, groupID, prefix+" table", 51.5, -0.1)

	db := database.DBConn
	db.Exec("DELETE FROM rippling_reach WHERE density_band = ?", bandGap)
	defer db.Exec("DELETE FROM rippling_reach WHERE density_band = ?", bandGap)

	mustExec(t, reachInsert, msgID, 900, 12, bandGap, 5.5, 45, insideWindow())

	row := bandRow(t, token, bandGap)
	assert.NotNil(t, row)
	if row == nil {
		return
	}
	assert.Equal(t, float64(45), row["capminutes"], "45 minutes were budgeted")
	assert.Equal(t, float64(12), row["avgdrivemin"], "but only 12 were reached - the cap never bound")
	assert.Equal(t, float64(5.5), row["avgradiusmiles"], "the measured density is kept, so bands can be re-cut")
}

// A post whose density could not be measured ran on the flat cap. It is not a
// fourth kind of place, and it must not vanish either: a growing 'unknown' means
// the spatial service is failing, and that is only visible if the row is there.
func TestRipplingDensityKeepsUnmeasuredPostsAsUnknown(t *testing.T) {
	prefix := uniquePrefix("densityunknown")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	groupID := CreateTestGroup(t, prefix+"_group")
	msgID := CreateTestMessage(t, poster, groupID, prefix+" chair", 51.5, -0.1)

	db := database.DBConn
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	mustExec(t, "INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status, "+
		"total_freeglers, max_drive_min, created_at) "+
		"VALUES (?, 51.5, -0.1, "+reachPoly+", ST_Envelope("+reachPoly+"), 'expanding', 900, 30, ?)",
		msgID, insideWindow())

	row := bandRow(t, token, "unknown")
	assert.NotNil(t, row, "a post with no measured band is reported, not dropped")
	if row == nil {
		return
	}
	assert.GreaterOrEqual(t, row["posts"], float64(1))
}

// Rows older than the window are excluded. Without this the window control would
// be decorative, and every band would slowly accumulate the whole of history
// while still looking like a 30-day comparison.
func TestRipplingDensityExcludesReachRowsOutsideTheWindow(t *testing.T) {
	prefix := uniquePrefix("densitywindow")
	adminID := CreateTestUser(t, prefix+"_admin", "Support")
	_, token := CreateTestSession(t, adminID)

	poster := CreateTestUser(t, prefix+"_poster", "User")
	groupID := CreateTestGroup(t, prefix+"_group")
	old := CreateTestMessage(t, poster, groupID, prefix+" old", 51.5, -0.1)
	recent := CreateTestMessage(t, poster, groupID, prefix+" recent", 51.5, -0.1)

	db := database.DBConn
	db.Exec("DELETE FROM rippling_reach WHERE density_band = ?", bandWindow)
	defer db.Exec("DELETE FROM rippling_reach WHERE density_band = ?", bandWindow)

	mustExec(t, reachInsert, old, 900, 20, bandWindow, 1.2, 20, time.Now().AddDate(0, 0, -200))
	mustExec(t, reachInsert, recent, 900, 20, bandWindow, 1.2, 20, insideWindow())

	row := bandRow(t, token, bandWindow)
	assert.NotNil(t, row)
	if row == nil {
		return
	}
	assert.Equal(t, float64(1), row["posts"], "only the post inside the 30-day window")
}

// Support or Admin only, like every other sysadmin metric: these rows describe
// how the whole network is being mailed.
func TestRipplingDensityRequiresPrivilege(t *testing.T) {
	prefix := uniquePrefix("densityauth")
	userID := CreateTestUser(t, prefix+"_member", "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/rippling/density?jwt=%s", token), nil))
	assert.Equal(t, 403, resp.StatusCode, "non-admin is forbidden from the density comparison")
}
