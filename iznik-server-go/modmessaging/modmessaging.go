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
// query, for the members list. Users absent from the map - including any user with no
// posts at all - are unrestricted.
func UsersUnaddressedOnly(db *gorm.DB, userids []uint64) map[uint64]bool {
	out := make(map[uint64]bool, len(userids))
	if len(userids) == 0 {
		return out
	}

	// One pass over the users' origin rows, reduced per user: MIN() is 0 only if they have
	// an unaddressed post, MAX() is 0 only if they have no addressed one.
	//
	// Positional Rows().Scan, not Scan(&struct): a SELECT of SEVERAL aggregates scanned
	// into a struct silently comes back all-zero through GORM here (no error, no log), and
	// all-zero is exactly the value that reads as "restrict this member".
	rows, err := db.Raw(
		"SELECT m.fromuser, MIN(mg.mod_messaging_allowed), MAX(mg.mod_messaging_allowed) "+
			"FROM messages m "+
			"INNER JOIN messages_groups mg ON mg.msgid = m.id AND mg.rippled_in = 0 "+
			"WHERE m.fromuser IN (?) "+
			"GROUP BY m.fromuser", userids).Rows()
	if err != nil || rows == nil {
		if err != nil {
			stdlog.Printf("Failed to read mod-messaging status for %d users: %v", len(userids), err)
		}
		return out
	}
	defer rows.Close()

	for rows.Next() {
		var userid uint64
		var minimum, maximum int
		if err := rows.Scan(&userid, &minimum, &maximum); err != nil {
			stdlog.Printf("Failed to scan mod-messaging status row: %v", err)
			continue
		}
		out[userid] = minimum == 0 && maximum == 0
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
