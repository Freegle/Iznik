package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// messages_spatial holds one row per post, unique on msgid. The community on that row is the
// post's own (home) community, not a community it rippled into, whichever copy was approved
// last, exactly as the 5-minute reconciler would choose.
func TestApproveKeepsHomeCommunityOnSpatialRow(t *testing.T) {
	f := newHomeLockFixture(t, "spatial_home")
	db := database.DBConn

	db.Exec("UPDATE messages SET lat = 51.5, lng = -0.1 WHERE id = ?", f.msgID)
	db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", f.msgID)
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", f.msgID, f.home)

	// The receiving community's moderator approves their rippled-in copy first, then the
	// home moderator approves the home copy.
	approveByHand(t, f.msgID, f.recv, f.tokRecv)
	approveByHand(t, f.msgID, f.home, f.tokHome)

	var rows int64
	db.Raw("SELECT COUNT(*) FROM messages_spatial WHERE msgid = ?", f.msgID).Scan(&rows)
	require.Equal(t, int64(1), rows)

	var groupid uint64
	db.Raw("SELECT groupid FROM messages_spatial WHERE msgid = ?", f.msgID).Scan(&groupid)
	assert.Equal(t, f.home, groupid, "the spatial row names the home community")
}
