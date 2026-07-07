package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/location"
	"github.com/stretchr/testify/assert"
)

// TestLocationResolve covers /location/resolve, which turns a place name the item search
// can't satisfy (a county/town/postcode) into a location so the UI can offer "search near
// <place>" instead of a dead-end. It must return the SINGLE best match, preferring an area
// Polygon over lesser geometry types for the same name.
func TestLocationResolve(t *testing.T) {
	prefix := uniquePrefix("resolve")
	name := prefix + " Shire"
	db := database.DBConn

	// Same name as both a lesser Road and the preferred area Polygon — Resolve must pick
	// the Polygon (the useful area centroid), not the Road.
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Road', 10.000000, 20.000000)", name)
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Polygon', 51.840000, -0.270000)", name)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/location/resolve?name="+url.QueryEscape(name), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var loc location.Location
	json2.Unmarshal(rsp(resp), &loc)
	assert.Equal(t, name, loc.Name)
	assert.Equal(t, "Polygon", loc.Type) // preferred over the Road of the same name
	assert.InDelta(t, 51.84, loc.Lat, 0.01)
	assert.InDelta(t, -0.27, loc.Lng, 0.01)

	// A name that is not a known place -> 404 (so the caller shows nothing).
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/location/resolve?name="+url.QueryEscape(prefix+" Nowhere"), nil))
	assert.Equal(t, 404, resp.StatusCode)

	// Missing name -> 400.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/location/resolve", nil))
	assert.Equal(t, 400, resp.StatusCode)
}
