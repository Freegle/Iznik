package roadblur

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
)

func stubBlurServer(t *testing.T, calls *int) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/blur-batch" {
			t.Errorf("unexpected path %s", r.URL.Path)
		}
		*calls++
		var req struct {
			Metres float64     `json:"metres"`
			Points []blurPoint `json:"points"`
		}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			t.Errorf("decode: %v", err)
		}
		type res struct {
			ID    int64   `json:"id"`
			Lat   float64 `json:"lat"`
			Lng   float64 `json:"lng"`
			Roadm float64 `json:"roadm"`
		}
		out := []res{}
		for _, p := range req.Points {
			// Deterministic stub: shift east by 0.001, road metres 300.
			out = append(out, res{ID: p.ID, Lat: p.Lat, Lng: p.Lng + 0.001, Roadm: 300})
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"results": out})
	}))
}

func TestRoadBlurCachedAndBatched(t *testing.T) {
	resetBlurForTest()
	calls := 0
	srv := stubBlurServer(t, &calls)
	defer srv.Close()
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	// Prewarm a list (with a duplicate and a null island): one call.
	RoadBlurPrewarm([][2]float64{{51.45, -2.58}, {51.46, -2.59}, {51.45, -2.58}, {0, 0}}, 400)
	if calls != 1 {
		t.Fatalf("prewarm made %d calls, want 1", calls)
	}
	// Cache hits: no further calls.
	la, ln := RoadBlur(51.45, -2.58, 400)
	if calls != 1 {
		t.Fatalf("cached RoadBlur made a call")
	}
	if la != 51.45 || ln != -2.58+0.001 {
		t.Fatalf("unexpected blurred point %f,%f", la, ln)
	}
	// Null island passes through.
	if za, zn := RoadBlur(0, 0, 400); za != 0 || zn != 0 {
		t.Fatalf("null island must pass through, got %f,%f", za, zn)
	}
	// New point: single-point call.
	RoadBlur(52.0, -1.0, 400)
	if calls != 2 {
		t.Fatalf("miss should cost one call, got %d", calls)
	}
}

func TestRoadBlurFallsBackToCircular(t *testing.T) {
	resetBlurForTest()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	defer os.Unsetenv("ROUTING_EVAL_URL")

	wantLat, wantLng := utils.Blur(51.45, -2.58, 400)
	la, ln := RoadBlur(51.45, -2.58, 400)
	if la != wantLat || ln != wantLng {
		t.Fatalf("fallback should equal utils.Blur: got %f,%f want %f,%f", la, ln, wantLat, wantLng)
	}
	srv.Close()

	// Dead server: same circular fallback.
	la, ln = RoadBlur(51.45, -2.58, 400)
	if la != wantLat || ln != wantLng {
		t.Fatalf("dead-server fallback should equal utils.Blur")
	}

	// The failure opened the circuit breaker: within the cooldown, even a
	// now-working server is NOT retried - a down routing server must cost
	// hot requests zero added latency, not one timeout per call.
	calls := 0
	srv2 := stubBlurServer(t, &calls)
	defer srv2.Close()
	os.Setenv("ROUTING_EVAL_URL", srv2.URL)
	la, ln = RoadBlur(51.45, -2.58, 400)
	if calls != 0 || la != wantLat || ln != wantLng {
		t.Fatalf("breaker open: expected circular fallback with no call, calls=%d", calls)
	}

	// After the cooldown (reset here), the working server is used again and
	// nothing about the failure was cached.
	ResetRoutingBreaker()
	la, ln = RoadBlur(51.45, -2.58, 400)
	if calls != 1 || ln != -2.58+0.001 {
		t.Fatalf("recovery should retry road-aware: calls=%d point %f,%f", calls, la, ln)
	}
}
