// Package modmessaging answers whether Freegle moderators may talk to the person behind a
// post, and removes posts nobody is in a position to moderate.
//
// The TN API ingestion (iznik-batch, GroupPostIngestionService) places a Trash Nothing post
// on the Freegle group its own lat/lng falls in, NOT on a group the poster asked for. When
// the resolved group is absent from TN's freegle_group_ids for that poster, the poster has
// consented to nothing here: they are not a member of that community in any meaningful
// sense, and its moderators have no relationship with them. Ingestion records that as
// messages_groups.mod_messaging_allowed = 0 - the only writer of a 0 anywhere, so every
// other post in the database reads as addressed.
//
// "Unaddressed" is therefore a property of the post's ORIGIN row (rippled_in = 0). The
// rippling engine inserts its copies without the column, so they take the table default
// (1); testing them too would read every rippled copy of an unaddressed post as addressed.
package modmessaging

import (
	stdlog "log"

	"github.com/freegle/iznik-server-go/auth"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// RemovalReason is recorded in messages_groups.spamreason and in the audit log against an
// automatic removal, so a Support user looking at a vanished post can see what took it down.
const RemovalReason = "Removed automatically: reported by members, with no community able to moderate it."

// PostIsUnaddressed reports whether msgid is a TN post that was never addressed to the
// Freegle community it landed on. False for every ordinary post, and false when the origin
// row is missing - the safe direction, since everything this gates only ever removes
// moderator abilities.
func PostIsUnaddressed(db *gorm.DB, msgid uint64) bool {
	if msgid == 0 {
		return false
	}

	var n int64
	db.Table("messages_groups").
		Where("msgid = ? AND rippled_in = 0 AND mod_messaging_allowed = 0", msgid).
		Count(&n)

	return n > 0
}

// ModsMayContact reports whether groupid's moderators may contact userid: open a chat to
// them, write in an existing one, or send them mail or a standard message.
//
// Anyone who is not a TrashNothing member can be contacted. A TrashNothing member can be
// contacted only through a membership of this community that they chose: Approved, not
// implicit and not rippled. A TrashNothing placement or a ripple join is not one, and once
// they leave, the community's moderators can no longer reach them, even in a chat they
// had before. It is decided per community. A TrashNothing member's posts and memberships
// elsewhere give this community's moderators nothing.
//
// TrashNothing shows our messages to its members as coming from a Freegle user, so a
// member who never chose this community cannot tell its moderators from a stranger.
func ModsMayContact(db *gorm.DB, userid uint64, groupid uint64) bool {
	if userid == 0 {
		return true
	}

	if !IsTrashNothingMember(db, userid) {
		return true
	}

	return HasChosenMembership(db, userid, groupid)
}

// MayContact is ModsMayContact for one person acting: Support and Admin act for Freegle as a
// whole rather than for a community, so the rule does not bind them.
func MayContact(db *gorm.DB, actor uint64, userid uint64, groupid uint64) bool {
	return auth.IsAdminOrSupport(actor) || ModsMayContact(db, userid, groupid)
}

// HasChosenMembership reports whether userid has a membership of groupid they chose:
// Approved, not implicit and not rippled. A failed read answers no.
func HasChosenMembership(db *gorm.DB, userid uint64, groupid uint64) bool {
	var chosen int64
	if err := db.Table("memberships").
		Where("userid = ? AND groupid = ? AND collection = ? AND implicit = 0 AND rippled = 0",
			userid, groupid, utils.COLLECTION_APPROVED).
		Count(&chosen).Error; err != nil {
		stdlog.Printf("Failed to read membership of user %d in group %d: %v", userid, groupid, err)
		return false
	}

	return chosen > 0
}

// MayContactThroughAnyGroup reports whether actor may contact userid as a moderator of any
// community: Support or Admin, someone who is not a TrashNothing member, or a TrashNothing
// member with a membership they chose of a community actor moderates. For a note added
// to a chat between two members, which speaks for no one community.
func MayContactThroughAnyGroup(db *gorm.DB, actor uint64, userid uint64) bool {
	if userid == 0 || auth.IsAdminOrSupport(actor) || !IsTrashNothingMember(db, userid) {
		return true
	}

	var shared int64
	if err := db.Table("memberships mm").
		Joins("INNER JOIN memberships mp ON mp.groupid = mm.groupid").
		Where("mm.userid = ? AND mm.role IN (?, ?) AND mp.userid = ? AND mp.collection = ? AND mp.implicit = 0 AND mp.rippled = 0",
			actor, utils.ROLE_MODERATOR, utils.ROLE_OWNER, userid, utils.COLLECTION_APPROVED).
		Count(&shared).Error; err != nil {
		stdlog.Printf("Failed to read shared communities of user %d and moderator %d: %v", userid, actor, err)
		return false
	}

	return shared > 0
}

// MembershipAllowsContact is ModsMayContact for a membership row already in hand, for the
// members list.
func MembershipAllowsContact(trashNothing bool, collection string, implicit bool, rippled bool) bool {
	return !trashNothing || (collection == utils.COLLECTION_APPROVED && !implicit && !rippled)
}

// IsTrashNothingMember is TrashNothingMembers for one user.
func IsTrashNothingMember(db *gorm.DB, userid uint64) bool {
	return userid > 0 && TrashNothingMembers(db, []uint64{userid})[userid]
}

// TrashNothingMembers says which of userids are TrashNothing members, in one query, by
// the definition in user.TrashNothingSQL. Users absent from the map are not.
func TrashNothingMembers(db *gorm.DB, userids []uint64) map[uint64]bool {
	out := make(map[uint64]bool, len(userids))
	if len(userids) == 0 {
		return out
	}

	var ids []uint64
	if err := db.Raw("SELECT u.id FROM users u WHERE u.id IN (?) AND "+user.TrashNothingSQL("u"), userids).Scan(&ids).Error; err != nil {
		stdlog.Printf("Failed to read which of %d users are TrashNothing members: %v", len(userids), err)
	}
	for _, id := range ids {
		out[id] = true
	}

	return out
}

// RemoveUnaddressedPost takes an unaddressed post off the platform: off every community it
// reached, out of browse and out of any future digest, with its ripple frozen so it can
// never re-reach anyone.
//
// Deliberately a SOFT delete. Nobody reviews this - it happens on a quorum of member
// reports with no moderator in the loop - so it stays recoverable by Support, and leaves a
// per-group audit trail rather than making the post silently vanish. Mirrors the
// withdrawn-while-pending cleanup in message.handleOutcome.
func RemoveUnaddressedPost(db *gorm.DB, msgid uint64) {
	if msgid == 0 {
		return
	}

	// The groups it was live on, captured before the delete so each gets its own log row.
	var groupids []uint64
	db.Table("messages_groups").Where("msgid = ? AND deleted = 0", msgid).Pluck("groupid", &groupids)
	if len(groupids) == 0 {
		return
	}

	if result := db.Table("messages_groups").Where("msgid = ? AND deleted = 0", msgid).
		Updates(map[string]interface{}{"deleted": gorm.Expr("1"), "spamreason": RemovalReason}); result.Error != nil {
		stdlog.Printf("Failed to remove unaddressed post %d from its groups: %v", msgid, result.Error)
		return
	}

	// messageid is cleared alongside the delete exactly as the moderator delete path does,
	// so a later redelivery of the same RFC822 id is not read as a duplicate of a dead row.
	if result := db.Table("messages").Where("id = ?", msgid).
		Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")}); result.Error != nil {
		stdlog.Printf("Failed to soft-delete unaddressed post %d: %v", msgid, result.Error)
	}

	// Stop the ripple dead: status='held' with no next expansion means it neither spreads
	// further nor gets re-reached and re-notified if the row is ever restored.
	db.Table("rippling_reach").Where("msgid = ? AND status <> 'held'", msgid).
		Updates(map[string]interface{}{"status": gorm.Expr("'held'"), "next_expansion_at": gorm.Expr("NULL")})

	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", msgid).Scan(&fromuser)

	// byuser NULL: this is a system removal, not a moderator's action, and attributing it
	// to whoever happened to file the second report would misread as a mod decision.
	for _, gid := range groupids {
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": flog.LOG_TYPE_MESSAGE, "subtype": flog.LOG_SUBTYPE_DELETED,
			"groupid": gid, "user": fromuser, "byuser": gorm.Expr("NULL"), "msgid": msgid, "text": RemovalReason,
		})
	}

	if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
		"msgid": msgid,
	}); err != nil {
		stdlog.Printf("Failed to queue freebie alerts remove for removed post %d: %v", msgid, err)
	}
}
