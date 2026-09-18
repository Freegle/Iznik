package test

import (
	json2 "encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
)

// Regression test for Discourse #10091 post 1: Browse "Nearby" with the distance slider at
// its maximum found nothing, while "All my communities" / group view showed posts for the
// same viewer and location.
//
// Root cause: the nearby feed's row-drop filter (isochrone/message.go's Messages handler)
// checked only crow-flies miles (resolveMaxDistance) against each candidate's blurred
// Haversine distance, never resolveMaxMinutes or the candidate's own routing-derived
// Roadmins - despite candDriveMetrics already stamping Roadmins on every reach-arm row, and
// despite the filter's own comment claiming parity with nearbyCount's countWithinBudget,
// which IS minutes-first. A band-capped member's saved settings.browseMaxDistance is a real,
// finite number (not the unlimited sentinel), so a post that is well within their saved
// drive-time budget but whose straight-line distance exceeds that crow figure (a river, an
// estuary, a motorway detour) was dropped by Nearby while message/groups.go's Groups() -
// which serves both group view and all-communities view and applies no server-side distance
// filter at all - kept showing it.
func TestNearbyFeedHonoursMinutesBudgetBeyondCrowDistance(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("nearbyminutes")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	CreateTestMembership(t, posterID, group, "Member")

	// The candidate post sits ~69 crow-flies miles from the viewer (52.5,-0.1 vs the
	// viewer's 51.5,-0.1) - well beyond the 10-mile budget set below - but the stubbed
	// routing engine answers it as 20 minutes away, within the viewer's 45-minute budget.
	msgid := CreateTestMessage(t, posterID, group, "OFFER: nearby minutes budget (nearbyminutes) "+prefix, 52.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid = ?", msgid)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgid)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgid)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msgid)

	// The reach polygon must cover the VIEWER's location for the containment probe; it does
	// not need to cover the candidate's own point.
	wkt := "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 52.6, -0.2 52.6, -0.2 51.4))"
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 52.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('"+wkt+"', 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", msgid, mustRasterize(t, wkt))

	stubReachIndexFromDB(t, false)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), "+
		"'$.browseView', 'nearby', "+
		"'$.mylocation', JSON_OBJECT('lat', 51.5, 'lng', -0.1), "+
		"'$.browseMaxDistance', 10, "+
		"'$.browseMaxMinutes', 45) WHERE id = ?", viewerID)

	// Stub the routing server candDriveMetrics reaches (driving.FetchDriveMetrics ->
	// roadblur.RoutingURL, the same env var roadblur's own blur calls use). Blur requests
	// (/v1/blur-batch) 404 here, which falls back to the classic circular blur - still far
	// from the viewer, still deterministic - without opening the shared circuit breaker:
	// only a >=500 response does that (roadblur.MarkRoutingFailureFor).
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/drive-metrics" {
			http.NotFound(w, r)
			return
		}
		var req struct {
			Targets []struct {
				ID int64 `json:"id"`
			} `json:"targets"`
		}
		json2.NewDecoder(r.Body).Decode(&req)
		mins, miles := 20.0, 15.0
		results := make([]map[string]any, 0, len(req.Targets))
		for _, tg := range req.Targets {
			results = append(results, map[string]any{"id": tg.ID, "mins": mins, "miles": miles})
		}
		w.Header().Set("Content-Type", "application/json")
		json2.NewEncoder(w).Encode(map[string]any{"results": results})
	}))
	defer srv.Close()
	t.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()
	t.Cleanup(roadblur.ResetRoutingBreaker)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)

	found := false
	for _, m := range msgs {
		if m.ID == msgid {
			found = true
		}
	}
	assert.True(t, found, "a post within the member's drive-minutes budget stays on Nearby even though it exceeds their crow-flies distance limit")
}
