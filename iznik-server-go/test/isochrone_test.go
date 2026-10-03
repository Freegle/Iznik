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

	// Get isochrones for user
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/isochrone?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var isochrones []isochrone.Isochrones
	json2.Unmarshal(rsp(resp), &isochrones)
	assert.Greater(t, len(isochrones), 0)
	assert.Equal(t, isochrones[0].Userid, userID)

	// Create a message in the area for this test
	CreateTestMessage(t, userID, "Test Message "+prefix, 55.9533, -3.1883)

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
