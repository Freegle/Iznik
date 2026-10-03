package microvolunteering

import (
	"gorm.io/gorm"
)

// Experiment: graded micro-volunteering.
//
// A graded task is a CheckMessage task on a post whose verdict other members have already
// settled, so the answer can be marked. It is what a frequent replier has to get right
// before the next reply is accepted (see chat.ReplyGateAfter). Nobody has to be there: the
// answer key is the quorum that already exists.

// GradedWindowHours is how long a correct answer keeps the reply gate open.
const GradedWindowHours = 24

// SettledVerdict returns "Approve" or "Reject" when at least ApprovalQuorum other members
// agree on the post, or "" when they do not. The member being graded is excluded, so their
// own answer can never settle the post they are answering about.
func SettledVerdict(db *gorm.DB, msgid uint64, excludeUser uint64) string {
	var approves, rejects int64
	db.Table("microactions").
		Where("actiontype = ? AND msgid = ? AND userid <> ? AND result = ?", ChallengeCheckMessage, msgid, excludeUser, "Approve").
		Count(&approves)
	db.Table("microactions").
		Where("actiontype = ? AND msgid = ? AND userid <> ? AND result = ?", ChallengeCheckMessage, msgid, excludeUser, "Reject").
		Count(&rejects)

	quorum := int64(ApprovalQuorum)
	switch {
	case approves >= quorum && rejects < quorum:
		return "Approve"
	case rejects >= quorum && approves < quorum:
		return "Reject"
	default:
		return ""
	}
}

// Grade says whether a member's answer on a post agrees with the settled verdict. nil when
// the post is not settled, so the answer cannot be marked either way.
func Grade(db *gorm.DB, msgid uint64, userid uint64, result string) *bool {
	settled := SettledVerdict(db, msgid, userid)
	if settled == "" {
		return nil
	}
	ok := settled == result
	return &ok
}

// HasRecentGradedPass reports whether the member has answered a graded task correctly in
// the last GradedWindowHours. It is computed from the rows rather than stored, so a verdict
// that settles later counts too.
func HasRecentGradedPass(db *gorm.DB, userid uint64) bool {
	type row struct {
		Msgid  uint64
		Result string
	}
	var mine []row
	db.Table("microactions").
		Select("msgid, result").
		Where("actiontype = ? AND userid = ? AND msgid IS NOT NULL AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
			ChallengeCheckMessage, userid, GradedWindowHours).
		Order("timestamp DESC").
		Limit(20).
		Scan(&mine)
	for _, m := range mine {
		if g := Grade(db, m.Msgid, userid, m.Result); g != nil && *g {
			return true
		}
	}
	return false
}

// getGradedMessageChallenge picks a settled post in the member's communities that they have
// not answered on and did not write. Newest first, so the post is still recognisable.
func getGradedMessageChallenge(db *gorm.DB, userID uint64, groupIDs []uint64) *Challenge {
	if len(groupIDs) == 0 {
		return nil
	}
	var candidates []uint64
	db.Table("messages_groups AS mg").
		Select("mg.msgid").
		Joins("INNER JOIN messages m ON m.id = mg.msgid").
		Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL "+
			"AND COALESCE(m.fromuser, 0) <> ? "+
			"AND NOT EXISTS (SELECT 1 FROM microactions mine WHERE mine.msgid = mg.msgid AND mine.userid = ? AND mine.actiontype = ?) "+
			"AND (SELECT COUNT(*) FROM microactions o WHERE o.msgid = mg.msgid AND o.actiontype = ? AND o.userid <> ?) >= ?",
			groupIDs, "Approved", userID, userID, ChallengeCheckMessage, ChallengeCheckMessage, userID, ApprovalQuorum).
		Group("mg.msgid").
		Order("MAX(mg.arrival) DESC").
		Limit(25).
		Scan(&candidates)

	for _, msgid := range candidates {
		if SettledVerdict(db, msgid, userID) != "" {
			id := msgid
			return &Challenge{Type: ChallengeCheckMessage, Msgid: &id, Graded: true}
		}
	}
	return nil
}
