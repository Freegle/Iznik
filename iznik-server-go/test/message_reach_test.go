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
		tick SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		total_ticks SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		next_expansion_at TIMESTAMP NULL DEFAULT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
}

func insertReach(mid uint64, tick, total int) {
	db := database.DBConn
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, tick, total_ticks, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857), ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), ?, ?, 'expanding') "+
		"ON DUPLICATE KEY UPDATE tick = VALUES(tick), total_ticks = VALUES(total_ticks)", mid, tick, total)
}

// A moderator of the post's group sees the post's ACTUAL rippling progress (tick/total/status).
func TestMessageReachAsMod(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachmod")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach test", 51.5, -0.1)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	insertReach(mid, 3, 9)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["rippling"], "post has a reach row")
	assert.Equal(t, float64(3), result["tick"], "actual tick surfaced")
	assert.Equal(t, float64(9), result["totalticks"], "total ticks surfaced")

	// The ACTUAL stored reach outline crosses as GeoJSON, so the reach modal can overlay it
	// against its client-side projection (held/clipped/capped reaches diverge from projected).
	polyStr, ok := result["polygon"].(string)
	assert.True(t, ok, "polygon returned as a GeoJSON string")
	var geo struct {
		Type        string         `json:"type"`
		Coordinates [][][2]float64 `json:"coordinates"`
	}
	assert.NoError(t, json.Unmarshal([]byte(polyStr), &geo), "polygon parses as GeoJSON")
	assert.Equal(t, "Polygon", geo.Type)
	assert.NotEmpty(t, geo.Coordinates, "polygon has at least one ring")
	// Coordinates are [lng, lat] in degrees, matching how the reach geometry is stored.
	assert.InDelta(t, -0.2, geo.Coordinates[0][0][0], 0.001)
	assert.InDelta(t, 51.4, geo.Coordinates[0][0][1], 0.001)
}

// A non-moderator is forbidden.
func TestMessageReachForbiddenForNonMod(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachnomod")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach forbidden", 51.5, -0.1)

	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, group, "Member")
	_, token := CreateTestSession(t, userID)

	insertReach(mid, 3, 9)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 403, resp.StatusCode, "non-moderator is forbidden from a post's reach")
}

// A post with no reach row returns rippling:false with a reason rather than 404.
func TestMessageReachNoReachRow(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachnone")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach none", 51.5, -0.1)
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, false, result["rippling"], "no reach row → rippling:false")
	assert.NotEmpty(t, result["reason"], "a reason is given for why it isn't rippling")
}
