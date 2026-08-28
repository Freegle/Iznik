package newsfeed

// Road-aware region tagging for ChitChat. Each thread stores the road-network
// region (`newsfeed.leaf`) its location belongs to, and the feed narrows a
// member's distance filter to regions their travel-time budget can actually
// REACH by road - so the far bank of an estuary drops out of "chitchat near
// me" even when it is well inside the crow-flies radius. Fail-soft top to
// bottom: no routing server, no stored leaf, or no minutes budget means the
// classic radius behaviour, unchanged.

import (
	"encoding/json"
	"fmt"
	"net/http"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/roadblur"
)

var leafClient = &http.Client{Timeout: 3 * time.Second}

// leafFor asks the routing server which region a point belongs to. Returns
// nil (store NULL) when the engine is not deployed or cannot answer - the
// backfill command retries later.
func leafFor(lat, lng float64) *int32 {
	if lat == 0 && lng == 0 {
		return nil
	}
	if !roadblur.RoutingHealthy() {
		return nil
	}
	resp, err := leafClient.Get(fmt.Sprintf("%s/v1/leaf?lat=%f&lng=%f", roadblur.RoutingURL(), lat, lng))
	if err != nil {
		roadblur.MarkRoutingFailure()
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		if resp.StatusCode != http.StatusServiceUnavailable {
			roadblur.MarkRoutingFailure()
		}
		return nil
	}
	var parsed struct {
		Leaves []int32 `json:"leaves"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil || len(parsed.Leaves) == 0 {
		return nil
	}
	return &parsed.Leaves[0]
}

// Reachable-leaves cache: which regions a member's travel-time budget covers.
// A member's home and budget change rarely, so this is answered from cache for
// the lifetime of a browsing session.
const memberLeavesTTL = 10 * time.Minute

type memberLeavesEntry struct {
	minutes uint64
	leaves  []int32
	expires time.Time
}

var (
	memberLeavesMu    sync.Mutex
	memberLeavesCache = map[uint64]memberLeavesEntry{}
)

// memberReachableLeaves returns the region ids reachable from (lat,lng)
// within the member's chosen minutes, or nil when the engine cannot answer
// (callers then keep the pure radius filter).
func memberReachableLeaves(myid uint64, lat, lng float64, minutes uint64) []int32 {
	if minutes == 0 || (lat == 0 && lng == 0) {
		return nil
	}
	now := time.Now()
	memberLeavesMu.Lock()
	if e, ok := memberLeavesCache[myid]; ok && e.minutes == minutes && now.Before(e.expires) {
		memberLeavesMu.Unlock()
		return e.leaves
	}
	memberLeavesMu.Unlock()

	if !roadblur.RoutingHealthy() {
		return nil
	}
	resp, err := leafClient.Get(fmt.Sprintf("%s/v1/reach-labels?lat=%f&lng=%f&minutes=%d",
		roadblur.RoutingURL(), lat, lng, minutes))
	if err != nil {
		roadblur.MarkRoutingFailure()
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		if resp.StatusCode != http.StatusServiceUnavailable {
			roadblur.MarkRoutingFailure()
		}
		return nil
	}
	var parsed struct {
		Leaves []int32 `json:"leaves"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil || len(parsed.Leaves) == 0 {
		return nil
	}

	memberLeavesMu.Lock()
	memberLeavesCache[myid] = memberLeavesEntry{minutes: minutes, leaves: parsed.Leaves, expires: now.Add(memberLeavesTTL)}
	if len(memberLeavesCache) > 100000 {
		// Crude reset rather than LRU: the cache refills in one call per
		// active member and this bound is far above concurrent-user counts.
		for k := range memberLeavesCache {
			delete(memberLeavesCache, k)
		}
	}
	memberLeavesMu.Unlock()
	return parsed.Leaves
}
