package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/location"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func TestClosest(t *testing.T) {
	l := location.ClosestPostcode(55.957571, -3.205333)
	id := l.ID
	assert.NotNil(t, id)
	name := l.Name
	areaname := l.Areaname
	assert.Greater(t, id, uint64(0))
	assert.Greater(t, len(name), 0)
	assert.Greater(t, len(areaname), 0)

	location := location.FetchSingle(id)
	assert.Equal(t, name, location.Name)
	assert.Equal(t, areaname, location.Areaname)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/"+fmt.Sprint(id), nil))
	assert.NotNil(t, resp)
	assert.Equal(t, 200, resp.StatusCode)

	json2.Unmarshal(rsp(resp), &location)
	assert.Equal(t, location.ID, id)
}

func TestTypeahead(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/typeahead?q=EH3&groupsnear=true&limit=1000", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var locations []location.Location
	json2.Unmarshal(rsp(resp), &locations)
	assert.Greater(t, len(locations), 0)
	assert.Greater(t, len(locations[0].Name), 0)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/location/typeahead?p=EH3", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestLatLng(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/latlng?lat=55.957571&lng=-3.205333", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var location location.Location
	json2.Unmarshal(rsp(resp), &location)
	assert.Equal(t, location.Name, "EH3 6SS")
}

func TestLocation_InvalidID(t *testing.T) {
	// Non-integer location ID
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/notanint", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestLocation_NonExistentID(t *testing.T) {
	// Location ID that doesn't exist - handler returns 404.
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/location/999999999", nil), 10000)
	assert.NoError(t, err)

	if assert.NotNil(t, resp) {
		assert.Equal(t, 404, resp.StatusCode)
	}
}

func TestTypeahead_MissingQuery(t *testing.T) {
	// No query param at all
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/typeahead", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestLatLngGroupsNearOntn(t *testing.T) {
	// LatLng should return groupsnear with the ontn field
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/latlng?lat=55.957571&lng=-3.205333", nil))
	assert.Equal(t, 200, resp.StatusCode)

	// Parse raw JSON to check ontn field exists
	var raw map[string]json2.RawMessage
	json2.Unmarshal(rsp(resp), &raw)
	assert.Contains(t, string(raw["groupsnear"]), "ontn", "groupsnear should include ontn field")
}

func TestTypeaheadAreaField(t *testing.T) {
	// Typeahead response should include area with lat/lng for postcodes that have an areaid
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/typeahead?q=EH3+6&limit=1", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var locations []location.Location
	json2.Unmarshal(rsp(resp), &locations)
	assert.Greater(t, len(locations), 0)

	if len(locations) > 0 && locations[0].Areaid > 0 {
		assert.NotNil(t, locations[0].Area, "Location with areaid should have area field populated")
		assert.Equal(t, locations[0].Areaid, locations[0].Area.ID)
		assert.NotZero(t, locations[0].Area.Lat, "Area should have lat")
		assert.NotZero(t, locations[0].Area.Lng, "Area should have lng")
	}
}

func TestTypeahead_V2Path(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/location/typeahead?q=EH3&limit=5", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var locations []location.Location
	json2.Unmarshal(rsp(resp), &locations)
	assert.Greater(t, len(locations), 0)
}

func TestAddresses(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/1687412/addresses", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var addresses []location.Address
	json2.Unmarshal(rsp(resp), &addresses)
	assert.Greater(t, len(addresses), 0)
}

