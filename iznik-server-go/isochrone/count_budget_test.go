package isochrone

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
)

// stubRoutingServer answers both endpoints countWithinBudget touches: identity blur (so the
// crow distances stay exactly the Haversine of the candidate coordinates) and drive metrics
// with a fixed minutes answer per target id — nil (absent) ids get no answer, exercising the
// crow-miles fallback branch.
func stubRoutingServer(t *testing.T, minsByID map[int64]float64) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/v1/blur-batch":
			var req struct {
				Points []struct {
					ID  int64   `json:"id"`
					Lat float64 `json:"lat"`
					Lng float64 `json:"lng"`
				} `json:"points"`
			}
			if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
				t.Errorf("blur decode: %v", err)
			}
			type res struct {
				ID    int64   `json:"id"`
				Lat   float64 `json:"lat"`
				Lng   float64 `json:"lng"`
				Roadm float64 `json:"roadm"`
			}
			out := []res{}
			for _, p := range req.Points {
				out = append(out, res{ID: p.ID, Lat: p.Lat, Lng: p.Lng, Roadm: 0})
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"results": out})
		case "/v1/drive-metrics":
			var req struct {
				Targets []struct {
					ID int64 `json:"id"`
				} `json:"targets"`
			}
			if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
				t.Errorf("drive decode: %v", err)
			}
			type res struct {
				ID   int64    `json:"id"`
				Mins *float64 `json:"mins"`
			}
			out := []res{}
			for _, tgt := range req.Targets {
				if m, ok := minsByID[tgt.ID]; ok {
					mm := m
					out = append(out, res{ID: tgt.ID, Mins: &mm})
				}
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"results": out})
		default:
			t.Errorf("unexpected path %s", r.URL.Path)
		}
	}))
}

// The badge must apply the client's slider rule (useDistance.js filterMessagesByDistance):
// minutes-first when the routing engine answers, crow miles only as the fallback. The
// candidates sit ~4.3 miles apart per 0.1 degree of longitude at this latitude, so a 5-mile
// crow limit cleanly separates them.
func TestCountWithinBudgetMinutesFirstCrowFallback(t *testing.T) {
	roadblur.ResetRoutingBreaker()

	// Viewer at origin; candidate 0 ~4.3 crow miles away, candidate 1 ~8.6, candidate 2 ~4.3.
	viewerLat, viewerLng := 53.86, -2.62
	cands := []reachCandidateRow{
		{ID: 100, Lat: viewerLat, Lng: viewerLng - 0.1},
		{ID: 101, Lat: viewerLat, Lng: viewerLng - 0.2},
		{ID: 102, Lat: viewerLat, Lng: viewerLng + 0.1},
	}

	// Candidate 0: 30 driving minutes (over the 25-minute budget) despite being within the
	// crow limit — must NOT count, the exact stuck-badge case (crow 8-11 miles, 30+ minutes
	// by rural road). Candidate 1: 10 minutes despite being OUTSIDE the crow limit — must
	// count, minutes win in both directions. Candidate 2: no routing answer — falls back to
	// the crow rule and counts.
	srv := stubRoutingServer(t, map[int64]float64{0: 30, 1: 10})
	defer srv.Close()
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	got := countWithinBudget(cands, viewerLat, viewerLng, 5, 25)
	assert.Equal(t, uint64(2), got,
		"minutes decide when known (30>25 out, 10<=25 in), crow decides the unanswered one")
}

// Without a minutes budget the behaviour is the original blurred-Haversine crow filter.
func TestCountWithinBudgetNoBudgetIsCrowOnly(t *testing.T) {
	roadblur.ResetRoutingBreaker()

	viewerLat, viewerLng := 53.86, -2.62
	cands := []reachCandidateRow{
		{ID: 100, Lat: viewerLat, Lng: viewerLng - 0.1}, // ~4.3 miles: in
		{ID: 101, Lat: viewerLat, Lng: viewerLng - 0.2}, // ~8.6 miles: out
	}

	// No drive-metrics answers at all; with maxMinutes 0 the endpoint must not even matter.
	srv := stubRoutingServer(t, map[int64]float64{})
	defer srv.Close()
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	got := countWithinBudget(cands, viewerLat, viewerLng, 5, 0)
	assert.Equal(t, uint64(1), got)
}

// A routing outage must degrade every candidate to the crow rule — the badge never errors
// and never goes dark because the engine is down.
func TestCountWithinBudgetRoutingDownFallsBackToCrow(t *testing.T) {
	roadblur.ResetRoutingBreaker()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	viewerLat, viewerLng := 53.86, -2.62
	cands := []reachCandidateRow{
		{ID: 100, Lat: viewerLat, Lng: viewerLng - 0.1}, // ~4.3 miles: in by crow
		{ID: 101, Lat: viewerLat, Lng: viewerLng - 0.2}, // ~8.6 miles: out by crow
	}

	got := countWithinBudget(cands, viewerLat, viewerLng, 5, 25)
	assert.Equal(t, uint64(1), got, "with routing down the crow limit governs, as before the budget existed")

	// Leave the breaker closed for whatever test runs next — this test deliberately failed
	// routing calls.
	roadblur.ResetRoutingBreaker()
}

// candDriveMetrics feeds the relevance score's reference close term and the
// summaries' stamped roadmins/roadmiles, so its answer mapping must be exact:
// index-aligned with the candidate slice, nil where the engine had no answer,
// and zero-coordinate candidates never sent as targets.
func TestCandDriveMetricsIndexAlignment(t *testing.T) {
	roadblur.ResetRoutingBreaker()

	viewerLat, viewerLng := 53.86, -2.62
	cands := []reachCandidateRow{
		{ID: 100, Lat: viewerLat, Lng: viewerLng - 0.1},
		{ID: 101, Lat: 0, Lng: 0}, // no coordinates: never a target
		{ID: 102, Lat: viewerLat, Lng: viewerLng + 0.1},
	}

	// Targets are numbered by candidate INDEX; index 1 is the zero-coord one,
	// so only 0 and 2 reach the engine — and only 0 gets an answer, proving
	// the unanswered slot stays nil rather than inheriting a neighbour's.
	srv := stubRoutingServer(t, map[int64]float64{0: 12})
	defer srv.Close()
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	mins, miles := candDriveMetrics(viewerLat, viewerLng, cands)

	if mins[0] == nil || *mins[0] != 12 {
		t.Fatalf("answered candidate: got %v, want 12 mins", mins[0])
	}
	if mins[1] != nil || miles[1] != nil {
		t.Fatalf("zero-coord candidate must stay nil, got %v/%v", mins[1], miles[1])
	}
	if mins[2] != nil {
		t.Fatalf("unanswered candidate must stay nil, got %v", mins[2])
	}
	if len(mins) != len(cands) || len(miles) != len(cands) {
		t.Fatalf("results not index-aligned: %d/%d for %d cands", len(mins), len(miles), len(cands))
	}
}

// A routing outage yields all-nil metrics — the summaries then score and
// display by the crow fallback, and nothing errors.
func TestCandDriveMetricsRoutingDownAllNil(t *testing.T) {
	roadblur.ResetRoutingBreaker()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	cands := []reachCandidateRow{{ID: 100, Lat: 53.86, Lng: -2.72}}
	mins, miles := candDriveMetrics(53.86, -2.62, cands)
	if mins[0] != nil || miles[0] != nil {
		t.Fatalf("outage must yield nil metrics, got %v/%v", mins[0], miles[0])
	}
	roadblur.ResetRoutingBreaker()
}
