package message

// Tier 9 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): message/search.go's
// four keyword-search builders (GetWordsExact, GetWordsTypo, GetWordsStarts,
// GetWordsSounds - keep-raw sites 849c08b687c3, 97b0cc9dd792, 7b1697ea1d18,
// feb5e1180e5a) each loop over the caller's search words, appending one (or
// several) bind placeholders per word - n = len(words), a runtime value with
// no application-level cap (the words come from splitting the user's raw
// search box text on word boundaries; see message.go's GetWords). That is
// exactly the shape ormharness.AssertGoldenParametrizedShape exists for: a
// FUNCTION of n, not a fixed set of named shapes.
//
// buildGetWordsExactQuery/buildGetWordsTypoQuery/buildGetWordsStartsQuery/
// buildGetWordsSoundsQuery (search.go) are the pure builders extracted from
// each GetWords* function for this proof - a behaviour-preserving refactor
// only, the actual SQL and db.Raw(...).Scan(...) call are unchanged.
//
// The other four toggles each builder also has - groupFilter (present or
// not), msgidFilter (present or not), typeFilter (Offer/Wanted/neither) and
// boxFilter (present or not) - are NOT what is unbounded here (each renders
// one of a small, fixed set of forms), so this file holds them at one
// representative non-trivial combination (all four active) across every n,
// rather than cross-multiplying them with the word-count dimension. That
// combination is exercised by wantGetWords*Query below calling straight
// into the real groupFilter/msgidFilter/typeFilter/boxFilter helpers - those
// are not the code under test, the same way markseen_tier9_test.go's
// wantInsertViewBatchQuery reuses the real utils.MESSAGE_LIKES_VIEW
// constant rather than re-deriving it.
//
// wantGetWords*Query IS an independent reconstruction of the word-loop
// itself (a slice-and-strings.Join build, rather than the production
// incremental-concatenation loop) - see markseen_tier9_test.go's file
// comment for why that independence, not a call into the function under
// test, is what makes the comparison worth something.

import (
	"strconv"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/utils"
)

// syntheticSearchWords returns n distinct, non-empty synthetic search terms
// ("term1".."termN") - non-empty matters because GetWordsTypo's template
// reads word[0:1].
func syntheticSearchWords(n int) []string {
	words := make([]string, n)
	for i := range words {
		words[i] = "term" + strconv.Itoa(i+1)
	}
	return words
}

// wantSearchToggles is the one representative (groupids, msgids, msgtype,
// box) combination held fixed across every n in this file - see the file
// comment for why the word-count dimension, not these, is what needs
// proving as a function of n.
func wantSearchToggles() (groupids []uint64, msgids []uint64, msgtype string, nelat, nelng, swlat, swlng float32, limit int64) {
	return []uint64{5, 7}, []uint64{11}, utils.OFFER, 51.52, -0.10, 51.50, -0.12, 100
}

func wantGetWordsExactQuery(n int) (string, []interface{}) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	words := syntheticSearchWords(n)

	bf := boxFilter(nelat, nelng, swlat, swlng)
	prefix := ""
	if len(bf) > 0 {
		prefix = bf + " AND "
	}

	placeholders := make([]string, len(words))
	args := make([]interface{}, 0, len(words)+1)
	for i, w := range words {
		placeholders[i] = "?"
		args = append(args, w)
	}

	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch, messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE " + prefix + "word IN (" + strings.Join(placeholders, ",") + ") " +
		groupFilter(groupids) + msgidFilter(msgids) + typeFilter(msgtype) +
		"GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?;"
	args = append(args, limit)
	return sql, args
}

func wantGetWordsTypoQuery(n int) (string, []interface{}) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	words := syntheticSearchWords(n)

	bf := boxFilter(nelat, nelng, swlat, swlng)
	prefix := ""
	if len(bf) > 0 {
		prefix = bf + " AND "
	}

	clauses := make([]string, len(words))
	args := make([]interface{}, 0, len(words)*3+1)
	for i, w := range words {
		clauses[i] = "(word LIKE ? AND damlevlim(word, ?, ?) < 2) "
		args = append(args, w[0:1]+"%", w, len(w))
	}

	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch, messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE (" + prefix + strings.Join(clauses, " OR ") + ")" +
		groupFilter(groupids) + msgidFilter(msgids) + typeFilter(msgtype) +
		" GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?"
	args = append(args, limit)
	return sql, args
}

func wantGetWordsStartsQuery(n int) (string, []interface{}) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	words := syntheticSearchWords(n)

	bf := boxFilter(nelat, nelng, swlat, swlng)
	prefix := ""
	if len(bf) > 0 {
		prefix = "(" + bf + ") AND "
	}

	clauses := make([]string, len(words))
	args := make([]interface{}, 0, len(words)+1)
	for i, w := range words {
		clauses[i] = "word LIKE ? "
		args = append(args, w+"%")
	}

	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch,  messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE " + prefix + " (" + strings.Join(clauses, " OR ") + ") " +
		groupFilter(groupids) + msgidFilter(msgids) + typeFilter(msgtype) +
		" GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?"
	args = append(args, limit)
	return sql, args
}