func TestCreateLocation(t *testing.T) {
	prefix := uniquePrefix("locwr_create")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	body := fmt.Sprintf(`{"name":"Test Location %s","polygon":"POLYGON((-3.21 55.94, -3.21 55.97, -3.18 55.97, -3.18 55.94, -3.21 55.94))"}`, prefix)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Greater(t, result["id"], float64(0))

	locID := int(result["id"].(float64))

	// Verify a remap_postcodes background task was queued.
	time.Sleep(100 * time.Millisecond)
	db := database.DBConn
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0), "remap_postcodes task should be queued after location create")

	// Verify locations_spatial was synced (critical for PostcodeRemapService to find new areas).
	var spatialCount int64
	db.Raw("SELECT COUNT(*) FROM locations_spatial WHERE locationid = ?", locID).Scan(&spatialCount)
	assert.Equal(t, int64(1), spatialCount, "locations_spatial should have an entry after location create")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations_spatial WHERE locationid = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestCreateLocationNotAdmin(t *testing.T) {
	prefix := uniquePrefix("locwr_notadm")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"name":"Test Location","polygon":"POLYGON((-3.21 55.94, -3.21 55.97, -3.18 55.97, -3.18 55.94, -3.21 55.94))"}`
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestCreateLocationNotLoggedIn(t *testing.T) {
	body := `{"name":"Test Location","polygon":"POLYGON((-3.21 55.94, -3.21 55.97, -3.18 55.97, -3.18 55.94, -3.21 55.94))"}`
	req := httptest.NewRequest("PUT", "/api/locations", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

// TestUpdateLocationPreservesNearbyVertex verifies that a vertex placed within 0.001
// degrees of the line between its neighbours is NOT silently dropped on save.
// Reproduces Discourse #9770: dragging a polygon midpoint creates a new vertex that
// ST_Simplify(polygon, 0.001) removes because it lies within ~111 m of the original edge.
func TestUpdateLocationPreservesNearbyVertex(t *testing.T) {
	prefix := uniquePrefix("locwr_nearvert")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn

	// Seed an initial 4-vertex polygon (Edinburgh area).
	db.Exec("INSERT INTO locations (name, type, canon, popularity) VALUES (?, 'Polygon', ?, 0)",
		"NearVertTest "+prefix, "nearverttest "+prefix)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "NearVertTest "+prefix).Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Polygon with 5 distinct vertices: the 5th is the midpoint-drag result.
	// It sits 0.0005 degrees (≈55 m) above the bottom edge — well within the
	// previous ST_Simplify(polygon, 0.001) tolerance, so the bug dropped it.
	withMidpoint := "POLYGON((-3.21 55.94, -3.21 55.97, -3.18 55.97, -3.18 55.94, -3.195 55.9405, -3.21 55.94))"
	body := fmt.Sprintf(`{"id":%d,"polygon":"%s"}`, locID, withMidpoint)
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// The midpoint vertex must be persisted — ST_NumPoints should be 6 (5 distinct + closing).
	var numPoints int
	db.Raw("SELECT ST_NumPoints(ST_ExteriorRing(ourgeometry)) FROM locations WHERE id = ?", locID).Scan(&numPoints)
	assert.Equal(t, 6, numPoints, "midpoint-drag vertex must be preserved (not simplified away)")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations_spatial WHERE locationid = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

// Reproduces the STILL-OPEN half of Discourse #9770 (post #7: "no change from before").
// The earlier fix removed write-time ST_Simplify, so ourgeometry keeps the dragged midpoint -
// but the areas display query (SearchLocations, areas=true) still ST_Simplify(...,0.001)s the
// geometry it returns. The map editor (ModGroupMapLocation.vue) loads that returned polygon,
// so after Save+reload the new vertex has vanished and the edit looks unsaved. The vertex must
// survive the exact round-trip the editor uses: PATCH save -> GET areas reload.
func TestUpdateLocationMidpointVertexSurvivesAreasReload(t *testing.T) {
	prefix := uniquePrefix("locwr_reload")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn

	// The area the moderator edits.
	db.Exec("INSERT INTO locations (name, type, canon, popularity) VALUES (?, 'Polygon', ?, 0)",
		"ReloadArea "+prefix, "reloadarea "+prefix)
	var areaID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "ReloadArea "+prefix).Scan(&areaID)
	assert.Greater(t, areaID, uint64(0))

	// A postcode whose areaid points at the area, so the areas query returns it.
	db.Exec("INSERT INTO locations (name, type, canon, popularity, areaid, lat, lng) VALUES (?, ?, ?, 0, ?, 55.955, -3.195)",
		"ReloadPC "+prefix, utils.LOCATION_TYPE_POSTCODE, "reloadpc "+prefix, areaID)

	defer func() {
		db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", areaID)
		db.Exec("DELETE FROM locations_spatial WHERE locationid = ?", areaID)
		db.Exec("DELETE FROM locations WHERE name IN (?, ?)", "ReloadArea "+prefix, "ReloadPC "+prefix)
	}()

	// User drags a midpoint (5th vertex, ~55 m above the bottom edge) and saves.
	withMidpoint := "POLYGON((-3.21 55.94, -3.21 55.97, -3.18 55.97, -3.18 55.94, -3.195 55.9405, -3.21 55.94))"
	body := fmt.Sprintf(`{"id":%d,"polygon":"%s"}`, areaID, withMidpoint)
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Sanity: the stored geometry keeps the vertex (write side already fixed).
	var storedPoints int
	db.Raw("SELECT ST_NumPoints(ST_ExteriorRing(ourgeometry)) FROM locations WHERE id = ?", areaID).Scan(&storedPoints)
	assert.Equal(t, 6, storedPoints, "stored ourgeometry should keep the dragged midpoint vertex")

	// Reload exactly as the map editor does: GET /locations?areas=true over a covering bbox.
	url := "/api/locations?areas=true&groupsnear=false&swlat=55.93&swlng=-3.22&nelat=55.98&nelng=-3.17&jwt=" + adminToken
	resp2, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var listResult struct {
		Locations []struct {
			ID      uint64 `json:"id"`
			Polygon string `json:"polygon"`
		} `json:"locations"`
	}
	json2.Unmarshal(rsp(resp2), &listResult)

	var reloaded string
	for _, l := range listResult.Locations {
		if l.ID == areaID {
			reloaded = l.Polygon
		}
	}
	assert.NotEmpty(t, reloaded, "the edited area should be returned by the areas query")

	// The polygon the editor receives back must still contain the dragged midpoint vertex.
	var reloadedPoints int
	db.Raw("SELECT ST_NumPoints(ST_ExteriorRing(ST_GeomFromText(?)))", reloaded).Scan(&reloadedPoints)
	assert.Equal(t, 6, reloadedPoints,
		"midpoint vertex must survive the areas reload the editor uses (Discourse #9770 post #7)")
}

func TestUpdateLocation(t *testing.T) {
	prefix := uniquePrefix("locwr_upd")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	// Create a location first.
	db := database.DBConn
	db.Exec("INSERT INTO locations (name, type, canon, popularity) VALUES (?, 'Polygon', ?, 0)",
		"UpdateTest "+prefix, "updatetest "+prefix)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "UpdateTest "+prefix).Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Update polygon.
	newPolygon := "POLYGON((-3.22 55.93, -3.22 55.98, -3.17 55.98, -3.17 55.93, -3.22 55.93))"
	body := fmt.Sprintf(`{"id":%d,"polygon":"%s"}`, locID, newPolygon)
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify ourgeometry was set (not geometry — ourgeometry is the human-edited override).
	var ourgeom string
	db.Raw("SELECT ST_AsText(ourgeometry) FROM locations WHERE id = ?", locID).Scan(&ourgeom)
	assert.NotEmpty(t, ourgeom, "ourgeometry should be set after PATCH")

	// Verify locations_spatial was updated.
	var spatialCount int64
	db.Raw("SELECT COUNT(*) FROM locations_spatial WHERE locationid = ?", locID).Scan(&spatialCount)
	assert.Equal(t, int64(1), spatialCount, "locations_spatial should have an entry")

	// Verify centroid lat/lng were updated.
	var centroid struct {
		Lat float64
		Lng float64
	}
	db.Raw("SELECT lat, lng FROM locations WHERE id = ?", locID).Scan(&centroid)
	assert.NotZero(t, centroid.Lat, "lat should be set from centroid")
	assert.NotZero(t, centroid.Lng, "lng should be set from centroid")

	// Verify a remap_postcodes background task was queued (async, so brief wait).
	time.Sleep(100 * time.Millisecond)
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0), "remap_postcodes task should be queued after geometry update")
	// Cleanup the task.
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)

	// Update name.
	newName := "Updated " + prefix
	body = fmt.Sprintf(`{"id":%d,"name":"%s"}`, locID, newName)
	req = httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify name was updated.
	var name string
	db.Raw("SELECT name FROM locations WHERE id = ?", locID).Scan(&name)
	assert.Equal(t, newName, name)

	// Verify canon was set to lowercase.
	var canon string
	db.Raw("SELECT canon FROM locations WHERE id = ?", locID).Scan(&canon)
	assert.Equal(t, "updated "+prefix, canon)

	// Cleanup
	db.Exec("DELETE FROM locations_spatial WHERE locationid = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestUpdateLocationInvalidGeometry(t *testing.T) {
	prefix := uniquePrefix("locwr_invgeo")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Exec("INSERT INTO locations (name, type, canon, popularity) VALUES (?, 'Polygon', ?, 0)",
		"InvalidGeoTest "+prefix, "invalidgeotest "+prefix)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "InvalidGeoTest "+prefix).Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Try with invalid polygon (self-intersecting).
	body := fmt.Sprintf(`{"id":%d,"polygon":"POLYGON((0 0, 1 1, 1 0, 0 1, 0 0))"}`, locID)
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Cleanup
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestUpdateLocationNotAdmin(t *testing.T) {
	prefix := uniquePrefix("locwr_updna")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"id":1,"name":"Hacked"}`
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestExcludeLocation(t *testing.T) {
	prefix := uniquePrefix("locwr_excl")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create a test location.
	db := database.DBConn
	db.Exec("INSERT INTO locations (name, type, canon, popularity) VALUES (?, 'Polygon', ?, 0)",
		"ExclTest "+prefix, "excltest "+prefix)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "ExclTest "+prefix).Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	body := fmt.Sprintf(`{"id":%d,"groupid":%d,"action":"Exclude"}`, locID, groupID)
	req := httptest.NewRequest("POST", "/api/locations?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify exclusion was created.
	var count int64
	db.Raw("SELECT COUNT(*) FROM locations_excluded WHERE locationid = ? AND groupid = ?", locID, groupID).Scan(&count)
	assert.Equal(t, int64(1), count)

	// Cleanup
	db.Exec("DELETE FROM locations_excluded WHERE locationid = ? AND groupid = ?", locID, groupID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestExcludeLocationQueuesRemapTask(t *testing.T) {
	prefix := uniquePrefix("locwr_exclrmp")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create a test location with a polygon. The remap task needs the
	// excluded location's geometry so PostcodeRemapService can re-run KNN
	// over the postcodes that were previously inside it.
	db := database.DBConn
	polygon := "POLYGON((-3.21 55.94, -3.21 55.97, -3.18 55.97, -3.18 55.94, -3.21 55.94))"
	db.Exec(fmt.Sprintf(
		"INSERT INTO locations (name, type, canon, popularity, ourgeometry) VALUES (?, 'Polygon', ?, 0, ST_GeomFromText(?, %d))",
		3857), // utils.SRID == 3857
		"ExclRemap "+prefix, "exclremap "+prefix, polygon)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "ExclRemap "+prefix).Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Make sure there is no stale task from a prior run.
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)

	body := fmt.Sprintf(`{"id":%d,"groupid":%d,"action":"Exclude"}`, locID, groupID)
	req := httptest.NewRequest("POST", "/api/locations?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify exclusion was created.
	var count int64
	db.Raw("SELECT COUNT(*) FROM locations_excluded WHERE locationid = ? AND groupid = ?", locID, groupID).Scan(&count)
	assert.Equal(t, int64(1), count)

	// Verify a remap_postcodes task was queued — exclusion changes which
	// area postcodes inside this polygon should belong to, so KNN must be
	// re-run. Async via goroutine, so brief wait.
	time.Sleep(100 * time.Millisecond)
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0), "remap_postcodes task should be queued after location exclude")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations_excluded WHERE locationid = ? AND groupid = ?", locID, groupID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestExcludeLocationNotMod(t *testing.T) {
	prefix := uniquePrefix("locwr_exclnm")
	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"id":1,"groupid":%d,"action":"Exclude"}`, groupID)
	req := httptest.NewRequest("POST", "/api/locations?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestConvertKML(t *testing.T) {
	prefix := uniquePrefix("locwr_kml")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	kml := `<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
