package userdump

import (
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// fakeLoki records every LogQL query it is asked for and returns canned entries
// keyed by a substring of the query, so tests can assert on the SHAPE of the
// queries the dump issues - which is where the cost lives.
type fakeLoki struct {
	mu      sync.Mutex
	queries []string
	limits  []int
	starts  []int64
	ends    []int64
	byMatch map[string][]lokiEntry
	errOn   string
}

// apiLinesAt is pass A's api lines for member 42 at the given times - the
// index pass D searches api_headers by.
func apiLinesAt(times ...int64) map[string][]lokiEntry {
	var lines []lokiEntry
	for i, t := range times {
		lines = append(lines, lokiEntry{tsNs: t, source: "api", line: fmt.Sprintf(`{"user_id":42,"n":%d}`, i)})
	}
	return map[string][]lokiEntry{`source="api", user_bucket="10"`: lines}
}

func (f *fakeLoki) query(logql string, startNs, endNs int64, limit int) ([]lokiEntry, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.queries = append(f.queries, logql)
	f.limits = append(f.limits, limit)
	f.starts = append(f.starts, startNs)
	f.ends = append(f.ends, endNs)
	if f.errOn != "" && strings.Contains(logql, f.errOn) {
		return nil, assert.AnError
	}
	for match, entries := range f.byMatch {
		if strings.Contains(logql, match) {
			if len(entries) > limit {
				entries = entries[:limit]
			}
			return entries, nil
		}
	}
	return nil, nil
}

func TestClampLokiStart(t *testing.T) {
	end := time.Date(2026, 8, 5, 12, 0, 0, 0, time.UTC).UnixNano()

	// The dump's own default is 90 days, which production Loki rejects outright
	// with "the query time range exceeds the limit" - so it must be narrowed.
	ninety := end - int64(90*24*time.Hour)
	assert.Equal(t, end-int64(maxLokiRange), clampLokiStart(ninety, end))

	// A window Loki will serve is left exactly as asked for.
	week := end - int64(7*24*time.Hour)
	assert.Equal(t, week, clampLokiStart(week, end))

	// Exactly at the limit is still fine.
	atLimit := end - int64(maxLokiRange)
	assert.Equal(t, atLimit, clampLokiStart(atLimit, end))
}

func TestSplitRange(t *testing.T) {
	// A window shorter than the span is one range, untouched.
	one := splitRange(0, 10, 100)
	assert.Equal(t, []nsRange{{0, 10}}, one)

	// A 30d window at a 15d span is exactly two halves.
	span := int64(halfSpan)
	two := splitRange(0, 2*span, span)
	assert.Equal(t, []nsRange{{0, span}, {span, 2 * span}}, two)

	// An uneven remainder still ends exactly at end.
	three := splitRange(0, 2*span+5, span)
	assert.Len(t, three, 3)
	assert.Equal(t, 2*span+5, three[2].end)
}

// Pass A must stay INDEX-NARROWED: the coarse user_bucket label, then the exact
// user_id - never a bare `| json` over every Freegle line, which took over a
// minute for a single day against production. It is one query per source
// group, so the busiest source cannot use up the line cap and crowd out the
// rest.
func TestCollectLoki_PassAIsABucketLookupPerSourceGroup(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`source="api", user_bucket="10"`: {{tsNs: 5, source: "api", line: `{"user_id":42}`}},
	}}

	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.NoError(t, err)
	assert.Equal(t, 1, n)

	var passA []string
	for _, q := range f.queries {
		if strings.Contains(q, `user_bucket="10"} | user_id="42"`) && !strings.Contains(q, "count_over_time") {
			passA = append(passA, q)
		}
	}
	assert.Len(t, passA, len(labelledSourceGroups))
	for _, q := range passA {
		assert.NotContains(t, q, "| json", "pass A must be an index lookup, not a parse of every line")
	}
	for _, g := range labelledSourceGroups {
		assert.Contains(t, strings.Join(passA, "\n"), `source`+g+`, user_bucket="10"`)
	}
}

