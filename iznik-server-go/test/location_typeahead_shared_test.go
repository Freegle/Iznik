package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/location"
	"github.com/stretchr/testify/assert"
)

// TestTypeaheadMatchesSearchLocations verifies that GET /location/typeahead?q=
// and GET /locations?typeahead= return the same location IDs for the same prefix.
// Both handlers now share typeaheadQuery() so any divergence in the SQL or
// AreaInfo loop would be immediately visible here.
func TestTypeaheadMatchesSearchLocations(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ta_shared")

	// Seed a Postcode location whose name contains a space (so the shared
	// AND l1.name LIKE '% %' clause matches it).  Use a unique prefix so
	// only our seeded row appears in results for this query.
	locName := fmt.Sprintf("ZZ %s", prefix[len(prefix)-5:])
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Postcode', 55.9533, -3.1883)", locName)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", locName).Scan(&locID)
	assert.Greater(t, locID, uint64(0))
	defer db.Exec("DELETE FROM locations WHERE id = ?", locID)

	// The prefix we query: "ZZ " covers only locations starting with "ZZ " so
	// just our seeded row will match in a clean environment.
	q := locName[:3] // "ZZ "

	// --- Typeahead endpoint (GET /location/typeahead?q=...) ---
	// groupsnear is always true for this handler; pconly defaults true.
	resp1, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/location/typeahead?q="+url.QueryEscape(q)+"&limit=10", nil))
	assert.Equal(t, 200, resp1.StatusCode)

	var taLocs []location.Location
	json2.Unmarshal(rsp(resp1), &taLocs)

	// --- SearchLocations endpoint (GET /locations?typeahead=...) ---
	resp2, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/locations?typeahead="+url.QueryEscape(q)+"&groupsnear=false&limit=10", nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var slResult struct {
		Locations []location.Location `json:"locations"`
	}
	json2.Unmarshal(rsp(resp2), &slResult)

	// Both must find the seeded location.
	assert.Greater(t, len(taLocs), 0, "Typeahead must find the seeded location")
	assert.Greater(t, len(slResult.Locations), 0, "SearchLocations must find the seeded location")

	taIDs := make(map[uint64]struct{})
	for _, l := range taLocs {
		taIDs[l.ID] = struct{}{}
	}
	slIDs := make(map[uint64]struct{})
	for _, l := range slResult.Locations {
		slIDs[l.ID] = struct{}{}
	}

	_, inTA := taIDs[locID]
	assert.True(t, inTA, "seeded location must appear in Typeahead results")

	_, inSL := slIDs[locID]
	assert.True(t, inSL, "seeded location must appear in SearchLocations results")

	// IDs present in SearchLocations must also be present in Typeahead (same SQL).
	// (Typeahead caps at 10; SearchLocations caps at 100.  We only seeded one row
	// and query a unique prefix so the sets should be identical.)
	for id := range slIDs {
		_, ok := taIDs[id]
		assert.True(t, ok, "location id %d from SearchLocations must also be in Typeahead", id)
	}
}

// TestTypeaheadWhitespaceNormalisation verifies that the whitespace-normalisation
// fix works: the old code used strings.ReplaceAll(q, `\s`, "") which only stripped
// the literal two-character sequence backslash-s and was a no-op for any real query.
// The fixed code uses strings.Fields so leading/trailing/internal extra spaces are
// collapsed before the LIKE prefix is formed.
func TestTypeaheadWhitespaceNormalisation(t *testing.T) {
	// "EH3" is a well-populated postcode area in the test DB.
	// A clean prefix returns N results.
	resp1, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/typeahead?q=EH3&limit=5", nil))
	assert.Equal(t, 200, resp1.StatusCode)

	var clean []location.Location
	json2.Unmarshal(rsp(resp1), &clean)
	assert.Greater(t, len(clean), 0, "clean prefix 'EH3' must find results")

	// Same prefix with leading and trailing spaces must return the same set.
	resp2, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/location/typeahead?q="+url.QueryEscape("  EH3  ")+"&limit=5", nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var padded []location.Location
	json2.Unmarshal(rsp(resp2), &padded)
	assert.Equal(t, len(clean), len(padded),
		"leading/trailing spaces must not change result count")

	cleanIDs := make(map[uint64]struct{})
	for _, l := range clean {
		cleanIDs[l.ID] = struct{}{}
	}
	for _, l := range padded {
		_, ok := cleanIDs[l.ID]
		assert.True(t, ok, "padded query must return the same location IDs as the clean query")
	}

	// An all-whitespace query must return 404 (normalises to "").
	resp3, _ := getApp().Test(httptest.NewRequest("GET",
		"/api/location/typeahead?q="+url.QueryEscape("   ")+"&limit=5", nil))
	assert.Equal(t, 404, resp3.StatusCode,
		"all-whitespace query must return 404 after normalisation to empty string")
}