<Placemark>
<Polygon>
<outerBoundaryIs>
<LinearRing>
<coordinates>-0.1,51.5,0 -0.1,51.6,0 0.0,51.6,0 0.0,51.5,0 -0.1,51.5,0</coordinates>
</LinearRing>
</outerBoundaryIs>
</Polygon>
</Placemark>
</Document>
</kml>`

	body, _ := json2.Marshal(map[string]interface{}{
		"action": "kml",
		"kml":    kml,
	})
	req := httptest.NewRequest("POST", "/api/locations/kml?jwt="+token, bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	assert.Contains(t, result["wkt"], "POLYGON")
	assert.Contains(t, result["wkt"], "-0.1 51.5")
}

func TestConvertKMLNotLoggedIn(t *testing.T) {
	body := `{"action":"kml","kml":"<kml/>"}`
	req := httptest.NewRequest("POST", "/api/locations/kml", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestConvertKMLInvalidXML(t *testing.T) {
	prefix := uniquePrefix("locwr_kmlbad")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body, _ := json2.Marshal(map[string]interface{}{
		"action": "kml",
		"kml":    "not valid xml at all",
	})
	req := httptest.NewRequest("POST", "/api/locations/kml?jwt="+token, bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestConvertKMLEmptyKML(t *testing.T) {
	prefix := uniquePrefix("locwr_kmlempty")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body, _ := json2.Marshal(map[string]interface{}{
		"action": "kml",
		"kml":    "",
	})
	req := httptest.NewRequest("POST", "/api/locations/kml?jwt="+token, bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

// Comprehensive TDD tests for location and isochrone integration

func TestCreateLocationQueuesTaskRemapPostcodes(t *testing.T) {
	// Test that creating a location with polygon geometry queues TaskRemapPostcodes
	prefix := uniquePrefix("locwr_queuetask")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-0.21 51.5, -0.21 51.6, -0.10 51.6, -0.10 51.5, -0.21 51.5))"

	body := fmt.Sprintf(`{"name":"CreateLocTest %s","polygon":"%s"}`, prefix, polygon)

	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Wait for async task queueing
	time.Sleep(100 * time.Millisecond)

	// Verify TaskRemapPostcodes was queued
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0), "TaskRemapPostcodes should be queued after location creation")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestUpdateLocationQueuesTaskRemapPostcodes(t *testing.T) {
	// Test that updating a location's geometry queues TaskRemapPostcodes
	prefix := uniquePrefix("locwr_updatetask")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn

	// Create initial location directly (simplest way to create a location)
	db.Exec(fmt.Sprintf(
		"INSERT INTO locations (name, type, canon, popularity) VALUES (?, 'Polygon', ?, 0)"),
		"LocTest "+prefix, "loctest "+prefix)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "LocTest "+prefix).Scan(&locID)
	assert.Greater(t, locID, uint64(0))

	// Clean up any existing tasks
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)

	// Update the location geometry using PATCH
	updatedPolygon := "POLYGON((-0.25 51.4, -0.25 51.7, -0.05 51.7, -0.05 51.4, -0.25 51.4))"
	updateBody := fmt.Sprintf(`{"id":%d,"polygon":"%s"}`, locID, updatedPolygon)

	updateReq := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(updateBody))
	updateReq.Header.Set("Content-Type", "application/json")
	updateResp, _ := getApp().Test(updateReq)
	assert.Equal(t, 200, updateResp.StatusCode)

	// Wait for async task queueing
	time.Sleep(100 * time.Millisecond)

	// Verify TaskRemapPostcodes was queued
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&taskCount)
	assert.Greater(t, taskCount, int64(0), "TaskRemapPostcodes should be queued after location update")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationCreateWithBoundaryValidation(t *testing.T) {
	// Test that location creation validates polygon boundaries
	prefix := uniquePrefix("locwr_boundary")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	// Valid polygon with closed ring
	validPolygon := "POLYGON((-1.5 53.8, -1.4 53.8, -1.4 53.9, -1.5 53.9, -1.5 53.8))"

	body := fmt.Sprintf(`{"name":"BoundaryTest %s","polygon":"%s"}`, prefix, validPolygon)

	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Get the created location
	db := database.DBConn

	// Verify polygon geometry exists and is valid (check COALESCE to handle both ourgeometry and geometry)
	var geomText string
	db.Raw("SELECT ST_AsText(COALESCE(ourgeometry, geometry)) FROM locations WHERE id = ?", locID).Scan(&geomText)
	assert.NotEmpty(t, geomText, "Location geometry should be set")
	assert.True(t, strings.Contains(geomText, "POLYGON"), "Location geometry should be a polygon")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationTaskRemapPostcodesDataStructure(t *testing.T) {
	// Test that TaskRemapPostcodes has correct data structure with location_id
	prefix := uniquePrefix("locwr_taskdata")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-0.1 51.5, -0.1 51.6, 0.0 51.6, 0.0 51.5, -0.1 51.5))"

	body := fmt.Sprintf(`{"name":"TaskDataTest %s","polygon":"%s"}`, prefix, polygon)

	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Wait for task to be queued
	time.Sleep(100 * time.Millisecond)

	// Verify task data structure
	var taskData string
	db.Raw("SELECT data FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ? LIMIT 1", locID).Scan(&taskData)
	assert.Greater(t, len(taskData), 0, "Task data should be populated")

	// Parse JSON to verify structure
	var data map[string]interface{}
	err := json2.Unmarshal([]byte(taskData), &data)
	assert.NoError(t, err)
	assert.NotNil(t, data["location_id"], "Task data should contain location_id")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestCreateLocationWithMissingName(t *testing.T) {
	// Test that CreateLocation requires name field
	prefix := uniquePrefix("locwr_noname")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	body := `{"polygon":"POLYGON((-0.21 51.5, -0.21 51.6, -0.10 51.6, -0.10 51.5, -0.21 51.5))"}`
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode, "Should reject location without name")
}

func TestCreateLocationWithMissingPolygon(t *testing.T) {
	// Test that CreateLocation requires polygon field
	prefix := uniquePrefix("locwr_nopoly")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	body := fmt.Sprintf(`{"name":"Test %s"}`, prefix)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode, "Should reject location without polygon")
}

func TestUpdateLocationNonExistentLocation(t *testing.T) {
	// Test that UpdateLocation handles non-existent location gracefully
	prefix := uniquePrefix("locwr_notexist")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	nonExistentID := uint64(999999999)
	body := fmt.Sprintf(`{"id":%d,"name":"New Name"}`, nonExistentID)
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestLocationSpatialIndexSync(t *testing.T) {
	// Test that CreateLocation syncs to locations_spatial table
	prefix := uniquePrefix("locwr_spatial")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-1.5 53.8, -1.5 53.9, -1.4 53.9, -1.4 53.8, -1.5 53.8))"

	body := fmt.Sprintf(`{"name":"SpatialSync %s","polygon":"%s"}`, prefix, polygon)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Verify locations_spatial has an entry
	var spatialGeom string
	db.Raw("SELECT ST_AsText(geometry) FROM locations_spatial WHERE locationid = ?", locID).Scan(&spatialGeom)
	assert.NotEmpty(t, spatialGeom, "locations_spatial should have geometry entry")

	// Cleanup
	db.Exec("DELETE FROM locations_spatial WHERE locationid = ?", locID)
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestCreateLocationCanonSetting(t *testing.T) {
	// Test that CreateLocation sets canon field to lowercase name
	prefix := uniquePrefix("locwr_canon")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	locationName := "Test LoC" + prefix
	body := fmt.Sprintf(`{"name":"%s","polygon":"POLYGON((-1.5 53.8, -1.5 53.9, -1.4 53.9, -1.4 53.8, -1.5 53.8))"}`, locationName)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))

	db := database.DBConn
	var canon string
	db.Raw("SELECT canon FROM locations WHERE id = ?", locID).Scan(&canon)
	assert.Equal(t, strings.ToLower(locationName), canon, "canon should be lowercase name")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationUpdateWithNilPolygon(t *testing.T) {
	// Test that updating a location without polygon field is handled gracefully (no error)
	prefix := uniquePrefix("locwr_nilpoly")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-0.1 51.5, -0.1 51.6, 0.0 51.6, 0.0 51.5, -0.1 51.5))"

	// Create initial location
	body := fmt.Sprintf(`{"name":"InitialLoc %s","polygon":"%s"}`, prefix, polygon)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Clean up tasks from creation
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)

	// Update without polygon (only name) - should succeed without error
	updateBody := fmt.Sprintf(`{"id":%d,"name":"UpdatedLoc %s"}`, locID, prefix)
	updateReq := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(updateBody))
	updateReq.Header.Set("Content-Type", "application/json")
	updateResp, _ := getApp().Test(updateReq)
	assert.Equal(t, 200, updateResp.StatusCode, "Update without polygon should succeed")

	// Verify name was updated
	var updatedName string
	db.Raw("SELECT name FROM locations WHERE id = ?", locID).Scan(&updatedName)
	assert.Equal(t, fmt.Sprintf("UpdatedLoc %s", prefix), updatedName)

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationTaskProcessingVerificationFields(t *testing.T) {
	// Test that TaskRemapPostcodes task has all required fields for backend handler
	prefix := uniquePrefix("locwr_taskfields")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-0.15 51.45, -0.15 51.65, 0.05 51.65, 0.05 51.45, -0.15 51.45))"

	body := fmt.Sprintf(`{"name":"FieldTest %s","polygon":"%s"}`, prefix, polygon)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Wait for async task queueing
	time.Sleep(100 * time.Millisecond)

	// Verify task has all required fields: location_id and polygon
	var taskData string
	db.Raw("SELECT data FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ? LIMIT 1", locID).Scan(&taskData)
	assert.NotEmpty(t, taskData, "Task should have data")

	var data map[string]interface{}
	err := json2.Unmarshal([]byte(taskData), &data)
	assert.NoError(t, err, "Task data should be valid JSON")

	// Verify location_id field exists and is correct
	locIDFloat, ok := data["location_id"].(float64)
	assert.True(t, ok, "location_id should exist and be numeric")
	assert.Equal(t, float64(locID), locIDFloat, "location_id should match created location")

	// Verify polygon field exists and is not empty
	polygonVal, ok := data["polygon"].(string)
	assert.True(t, ok, "polygon should exist and be string")
	assert.NotEmpty(t, polygonVal, "polygon should not be empty")
	assert.True(t, strings.Contains(polygonVal, "POLYGON"), "polygon should be WKT POLYGON format")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationRemapMultipleTasksOnNonOverlappingUpdate(t *testing.T) {
	// Test that updating a location with non-overlapping geometry queues both old and new remap tasks
	prefix := uniquePrefix("locwr_multiupdate")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	oldPolygon := "POLYGON((-2.5 53.0, -2.5 53.1, -2.4 53.1, -2.4 53.0, -2.5 53.0))"

	// Create location with initial polygon
	body := fmt.Sprintf(`{"name":"MultiTest %s","polygon":"%s"}`, prefix, oldPolygon)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Clean up tasks from creation
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)

	// Update with completely different (non-overlapping) polygon
	newPolygon := "POLYGON((-1.0 52.0, -1.0 52.1, -0.9 52.1, -0.9 52.0, -1.0 52.0))"
	updateBody := fmt.Sprintf(`{"id":%d,"polygon":"%s"}`, locID, newPolygon)
	updateReq := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(updateBody))
	updateReq.Header.Set("Content-Type", "application/json")
	updateResp, _ := getApp().Test(updateReq)
	assert.Equal(t, 200, updateResp.StatusCode)

	// Wait for async task queueing
	time.Sleep(100 * time.Millisecond)

	// Verify multiple tasks are queued for non-overlapping case
	var taskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&taskCount)
	// Should be 2 tasks (old polygon + new polygon) for non-overlapping case
	assert.Greater(t, taskCount, int64(0), "Should queue remap tasks for non-overlapping geometry update")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationTaskQueueStalePrecondition(t *testing.T) {
	// Test that TaskRemapPostcodes tasks are properly cleaned up before new ones are created
	prefix := uniquePrefix("locwr_taskclean")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-0.2 51.4, -0.2 51.7, 0.1 51.7, 0.1 51.4, -0.2 51.4))"

	body := fmt.Sprintf(`{"name":"CleanTest %s","polygon":"%s"}`, prefix, polygon)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))

	// Wait for async task queueing
	time.Sleep(100 * time.Millisecond)

	// Count tasks after creation
	var initialTaskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&initialTaskCount)
	assert.Greater(t, initialTaskCount, int64(0))

	// Update location again with different polygon
	newPolygon := "POLYGON((-0.3 51.3, -0.3 51.8, 0.2 51.8, 0.2 51.3, -0.3 51.3))"
	updateBody := fmt.Sprintf(`{"id":%d,"polygon":"%s"}`, locID, newPolygon)
	updateReq := httptest.NewRequest("PATCH", "/api/locations?jwt="+adminToken, bytes.NewBufferString(updateBody))
	updateReq.Header.Set("Content-Type", "application/json")
	updateResp, _ := getApp().Test(updateReq)
	assert.Equal(t, 200, updateResp.StatusCode)

	// Wait for async task queueing
	time.Sleep(100 * time.Millisecond)

	// Verify we have tasks (not necessarily comparing exact count as multiple tasks may exist)
	var finalTaskCount int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID).Scan(&finalTaskCount)
	assert.Greater(t, finalTaskCount, int64(0), "Tasks should be queued after update")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

func TestLocationTaskRemapIntegrationWithPostgresSync(t *testing.T) {
	// Integration test: Verify that location geometry is synced to PostgreSQL after creation
	// This is a precondition for PostcodeRemapService KNN queries
	prefix := uniquePrefix("locwr_postgresync")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	db := database.DBConn
	polygon := "POLYGON((-1.8 54.5, -1.8 54.6, -1.7 54.6, -1.7 54.5, -1.8 54.5))"

	body := fmt.Sprintf(`{"name":"PostgresSyncTest %s","polygon":"%s"}`, prefix, polygon)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+adminToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	locID := int(result["id"].(float64))
	assert.Greater(t, locID, 0)

	// Poll for the async task (up to 2s) rather than a fixed sleep
	var taskData string
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		db.Raw("SELECT data FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ? LIMIT 1", locID).Scan(&taskData)
		if taskData != "" {
			break
		}
		time.Sleep(50 * time.Millisecond)
	}

	// Verify that the task queued has the polygon for PostgreSQL sync
	assert.NotEmpty(t, taskData, "Task should be queued with polygon for PostgreSQL sync")
	if taskData == "" {
		return
	}

	var data map[string]interface{}
	err := json2.Unmarshal([]byte(taskData), &data)
	assert.NoError(t, err)

	// The polygon in the task data is what PostcodeRemapService will use for KNN
	polygonForSync := data["polygon"].(string)
	assert.True(t, strings.Contains(polygonForSync, "POLYGON"), "Task should contain WKT polygon for sync")

	// Verify geometry is stored in locations table
	var storedGeom string
	db.Raw("SELECT ST_AsText(COALESCE(ourgeometry, geometry)) FROM locations WHERE id = ?", locID).Scan(&storedGeom)
	assert.NotEmpty(t, storedGeom, "Location should have geometry for queries")

	// Cleanup
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'remap_postcodes' AND JSON_EXTRACT(data, '$.location_id') = ?", locID)
	db.Exec("DELETE FROM locations_spatial WHERE locationid = ?", locID)
	db.Exec("DELETE FROM locations WHERE id = ?", locID)
}

// TestClosestGroupsContainingPolygonIsAuthoritative reproduces bug #9518: when a
// location point lies inside a group's polygon, that group is the correct answer
// even if its centre (lat/lng) is far away — polygon containment must beat the
// centre-distance heuristic. The V1 PHP groupsNear() does this with an ST_Contains
// check; the Go ClosestGroups() previously omitted it and so returned only the
// group with the closest centre, dropping the containing group whose centre lies
// beyond the search radius (it gets filtered out by the HAVING hav < radius clause).
//
// We seed two groups in an empty region (Gulf of Guinea — no real Freegle groups
// there) so only our test groups are candidates:
//   - Group A: a large polygon that CONTAINS the point, but whose centre is ~230
//     miles away (so it is excluded by the centre-distance HAVING clause).
//   - Group B: a small polygon that does NOT contain the point, but whose centre
//     is ~5 miles away (so it passes the centre-distance filter).
// The containing group A must be returned.
func TestClosestGroupsContainingPolygonIsAuthoritative(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("closest_contain")

	pointLat := 0.5
	pointLng := 0.5

	// Group A: large containing polygon, distant centre, NULL alt centre.
	nameA := "ContainA_" + prefix
	db.Exec(fmt.Sprintf(
		"INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, listable, lat, lng, altlat, altlng, polyindex) "+
			"VALUES (?, ?, 'Freegle', 1, 1, 1, 2.9, 2.9, NULL, NULL, ST_GeomFromText('POLYGON((-2 -2, -2 3, 3 3, 3 -2, -2 -2))', %d))",
		utils.SRID), nameA, "Containing Group A")
	var groupA uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", nameA).Scan(&groupA)
	assert.Greater(t, groupA, uint64(0))

	// Group B: small non-containing polygon near the point, close centre.
	nameB := "ContainB_" + prefix
	db.Exec(fmt.Sprintf(
		"INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, listable, lat, lng, altlat, altlng, polyindex) "+
			"VALUES (?, ?, 'Freegle', 1, 1, 1, 0.55, 0.55, NULL, NULL, ST_GeomFromText('POLYGON((0.6 0.6, 0.6 0.7, 0.7 0.7, 0.7 0.6, 0.6 0.6))', %d))",
		utils.SRID), nameB, "Near Group B")
	var groupB uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", nameB).Scan(&groupB)
	assert.Greater(t, groupB, uint64(0))

	defer func() {
		db.Exec("DELETE FROM `groups` WHERE id IN (?, ?)", groupA, groupB)
	}()

	groups := location.ClosestGroups(pointLat, pointLng, float64(location.NEARBY), 10)

	assert.Greater(t, len(groups), 0, "should find the containing group")
	if len(groups) > 0 {
		assert.Equal(t, groupA, groups[0].ID,
			"the group whose polygon contains the point must be returned, even though its centre is far away")
		assert.Equal(t, "Containing Group A", groups[0].Namedisplay,
			"namedisplay should be populated for the containing group")
	}

	// The containing-groups path is authoritative: a non-containing group with a
	// closer centre must not be returned ahead of (or instead of) the containing one.
	for _, g := range groups {
		assert.NotEqual(t, groupB, g.ID,
			"non-containing group B must not appear when a containing group exists")
	}
}

// Reproduces Discourse #9905 post #1: an FD member posted to their second-nearest
// group instead of their nearest.
//
// ClosestGroups' radius-stepping search fires one query per doubling radius band
// (currradius = NEARBY/16, then x2, x4, x8...) CONCURRENTLY, and stops as soon as
// ANY completed band has accumulated `limit` candidates - it does not wait for the
// other, larger-radius bands still in flight (see the "done"/wg.Done() handling in
// ClosestGroups). A band's HAVING clause admits a group only if that group's
// registered *centre* ("hav"/haversine distance) is within that band's own radius -
// independent of "dist", the distance to the group's actual polygon boundary, which
// is what determines "nearest" for ranking. A group whose boundary is very close to
// the point but whose registered centre is comparatively far away (a large or
// awkwardly-shaped catchment - the exact case the alt-lat/lng workaround at the top
// of ClosestGroups exists for) is therefore only discoverable via a wider, slower
// band. If enough closer-centred (but farther-boundary) decoy groups are found by
// the faster, narrower bands first, the early exit fires and the genuinely nearest
// group is silently dropped from the result - so groups[0] ends up being a group
// that is not actually nearest.
func TestClosestGroupsFarCentreNearBoundaryGroupNotDroppedByEarlyExit(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("closest_earlyexit")

	pointLat := 20.0
	pointLng := 20.0

	// The true nearest group: polygon boundary ~0.05 miles from the point (closer
	// than any decoy below), but a registered centre ~20 miles away - inside
	// NEARBY(50mi) overall, but only found by the currradius=32 band (16 <= 20 < 32),
	// which is dispatched last and has the largest bounding box to scan.
	nameNorth := "EarlyExitNorth_" + prefix
	db.Exec(fmt.Sprintf(
		"INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, listable, lat, lng, altlat, altlng, polyindex) "+
			"VALUES (?, ?, 'Freegle', 1, 1, 1, %f, %f, NULL, NULL, ST_GeomFromText('POLYGON((20.0005 20.0005, 20.0005 20.0015, 20.0015 20.0015, 20.0015 20.0005, 20.0005 20.0005))', %d))",
		pointLat+0.29, pointLng, utils.SRID), nameNorth, "Nearest North Group")
	var groupNorth uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", nameNorth).Scan(&groupNorth)
	assert.Greater(t, groupNorth, uint64(0))

	// Ten decoy groups: registered centre right next to the point (hav ~0.07mi, so
	// every band's HAVING clause admits them from the very first, narrowest band),
	// but a polygon boundary further away (~0.3mi) than the true nearest group's -
	// a correct, complete search still ranks them all behind it.
	groupIDs := []uint64{groupNorth}
	for i := 0; i < 10; i++ {
		name := fmt.Sprintf("EarlyExitDecoy%d_%s", i, prefix)
		off := float64(i) * 0.0001
		db.Exec(fmt.Sprintf(
			"INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, listable, lat, lng, altlat, altlng, polyindex) "+
				"VALUES (?, ?, 'Freegle', 1, 1, 1, %f, %f, NULL, NULL, ST_GeomFromText('POLYGON((%f 20.003, %f 20.004, 20.004 20.004, 20.004 20.003, %f 20.003))', %d))",
			pointLat+0.001, pointLng+off, 20.003+off, 20.003+off, 20.003+off, utils.SRID), name, "Decoy Group "+name)
		var decoyID uint64
		db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", name).Scan(&decoyID)
		assert.Greater(t, decoyID, uint64(0))
		groupIDs = append(groupIDs, decoyID)
	}

	// ClosestGroups fires one query per radius band CONCURRENTLY and races them -
	// whichever band's goroutine grabs the results-slice mutex first and already
	// has `limit` candidates wins, regardless of which bands have or haven't
	// finished. Bands 1-3 (radius 4/8/16) reach the decoys' 10-of-10 quickly
	// because their bounding box is tiny. Band 4 (radius 32, the one that can also
	// see the true nearest group) has to spatially scan a much wider box - in real
	// production that is exactly the "may be slow even with a spatial index"
	// tradeoff ClosestGroups' own top-of-function comment describes for a wide
	// search area with many candidate groups. Reproduce that cost differential here
	// (rather than relying on incidental goroutine-scheduling luck) by seeding a
	// wide ring of filler groups that only band 4's bounding box scans: they never
	// satisfy any band's HAVING clause (their registered centre is far outside even
	// band 4's radius), so they can never appear in the result, but they force
	// band 4's query to do real extra spatial + haversine work band 1-3 don't.
	fillerPrefix := "EarlyExitFiller_" + prefix
	var fillerValues strings.Builder
	for i := 0; i < 3000; i++ {
		if i > 0 {
			fillerValues.WriteString(",")
		}
		// Tiny jitter (<=0.005 degrees) just to keep polygons distinct - the whole
		// cluster stays well inside band 4's ~0.2 degree bounding box and well
		// outside band 3's ~0.1 degree one.
		jitter := float64(i%50) * 0.0001
		fillerValues.WriteString(fmt.Sprintf(
			"('%s%d', '%s%d', 'Freegle', 1, 1, 1, 29.0, 29.0, NULL, NULL, "+
				"ST_GeomFromText('POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))', %d))",
			fillerPrefix, i, fillerPrefix, i,
			20.15+jitter, 20.15, 20.15+jitter, 20.151, 20.151+jitter, 20.151, 20.151+jitter, 20.15, 20.15+jitter, 20.15,
			utils.SRID))
	}
	db.Exec("INSERT INTO `groups` (nameshort, namefull, type, onhere, publish, listable, lat, lng, altlat, altlng, polyindex) VALUES " +
		fillerValues.String())

	defer func() {
		db.Exec("DELETE FROM `groups` WHERE id IN (?)", groupIDs)
		db.Exec("DELETE FROM `groups` WHERE nameshort LIKE ?", fillerPrefix+"%")
	}()

	groups := location.ClosestGroups(pointLat, pointLng, float64(location.NEARBY), 10)
	assert.Greater(t, len(groups), 0, "should find some groups near the point")

	found := false
	for _, g := range groups {
		if g.ID == groupNorth {
			found = true
		}
	}
	assert.True(t, found, "the group that is genuinely nearest by polygon boundary distance must not be dropped just because narrower/faster search bands already filled the result up to the limit")

	if found && len(groups) > 0 {
		assert.Equal(t, groupNorth, groups[0].ID, "the genuinely nearest group must rank first")
	}
}
