// Package supportcase serves the cases that come from a moderator referring a chat to Support: the
// list and the full first-pass view for Support and Admin, the narrow view for the referring
// moderator, and the thread of replies between them. Nothing here goes to the member.
//
// The two views are separate structs with explicit column lists on purpose. The referring moderator
// must never receive the first pass (brief), the member, or the handledby field, so those fields are
// not on the struct the moderator is served, rather than being blanked on a shared one. Messages
// still carry their author, so a Support volunteer's reply shows that volunteer's name and id, as it
// would between volunteers; only the case-level handledby is left out.
package supportcase

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/supportcase/casestore"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

const (
	maxBodyRunes = 10000
	maxMessages  = 500
	summaryChars = 200
)

// Person is a user as a case shows them.
type Person struct {
	ID          uint64 `json:"id"`
	Displayname string `json:"displayname"`
}

// Message is one entry in a case's thread.
type Message struct {
	ID          uint64    `json:"id"`
	Role        string    `json:"role"`
	Userid      *uint64   `json:"userid"`
	Displayname string    `json:"displayname"`
	Body        string    `json:"body"`
	Draftused   string    `json:"draftused"`
	Created     time.Time `json:"created"`
}

// Row is one case in a list.
type Row struct {
	ID             uint64    `json:"id"`
	Created        time.Time `json:"created"`
	Status         string    `json:"status"`
	Chatid         *uint64   `json:"chatid"`
	Member         *Person   `json:"member"`
	Referrer       *Person   `json:"referrer"`
	Classification *string   `json:"classification"`
	Confidence     *string   `json:"confidence"`
	Summary        *string   `json:"summary"`
	FirstpassState string    `json:"firstpass_status"`
	Messages       int       `json:"messages"`
	Lastactivity   time.Time `json:"lastactivity"`
}

// SupportView is the full case, for Support and Admin.
type SupportView struct {
	Viewer          string          `json:"viewer"`
	ID              uint64          `json:"id"`
	Created         time.Time       `json:"created"`
	Updated         time.Time       `json:"updated"`
	Status          string          `json:"status"`
	Source          string          `json:"source"`
	Chatid          *uint64         `json:"chatid"`
	Reason          *string         `json:"reason"`
	Member          *Person         `json:"member"`
	Referrer        *Person         `json:"referrer"`
	FirstpassStatus string          `json:"firstpass_status"`
	FirstpassError  *string         `json:"firstpass_error"`
	FirstpassRunid  *uint64         `json:"firstpass_runid"`
	Brief           json.RawMessage `json:"brief"`
	Handledby       *Person         `json:"handledby"`
	HandledAt       *time.Time      `json:"handled_at"`
	Messages        []Message       `json:"messages"`
}

// ReferrerView is all the referring moderator may see of their own case.
type ReferrerView struct {
	Viewer   string    `json:"viewer"`
	ID       uint64    `json:"id"`
	Created  time.Time `json:"created"`
	Status   string    `json:"status"`
	Chatid   *uint64   `json:"chatid"`
	Reason   *string   `json:"reason"`
	Messages []Message `json:"messages"`
}

// MessageRequest is the body of POST /supportcases/:id/messages.
type MessageRequest struct {
	Body      string `json:"body"`
	Draftused string `json:"draftused"`
}

// StatusRequest is the body of PATCH /supportcases.
type StatusRequest struct {
	ID     uint64 `json:"id"`
	Status string `json:"status"`
}

const nameSQL = "COALESCE(NULLIF(%s.fullname, ''), NULLIF(TRIM(CONCAT(COALESCE(%s.firstname, ''), ' ', COALESCE(%s.lastname, ''))), ''), '')"

func nameOf(alias string) string {
	return strings.ReplaceAll(nameSQL, "%s", alias)
}

func person(id *uint64, name string) *Person {
	if id == nil {
		return nil
	}
	return &Person{ID: *id, Displayname: name}
}

func notFound() error {
	return fiber.NewError(fiber.StatusNotFound, "Case not found")
}

// caller returns the logged-in user and whether they are Support or Admin.
func caller(c *fiber.Ctx) (uint64, bool, error) {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return 0, false, fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	return myid, auth.IsAdminOrSupport(myid), nil
}

func openKey(chatid *uint64, referrerid uint64) string {
	var cid uint64
	if chatid != nil {
		cid = *chatid
	}
	return casestore.OpenKey(cid, referrerid)
}

