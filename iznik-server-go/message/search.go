package message

import (
	"fmt"
	"sync"

	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
	"strconv"
	"strings"
	"time"
)

const SEARCH_LIMIT = 100

// reachUniverseTTL is how long a member's reach-arm universe (the msgids whose rippling
// reach covers them) may be served from cache. The reach arm is the expensive half of
// nearbyFeedMsgIDs (exact ST_Contains over the sandwich-prefiltered candidates with
// ~178KB polygons), and members search repeatedly in quick succession ("headboard",
// "wall tiles", "table"...), recomputing an answer that only changes on the rippling
// engine's minutes-cadence ticks. 60s staleness on reach MEMBERSHIP is well inside that
// cadence. The member's OWN posts are deliberately NOT cached - that arm is a cheap
// indexed query and keeping it fresh means a just-posted item is searchable immediately.
const reachUniverseTTL = 60 * time.Second

// reachUniverseMaxEntries bounds the cache; each entry is a few KB, and the crude
// clear-on-overflow keeps the worst case bounded without an LRU's bookkeeping.
const reachUniverseMaxEntries = 10000

type reachUniverseEntry struct {
	ids     []uint64
	expires time.Time
}

var (
	reachUniverseMu    sync.Mutex
	reachUniverseCache = map[string]reachUniverseEntry{}
)

// reachUniverseKey identifies a cached reach universe: the member plus their location
// (4dp ~ 11m - a location change invalidates immediately).
func reachUniverseKey(myid uint64, lat float64, lng float64) string {
	return fmt.Sprintf("%d:%.4f:%.4f", myid, lat, lng)
}

// cachedReachUniverse returns the cached reach-arm ids for the key, or (nil, false).
// Both it and storeReachUniverse COPY the slice at the cache boundary: nearbyFeedMsgIDs
// returns the reach slice directly when the member has no own posts, so handing out (or
// retaining) the internal backing array would let any future caller that sorts or appends
// in place silently corrupt the cache for every subsequent request on this node. The copy
// is a few KB against a multi-second query saved - seal it here, at one choke point,
// rather than relying on every caller staying read-only.
func cachedReachUniverse(key string, now time.Time) ([]uint64, bool) {
	reachUniverseMu.Lock()
	defer reachUniverseMu.Unlock()
	e, ok := reachUniverseCache[key]
	if !ok || now.After(e.expires) {
		return nil, false
	}
	out := make([]uint64, len(e.ids))
	copy(out, e.ids)
	return out, true
}

// PrimeReachUniverse lets the browse feed pre-warm the search cache: loading the Nearby
// feed computes exactly this reach membership (isochrone fetchReachCandidates - same
// successful=0 / not-held / polygon-containment / author-cap predicate), and members load
// the feed moments before they search. Priming here makes a member's FIRST search hit the
// warm path (~0.15s) instead of re-running the multi-second containment the feed just did.
// The key is member+location from user.GetLatLng on both sides, so it can only ever hit
// for the same view of the world; a mismatch is just a cache miss.
func PrimeReachUniverse(myid uint64, lat float64, lng float64, ids []uint64) {
	storeReachUniverse(reachUniverseKey(myid, lat, lng), ids, time.Now())
}

func storeReachUniverse(key string, ids []uint64, now time.Time) {
	kept := make([]uint64, len(ids))
	copy(kept, ids)
	reachUniverseMu.Lock()
	defer reachUniverseMu.Unlock()
	if len(reachUniverseCache) >= reachUniverseMaxEntries {
		reachUniverseCache = map[string]reachUniverseEntry{}
	}
	reachUniverseCache[key] = reachUniverseEntry{ids: kept, expires: now.Add(reachUniverseTTL)}
}

type Matchedon struct {
	Type string `json:"type"`
	Word string `json:"word"`
}

