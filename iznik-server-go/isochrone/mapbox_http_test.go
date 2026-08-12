package isochrone

import (
	"errors"
	"net/http"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// FetchIsochroneWKT HTTP paths - mapbox_test.go already covers the pure
// conversion helpers (polygonToWKT, geojsonGeometryToWKT,
// FetchIsochroneWKTFromGeoJSON) and the no-token short-circuit. These tests
// use the shared fakeRoundTripper (defined in routing_test.go) to drive
// FetchIsochroneWKT's own HTTP success/error branches without any network
// access.
// ---------------------------------------------------------------------------

func TestFetchIsochroneWKT_NetworkError(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	withFakeTransport(t, &fakeRoundTripper{err: errors.New("dial tcp: connection refused")})
	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestFetchIsochroneWKT_ReadBodyError(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	withFakeTransport(t, &fakeRoundTripper{resp: &http.Response{
		StatusCode: 200,
		Body:       errReadCloser{},
		Header:     make(http.Header),
	}})
	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestFetchIsochroneWKT_NonOKStatus(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(429, "rate limited")})
	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestFetchIsochroneWKT_NonOKStatusLongBody(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(500, strings.Repeat("y", 1000))})
	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestFetchIsochroneWKT_BadJSON(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(200, "not json")})
	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestFetchIsochroneWKT_NoFeaturesInResponse(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(200, `{"features":[]}`)})
	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestFetchIsochroneWKT_SuccessPolygon(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	body := `{"features":[{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[-0.1,51.5],[-0.2,51.5],[-0.2,51.6],[-0.1,51.5]]]}}]}`
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(200, body)})

	wkt := FetchIsochroneWKT("Walk", -0.1, 51.5, 10)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	assert.Contains(t, wkt, "-0.100000 51.500000")
}

func TestFetchIsochroneWKT_TransportProfileMapping(t *testing.T) {
	tests := []struct{ transport, profile string }{
		{"Walk", "walking"},
		{"Cycle", "cycling"},
		{"Drive", "driving"},
		{"Teleport", "driving"}, // unknown transport falls back to "driving"
	}

	for _, tt := range tests {
		t.Run(tt.transport, func(t *testing.T) {
			withEnv(t, "MAPBOX_KEY", "test-token")
			rt := &fakeRoundTripper{resp: fakeResponse(200, `{"features":[]}`)}
			withFakeTransport(t, rt)

			FetchIsochroneWKT(tt.transport, -0.1, 51.5, 10)
			assert.Contains(t, rt.lastURL, "/mapbox/"+tt.profile+"/")
			assert.Contains(t, rt.lastURL, "access_token=test-token")
		})
	}
}

func TestFetchIsochroneWKT_URLIncludesParams(t *testing.T) {
	withEnv(t, "MAPBOX_KEY", "test-token")
	rt := &fakeRoundTripper{resp: fakeResponse(200, `{"features":[]}`)}
	withFakeTransport(t, rt)

	FetchIsochroneWKT("Walk", -0.1, 51.5, 12)
	assert.Contains(t, rt.lastURL, "contours_minutes=12")
	assert.Contains(t, rt.lastURL, "-0.100000,51.500000")
}