// caseRow is the columns the checks below need, whoever is asking.
type caseRow struct {
	ID         uint64
	Chatid     *uint64
	Referrerid *uint64
	Status     string
}

// loadCase reads a case on the primary. A moderator who is not Support or Admin may load only a case
// they referred, and only while they can still see its chat; anything else is not found.
func loadCase(id, myid uint64, support bool) (*caseRow, error) {
	q := database.DBConn.Clauses(dbresolver.Write).Table("support_cases").
		Select("id, chatid, referrerid, status").Where("id = ?", id)
	if !support {
		q = q.Where("referrerid = ?", myid)
	}
	var rows []caseRow
	if err := q.Limit(1).Scan(&rows).Error; err != nil {
		return nil, fiber.NewError(fiber.StatusInternalServerError, "Failed to load case")
	}
	if len(rows) == 0 {
		return nil, notFound()
	}
	cr := &rows[0]

	if !support {
		if cr.Chatid == nil {
			return nil, notFound()
		}
		var room struct {
			User1   uint64
			User2   uint64
			Groupid uint64
		}
		res := database.DBConn.Clauses(dbresolver.Write).Table("chat_rooms").
			Select("COALESCE(user1, 0) AS user1, COALESCE(user2, 0) AS user2, COALESCE(groupid, 0) AS groupid").
			Where("id = ?", *cr.Chatid).Scan(&room)
		if res.Error != nil {
			return nil, fiber.NewError(fiber.StatusInternalServerError, "Failed to load case")
		}
		if res.RowsAffected == 0 || !chat.CanSeeChatRoom(myid, room.User1, room.User2, room.Groupid) {
			return nil, notFound()
		}
	}
	return cr, nil
}

func loadMessages(caseid uint64, forReferrer bool) ([]Message, error) {
	msgs := []Message{}
	err := database.DBConn.Clauses(dbresolver.Write).Table("support_case_messages m").
		Select("m.id, m.role, m.userid, "+nameOf("u")+" AS displayname, m.body, m.draftused, m.created_at AS created").
		Joins("LEFT JOIN users u ON u.id = m.userid").
		Where("m.caseid = ?", caseid).Order("m.id ASC").Limit(maxMessages).Scan(&msgs).Error
	if err != nil {
		return nil, fiber.NewError(fiber.StatusInternalServerError, "Failed to load messages")
	}
	if forReferrer {
		// Whether Support used a first-pass draft is first-pass information.
		for i := range msgs {
			msgs[i].Draftused = "No"
		}
	}
	return msgs, nil
}

type listScan struct {
	ID             uint64
	Created        time.Time
	Status         string
	Chatid         *uint64
	Userid         *uint64
	Membername     string
	Referrerid     *uint64
	Referrername   string
	Classification *string
	Confidence     *string
	Summary        *string
	FirstpassState string
	Messages       int
	Lastactivity   time.Time
}

// List returns cases newest first, for Support and Admin.
//
// @Summary List support cases
// @Tags supportcase
// @Router /supportcases [get]
func List(c *fiber.Ctx) error {
	myid, support, err := caller(c)
	if err != nil {
		return err
	}
	_ = myid
	if !support {
		return notFound()
	}

	limit, _ := strconv.Atoi(c.Query("limit", "50"))
	if limit < 1 || limit > 100 {
		limit = 50
	}

	chatid, _ := strconv.ParseUint(c.Query("chatid"), 10, 64)
	status := c.Query("status")
	if status == "" {
		if chatid > 0 {
			status = "All"
		} else {
			status = "Open"
		}
	}

	q := database.DBConn.Clauses(dbresolver.Write).Table("support_cases c").
		Select("c.id, c.created_at AS created, c.status, c.chatid, c.userid, " + nameOf("mu") + " AS membername, " +
			"c.referrerid, " + nameOf("ru") + " AS referrername, " +
			"IF(JSON_VALID(c.brief), JSON_UNQUOTE(JSON_EXTRACT(c.brief, '$.classification')), NULL) AS classification, " +
			"IF(JSON_VALID(c.brief), JSON_UNQUOTE(JSON_EXTRACT(c.brief, '$.confidence.level')), NULL) AS confidence, " +
			fmt.Sprintf("IF(JSON_VALID(c.brief), LEFT(JSON_UNQUOTE(JSON_EXTRACT(c.brief, '$.question')), %d), NULL) AS summary, ", summaryChars) +
			"c.firstpass_status AS firstpass_state, " +
			"(SELECT COUNT(*) FROM support_case_messages sm WHERE sm.caseid = c.id) AS messages, " +
			"COALESCE((SELECT MAX(sm2.created_at) FROM support_case_messages sm2 WHERE sm2.caseid = c.id), c.updated_at) AS lastactivity").
		Joins("LEFT JOIN users mu ON mu.id = c.userid").
		Joins("LEFT JOIN users ru ON ru.id = c.referrerid")

	switch status {
	case "Open", "Handled":
		q = q.Where("c.status = ?", status)
	case "All":
	default:
		return fiber.NewError(fiber.StatusBadRequest, "status must be Open, Handled or All")
	}
	if chatid > 0 {
		q = q.Where("c.chatid = ?", chatid)
	}
	if before, _ := strconv.ParseUint(c.Query("before"), 10, 64); before > 0 {
		q = q.Where("c.id < ?", before)
	}

	var scanned []listScan
	if err := q.Order("c.id DESC").Limit(limit).Scan(&scanned).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to list cases")
	}

	rows := make([]Row, 0, len(scanned))
	for _, s := range scanned {
		rows = append(rows, Row{
			ID: s.ID, Created: s.Created, Status: s.Status, Chatid: s.Chatid,
			Member:         person(s.Userid, s.Membername),
			Referrer:       person(s.Referrerid, s.Referrername),
			Classification: s.Classification, Confidence: s.Confidence, Summary: s.Summary,
			FirstpassState: s.FirstpassState, Messages: s.Messages, Lastactivity: s.Lastactivity,
		})
	}
	return c.JSON(rows)
}

