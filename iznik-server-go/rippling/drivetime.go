package rippling

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"time"
)

// driveTimeHTTPClient is deliberately short-timeout: this sits on a read path that a
// member is waiting on, and the budget-bounded search it calls answers in tens of
// milliseconds on the UK graph. If the routing server is slow or down we would rather
// show no estimate than hold the whole response.
var driveTimeHTTPClient = &http.Client{Timeout: 2 * time.Second}

// routingInternalURL is the routing server's INTERNAL, no-auth port - the one trusted
// backend services use (iznik-routing-go's startServer: 8194 internal, 8196 external
// with JWT and moderators only). Deliberately not SPATIAL_SERVER_URL, which points at
// the external port: an unauthenticated call there answers "authentication required"
// with a 401, which this would then report as "no estimate" for every member forever.
// Mirrors town.routingEvalURL() and iznik-batch's ReachService.
func routingInternalURL() string {
	if u := os.Getenv("ROUTING_EVAL_URL"); u != "" {
		return u
	}
	if u := os.Getenv("SPATIAL_KNN_URL"); u != "" {
		return u
	}

	return "http://spatial:8194"
}

// DriveTime is the road time from a post's ripple origin to a member.
type DriveTime struct {
	// Minutes is only meaningful when Reachable is true.
	Minutes float64
	// Reachable is false when the member is beyond maxMinutes by road. That is a real
	// answer, not a failure: it means no tick of the post's schedule can ever cover
	// them, so their reply waits for the reach to finish instead.
	Reachable bool
}

type driveTimeResponse struct {
	Reachable bool    `json:"reachable"`
	DriveMin  float64 `json:"drive_min"`
}

// FetchDriveTime asks the routing server how long it takes to drive from the post's
// ripple origin to the member. Returns ok=false when the question could not be
// answered at all (no routing server configured, timeout, non-200), which callers
// treat as "no estimate" - distinct from a definite "not reachable within the budget".
//
// maxMinutes should be the post's own final tick budget: the search cost scales with
// it (about 40ms at 30 minutes, 300ms at 60 on the UK graph), and anything beyond the
// post's widest reach is a distance we have no use for.
func FetchDriveTime(fromLat, fromLng, toLat, toLng, maxMinutes float64) (DriveTime, bool) {
	base := routingInternalURL()

	url := fmt.Sprintf(
		"%s/v1/drive-time?lat=%f&lng=%f&tolat=%f&tolng=%f&max_minutes=%f&mode=drive",
		base, fromLat, fromLng, toLat, toLng, maxMinutes,
	)

	resp, err := driveTimeHTTPClient.Get(url)
	if err != nil {
		log.Printf("rippling: drive-time fetch failed: %v", err)
		return DriveTime{}, false
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Printf("rippling: drive-time read failed: %v", err)
		return DriveTime{}, false
	}

	if resp.StatusCode != http.StatusOK {
		log.Printf("rippling: drive-time HTTP %d", resp.StatusCode)
		return DriveTime{}, false
	}

	var r driveTimeResponse
	if err := json.Unmarshal(body, &r); err != nil {
		log.Printf("rippling: drive-time JSON parse failed: %v", err)
		return DriveTime{}, false
	}

	return DriveTime{Minutes: r.DriveMin, Reachable: r.Reachable}, true
}
