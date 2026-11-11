package message

import (
	"log"

	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// BanWithdrawnText is the outcome comment on a post withdrawn by a ban.
const BanWithdrawnText = "Marked as withdrawn by ban"

// WithdrawPostsOnBan takes a banned member's open OFFER and WANTED posts down: a ban stops
// someone posting, and that includes the posts already up. An approved post gets a Withdrawn
// outcome; a post that is only pending is deleted, as a member's own withdrawal of a pending
// post is. Posts that already have an outcome are left alone. Nobody who replied is told.
//
// Each post is taken down in its own transaction, so a failure never leaves a post with its
// intended outcome removed and no withdrawal recorded. The first error stops the run and is
// returned; posts already withdrawn stay withdrawn.
func WithdrawPostsOnBan(db *gorm.DB, userid uint64, byuser uint64) error {
	var posts []struct {
		ID         uint64
		Collection string
	}

	if err := db.Table("messages m").Select("m.id, m.collection").
		Where("m.fromuser = ? AND m.deleted IS NULL AND m.type IN ?", userid, []string{utils.OFFER, utils.WANTED}).
		Where("NOT EXISTS (SELECT 1 FROM messages_outcomes mo WHERE mo.msgid = m.id)").
		Scan(&posts).Error; err != nil {
		log.Printf("Failed to find posts to withdraw for banned user %d: %v", userid, err)
		return err
	}

	for _, p := range posts {
		if p.Collection != utils.COLLECTION_PENDING && p.Collection != utils.COLLECTION_APPROVED {
			continue
		}

		err := db.Transaction(func(tx *gorm.DB) error {
			if p.Collection == utils.COLLECTION_PENDING {
				if err := tx.Table("messages").Where("id = ?", p.ID).
					Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")}).Error; err != nil {
					return err
				}
				logModAction(tx, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, userid, byuser, p.ID, 0, BanWithdrawnText)
				return nil
			}

			if err := tx.Table("messages_outcomes_intended").Where("msgid = ?", p.ID).Delete(nil).Error; err != nil {
				return err
			}
			if err := tx.Table("messages_outcomes").Create(map[string]interface{}{
				"msgid": p.ID, "outcome": utils.OUTCOME_WITHDRAWN, "comments": BanWithdrawnText,
			}).Error; err != nil {
				return err
			}
			logModAction(tx, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_OUTCOME, userid, byuser, p.ID, 0,
				"Withdrawn: "+BanWithdrawnText)
			return nil
		})
		if err != nil {
			log.Printf("Failed to withdraw message %d for banned user %d: %v", p.ID, userid, err)
			return err
		}

		if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{"msgid": p.ID}); err != nil {
			log.Printf("Failed to queue freebie alerts remove for message %d withdrawn by ban: %v", p.ID, err)
		}
	}

	return nil
}