func wantGetWordsSoundsQuery(n int) (string, []interface{}) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	words := syntheticSearchWords(n)

	bf := boxFilter(nelat, nelng, swlat, swlng)
	prefix := ""
	if len(bf) > 0 {
		prefix = "(" + bf + ") AND "
	}

	clauses := make([]string, len(words))
	args := make([]interface{}, 0, len(words)+1)
	for i, w := range words {
		clauses[i] = "soundex = SUBSTRING(SOUNDEX(?), 1, 10) "
		args = append(args, w)
	}

	sql := "SELECT COUNT(DISTINCT messages_index.wordid) AS wordmatch,  messages_spatial.msgid, words.word, messages_spatial.groupid, messages_spatial.arrival, messages_spatial.msgtype as type, ST_Y(point) AS lat, ST_X(point) AS lng FROM messages_index " +
		"INNER JOIN words ON messages_index.wordid = words.id " +
		"INNER JOIN messages_spatial ON messages_index.msgid = messages_spatial.msgid " +
		"WHERE " + prefix + " (" + strings.Join(clauses, " OR ") + ") " +
		groupFilter(groupids) + msgidFilter(msgids) + typeFilter(msgtype) +
		" GROUP BY msgid HAVING wordmatch > 0 ORDER BY wordmatch DESC, popularity DESC LIMIT ?"
	args = append(args, limit)
	return sql, args
}

// parametrizedSearchCases renders build for n = 0 (GetWordsExact places no
// guard on an empty word list, unlike its three siblings, so the builder
// must still be proven correct there - see search.go), 1 (single-word
// search, the common case), 2 (proves multi-word joining, not just
// single-clause rendering) and 10 (a many-word search box entry; no
// application-level cap exists to test up to - see the file comment).
func parametrizedSearchCases(build func(words []string) (string, []interface{})) []ormharness.ParametrizedShapeCase {
	ns := []int{0, 1, 2, 10}
	cases := make([]ormharness.ParametrizedShapeCase, len(ns))
	for i, n := range ns {
		sql, args := build(syntheticSearchWords(n))
		cases[i] = ormharness.ParametrizedShapeCase{N: n, SQL: sql, Args: args}
	}
	return cases
}

func TestTier9_849c08b687c3(t *testing.T) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	wantSQL := func(n int) string { sql, _ := wantGetWordsExactQuery(n); return sql }
	wantArgCount := func(n int) int { _, args := wantGetWordsExactQuery(n); return len(args) }
	cases := parametrizedSearchCases(func(words []string) (string, []interface{}) {
		return buildGetWordsExactQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
	})
	ormharness.AssertGoldenParametrizedShape(t, "849c08b687c3", wantSQL, wantArgCount, cases)
}

func TestTier9_97b0cc9dd792(t *testing.T) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	wantSQL := func(n int) string { sql, _ := wantGetWordsTypoQuery(n); return sql }
	wantArgCount := func(n int) int { _, args := wantGetWordsTypoQuery(n); return len(args) }
	cases := parametrizedSearchCases(func(words []string) (string, []interface{}) {
		return buildGetWordsTypoQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
	})
	ormharness.AssertGoldenParametrizedShape(t, "97b0cc9dd792", wantSQL, wantArgCount, cases)
}

func TestTier9_7b1697ea1d18(t *testing.T) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	wantSQL := func(n int) string { sql, _ := wantGetWordsStartsQuery(n); return sql }
	wantArgCount := func(n int) int { _, args := wantGetWordsStartsQuery(n); return len(args) }
	cases := parametrizedSearchCases(func(words []string) (string, []interface{}) {
		return buildGetWordsStartsQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
	})
	ormharness.AssertGoldenParametrizedShape(t, "7b1697ea1d18", wantSQL, wantArgCount, cases)
}

func TestTier9_feb5e1180e5a(t *testing.T) {
	groupids, msgids, msgtype, nelat, nelng, swlat, swlng, limit := wantSearchToggles()
	wantSQL := func(n int) string { sql, _ := wantGetWordsSoundsQuery(n); return sql }
	wantArgCount := func(n int) int { _, args := wantGetWordsSoundsQuery(n); return len(args) }
	cases := parametrizedSearchCases(func(words []string) (string, []interface{}) {
		return buildGetWordsSoundsQuery(words, limit, groupids, msgids, msgtype, nelat, nelng, swlat, swlng)
	})
	ormharness.AssertGoldenParametrizedShape(t, "feb5e1180e5a", wantSQL, wantArgCount, cases)
}
