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
// Also stands up rippling_reach_geom (plans/2026-08-23-rippling-reach-polygon-dedup.md) so a
// from-scratch bootstrap - never exercised while the real migrated schema is cloned in first,
// but a latent trap if that assumption ever breaks - carries the dedup columns too, rather
// than silently lacking polygon_hash/max_polygon_hash.
func ensureRippleReachTable() {
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach_geom (
		hash BINARY(16) NOT NULL PRIMARY KEY,
		geom GEOMETRY NOT NULL SRID 3857,
		createdat TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		SPATIAL INDEX rippling_reach_geom_geom (geom)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		polygon_hash BINARY(16) NULL,
		max_polygon_hash BINARY(16) NULL,
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

	// The ACTUAL stored reach outline crosses as GeoJSON - it is what the reach modal draws,
	// since held/clipped/capped reaches are invisible to a schedule-based projection.
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

// A drained row (polygon blob replaced by the sentinel POINT, real geometry only in
// rippling_reach_geom - the end state of ripple:drain-deduped-blobs) must still serve
// the REAL outline: the reach modal reading a dot instead of the reach is exactly the
// failure the COALESCE join exists to prevent.
func TestMessageReachServesDrainedGeometryFromSharedRow(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachdrained")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach drained test", 51.5, -0.1)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	insertReach(mid, 3, 9)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	// Dedup then drain, the way the real commands do: shared row from the stored
	// bytes, hash pointed at it, blob replaced by the sentinel.
	db.Exec("INSERT INTO rippling_reach_geom (hash, geom) "+
		"SELECT UNHEX(MD5(ST_AsBinary(polygon))), polygon FROM rippling_reach WHERE msgid = ? "+
		"ON DUPLICATE KEY UPDATE createdat = CURRENT_TIMESTAMP", mid)
	db.Exec("UPDATE rippling_reach SET polygon_hash = UNHEX(MD5(ST_AsBinary(polygon))) WHERE msgid = ?", mid)
	db.Exec("UPDATE rippling_reach SET polygon = ST_GeomFromText('POINT(0 0)', 3857) WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["rippling"])

	polyStr, ok := result["polygon"].(string)
	assert.True(t, ok, "polygon returned as a GeoJSON string")
	var geo struct {
		Type        string         `json:"type"`
		Coordinates [][][2]float64 `json:"coordinates"`
	}
	assert.NoError(t, json.Unmarshal([]byte(polyStr), &geo))
	assert.Equal(t, "Polygon", geo.Type, "the REAL polygon, not the drained sentinel POINT")
	assert.NotEmpty(t, geo.Coordinates)
	assert.InDelta(t, -0.2, geo.Coordinates[0][0][0], 0.001, "real reach outline served from rippling_reach_geom")
	assert.InDelta(t, 51.4, geo.Coordinates[0][0][1], 0.001)
}

// A moderator of some OTHER group can still see a post's reach. Rippling carries posts to
// groups beyond the one they were posted on, so the mods asking "how far did this get?" are
// usually not mods of its origin group - gating on mod-of-this-post's-group hid reach from
// exactly the people it is for. Reach carries no member data, so there is nothing to scope.
func TestMessageReachAsModOfDifferentGroup(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachothermod")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach other-group mod", 51.5, -0.1)

	// A mod of an unrelated group, with no membership at all of the post's group.
	otherGroup := CreateTestGroup(t, prefix+"_other")
	modID := CreateTestUser(t, prefix+"_othermod", "User")
	CreateTestMembership(t, modID, otherGroup, "Moderator")
	_, token := CreateTestSession(t, modID)

	insertReach(mid, 3, 9)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode, "any moderator may view reach, not just mods of the post's group")

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["rippling"], "post has a reach row")
	assert.Equal(t, float64(3), result["tick"], "actual tick surfaced to an other-group mod")
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

