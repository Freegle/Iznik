package userdump

import (
	"testing"

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
