package user

import (
	"log"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
)

// IsAdminOrSupport checks if the user has Admin or Support system role.
// Delegates to auth.IsAdminOrSupport to avoid circular imports.
func IsAdminOrSupport(myid uint64) bool {
	return auth.IsAdminOrSupport(myid)
}

// IsModOfGroup checks if the user is a Moderator or Owner of the given group, or is Admin/Support.
func IsModOfGroup(myid uint64, groupid uint64) bool {
	return auth.IsModOfGroup(myid, groupid)
}

// IsActiveModOfGroup reports whether myid is an ACTIVE moderator/owner of the group. Admin/Support
// always qualify; otherwise the membership must be Moderator/Owner AND the mod must not have stepped
// back to backup (activeModSettingsSQL: settings.active wins, legacy settings.showmessages as
// fallback). This is stricter than IsModOfGroup, which checks role only - it is used to keep a
// group's work queues (Pending/Spam/Edit) hidden from backup mods even when an explicit groupid is
// requested, matching the groupid=0 path (GetActiveModGroupIDs) and the work-count badges.
func IsActiveModOfGroup(myid uint64, groupid uint64) bool {
	if auth.IsAdminOrSupport(myid) {
		return true
	}
	if groupid == 0 {
		return false
	}
	db := database.DBConn
	var count int64
	result := db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ? AND role IN (?, ?) AND collection = ? "+
		"AND "+activeModSettingsSQL,
		myid, groupid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).Scan(&count)
	if result.Error != nil {
		log.Printf("Failed to check active mod role for user %d group %d: %v", myid, groupid, result.Error)
		return false
	}
	return count > 0
}

// IsModOfUser checks if myid is Admin/Support or a Moderator/Owner of any
// group that targetid also belongs to (including groups the target is banned from).
func IsModOfUser(myid, targetid uint64) bool {
	if auth.IsAdminOrSupport(myid) {
		return true
	}
	db := database.DBConn
	var count int64

	// Check active memberships.
	result := db.Raw("SELECT COUNT(*) FROM memberships m1 "+
		"INNER JOIN memberships m2 ON m2.groupid = m1.groupid "+
		"WHERE m1.userid = ? AND m2.userid = ? "+
		"AND m1.role IN (?, ?)",
		myid, targetid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&count)
	if result.Error != nil {
		log.Printf("Failed to check IsModOfUser for user %d target %d: %v", myid, targetid, result.Error)
		return false
	}
	if count > 0 {
		return true
	}

	// Also check users_banned — banning deletes the memberships row,
	// so banned users won't appear in the query above.
	result = db.Raw("SELECT COUNT(*) FROM memberships m1 "+
		"INNER JOIN users_banned b ON b.groupid = m1.groupid "+
		"WHERE m1.userid = ? AND b.userid = ? "+
		"AND m1.role IN (?, ?)",
		myid, targetid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&count)
	if result.Error != nil {
		log.Printf("Failed to check IsModOfUser (banned) for user %d target %d: %v", myid, targetid, result.Error)
		return false
	}
	return count > 0
}

