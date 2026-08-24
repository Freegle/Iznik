package rippling

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func intp(v int) *int { return &v }

// DeriveAttribution runs the seven-rung precedence ladder. Every rung must be
// individually reachable, and where two rungs' evidence is simultaneously
// true the HIGHER-precedence rung must win.
func TestDeriveAttribution_Ladder(t *testing.T) {
	cases := []struct {
		name                                             string
		wasHomeMember, wasNotified, wasRippleGroupMember int
		wasRippleJoinMember, postHadRippled              int
		inOriginCatchment, inReach                       *int
		want                                             string
	}{
		// ---- Individual rungs, all other evidence absent ----
		{"home wins with nothing else set", 1, 0, 0, 0, 0, nil, nil, AttributionHome},
		{"ripple_notified when only notified evidence set", 0, 1, 0, 0, 0, nil, nil, AttributionRippleNotified},
		{"ripple_group when only group-membership evidence set", 0, 0, 1, 0, 0, nil, nil, AttributionRippleGroup},
		{"ripple_join when only ripple-created origin membership set", 0, 0, 0, 1, 0, nil, nil, AttributionRippleJoin},
		{"organic_local when only catchment evidence set", 0, 0, 0, 0, 0, intp(1), nil, AttributionOrganicLocal},
		{"ripple_reach when post rippled and reach evidence set", 0, 0, 0, 0, 1, nil, intp(1), AttributionRippleReach},
		{"unknown when nothing resolvable", 0, 0, 0, 0, 0, nil, nil, AttributionUnknown},

		// ---- Precedence collisions: higher rung must win over lower ones ----
		{"home beats notified", 1, 1, 0, 0, 0, nil, nil, AttributionHome},
		{"home beats ripple_group", 1, 0, 1, 0, 0, nil, nil, AttributionHome},
		{"home beats organic_local", 1, 0, 0, 0, 0, intp(1), nil, AttributionHome},
		{"home beats ripple_reach", 1, 0, 0, 0, 1, nil, intp(1), AttributionHome},
		{"home beats everything at once", 1, 1, 1, 1, 1, intp(1), intp(1), AttributionHome},
		{"notified beats ripple_group", 0, 1, 1, 0, 0, nil, nil, AttributionRippleNotified},
		{"notified beats ripple_join", 0, 1, 0, 1, 0, nil, nil, AttributionRippleNotified},
		{"notified beats organic_local", 0, 1, 0, 0, 0, intp(1), nil, AttributionRippleNotified},
		{"notified beats ripple_reach", 0, 1, 0, 0, 1, nil, intp(1), AttributionRippleNotified},
		{"ripple_group beats ripple_join", 0, 0, 1, 1, 0, nil, nil, AttributionRippleGroup},
		{"ripple_group beats organic_local", 0, 0, 1, 0, 0, intp(1), nil, AttributionRippleGroup},
		{"ripple_group beats ripple_reach", 0, 0, 1, 0, 1, nil, intp(1), AttributionRippleGroup},
		{"ripple_join beats organic_local - membership exposure outranks maybe-saw-it-in-Browse", 0, 0, 0, 1, 0, intp(1), nil, AttributionRippleJoin},
		{"ripple_join beats ripple_reach", 0, 0, 0, 1, 1, nil, intp(1), AttributionRippleJoin},
		{"organic_local beats ripple_reach - deliberately ranked above reach", 0, 0, 0, 0, 1, intp(1), intp(1), AttributionOrganicLocal},

		// ---- inOriginCatchment / inReach: nil vs 0 vs 1 ----
		{"catchment nil does not trigger organic_local", 0, 0, 0, 0, 0, nil, nil, AttributionUnknown},
		{"catchment 0 does not trigger organic_local", 0, 0, 0, 0, 0, intp(0), nil, AttributionUnknown},
		{"catchment 1 triggers organic_local", 0, 0, 0, 0, 0, intp(1), nil, AttributionOrganicLocal},
		{"reach nil does not trigger ripple_reach even if post rippled", 0, 0, 0, 0, 1, nil, nil, AttributionUnknown},
		{"reach 0 does not trigger ripple_reach even if post rippled", 0, 0, 0, 0, 1, nil, intp(0), AttributionUnknown},
		{"reach 1 without postHadRippled does not trigger ripple_reach", 0, 0, 0, 0, 0, nil, intp(1), AttributionUnknown},
		{"reach 1 with postHadRippled triggers ripple_reach", 0, 0, 0, 0, 1, nil, intp(1), AttributionRippleReach},

		// ---- Structural guard: a post that never rippled cannot be ripple-attributed via reach ----
		{"postHadRippled=0 blocks ripple_reach even with reach evidence present", 0, 0, 0, 0, 0, nil, intp(1), AttributionUnknown},

		// ---- ripple_join needs no postHadRippled guard: the membership IS the ripple evidence.
		//      It was created by a PREVIOUS ripple (of the member's own post), so it stands even
		//      though THIS post never rippled anywhere.
		{"ripple_join stands on a post that never rippled", 0, 0, 0, 1, 0, nil, nil, AttributionRippleJoin},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := DeriveAttribution(c.wasHomeMember, c.wasNotified, c.wasRippleGroupMember,
				c.wasRippleJoinMember, c.postHadRippled, c.inOriginCatchment, c.inReach)
			assert.Equal(t, c.want, got, c.name)
		})
	}
}

