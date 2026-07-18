package message

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// The reach-universe cache lets consecutive browse-scoped searches skip the expensive
// reach containment query (see reachUniverseTTL). These tests pin its contract: keyed
// by member+location, TTL-expired entries miss, and overflow clears rather than grows.

func TestReachUniverseCache_HitWithinTTL(t *testing.T) {
	reachUniverseMu.Lock()
	reachUniverseCache = map[string]reachUniverseEntry{}
	reachUniverseMu.Unlock()

	now := time.Now()
	key := reachUniverseKey(1, 51.81, -0.02)
	storeReachUniverse(key, []uint64{10, 20}, now)

	ids, hit := cachedReachUniverse(key, now.Add(reachUniverseTTL/2))
	assert.True(t, hit)
	assert.Equal(t, []uint64{10, 20}, ids)
}

func TestReachUniverseCache_MissAfterTTL(t *testing.T) {
	reachUniverseMu.Lock()
	reachUniverseCache = map[string]reachUniverseEntry{}
	reachUniverseMu.Unlock()

	now := time.Now()
	key := reachUniverseKey(1, 51.81, -0.02)
	storeReachUniverse(key, []uint64{10}, now)

	_, hit := cachedReachUniverse(key, now.Add(reachUniverseTTL+time.Second))
	assert.False(t, hit)
}

func TestReachUniverseCache_KeyIncludesLocation(t *testing.T) {
	// Moving ~11m+ (4dp) must miss the old entry - a member who changes their
	// location gets a fresh universe immediately.
	a := reachUniverseKey(1, 51.8129, -0.0204)
	b := reachUniverseKey(1, 51.8130, -0.0204)
	c := reachUniverseKey(2, 51.8129, -0.0204)
	assert.NotEqual(t, a, b)
	assert.NotEqual(t, a, c)
}

func TestReachUniverseCache_OverflowClears(t *testing.T) {
	reachUniverseMu.Lock()
	reachUniverseCache = map[string]reachUniverseEntry{}
	for i := 0; i < reachUniverseMaxEntries; i++ {
		reachUniverseCache[reachUniverseKey(uint64(i), 0, 0)] = reachUniverseEntry{expires: time.Now().Add(time.Hour)}
	}
	reachUniverseMu.Unlock()

	// The store that overflows clears the map and inserts just itself.
	storeReachUniverse("fresh", []uint64{1}, time.Now())

	reachUniverseMu.Lock()
	size := len(reachUniverseCache)
	reachUniverseMu.Unlock()
	assert.Equal(t, 1, size)
}

func TestReachUniverseCache_CachesEmptyUniverse(t *testing.T) {
	// An empty reach universe is a valid, cacheable answer (member out of any reach):
	// it must produce a HIT with nil ids, not be mistaken for a miss - otherwise
	// members with no reachable posts would re-pay the expensive query every search.
	reachUniverseMu.Lock()
	reachUniverseCache = map[string]reachUniverseEntry{}
	reachUniverseMu.Unlock()

	now := time.Now()
	key := reachUniverseKey(3, 51.0, 0.0)
	storeReachUniverse(key, nil, now)

	ids, hit := cachedReachUniverse(key, now.Add(time.Second))
	assert.True(t, hit)
	assert.Nil(t, ids)
}
