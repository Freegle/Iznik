package chat

import (
	"os"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Micro-volunteering as the reply gate.
//
// A member can reply to as many posts as they like, but the people who reply to everything
// are the ones most likely to be hoarding, reselling or not people at all. REPLY_GATE_AFTER=N
// makes the (N+1)th reply in a day wait until the member has answered a graded
// micro-volunteering task correctly (see microvolunteering.HasRecentGradedPass). Random
// button-pressing does not pass; a wrong answer keeps the gate shut. Nobody has to be there
// for it to work.

// ReplyGateAfter is how many replies a member may send in a day before the gate applies.
// Unset, or anything that isn't a valid non-negative number, defaults to 5. An explicit 0
// turns the gate off.
func ReplyGateAfter() int {
	v := strings.TrimSpace(os.Getenv("REPLY_GATE_AFTER"))
	if v == "" {
		return 5
	}
	n, err := strconv.Atoi(v)
	if err != nil || n < 0 {
		return 5
	}
	return n
}

// ReplyGateStatus is the HTTP status a refused reply gets. 428 Precondition Required: the
// client has something to do first, and it is not a permission problem.
const ReplyGateStatus = 428

// ReplyGateMessage is the error body a refused reply gets, so the client can tell this
// refusal apart from any other.
const ReplyGateMessage = "reply_gate"

// repliesToday counts the distinct posts the member has replied to in the last day. Reports
// also carry a refmsgid but live in User2Mod rooms, so the room type keeps them out.
func repliesToday(db *gorm.DB, userid uint64) int64 {
	var n int64
	db.Table("chat_messages").
		Joins("INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid").
		Where("chat_messages.userid = ? AND chat_messages.type = ? AND chat_messages.refmsgid IS NOT NULL "+
			"AND chat_rooms.chattype = ? AND chat_messages.date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
			userid, utils.CHAT_MESSAGE_INTERESTED, utils.CHAT_TYPE_USER2USER).
		Distinct("chat_messages.refmsgid").
		Count(&n)
	return n
}

// replyGateBlocks says whether this reply must wait for a graded pass.
func replyGateBlocks(db *gorm.DB, userid uint64) bool {
	after := ReplyGateAfter()
	if after == 0 {
		return false
	}
	if repliesToday(db, userid) < int64(after) {
		return false
	}
	return !microvolunteering.HasRecentGradedPass(db, userid)
}
