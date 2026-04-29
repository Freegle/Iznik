package isochrone

import (
	"os"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestPolygonToWKTSingleRing(t *testing.T) {
	// A minimal closed triangle ring.
	rings := [][][]float64{
		{{0, 0}, {1, 0}, {1, 1}, {0, 0}},
	}
	wkt := polygonToWKT(rings)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	assert.True(t, strings.HasSuffix(wkt, ")"))
	// All four vertices must appear.
	assert.Contains(t, wkt, "0.000000 0.000000")
	assert.Contains(t, wkt, "1.000000 0.000000")
	assert.Contains(t, wkt, "1.000000 1.000000")
}

func TestPolygonToWKTEmpty(t *testing.T) {
	// No rings yields an empty string — caller falls back to location geometry.
	assert.Equal(t, "", polygonToWKT(nil))
	assert.Equal(t, "", polygonToWKT([][][]float64{}))
}

func TestPolygonToWKTDropsShortCoords(t *testing.T) {
	// Coordinates with fewer than 2 values must be silently dropped — Mapbox
	// sometimes returns extra metadata (elevation etc.); we only care about
	// X and Y.
	rings := [][][]float64{
		{{10, 20}, {1}, {30, 40}},
	}
	wkt := polygonToWKT(rings)
	assert.Contains(t, wkt, "10.000000 20.000000")
	assert.Contains(t, wkt, "30.000000 40.000000")
	// The 1-element coord must not appear as a point.
	assert.NotContains(t, wkt, " 1.000000,")
}

func TestFetchIsochroneWKTFromGeoJSONPolygon(t *testing.T) {
	geojson := `{
		"features": [
			{"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[-0.1,51.5],[-0.2,51.5],[-0.2,51.6],[-0.1,51.6],[-0.1,51.5]]]}}
		]
	}`
	wkt := FetchIsochroneWKTFromGeoJSON(geojson)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	assert.Contains(t, wkt, "-0.100000 51.500000")
	assert.Contains(t, wkt, "-0.200000 51.600000")
}

func TestFetchIsochroneWKTFromGeoJSONMultiPolygon(t *testing.T) {
	// MultiPolygon — WKT conversion should use the first polygon only.
	geojson := `{
		"features": [
			{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[
				[[[0,0],[1,0],[1,1],[0,0]]],
				[[[10,10],[11,10],[11,11],[10,10]]]
			]}}
		]
	}`
	wkt := FetchIsochroneWKTFromGeoJSON(geojson)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	// First polygon's coords present.
	assert.Contains(t, wkt, "0.000000 0.000000")
	// Second polygon's coords must NOT be present.
	assert.NotContains(t, wkt, "10.000000 10.000000")
}

func TestFetchIsochroneWKTFromGeoJSONEmptyFeatures(t *testing.T) {
	// No features → empty string.
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(`{"features":[]}`))
}

func TestFetchIsochroneWKTFromGeoJSONUnknownType(t *testing.T) {
	// Unexpected geometry types (e.g. Point) are rejected.
	geojson := `{"features":[{"type":"Feature","geometry":{"type":"Point","coordinates":[0,0]}}]}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestFetchIsochroneWKTFromGeoJSONBadJSON(t *testing.T) {
	// Malformed JSON must return empty (callers fall back).
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(`not json`))
}

func TestFetchIsochroneWKTFromGeoJSONBadPolygonCoords(t *testing.T) {
	// Coordinates shape that can't unmarshal into [][][]float64 must not panic.
	geojson := `{"features":[{"type":"Feature","geometry":{"type":"Polygon","coordinates":"oops"}}]}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestFetchIsochroneWKTFromGeoJSONBadMultiPolygonCoords(t *testing.T) {
	// Same invariant for MultiPolygon.
	geojson := `{"features":[{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":42}}]}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestFetchIsochroneWKTFromGeoJSONMultiPolygonEmpty(t *testing.T) {
	// MultiPolygon with an empty coordinates list.
	geojson := `{"features":[{"type":"Feature","geometry":{"type":"MultiPolygon","coordinates":[]}}]}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestFetchIsochroneWKTNoToken(t *testing.T) {
	// Missing MAPBOX_KEY must short-circuit to empty (caller falls back).
	orig := os.Getenv("MAPBOX_KEY")
	os.Unsetenv("MAPBOX_KEY")
	defer os.Setenv("MAPBOX_KEY", orig)

	assert.Equal(t, "", FetchIsochroneWKT("Walk", -0.1, 51.5, 10))
}

func TestMapboxTransportMap(t *testing.T) {
	// The canonical three transport modes must map to Mapbox profile names.
	assert.Equal(t, "walking", mapboxTransport["Walk"])
	assert.Equal(t, "cycling", mapboxTransport["Cycle"])
	assert.Equal(t, "driving", mapboxTransport["Drive"])
}
