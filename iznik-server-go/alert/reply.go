package alert

import (
	"encoding/base64"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// The request body is capped at 4MB by fiber and the files travel as base64 in it, so the files
// together stay under 2.5MB.
const (
	maxAttachments    = 5
	maxAttachmentsLen = 2500000
	maxQuotedBytes    = 200000
	maxReplyLen       = 5000

	// A required reply stops blocking after this long, so nobody is held up for ever by an alert
	// nobody remembers to cancel.
	replyWindowDays = 14
	snoozeMinutes   = 30
)

var allowedAttachmentMimes = map[string]bool{
	"image/png":       true,
	"image/jpeg":      true,
	"image/gif":       true,
	"image/webp":      true,
	"application/pdf": true,
}

var unsafeNameRE = regexp.MustCompile(`[^A-Za-z0-9._ -]`)

// AttachmentUpload is one file sent with a message to a community's volunteers, as base64.
type AttachmentUpload struct {
	Name string `json:"name"`
	Mime string `json:"mime"`
	Data string `json:"data"`
}

type attachmentFile struct {
	name string
	mime string
	data []byte
}

func validateAttachments(in []AttachmentUpload) ([]attachmentFile, error) {
	if len(in) > maxAttachments {
		return nil, fiber.NewError(fiber.StatusBadRequest, "At most 5 attachments")
	}

	out := make([]attachmentFile, 0, len(in))
	total := 0
	for _, a := range in {
		mime := strings.ToLower(strings.TrimSpace(a.Mime))
		if !allowedAttachmentMimes[mime] {
			return nil, fiber.NewError(fiber.StatusBadRequest, "Attachments must be images or PDFs")
		}
		data, err := base64.StdEncoding.DecodeString(a.Data)
		if err != nil || len(data) == 0 {
			return nil, fiber.NewError(fiber.StatusBadRequest, "Invalid attachment")
		}
		total += len(data)
		if total > maxAttachmentsLen {
			return nil, fiber.NewError(fiber.StatusBadRequest, "Attachments are too big: 2.5MB in total")
		}
		name := unsafeNameRE.ReplaceAllString(filepath.Base(a.Name), "_")
		if name == "" || name == "." || name == "_" {
			name = "attachment"
		}
		if len(name) > 100 {
			name = name[len(name)-100:]
		}
		out = append(out, attachmentFile{name: name, mime: mime, data: data})
	}
	return out, nil
}

// PendingAlert is an alert a moderator has been told to reply to.
type PendingAlert struct {
	ID              uint64 `json:"id"`
	Groupid         uint64 `json:"groupid"`
	Groupname       string `json:"groupname"`
	Subject         string `json:"subject"`
	Text            string `json:"text"`
	Html            string `json:"html"`
	Quoted          string `json:"quoted"`
	Createdby       uint64 `json:"createdby"`
	Created         string `json:"created"`
	Snoozeavailable bool   `json:"snoozeavailable"`
}

// pendingFor returns the alerts a user must still reply to: they moderate the community, the alert
// requires a reply, it is neither cancelled nor older than the window, they did not send it, they have
// not replied and are not currently snoozed.
func pendingFor(db *gorm.DB, myid uint64) []PendingAlert {
	out := []PendingAlert{}
	db.Table("alerts a").
		Select("a.id, a.groupid, COALESCE(NULLIF(g.namefull, ''), g.nameshort) AS groupname, a.subject, a.text, a.html, "+
			"COALESCE(a.quoted, '') AS quoted, COALESCE(a.createdby, 0) AS createdby, a.created, "+
			"(ack.id IS NULL OR ack.snooze_used = 0) AS snoozeavailable").
		Joins("INNER JOIN `groups` g ON g.id = a.groupid").
		Joins("INNER JOIN memberships m ON m.groupid = a.groupid AND m.userid = ? AND m.role IN (?, ?)",
			myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Joins("LEFT JOIN alerts_acknowledgements ack ON ack.alertid = a.id AND ack.userid = ?", myid).
		Where("a.requirereply = 1 AND a.cancelled IS NULL AND a.created > NOW() - INTERVAL ? DAY", replyWindowDays).
		Where("(a.createdby IS NULL OR a.createdby <> ?)", myid).
		Where("(ack.id IS NULL OR (ack.replied IS NULL AND (ack.snoozed_until IS NULL OR ack.snoozed_until <= NOW())))").
		Order("a.id").
		Scan(&out)
	return out
}

func isPending(db *gorm.DB, myid, alertid uint64) *PendingAlert {
	for _, p := range pendingFor(db, myid) {
		if p.ID == alertid {
			pp := p
			return &pp
		}
	}
	return nil
}

// GetPending handles GET /modtools/alert/pending - the alerts the caller must reply to before
// ModTools lets them carry on. An empty list for anyone who is not a moderator.
//
// @Summary Alerts awaiting my reply
// @Tags alert
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Not logged in"
// @Router /api/modtools/alert/pending [get]
func GetPending(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "alerts": pendingFor(database.DBConn, myid)})
}

