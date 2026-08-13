package dashboard

import (
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"sort"
	"sync"
	"time"
)

// The MT dashboard's trend widgets are the most expensive read path apiv2 serves, and the
// expense is almost entirely REPEATED work. A 6.15h daytime sample of >=1s calls showed
// UsersReplying 17 calls/204s, PopularPosts 16/95s, Happiness 13/63s, RecentCounts 9/34s,
// UsersPosting 5/28s - 438 DB-bound seconds, and segmented by (range, scope) it was
// (7d, allgroups) 67 slow calls, (365d, allgroups) 24, (365d, systemwide) 7. That is many
// moderators asking the same handful of questions, not one user doing something unusual.
// Because db3's apiv2 reads from db3's own mysqld, every one of those seconds lands on the
// cluster's write node.
//
// Two separate problems, two mechanisms here:
//
//   - Repeats across time: a moderator reloading the dashboard, or several moderators of the
//     same groups loading it within minutes, re-derive an identical answer. A TTL cache fixes
//     that.
//   - Repeats at the same instant: a component that outruns the 50s gateway timeout is
//     retried by the client while the original is still running, so copies STACK. That is how
//     ModeratorsActive once pinned db3 with 19+ concurrent copies (see getModeratorsActive).
//     A TTL cache does NOT help here, because nothing is in the cache yet - the first call has
//     not returned. Single-flight fixes that: concurrent callers asking the identical question
//     wait for the one in-flight computation instead of starting their own.
//
// Deliberately NOT cached: /group/work and anything moderators act on. These are trend
// widgets - "who replied most this month", "how many posts arrived" - where minutes of
// staleness is invisible and re-deriving it from raw tables on every page load is the bug.

// componentCacheMaxEntries bounds the cache. Keys are per (component, group scope, date
// range), so a busy estate with many moderator group-sets and custom ranges is the growth
// case. Clear-on-overflow keeps the worst case bounded without an LRU's bookkeeping, the
// same trade-off message.reachUniverseCache makes.
const componentCacheMaxEntries = 2000

// componentNegativeTTL is how long an empty or nil answer is reused. Empty is what a
// component returns when it hit its deadline or errored, so it must expire quickly - a
// moderator should not stare at an empty widget for half an hour because one query timed
// out. Single-flight still collapses any storm that follows.
const componentNegativeTTL = 60 * time.Second

type cachedComponentResult struct {
	val     interface{}
	expires time.Time
}

// inflightComponent is one computation other callers can wait on. val is written before
// done is closed, so a waiter that has received from done is guaranteed to see it.
type inflightComponent struct {
	done chan struct{}
	val  interface{}
}

var (
	componentMu       sync.Mutex
	componentCache    = map[string]cachedComponentResult{}
	componentInflight = map[string]*inflightComponent{}
)

// componentTTL is how long each component's answer may be reused.
//
// The widths are chosen against what the number MEANS, not against how expensive it is:
// a leaderboard or a count over a month is a trend, and a wider range is more of a trend
// still, so a year-range view tolerates more staleness than a week-range one.
// DiscourseTopics is an external Discourse fetch (~1,100 identical calls/day) with no DB
// involvement, so its TTL is about being a good citizen upstream rather than DB relief.
func componentTTL(comp string, rangeDays int) time.Duration {
	switch comp {
	case "DiscourseTopics":
		return 120 * time.Second
	case "RecentCounts", "PopularPosts", "UsersPosting", "UsersReplying", "ModeratorsActive", "Happiness":
		if rangeDays > 31 {
			return 30 * time.Minute
		}
		return 5 * time.Minute
	}
	// Everything else already reads the nightly stats rollup and is cheap; leave it alone
	// rather than adding staleness for no gain.
	return 0
}

// componentCacheKey identifies one answer. The group scope is hashed rather than listed
// because a systemwide scope is ~440 ids; sha256 rather than a cheap non-cryptographic hash
// because a collision here would serve one moderator's groups' figures to another's.
func componentCacheKey(comp string, groupIDs []uint64, startQ, endQ string, systemwide bool) string {
	sorted := make([]uint64, len(groupIDs))
	copy(sorted, groupIDs)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i] < sorted[j] })

	h := sha256.New()
	buf := make([]byte, 8)
	for _, id := range sorted {
		binary.BigEndian.PutUint64(buf, id)
		h.Write(buf)
	}

	return fmt.Sprintf("%s|%s|%s|%t|%s", comp, startQ, endQ, systemwide, hex.EncodeToString(h.Sum(nil)[:16]))
}

// cachedComponent serves comp's answer from cache, joins an identical in-flight
// computation, or runs compute itself - in that order. ttl <= 0 runs compute directly.
func cachedComponent(key string, ttl time.Duration, compute func() interface{}) interface{} {
	if ttl <= 0 {
		return compute()
	}

	componentMu.Lock()
	if e, ok := componentCache[key]; ok && time.Now().Before(e.expires) {
		componentMu.Unlock()
		return e.val
	}
	if call, ok := componentInflight[key]; ok {
		componentMu.Unlock()
		<-call.done
		return call.val
	}
	call := &inflightComponent{done: make(chan struct{})}
	componentInflight[key] = call
	componentMu.Unlock()

	// The cleanup is deferred so that a panic inside compute still releases every waiter
	// (with a nil answer) instead of parking them on a channel that never closes.
	completed := false
	defer func() {
		componentMu.Lock()
		delete(componentInflight, key)
		if completed {
			if len(componentCache) >= componentCacheMaxEntries {
				componentCache = map[string]cachedComponentResult{}
			}
			keep := ttl
			if isEmptyComponentResult(call.val) {
				keep = componentNegativeTTL
			}
			componentCache[key] = cachedComponentResult{val: call.val, expires: time.Now().Add(keep)}
		}
		componentMu.Unlock()
		close(call.done)
	}()

	// Run outside the lock: two different components must not serialise against each other.
	call.val = compute()
	completed = true
	return call.val
}

// isEmptyComponentResult reports whether a component produced nothing - which for every
// component here means either "no data in range" or "the query failed or hit its deadline".
// The two are indistinguishable from the outside, so both get the short negative TTL.
func isEmptyComponentResult(v interface{}) bool {
	switch t := v.(type) {
	case nil:
		return true
	case []map[string]interface{}:
		return len(t) == 0
	case map[string]int64:
		return len(t) == 0
	case map[string]interface{}:
		return len(t) == 0
	case string:
		return t == ""
	}
	return false
}

// resetComponentCache drops all cached and in-flight state. Tests only: package-level
// caches otherwise leak answers between cases.
func resetComponentCache() {
	componentMu.Lock()
	defer componentMu.Unlock()
	componentCache = map[string]cachedComponentResult{}
	componentInflight = map[string]*inflightComponent{}
}
