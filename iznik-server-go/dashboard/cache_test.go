package dashboard

// Tests for the dashboard component cache: the key (which must separate scopes that must not
// see each other's figures), the TTL policy, the empty-result classification that drives the
// short negative TTL, and the single-flight behaviour that stops retry storms stacking copies
// of an expensive query onto db3.

import (
	"fmt"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestComponentCacheKey_SameScopeSameKeyRegardlessOfGroupOrder(t *testing.T) {
	// resolveGroupIDs returns whatever order the DB hands back, so two loads by the same
	// moderator must not miss the cache just because the rows came back differently.
	a := componentCacheKey("UsersReplying", []uint64{7, 3, 91}, "2026-01-01", "2026-02-01", false)
	b := componentCacheKey("UsersReplying", []uint64{91, 7, 3}, "2026-01-01", "2026-02-01", false)
	assert.Equal(t, a, b)
}

func TestComponentCacheKey_DifferentGroupsDifferentKey(t *testing.T) {
	// The important one: one moderator's groups must never key to another's.
	a := componentCacheKey("UsersReplying", []uint64{1, 2}, "2026-01-01", "2026-02-01", false)
	b := componentCacheKey("UsersReplying", []uint64{1, 3}, "2026-01-01", "2026-02-01", false)
	assert.NotEqual(t, a, b)

	// A subset must differ from its superset too.
	c := componentCacheKey("UsersReplying", []uint64{1}, "2026-01-01", "2026-02-01", false)
	assert.NotEqual(t, a, c)
}

func TestComponentCacheKey_ComponentRangeAndScopeAllSeparate(t *testing.T) {
	base := componentCacheKey("UsersReplying", []uint64{1}, "2026-01-01", "2026-02-01", false)

	assert.NotEqual(t, base, componentCacheKey("UsersPosting", []uint64{1}, "2026-01-01", "2026-02-01", false))
	assert.NotEqual(t, base, componentCacheKey("UsersReplying", []uint64{1}, "2026-01-02", "2026-02-01", false))
	assert.NotEqual(t, base, componentCacheKey("UsersReplying", []uint64{1}, "2026-01-01", "2026-02-02", false))
	assert.NotEqual(t, base, componentCacheKey("UsersReplying", []uint64{1}, "2026-01-01", "2026-02-01", true))
}

func TestComponentTTL_WidensWithRange(t *testing.T) {
	assert.Equal(t, 5*time.Minute, componentTTL("UsersReplying", 7))
	assert.Equal(t, 5*time.Minute, componentTTL("UsersReplying", 31))
	assert.Equal(t, 30*time.Minute, componentTTL("UsersReplying", 32))
	assert.Equal(t, 30*time.Minute, componentTTL("PopularPosts", 365))
}

func TestComponentTTL_DiscourseIsIndependentOfRange(t *testing.T) {
	assert.Equal(t, 120*time.Second, componentTTL("DiscourseTopics", 7))
	assert.Equal(t, 120*time.Second, componentTTL("DiscourseTopics", 365))
}

// Components already served by the nightly stats rollup are cheap, so they stay uncached
// rather than gaining staleness for no benefit.
func TestComponentTTL_RollupBackedComponentsAreNotCached(t *testing.T) {
	assert.Zero(t, componentTTL("Activity", 365))
	assert.Zero(t, componentTTL("Donations", 365))
	assert.Zero(t, componentTTL("MessageBreakdown", 365))
}

func TestRangeDaysBetween(t *testing.T) {
	assert.Equal(t, 7, rangeDaysBetween("2026-01-01", "2026-01-08"))
	assert.Equal(t, 365, rangeDaysBetween("2025-01-01", "2026-01-01"))
	assert.Equal(t, 0, rangeDaysBetween("2026-01-08", "2026-01-01"), "a backwards range must not go negative")
	assert.Equal(t, 0, rangeDaysBetween("not-a-date", "2026-01-01"))
	assert.Equal(t, 0, rangeDaysBetween("2026-01-01", "not-a-date"))
}

func TestIsEmptyComponentResult(t *testing.T) {
	assert.True(t, isEmptyComponentResult(nil))
	assert.True(t, isEmptyComponentResult([]map[string]interface{}{}))
	assert.True(t, isEmptyComponentResult(map[string]int64{}))
	assert.True(t, isEmptyComponentResult(""))

	assert.False(t, isEmptyComponentResult([]map[string]interface{}{{"id": 1}}))
	assert.False(t, isEmptyComponentResult(map[string]int64{"newmessages": 0}),
		"RecentCounts legitimately returns zeroes and must keep its full TTL")
	assert.False(t, isEmptyComponentResult("{\"topics\":[]}"))
}

func TestCachedComponent_SecondCallIsServedFromCache(t *testing.T) {
	resetComponentCache()

	var calls int32
	compute := func() interface{} {
		atomic.AddInt32(&calls, 1)
		return []map[string]interface{}{{"id": uint64(1)}}
	}

	first := cachedComponent("k", time.Minute, compute)
	second := cachedComponent("k", time.Minute, compute)

	assert.Equal(t, int32(1), atomic.LoadInt32(&calls))
	assert.Equal(t, first, second)
}

func TestCachedComponent_ZeroTTLAlwaysComputes(t *testing.T) {
	resetComponentCache()

	var calls int32
	compute := func() interface{} {
		atomic.AddInt32(&calls, 1)
		return []map[string]interface{}{{"id": uint64(1)}}
	}

	cachedComponent("k", 0, compute)
	cachedComponent("k", 0, compute)

	assert.Equal(t, int32(2), atomic.LoadInt32(&calls))
}

func TestCachedComponent_DifferentKeysDoNotShare(t *testing.T) {
	resetComponentCache()

	a := cachedComponent("a", time.Minute, func() interface{} { return "A" })
	b := cachedComponent("b", time.Minute, func() interface{} { return "B" })

	assert.Equal(t, "A", a)
	assert.Equal(t, "B", b)
}

// The retry-storm case, and the reason a plain TTL cache is not enough: a slow component is
// still running when the next request for it arrives, so nothing is cached yet. Without
// single-flight each of those requests starts its own copy of the query — how the dashboard
// once stacked 19+ concurrent copies onto db3.
func TestCachedComponent_ConcurrentCallersShareOneComputation(t *testing.T) {
	resetComponentCache()

	var calls int32
	release := make(chan struct{})
	started := make(chan struct{})

	compute := func() interface{} {
		if atomic.AddInt32(&calls, 1) == 1 {
			close(started)
		}
		<-release
		return []map[string]interface{}{{"id": uint64(42)}}
	}

	const callers = 20
	results := make([]interface{}, callers)
	var wg sync.WaitGroup

	wg.Add(1)
	go func() {
		defer wg.Done()
		results[0] = cachedComponent("slow", time.Minute, compute)
	}()

	// Only let the others in once the first computation is genuinely in flight.
	<-started

	for i := 1; i < callers; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			results[i] = cachedComponent("slow", time.Minute, compute)
		}(i)
	}

	// Give the waiters time to queue up behind the in-flight call before it finishes.
	time.Sleep(50 * time.Millisecond)
	close(release)
	wg.Wait()

	assert.Equal(t, int32(1), atomic.LoadInt32(&calls), "concurrent callers must not each run the query")
	for i := 0; i < callers; i++ {
		assert.Equal(t, []map[string]interface{}{{"id": uint64(42)}}, results[i])
	}
}

