package isochrone

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"os"
	"strings"
)

// mapboxTransport maps our transport types to Mapbox profile names.
var mapboxTransport = map[string]string{
	"Walk":  "walking",
	"Cycle": "cycling",
	"Drive": "driving",
}

// geojsonGeometry represents the geometry portion of a GeoJSON feature.
type geojsonGeometry struct {
	Type        string          `json:"type"`
	Coordinates json.RawMessage `json:"coordinates"`
}

// geojsonFeature represents a single GeoJSON feature.
type geojsonFeature struct {
	Type     string          `json:"type"`
	Geometry geojsonGeometry `json:"geometry"`
}

// geojsonResponse represents the Mapbox isochrone API response.
type geojsonResponse struct {
	Features []geojsonFeature `json:"features"`
}

// FetchIsochroneWKT calls the Mapbox Isochrone API and returns a WKT POLYGON string.
// Returns empty string on failure (caller should fall back to location geometry).
func FetchIsochroneWKT(transport string, lng, lat float64, minutes int) string {
	token := os.Getenv("MAPBOX_KEY")
	if token == "" {
		log.Printf("MAPBOX_KEY not set, cannot fetch isochrone polygon")
		return ""
	}

	profile, ok := mapboxTransport[transport]
	if !ok {
		profile = "driving"
	}

	url := fmt.Sprintf(
		"https://api.mapbox.com/isochrone/v1/mapbox/%s/%f,%f.json?polygons=true&contours_minutes=%d&access_token=%s",
		profile, lng, lat, minutes, token,
	)

	resp, err := isochroneHTTPClient.Get(url)
	if err != nil {
		log.Printf("Mapbox isochrone fetch failed: %v", err)
		return ""
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Printf("Mapbox isochrone read failed: %v", err)
		return ""
	}

	if resp.StatusCode != 200 {
		log.Printf("Mapbox isochrone HTTP %d: %s", resp.StatusCode, string(body[:min(len(body), 500)]))
		return ""
	}

	var geoResp geojsonResponse
	if err := json.Unmarshal(body, &geoResp); err != nil {
		log.Printf("Mapbox isochrone JSON parse failed: %v", err)
		return ""
	}

	if len(geoResp.Features) == 0 {
		log.Printf("Mapbox isochrone returned no features")
		return ""
	}

	// Convert the first feature's geometry to WKT.
	geom := geoResp.Features[0].Geometry
	return geojsonGeometryToWKT(geom)
}

// FetchIsochroneWKTFromGeoJSON parses a GeoJSON FeatureCollection string and
// returns a WKT POLYGON. Exported for testing the conversion logic without
// calling the Mapbox API.
func FetchIsochroneWKTFromGeoJSON(geojsonStr string) string {
	var geoResp geojsonResponse
	if err := json.Unmarshal([]byte(geojsonStr), &geoResp); err != nil {
		log.Printf("GeoJSON parse failed: %v", err)
		return ""
	}
	if len(geoResp.Features) == 0 {
		return ""
	}
	return geojsonGeometryToWKT(geoResp.Features[0].Geometry)
}

// geojsonGeometryToWKT converts a GeoJSON geometry to WKT format.
// Supports Polygon and MultiPolygon types.
func geojsonGeometryToWKT(geom geojsonGeometry) string {
	switch geom.Type {
	case "Polygon":
		var coords [][][]float64
		if err := json.Unmarshal(geom.Coordinates, &coords); err != nil {
			log.Printf("Failed to parse Polygon coordinates: %v", err)
			return ""
		}
		return polygonToWKT(coords)

	case "MultiPolygon":
		var coords [][][][]float64
		if err := json.Unmarshal(geom.Coordinates, &coords); err != nil {
			log.Printf("Failed to parse MultiPolygon coordinates: %v", err)
			return ""
		}
		if len(coords) > 0 {
			// Use first polygon from multipolygon.
			return polygonToWKT(coords[0])
		}
		return ""

	default:
		log.Printf("Unexpected geometry type from Mapbox: %s", geom.Type)
		return ""
	}
}

// polygonToWKT converts GeoJSON polygon coordinates to WKT POLYGON format.
func polygonToWKT(rings [][][]float64) string {
	if len(rings) == 0 {
		return ""
	}

	var ringStrs []string
	for _, ring := range rings {
		var points []string
		for _, coord := range ring {
			if len(coord) >= 2 {
				points = append(points, fmt.Sprintf("%f %f", coord[0], coord[1]))
			}
		}
		ringStrs = append(ringStrs, "("+strings.Join(points, ", ")+")")
	}

	return "POLYGON(" + strings.Join(ringStrs, ", ") + ")"
}
