package roadblur

// Road-aware display blurring: member and post locations shown to others are
// blurred for privacy, and the classic circular offset can move a point to
// the wrong side of a river — a tiny crow-flies error but a huge road-network
// one now that the site shows road distances. RoadBlur asks the routing
// server for a deterministic road-network blur (same connectivity side by
// construction, at least R/4 crow-flies and R/2 road metres away, stable per
// input) and falls back to the classic circular utils.Blur whenever the
// routing server cannot answer — so nothing ever breaks when routing is down,
// the display just reverts to the old blur.
//
// Blur is deterministic per location and locations repeat heavily, so results
// are cached; list endpoints prewarm the cache with ONE /v1/blur-batch call
// per response rather than per-point requests.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"math"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"

	"github.com/freegle/iznik-server-go/utils"
)

// RoutingURL is the single owner of "where is the routing server" for apiv2
// (deliberately NO SPATIAL_KNN_URL fallback: that variable points at the KNN
// index service, which serves neither blur nor drive metrics).
func RoutingURL() string {
	if u := os.Getenv("ROUTING_EVAL_URL"); u != "" {
		return u
	}
	return "http://spatial:8194"
}

// Short timeout: display decoration, never worth stalling a response for.
var routingClient = &http.Client{Timeout: 3 * time.Second}

// Circuit breaker for the routing server, shared by every road-metrics
// caller in this process (blur here, drive metrics in the driving package).
// After a failed call, further routing requests are skipped for a cooldown
// and everything falls back to crow-flies/circular immediately - a down
// routing server must cost each hot request ZERO added latency, not one
// 3-second timeout per call site.
var routingDownUntil atomic.Int64

const routingCooldown = 30 * time.Second

// RoutingHealthy reports whether routing calls should be attempted.
func RoutingHealthy() bool {
	return time.Now().UnixNano() >= routingDownUntil.Load()
}

// MarkRoutingFailure starts the cooldown after a failed routing call.
func MarkRoutingFailure() {
	routingDownUntil.Store(time.Now().Add(routingCooldown).UnixNano())
}

const blurCacheCap = 200000

var (
	blurMu    sync.Mutex
	blurCache = map[string][2]float64{}
	blurOrder []string
)

func blurKey(lat, lng, dist float64) string {
	return fmt.Sprintf("%.6f,%.6f,%.0f", lat, lng, dist)
}

func blurCacheGet(k string) ([2]float64, bool) {
	blurMu.Lock()
	defer blurMu.Unlock()
	v, ok := blurCache[k]
	return v, ok
}

func blurCachePut(k string, v [2]float64) {
	blurMu.Lock()
	defer blurMu.Unlock()
	if _, ok := blurCache[k]; ok {
		return
	}
	blurCache[k] = v
	blurOrder = append(blurOrder, k)
	if len(blurOrder) > blurCacheCap {
		old := blurOrder[0]
		blurOrder = blurOrder[1:]
		delete(blurCache, old)
	}
}

type blurPoint struct {
	ID  int64   `json:"id"`
	Lat float64 `json:"lat"`
	Lng float64 `json:"lng"`
}

// fetchBlurBatch asks the routing server to blur the points; nil on any
// failure (callers fall back to utils.Blur).
func fetchBlurBatch(routingURL string, dist float64, pts []blurPoint) [][2]float64 {
	if len(pts) == 0 {
		return nil
	}
	body, err := json.Marshal(map[string]any{"metres": dist, "points": pts})
	if err != nil {
		return nil
	}
	if !RoutingHealthy() {
		return nil
	}
	resp, err := routingClient.Post(routingURL+"/v1/blur-batch", "application/json", bytes.NewReader(body))
	if err != nil {
		MarkRoutingFailure()
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		MarkRoutingFailure()
		return nil
	}
	var parsed struct {
		Results []struct {
			ID    int64   `json:"id"`
			Lat   float64 `json:"lat"`
			Lng   float64 `json:"lng"`
			Roadm float64 `json:"roadm"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		return nil
	}
	if len(parsed.Results) != len(pts) {
		return nil
	}
	out := make([][2]float64, len(pts))
	for i, r := range parsed.Results {
		if r.Roadm > 0 {
			// Same output contract as utils.Blur: 3dp (~70m) — a road-network
			// point rounded, never an exact node coordinate.
			out[i] = [2]float64{math.Round(r.Lat*1000) / 1000, math.Round(r.Lng*1000) / 1000}
		} else {
			// Routing had no road-aware answer for this point (off-network):
			// classic circular blur keeps behaviour unchanged there.
			out[i] = blurCircular(pts[i].Lat, pts[i].Lng, dist)
		}
	}
	return out
}

func blurCircular(lat, lng, dist float64) [2]float64 {
	blat, blng := utils.Blur(lat, lng, dist)
	return [2]float64{blat, blng}
}

// RoadBlur blurs one display location road-aware, cache-first, falling back
// to the classic circular blur when the routing server is unavailable.
// Same sentinel behaviour as utils.Blur: (0,0) passes through.
func RoadBlur(lat, lng, dist float64) (float64, float64) {
	if lat == 0 && lng == 0 {
		return 0, 0
	}
	k := blurKey(lat, lng, dist)
	if v, ok := blurCacheGet(k); ok {
		return v[0], v[1]
	}
	res := fetchBlurBatch(RoutingURL(), dist, []blurPoint{{ID: 0, Lat: lat, Lng: lng}})
	if res == nil {
		// Fallback is NOT cached: the next request retries road-aware, so a
		// routing blip does not pin circular results for the cache lifetime.
		v := blurCircular(lat, lng, dist)
		return v[0], v[1]
	}
	blurCachePut(k, res[0])
	return res[0][0], res[0][1]
}

// RoadBlurPrewarm resolves a whole list of locations with at most ONE routing
// call (cache misses only), so list endpoints never blur point-by-point over
// HTTP. Safe to call with duplicates.
func RoadBlurPrewarm(coords [][2]float64, dist float64) {
	var miss []blurPoint
	seen := map[string]bool{}
	for _, c := range coords {
		if c[0] == 0 && c[1] == 0 {
			continue
		}
		k := blurKey(c[0], c[1], dist)
		if seen[k] {
			continue
		}
		seen[k] = true
		if _, ok := blurCacheGet(k); ok {
			continue
		}
		miss = append(miss, blurPoint{ID: int64(len(miss)), Lat: c[0], Lng: c[1]})
	}
	if len(miss) == 0 {
		return
	}
	// The routing batch endpoint caps at 1000 points per call.
	for start := 0; start < len(miss); start += 1000 {
		end := start + 1000
		if end > len(miss) {
			end = len(miss)
		}
		chunk := miss[start:end]
		res := fetchBlurBatch(RoutingURL(), dist, chunk)
		if res == nil {
			return // fallback happens per-point in RoadBlur
		}
		for i, p := range chunk {
			blurCachePut(blurKey(p.Lat, p.Lng, dist), res[i])
		}
	}
}

// resetBlurForTest clears the cache between tests.
func resetBlurForTest() {
	blurMu.Lock()
	defer blurMu.Unlock()
	blurCache = map[string][2]float64{}
	blurOrder = nil
	routingDownUntil.Store(0)
}

// ResetRoutingBreaker clears the routing-failure cooldown. Tests only: a
// deliberately-failed call in one test must not open the breaker for the next.
func ResetRoutingBreaker() {
	routingDownUntil.Store(0)
}
