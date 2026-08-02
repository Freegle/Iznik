package session

import (
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// MergeUserAccounts merges the account `loser` into `survivor` after the
// survivor proves ownership of one of the loser's email addresses via email
// verification. All references move to the surviving user and the loser is
// marked deleted. Everything runs in one transaction so a failure rolls back
// cleanly rather than leaving the merge half-applied.
func MergeUserAccounts(db *gorm.DB, survivor uint64, loser uint64) error {
	return db.Transaction(func(tx *gorm.DB) error {
		// ORM migration site d7333aa6fdae (wave 2).
		if err := tx.Table("messages").Where("fromuser = ?", loser).Update("fromuser", survivor).Error; err != nil {
			return err
		}

		if err := mergeChatRooms(tx, survivor, loser); err != nil {
			return err
		}

		// ORM migration site 14cccaf3f10c (wave 2).
		if err := tx.Table("chat_messages").Where("userid = ?", loser).Update("userid", survivor).Error; err != nil {
			return err
		}

		// ORM migration site ea1047806312 (wave 2).
		if err := tx.Table("users_emails").Where("userid = ?", loser).Update("userid", survivor).Error; err != nil {
			return err
		}

		// The survivor may already belong to some of the loser's groups —
		// IGNORE skips those, then the leftovers are removed.
		// ORM migration site e3d91cc664c5 (wave 2).
		if err := tx.Table("memberships").Clauses(clause.Update{Modifier: "IGNORE"}).
			Where("userid = ?", loser).Update("userid", survivor).Error; err != nil {
			return err
		}
		// ORM migration site 191766b7cd39 (wave 2).
		if err := tx.Table("memberships").Where("userid = ?", loser).Delete(nil).Error; err != nil {
			return err
		}

		// ORM migration site 5060d022dfe8 (wave 2).
		return tx.Table("users").Where("id = ?", loser).Update("deleted", gorm.Expr("NOW()")).Error
	})
}

// mergeChatRooms reassigns the loser's chat rooms to the survivor without
// tripping the unique key on (user1, user2, chattype). A blind UPDATE fails
// with a duplicate-key error when both users already have a room with the
// same counterparty, aborting the whole statement and stranding those rooms
// (and their history) on the about-to-be-deleted loser. Instead:
//
//  1. Rooms directly between the pair would become self-chats after the
//     merge — delete them (their chat_messages and chat_roster rows go via
//     FK cascade).
//  2. Where both users have a room with the same counterparty and chattype
//     (in either user1/user2 ordering), move the loser room's history into
//     the survivor's room, then delete the loser room.
//  3. Reassign whatever remains, which can no longer collide.
func mergeChatRooms(tx *gorm.DB, survivor uint64, loser uint64) error {
	// 1. Direct rooms between the two users, plus any degenerate self-rooms.
	// ORM migration site 787b2eadea22 (wave 2).
	if err := tx.Table("chat_rooms").
		Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
			survivor, loser, loser, survivor, loser, loser).Delete(nil).Error; err != nil {
		return err
	}

	// 2. Loser rooms whose counterparty+chattype the survivor already has.
	// The counterparty can sit in user1 or user2 on either side, so match via
	// IF() rather than on raw columns. A NULL counterparty (User2Mod rooms
	// have user2 NULL) never matches here — those rooms can't collide anyway
	// because the unique key permits repeated NULLs. MIN() picks one surviving
	// room if the survivor somehow has rooms in both orderings.
	var pairs []struct {
		LoserID    uint64
		SurvivorID uint64
	}
	if err := tx.Raw(`SELECT lr.id AS loser_id, MIN(sr.id) AS survivor_id
		FROM chat_rooms lr
		JOIN chat_rooms sr
		  ON sr.chattype = lr.chattype
		 AND (   (sr.user1 = ? AND sr.user2 = IF(lr.user1 = ?, lr.user2, lr.user1))
		      OR (sr.user2 = ? AND sr.user1 = IF(lr.user1 = ?, lr.user2, lr.user1)))
		WHERE lr.user1 = ? OR lr.user2 = ?
		GROUP BY lr.id`,
		survivor, loser, survivor, loser, loser, loser).Scan(&pairs).Error; err != nil {
		return err
	}

	for _, p := range pairs {
		// Move the history before deleting the room — chat_messages.chatid
		// cascades on room deletion.
		// ORM migration site 90a890072eab (wave 2).
		if err := tx.Table("chat_messages").Where("chatid = ?", p.LoserID).Update("chatid", p.SurvivorID).Error; err != nil {
			return err
		}
		// ORM migration site d820bc9bf511 (wave 2).
		if err := tx.Table("chat_messages").Where("refchatid = ?", p.LoserID).Update("refchatid", p.SurvivorID).Error; err != nil {
			return err
		}
		// Carry roster presence (unread pointers etc.) across where the
		// surviving room doesn't already have a row for that user.
		// ORM migration site 2c505360400e (wave 2).
		if err := tx.Table("chat_roster").Clauses(clause.Update{Modifier: "IGNORE"}).
			Where("chatid = ?", p.LoserID).Update("chatid", p.SurvivorID).Error; err != nil {
			return err
		}
		// Surface the merged history's recency in chat list ordering. Multi-table
		// UPDATE...JOIN — out of wave 2's single-table scope, left raw.
		if err := tx.Exec(`UPDATE chat_rooms surv JOIN chat_rooms lose ON lose.id = ?
			SET surv.latestmessage = lose.latestmessage
			WHERE surv.id = ? AND lose.latestmessage IS NOT NULL
			  AND (surv.latestmessage IS NULL OR surv.latestmessage < lose.latestmessage)`,
			p.LoserID, p.SurvivorID).Error; err != nil {
			return err
		}
		// ORM migration site e91e1b857064 (wave 2).
		if err := tx.Table("chat_rooms").Where("id = ?", p.LoserID).Delete(nil).Error; err != nil {
			return err
		}
	}

	// 3. The remaining rooms can't collide: colliding counterparties were
	// consolidated above, and User2Mod-style rooms have a NULL column the
	// unique key doesn't enforce over.
	// ORM migration site e5c1d288cbae (wave 2).
	if err := tx.Table("chat_rooms").Where("user1 = ?", loser).Update("user1", survivor).Error; err != nil {
		return err
	}
	// ORM migration site 3b4c210e4a12 (wave 2).
	if err := tx.Table("chat_rooms").Where("user2 = ?", loser).Update("user2", survivor).Error; err != nil {
		return err
	}

	// Move the loser's roster presence to the survivor; where the survivor
	// already sits in a room, keep theirs and drop the loser's.
	// ORM migration site f370eb323bd4 (wave 2).
	if err := tx.Table("chat_roster").Clauses(clause.Update{Modifier: "IGNORE"}).
		Where("userid = ?", loser).Update("userid", survivor).Error; err != nil {
		return err
	}
	// ORM migration site 8a7c730e0d5c (wave 2).
	return tx.Table("chat_roster").Where("userid = ?", loser).Delete(nil).Error
}
