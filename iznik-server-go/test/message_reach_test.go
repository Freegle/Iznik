package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// ensureRippleReachTable stands up rippling_reach with the columns the reach endpoint reads,
// so these tests run regardless of whether the reach-engine migration is in this schema yet.
func ensureRippleReachTable() {
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		arrival TIMESTAMP NULL DEFAULT NULL,
		mode VARCHAR(8) NOT NULL DEFAULT 'drive',
		tick SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		total_ticks SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		total_freeglers INT UNSIGNED NOT NULL DEFAULT 0,
		max_drive_min FLOAT NULL DEFAULT NULL,
		next_expansion_at TIMESTAMP NULL DEFAULT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
}

// A moderator of the post's group can fetch the post's current rippling reach: the reach
// polygon (as GeoJSON for the map), the origin point, and the schedule cursor (tick).
func TestMessageReachAsMod(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachmod")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach map test", 51.5, -0.1)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, tick, total_ticks, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), 3, 9, 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), tick = VALUES(tick)", mid)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	assert.Equal(t, true, result["rippling"], "post has a reach row")
	assert.Equal(t, float64(3), result["tick"], "current tick surfaced")
	assert.Equal(t, float64(9), result["totalticks"], "total ticks surfaced")
	assert.Equal(t, 51.5, result["lat"], "origin lat surfaced")

	polygon, ok := result["polygon"].(map[string]interface{})
	assert.True(t, ok, "polygon returned as GeoJSON object")
	assert.Equal(t, "Polygon", polygon["type"], "GeoJSON geometry type is Polygon")
	assert.NotNil(t, polygon["coordinates"], "GeoJSON has coordinates")
}

// A user who is not a moderator of any of the post's groups is forbidden.
func TestMessageReachForbiddenForNonMod(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachnomod")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach map forbidden", 51.5, -0.1)

	// A plain member, not a moderator.
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, group, "Member")
	_, token := CreateTestSession(t, userID)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, tick, total_ticks, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), 3, 9, 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", mid)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 403, resp.StatusCode, "non-moderator is forbidden from a post's reach")
}

// A post that is not rippling (no reach row) returns rippling:false rather than 404, so the
// mod UI can say "not rippling yet" cleanly.
func TestMessageReachNoReachRow(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachnone")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach map none", 51.5, -0.1)
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, false, result["rippling"], "no reach row → rippling:false")
}
