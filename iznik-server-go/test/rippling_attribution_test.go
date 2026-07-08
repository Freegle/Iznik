package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/stretchr/testify/assert"
)

func iptr(v int) *int { return &v }
func sptr(s string) *string { return &s }

// The attribution ladder over the evidence bits. Precedence and the hard guard are the whole
// point of the graded scheme, so every rung and the interesting overlaps are pinned here.
func TestDeriveAttribution(t *testing.T) {
	cases := []struct {
		name                                             string
		wasHome, wasNotified, wasRippleGroup, hadRippled int
		inOrigin, inReach                                *int
		want                                             string
	}{
		{"home member", 1, 0, 0, 1, nil, nil, rippling.AttributionHome},
		{"home wins over ripple evidence (conservative: never over-credit rippling)",
			1, 1, 1, 1, iptr(1), iptr(1), rippling.AttributionHome},
		{"notified ledger hit", 0, 1, 0, 1, nil, nil, rippling.AttributionRippleNotified},
		{"notified outranks rippled-group membership", 0, 1, 1, 1, nil, nil, rippling.AttributionRippleNotified},
		{"established member of a rippled-into group", 0, 0, 1, 1, nil, nil, rippling.AttributionRippleGroup},
		{"non-member inside origin catchment: would have seen it in Browse anyway",
			0, 0, 0, 1, iptr(1), iptr(1), rippling.AttributionOrganicLocal},
		{"outside catchment, inside reach: exposure existed only because of the ripple",
			0, 0, 0, 1, iptr(0), iptr(1), rippling.AttributionRippleReach},
		{"inside reach but post never rippled: hard guard blocks ripple_reach",
			0, 0, 0, 0, iptr(0), iptr(1), rippling.AttributionUnknown},
		{"no location on file: reach containment unknowable", 0, 0, 0, 1, nil, nil, rippling.AttributionUnknown},
		{"outside both", 0, 0, 0, 1, iptr(0), iptr(0), rippling.AttributionUnknown},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := rippling.DeriveAttribution(c.wasHome, c.wasNotified, c.wasRippleGroup, c.hadRippled, c.inOrigin, c.inReach)
			assert.Equal(t, c.want, got)
		})
	}
}

// The client-reported surface is spoofable input: well-formed values pass through, anything
// else (uppercase, spaces, injection shapes, over-long, empty, absent) is dropped to nil.
func TestSanitizeClientSource(t *testing.T) {
	assert.Nil(t, rippling.SanitizeClientSource(nil))
	assert.Nil(t, rippling.SanitizeClientSource(sptr("")))
	assert.Nil(t, rippling.SanitizeClientSource(sptr("Bad Source!")))
	assert.Nil(t, rippling.SanitizeClientSource(sptr("UPPER")))
	assert.Nil(t, rippling.SanitizeClientSource(sptr("a'; DROP TABLE x--")))
	assert.Nil(t, rippling.SanitizeClientSource(sptr("_leading")))
	assert.Nil(t, rippling.SanitizeClientSource(sptr("this-is-well-over-thirty-two-characters-long")))

	for _, ok := range []string{"browse", "search", "message_page", "email", "myposts", "digest-2"} {
		got := rippling.SanitizeClientSource(sptr(ok))
		if assert.NotNil(t, got, ok) {
			assert.Equal(t, ok, *got)
		}
	}
}
