package userdump

import (
	"errors"
	"strconv"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// parseInclude splits a CSV of section names, trimming whitespace and
// lower-casing, dropping empty segments, and defaulting to {"db"} when
// nothing parsed out.
func TestParseInclude(t *testing.T) {
	cases := []struct {
		name string
		in   string
		want map[string]bool
	}{
		{"single value", "db", map[string]bool{"db": true}},
		{"multiple values", "db,loki,sentry", map[string]bool{"db": true, "loki": true, "sentry": true}},
		{"whitespace trimmed around each segment", " db , loki ", map[string]bool{"db": true, "loki": true}},
		{"mixed case lower-cased", "DB,Loki,SENTRY", map[string]bool{"db": true, "loki": true, "sentry": true}},
		{"empty segments dropped", "db,,loki,", map[string]bool{"db": true, "loki": true}},
		{"empty string defaults to db", "", map[string]bool{"db": true}},
		{"whitespace-only string defaults to db", "   ", map[string]bool{"db": true}},
		{"only commas defaults to db", ",,,", map[string]bool{"db": true}},
		{"duplicate values collapse", "db,db,DB", map[string]bool{"db": true}},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			assert.Equal(t, c.want, parseInclude(c.in))
		})
	}
}

// includeString renders a section-name set back to a deterministic
// (sorted) CSV string - the inverse of parseInclude, used so the same
// include set always produces the same cache-friendly string regardless of
// Go's randomised map iteration order.
func TestIncludeString(t *testing.T) {
	cases := []struct {
		name string
		in   map[string]bool
		want string
	}{
		{"single entry", map[string]bool{"db": true}, "db"},
		{"already-sorted multiple entries", map[string]bool{"db": true, "loki": true, "sentry": true}, "db,loki,sentry"},
		{"out-of-order insertion still sorts", map[string]bool{"sentry": true, "db": true, "loki": true}, "db,loki,sentry"},
		{"empty map produces empty string", map[string]bool{}, ""},
		{"nil map produces empty string", nil, ""},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			assert.Equal(t, c.want, includeString(c.in))
		})
	}
}

// Deterministic output is the whole point: run the same set through
// includeString many times (map iteration order is randomised per-run by
// the Go runtime) and confirm it never varies.
func TestIncludeString_DeterministicAcrossRepeatedCalls(t *testing.T) {
	m := map[string]bool{"sentry": true, "db": true, "loki": true, "extra": true}
	first := includeString(m)
	for i := 0; i < 20; i++ {
		assert.Equal(t, first, includeString(m))
	}
	assert.Equal(t, "db,extra,loki,sentry", first)
}

// Round-trip: parseInclude -> includeString always yields the sorted,
// deduplicated, lower-cased canonical form.
func TestParseIncludeThenIncludeString_RoundTrips(t *testing.T) {
	assert.Equal(t, "db,loki,sentry", includeString(parseInclude(" Sentry, db ,LOKI,db")))
}

// Sections run concurrently: a snapshot is as slow as its slowest section, not
// the sum of them. Progress callbacks still arrive one at a time with a
// running count, and every section is recorded.
func TestRunSections_RunsConcurrently(t *testing.T) {
	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()
	assert.NoError(t, b.InitMeta())

	var plan []section
	for i := 0; i < dumpWorkers; i++ {
		name := "s" + strconv.Itoa(i)
		plan = append(plan, section{name: name, weight: 1, run: func(b *Builder) (int, error) {
			time.Sleep(300 * time.Millisecond)
			return 1, nil
		}})
	}
	plan = append(plan, section{name: "bad", weight: 8, run: func(b *Builder) (int, error) {
		return 0, errors.New("boom")
	}})

	var dones []int
	start := time.Now()
	warnings := runSections(b, plan, dumpWorkers+8, []string{"plan warning"},
		func(done, total, totalWeight, doneWeight int, sec section, rows int, secErr error) {
			dones = append(dones, done)
			assert.Equal(t, len(plan), total)
		})

	assert.Less(t, time.Since(start), 2*300*time.Millisecond, "sections overlap rather than queue")
	var want []int
	for i := 1; i <= len(plan); i++ {
		want = append(want, i)
	}
	assert.Equal(t, want, dones)
	assert.Equal(t, []string{"plan warning", "bad: boom"}, warnings)

	var n int
	assert.NoError(t, b.db.QueryRow("SELECT COUNT(*) FROM _sections").Scan(&n))
	assert.Equal(t, len(plan), n)
}