// The reach map shows a post's RINGS as well as its reach.
//
// Without them the map under-reports where a post went, for exactly the posts whose
// moderators are most likely to be asking: a Hawes post's reach outline stops in the
// dale while two cluster wedges carry it to Penrith and Lancaster, browse shows those
// members the post and the mail invites them. "Did this get to X?" answered from the
// outline alone is wrong whenever X is in a ring.
func TestMessageReachIncludesTheRings(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn
	db.Exec("ALTER TABLE rippling_reach ADD COLUMN overflow_bounds JSON NULL")

	prefix := uniquePrefix("reachrings")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach rings test", 51.5, -0.1)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	insertReach(mid, 3, 9)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	// A band ring and a wedge, well outside the reach polygon above.
	db.Exec("UPDATE rippling_reach SET overflow_bounds = JSON_OBJECT("+
		"'bbox', JSON_ARRAY(0.5, 51.9, 1.5, 52.5), "+
		"'rural', JSON_OBJECT('sparse', 'POLYGON((0.5 51.9,1.5 51.9,1.5 52.5,0.5 52.5,0.5 51.9))'), "+
		"'cluster', JSON_OBJECT('w1', 'POLYGON((-3.5 53.9,-2.5 53.9,-2.5 54.5,-3.5 54.5,-3.5 53.9))')"+
		") WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	rings, ok := result["overflow"].(map[string]interface{})
	assert.True(t, ok, "the rings come back keyed by lane")
	assert.Len(t, rings, 2, "one entry per lane the post carries, and only those")

	// Keyed by lane, because which lane admitted somebody is the question a moderator
	// asks next: a band ring means the cap bound, a wedge means it did not.
	sparse, ok := rings["rural.sparse"].(string)
	assert.True(t, ok, "the band ring is keyed by its lane")
	assert.Contains(t, rings, "cluster.w1", "the wedge is keyed by its slot")
	assert.NotContains(t, rings, "cluster.w2", "a lane this post has not got is absent, not null")

	var geo struct {
		Type        string         `json:"type"`
		Coordinates [][][2]float64 `json:"coordinates"`
	}
	assert.NoError(t, json.Unmarshal([]byte(sparse), &geo), "each ring parses as GeoJSON")
	assert.Equal(t, "Polygon", geo.Type)
	assert.NotEmpty(t, geo.Coordinates)

	// Assert the ring's EXTENT, not its first vertex: simplifying normalises the
	// winding and start point, so which corner comes first is MySQL's business and
	// asserting it tests nothing about whether the right area came back.
	// Coordinates are [lng, lat] degrees, as the reach polygon's are.
	minLng, maxLng := geo.Coordinates[0][0][0], geo.Coordinates[0][0][0]
	minLat, maxLat := geo.Coordinates[0][0][1], geo.Coordinates[0][0][1]
	for _, p := range geo.Coordinates[0] {
		if p[0] < minLng {
			minLng = p[0]
		}
		if p[0] > maxLng {
			maxLng = p[0]
		}
		if p[1] < minLat {
			minLat = p[1]
		}
		if p[1] > maxLat {
			maxLat = p[1]
		}
	}
	assert.InDelta(t, 0.5, minLng, 0.01, "the ring spans the longitudes it was seeded with")
	assert.InDelta(t, 1.5, maxLng, 0.01)
	assert.InDelta(t, 51.9, minLat, 0.01, "and the latitudes")
	assert.InDelta(t, 52.5, maxLat, 0.01)
}

// A post with no rings says nothing about them, rather than shipping ten nulls.
func TestMessageReachOmitsRingsWhenThereAreNone(t *testing.T) {
	ensureRippleReachTable()
	db := database.DBConn

	prefix := uniquePrefix("reachnorings")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: no rings", 51.5, -0.1)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, group, "Moderator")
	_, token := CreateTestSession(t, modID)

	insertReach(mid, 1, 9)
	db.Exec("UPDATE rippling_reach SET overflow_bounds = NULL WHERE msgid = ?", mid)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d/reach?jwt=%s", mid, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "overflow", "no rings means no key at all")
}
