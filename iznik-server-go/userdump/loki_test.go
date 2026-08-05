package userdump

import (
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// fakeLoki records every LogQL query it is asked for and returns canned entries
// keyed by a substring of the query, so tests can assert on the SHAPE of the
// queries the dump issues - which is where the cost lives.
type fakeLoki struct {
	queries []string
	starts  []int64
	ends    []int64
	byMatch map[string][]lokiEntry
	errOn   string
}

func (f *fakeLoki) query(logql string, startNs, endNs int64, limit int) ([]lokiEntry, error) {
	f.queries = append(f.queries, logql)
	f.starts = append(f.starts, startNs)
	f.ends = append(f.ends, endNs)
	if f.errOn != "" && strings.Contains(logql, f.errOn) {
		return nil, assert.AnError
	}
	for match, entries := range f.byMatch {
		if strings.Contains(logql, match) {
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

// Pass A must use the user_id STREAM LABEL for the sources that have it. As a
// `| json` filter it had to parse every Freegle log line in the window - over a
// minute for a single day against production.
func TestCollectLoki_PassAUsesLabelSelector(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`{app="freegle", user_id="42"}`: {{tsNs: 5, source: "api", line: `{"user_id":42}`}},
	}}

	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.NoError(t, err)
	assert.Equal(t, 1, n)

	assert.Contains(t, f.queries[0], `{app="freegle", user_id="42"}`)
	assert.NotContains(t, f.queries[0], "| json",
		"the labelled sources must be an index lookup, not a parse of every line")
}

// The sources that do NOT label user_id still need the parse, but it must be
// pinned to those streams so it never touches the api/client firehose.
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
		if strings.Contains(q, "| json") && strings.Contains(q, `user_id="42"`) {
			jsonPass = q
		}
		// escapeLokiRegex escapes the dot, so the address appears as a@b\.com.
		if strings.Contains(q, "|~") && strings.Contains(q, `a@b\.com`) {
			emailPass = q
		}
	}

	assert.NotEmpty(t, jsonPass, "the unlabelled sources are still collected")
	assert.Contains(t, jsonPass, unlabelledSources)

	assert.NotEmpty(t, emailPass, "email addresses are still searched for")
	assert.Contains(t, emailPass, unlabelledSources,
		"the email regex must not scan the whole app")

	// The two heaviest sources are covered by label in pass A, so no expensive
	// query may name them.
	for _, q := range f.queries {
		if strings.Contains(q, "| json") || strings.Contains(q, "|~") {
			assert.NotContains(t, q, `source="api"`)
			assert.NotContains(t, q, `source="client"`)
		}
	}
	assert.NotContains(t, unlabelledSources, "|api|")
	assert.False(t, strings.HasPrefix(unlabelledSources, "api|"))
	assert.NotContains(t, unlabelledSources, "client")
	assert.NotContains(t, unlabelledSources, "chat_reply")
}

// Every query in the section must respect the clamped window, or the ones that
// were not clamped simply 400 and their data is lost.
func TestCollectLoki_AllQueriesUseTheClampedWindow(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`{app="freegle", user_id="42"}`: {
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
	want := end - int64(maxLokiRange)
	for i, s := range f.starts {
		assert.Equal(t, want, s, "query %d (%s) used an unclamped start", i, f.queries[i])
	}
}

// Pass A failing is still fatal for the section - the caller records it as a
// warning rather than silently storing a partial log set.
func TestCollectLoki_PassAErrorIsFatalForTheSection(t *testing.T) {
	f := &fakeLoki{errOn: `{app="freegle", user_id="42"}`}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	n, err := collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.Error(t, err)
	assert.Equal(t, 0, n)
}

// Session ids are still harvested from the api lines pass A now returns by
// label, and pass C still looks them up.
func TestCollectLoki_HarvestsSessionsFromLabelledApiLines(t *testing.T) {
	f := &fakeLoki{byMatch: map[string][]lokiEntry{
		`{app="freegle", user_id="42"}`: {
			{tsNs: 5, source: "api", line: `{"session_id":"sess-1"}`},
		},
	}}
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	end := time.Now().UnixNano()
	_, err = collectLoki(b, f, 42, nil, end-int64(24*time.Hour), end)
	assert.NoError(t, err)

	found := false
	for _, q := range f.queries {
		if strings.Contains(q, `session_id="sess-1"`) {
			found = true
		}
	}
	assert.True(t, found, "pass C must still run off the sessions pass A found")
}
