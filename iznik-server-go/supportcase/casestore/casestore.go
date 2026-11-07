// Package casestore holds the support case writes that both the referral (package chat) and the
// case API (package supportcase) make, so the two write them the same way. It imports neither, so
// either can use it without an import cycle.
package casestore

import (
	"errors"
	"fmt"

	"github.com/go-sql-driver/mysql"
	"gorm.io/gorm"
)

// TaskCaseMessage is the background task that mails the other side of a case about a new message.
const TaskCaseMessage = "support_case_message"

// Case is a new support_cases row. Create fills ID from the INSERT's own result, on the connection
// that ran it.
type Case struct {
	ID              uint64 `gorm:"primaryKey"`
	Chatid          uint64
	Userid          *uint64
	Referrerid      uint64
	Source          string
	Reason          *string
	Status          string
	FirstpassStatus string `gorm:"column:firstpass_status"`
	OpenKey         string `gorm:"column:open_key"`
}

type message struct {
	ID        uint64 `gorm:"primaryKey"`
	Caseid    uint64
	Userid    uint64
	Role      string
	Body      string
	Draftused string
}

// OpenKey is support_cases.open_key while a case is Open: one open case per chat and referrer.
func OpenKey(chatid, referrerid uint64) string {
	return fmt.Sprintf("%d-%d", chatid, referrerid)
}

// IsDuplicateKey reports a MySQL unique-key violation (error 1062).
func IsDuplicateKey(err error) bool {
	var me *mysql.MySQLError
	return errors.As(err, &me) && me.Number == 1062
}

// CreateCase inserts a case and returns its id.
func CreateCase(tx *gorm.DB, c *Case) (uint64, error) {
	if err := tx.Table("support_cases").Create(c).Error; err != nil {
		return 0, err
	}
	return c.ID, nil
}

// AddMessage inserts a message on a case and queues the mail that tells the other side about it.
// Call it inside a transaction so the message and its mail are written together or not at all.
func AddMessage(tx *gorm.DB, caseid, userid uint64, role, body, draftused string) (uint64, error) {
	if draftused == "" {
		draftused = "No"
	}
	m := message{Caseid: caseid, Userid: userid, Role: role, Body: body, Draftused: draftused}
	if err := tx.Table("support_case_messages").Create(&m).Error; err != nil {
		return 0, err
	}
	if err := tx.Table("background_tasks").Create(map[string]interface{}{
		"task_type": TaskCaseMessage,
		"data":      gorm.Expr("JSON_OBJECT('caseid', ?, 'messageid', ?)", caseid, m.ID),
	}).Error; err != nil {
		return 0, err
	}
	return m.ID, nil
}