// Every `| json` or regex pass must put a `|=` line filter BEFORE the parse
// stage - the parser is the expensive part, and the substring filter skips
// the lines that cannot match (~7x measured against production).
func TestCollectLoki_EveryParseHasALineFilterFirst(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`source="api", user_bucket="10"`: {{tsNs: 5, source: "api", line: `{"session_id":"s1"}`}},
	}}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, []string{"A@B.com"}, end-int64(24*time.Hour), end)
	assert.NoError(t, err)

	for _, q := range f.queries {
		if jsonAt := strings.Index(q, "| json"); jsonAt >= 0 {
			filterAt := strings.Index(q, "|=")
			assert.True(t, filterAt >= 0 && filterAt < jsonAt,
				"query parses without a preceding line filter: %s", q)
		}
		if regexAt := strings.Index(q, "|~"); regexAt >= 0 {
			filterAt := strings.Index(q, "|=")
			assert.True(t, filterAt >= 0 && filterAt < regexAt,
				"regex query without a preceding |= prefilter: %s", q)
		}
	}
}

// The slim unlabelled sources are parsed separately and narrowly: never the
// api/client firehose by source selector, and never api_headers (its own
// bounded pass covers that).
func TestCollectLoki_UnlabelledSourcesAreQueriedSeparatelyAndNarrowly(t *testing.T) {
	f := &fakeLoki{}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, []string{"a@b.com"}, end-int64(24*time.Hour), end)
	assert.NoError(t, err)

	var jsonPass, emailPass string
	for _, q := range f.queries {
		if strings.Contains(q, "source=~") && strings.Contains(q, "| json") {
			jsonPass = q
		}
		// escapeLokiRegex escapes the dot for the regex and the backslash for
		// the string, so the address appears as a@b\\.com.
		if strings.Contains(q, "|~") && strings.Contains(q, `a@b\\.com`) {
			emailPass = q
		}
	}

	assert.NotEmpty(t, jsonPass, "the unlabelled sources are still collected")
	assert.Contains(t, jsonPass, unlabelledSources)

	assert.NotEmpty(t, emailPass, "email addresses are still searched for")
	assert.Contains(t, emailPass, unlabelledSources,
		"the email regex must not scan the whole app")

	// The heavyweight sources must never be swept by a source-regex selector:
	// api/client only ever appear with the indexed user_id label alongside,
	// and api_headers only in its own bounded single-source pass.
	assert.NotContains(t, unlabelledSources, "api")
	assert.NotContains(t, unlabelledSources, "client")
	assert.NotContains(t, unlabelledSources, "chat_reply")
	for _, q := range f.queries {
		if strings.Contains(q, `source="client"`) {
			assert.Contains(t, q, `user_id=`,
				"client-source query without the indexed user_id label: %s", q)
		}
	}
}

// The email prefilter is the lowercased address (case-sensitive |=), with the
// case-insensitive regex kept as the exact match behind it.
func TestCollectLoki_EmailPassPrefiltersLowercased(t *testing.T) {
	f := &fakeLoki{}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, []string{"Upper@Example.COM"}, end-int64(24*time.Hour), end)
	assert.NoError(t, err)

	found := false
	for _, q := range f.queries {
		if strings.Contains(q, `|= "upper@example.com"`) {
			found = true
			assert.Contains(t, q, `(?i)`)
		}
	}
	assert.True(t, found, "the email pass must |= prefilter on the lowercased address")
}

// Logged-out client lines name nobody, so finding them is a full-text scan of
// seven days of client logs; it is not run. The dump records that they were
// left out, so an empty answer is not read as "nothing there".
func TestCollectLoki_SkipsLoggedOutLinesAndSaysSo(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`source="api", user_bucket="10"`: {{tsNs: 5, source: "api", line: `{"session_id":"sess-1"}`}},
	}}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()
	assert.NoError(t, b.InitMeta())

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, []string{"a@b.com"}, end-int64(maxLokiRange), end)
	assert.NoError(t, err)

	for _, q := range f.queries {
		assert.NotContains(t, q, `user_id=""`)
	}

	var note string
	assert.NoError(t, b.db.QueryRow(`SELECT note FROM _sections WHERE name = 'loki_not_collected'`).Scan(&note))
	assert.Equal(t, lokiNotCollected, note)
}

// Every query in the section must stay inside the clamped window, or the ones
// that were not clamped simply 400 and their data is lost.
func TestCollectLoki_AllQueriesStayInsideTheClampedWindow(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`source="api", user_bucket="10"`: {
			{tsNs: 5, source: "api", line: `{"session_id":"s1"}`},
		},
	}}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, []string{"a@b.com"}, end-int64(90*24*time.Hour), end)
	assert.NoError(t, err)

	assert.NotEmpty(t, f.starts)
	oldest := end - int64(maxLokiRange)
	for i, s := range f.starts {
		assert.GreaterOrEqual(t, s, oldest, "query %d (%s) starts before the clamp", i, f.queries[i])
		assert.LessOrEqual(t, f.ends[i], end, "query %d (%s) ends after the window", i, f.queries[i])
		assert.Less(t, s, f.ends[i], "query %d (%s) has an empty window", i, f.queries[i])
	}
}