func alertIDParam(c *fiber.Ctx) (uint64, error) {
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return 0, fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}
	return id, nil
}

// ReplyToAlert handles POST /modtools/alert/:id/reply - sends the reply to whoever sent the alert, as
// a chat message, and records that the caller has replied.
//
// @Summary Reply to an alert that requires a reply
// @Tags alert
// @Accept json
// @Produce json
// @Security BearerAuth
// @Param id path integer true "Alert ID"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error "Not logged in"
// @Failure 404 {object} fiber.Error "Nothing to reply to"
// @Router /api/modtools/alert/{id}/reply [post]
func ReplyToAlert(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	id, err := alertIDParam(c)
	if err != nil {
		return err
	}

	var req struct {
		Message string `json:"message"`
	}
	if c.BodyParser(&req) != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	msg := strings.TrimSpace(req.Message)
	if msg == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Please write a reply")
	}
	if len(msg) > maxReplyLen {
		return fiber.NewError(fiber.StatusBadRequest, "That reply is too long")
	}

	db := database.DBConn
	p := isPending(db, myid, id)
	if p == nil {
		return fiber.NewError(fiber.StatusNotFound, "There is nothing for you to reply to")
	}

	chatid, cerr := chat.GetOrCreateUser2UserChat(db, myid, p.Createdby)
	if cerr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not start the chat")
	}

	body := "Reply to your message to the " + p.Groupname + " volunteers, \"" + p.Subject + "\":\n\n" + msg
	if err := db.Table("chat_messages").Create(map[string]interface{}{
		"chatid":             chatid,
		"userid":             myid,
		"type":               utils.CHAT_MESSAGE_DEFAULT,
		"date":               time.Now(),
		"message":            body,
		"processingrequired": gorm.Expr("1"),
	}).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not send the reply")
	}
	db.Table("chat_rooms").Where("id = ?", chatid).Update("latestmessage", gorm.Expr("NOW()"))

	db.Table("alerts_acknowledgements").Clauses(clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "replied"}, Value: gorm.Expr("NOW()")},
			{Column: clause.Column{Name: "reply"}, Value: msg},
		},
	}).Create(map[string]interface{}{
		"alertid": id, "userid": myid, "replied": gorm.Expr("NOW()"), "reply": msg,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "chatid": chatid})
}

// SnoozeAlert handles POST /modtools/alert/:id/snooze - one 30 minute break per moderator per alert.
//
// @Summary Sleep an alert for 30 minutes, once
// @Tags alert
// @Produce json
// @Security BearerAuth
// @Param id path integer true "Alert ID"
// @Success 200 {object} map[string]interface{}
// @Failure 403 {object} fiber.Error "Already used"
// @Router /api/modtools/alert/{id}/snooze [post]
func SnoozeAlert(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	id, err := alertIDParam(c)
	if err != nil {
		return err
	}

	db := database.DBConn
	p := isPending(db, myid, id)
	if p == nil {
		return fiber.NewError(fiber.StatusNotFound, "There is nothing for you to snooze")
	}
	if !p.Snoozeavailable {
		return fiber.NewError(fiber.StatusForbidden, "You have already used your 30 minutes for this message")
	}

	// The IF makes the one-time rule hold when two tabs press the button together.
	until := gorm.Expr("NOW() + INTERVAL " + strconv.Itoa(snoozeMinutes) + " MINUTE")
	db.Table("alerts_acknowledgements").Clauses(clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "snoozed_until"}, Value: gorm.Expr("IF(snooze_used = 0, VALUES(snoozed_until), snoozed_until)")},
			{Column: clause.Column{Name: "snooze_used"}, Value: 1},
		},
	}).Create(map[string]interface{}{
		"alertid": id, "userid": myid, "snoozed_until": until, "snooze_used": 1,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "minutes": snoozeMinutes})
}

// CancelAlert handles POST /modtools/alert/:id/cancel - the sender, or Support/Admin, releases every
// moderator from a required reply.
//
// @Summary Stop requiring replies to an alert
// @Tags alert
// @Produce json
// @Security BearerAuth
// @Param id path integer true "Alert ID"
// @Success 200 {object} map[string]interface{}
// @Failure 403 {object} fiber.Error "Not authorized"
// @Router /api/modtools/alert/{id}/cancel [post]
func CancelAlert(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	id, err := alertIDParam(c)
	if err != nil {
		return err
	}

	db := database.DBConn
	var createdby uint64
	db.Table("alerts").Select("COALESCE(createdby, 0)").Where("id = ?", id).Row().Scan(&createdby)
	if createdby != myid && !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	db.Table("alerts").Where("id = ? AND cancelled IS NULL", id).Update("cancelled", gorm.Expr("NOW()"))
	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