type SearchResult struct {
	ID        uint64    `json:"-" gorm:"primary_key"`
	Msgid     uint64    `json:"id"`
	Arrival   time.Time `json:"arrival"`
	Groupid   uint64    `json:"groupid"`
	Lat       float64   `json:"lat"`
	Lng       float64   `json:"lng"`
	Tag       string    `json:"-"`
	Word      string    `json:"word"`
	Type      string    `json:"type"`
	Matchedon Matchedon `json:"matchedon" gorm:"-"`
	// Distance is the great-circle miles from the searching member to this post (blurred coords,
	// the same measure the browse feed exposes). Populated by the Search handler so search can
	// reflect the member's "How far away" slider and "Closest" sort. 0 when the member has no
	// known location (logged out).
	Distance float64 `json:"distance" gorm:"-"`
}

// GetWords tokenises a search string into lower-cased, stopword-filtered words.
// Used for the vector search's lexical-match tier (the query terms and the
// keyword boost). The old keyword-index query functions that also lived here
// were removed when the keyword index was retired.
func GetWords(search string) []string {
	common := [...]string{
		"the", "old", "new", "please", "thanks", "with", "offer", "taken", "wanted", "received", "attachment", "offered", "and",
		"freegle", "freecycle", "for", "large", "small", "are", "but", "not", "you", "all", "any", "can", "her", "was", "one", "our",
		"out", "day", "get", "has", "him", "how", "now", "see", "two", "who", "did", "its", "let", "she", "too", "use", "plz",
		"of", "to", "in", "it", "is", "be", "as", "at", "so", "we", "he", "by", "or", "on", "do", "if", "me", "my", "up", "an", "go", "no", "us", "am",
		"working", "broken", "black", "white", "grey", "blue", "green", "red", "yellow", "brown", "orange", "pink", "machine", "size", "set",
		"various", "assorted", "different", "bits", "ladies", "gents", "kids", "nice", "brand", "pack", "soft",
		"top", "plastic", "electric", "unopened",
	}

	// Remove all punctuation and split on word boundaries
	words := strings.FieldsFunc(strings.ToLower(search), func(r rune) bool {
		return !((r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9'))
	})

	// Filter out common words
	var filtered []string
	for _, word := range words {
		if len(word) >= 2 {
			found := false
			for _, c := range common {
				if word == c {
					found = true
					break
				}
			}

			if !found {
				// Trim space
				filtered = append(filtered, strings.TrimSpace(word))
			}
		}
	}

	return filtered
}

func groupFilter(groupids []uint64) string {
	ret := ""

	if len(groupids) > 0 {
		ret = " AND EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = messages_spatial.msgid AND mg.groupid IN ("
		for i, id := range groupids {
			if i > 0 {
				ret += ","
			}
			ret += strconv.FormatUint(id, 10)
		}
		ret += ") AND mg.collection = 'Approved' AND mg.deleted = 0) "
	}

	return ret
}

// nearbyFeedMsgIDs returns the msgids of the posts that make up the member's Nearby browse
// feed - the universe a browse-scoped search must search within (Discourse 9933: "the set of
// posts you search in should precisely match the set you'd see if you scrolled to the very
// bottom of the infinite scroll"). It mirrors the reach arm of isochrone.Messages: open posts
// whose rippling reach polygon covers the member (skipping held reaches, and respecting the
// post AUTHOR's outbound distance cap via utils.AuthorReachCapWhere), plus the member's own
// open posts. Only spatial posts are searchable (both search arms index messages_spatial), so
// the own-posts arm here is spatial-limited: a PENDING own post appears on the feed but is not
// in the search index, so it cannot appear in search results either way. Keep this predicate
// in sync with isochrone/message.go fetchReachCandidates (same reach semantics; the SQL shape
// differs deliberately - see the query comment below).
func nearbyFeedMsgIDs(db *gorm.DB, myid uint64, lat float64, lng float64) []uint64 {
	// Two arms rather than one `own OR reach` predicate. The OR form cost 32s in
	// production (Sentry NUXT3-DP1) because it forced a full messages_spatial scan: with
	// rippling_reach only LEFT JOINed, neither spatial index can be the access path, and
	// referencing the ~178KB polygon BLOB inside an OR branch defeats MySQL's lazy conjunct
	// evaluation, so every scanned row paid the fetch. Splitting the arms lets the reach arm
	// DRIVE from rippling_reach on the rippling_reach_polygon R-tree (MBRContains narrows to
	// the candidates, ST_Contains then decides exactly) - same 472 rows, 32s -> ~3s.
	//
	// The sandwich prefilter is kept: outer_bound is a genuine superset of polygon for every
	// reach row a browse-scoped search can return, so it rejects cheaply before the 178KB
	// polygon is touched (measured ~2x faster than driving the polygon R-tree alone).
	// Verified on production: for real derived bounds, 0 rows have a point inside `polygon`
	// but outside `outer_bound` (486 true hits for the reporting member, 4,095 across other
	// sampled viewer points). The only rows where outer_bound rejects a polygon hit are the
	// deliberate degenerate-POINT sentinels, which are assigned to COMPLETED posts to prune
	// them from the R-tree - and ms.successful = 0 already excludes those (verified: 0 open
	// posts carry a POINT sentinel, all 22,139 are completed).
	//
	// (An inner_bound fast-accept was also measured and REJECTED: it decides only ~23% of
	// candidates and made the query consistently slower.)
	//
	// The reach arm is served from a 60s per-member cache (see reachUniverseTTL): reach
	// membership only changes on the rippling engine's minutes-cadence ticks, and members
	// search repeatedly in quick succession, so consecutive searches skip the expensive
	// containment entirely (measured: ~1.4s cold, ~0.15s warm end-to-end). The own arm
	// stays fresh so a just-posted item is searchable immediately.
	now := time.Now()
	key := reachUniverseKey(myid, lat, lng)
	reachIDs, hit := cachedReachUniverse(key, now)
	if !hit {
		db.Raw(
			"SELECT ms.msgid FROM rippling_reach rr "+
				"INNER JOIN messages_spatial ms ON ms.msgid = rr.msgid "+
				"INNER JOIN messages m ON m.id = ms.msgid "+
				"INNER JOIN users au ON au.id = m.fromuser "+
				"WHERE ms.successful = 0 AND rr.status != 'held' "+
				"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) "+
				"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "+
				utils.AuthorReachCapWhere,
			lng, lat, utils.SRID,
			lng, lat, utils.SRID,
			float64(9007199254740991), lat, lng, lat,
		).Scan(&reachIDs)
		storeReachUniverse(key, reachIDs, now)
	}

	// Own arm: always fresh (cheap indexed query), never cached.
	var ownIDs []uint64
	db.Raw(
		"SELECT ms.msgid FROM messages_spatial ms "+
			"INNER JOIN messages m ON m.id = ms.msgid "+
			"WHERE ms.successful = 0 AND m.fromuser = ?",
		myid,
	).Scan(&ownIDs)

	if len(ownIDs) == 0 {
		return reachIDs
	}

	seen := make(map[uint64]bool, len(reachIDs)+len(ownIDs))
	ids := make([]uint64, 0, len(reachIDs)+len(ownIDs))
	for _, id := range reachIDs {
		if !seen[id] {
			seen[id] = true
			ids = append(ids, id)
		}
	}
	for _, id := range ownIDs {
		if !seen[id] {
			seen[id] = true
			ids = append(ids, id)
		}
	}
	return ids
}

// SearchByMsgID returns the message with the given id as a single-element search
// result, restricted to the supplied groups (nil groupids = no restriction, as
// used for admin/support). Returns nil if the message does not exist or is not in
// one of those groups. This lets "search by message id" return the exact message
// rather than word-matching the digits against message text.
func SearchByMsgID(db *gorm.DB, msgid uint64, groupids []uint64) []SearchResult {
	var results []SearchResult

	sql := "SELECT messages_spatial.msgid, messages_spatial.groupid, messages_spatial.arrival, " +
		"messages_spatial.msgtype AS type, ST_Y(point) AS lat, ST_X(point) AS lng " +
		"FROM messages_spatial WHERE messages_spatial.msgid = ?" + groupFilter(groupids) + " LIMIT 1"

	db.Raw(sql, msgid).Scan(&results)

	for i := range results {
		results[i].Matchedon = Matchedon{Type: "id", Word: strconv.FormatUint(msgid, 10)}
	}

	return results
}
