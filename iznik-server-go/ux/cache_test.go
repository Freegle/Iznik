package ux

import (
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestCachedStoresOnlyCacheableSuccess(t *testing.T) {
	ResetCache()
	calls := 0
	compute := func(cacheable bool, err error) func() (interface{}, bool, error) {
		return func() (interface{}, bool, error) { calls++; return calls, cacheable, err }
	}

	v, _ := cached("k1", compute(true, nil))
	v2, _ := cached("k1", compute(true, nil))
	assert.Equal(t, v, v2)
	assert.Equal(t, 1, calls, "second call came from the cache")

	cached("k2", compute(false, nil))
	cached("k2", compute(false, nil))
	assert.Equal(t, 3, calls, "a degraded answer is recomputed")

	_, err := cached("k3", compute(true, errors.New("boom")))
	assert.Error(t, err)
	cached("k3", compute(true, nil))
	assert.Equal(t, 5, calls, "an error is never cached")
}

func TestCachedSingleFlight(t *testing.T) {
	ResetCache()
	var mu sync.Mutex
	calls := 0
	release := make(chan struct{})
	compute := func() (interface{}, bool, error) {
		mu.Lock()
		calls++
		mu.Unlock()
		<-release
		return "x", true, nil
	}

	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(1)
		go func() { defer wg.Done(); cached("sf", compute) }()
	}
	time.Sleep(100 * time.Millisecond)
	close(release)
	wg.Wait()
	assert.Equal(t, 1, calls, "concurrent identical requests share one computation")
}

func TestCachedFullCacheDropsExpiredAndNeverEvictsLive(t *testing.T) {
	ResetCache()
	cacheMu.Lock()
	for i := 0; i < cacheMaxEntries; i++ {
		cache[string(rune('a'+i%26))+time.Duration(i).String()] = cacheEntry{val: i, expires: time.Now().Add(-time.Minute)}
	}
	cacheMu.Unlock()

	cached("fresh", func() (interface{}, bool, error) { return 1, true, nil })
	cacheMu.Lock()
	assert.Contains(t, cache, "fresh", "expired entries were dropped to make room")
	assert.Less(t, len(cache), cacheMaxEntries)

	for k := range cache {
		delete(cache, k)
	}
	for i := 0; i < cacheMaxEntries; i++ {
		cache[time.Duration(i).String()] = cacheEntry{val: i, expires: time.Now().Add(time.Hour)}
	}
	cacheMu.Unlock()

	cached("overflow", func() (interface{}, bool, error) { return 1, true, nil })
	cacheMu.Lock()
	assert.NotContains(t, cache, "overflow", "live entries are not thrown out for a newcomer")
	assert.Len(t, cache, cacheMaxEntries)
	cacheMu.Unlock()
	ResetCache()
}

func TestTruncate(t *testing.T) {
	assert.Equal(t, "abc", truncate("abcdef", 3))
	assert.Equal(t, "ab", truncate("ab", 3))
}
