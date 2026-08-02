package user

import (
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// SyncSystemRole reconciles users.systemrole with a user's actual group
// moderatorships after a membership change. It mirrors V1
// User::updateSystemRole (iznik-server/include/user/User.php):
//
//   - a User who now holds an Owner/Moderator membership on any group is
//     promoted to Moderator;
//   - a Moderator who no longer holds any Owner/Moderator membership is
//     demoted to User.
//
// Support and Admin are never auto-changed — they are granted deliberately and
// outrank Moderator. systemrole transitions are rare, so recomputing on each
// membership mutation is cheap.
//
// In the Go API this is currently invoked only on membership DELETION
// (leave/ban) — the path V1 covered but the Go rewrite did not, which left
// ~800 ex-moderators carrying a stale Moderator systemrole (and the elevated
// access it implies). Role promotions (Member->Moderator) still flow through
// V1, which calls updateSystemRole itself; the promotion branch here keeps the
// helper correct should Go ever gain a mod-granting path.
func SyncSystemRole(db *gorm.DB, userid uint64) {
	if userid == 0 {
		return
	}

	var systemrole string
	// ORM migration site 132e1b9b2d4a (wave 1).
	db.Table("users").Select("systemrole").Where("id = ?", userid).Scan(&systemrole)

	// Only the User<->Moderator transition is automatic; never touch Support/Admin.
	if systemrole != utils.SYSTEMROLE_USER && systemrole != utils.SYSTEMROLE_MODERATOR {
		return
	}

	var modCount int64
	// ORM migration site 21d2f5cc09d7 (wave 1).
	db.Table("memberships").Where("userid = ? AND role IN (?, ?)",
		userid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Count(&modCount)

	switch {
	case modCount > 0 && systemrole == utils.SYSTEMROLE_USER:
		// Guarded WHERE so a concurrent Support/Admin change is never clobbered.
		// ORM migration site df3c6bdb7aba (wave 2).
		db.Table("users").Where("id = ? AND systemrole = ?", userid, utils.SYSTEMROLE_USER).
			Update("systemrole", utils.SYSTEMROLE_MODERATOR)
	case modCount == 0 && systemrole == utils.SYSTEMROLE_MODERATOR:
		// ORM migration site e4f7cca3adb8 (wave 2).
		db.Table("users").Where("id = ? AND systemrole = ?", userid, utils.SYSTEMROLE_MODERATOR).
			Update("systemrole", utils.SYSTEMROLE_USER)
	}
}
