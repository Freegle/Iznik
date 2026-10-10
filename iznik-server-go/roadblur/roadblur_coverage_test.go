package roadblur

import (
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
)

func cleanState(t *testing.T) {
	t.Helper()
	resetBlurForTest()
	ResetRoutingBreaker()
	t.Cleanup(func() {
		resetBlurForTest()
		ResetRoutingBreaker()
	})
}

// echoServer answers every blur-batch point with lng+0.001 and roadm 300,
// counting requests and recording the size of each batch.
func echoServer(t *testing.T, calls *int32, sizes *[]int) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(calls, 1)
		var req struct {
			Points []blurPoint `json:"points"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if sizes != nil {
			*sizes = append(*sizes, len(req.Points))
		}
		out := []map[string]any{}
		for _, p := range req.Points {
			out = append(out, map[string]any{"id": p.ID, "lat": p.Lat, "lng": p.Lng + 0.001, "roadm": 300})
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"results": out})
	}))
}

func TestCovRoutingURL(t *testing.T) {
	tests := []struct {
		name, env, want string
	}{
		{"set", "http://example.test:1234", "http://example.test:1234"},
		{"unset", "", "http://spatial:8194"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			t.Setenv("ROUTING_EVAL_URL", tc.env)
			if got := RoutingURL(); got != tc.want {
				t.Fatalf("got %q want %q", got, tc.want)
			}
		})
	}
}

func TestCovBreakerLifecycle(t *testing.T) {
	cleanState(t)
	if !RoutingHealthy() {
		t.Fatal("should be healthy by default")
	}
	MarkRoutingFailure()
	if RoutingHealthy() {
		t.Fatal("should be unhealthy within cooldown")
	}
	ResetRoutingBreaker()
	if !RoutingHealthy() {
		t.Fatal("should be healthy after reset")
	}
}

func TestCovMarkRoutingFailureForTable(t *testing.T) {
	tests := []struct {
		status int
		opens  bool
	}{
		{400, false}, {401, false}, {404, false},
		{500, true}, {501, false}, {502, true}, {503, false},
	}
	for _, tc := range tests {
		t.Run(fmt.Sprint(tc.status), func(t *testing.T) {
			cleanState(t)
			MarkRoutingFailureFor(tc.status)
			if RoutingHealthy() == tc.opens {
				t.Fatalf("status %d: opens=%v but healthy=%v", tc.status, tc.opens, RoutingHealthy())
			}
		})
	}
}

func TestCovBlurKey(t *testing.T) {
	tests := []struct {
		lat, lng, dist float64
		want           string
	}{
		{51.5, -2.5, 400, "51.500000,-2.500000,400"},
		{51.1234567, -2.9876543, 400.4, "51.123457,-2.987654,400"},
		{0, 0, 0, "0.000000,0.000000,0"},
	}
	for _, tc := range tests {
		if got := blurKey(tc.lat, tc.lng, tc.dist); got != tc.want {
			t.Errorf("blurKey(%v,%v,%v)=%q want %q", tc.lat, tc.lng, tc.dist, got, tc.want)
		}
	}
	if blurKey(1, 2, 3) != blurKey(1, 2, 3) {
		t.Error("blurKey not deterministic")
	}
}

// Eviction at blurCacheCap (200000) is only reachable by filling the cache to
// cap, so it is not exercised here.
func TestCovBlurCacheGetPut(t *testing.T) {
	cleanState(t)
	if _, ok := blurCacheGet("k"); ok {
		t.Fatal("expected miss")
	}
	blurCachePut("k", [2]float64{1, 2})
	v, ok := blurCacheGet("k")
	if !ok || v != [2]float64{1, 2} {
		t.Fatalf("expected hit {1,2}, got %v %v", v, ok)
	}
	blurCachePut("k", [2]float64{9, 9})
	v, _ = blurCacheGet("k")
	if v != [2]float64{1, 2} {
		t.Fatalf("second put must be a no-op, got %v", v)
	}
	if len(blurOrder) != 1 {
		t.Fatalf("order should have one entry, got %d", len(blurOrder))
	}
}

func TestCovFetchBlurBatch(t *testing.T) {
	pts := []blurPoint{{ID: 0, Lat: 51.45, Lng: -2.58}, {ID: 1, Lat: 51.46, Lng: -2.59}}

	serve := func(status int, body string) *httptest.Server {
		return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(status)
			_, _ = w.Write([]byte(body))
		}))
	}

	t.Run("empty pts", func(t *testing.T) {
		cleanState(t)
		if got := fetchBlurBatch("http://127.0.0.1:1", 400, nil); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
	})

	t.Run("marshal error", func(t *testing.T) {
		cleanState(t)
		if got := fetchBlurBatch("http://127.0.0.1:1", math.NaN(), pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
		if !RoutingHealthy() {
			t.Fatal("marshal failure must not open the breaker")
		}
	})

	t.Run("breaker open", func(t *testing.T) {
		cleanState(t)
		var calls int32
		srv := echoServer(t, &calls, nil)
		defer srv.Close()
		MarkRoutingFailure()
		if got := fetchBlurBatch(srv.URL, 400, pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
		if calls != 0 {
			t.Fatalf("no request expected, got %d", calls)
		}
	})

	t.Run("connection error opens breaker", func(t *testing.T) {
		cleanState(t)
		srv := httptest.NewServer(http.NotFoundHandler())
		url := srv.URL
		srv.Close()
		if got := fetchBlurBatch(url, 400, pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
		if RoutingHealthy() {
			t.Fatal("connection error must open the breaker")
		}
	})

	t.Run("non-200", func(t *testing.T) {
		cleanState(t)
		srv := serve(500, "boom")
		defer srv.Close()
		if got := fetchBlurBatch(srv.URL, 400, pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
		if RoutingHealthy() {
			t.Fatal("500 must open the breaker")
		}
	})

	t.Run("non-200 501 keeps breaker closed", func(t *testing.T) {
		cleanState(t)
		srv := serve(501, "")
		defer srv.Close()
		if got := fetchBlurBatch(srv.URL, 400, pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
		if !RoutingHealthy() {
			t.Fatal("501 must not open the breaker")
		}
	})

	t.Run("malformed json", func(t *testing.T) {
		cleanState(t)
		srv := serve(200, "{not json")
		defer srv.Close()
		if got := fetchBlurBatch(srv.URL, 400, pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
	})

	t.Run("length mismatch", func(t *testing.T) {
		cleanState(t)
		srv := serve(200, `{"results":[{"id":0,"lat":1,"lng":2,"roadm":5}]}`)
		defer srv.Close()
		if got := fetchBlurBatch(srv.URL, 400, pts); got != nil {
			t.Fatalf("want nil, got %v", got)
		}
	})

	t.Run("success rounds to 3dp", func(t *testing.T) {
		cleanState(t)
		srv := serve(200, `{"results":[{"id":0,"lat":51.45678,"lng":-2.58349,"roadm":300},{"id":1,"lat":51.46,"lng":-2.59,"roadm":10}]}`)
		defer srv.Close()
		got := fetchBlurBatch(srv.URL, 400, pts)
		if len(got) != 2 {
			t.Fatalf("want 2 results, got %v", got)
		}
		if got[0] != [2]float64{51.457, -2.583} {
			t.Errorf("point 0: got %v", got[0])
		}
		if got[1] != [2]float64{51.46, -2.59} {
			t.Errorf("point 1: got %v", got[1])
		}
	})

	t.Run("off-network falls back to circular", func(t *testing.T) {
		cleanState(t)
		srv := serve(200, `{"results":[{"id":0,"lat":9,"lng":9,"roadm":0},{"id":1,"lat":9,"lng":9,"roadm":-1}]}`)
		defer srv.Close()
		got := fetchBlurBatch(srv.URL, 400, pts)
		if len(got) != 2 {
			t.Fatalf("want 2 results, got %v", got)
		}
		for i, p := range pts {
			if want := blurCircular(p.Lat, p.Lng, 400); got[i] != want {
				t.Errorf("point %d: got %v want circular %v", i, got[i], want)
			}
		}
	})
}

func TestCovBlurCircular(t *testing.T) {
	tests := []struct{ lat, lng, dist float64 }{
		{0, 0, 400},
		{51.45, -2.58, 400},
		{55.95, -3.19, 1000},
	}
	for _, tc := range tests {
		wl, wg := utils.Blur(tc.lat, tc.lng, tc.dist)
		got := blurCircular(tc.lat, tc.lng, tc.dist)
		if got != [2]float64{wl, wg} {
			t.Errorf("blurCircular(%v) = %v, utils.Blur = %v,%v", tc, got, wl, wg)
		}
	}
	if got := blurCircular(0, 0, 400); got != [2]float64{0, 0} {
		t.Errorf("sentinel should stay (0,0), got %v", got)
	}
}

func TestCovRoadBlur(t *testing.T) {
	t.Run("sentinel passthrough", func(t *testing.T) {
		cleanState(t)
		t.Setenv("ROUTING_EVAL_URL", "http://127.0.0.1:1")
		if la, ln := RoadBlur(0, 0, 400); la != 0 || ln != 0 {
			t.Fatalf("got %v,%v", la, ln)
		}
		if !RoutingHealthy() {
			t.Fatal("sentinel must not touch routing")
		}
	})

	t.Run("cache hit", func(t *testing.T) {
		cleanState(t)
		var calls int32
		srv := echoServer(t, &calls, nil)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		blurCachePut(blurKey(51.45, -2.58, 400), [2]float64{7, 8})
		if la, ln := RoadBlur(51.45, -2.58, 400); la != 7 || ln != 8 {
			t.Fatalf("got %v,%v", la, ln)
		}
		if calls != 0 {
			t.Fatalf("cache hit made %d calls", calls)
		}
	})

	t.Run("miss with success caches", func(t *testing.T) {
		cleanState(t)
		var calls int32
		srv := echoServer(t, &calls, nil)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		la, ln := RoadBlur(51.45, -2.58, 400)
		if la != 51.45 || ln != -2.579 {
			t.Fatalf("got %v,%v", la, ln)
		}
		RoadBlur(51.45, -2.58, 400)
		if calls != 1 {
			t.Fatalf("expected 1 call, got %d", calls)
		}
		if _, ok := blurCacheGet(blurKey(51.45, -2.58, 400)); !ok {
			t.Fatal("result should be cached")
		}
	})

	t.Run("unavailable falls back and is not cached", func(t *testing.T) {
		cleanState(t)
		bad := httptest.NewServer(http.NotFoundHandler())
		badURL := bad.URL
		bad.Close()
		t.Setenv("ROUTING_EVAL_URL", badURL)
		la, ln := RoadBlur(51.45, -2.58, 400)
		wl, wg := utils.Blur(51.45, -2.58, 400)
		if la != wl || ln != wg {
			t.Fatalf("expected circular %v,%v got %v,%v", wl, wg, la, ln)
		}
		if _, ok := blurCacheGet(blurKey(51.45, -2.58, 400)); ok {
			t.Fatal("fallback must not be cached")
		}
		var calls int32
		srv := echoServer(t, &calls, nil)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		ResetRoutingBreaker()
		_, ln = RoadBlur(51.45, -2.58, 400)
		if calls != 1 || ln != -2.579 {
			t.Fatalf("healthy retry should be road-aware: calls=%d ln=%v", calls, ln)
		}
	})
}

func TestCovRoadBlurPrewarm(t *testing.T) {
	t.Run("empty is no-op", func(t *testing.T) {
		cleanState(t)
		var calls int32
		srv := echoServer(t, &calls, nil)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		RoadBlurPrewarm(nil, 400)
		RoadBlurPrewarm([][2]float64{}, 400)
		RoadBlurPrewarm([][2]float64{{0, 0}, {0, 0}}, 400)
		if calls != 0 {
			t.Fatalf("expected no calls, got %d", calls)
		}
	})

	t.Run("dedupe, skip sentinel, populate cache", func(t *testing.T) {
		cleanState(t)
		var calls int32
		var sizes []int
		srv := echoServer(t, &calls, &sizes)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		RoadBlurPrewarm([][2]float64{{51.45, -2.58}, {0, 0}, {51.45, -2.58}, {51.46, -2.59}}, 400)
		if calls != 1 || len(sizes) != 1 || sizes[0] != 2 {
			t.Fatalf("expected one call with 2 points, calls=%d sizes=%v", calls, sizes)
		}
		la, ln := RoadBlur(51.45, -2.58, 400)
		if la != 51.45 || ln != -2.579 || calls != 1 {
			t.Fatalf("expected cache hit, got %v,%v calls=%d", la, ln, calls)
		}
	})

	t.Run("already cached makes no call", func(t *testing.T) {
		cleanState(t)
		var calls int32
		srv := echoServer(t, &calls, nil)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		blurCachePut(blurKey(51.45, -2.58, 400), [2]float64{1, 1})
		RoadBlurPrewarm([][2]float64{{51.45, -2.58}}, 400)
		if calls != 0 {
			t.Fatalf("expected no calls, got %d", calls)
		}
	})

	t.Run("failure caches nothing", func(t *testing.T) {
		cleanState(t)
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(500)
		}))
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		RoadBlurPrewarm([][2]float64{{51.45, -2.58}}, 400)
		if _, ok := blurCacheGet(blurKey(51.45, -2.58, 400)); ok {
			t.Fatal("nothing should be cached on failure")
		}
	})

	t.Run("chunks over 1000 points", func(t *testing.T) {
		cleanState(t)
		var calls int32
		var sizes []int
		srv := echoServer(t, &calls, &sizes)
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		coords := make([][2]float64, 1001)
		for i := range coords {
			coords[i] = [2]float64{50 + float64(i)*0.0001, -2.5}
		}
		RoadBlurPrewarm(coords, 400)
		if calls != 2 || len(sizes) != 2 || sizes[0] != 1000 || sizes[1] != 1 {
			t.Fatalf("expected chunks 1000+1, calls=%d sizes=%v", calls, sizes)
		}
		for _, c := range coords {
			if _, ok := blurCacheGet(blurKey(c[0], c[1], 400)); !ok {
				t.Fatalf("missing cache entry for %v", c)
			}
		}
	})

	t.Run("failure on second chunk keeps first", func(t *testing.T) {
		cleanState(t)
		var n int32
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			var req struct {
				Points []blurPoint `json:"points"`
			}
			_ = json.NewDecoder(r.Body).Decode(&req)
			if atomic.AddInt32(&n, 1) > 1 {
				w.WriteHeader(500)
				return
			}
			out := []map[string]any{}
			for _, p := range req.Points {
				out = append(out, map[string]any{"id": p.ID, "lat": p.Lat, "lng": p.Lng, "roadm": 5})
			}
			_ = json.NewEncoder(w).Encode(map[string]any{"results": out})
		}))
		defer srv.Close()
		t.Setenv("ROUTING_EVAL_URL", srv.URL)
		coords := make([][2]float64, 1001)
		for i := range coords {
			coords[i] = [2]float64{50 + float64(i)*0.0001, -2.5}
		}
		RoadBlurPrewarm(coords, 400)
		if _, ok := blurCacheGet(blurKey(coords[0][0], coords[0][1], 400)); !ok {
			t.Error("first chunk should be cached")
		}
		if _, ok := blurCacheGet(blurKey(coords[1000][0], coords[1000][1], 400)); ok {
			t.Error("failed chunk must not be cached")
		}
	})
}
