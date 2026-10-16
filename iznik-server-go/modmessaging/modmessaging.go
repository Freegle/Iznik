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
	"fmt"
	stdlog "log"

	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/queue"
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

// UserIsUnaddressedOnly reports whether every post this user has ever made is unaddressed.
// A "mixed" poster - one unaddressed post plus any ordinary one - is a real Freegle member
// and is deliberately NOT restricted, so moderators can still reach them.
//
// Deleted posts still count. Someone whose only unaddressed post has been taken down has
// not thereby become contactable, and a post removed by the report path would otherwise
// silently restore the chat button.
func UserIsUnaddressedOnly(db *gorm.DB, userid uint64) bool {
	if userid == 0 {
		return false
	}

	return UsersUnaddressedOnly(db, []uint64{userid})[userid]
}

// UsersUnaddressedOnly answers UserIsUnaddressedOnly for a whole page of users in one
// query. Users absent from the map - including any user with no posts at all - are
// unrestricted.
func UsersUnaddressedOnly(db *gorm.DB, userids []uint64) map[uint64]bool {
	out := make(map[uint64]bool, len(userids))
	for userid, perGroup := range originStatus(db, userids) {
		out[userid] = !addressedAnywhere(perGroup)
	}

	return out
}

// IsUnaddressedOn reports whether the user's only tie to ONE group is TN posts placed there
// without their choosing: at least one unaddressed post on it, and no addressed one.
//
// That is what the membership the TN ingestion makes for such a post stands for, and it is
// the TN equivalent of a ripple-join (memberships.rippled = 1), so it is barred from what
// that is barred from. Unlike UserIsUnaddressedOnly it is per group: a "mixed" poster is a
// real member where they chose to be, and only there. Deleted posts still count, for the
// same reason as there. False for a missing id. Mirrors iznik-batch's
// ModMessaging::isUnaddressedOn.
func IsUnaddressedOn(db *gorm.DB, userid uint64, groupid uint64) bool {
	if userid == 0 || groupid == 0 {
		return false
	}

	posts := func(allowed int) bool {
		var n int64
		db.Table("messages mm_msg").
			Joins("INNER JOIN messages_groups mm_mg ON mm_mg.msgid = mm_msg.id AND mm_mg.rippled_in = 0").
			Where("mm_msg.fromuser = ? AND mm_mg.groupid = ? AND mm_mg.mod_messaging_allowed = ?",
				userid, groupid, allowed).
			Count(&n)
		return n > 0
	}

	return posts(0) && !posts(1)
}

// CannotBeContactedOn reports whether the group's moderators have nobody to write to: the
// user is unaddressed everywhere, or unaddressed on this group. It is the bar on moderators
// starting a chat or a modmail, and on telling the member they were removed.
func CannotBeContactedOn(db *gorm.DB, userid uint64, groupid uint64) bool {
	return UserIsUnaddressedOnly(db, userid) || IsUnaddressedOn(db, userid, groupid)
}

// Membership names one user's membership of one group.
type Membership struct {
	Userid  uint64
	Groupid uint64
}

// MembershipsCannotBeContacted answers CannotBeContactedOn for a whole page of memberships
// in one query, for the members list. Memberships absent from the map are unrestricted.
func MembershipsCannotBeContacted(db *gorm.DB, memberships []Membership) map[Membership]bool {
	out := make(map[Membership]bool, len(memberships))
	if len(memberships) == 0 {
		return out
	}

	userids := make([]uint64, 0, len(memberships))
	for _, m := range memberships {
		userids = append(userids, m.Userid)
	}
	status := originStatus(db, userids)

	for _, m := range memberships {
		perGroup := status[m.Userid]
		if len(perGroup) == 0 {
			continue
		}

		here := perGroup[m.Groupid]
		out[m] = !addressedAnywhere(perGroup) || (here.unaddressed && !here.addressed)
	}

	return out
}

// UnaddressedOnSQL is IsUnaddressedOn as a SQL predicate over a user column and a group
// column, for a query that selects members - typically memberships.userid and
// memberships.groupid. The columns are spliced in as given, so pass column names, never
// input. Driven from the user's own posts (messages.fromuser), so it is a few keyed probes
// per row however big the group is.
func UnaddressedOnSQL(userColumn string, groupColumn string) string {
	origin := func(allowed int) string {
		return fmt.Sprintf("SELECT 1 FROM messages mm_msg "+
			"INNER JOIN messages_groups mm_mg ON mm_mg.msgid = mm_msg.id AND mm_mg.rippled_in = 0 "+
			"WHERE mm_msg.fromuser = %s AND mm_mg.groupid = %s AND mm_mg.mod_messaging_allowed = %d",
			userColumn, groupColumn, allowed)
	}

	return "(EXISTS (" + origin(0) + ") AND NOT EXISTS (" + origin(1) + "))"
}

// groupStatus is what a user's origin posts on one group say about it.
type groupStatus struct {
	unaddressed bool
	addressed   bool
}

func addressedAnywhere(perGroup map[uint64]groupStatus) bool {
	for _, s := range perGroup {
		if s.addressed {
			return true
		}
	}

	return false
}

// originStatus reads, in one query, which kinds of origin post each user has on each group
// they have posted to. Users with no posts are absent.
func originStatus(db *gorm.DB, userids []uint64) map[uint64]map[uint64]groupStatus {
	out := make(map[uint64]map[uint64]groupStatus, len(userids))
	if len(userids) == 0 {
		return out
	}

	// One pass over the users' origin rows, reduced per user and group: MIN() is 0 only if
	// there is an unaddressed post, MAX() is 1 only if there is an addressed one.
	//
	// Positional Rows().Scan, not Scan(&struct): a SELECT of SEVERAL aggregates scanned
	// into a struct silently comes back all-zero through GORM here (no error, no log), and
	// all-zero is exactly the value that reads as "restrict this member".
	rows, err := db.Table("messages m").
		Select("m.fromuser, mg.groupid, MIN(mg.mod_messaging_allowed), MAX(mg.mod_messaging_allowed)").
		Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id AND mg.rippled_in = 0").
		Where("m.fromuser IN ?", userids).
		Group("m.fromuser, mg.groupid").
		Rows()
	if err != nil || rows == nil {
		if err != nil {
			stdlog.Printf("Failed to read mod-messaging status for %d users: %v", len(userids), err)
		}
		return out
	}
	defer rows.Close()

	for rows.Next() {
		var userid, groupid uint64
		var minimum, maximum int
		if err := rows.Scan(&userid, &groupid, &minimum, &maximum); err != nil {
			stdlog.Printf("Failed to scan mod-messaging status row: %v", err)
			continue
		}
		if out[userid] == nil {
			out[userid] = map[uint64]groupStatus{}
		}
		out[userid][groupid] = groupStatus{unaddressed: minimum == 0, addressed: maximum == 1}
	}

	// A read that died halfway leaves the rest of the page looking unrestricted, which is
	// the wrong way for a moderator to find out. Log it; the members list still renders.
	if err := rows.Err(); err != nil {
		stdlog.Printf("Failed to read mod-messaging status for %d users: %v", len(userids), err)
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
