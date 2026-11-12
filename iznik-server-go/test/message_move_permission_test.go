package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Moving a post changes the post itself, so only a moderator of a community it was posted to
// may do it. A moderator of a community it merely rippled into has no standing over it, even
// if they also moderate the target.
func TestMoveRefusedForModeratorOfRippledInCopyOnly(t *testing.T) {
	f := newHomeLockFixture(t, "move_rippled")
	target := addModCommunity(t, "move_rippled_target")
	// The receiving community's moderator also moderates the target community.
	CreateTestMembership(t, f.modRecv, target.gid, utils.ROLE_MODERATOR)

	status, _ := postModAction(t, f.tokRecv, f.msgID, "Move", target.gid)
	assert.Equal(t, 403, status, "a moderator of a rippled-in copy cannot move the home post")

	assert.Equal(t, int64(1), groupRowCount(f.msgID, f.home), "the home copy is untouched")
	assert.Equal(t, int64(1), groupRowCount(f.msgID, f.recv), "and so is the rippled-in copy")
	assert.Equal(t, int64(0), groupRowCount(f.msgID, target.gid), "nothing lands on the target")
}
