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
// Returns empty string on failure, and the caller then falls back to Mapbox.
//
// ROUTING_EVAL_URL is the routing container's INTERNAL, no-auth port (8194). It is not
// interchangeable with the other two spatial env vars, and getting this wrong is silent:
//
//	SPATIAL_SERVER_URL  http://spatial:8196       same container, EXTERNAL port, JWT + mod only
//	SPATIAL_KNN_URL     http://spatial-knn:8194   a different container (KNN), no /v1/isochrone
//
// This read SPATIAL_SERVER_URL, so every call answered 401, returned "" here, and sent the
// caller to Mapbox instead - paying a third party for isochrones our own router serves free,
// with nothing user-visible to give it away. See the warning at docker-compose.yml:790.
func FetchIsochroneWKTFromRoutingServer(transport string, lat, lng float64, minutes int) string {
	base := os.Getenv("ROUTING_EVAL_URL")
	if base == "" {
		// ROUTING_SERVER_URL is kept for backward compat with deployments that set it.
		base = os.Getenv("ROUTING_SERVER_URL")
	}
	if base == "" {
		// Unset still means "skip the routing server and use Mapbox", as before. Not
		// defaulted to spatial:8194: that would take away the ability to turn the
		// routing server off, and the client's timeout is 60s, so a host that hangs
		// rather than refusing would stall every isochrone before falling back.
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
