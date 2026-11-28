package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func groupRowCount(msgID, gid uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, gid).Scan(&n)
	return n
}

// A moderator can send a post back to draft only on communities they moderate. Naming another
// community, or naming none, must leave that community's copy alone.
func TestRejectToDraftModeratorCannotTouchOtherCommunity(t *testing.T) {
	f := newHomeLockFixture(t, "rtd_scope_named")

	status, _ := postModAction(t, f.tokHome, f.msgID, "RejectToDraft", f.recv)
	assert.Equal(t, 403, status, "a moderator of the home community has no standing on the receiving one")
	assert.Equal(t, int64(1), groupRowCount(f.msgID, f.recv), "the receiving community's copy is not deleted")
	assert.Equal(t, int64(1), groupRowCount(f.msgID, f.home), "and nothing else changes")
}

func TestRejectToDraftModeratorWithNoGroupOnlyTakesOwnCommunities(t *testing.T) {
	f := newHomeLockFixture(t, "rtd_scope_none")

	status, body := postModAction(t, f.tokHome, f.msgID, "RejectToDraft", 0)
	require.Equal(t, 200, status, body)
	assert.Equal(t, int64(0), groupRowCount(f.msgID, f.home), "the moderator's own community's copy goes back to draft")
	assert.Equal(t, int64(1), groupRowCount(f.msgID, f.recv), "a community they do not moderate keeps its copy")
}