// Pass A1 failing is still fatal for the section - the caller records it as a
// warning rather than silently storing a partial log set.
func TestCollectLoki_PassAErrorIsFatalForTheSection(t *testing.T) {
	f := &fakeLoki{errOn: `source="api", user_bucket="10"`}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.Error(t, err)
	assert.Equal(t, 0, n)
}

// Lines labelled with user_id itself (the form written until 2026-08-23) are
// older than anything Loki is asked for, so there is no query for them: they
// returned nothing and cost 3-5s each. Every member lookup is by bucket.
func TestCollectLoki_NoPreBucketLabelQueries(t *testing.T) {
	end := time.Now().UnixNano()
	f := &fakeLoki{byMatch: apiLinesAt(end - int64(time.Hour))}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	_, err = collectLoki(b, f, 42, []string{"a@b.com"}, end-int64(maxLokiRange), end)
	assert.NoError(t, err)
	for _, q := range f.queries {
		assert.NotContains(t, q, `, user_id="42"}`)
		assert.NotContains(t, q, `{app="freegle", user_id="42"`)
	}
}

// Passes can overlap, so an entry must never be stored twice.
func TestCollectLoki_TheSameEntryFromTwoPassesIsStoredOnce(t *testing.T) {
	same := lokiEntry{tsNs: 11, source: "api", line: `{"user_id":42}`}
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`source="api", user_bucket="10"`: {same},
		`| json | user_id="42"`:          {same},
	}}

	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.NoError(t, err)
	assert.Equal(t, 1, n, "the same entry from two passes must collapse to one")
}

// Every address goes into one query per half-window rather than one each: a
// member with 23 addresses used to spend the whole section budget on pass B.
func TestCollectLoki_AllEmailsInOneQueryPerHalf(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`|~ "(?i)(`: {{tsNs: 7, source: "email", line: "to Two@b.com"}},
	}}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, []string{"one@a.com", "Two@b.com", " "}, end-int64(maxLokiRange), end)
	assert.NoError(t, err)
	assert.Equal(t, 1, n, "the same line found by both halves is stored once")

	var emailQueries []string
	for _, q := range f.queries {
		if strings.Contains(q, "|~") {
			emailQueries = append(emailQueries, q)
		}
	}
	assert.Len(t, emailQueries, len(splitRange(end-int64(maxLokiRange), end, int64(halfSpan))))
	for _, q := range emailQueries {
		assert.Contains(t, q, `|= "one@a.com" or "two@b.com" |~ "(?i)(one@a\\.com|Two@b\\.com)"`)
	}
}

func TestLokiEmailQuery_NoEmails(t *testing.T) {
	assert.Equal(t, "", lokiEmailQuery(nil))
	assert.Equal(t, "", lokiEmailQuery([]string{" "}))
}

// The regex sits inside a LogQL double-quoted string, so a regex escape must be
// string-escaped again: Loki rejects "\." with a 400.
func TestEscapeLokiRegex_IsValidInsideALogQLString(t *testing.T) {
	assert.Equal(t, `a\\.b\\+c@d\\.com`, escapeLokiRegex("a.b+c@d.com"))
	assert.Equal(t, `say\"hi\"`, escapeLokiRegex(`say"hi"`))
}

// flakyLoki fails the first query matching failOnce, then behaves like fakeLoki.
type flakyLoki struct {
	*fakeLoki
	failOnce string
	failed   bool
}

func (f *flakyLoki) query(logql string, startNs, endNs int64, limit int) ([]lokiEntry, error) {
	f.mu.Lock()
	fail := !f.failed && strings.Contains(logql, f.failOnce)
	if fail {
		f.failed = true
	}
	f.mu.Unlock()
	if fail {
		return nil, assert.AnError
	}
	return f.fakeLoki.query(logql, startNs, endNs, limit)
}

// Pass A is fatal, so one timeout on a heavy member's labelled query must not
// empty the whole section: it is tried a second time.
func TestCollectLoki_PassAIsRetriedOnce(t *testing.T) {
	f := &flakyLoki{
		fakeLoki: &fakeLoki{byMatch: map[string][]lokiEntry{
			`source="api", user_bucket="10"`: {{tsNs: 5, source: "api", line: `{"user_id":42}`}},
		}},
		failOnce: `source="api", user_bucket="10"`,
	}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.NoError(t, err)
	assert.Equal(t, 1, n)
}

