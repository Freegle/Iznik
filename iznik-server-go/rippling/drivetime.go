package rippling

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"
)

// driveTimeHTTPClient is deliberately short-timeout: this sits on a read path that a
// member is waiting on, and the budget-bounded search it calls answers in tens of
// milliseconds on the UK graph. If the routing server is slow or down we would rather
// show no estimate than hold the whole response.
var driveTimeHTTPClient = &http.Client{Timeout: 2 * time.Second}

// The routing server shares db3 with mysqld and apiv2, and each drive-time answer is a
// Dijkstra whose cost scales with the budget. Left unbounded, the per-blocked-post
// fan-out in the feed path stacked hundreds of concurrent searches for a single viewer
// (2026-08-13: ~600 timed-out calls/minute during a load-31 spike), and the 2s client
// timeout does not shed that load — the routing server still works every abandoned
// request to completion. Three guards keep the estimate strictly best-effort:
//
//   - a process-wide concurrency cap, so feed traffic can never queue more searches
//     than the routing host is sized for;
//   - a TTL cache keyed on the exact question, because both ends repeat: origins are
//     per-post constants and a polling viewer re-asks for the same blocked posts every
//     refresh;
//   - a failure breaker, so when the routing server is already drowning we stop asking
//     entirely for a cooldown instead of feeding the spiral two-second timeouts.
const driveTimeMaxConcurrent = 8

var driveTimeSem = make(chan struct{}, driveTimeMaxConcurrent)

// Tunable for tests only.
var (
	driveTimeCacheTTL       = 15 * time.Minute
	driveTimeBreakerAfter   = int32(3)
	driveTimeBreakerCooloff = 30 * time.Second
)

type driveTimeCacheEntry struct {
	dt      DriveTime
	expires time.Time
}

var driveTimeCache sync.Map // string key -> driveTimeCacheEntry
var driveTimeCacheSize atomic.Int64

// driveTimeCacheSweepAt bounds memory: past this many entries a put sweeps expired
// ones first. Entries are ~100 bytes; 20k ≈ 2MB worst case.
const driveTimeCacheSweepAt = 20000

var driveTimeFailStreak atomic.Int32
var driveTimeBreakerUntil atomic.Int64 // unix nanos; 0 = closed

func driveTimeBreakerOpen() bool {
	until := driveTimeBreakerUntil.Load()
	return until != 0 && time.Now().UnixNano() < until
}

func driveTimeRecordFailure() {
	if driveTimeFailStreak.Add(1) >= driveTimeBreakerAfter {
		driveTimeBreakerUntil.Store(time.Now().Add(driveTimeBreakerCooloff).UnixNano())
	}
}

func driveTimeRecordSuccess() {
	driveTimeFailStreak.Store(0)
	driveTimeBreakerUntil.Store(0)
}

// routingInternalURL is the ROUTING server's INTERNAL, no-auth port. Three env vars point
// at three different things here and only this one is right:
//
//	ROUTING_EVAL_URL     http://spatial:8194       routing server, internal, no auth  <- this
//	SPATIAL_SERVER_URL   http://spatial:8196       routing server, external, JWT + mod only
//	SPATIAL_KNN_URL      http://spatial-knn:8194   a DIFFERENT container (KNN), no /v1/drive-time
//
// Using SPATIAL_SERVER_URL gets a 401 and using SPATIAL_KNN_URL gets a 404, and both would
// surface identically as "no estimate" for every member, forever and silently. docker-compose
// already carries a comment warning about this exact misrouting for the drive-time analytics.
// So there is no KNN fallback: the only fallback is the routing container's own internal port.
func routingInternalURL() string {
	if u := os.Getenv("ROUTING_EVAL_URL"); u != "" {
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

	// The formatted URL is the cache key: it already encodes the whole question at the
	// %f precision the routing server would see, so equal keys are equal questions.
	if v, hit := driveTimeCache.Load(url); hit {
		entry := v.(driveTimeCacheEntry)
		if time.Now().Before(entry.expires) {
			return entry.dt, true
		}
		driveTimeCache.Delete(url)
		driveTimeCacheSize.Add(-1)
	}

	if driveTimeBreakerOpen() {
		return DriveTime{}, false
	}

	driveTimeSem <- struct{}{}
	defer func() { <-driveTimeSem }()

	resp, err := driveTimeHTTPClient.Get(url)
	if err != nil {
		driveTimeRecordFailure()
		log.Printf("rippling: drive-time fetch failed: %v", err)
		return DriveTime{}, false
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		driveTimeRecordFailure()
		log.Printf("rippling: drive-time read failed: %v", err)
		return DriveTime{}, false
	}

	if resp.StatusCode != http.StatusOK {
		driveTimeRecordFailure()
		log.Printf("rippling: drive-time HTTP %d", resp.StatusCode)
		return DriveTime{}, false
	}

	var r driveTimeResponse
	if err := json.Unmarshal(body, &r); err != nil {
		driveTimeRecordFailure()
		log.Printf("rippling: drive-time JSON parse failed: %v", err)
		return DriveTime{}, false
	}

	driveTimeRecordSuccess()

	dt := DriveTime{Minutes: r.DriveMin, Reachable: r.Reachable}
	if driveTimeCacheSize.Load() >= driveTimeCacheSweepAt {
		now := time.Now()
		driveTimeCache.Range(func(k, v interface{}) bool {
			if now.After(v.(driveTimeCacheEntry).expires) {
				driveTimeCache.Delete(k)
				driveTimeCacheSize.Add(-1)
			}
			return true
		})
	}
	if _, loaded := driveTimeCache.LoadOrStore(url, driveTimeCacheEntry{dt: dt, expires: time.Now().Add(driveTimeCacheTTL)}); !loaded {
		driveTimeCacheSize.Add(1)
	}

	return dt, true
}
