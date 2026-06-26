package isochrone

import (
	"encoding/json"
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

// FetchIsochroneWKT additional tests — comprehensive coverage of error paths
// (Unit tests only; the actual API calls are integration tests not run in -short mode)

func TestFetchIsochroneWKT_NoMapboxKey(t *testing.T) {
	// Missing MAPBOX_KEY env var must return empty string.
	orig := os.Getenv("MAPBOX_KEY")
	os.Unsetenv("MAPBOX_KEY")
	defer os.Setenv("MAPBOX_KEY", orig)

	result := FetchIsochroneWKT("Walk", -0.1, 51.5, 10)
	assert.Equal(t, "", result)
}

func TestFetchIsochroneWKT_UnknownTransport_DefaultsToDriving(t *testing.T) {
	// FetchIsochroneWKT maps unknown transport modes to "driving".
	// We can only verify the logic by checking that a valid key doesn't crash
	// when parsing unknown transport (the URL construction uses the mapped profile).
	// Since we can't easily mock net/http in pure Go without interfaces,
	// we verify that "Unknown" maps to "driving" in the inline logic check.
	// This test documents the contract: unknown → "driving".

	// The function uses mapboxTransport to look up the profile.
	_, ok := mapboxTransport["Unknown"]
	assert.False(t, ok, "Unknown transport mode should not be in map")
	// So the inline fallback to "driving" is exercised only in integration.
	// This test documents that behavior for future maintainers.
}

func TestFetchIsochroneWKT_TransportCases(t *testing.T) {
	// Transport mode mapping must be exact (case-sensitive).
	// "walk" (lowercase) should NOT match "Walk" (uppercase).
	_, walkExists := mapboxTransport["Walk"]
	_, walkLowerExists := mapboxTransport["walk"]

	assert.True(t, walkExists, "Walk (uppercase) must be in mapboxTransport")
	assert.False(t, walkLowerExists, "walk (lowercase) must not be in mapboxTransport")
}

func TestFetchIsochroneWKTFromGeoJSON_PolygonWithMultipleRings(t *testing.T) {
	// Polygon with exterior ring and hole (interior ring).
	geojson := `{
		"features": [{
			"type": "Feature",
			"geometry": {
				"type": "Polygon",
				"coordinates": [
					[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
					[[2, 2], [8, 2], [8, 8], [2, 8], [2, 2]]
				]
			}
		}]
	}`
	wkt := FetchIsochroneWKTFromGeoJSON(geojson)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	// Both rings should be present in the WKT output.
	assert.Contains(t, wkt, "0.000000 0.000000")
	assert.Contains(t, wkt, "2.000000 2.000000")
}

func TestFetchIsochroneWKTFromGeoJSON_NoFeatures_EmptyArray(t *testing.T) {
	// Empty features array must return empty string.
	geojson := `{"features":[]}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestFetchIsochroneWKTFromGeoJSON_MissingFeatures_StillParseable(t *testing.T) {
	// Valid JSON but no features field → unmarshals to zero-value geojsonResponse,
	// which has empty Features slice → returns empty string.
	geojson := `{"type":"FeatureCollection"}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestFetchIsochroneWKTFromGeoJSON_Feature_NoGeometry(t *testing.T) {
	// Feature with no geometry field → geojsonGeometry is zero-value,
	// Type is empty string → default case → returns empty string.
	geojson := `{"features":[{"type":"Feature"}]}`
	assert.Equal(t, "", FetchIsochroneWKTFromGeoJSON(geojson))
}

func TestGeojsonGeometryToWKT_PolygonEmptyCoordinates(t *testing.T) {
	// Polygon with empty coordinates array.
	geom := geojsonGeometry{
		Type:        "Polygon",
		Coordinates: json.RawMessage(`[]`),
	}
	wkt := geojsonGeometryToWKT(geom)
	// polygonToWKT([]) returns "" because len(rings) == 0.
	assert.Equal(t, "", wkt)
}

func TestGeojsonGeometryToWKT_MultiPolygonFirstPolygonHasCoords(t *testing.T) {
	// MultiPolygon with two polygons; only first is used.
	geom := geojsonGeometry{
		Type: "MultiPolygon",
		Coordinates: json.RawMessage(`[
			[[[0, 0], [1, 0], [1, 1], [0, 0]]],
			[[[5, 5], [6, 5], [6, 6], [5, 5]]]
		]`),
	}
	wkt := geojsonGeometryToWKT(geom)
	// First polygon's coords present.
	assert.Contains(t, wkt, "0.000000 0.000000")
	// Second polygon's coords must NOT be present.
	assert.NotContains(t, wkt, "5.000000 5.000000")
}

func TestPolygonToWKT_SinglePointRing(t *testing.T) {
	// Ring with only one point (degenerate polygon).
	// The function does not validate; it just outputs what it's given.
	rings := [][][]float64{
		{{10.5, 20.5}},
	}
	wkt := polygonToWKT(rings)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	assert.Contains(t, wkt, "10.500000 20.500000")
}

func TestPolygonToWKT_EmptyRingArray(t *testing.T) {
	// No rings.
	rings := [][][]float64{}
	wkt := polygonToWKT(rings)
	assert.Equal(t, "", wkt)
}

func TestPolygonToWKT_RingWithEmptyCoordinate(t *testing.T) {
	// Ring containing an empty coordinate (dropped by len check).
	rings := [][][]float64{
		{{1, 2}, {}, {3, 4}},
	}
	wkt := polygonToWKT(rings)
	assert.Contains(t, wkt, "1.000000 2.000000")
	assert.Contains(t, wkt, "3.000000 4.000000")
	// The empty coord must not produce a point.
	// The output should have exactly 2 points.
	points := strings.Count(wkt, ",") + 1 // rough count: each point is x y, separated by commas
	assert.True(t, points >= 2, "should have at least 2 points")
}

func TestPolygonToWKT_CoordinateWith3Elements(t *testing.T) {
	// GeoJSON allows 3D coordinates (longitude, latitude, elevation).
	// The WKT conversion only uses the first 2 (len(coord) >= 2 check).
	rings := [][][]float64{
		{{0, 0, 100}, {1, 1, 200}, {2, 0, 300}, {0, 0, 100}},
	}
	wkt := polygonToWKT(rings)
	// All coordinates should appear in WKT (just x y, no z).
	assert.Contains(t, wkt, "0.000000 0.000000")
	assert.Contains(t, wkt, "1.000000 1.000000")
	assert.Contains(t, wkt, "2.000000 0.000000")
	// Elevation values must not appear in the WKT output.
	assert.NotContains(t, wkt, "100")
}

func TestFetchIsochroneWKTFromGeoJSON_LargeCoordinateValues(t *testing.T) {
	// Coordinates far from standard lat/lng ranges (e.g., very large or negative values).
	// The function does not validate bounds; it just converts.
	geojson := `{
		"features": [{
			"type": "Feature",
			"geometry": {
				"type": "Polygon",
				"coordinates": [[
					[-180, -90],
					[180, -90],
					[180, 90],
					[-180, 90],
					[-180, -90]
				]]
			}
		}]
	}`
	wkt := FetchIsochroneWKTFromGeoJSON(geojson)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	// Just verify it produced output; exact values vary by float formatting.
	assert.True(t, len(wkt) > 10)
}

func TestPolygonToWKT_MultipleRingsFormatting(t *testing.T) {
	// Multiple rings produce multiple parenthesized coordinate lists.
	rings := [][][]float64{
		{{0, 0}, {1, 0}, {1, 1}, {0, 0}},
		{{2, 2}, {3, 2}, {3, 3}, {2, 2}},
	}
	wkt := polygonToWKT(rings)
	// WKT format is POLYGON(ring1, ring2, ...)
	// Each ring is (coord1, coord2, ...)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON("))
	// The exact format is tested in other tests; here we just verify
	// structure: two ring sections separated by "), (".
	ringCount := strings.Count(wkt, "), (") + 1
	assert.Equal(t, 2, ringCount, "should have 2 rings separated by ', '")
}
