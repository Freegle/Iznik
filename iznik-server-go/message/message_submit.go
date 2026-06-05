package message

import (
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// SubmitMessage creates a complete, ready-to-go post in a SINGLE call.
//
// This replaces the old multi-step compose dance (PUT /message to create a
// draft → POST /image per attachment → POST /message action=JoinAndPost to
// submit), which forced the client to invent temporary message ids and fake
// attachment ids and to keep draft/submit state in sync — the source of a long
// run of compose bugs (Discourse #9656 etc., sparse-array crashes, string ids
// sent to a []uint64 field, AI illustrations dropped on the repost path).
//
// The old endpoints are deliberately left in place for backwards compatibility
// (edit/repost still use them); this is purely additive.
//
// One request carries everything: the item, the body, the location, the group,
// the options, and the attachments INLINE (by Cloudflare externaluid — the
// server inserts the messages_attachments row with msgid already set, so there
// are no NULL-then-link round-trips and no client-side attachment ids at all).
// For an unauthenticated user an email find-or-creates the account and a JWT is
// returned, so the same single call works logged-in or logged-out.
//
// The message always lands Pending; the content-check batch promotes clean
// posts from non-moderated members to Approved (and only then is it added to
// messages_spatial). This matches handleJoinAndPost exactly.
func SubmitMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	type submitAttachment struct {
		Externaluid  string          `json:"externaluid"`
		Externalmods json.RawMessage `json:"externalmods"`
	}
	type SubmitRequest struct {
		Type             string             `json:"type"`
		Messagetype      string             `json:"messagetype"` // client alias for Type
		Item             string             `json:"item"`
		Textbody         string             `json:"textbody"`
		Groupid          uint64             `json:"groupid"`
		Locationid       *uint64            `json:"locationid"`
		Availablenow     *int               `json:"availablenow"`
		Deadline         *string            `json:"deadline"`
		Deliverypossible *bool              `json:"deliverypossible"`
		Email            string             `json:"email"`
		Attachments      []submitAttachment `json:"attachments"`
	}

	var req SubmitRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}
	if req.Type == "" && req.Messagetype != "" {
		req.Type = req.Messagetype
	}
	if req.Type != "Offer" && req.Type != "Wanted" {
		return fiber.NewError(fiber.StatusBadRequest, "type must be Offer or Wanted")
	}
	if strings.TrimSpace(req.Item) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Item is required")
	}
	if req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	db := database.DBConn

	// Unauthenticated: an email find-or-creates the user and yields a JWT so the
	// single call works for logged-out posters too.
	var jwtString string
	var persistent fiber.Map
	if myid == 0 && req.Email != "" {
		var err error
		myid, jwtString, persistent, err = findOrCreateUserForDraft(db, req.Email)
		if err != nil {
			if strings.Contains(err.Error(), "invalid email") {
				return fiber.NewError(fiber.StatusBadRequest, "Invalid email address")
			}
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
		}
	}
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Banned from the target group?
	var bannedCount int64
	db.Raw("SELECT COUNT(*) FROM users_banned WHERE userid = ? AND groupid = ?", myid, req.Groupid).Scan(&bannedCount)
	if bannedCount > 0 {
		return fiber.NewError(fiber.StatusForbidden, "You are banned from this group")
	}

	// Auto-join the group (V1 parity with JoinAndPost), logging a new membership.
	joinRes := db.Exec("INSERT IGNORE INTO memberships (userid, groupid, role, collection) VALUES (?, ?, ?, ?)",
		myid, req.Groupid, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED)
	if joinRes.RowsAffected > 0 {
		db.Exec("INSERT INTO logs (timestamp, type, subtype, groupid, user, byuser) VALUES (NOW(), ?, ?, ?, ?, ?)",
			flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED, req.Groupid, myid, myid)
	}

	// Prohibited posters cannot post here.
	var ourPostingStatus *string
	db.Raw("SELECT ourPostingStatus FROM memberships WHERE userid = ? AND groupid = ?", myid, req.Groupid).Scan(&ourPostingStatus)
	if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
		return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post on this group")
	}

	availNow := 1
	if req.Availablenow != nil {
		availNow = *req.Availablenow
	}

	// Create the message row. Subject is rebuilt below once we have the location.
	fromip := c.IP()
	messageid := fmt.Sprintf("%.6f@%s-%d", float64(time.Now().UnixNano())/1e9, utils.USER_DOMAIN, req.Groupid)
	initialSubject := req.Type + ": " + req.Item
	if r := db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source, availableinitially, availablenow, locationid, fromip, messageid) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'Platform', ?, ?, ?, ?, ?)",
		myid, req.Type, initialSubject, req.Textbody, req.Textbody, availNow, availNow, req.Locationid, fromip, messageid); r.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create message")
	}
	var msgid uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", myid).Scan(&msgid)
	if msgid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to retrieve message ID")
	}

	// Attachments INLINE: insert each with msgid already set. No fake ids, no
	// NULL-then-UPDATE. First attachment is primary (drives the thumbnail).
	for i, att := range req.Attachments {
		if strings.TrimSpace(att.Externaluid) == "" {
			continue
		}
		var mods interface{}
		if len(att.Externalmods) > 0 {
			mods = string(att.Externalmods)
		}
		isPrimary := 0
		if i == 0 {
			isPrimary = 1
		}
		db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, ?)",
			msgid, att.Externaluid, mods, isPrimary)
	}

	// Item + messages_items.
	db.Exec("INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", req.Item)
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ? LIMIT 1", req.Item).Scan(&itemID)
	if itemID > 0 {
		db.Exec("INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?)", msgid, itemID)
	}

	// Location → lat/lng + user's last location, then rebuild the subject as
	// "KEYWORD: Item (Area PC)" using the group's keyword.
	if req.Locationid != nil && *req.Locationid > 0 {
		db.Exec("UPDATE users SET lastlocation = ? WHERE id = ?", *req.Locationid, myid)
		var lat, lng float64
		db.Raw("SELECT lat, lng FROM locations WHERE id = ?", *req.Locationid).Row().Scan(&lat, &lng)
		if lat != 0 || lng != 0 {
			db.Exec("UPDATE messages SET lat = ?, lng = ? WHERE id = ?", lat, lng, msgid)
		}
	}
	locStr := constructLocationString(db, msgid)
	keyword := getGroupKeyword(db, req.Groupid, req.Type)
	subject := keyword + ": " + req.Item
	if locStr != "" {
		subject = keyword + ": " + req.Item + " (" + locStr + ")"
	}
	db.Exec("UPDATE messages SET subject = ?, suggestedsubject = ? WHERE id = ?", subject, subject, msgid)

	// Options.
	if req.Deadline != nil && *req.Deadline != "" {
		db.Exec("UPDATE messages SET deadline = ? WHERE id = ?", *req.Deadline, msgid)
	}
	if req.Deliverypossible != nil {
		db.Exec("UPDATE messages SET deliverypossible = ? WHERE id = ?", *req.Deliverypossible, msgid)
	}

	// Post it: Pending in the group, posting + history records, internal fromaddr.
	db.Exec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, NOW())",
		msgid, req.Groupid, utils.COLLECTION_PENDING)
	db.Exec("INSERT INTO messages_postings (msgid, groupid) VALUES (?, ?)", msgid, req.Groupid)

	fromaddr := user.GetOrCreateInternalEmail(db, myid)
	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", fromaddr, msgid)
	var histFromname string
	db.Raw("SELECT COALESCE(fullname, '') FROM users WHERE id = ?", myid).Scan(&histFromname)
	db.Exec("INSERT IGNORE INTO messages_history (msgid, groupid, source, fromuser, fromname, fromaddr, subject, arrival, fromip) VALUES (?, ?, 'Platform', ?, ?, ?, ?, NOW(), ?)",
		msgid, req.Groupid, myid, histFromname, fromaddr, subject, fromip)

	logMessageReceived(db, req.Groupid, myid, msgid)

	// NOT added to messages_spatial — Pending posts are not in public browse.

	resp := fiber.Map{"ret": 0, "status": "Success", "id": msgid, "groupid": req.Groupid}
	if jwtString != "" {
		resp["jwt"] = jwtString
		resp["persistent"] = persistent
	}

	// New user without a password: generate one (so they can log in later).
	var hasPassword int64
	db.Raw("SELECT COUNT(*) FROM users_logins WHERE userid = ? AND type = ?", myid, utils.LOGIN_TYPE_NATIVE).Scan(&hasPassword)
	if hasPassword == 0 {
		password := utils.RandomHex(8)
		salt := auth.GetPasswordSalt()
		hashed := auth.HashPassword(password, salt)
		db.Exec("INSERT INTO users_logins (userid, type, uid, credentials, salt) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials = VALUES(credentials), salt = VALUES(salt)",
			myid, utils.LOGIN_TYPE_NATIVE, myid, hashed, salt)
		resp["newuser"] = true
		resp["newpassword"] = password
	}

	return c.JSON(resp)
}
