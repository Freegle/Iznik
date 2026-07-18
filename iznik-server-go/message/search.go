package message

import (
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
	"strconv"
	"strings"
	"time"
)

const SEARCH_LIMIT = 100

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
	var ids []uint64

	// Two arms UNIONed rather than one `own OR reach` predicate. The OR form cost 32s in
	// production (Sentry NUXT3-DP1) because it forced a full messages_spatial scan: with
	// rippling_reach only LEFT JOINed, neither spatial index can be the access path, and
	// referencing the ~178KB polygon BLOB inside an OR branch defeats MySQL's lazy conjunct
	// evaluation, so every scanned row paid the fetch. Splitting the arms lets the reach arm
	// DRIVE from rippling_reach on the rippling_reach_polygon R-tree (MBRContains narrows to
	// the candidates, ST_Contains then decides exactly) - same 472 rows, 32s -> ~3s.
	//
	// Deliberately NOT prefiltered on outer_bound: measured against production data, 422
	// reach rows contain a point in `polygon` that their `outer_bound` does NOT contain, so
	// as a hard filter it silently drops posts the member should be able to search. Its
	// derivation (ST_Buffer(ST_Simplify(polygon, tol), tol)) can simplify away a thin spike
	// that the buffer does not restore, so it is not a true superset. MBRContains(polygon)
	// IS sound (verified: 0 rows exact-but-not-MBR) and is what the R-tree evaluates anyway.
	db.Raw(
		"SELECT ms.msgid FROM messages_spatial ms "+
			"INNER JOIN messages m ON m.id = ms.msgid "+
			"WHERE ms.successful = 0 AND m.fromuser = ? "+
			"UNION "+
			"SELECT ms.msgid FROM rippling_reach rr "+
			"INNER JOIN messages_spatial ms ON ms.msgid = rr.msgid "+
			"INNER JOIN messages m ON m.id = ms.msgid "+
			"INNER JOIN users au ON au.id = m.fromuser "+
			"WHERE ms.successful = 0 AND rr.status != 'held' "+
			"AND MBRContains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "+
			"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "+
			utils.AuthorReachCapWhere,
		myid,
		lng, lat, utils.SRID,
		lng, lat, utils.SRID,
		float64(9007199254740991), lat, lng, lat,
	).Scan(&ids)

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

func GetWordsExact(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
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

	var res []SearchResult
	db.Raw(sql, args...).Scan(&res)

	return processResults("Exact", res)
}

func GetWordsTypo(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	if len(words) > 0 {
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

		db.Raw(sql, args...).Scan(&res)
	}

	return processResults("Typo", res)
}

func GetWordsStarts(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	if len(words) > 0 {
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

		db.Raw(sql, args...).Scan(&res)
	}

	return processResults("StartsWith", res)
}

func GetWordsSounds(db *gorm.DB, words []string, limit int64, groupids []uint64, msgids []uint64, msgtype string, nelat float32, nelng float32, swlat float32, swlng float32) []SearchResult {
	var res []SearchResult

	if len(words) > 0 {
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

	sql := "SELECT messages_spatial.msgid, messages_spatial.groupid, messages_spatial.arrival, " +
		"messages_spatial.msgtype AS type, ST_Y(point) AS lat, ST_X(point) AS lng " +
		"FROM messages_spatial WHERE messages_spatial.msgid = ?" + groupFilter(groupids) + " LIMIT 1"

	db.Raw(sql, msgid).Scan(&results)

	for i := range results {
		results[i].Matchedon = Matchedon{Type: "id", Word: strconv.FormatUint(msgid, 10)}
	}

	return results
}
