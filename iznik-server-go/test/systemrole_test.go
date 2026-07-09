package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

// readSystemrole returns the current users.systemrole for a user.
func readSystemrole(userID uint64) string {
	var role string
	database.DBConn.Raw("SELECT systemrole FROM users WHERE id = ?", userID).Scan(&role)
	return role
}

// A Moderator systemrole with no Owner/Moderator membership anywhere is the
// stale state that left ~800 ex-mods elevated — it must demote to User.
func TestSyncSystemRole_DemotesStaleModerator(t *testing.T) {
	uid := CreateTestUser(t, uniquePrefix("syncrole_demote"), "Moderator")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "User", readSystemrole(uid))
}

// Still a Moderator on a group → keep Moderator systemrole.
func TestSyncSystemRole_KeepsModeratorWithModMembership(t *testing.T) {
	prefix := uniquePrefix("syncrole_keepmod")
	uid := CreateTestUser(t, prefix, "Moderator")
	gid := CreateTestGroup(t, prefix)
	CreateTestMembership(t, uid, gid, "Moderator")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "Moderator", readSystemrole(uid))
}

// Owner counts as a mod role too → keep Moderator systemrole.
func TestSyncSystemRole_KeepsModeratorWithOwnerMembership(t *testing.T) {
	prefix := uniquePrefix("syncrole_keepowner")
	uid := CreateTestUser(t, prefix, "Moderator")
	gid := CreateTestGroup(t, prefix)
	CreateTestMembership(t, uid, gid, "Owner")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "Moderator", readSystemrole(uid))
}

// The mirror: a plain User who holds a mod membership is promoted to Moderator.
func TestSyncSystemRole_PromotesUserWithModMembership(t *testing.T) {
	prefix := uniquePrefix("syncrole_promote")
	uid := CreateTestUser(t, prefix, "User")
	gid := CreateTestGroup(t, prefix)
	CreateTestMembership(t, uid, gid, "Moderator")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "Moderator", readSystemrole(uid))
}

// Support/Admin outrank Moderator and are set deliberately — never auto-changed,
// even with no mod membership.
func TestSyncSystemRole_LeavesSupportUntouched(t *testing.T) {
	uid := CreateTestUser(t, uniquePrefix("syncrole_support"), "Support")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "Support", readSystemrole(uid))
}

func TestSyncSystemRole_LeavesAdminUntouched(t *testing.T) {
	uid := CreateTestUser(t, uniquePrefix("syncrole_admin"), "Admin")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "Admin", readSystemrole(uid))
}

// A plain User with no mod membership stays a User (no spurious promotion).
func TestSyncSystemRole_UserWithNoMembershipStaysUser(t *testing.T) {
	uid := CreateTestUser(t, uniquePrefix("syncrole_user"), "User")
	user.SyncSystemRole(database.DBConn, uid)
	assert.Equal(t, "User", readSystemrole(uid))
}