func TestActivityWindows_MergesPadsAndGoesNewestFirst(t *testing.T) {
	base := time.Date(2026, 9, 1, 12, 0, 0, 0, time.UTC).UnixNano()
	min := int64(time.Minute)
	start, end := base-int64(time.Hour), base+int64(24*time.Hour)

	// 12:00 and 12:04 merge (gap under 5 minutes); 15:00 stands alone.
	w := activityWindows([]int64{base + 180*min, base, base + 4*min}, start, end)
	assert.Equal(t, []nsRange{
		{start: base + 179*min, end: base + 182*min},
		{start: base - min, end: base + 6*min},
	}, w)

	// Clamped to the searchable range, and nothing when there is no activity.
	assert.Equal(t, []nsRange{{start: base, end: base + 2*min}}, activityWindows([]int64{base}, base, end))
	assert.Empty(t, activityWindows(nil, start, end))
}

// api_headers has no member label, so it is searched only in the minutes the
// member's own api lines (from pass A) show them active - not across 7 days -
// and each search asks for about as many lines as there were requests.
func TestCollectLoki_ApiHeadersOnlyWhereTheMemberWasActive(t *testing.T) {
	end := time.Now().UnixNano()
	a, b := end-int64(2*time.Hour), end-int64(50*time.Hour)
	f := &fakeLoki{byMatch: apiLinesAt(a, a+int64(time.Second), b)}
	bd, err := NewBuilder()
	assert.NoError(t, err)
	defer bd.Remove()

	_, err = collectLoki(bd, f, 42, nil, end-int64(maxLokiRange), end)
	assert.NoError(t, err)

	var ranges []nsRange
	var limits []int
	for i, q := range f.queries {
		if strings.Contains(q, `source="api_headers"`) {
			ranges = append(ranges, nsRange{start: f.starts[i], end: f.ends[i]})
			limits = append(limits, f.limits[i])
		}
	}
	assert.Len(t, ranges, 2, "one search per active window")
	for i, r := range ranges {
		near := func(m int64) bool { return r.start <= m && m < r.end }
		assert.True(t, near(a) || near(b), "a header search outside the member's activity: %v", r)
		assert.LessOrEqual(t, r.end-r.start, int64(10*time.Minute))
		assert.Less(t, limits[i], 20, "the limit follows the number of requests in the window")
	}
}

func TestCollectLoki_NoActivityMeansNoHeaderSearch(t *testing.T) {
	f := &fakeLoki{}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, nil, end-int64(maxLokiRange), end)
	assert.NoError(t, err)
	for _, q := range f.queries {
		assert.NotContains(t, q, `source="api_headers"`)
	}
}

// A member active all week fills pass A's line cap, so the headers are taken
// for the period of their newest api lines only, and never past the cap.
func TestCollectLoki_ApiHeadersStayWithinTheCap(t *testing.T) {
	end := time.Now().UnixNano()
	var times []int64
	for i := 0; i < 6000; i++ {
		times = append(times, end-int64(i)*int64(90*time.Second))
	}
	byMatch := apiLinesAt(times...)
	lines := make([]lokiEntry, 20000)
	for i := range lines {
		lines[i] = lokiEntry{tsNs: int64(i), source: "api_headers", line: fmt.Sprintf("h%d", i)}
	}
	byMatch[`source="api_headers"`] = lines
	f := &fakeLoki{byMatch: byMatch}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()
	assert.NoError(t, b.InitMeta())

	_, err = collectLoki(b, f, 42, nil, end-int64(maxLokiRange), end)
	assert.NoError(t, err)

	var headers int
	assert.NoError(t, b.db.QueryRow(`SELECT COUNT(*) FROM loki_logs WHERE source = 'api_headers'`).Scan(&headers))
	assert.LessOrEqual(t, headers, apiHeadersLineCap, "the cap is never overshot")
	assert.Greater(t, headers, 0)

	var n int
	assert.NoError(t, b.db.QueryRow(`SELECT COUNT(*) FROM _sections WHERE name = 'loki_bounds' AND note LIKE 'api_headers: only for the period%'`).Scan(&n))
	assert.Equal(t, 1, n, "the dump says the headers cover only the newest period")
}
