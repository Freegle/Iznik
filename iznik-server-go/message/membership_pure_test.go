package message

// Tests for the pure (no-DB) renderer in membership.go: the "is this post approved in one of
// the viewer's groups" predicate that the browse feed, its unseen count and the combined
// browse query all share.

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestApprovedInMyGroupsPredicate_FewGroupsUsesConstantList(t *testing.T) {
	sql, args := approvedInMyGroupsPredicate("ms.msgid", []uint64{12, 7, 45}, 99)

	// The member's groups arrive as constants, so MySQL can push a groupid into the
	// (msgid, groupid) index instead of walking every membership row the post has.
	assert.Contains(t, sql, "mg.groupid IN (12,7,45)")
	assert.NotContains(t, strings.ToLower(sql), "memberships",
		"the memberships join is what made this slow; it must be gone in the constant form")
	assert.Contains(t, sql, "mg.msgid = ms.msgid")
	assert.Empty(t, args, "the constant form binds nothing - the ids are inlined")
}

func TestApprovedInMyGroupsPredicate_HonoursTheMsgidColumn(t *testing.T) {
	// message.Groups correlates on messages_spatial.msgid, the isochrone feed on its ms alias.
	sql, _ := approvedInMyGroupsPredicate("messages_spatial.msgid", []uint64{3}, 99)

	assert.Contains(t, sql, "mg.msgid = messages_spatial.msgid")
}

func TestApprovedInMyGroupsPredicate_NoGroupsIsAlwaysFalse(t *testing.T) {
	// A member of nothing matches no post. Rendering an empty IN () list here would be a SQL
	// syntax error and would take out the whole browse feed, so this case is answered without
	// one. New accounts sit in exactly this state between signing up and joining a community.
	sql, args := approvedInMyGroupsPredicate("ms.msgid", nil, 99)

	assert.NotContains(t, sql, "IN ()", "an empty IN list is a syntax error")
	assert.Contains(t, strings.ToUpper(sql), "FALSE")
	assert.Empty(t, args)
}

func TestApprovedInMyGroupsPredicate_ManyGroupsFallsBackToTheJoin(t *testing.T) {
	// A moderator can be in hundreds of communities. Past that the constant list stops paying:
	// each id is another index dive per post, while the join form walks only the memberships
	// the post actually has. Above the cap we keep the original shape.
	many := make([]uint64, maxGroupsForConstantList+1)
	for i := range many {
		many[i] = uint64(i + 1)
	}

	sql, args := approvedInMyGroupsPredicate("ms.msgid", many, 99)

	assert.Contains(t, strings.ToLower(sql), "inner join memberships",
		"above the cap the predicate must revert to joining memberships")
	assert.NotContains(t, sql, "mg.groupid IN (")
	assert.Equal(t, []interface{}{uint64(99)}, args,
		"the fallback binds the viewer id rather than inlining group ids")
}

func TestApprovedInMyGroupsPredicate_CapBoundaryStillUsesConstantList(t *testing.T) {
	// Exactly at the cap is still the fast form; the fallback starts one above it.
	atCap := make([]uint64, maxGroupsForConstantList)
	for i := range atCap {
		atCap[i] = uint64(i + 1)
	}

	sql, args := approvedInMyGroupsPredicate("ms.msgid", atCap, 99)

	assert.Contains(t, sql, "mg.groupid IN (")
	assert.Empty(t, args)
}

func TestApprovedInMyGroupsPredicate_KeepsApprovedAndUndeletedArms(t *testing.T) {
	// Both forms must still filter to live approved memberships - dropping either would show
	// posts that were rejected, or copies removed when the origin was.
	for _, groups := range [][]uint64{{1, 2}, make([]uint64, maxGroupsForConstantList+1)} {
		sql, _ := approvedInMyGroupsPredicate("ms.msgid", groups, 99)
		assert.Contains(t, sql, "mg.collection = 'Approved'")
		assert.Contains(t, sql, "mg.deleted = 0")
	}
}
