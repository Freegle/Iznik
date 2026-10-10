package rippling

import (
	"net/http"
	"net/http/httptest"
	"os"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func resetDriveTimeGuards() {
	driveTimeCache.Range(func(k, _ interface{}) bool {
		driveTimeCache.Delete(k)
		return true
	})
	driveTimeCacheSize.Store(0)
	driveTimeFailStreak.Store(0)
	driveTimeBreakerUntil.Store(0)
}

func withRoutingStub(t *testing.T, handler http.HandlerFunc) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(handler)
	old := os.Getenv("ROUTING_EVAL_URL")
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	t.Cleanup(func() {
		srv.Close()
		os.Setenv("ROUTING_EVAL_URL", old)
		resetDriveTimeGuards()
	})
	resetDriveTimeGuards()
	return srv
}

// A repeated question must be answered from the cache: the feed path re-asks the same
// (origin, viewer, budget) triple on every poll, and each upstream answer is a Dijkstra
// on a host shared with mysqld.
func TestFetchDriveTimeCachesAnswers(t *testing.T) {
	var hits atomic.Int32
	withRoutingStub(t, func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		w.Write([]byte(`{"reachable":true,"drive_min":12.5}`))
	})

	dt1, ok1 := FetchDriveTime(53.5, -2.2, 53.6, -2.3, 30)
	dt2, ok2 := FetchDriveTime(53.5, -2.2, 53.6, -2.3, 30)

	assert.True(t, ok1)
	assert.True(t, ok2)
	assert.Equal(t, dt1, dt2)
	assert.Equal(t, int32(1), hits.Load(), "second identical question must not reach the routing server")

	// A different question is a different key.
	_, ok3 := FetchDriveTime(53.5, -2.2, 53.6, -2.3, 45)
	assert.True(t, ok3)
	assert.Equal(t, int32(2), hits.Load())
}

// After consecutive failures the breaker opens and calls stop reaching the routing
// server at all for the cooldown — timing out two seconds per blocked post is exactly
// how an overloaded routing host gets buried deeper.
func TestFetchDriveTimeBreakerOpensAndRecovers(t *testing.T) {
	var hits atomic.Int32
	var failing atomic.Bool
	failing.Store(true)
	withRoutingStub(t, func(w http.ResponseWriter, r *http.Request) {
		hits.Add(1)
		if failing.Load() {
			w.WriteHeader(http.StatusInternalServerError)
			return
		}
		w.Write([]byte(`{"reachable":true,"drive_min":5}`))
	})

	// The breaker reads its clock through driveTimeNow, so the test holds time still
	// while it checks the breaker is open and then moves it past the cooloff, with the
	// real 30 second cooloff left as it is. Shortening the cooloff and sleeping was racy
	// in the unsafe direction: the "still open" call below had to reach the breaker
	// within the shortened cooloff of the third failure, and a scheduler preemption
	// under -race takes longer than a few milliseconds, which let the call through.
	now := time.Now()
	oldNow := driveTimeNow
	driveTimeNow = func() time.Time { return now }
	defer func() { driveTimeNow = oldNow }()

	for i := 0; i < int(driveTimeBreakerAfter); i++ {
		_, ok := FetchDriveTime(50.0, -1.0, 50.1, float64(i)*0.01, 30)
		assert.False(t, ok)
	}
	assert.Equal(t, int32(driveTimeBreakerAfter), hits.Load())

	// Breaker open: no upstream call.
	_, ok := FetchDriveTime(50.0, -1.0, 50.9, -1.9, 30)
	assert.False(t, ok)
	assert.Equal(t, int32(driveTimeBreakerAfter), hits.Load(), "open breaker must not call upstream")

	// Just inside the cooloff it is still open.
	now = now.Add(driveTimeBreakerCooloff - time.Nanosecond)
	_, ok = FetchDriveTime(50.0, -1.0, 50.9, -1.9, 30)
	assert.False(t, ok)
	assert.Equal(t, int32(driveTimeBreakerAfter), hits.Load(), "breaker must stay open until the cooloff has passed")

	// Once the cooloff has passed a healthy server closes it again.
	failing.Store(false)
	now = now.Add(time.Nanosecond)
	dt, ok := FetchDriveTime(50.0, -1.0, 50.9, -1.9, 30)
	assert.True(t, ok)
	assert.True(t, dt.Reachable)
	assert.Equal(t, int32(0), driveTimeFailStreak.Load())
}