type fullScan struct {
	ID              uint64
	Created         time.Time
	Updated         time.Time
	Status          string
	Source          string
	Chatid          *uint64
	Reason          *string
	Userid          *uint64
	Membername      string
	Referrerid      *uint64
	Referrername    string
	FirstpassStatus string
	FirstpassError  *string
	FirstpassRunid  *uint64
	Brief           *string
	Handledbyid     *uint64
	Handledbyname   string
	HandledAt       *time.Time
}

// Get returns one case. Support and Admin get the full case; the moderator who referred it gets only
// their own reason, its status and the thread; anyone else gets a 404.
//
// @Summary Get a support case
// @Tags supportcase
// @Router /supportcases/{id} [get]
func Get(c *fiber.Ctx) error {
	myid, support, err := caller(c)
	if err != nil {
		return err
	}
	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return notFound()
	}

	cr, err := loadCase(id, myid, support)
	if err != nil {
		return err
	}

	if !support {
		var rows []struct {
			Created time.Time
			Reason  *string
		}
		if err := database.DBConn.Clauses(dbresolver.Write).Table("support_cases").
			Select("created_at AS created, reason").Where("id = ?", cr.ID).Limit(1).Scan(&rows).Error; err != nil || len(rows) == 0 {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to load case")
		}
		msgs, err := loadMessages(cr.ID, true)
		if err != nil {
			return err
		}
		return c.JSON(ReferrerView{
			Viewer: "Referrer", ID: cr.ID, Created: rows[0].Created, Status: cr.Status,
			Chatid: cr.Chatid, Reason: rows[0].Reason, Messages: msgs,
		})
	}

	var rows []fullScan
	if err := database.DBConn.Clauses(dbresolver.Write).Table("support_cases c").
		Select("c.id, c.created_at AS created, c.updated_at AS updated, c.status, c.source, c.chatid, c.reason, "+
			"c.userid, "+nameOf("mu")+" AS membername, c.referrerid, "+nameOf("ru")+" AS referrername, "+
			"c.firstpass_status, c.firstpass_error, c.firstpass_runid, c.brief, "+
			"c.handledby AS handledbyid, "+nameOf("hu")+" AS handledbyname, c.handled_at").
		Joins("LEFT JOIN users mu ON mu.id = c.userid").
		Joins("LEFT JOIN users ru ON ru.id = c.referrerid").
		Joins("LEFT JOIN users hu ON hu.id = c.handledby").
		Where("c.id = ?", cr.ID).Limit(1).Scan(&rows).Error; err != nil || len(rows) == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to load case")
	}
	r := rows[0]

	msgs, err := loadMessages(cr.ID, false)
	if err != nil {
		return err
	}

	var brief json.RawMessage
	if r.Brief != nil && json.Valid([]byte(*r.Brief)) {
		brief = json.RawMessage(*r.Brief)
	}

	return c.JSON(SupportView{
		Viewer: "Support", ID: r.ID, Created: r.Created, Updated: r.Updated, Status: r.Status,
		Source: r.Source, Chatid: r.Chatid, Reason: r.Reason,
		Member: person(r.Userid, r.Membername), Referrer: person(r.Referrerid, r.Referrername),
		FirstpassStatus: r.FirstpassStatus, FirstpassError: r.FirstpassError, FirstpassRunid: r.FirstpassRunid,
		Brief: brief, Handledby: person(r.Handledbyid, r.Handledbyname), HandledAt: r.HandledAt,
		Messages: msgs,
	})
}