// An empty answer means the component errored or hit its deadline, so it must expire fast
// rather than pinning a blank widget in front of moderators for the full TTL.
func TestCachedComponent_EmptyResultGetsShortTTL(t *testing.T) {
	resetComponentCache()

	cachedComponent("empty", 30*time.Minute, func() interface{} {
		return []map[string]interface{}{}
	})

	componentMu.Lock()
	entry, ok := componentCache["empty"]
	componentMu.Unlock()

	assert.True(t, ok)
	assert.LessOrEqual(t, time.Until(entry.expires), componentNegativeTTL)
}

func TestCachedComponent_ExpiredEntryRecomputes(t *testing.T) {
	resetComponentCache()

	var calls int32
	compute := func() interface{} {
		atomic.AddInt32(&calls, 1)
		return "v"
	}

	cachedComponent("k", 20*time.Millisecond, compute)
	time.Sleep(40 * time.Millisecond)
	cachedComponent("k", 20*time.Millisecond, compute)

	assert.Equal(t, int32(2), atomic.LoadInt32(&calls))
}

// A panicking compute must not leave waiters parked on a channel that never closes.
func TestCachedComponent_PanicReleasesWaiters(t *testing.T) {
	resetComponentCache()

	done := make(chan struct{})
	go func() {
		defer func() {
			_ = recover()
			close(done)
		}()
		cachedComponent("boom", time.Minute, func() interface{} { panic("compute failed") })
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("panicking compute deadlocked the cache")
	}

	// The key must be left clean so the next request can try again.
	componentMu.Lock()
	_, inflight := componentInflight["boom"]
	_, cached := componentCache["boom"]
	componentMu.Unlock()

	assert.False(t, inflight, "panic must clear the in-flight marker")
	assert.False(t, cached, "a panic must not be cached as an answer")
}

