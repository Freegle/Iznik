package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/isochrone"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func TestIsochrones(t *testing.T) {
	// Logged out - should return 401
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message", nil))
	assert.Equal(t, 401, resp.StatusCode)

	prefix := uniquePrefix("iso")
	userID, token := CreateFullTestUser(t, prefix)

	groupID := CreateTestGroup(t, prefix+"_msg")
	CreateTestMessage(t, userID, groupID, "Test Message "+prefix, 55.9533, -3.1883)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
}

func TestMapboxWKTConversion(t *testing.T) {
	// Test the GeoJSON-to-WKT conversion used by the Mapbox integration.
	// This doesn't call the Mapbox API — it tests the pure conversion logic.
	wkt := isochrone.FetchIsochroneWKTFromGeoJSON(`{
		"type": "FeatureCollection",
		"features": [{
			"type": "Feature",
			"geometry": {
				"type": "Polygon",
				"coordinates": [[[-1.5, 53.8], [-1.4, 53.8], [-1.4, 53.9], [-1.5, 53.9], [-1.5, 53.8]]]
			}
		}]
	}`)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("), "Expected WKT POLYGON, got: "+wkt)
	assert.Contains(t, wkt, "-1.5")
	assert.Contains(t, wkt, "53.8")
}

// Comprehensive TDD tests for isochrone functionality

func TestEnsureIsochroneExistsWithValidLocation(t *testing.T) {
	// Test that ensureIsochroneExists creates an isochrone when given a valid location
	prefix := uniquePrefix("EnsureValid")
	db := database.DBConn

	// Create a location with lat/lng
	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 51.5074, -0.1278, ST_GeomFromText('POINT(-0.1278 51.5074)', ?))", prefix+"_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Clean up any existing isochrones for this location
	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	// Call ensureIsochroneExists with Walk transport and 15 minutes
	isoID := isochrone.EnsureIsochroneExists(locID, "Walk", 15)

	// Should return a non-zero ID (either created or existing)
	assert.Greater(t, isoID, uint64(0), "ensureIsochroneExists should return valid ID")

	// Verify isochrone exists in database
	var count int64
	db.Raw("SELECT COUNT(*) FROM isochrones WHERE id = ? AND locationid = ? AND transport = 'Walk' AND minutes = 15", isoID, locID).Scan(&count)
	assert.Equal(t, int64(1), count)

	// Cleanup
	db.Exec("DELETE FROM isochrones WHERE id = ?", isoID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestEnsureIsochroneExistsWithDifferentTransports(t *testing.T) {
	// Test that ensureIsochroneExists works with all three transport types
	prefix := uniquePrefix("TransportTest")
	db := database.DBConn

	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 55.9533, -3.1883, ST_GeomFromText('POINT(-3.1883 55.9533)', ?))", prefix+"_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	transports := []string{"Walk", "Cycle", "Drive"}
	isoIDs := make(map[string]uint64)

	for _, transport := range transports {
		isoID := isochrone.EnsureIsochroneExists(locID, transport, 20)
		assert.Greater(t, isoID, uint64(0), "Should create isochrone for transport: "+transport)
		isoIDs[transport] = isoID
	}

	// Verify all three isochrones exist and are different
	assert.NotEqual(t, isoIDs["Walk"], isoIDs["Cycle"])
	assert.NotEqual(t, isoIDs["Walk"], isoIDs["Drive"])
	assert.NotEqual(t, isoIDs["Cycle"], isoIDs["Drive"])

	// Cleanup
	for _, isoID := range isoIDs {
		db.Exec("DELETE FROM isochrones WHERE id = ?", isoID)
	}
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestEnsureIsochroneExistsReturnsExisting(t *testing.T) {
	// Test that ensureIsochroneExists returns existing isochrone instead of creating duplicate
	prefix := uniquePrefix("ExistingIso")
	db := database.DBConn

	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 52.5200, 13.4050, ST_GeomFromText('POINT(13.4050 52.5200)', ?))", prefix+"_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	// Call ensureIsochroneExists twice with same parameters
	isoID1 := isochrone.EnsureIsochroneExists(locID, "Cycle", 25)
	isoID2 := isochrone.EnsureIsochroneExists(locID, "Cycle", 25)

	// Should return the same ID both times
	assert.Equal(t, isoID1, isoID2, "Should return existing isochrone ID, not create duplicate")

	// Cleanup
	db.Exec("DELETE FROM isochrones WHERE id = ?", isoID1)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestEnsureIsochroneExistsWithInvalidLocation(t *testing.T) {
	// Test that ensureIsochroneExists returns 0 for invalid location
	invalidLocID := uint64(999999999)

	isoID := isochrone.EnsureIsochroneExists(invalidLocID, "Walk", 15)

	// Should return 0 for invalid location
	assert.Equal(t, uint64(0), isoID)
}

func TestEnsureIsochroneExistsWithNullGeometryLocation(t *testing.T) {
	// Test that ensureIsochroneExists handles location with NULL geometry
	prefix := uniquePrefix("EnsureNullGeom")
	db := database.DBConn

	// Create location with NULL geometry but valid lat/lng
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Postcode', 51.5074, -0.1278)", prefix+"_null_loc")
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_null_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Verify geometry is actually NULL
	var geomCount int64
	db.Raw("SELECT COUNT(*) FROM locations WHERE id = ? AND geometry IS NULL", locID).Scan(&geomCount)
	assert.Equal(t, int64(1), geomCount)

	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	// Call ensureIsochroneExists should still work via fallback
	isoID := isochrone.EnsureIsochroneExists(locID, "Walk", 15)

	// Should succeed and return valid ID
	assert.Greater(t, isoID, uint64(0), "Should create isochrone for location with NULL geometry")

	// Cleanup
	db.Exec("DELETE FROM isochrones WHERE id = ?", isoID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestEnsureIsochroneExistsWithPointGeometry(t *testing.T) {
	// Test that ensureIsochroneExists prefers POINT-only locations as fallback
	prefix := uniquePrefix("EnsurePoint")
	db := database.DBConn

	// Create location with only POINT geometry
	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 55.9533, -3.1883, ST_GeomFromText('POINT(-3.1883 55.9533)', ?))",
		prefix+"_point_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_point_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	// Call ensureIsochroneExists with POINT geometry location
	isoID := isochrone.EnsureIsochroneExists(locID, "Walk", 15)

	// Should return valid ID (creates POINT or fallback polygon)
	assert.Greater(t, isoID, uint64(0), "Should create isochrone for POINT geometry location")

	// Verify isochrone exists
	var count int64
	db.Raw("SELECT COUNT(*) FROM isochrones WHERE id = ?", isoID).Scan(&count)
	assert.Equal(t, int64(1), count)

	// Cleanup
	db.Exec("DELETE FROM isochrones WHERE id = ?", isoID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestEnsureIsochroneExistsDuplicateInsertIgnore(t *testing.T) {
	// Test that INSERT IGNORE prevents duplicates when multiple
	// calls race to create the same isochrone
	prefix := uniquePrefix("EnsureDupe")
	db := database.DBConn

	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 52.5200, 13.4050, ST_GeomFromText('POINT(13.4050 52.5200)', ?))",
		prefix+"_dupe_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_dupe_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	// Simulate race: create two isochrones with identical key
	isoID1 := isochrone.EnsureIsochroneExists(locID, "Walk", 15)
	isoID2 := isochrone.EnsureIsochroneExists(locID, "Walk", 15)

	// Both should succeed and return same ID
	assert.Equal(t, isoID1, isoID2, "INSERT IGNORE should prevent duplicates")

	// Verify only one isochrone exists for this location+transport+minutes
	var count int64
	db.Raw("SELECT COUNT(*) FROM isochrones WHERE locationid = ? AND transport = 'Walk' AND minutes = 15",
		locID).Scan(&count)
	assert.Equal(t, int64(1), count, "Should have exactly one isochrone for the key")

	// Cleanup
	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestEnsureIsochroneExistsMinutesVariation(t *testing.T) {
	// Test that ensureIsochroneExists creates separate isochrones for different minutes
	prefix := uniquePrefix("EnsureMinutes")
	db := database.DBConn

	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 51.5074, -0.1278, ST_GeomFromText('POINT(-0.1278 51.5074)', ?))",
		prefix+"_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)

	minuteValues := []int{5, 15, 25, 45}
	isoIDs := make(map[int]uint64)

	for _, mins := range minuteValues {
		isoID := isochrone.EnsureIsochroneExists(locID, "Walk", mins)
		assert.Greater(t, isoID, uint64(0), "Should create isochrone for %d minutes", mins)
		isoIDs[mins] = isoID
	}

	// Verify all are different
	for i, min1 := range minuteValues {
		for j, min2 := range minuteValues {
			if i != j {
				assert.NotEqual(t, isoIDs[min1], isoIDs[min2],
					"Isochrones for different minutes should have different IDs")
			}
		}
	}

	// Cleanup
	for _, isoID := range isoIDs {
		db.Exec("DELETE FROM isochrones WHERE id = ?", isoID)
	}
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

// TestIsochroneMyGroupsView verifies the 'mygroups' browse view: the list feed and the count BOTH
// restrict to the user's member groups, and a non-member group's post is excluded — so the nav
// badge matches the feed and "Mark seen" can clear it. effectiveBrowseView resolves the view from
// the user's saved setting, so no ?browseView= param is needed (the bug was a client path omitting
// it, defaulting to 'nearby', and counting non-member nearby posts the member feed never showed).
func TestIsochroneMyGroupsView(t *testing.T) {
	prefix := uniquePrefix("isomygroups")
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	db := database.DBConn

	memberGroup := CreateTestGroup(t, prefix+"_member")
	otherGroup := CreateTestGroup(t, prefix+"_other")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, memberGroup)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.browseView', 'mygroups') WHERE id = ?", userID)

	memberMsg := CreateTestMessage(t, posterID, memberGroup, prefix+" memberpost", 55.9533, -3.1883)
	otherMsg := CreateTestMessage(t, posterID, otherGroup, prefix+" otherpost", 55.9533, -3.1883)
	// The browse feed shows open posts (messages_spatial.successful = 0); the helper inserts 1.
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", memberMsg, otherMsg)

	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, memberGroup)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", memberMsg, otherMsg)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", memberMsg, otherMsg)

	// List with NO browseView param: effectiveBrowseView resolves 'mygroups' from the user's setting.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[memberMsg], "member-group post appears in the mygroups feed")
	assert.False(t, got[otherMsg], "non-member-group post is excluded from the mygroups feed")

	// Count uses the same member-group universe — includes the unseen member post.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var cres map[string]interface{}
	json2.Unmarshal(rsp(resp), &cres)
	assert.GreaterOrEqual(t, cres["count"].(float64), float64(1), "mygroups count includes the unseen member-group post")
}

// TestMyGroupsCountsRippledInPost verifies the rippling-out count fix: a post whose single
// messages_spatial.groupid points at a NON-member origin group, but which has an Approved
// messages_groups row in one of the viewer's groups (i.e. it rippled in), is counted in the
// mygroups badge and shown in the feed. The old spatial.groupid-based count missed it because
// spatial stores only one group per post.
func TestMyGroupsCountsRippledInPost(t *testing.T) {
	prefix := uniquePrefix("myggrippledin")
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	db := database.DBConn

	memberGroup := CreateTestGroup(t, prefix+"_member")
	originGroup := CreateTestGroup(t, prefix+"_origin")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, memberGroup)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, memberGroup)

	// Post originates in a group the viewer is NOT in: spatial.groupid = originGroup.
	msg := CreateTestMessage(t, posterID, originGroup, prefix+" rippledpost", 55.9533, -3.1883)
	// It rippled INTO the viewer's member group: an Approved messages_groups row there, while the
	// spatial row keeps pointing at the origin (rippling stores only one group per spatial row).
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, NOW(), 'Approved', 0)", msg, memberGroup)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)

	// Feed includes the rippled-in post.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}
	assert.True(t, got[msg], "post rippled into a member group appears in the mygroups feed even though spatial.groupid is the non-member origin")

	// Count includes it too, so badge == feed and Mark seen can drain it.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/count?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var cres map[string]interface{}
	json2.Unmarshal(rsp(resp), &cres)
	assert.GreaterOrEqual(t, cres["count"].(float64), float64(1), "mygroups count includes the rippled-in post")
}

