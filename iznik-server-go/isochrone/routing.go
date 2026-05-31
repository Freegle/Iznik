package isochrone

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"os"
	"strings"
)

var routingTransport = map[string]string{
	"Walk":  "walk",
	"Cycle": "cycle",
	"Drive": "drive",
}

type routingGeometry struct {
	Type        string         `json:"type"`
	Coordinates [][][2]float64 `json:"coordinates"`
}

type routingPolygon struct {
	Type     string          `json:"type"`
	Geometry routingGeometry `json:"geometry"`
}

type routingResponse struct {
	Walk  routingPolygon `json:"walk"`
	Cycle routingPolygon `json:"cycle"`
	Drive routingPolygon `json:"drive"`
}

// FetchIsochroneWKTFromRoutingServer calls the internal spatial server and
// returns a WKT POLYGON for the requested transport mode.
// Returns empty string on failure or when SPATIAL_SERVER_URL is not set.
func FetchIsochroneWKTFromRoutingServer(transport string, lat, lng float64, minutes int) string {
	// SPATIAL_SERVER_URL is the canonical name; ROUTING_SERVER_URL is kept for backward compat.
	base := os.Getenv("SPATIAL_SERVER_URL")
	if base == "" {
		base = os.Getenv("ROUTING_SERVER_URL")
	}
	if base == "" {
		return ""
	}

	url := fmt.Sprintf("%s/v1/isochrone?lat=%f&lng=%f&minutes=%d", base, lat, lng, minutes)

	resp, err := isochroneHTTPClient.Get(url)
	if err != nil {
		log.Printf("routing server fetch failed: %v", err)
		return ""
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Printf("routing server read failed: %v", err)
		return ""
	}

	if resp.StatusCode != 200 {
		log.Printf("routing server HTTP %d: %s", resp.StatusCode, string(body[:min(len(body), 500)]))
		return ""
	}

	var r routingResponse
	if err := json.Unmarshal(body, &r); err != nil {
		log.Printf("routing server JSON parse failed: %v", err)
		return ""
	}

	key := routingTransport[transport]
	var poly routingPolygon
	switch key {
	case "walk":
		poly = r.Walk
	case "cycle":
		poly = r.Cycle
	default:
		poly = r.Drive
	}

	return routingPolygonToWKT(poly)
}

func routingPolygonToWKT(poly routingPolygon) string {
	if poly.Geometry.Type != "Polygon" || len(poly.Geometry.Coordinates) == 0 {
		return ""
	}
	ring := poly.Geometry.Coordinates[0]
	if len(ring) < 3 {
		return ""
	}
	points := make([]string, len(ring))
	for i, coord := range ring {
		points[i] = fmt.Sprintf("%f %f", coord[0], coord[1])
	}
	return "POLYGON((" + strings.Join(points, ", ") + "))"
}
