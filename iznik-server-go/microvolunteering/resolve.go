package microvolunteering

import (
	"os"
	"strings"

	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Experiment: reports resolved by the system.
//
// Today two member reports pull a post back to Pending for a moderator, and with nobody
// there the 48-hour auto-approve puts it back up. Nobody tells the poster or the
// reporters anything. REPORTS_RESOLVE=1 makes the quorum final: the post is taken down,
// the poster is told which post, why, and that they can fix it and post again, and each
// reporter is told the outcome. A person can still restore the post from the logs.
//
// Off unless switched on. This is a thought experiment, not the shipped behaviour.

func ReportsResolve() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("REPORTS_RESOLVE")))
	return v == "1" || v == "true" || v == "yes" || v == "on"
}

// SystemUserEmail is the account the system speaks as, the same one the first-reply
// engine uses (firstreply.SystemUserID); looked up here rather than imported, to keep
// the chat package's import graph a tree.
func SystemUserEmail() string {
	email := os.Getenv("FIRSTREPLY_SYSTEM_USER_EMAIL")
	if email == "" {
		email = "freegle@ilovefreegle.org"
	}
	return email
}

func systemUserID(db *gorm.DB) uint64 {
	var id uint64
	db.Table("users_emails").Select("userid").Where("email = ?", SystemUserEmail()).Limit(1).Scan(&id)
	return id
}

// ResolveReports takes the post down and tells everyone involved. Idempotent: a post
// already taken down is left alone, so a third report sends nothing twice.
func ResolveReports(db *gorm.DB, msgid uint64) {
	if msgid == 0 {
		return
	}

	var post struct {
		Fromuser uint64
		Subject  string
		Deleted  *string
	}
	db.Table("messages").Select("COALESCE(fromuser, 0) AS fromuser, subject, deleted").Where("id = ?", msgid).Scan(&post)
	if post.Fromuser == 0 || post.Deleted != nil {
		return
	}

	// The origin community is the routing label for the notices; a member never sees it.
	var groupid uint64
	db.Table("messages_groups").Select("groupid").Where("msgid = ?", msgid).
		Order("rippled_in ASC, groupid ASC").Limit(1).Scan(&groupid)
	if groupid == 0 {
		return
	}

	// Reporters first, so the takedown cannot race a second resolve into telling nobody.
	var reporters []uint64
	db.Table("microactions").Select("DISTINCT userid").
		Where("msgid = ? AND actiontype = ? AND result = ? AND userid <> ?", msgid, ChallengeCheckMessage, "Reject", post.Fromuser).
		Scan(&reporters)

	db.Table("messages").Where("id = ?", msgid).Update("deleted", gorm.Expr("NOW()"))
	db.Table("messages_groups").Where("msgid = ?", msgid).Update("deleted", gorm.Expr("1"))
	FreezeReachIfOriginPending(db, msgid)

	text := "Taken down after member reports."
	g := groupid
	fromuser := post.Fromuser
	flog.Log(flog.LogEntry{
		Type:    flog.LOG_TYPE_MESSAGE,
		Subtype: flog.LOG_SUBTYPE_DELETED,
		Groupid: &g,
		User:    &fromuser,
		Msgid:   &msgid,
		Text:    &text,
	})

	sys := systemUserID(db)
	if sys == 0 {
		return
	}

	subject := post.Subject
	if subject == "" {
		subject = "your post"
	}

	systemMessage(db, sys, post.Fromuser, groupid,
		"Your post \""+subject+"\" was reported by several freeglers and has been taken down. "+
			"Have a look at Freegle's rules at /rules. If you can fix it, or you think this is wrong, please post it again.")

	for _, reporter := range reporters {
		systemMessage(db, sys, reporter, groupid,
			"Thanks for reporting \""+subject+"\". Enough people agreed with you that it has been taken down.")
	}
}

// systemMessage tells a member something as Freegle, using the existing volunteers chat
// so the app, the email and the push all already know how to show it.
func systemMessage(db *gorm.DB, sys uint64, userid uint64, groupid uint64, text string) {
	var roomid uint64
	db.Table("chat_rooms").Select("id").
		Where("user1 = ? AND groupid = ? AND chattype = ?", userid, groupid, utils.CHAT_TYPE_USER2MOD).
		Limit(1).Scan(&roomid)
	if roomid == 0 {
		res := gorm.WithResult()
		if err := db.Table("chat_rooms").Clauses(res).Create(map[string]interface{}{
			"chattype":      utils.CHAT_TYPE_USER2MOD,
			"user1":         userid,
			"groupid":       groupid,
			"latestmessage": gorm.Expr("NOW()"),
		}).Error; err != nil || res.Result == nil {
			return
		}
		id, _ := res.Result.LastInsertId()
		roomid = uint64(id)
		db.Table("chat_roster").Create(map[string]interface{}{
			"chatid": roomid,
			"userid": userid,
			"status": utils.CHAT_STATUS_OFFLINE,
		})
	}

	db.Table("chat_messages").Create(map[string]interface{}{
		"chatid":               roomid,
		"userid":               sys,
		"type":                 utils.CHAT_MESSAGE_MODMAIL,
		"message":              text,
		"date":                 gorm.Expr("NOW()"),
		"reviewrequired":       0,
		"reviewrejected":       0,
		"processingrequired":   0,
		"processingsuccessful": 1,
	})
	db.Table("chat_rooms").Where("id = ?", roomid).Update("latestmessage", gorm.Expr("NOW()"))
}