// TestMyGroupsCountExcludesStaleSpatialRow verifies a messages_spatial row still pointing at a
// member group after the post was removed/retracted there (no Approved, undeleted messages_groups
// row in any of the viewer's groups) is NOT counted and NOT in the feed — the residual the old
// spatial.groupid count left stuck so Mark seen could never clear the badge.
func TestMyGroupsCountExcludesStaleSpatialRow(t *testing.T) {
	prefix := uniquePrefix("myggstale")
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	db := database.DBConn

	memberGroup := CreateTestGroup(t, prefix+"_member")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, memberGroup)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, memberGroup)

	// Post was in the member group (spatial.groupid = memberGroup) ...
	msg := CreateTestMessage(t, posterID, memberGroup, prefix+" stalepost", 55.9533, -3.1883)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msg)
	// ... but has since been removed/retracted there: the messages_groups row is deleted, while the
	// spatial row is left behind still pointing at the member group.
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", msg, memberGroup)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)

	// Feed excludes the stale post.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	for _, m := range msgs {
		assert.NotEqual(t, msg, m.ID, "stale spatial row (no Approved messages_groups in a member group) is excluded from the feed")
	}

	// The viewer's only group has no live member-group post, so the badge is 0 — not stuck on the
	// retracted one.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/count?browseView=mygroups&jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var cres map[string]interface{}
	json2.Unmarshal(rsp(resp), &cres)
	assert.Equal(t, float64(0), cres["count"].(float64), "mygroups count excludes the stale/retracted spatial row (no stuck residual)")
}
