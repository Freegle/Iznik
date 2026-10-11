package test

import (
	"io"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/isochrone"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// mapboxOnlyTransport answers requests to the Mapbox API with a canned isochrone and
// passes every other request to the transport it wraps. The isochrone package's HTTP
// client uses http.DefaultTransport, so swapping that in a test reaches the Mapbox
// call without any change to the production code, and no request can reach the real
// Mapbox API with a real key.
type mapboxOnlyTransport struct {
	next  http.RoundTripper
	calls atomic.Int32
}

func (m *mapboxOnlyTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	if req.URL.Host != "api.mapbox.com" {
		return m.next.RoundTrip(req)
	}
	m.calls.Add(1)
	body := `{"features":[{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[13.40,52.52],[13.41,52.52],[13.41,52.53],[13.40,52.53],[13.40,52.52]]]}}]}`
	return &http.Response{
		StatusCode: 200,
		Body:       io.NopCloser(strings.NewReader(body)),
		Header:     http.Header{"Content-Type": []string{"application/json"}},
		Request:    req,
	}, nil
}

// TestEnsureIsochroneExistsFallsBackToMapbox covers the Mapbox branch of
// EnsureIsochroneExists: the routing server has no road graph for the point, so it
// answers with an empty polygon, and the polygon comes from Mapbox instead. Every
// other EnsureIsochroneExists test uses a point the routing server can answer, so
// without this one the fallback, and the source it records, would be untested.
func TestEnsureIsochroneExistsFallsBackToMapbox(t *testing.T) {
	prefix := uniquePrefix("IsoMapbox")
	db := database.DBConn

	fake := &mapboxOnlyTransport{next: http.DefaultTransport}
	original := http.DefaultTransport
	http.DefaultTransport = fake
	t.Cleanup(func() { http.DefaultTransport = original })
	t.Setenv("MAPBOX_KEY", "test-token")

	// Berlin is off every road graph we load (the whole UK locally, the Bristol
	// extract in CI), so the routing server cannot answer for it.
	db.Exec("INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', 52.52, 13.405, ST_GeomFromText('POINT(13.405 52.52)', ?))", prefix+"_loc", utils.SRID)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_loc").Scan(&locID)
	assert.Greater(t, locID, uint64(0))
	t.Cleanup(func() {
		db.Exec("DELETE FROM isochrones WHERE locationid = ?", locID)
		db.Exec("DELETE FROM locations WHERE id = ?", locID)
	})

	isoID := isochrone.EnsureIsochroneExists(locID, "Walk", 5)
	assert.Greater(t, isoID, uint64(0), "the Mapbox fallback should produce an isochrone")
	assert.Equal(t, int32(1), fake.calls.Load(), "Mapbox should be asked exactly once")

	var row struct {
		Source string
		Kind   string
	}
	db.Raw("SELECT COALESCE(source, '') AS source, ST_GeometryType(polygon) AS kind FROM isochrones WHERE id = ?", isoID).Scan(&row)
	assert.Equal(t, "Mapbox", row.Source)
	assert.Equal(t, "POLYGON", strings.ToUpper(row.Kind), "the stored shape should be Mapbox's polygon, not the location point")
}
