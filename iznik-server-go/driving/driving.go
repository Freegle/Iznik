package driving

// Road drive time and distance from the logged-in member's approximate home
// to a batch of points, answered by the routing server's reach engine
// (POST /v1/drive-metrics). This is what lets the site show "N miles by road"
// instead of crow-flies on post lists, chat headers and profiles.
//
// Fail-soft by design: if the routing server is down, slow, or the reach
// engine is not configured there (503), the response is an empty result set
// and the client falls back to crow-flies — never an error the user sees.

import (
	"bytes"
	"encoding/json"
	"net/http"
	"time"

	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// Short timeout: this decorates a UI that already has a crow-flies answer.
var routingClient = &http.Client{Timeout: 3 * time.Second}

type Target struct {
	ID  int64   `json:"id"`
	Lat float64 `json:"lat"`
	Lng float64 `json:"lng"`
}

type driveDistanceRequest struct {
	Targets []Target `json:"targets"`
}

type DriveResult struct {
	ID    int64    `json:"id"`
	Mins  *float64 `json:"mins"`
	Miles *float64 `json:"miles"`
}

// FetchDriveMetrics asks the routing server for road time/distance from one
// origin to the targets. Any failure returns nil for that chunk (fall back to
// crow-flies). Chunked at the routing server's 1000-target request cap - a
// feed can be far bigger (a heavy-membership mygroups view is thousands of
// posts), and an uncapped request would 400 and lose road metrics for ALL of
// them. Skips straight to nil while the shared routing circuit breaker is
// open, so a down routing server costs no added latency here.
func FetchDriveMetrics(routingURL string, lat, lng float64, targets []Target) []DriveResult {
	const chunkCap = 1000
	var out []DriveResult
	for start := 0; start < len(targets); start += chunkCap {
		end := start + chunkCap
		if end > len(targets) {
			end = len(targets)
		}
		if res := fetchDriveMetricsChunk(routingURL, lat, lng, targets[start:end]); res != nil {
			out = append(out, res...)
		}
	}
	return out
}

func fetchDriveMetricsChunk(routingURL string, lat, lng float64, targets []Target) []DriveResult {
	if len(targets) == 0 || !roadblur.RoutingHealthy() {
		return nil
	}
	body, err := json.Marshal(fiber.Map{
		"lat":         lat,
		"lng":         lng,
		"max_minutes": 120,
		"targets":     targets,
	})
	if err != nil {
		return nil
	}
	resp, err := routingClient.Post(routingURL+"/v1/drive-metrics", "application/json", bytes.NewReader(body))
	if err != nil {
		roadblur.MarkRoutingFailure()
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		roadblur.MarkRoutingFailure()
		return nil
	}
	var parsed struct {
		Results []DriveResult `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		return nil
	}
	return parsed.Results
}

// DriveDistance handles POST /drivedistance.
//
// @Summary Road drive time and distance to a batch of points
// @Description Returns road drive minutes and miles from the logged-in
// @Description member's approximate location to each target point, computed
// @Description by the routing server's reach engine. Targets the engine
// @Description cannot reach within 2 hours, or when the engine is not
// @Description available, come back with null values — callers show
// @Description crow-flies distance instead.
// @Tags location
// @Param body body driveDistanceRequest true "Target points (max 100)"
// @Success 200 {object} object
// @Router /drivedistance [post]
func DriveDistance(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	var req driveDistanceRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid body")
	}
	if len(req.Targets) == 0 || len(req.Targets) > 100 {
		return fiber.NewError(fiber.StatusBadRequest, "1-100 targets required")
	}
	latlng := user.GetLatLng(myid)
	if latlng.Lat == 0 && latlng.Lng == 0 {
		return c.JSON(fiber.Map{"results": []DriveResult{}})
	}
	results := FetchDriveMetrics(roadblur.RoutingURL(), float64(latlng.Lat), float64(latlng.Lng), req.Targets)
	if results == nil {
		results = []DriveResult{}
	}
	return c.JSON(fiber.Map{"results": results})
}
