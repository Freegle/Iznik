package message

import (
	"testing"
	"time"
)

// The two dates a browse card is built from (posted, visibleSince) are remembered in-process
// for whenVisibleTTL: the country-wide no-location in-bounds fallback asks for the same tens
// of thousands of posts member after member, and dating them cost ~1.7s of lookups per call.

func resetWhenVisibleCache() {
	whenVisibleMu.Lock()
	defer whenVisibleMu.Unlock()
	whenVisibleCache = map[uint64]whenVisibleEntry{}
}

func TestWhenVisibleCache_ServesStoredRowsUntilTheyExpire(t *testing.T) {
	resetWhenVisibleCache()
	defer resetWhenVisibleCache()

	now := time.Date(2026, 9, 7, 12, 0, 0, 0, time.UTC)
	written := now.Add(-30 * 24 * time.Hour)
	reposted := now.Add(-3 * 24 * time.Hour)
	whenVisibleStore([]whenVisibleRow{{ID: 1, Posted: written, VisibleSince: reposted}}, now)

	hits, misses := whenVisibleCached([]uint64{1, 2}, now.Add(time.Minute))
	if len(misses) != 1 || misses[0] != 2 {
		t.Fatalf("an id never stored is a miss: got misses %v", misses)
	}
	if r, ok := hits[1]; !ok || !r.VisibleSince.Equal(reposted) || !r.Posted.Equal(written) {
		t.Fatalf("a stored id is served back unchanged inside the TTL: got %+v ok=%v", r, ok)
	}

	hits, misses = whenVisibleCached([]uint64{1}, now.Add(whenVisibleTTL))
	if len(hits) != 0 || len(misses) != 1 {
		t.Fatalf("at the TTL the entry is a miss again, so a repost is picked up: hits %v misses %v", hits, misses)
	}
}

func TestWhenVisibleCache_SweepsExpiredEntriesOnceFull(t *testing.T) {
	resetWhenVisibleCache()
	defer resetWhenVisibleCache()

	now := time.Date(2026, 9, 7, 12, 0, 0, 0, time.UTC)
	stale := make([]whenVisibleRow, whenVisibleSweepAt)
	for i := range stale {
		stale[i] = whenVisibleRow{ID: uint64(i + 1), Posted: now, VisibleSince: now}
	}
	// Stored long enough ago that every one of them has expired by now.
	whenVisibleStore(stale, now.Add(-2*whenVisibleTTL))

	whenVisibleStore([]whenVisibleRow{{ID: whenVisibleSweepAt + 1, Posted: now, VisibleSince: now}}, now)

	whenVisibleMu.Lock()
	size := len(whenVisibleCache)
	whenVisibleMu.Unlock()
	if size != 1 {
		t.Fatalf("a store into a full cache sweeps the expired entries first: %d entries left, want 1", size)
	}
}
