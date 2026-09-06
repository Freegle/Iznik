package message

import (
	"fmt"
	"sync"

	"github.com/freegle/iznik-server-go/rippling"
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
	// Roadmins/Roadmiles mirror the feed summaries' fields: drive time and road miles from
	// the member, stamped in one batched routing call when their drive-minutes budget is in
	// play, so the client's payload-only verdict and Closest sort see the same numbers the
	// server filtered and ordered by. Nil when not fetched or the engine had no answer.
	Roadmins  *float64 `json:"roadmins,omitempty" gorm:"-"`
	Roadmiles *float64 `json:"roadmiles,omitempty" gorm:"-"`
}

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

func processResults(tag string, results []SearchResult) []SearchResult {
	for i := range results {
		results[i].Matchedon.Type = tag
		results[i].Matchedon.Word = results[i].Word
	}

	return results
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

// msgidFilter restricts a keyword-search query to an explicit msgid universe - for a
// browse-scoped Nearby search, the member's reach feed (see nearbyFeedMsgIDs). Applying it
// INSIDE the query matters: each search arm caps its results (LIMIT/top-K), so filtering
// afterwards would let out-of-feed posts crowd in-feed posts out of the capped candidate
// set. Empty = no restriction. The ids come from our own queries, never user input, so the
// IN list is built directly (same pattern as groupFilter).
func msgidFilter(msgids []uint64) string {
	if len(msgids) == 0 {
		return ""
	}

	var sb strings.Builder
	sb.WriteString(" AND messages_spatial.msgid IN (")
	for i, id := range msgids {
		if i > 0 {
			sb.WriteByte(',')
		}
		sb.WriteString(strconv.FormatUint(id, 10))
	}
	sb.WriteString(") ")
	return sb.String()
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
		// The extractor now resolves utils.AuthorReachCapWhere to a single
		// fixed golden with no unresolved gap (the manifest's stale,
		// presentInCode=false 7ef7f895e8bf is the pre-fix snapshot), so this
		// is an ordinary fixed-shape multi-join query, not a many-shapes site.
		// Containment matches the feed: the committed reach, or any overflow ring
		// that admits this viewer (rippling.ViewerOverflowPaths - the same answer
		// the feed and badge give, so a post can never be scrollable but
		// unsearchable). The author cap stays outside the OR: it is the author's
		// own preference and applies however the viewer got in.
		// TWO ARMS, TWO QUERIES - deliberately not one OR.
		//
		// The committed-reach arm. Preferred source: the spatial index's id
		// list (exact, from the stored cell grids - the same authority the
		// feed and badge use), narrowed here by the same visibility and
		// author-cap conjuncts, bounded by primary key. The legacy geometry
		// SQL survives as the fallback while its columns exist; once they are
		// dropped the last resort is the outer-bound superset with each
		// candidate probed against its stored cells in Go.
		//
		// History, because this is the exact query the 2026-08-21 outage hit:
		// the SQL form is driven by the outer_bound R-tree, and ORing the
		// ring test into it removed that index (EXPLAIN: key=
		// rippling_reach_polygon rows=1 becomes key=NULL rows=62,534) and
		// full-scanned a 17GB table on every cold cache fill - 70+ concurrent
		// copies, 250 running threads, load 158. The ring test stays out of
		// every form here.
		reachIDs = searchReachArmIDs(db, lng, lat)

		// Ring arm. The posts an overflow ring admits this viewer to, resolved
		// through rippling.AdmittedMsgids - the spatial server's rasters, with
		// only the boundary band going to the JSON. The ids arrive already
		// decided, so all that is left here is the same visibility and
		// author-cap filtering the committed arm applies, bounded by primary
		// key. No ring test reaches this query at all: it is the shape, not the
		// volume, that took the site down.
		if admitted := rippling.AdmittedMsgids(db, lng, lat, utils.SRID,
			rippling.ViewerOverflowPaths(db, myid, float32(lat), float32(lng))); len(admitted) > 0 {
			var ringIDs []uint64
			rArgs := []interface{}{admitted}
			rArgs = append(rArgs, float64(9007199254740991), lat, lng, lat)

			if err := db.Table("rippling_reach rr").
				Select("ms.msgid").
				Joins("INNER JOIN messages_spatial ms ON ms.msgid = rr.msgid").
				Joins("INNER JOIN messages m ON m.id = ms.msgid").
				Joins("INNER JOIN users au ON au.id = m.fromuser").
				Where("ms.successful = 0 AND rr.status != 'held' AND rr.msgid IN (?) "+
					utils.AuthorReachCapWhere,
					rArgs...).
				Scan(&ringIDs).Error; err != nil {
				fmt.Printf("search: overflow ring arm gave up (%v)\n", err)
			}

			if len(ringIDs) > 0 {
				seen := make(map[uint64]bool, len(reachIDs))
				for _, id := range reachIDs {
					seen[id] = true
				}
				for _, id := range ringIDs {
					if !seen[id] {
						reachIDs = append(reachIDs, id)
						seen[id] = true
					}
				}
			}
		}

		storeReachUniverse(key, reachIDs, now)
	}

	// Own arm: always fresh (cheap indexed query), never cached.
	var ownIDs []uint64
	db.Table("messages_spatial ms").
		Select("ms.msgid").
		Joins("INNER JOIN messages m ON m.id = ms.msgid").
		Where("ms.successful = 0 AND m.fromuser = ?", myid).
		Scan(&ownIDs)

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

func typeFilter(msgtype string) string {
	var ret string

	switch msgtype {
	case utils.OFFER:
		ret = " AND messages_spatial.msgtype = '" + utils.OFFER + "' "
	case utils.WANTED:
		ret = " AND messages_spatial.msgtype = '" + utils.WANTED + "' "
	default:
		ret = ""
	}

	return ret
}

func boxFilter(nelatf float32, nelngf float32, swlatf float32, swlngf float32) string {
	var ret string

	// Add in some padding.  This copes with blurring and also shows some fairly nearby results which might not be
	// on the map.
	if nelatf != 0 && nelngf != 0 && swlatf != 0 && swlngf != 0 {
		nelat := strconv.FormatFloat(float64(nelatf+0.02), 'f', -1, 32)
		nelng := strconv.FormatFloat(float64(nelngf+0.02), 'f', -1, 32)
		swlat := strconv.FormatFloat(float64(swlatf-0.02), 'f', -1, 32)
		swlng := strconv.FormatFloat(float64(swlngf-0.02), 'f', -1, 32)
		srid := strconv.FormatInt(utils.SRID, 10)
		ret = " ST_Contains(ST_SRID(POLYGON(LINESTRING(" +
			"POINT(" + swlng + ", " + swlat + "), " +
			"POINT(" + swlng + ", " + nelat + "), " +
			"POINT(" + nelng + ", " + nelat + "), " +
			"POINT(" + nelng + ", " + swlat + "), " +
			"POINT(" + swlng + ", " + swlat + "))), " + srid + "), point) "
	}

	return ret
}

// buildGetWordsExactQuery is a pure SQL-builder: no database needed - see
// message_list.go's buildMTUnionAllMsgIDQuery for the established
// convention this follows. Extracted from GetWordsExact (a pure
// behaviour-preserving refactor) so the per-word bind count (n =
// len(words), unbounded - a search query can contain any number of terms)
// can be proven correct for any n via
// the retired ormharness (search_tier9_test.go, removed in d22ba1d6c) rather
// than sampled at one or two word counts - see keep-raw site 849c08b687c3
// and plans/active/orm-keepraw-adversarial-review.md §4.
func buildGetWordsExactQuery(words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) (string, []interface{}) {
	bf := boxFilter(nelat, nelng, swlat, swlng)

	if len(bf) > 0 {
		bf = bf + " AND "
	}

	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch, messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE " +
		bf +
		"word IN ("

	args := []interface{}{}

	for i, w := range words {
		if i > 0 {
			sql += ","
		}

		sql += "? "
		args = append(args, w)
	}

	sql += ") " +
		groupFilter(groupids) +
		msgidFilter(msgids) +
		typeFilter(msgtype) +
		"GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?;"

	args = append(args, limit)

	return sql, args
}

func GetWordsExact(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	// Empty words would render "word IN ()", which is invalid SQL (Error
	// 1064 in production whenever a search reduced to zero indexed words).
	// GetWordsTypo/Starts/Sounds all carry this same guard; Exact was the
	// one sibling missing it.
	if len(words) > 0 {
		sql, args := buildGetWordsExactQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)

		// keep-raw: site 849c08b687c3 - unbounded per-word bind count in a
		// hand-built word-IN shape; see buildGetWordsExactQuery.
		db.Raw(sql, args...).Scan(&res)
	}

	return processResults("Exact", res)
}

