package message

import (
	"log"

	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// BanWithdrawnText is the outcome comment on a post withdrawn by a ban, as V1's
// User::removeMembership($ban) wrote it.
const BanWithdrawnText = "Marked as withdrawn by ban"

// WithdrawPostsOnBan takes a banned member's open OFFER and WANTED posts on the community
// down, as V1's User::removeMembership($ban) did: a ban stops someone posting on the
// community, and that includes the posts already there. Only posts whose home is this
// community (rippled_in = 0); a post that rippled in from a neighbour is that neighbour's.
//
// A post with an Approved copy gets a Withdrawn outcome, and any copy still Pending
// elsewhere is retired, as handleOutcome does for a member's own withdrawal, so it is never
// approved into that community later. A post that is only Pending is deleted instead, as a
// member's own withdrawal of a pending post is. Nobody who replied is told, as in V1.
func WithdrawPostsOnBan(db *gorm.DB, userid uint64, groupid uint64, byuser uint64) {
	var msgids []uint64
	if err := db.Raw("SELECT DISTINCT m.id FROM messages m "+
		"INNER JOIN messages_groups mg ON mg.msgid = m.id "+
		"WHERE m.fromuser = ? AND mg.groupid = ? AND mg.rippled_in = 0 AND mg.deleted = 0 "+
		"AND m.deleted IS NULL AND m.type IN (?, ?) "+
		"AND NOT EXISTS (SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = m.id)",
		userid, groupid, utils.OFFER, utils.WANTED).Scan(&msgids).Error; err != nil {
		log.Printf("Failed to find posts to withdraw for user %d banned from group %d: %v", userid, groupid, err)
		return
	}

	for _, msgid := range msgids {
		// A failed read must not be taken for "not approved anywhere", which would delete a
		// live post, so the post is left alone instead.
		var approved int64
		if err := db.Table("messages_groups").
			Where("msgid = ? AND collection = ? AND deleted = 0", msgid, utils.COLLECTION_APPROVED).
			Count(&approved).Error; err != nil {
			log.Printf("Failed to read where message %d is approved, not withdrawing it on ban: %v", msgid, err)
			continue
		}

		var pendingGroups []uint64
		if err := db.Table("messages_groups").Select("groupid").
			Where("msgid = ? AND collection = ? AND deleted = 0", msgid, utils.COLLECTION_PENDING).
			Scan(&pendingGroups).Error; err != nil {
			log.Printf("Failed to read where message %d is pending, not withdrawing it on ban: %v", msgid, err)
			continue
		}

		if approved == 0 {
			// Only pending: delete it, as handleOutcome does for a pending withdrawal.
			var groups []uint64
			db.Table("messages_groups").Select("groupid").Where("msgid = ? AND deleted = 0", msgid).Scan(&groups)
			db.Table("messages_groups").Where("msgid = ?", msgid).Update("deleted", gorm.Expr("1"))
			db.Table("messages").Where("id = ?", msgid).
				Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")})
			for _, gid := range groups {
				logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, gid, userid, byuser, msgid, 0, BanWithdrawnText)
			}
		} else {
			db.Table("messages_outcomes_intended").Where("msgid = ?", msgid).Delete(nil)
			db.Table("messages_outcomes").Create(map[string]interface{}{
				"msgid": msgid, "outcome": utils.OUTCOME_WITHDRAWN, "comments": BanWithdrawnText,
			})
			logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_OUTCOME, groupid, userid, byuser, msgid, 0,
				"Withdrawn: "+BanWithdrawnText)

			if len(pendingGroups) > 0 {
				db.Table("messages_groups").
					Where("msgid = ? AND collection = ? AND deleted = 0", msgid, utils.COLLECTION_PENDING).
					Update("deleted", gorm.Expr("1"))
				for _, gid := range pendingGroups {
					logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, gid, userid, byuser, msgid, 0, utils.OUTCOME_WITHDRAWN)
				}
			}
		}

		if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{"msgid": msgid}); err != nil {
			log.Printf("Failed to queue freebie alerts remove for message %d withdrawn by ban: %v", msgid, err)
		}
	}
}