func TestSortedHappiness_OrdersByCountThenLabel(t *testing.T) {
	got := sortedHappiness(map[string]int{"Happy": 3, "Fine": 10, "Unhappy": 3})

	assert.Equal(t, []map[string]interface{}{
		{"count": 10, "happiness": "Fine"},
		{"count": 3, "happiness": "Happy"},
		{"count": 3, "happiness": "Unhappy"},
	}, got)
}

func TestSortedHappiness_DropsZeroAndUnknownBuckets(t *testing.T) {
	got := sortedHappiness(map[string]int{"Happy": 0, "Fine": 2, "Bogus": 9})

	assert.Equal(t, []map[string]interface{}{
		{"count": 2, "happiness": "Fine"},
	}, got)
}

func TestSortedHappiness_EmptyIsEmptySlice(t *testing.T) {
	got := sortedHappiness(map[string]int{})
	assert.NotNil(t, got)
	assert.Empty(t, got)
}

// A stranger can fill the cache: the date range and group come straight from the query
// string, and most components answer without a login, so unique keys are free to generate.
// Emptying the cache when it fills would hand that stranger the power to throw away every
// moderator's freshly-computed figures and send the lot back to the database at once,
// which is the pile-up the cache exists to prevent.
func TestCachedComponent_FullCacheOfLiveEntriesIsNotThrownAway(t *testing.T) {
	componentMu.Lock()
	componentCache = map[string]cachedComponentResult{}
	componentInflight = map[string]*inflightComponent{}
	for i := 0; i < componentCacheMaxEntries; i++ {
		componentCache[fmt.Sprintf("live-%d", i)] = cachedComponentResult{
			val:     []map[string]interface{}{{"n": i}},
			expires: time.Now().Add(time.Hour),
		}
	}
	componentMu.Unlock()

	got := cachedComponent("flood-key", time.Minute, func() interface{} {
		return []map[string]interface{}{{"x": 1}}
	})
	assert.NotNil(t, got, "the answer is still computed and returned, it just isn't stored")

	componentMu.Lock()
	remaining := len(componentCache)
	componentMu.Unlock()

	assert.GreaterOrEqual(t, remaining, componentCacheMaxEntries,
		"entries that were still live were discarded to make room")
}

// Expired entries are fair game, so an honestly-busy cache still turns over.
func TestCachedComponent_ExpiredEntriesAreReclaimedWhenFull(t *testing.T) {
	componentMu.Lock()
	componentCache = map[string]cachedComponentResult{}
	componentInflight = map[string]*inflightComponent{}
	for i := 0; i < componentCacheMaxEntries; i++ {
		componentCache[fmt.Sprintf("stale-%d", i)] = cachedComponentResult{
			val:     []map[string]interface{}{{"n": i}},
			expires: time.Now().Add(-time.Hour),
		}
	}
	componentMu.Unlock()

	cachedComponent("fresh-key", time.Minute, func() interface{} {
		return []map[string]interface{}{{"x": 1}}
	})

	componentMu.Lock()
	_, cached := componentCache["fresh-key"]
	remaining := len(componentCache)
	componentMu.Unlock()

	assert.True(t, cached, "a new answer must be cached once expired entries have been cleared out")
	assert.Less(t, remaining, componentCacheMaxEntries, "expired entries should have been dropped")
}