// The "was already a member" test that every rippling stat leans on must ignore memberships
// rippling ITSELF created (memberships.rippled = 1 - the auto-join written when the member's own
// post rippled into that group). Counting those as pre-existing local membership makes rippling
// look less effective than it is: the member is only in that group because of an earlier ripple.
func TestEstablishedOriginMemberExists(t *testing.T) {
	sql := EstablishedOriginMemberExists("cm.refmsgid", "cm.userid")

	assert.Contains(t, sql, "mem.rippled = 0",
		"ripple-created auto-joins must never count as pre-existing membership")
	assert.Contains(t, sql, "og.rippled_in = 0",
		"only ORIGIN groups count - a rippled-in copy's group is not the member's home")
	assert.Contains(t, sql, "og.deleted = 0")
	assert.Contains(t, sql, "mem.collection = 'Approved'")
	assert.Contains(t, sql, "mem.added < og.arrival",
		"established = joined before the post arrived, so a join-to-reply never counts")
	assert.Contains(t, sql, "og.msgid = cm.refmsgid", "correlates on the caller's message column")
	assert.Contains(t, sql, "mem.userid = cm.userid", "correlates on the caller's user column")
	assert.True(t, strings.HasPrefix(strings.TrimSpace(sql), "EXISTS("),
		"returns an EXISTS fragment the caller can negate")
}

// SanitizeClientSource validates the client-reported reply surface against
// ^[a-z0-9][a-z0-9_-]{0,31}$ (max length 32) and returns nil for anything that
// doesn't match, so the DB never receives arbitrary/injected strings.
func TestSanitizeClientSource(t *testing.T) {
	str := func(s string) *string { return &s }

	cases := []struct {
		name      string
		in        *string
		wantNil   bool
		wantValue string
	}{
		{"nil input stays nil", nil, true, ""},
		{"pointer to empty string is rejected", str(""), true, ""},
		{"a normal valid value passes through unchanged", str("browse"), false, "browse"},
		{"digits and underscore/hyphen mid-string are valid", str("message_page-2"), false, "message_page-2"},
		{"leading char must be [a-z0-9] - leading hyphen rejected", str("-browse"), true, ""},
		{"leading char must be [a-z0-9] - leading underscore rejected", str("_browse"), true, ""},
		{"invalid character mid-string rejected", str("brow se"), true, ""},
		{"invalid character mid-string rejected (punctuation)", str("browse!"), true, ""},
		{"uppercase rejected - regex is lowercase-only", str("Browse"), true, ""},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := SanitizeClientSource(c.in)
			if c.wantNil {
				assert.Nil(t, got, c.name)
				return
			}
			if assert.NotNil(t, got, c.name) {
				assert.Equal(t, c.wantValue, *got)
				// Confirms the function returns the same pointer/value rather
				// than a copy that happens to match.
				assert.Equal(t, c.in, got)
			}
		})
	}
}

func TestSanitizeClientSource_LengthBoundary(t *testing.T) {
	exact32 := "a" + strings.Repeat("b", 31) // 1 + 31 = 32 chars, matches [a-z0-9][a-z0-9_-]{0,31}
	assert.Len(t, exact32, 32)
	str32 := exact32
	got := SanitizeClientSource(&str32)
	if assert.NotNil(t, got) {
		assert.Equal(t, exact32, *got)
	}

	over33 := exact32 + "c" // 33 chars - one over the cap
	assert.Len(t, over33, 33)
	str33 := over33
	assert.Nil(t, SanitizeClientSource(&str33))
}