// maxMessagesPerHour caps how many messages one author can add to one case in an hour; each mails the
// other side.
const maxMessagesPerHour = 10

// PostMessage adds to the thread. The author is a Referrer when they referred the case, even if they
// are also Support; otherwise Support. A referrer posting to a Handled case reopens it.
//
// @Summary Reply on a support case
// @Tags supportcase
// @Router /supportcases/{id}/messages [post]
func PostMessage(c *fiber.Ctx) error {
	myid, support, err := caller(c)
	if err != nil {
		return err
	}
	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return notFound()
	}

	cr, err := loadCase(id, myid, support)
	if err != nil {
		return err
	}

	var req MessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	body := strings.TrimSpace(req.Body)
	if body == "" {
		return fiber.NewError(fiber.StatusBadRequest, "body is required")
	}
	if utf8.RuneCountInString(body) > maxBodyRunes {
		return fiber.NewError(fiber.StatusBadRequest, "body is too long")
	}

	role := "Support"
	if cr.Referrerid != nil && *cr.Referrerid == myid {
		role = "Referrer"
	}
	draftused := "No"
	switch req.Draftused {
	case "", "No":
	case "Unchanged", "Edited":
		if role == "Support" {
			draftused = req.Draftused
		}
	default:
		return fiber.NewError(fiber.StatusBadRequest, "draftused must be No, Unchanged or Edited")
	}

	var recent int64
	if err := database.DBConn.Clauses(dbresolver.Write).Table("support_case_messages").
		Where("caseid = ? AND userid = ? AND created_at > NOW() - INTERVAL 1 HOUR", cr.ID, myid).
		Count(&recent).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to post message")
	}
	if recent >= maxMessagesPerHour {
		return fiber.NewError(fiber.StatusTooManyRequests, "Too many replies, please wait a little")
	}

	var msgid uint64
	err = database.DBConn.Transaction(func(tx *gorm.DB) error {
		if role == "Referrer" && cr.Status == "Handled" {
			res := tx.Table("support_cases").Where("id = ? AND status = 'Handled'", cr.ID).Updates(map[string]interface{}{
				"status":     "Open",
				"open_key":   openKey(cr.Chatid, myid),
				"handledby":  nil,
				"handled_at": nil,
			})
			if res.Error != nil {
				return res.Error
			}
		}
		var err error
		msgid, err = casestore.AddMessage(tx, cr.ID, myid, role, body, draftused)
		return err
	})
	if err != nil {
		if casestore.IsDuplicateKey(err) {
			return fiber.NewError(fiber.StatusConflict, "Another case for this chat is already open")
		}
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to post message")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": msgid})
}

// Patch marks a case Handled or reopens it. Support and Admin only.
//
// @Summary Mark a support case handled or open
// @Tags supportcase
// @Router /supportcases [patch]
func Patch(c *fiber.Ctx) error {
	myid, support, err := caller(c)
	if err != nil {
		return err
	}
	if !support {
		return notFound()
	}

	var req StatusRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}
	if req.Status != "Open" && req.Status != "Handled" {
		return fiber.NewError(fiber.StatusBadRequest, "status must be Open or Handled")
	}

	cr, err := loadCase(req.ID, myid, true)
	if err != nil {
		return err
	}
	if cr.Status == req.Status {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	updates := map[string]interface{}{"status": req.Status}
	if req.Status == "Handled" {
		updates["open_key"] = nil
		updates["handledby"] = myid
		updates["handled_at"] = gorm.Expr("NOW()")
	} else {
		if cr.Referrerid == nil {
			updates["open_key"] = nil
		} else {
			updates["open_key"] = openKey(cr.Chatid, *cr.Referrerid)
		}
		updates["handledby"] = nil
		updates["handled_at"] = nil
	}

	if err := database.DBConn.Table("support_cases").Where("id = ?", cr.ID).Updates(updates).Error; err != nil {
		if casestore.IsDuplicateKey(err) {
			return fiber.NewError(fiber.StatusConflict, "Another case for this chat is already open")
		}
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to update case")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
