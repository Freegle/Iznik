// Package browsecount remembers the browse badge count for a short while.
//
// The badge is the number on the nav that says how many unseen posts there are. Working it
// out is the largest single piece of apiv2's wall time: about 24,700 requests an hour, and
// the query behind it walks the whole open-post set, probing each row against the viewer's
// communities and their seen markers. Measured against production it takes 380-720ms
// depending on how many communities the viewer belongs to, and the answer barely differs
// between one request and the next.
//
// Rewriting the query was tried first and does not work: the planner picks the same
// whole-table drive order however the joins are arranged, and forcing it the other way
// round with a subquery helps a viewer in two communities and makes a viewer in five
// noticeably worse. The cost is the shape of the question, not the way it is asked.
//
// The one change that has to appear at once is the viewer marking posts as seen, because
// the badge dropping to zero is how they know it worked. So marking seen clears that
// viewer's entry as it writes, and this cache never delays it. Everything else - a new post
// arriving, or a seen marker written by the digest job in another process - shows up within
// the few seconds below, which is the right trade for a number on a badge.
package browsecount

import (
	"sync"
	"time"
)

// TTL is how long a count is reused for. Short enough that a new post shows up promptly,
// long enough to take the repeat work out of a member clicking around the site.
const TTL = 30 * time.Second

// maxEntries bounds the map. One entry per viewer who has loaded a page recently, so this
// is generous for the live population.
const maxEntries = 20000

// An entry is only reused for the same question. A viewer who moves the distance slider
// (miles or its drive-minutes budget) or switches between "nearby" and "my communities"
// is asking a different one.
type entry struct {
	browseView  string
	maxDistance float64
	maxMinutes  float64
	count       uint64
	expires     time.Time
}

var (
	mu    sync.Mutex
	cache = map[uint64]entry{}
)

// Get returns a remembered count for this viewer and question, if there is a live one.
func Get(myid uint64, browseView string, maxDistance float64, maxMinutes float64) (uint64, bool) {
	if myid == 0 {
		return 0, false
	}

	mu.Lock()
	defer mu.Unlock()

	e, ok := cache[myid]
	if !ok || e.browseView != browseView || e.maxDistance != maxDistance || e.maxMinutes != maxMinutes {
		return 0, false
	}
	if !time.Now().Before(e.expires) {
		delete(cache, myid)
		return 0, false
	}

	return e.count, true
}

// Put remembers a count for this viewer.
func Put(myid uint64, browseView string, maxDistance float64, maxMinutes float64, count uint64) {
	if myid == 0 {
		return
	}

	mu.Lock()
	defer mu.Unlock()

	if _, replacing := cache[myid]; !replacing && len(cache) >= maxEntries {
		dropExpired()

		// Still full of live entries, so don't store this one. The request is answered
		// normally and the next one works it out again: slower, but it never takes an
		// answer away from another viewer to make room.
		if len(cache) >= maxEntries {
			return
		}
	}

	cache[myid] = entry{
		browseView:  browseView,
		maxDistance: maxDistance,
		maxMinutes:  maxMinutes,
		count:       count,
		expires:     time.Now().Add(TTL),
	}
}

// Invalidate forgets this viewer's count. Called when they mark posts as seen, so the badge
// drops immediately rather than after the TTL.
func Invalidate(myid uint64) {
	if myid == 0 {
		return
	}

	mu.Lock()
	defer mu.Unlock()
	delete(cache, myid)
}

// dropExpired removes entries whose time is up. Callers must hold mu.
func dropExpired() {
	now := time.Now()

	for k, e := range cache {
		if !now.Before(e.expires) {
			delete(cache, k)
		}
	}
}
