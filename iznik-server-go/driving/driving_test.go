package driving

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestFetchDriveMetrics(t *testing.T) {
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