// buildGetWordsTypoQuery is a pure SQL-builder - see buildGetWordsExactQuery
// above for the convention and the reason (keep-raw site 97b0cc9dd792): the
// per-word bind count (n = len(words), unbounded) is proven for any n via
// the retired ormharness (search_tier9_test.go, removed in d22ba1d6c). Extracted
// from GetWordsTypo, a pure behaviour-preserving refactor.
func buildGetWordsTypoQuery(words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) (string, []interface{}) {
	bf := boxFilter(nelat, nelng, swlat, swlng)

	if len(bf) > 0 {
		bf = bf + " AND "
	}

	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch, messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE (" + bf

	args := []interface{}{}

	for i, word := range words {
		if i > 0 {
			sql += " OR "
		}

		prefix := word[0:1] + "%"

		sql += "(word LIKE ? AND damlevlim(word, ?, ?) < 2) "
		args = append(args, prefix, word, len(word))
	}

	sql += ")" + groupFilter(groupids) +
		msgidFilter(msgids) +
		typeFilter(msgtype) +
		" GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?"

	args = append(args, limit)

	return sql, args
}

func GetWordsTypo(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	if len(words) > 0 {
		sql, args := buildGetWordsTypoQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
		db.Raw(sql, args...).Scan(&res)
	}

	return processResults("Typo", res)
}

// buildGetWordsStartsQuery is a pure SQL-builder - see
// buildGetWordsExactQuery above for the convention and the reason (keep-raw
// site 7b1697ea1d18): the per-word bind count (n = len(words), unbounded) is
// proven for any n by the retired ormharness (removed in d22ba1d6c)
// (search_tier9_test.go). Extracted from GetWordsStarts, a pure
// behaviour-preserving refactor.
func buildGetWordsStartsQuery(words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) (string, []interface{}) {
	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch,  messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE "

	bf := boxFilter(nelat, nelng, swlat, swlng)

	if len(bf) > 0 {
		sql += "(" + bf + ") AND "
	}

	sql += " ("

	args := []interface{}{}

	for i, word := range words {
		if i > 0 {
			sql += " OR "
		}

		prefix := word + "%"

		sql += "word LIKE ? "
		args = append(args, prefix)
	}

	sql += ") " + groupFilter(groupids) +
		msgidFilter(msgids) +
		typeFilter(msgtype) +
		" GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?"

	args = append(args, limit)

	return sql, args
}

func GetWordsStarts(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	if len(words) > 0 {
		sql, args := buildGetWordsStartsQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
		db.Raw(sql, args...).Scan(&res)
	}

	return processResults("StartsWith", res)
}

// buildGetWordsSoundsQuery is a pure SQL-builder - see
// buildGetWordsExactQuery above for the convention and the reason (keep-raw
// site feb5e1180e5a): the per-word bind count (n = len(words), unbounded) is
// proven for any n by the retired ormharness (removed in d22ba1d6c)
// (search_tier9_test.go). Extracted from GetWordsSounds, a pure
// behaviour-preserving refactor.
func buildGetWordsSoundsQuery(words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) (string, []interface{}) {
	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch,  messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE "

	bf := boxFilter(nelat, nelng, swlat, swlng)

	if len(bf) > 0 {
		sql += "(" + bf + ") AND "
	}

	sql += " ("

	args := []interface{}{}

	for i, word := range words {
		if i > 0 {
			sql += " OR "
		}

		sql += "soundex = SUBSTRING(SOUNDEX(?), 1, 10) "
		args = append(args, word)
	}

	sql += ") " + groupFilter(groupids) +
		msgidFilter(msgids) +
		typeFilter(msgtype) +
		" GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?"

	args = append(args, limit)

	return sql, args
}

func GetWordsSounds(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	if len(words) > 0 {
		sql, args := buildGetWordsSoundsQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
		db.Raw(sql, args...).Scan(&res)
	}

	return processResults("SoundsLike", res)
}

// SearchByMsgID returns the message with the given id as a single-element search
// result, restricted to the supplied groups (nil groupids = no restriction, as
// used for admin/support). Returns nil if the message does not exist or is not in
// one of those groups. This lets "search by message id" return the exact message
// rather than word-matching the digits against message text.
func SearchByMsgID(db *gorm.DB, msgid uint64, groupids []uint64) []SearchResult {
	var results []SearchResult

	// len(groupids)>0
	// is the only toggle - 2 possible rendered forms, both proven by the
	// retired ormharness (shapes.json / TestTier3Shapes_a5e382bd3536,
	// removed in d22ba1d6c). Unlike groupFilter (still used unchanged by
	// GetWords*), the group-id list here is bound via GORM's native IN (?)
	// slice-bind rather than spliced as literal text (plan 7.5), so an
	// arbitrary-length group list is a single well-defined bind, not an
	// extra shape dimension.
	whereSQL := "messages_spatial.msgid = ?"
	whereArgs := []interface{}{msgid}
	if len(groupids) > 0 {
		whereSQL += " AND EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = messages_spatial.msgid AND mg.groupid IN (?) AND mg.collection = 'Approved' AND mg.deleted = 0)"
		whereArgs = append(whereArgs, groupids)
	}

	db.Table("messages_spatial").
		Select("messages_spatial.msgid, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype AS type, ST_Y(point) AS lat, ST_X(point) AS lng").
		Where(whereSQL, whereArgs...).
		Limit(1).
		Scan(&results)

	for i := range results {
		results[i].Matchedon = Matchedon{Type: "id", Word: strconv.FormatUint(msgid, 10)}
	}

	return results
}

// searchReachArmIDs resolves the committed-reach arm of the search universe:
// which live posts' current reach covers this viewer, filtered by the same
// visibility and author-cap conjuncts as the feed. Two forms, in preference
// order, mirroring the feed's reachContainmentSQL:
//
//  1. Spatial-index id list (exact, from the stored cell grids), narrowed by
//     a primary-key IN - the keyed lookup shape.
//  2. Degraded (spatial down): outer-bound superset in SQL, each candidate
//     probed against its stored cells here. Correct and bounded, just
//     slower - the emergency path.
func searchReachArmIDs(db *gorm.DB, lng, lat float64) []uint64 {
	authorCapArgs := []interface{}{float64(9007199254740991), lat, lng, lat}
	var reachIDs []uint64

	if in, partial, ok := rippling.SpatialReachIDs(db, lng, lat); ok {
		if len(partial) > 0 {
			// Impossible for healthy rows (partial meant a legacy
			// coarse-raster row); do not silently hide posts.
			fmt.Printf("search: %d partial reach ids with no legacy geometry to resolve them\n", len(partial))
		}
		// Labels-truth: the same narrowing AND discovery union the feed
		// applies, so search can never surface a post browse hides - nor
		// hide a discovered post browse shows (the file's own invariant:
		// never scrollable but unsearchable). The SQL below still applies
		// every visibility conjunct to the discovered ids.
		ids := make([]uint64, len(in))
		for i, id := range in {
			ids[i] = uint64(id)
		}
		verdicts, discovered, _ := rippling.LabelVerdictsWithDiscover(lat, lng, ids)
		in = rippling.DropLabelOut(in, verdicts)
		for _, id := range discovered {
			in = append(in, int64(id))
		}
		db.Table("rippling_reach rr").
			Select("ms.msgid").
			Joins("INNER JOIN messages_spatial ms ON ms.msgid = rr.msgid").
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Joins("INNER JOIN users au ON au.id = m.fromuser").
			Where("ms.successful = 0 AND rr.status != 'held' "+
				"AND rr.msgid IN (?) "+utils.AuthorReachCapWhere,
				append([]interface{}{in}, authorCapArgs...)...).
			Scan(&reachIDs)
		return reachIDs
	}

	// Degraded: outer-bound superset + Go-side cells probe. Rows the probe
	// cannot decide (a RETIRED grid: the label + union threshold replaced
	// its cells) get one batched label evaluation - the same rescue the
	// feed's degraded path applies (filterProbed) - so a spatial-index
	// outage alone does not desynchronise search from browse; only spatial
	// AND routing down together fails closed.
	var cands []struct {
		Msgid uint64 `gorm:"column:msgid"`
		Cells []byte `gorm:"column:cells"`
	}
	args := []interface{}{lng, lat, utils.SRID}
	db.Table("rippling_reach rr").
		Select("ms.msgid, "+rippling.ReachCellsExpr(db)+" AS cells").
		Joins("INNER JOIN messages_spatial ms ON ms.msgid = rr.msgid").
		Joins("INNER JOIN messages m ON m.id = ms.msgid").
		Joins("INNER JOIN users au ON au.id = m.fromuser").
		Where("ms.successful = 0 AND rr.status != 'held' "+
			"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) "+
			utils.AuthorReachCapWhere,
			append(args, authorCapArgs...)...).
		Scan(&cands)
	var undecided []uint64
	for _, c := range cands {
		if in, ok := rippling.CellSetContains(c.Cells, lng, lat); ok && in {
			reachIDs = append(reachIDs, c.Msgid)
		} else if len(c.Cells) == 0 {
			undecided = append(undecided, c.Msgid)
		}
	}
	reachIDs = append(reachIDs, rippling.RescueUndecided(lat, lng, undecided)...)
	return reachIDs
}

// dropRippledIn keeps only the results one of the given groups holds as its OWN post:
// a messages_groups row with rippled_in = 0 on that group. It is the search arm of the
// Approved Messages "Only this group's own posts (hide rippled-in)" filter
// (?originonly=true), whose listing arm is the mg.rippled_in = 0 clause in
// message_list.go. No groups means nothing to scope to, so nothing is dropped.
func dropRippledIn(db *gorm.DB, results []SearchResult, groupids []uint64) []SearchResult {
	if len(results) == 0 || len(groupids) == 0 {
		return results
	}

	ids := make([]uint64, 0, len(results))
	for _, r := range results {
		ids = append(ids, r.Msgid)
	}

	var own []uint64
	db.Table("messages_groups").
		Select("DISTINCT msgid").
		Where("msgid IN ? AND groupid IN ? AND rippled_in = 0 AND deleted = 0", ids, groupids).
		Scan(&own)

	keep := make(map[uint64]bool, len(own))
	for _, id := range own {
		keep[id] = true
	}

	kept := results[:0]
	for _, r := range results {
		if keep[r.Msgid] {
			kept = append(kept, r)
		}
	}

	return kept
}
