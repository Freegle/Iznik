package driving

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/roadblur"
)

func TestFetchDriveMetricsChunksAtRoutingCap(t *testing.T) {
	roadblur.ResetRoutingBreaker()
	// The routing server rejects >1000 targets per request; a 2,500-target
	// feed must arrive as three requests and still map every id.
	var sizes []int
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var req struct {
			Targets []Target `json:"targets"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if len(req.Targets) > 1000 {
			w.WriteHeader(http.StatusBadRequest)
			return
		}
		sizes = append(sizes, len(req.Targets))
		results := make([]DriveResult, len(req.Targets))
		for i, tg := range req.Targets {
			m := float64(tg.ID)
			results[i] = DriveResult{ID: tg.ID, Mins: &m, Miles: &m}
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"results": results})
	}))
	defer srv.Close()

	targets := make([]Target, 2500)
	for i := range targets {
		targets[i] = Target{ID: int64(i), Lat: 51.0, Lng: -2.0}
	}
	res := FetchDriveMetrics(srv.URL, 51.45, -2.58, targets)
	if len(res) != 2500 {
		t.Fatalf("expected 2500 results, got %d", len(res))
	}
	if len(sizes) != 3 || sizes[0] != 1000 || sizes[1] != 1000 || sizes[2] != 500 {
		t.Fatalf("expected chunks 1000/1000/500, got %v", sizes)
	}
	if res[2499].ID != 2499 || res[2499].Mins == nil || *res[2499].Mins != 2499 {
		t.Fatalf("last result mismapped: %+v", res[2499])
	}
}

func TestFetchDriveMetrics(t *testing.T) {
	roadblur.ResetRoutingBreaker()
	// Stub routing server: echoes one reachable and one unreachable target.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/drive-metrics" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		var req struct {
			Lat     float64  `json:"lat"`
			Lng     float64  `json:"lng"`
			Targets []Target `json:"targets"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			t.Errorf("decode: %v", err)
		}
		if len(req.Targets) != 2 || req.Lat != 51.45 {
			t.Errorf("unexpected request: %+v", req)
		}
		mins, miles := 12.5, 4.2
		_ = json.NewEncoder(w).Encode(map[string]any{
			"results": []DriveResult{
				{ID: 1, Mins: &mins, Miles: &miles},
				{ID: 2},
			},
		})
	}))
	defer srv.Close()

	res := FetchDriveMetrics(srv.URL, 51.45, -2.58, []Target{{ID: 1, Lat: 51.46, Lng: -2.59}, {ID: 2, Lat: 51.3, Lng: -2.3}})
	if len(res) != 2 {
		t.Fatalf("got %d results", len(res))
	}
	if res[0].Mins == nil || *res[0].Mins != 12.5 || res[0].Miles == nil || *res[0].Miles != 4.2 {
		t.Fatalf("result 0 wrong: %+v", res[0])
	}
	if res[1].Mins != nil || res[1].Miles != nil {
		t.Fatalf("result 1 should be nulls: %+v", res[1])
	}
}

func TestFetchDriveMetricsFailSoft(t *testing.T) {
	roadblur.ResetRoutingBreaker()
	defer roadblur.ResetRoutingBreaker()
	// 503 (engine unconfigured) and connection failure both return nil.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	if res := FetchDriveMetrics(srv.URL, 51.45, -2.58, []Target{{ID: 1, Lat: 51.46, Lng: -2.59}}); res != nil {
		t.Fatalf("503 should return nil, got %+v", res)
	}
	srv.Close()
	if res := FetchDriveMetrics(srv.URL, 51.45, -2.58, []Target{{ID: 1, Lat: 51.46, Lng: -2.59}}); res != nil {
		t.Fatalf("dead server should return nil, got %+v", res)
	}
}
