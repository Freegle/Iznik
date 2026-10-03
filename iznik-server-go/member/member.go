package member

import (
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// MemberSummary is a row in the /modtools/members list. It is deliberately
// lighter than the full user.User returned by GetMember - just enough for
// the ModTools home page lists (new/flagged/banned/search).
type MemberSummary struct {
	ID          uint64    `json:"id"`
	Displayname string    `json:"displayname"`
	Added       time.Time `json:"added"`
	Flagreason  *string   `json:"flagreason,omitempty"`
}

// displaynameExpr is the standard "look up another member's name by id"
// SQL expression, matching the precedent used repeatedly in dashboard.go
// and team.go. It deliberately doesn't use user.GetUserById's heavier,
// side-effecting logic (InventName etc) which is unsuitable for list rows.
const displaynameExpr = "COALESCE(fullname, firstname, lastname, 'Unknown')"

// ListMembers handles GET /modtools/members - national member lists for
// ModTools (replaces the old per-group pending/spam member lists).
//
// @Summary List members for ModTools
// @Tags member
// @Produce json
// @Param filter query string false "new|flagged|banned|search"
// @Param q query string false "Search term (filter=search only)"
// @Param since query integer false "Hours to look back (new/flagged only)"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/members [get]
func ListMembers(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	db := database.DBConn

	filter := c.Query("filter", "new")

	sinceHours := 24
	if s := c.Query("since"); s != "" {
		if v, err := strconv.Atoi(s); err == nil && v > 0 {
			sinceHours = v
		}
	}

	cutoff := time.Now().Add(-time.Duration(sinceHours) * time.Hour)

	q := db.Table("users").
		Select("id, " + displaynameExpr + " AS displayname, added, reviewreason AS flagreason")

	switch filter {
	case "flagged":
		q = q.Where("reviewrequestedat IS NOT NULL AND (reviewedat IS NULL OR reviewrequestedat > reviewedat) AND deleted IS NULL").
			Where("reviewrequestedat >= ?", cutoff).
			Order("reviewrequestedat DESC")
	case "banned":
		q = q.Where("banned IS NOT NULL AND deleted IS NULL").
			Order("banned DESC")
	case "search":
		term := c.Query("q")
		if term == "" {
			return fiber.NewError(fiber.StatusBadRequest, "q is required for filter=search")
		}
		like := "%" + term + "%"
		q = q.Where("deleted IS NULL AND (fullname LIKE ? OR firstname LIKE ? OR lastname LIKE ? OR email LIKE ?)", like, like, like, like).
			Order("added DESC")
	default:
		filter = "new"
		q = q.Where("added >= ? AND deleted IS NULL", cutoff).
			Order("added DESC")
	}

	var members []MemberSummary
	q.Limit(200).Scan(&members)

	if members == nil {
		members = make([]MemberSummary, 0)
	}

	// Ratings review (Thumbs Up/Down feedback) is a separate, not-yet-migrated
	// ModTools page (members/feedback.vue, still on the old groupid-based
	// contract) - the home page never reads this field. Return an empty,
	// contract-shaped array rather than block on that page's redesign.
	ratings := make([]interface{}, 0)

	return c.JSON(fiber.Map{
		"members": members,
		"ratings": ratings,
	})
}

// GetMember handles GET /modtools/members/:id - a single member's full
// details for the ModTools member card.
//
// @Summary Get a specific member
// @Tags member
// @Produce json
// @Param id path integer true "Member ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/members/{id} [get]
func GetMember(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	u := user.GetUserById(id, myid)

	if u.ID != id {
		return fiber.NewError(fiber.StatusNotFound, "Member not found")
	}

	return c.JSON(fiber.Map{
		"member": u,
	})
}

type banRequest struct {
	Reason string `json:"reason"`
}

// BanMember handles POST /modtools/members/:id/ban.
//
// @Summary Ban a member
// @Tags member
// @Produce json
// @Param id path integer true "Member ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/members/{id}/ban [post]
func BanMember(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	var req banRequest
	_ = c.BodyParser(&req)

	db := database.DBConn

	db.Table("users").Where("id = ?", id).
		Updates(map[string]interface{}{
			"banned":   gorm.Expr("NOW()"),
			"bannedby": myid,
		})

	reason := req.Reason
	log.Log(log.LogEntry{
		Type:    log.LOG_TYPE_USER,
		Subtype: log.LOG_SUBTYPE_BANNED,
		User:    &id,
		Byuser:  &myid,
		Text:    &reason,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// UnbanMember handles DELETE /modtools/members/:id/ban.
//
// @Summary Unban a member
// @Tags member
// @Produce json
// @Param id path integer true "Member ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/members/{id}/ban [delete]
func UnbanMember(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	db := database.DBConn

	db.Table("users").Where("id = ?", id).
		Updates(map[string]interface{}{
			"banned":   gorm.Expr("NULL"),
			"bannedby": gorm.Expr("NULL"),
		})

	log.Log(log.LogEntry{
		Type:    log.LOG_TYPE_USER,
		Subtype: log.LOG_SUBTYPE_UNBANNED,
		User:    &id,
		Byuser:  &myid,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

type patchMemberRequest struct {
	Postingstatus *string `json:"postingstatus"`
}

// PatchMember handles PATCH /modtools/members/:id.
//
// @Summary Update a member (posting status)
// @Tags member
// @Produce json
// @Param id path integer true "Member ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/members/{id} [patch]
func PatchMember(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	var req patchMemberRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request")
	}

	if req.Postingstatus == nil {
		return fiber.NewError(fiber.StatusBadRequest, "postingstatus is required")
	}

	switch *req.Postingstatus {
	case utils.POSTINGSTATUS_MODERATED, utils.POSTINGSTATUS_DEFAULT, utils.POSTINGSTATUS_PROHIBITED, utils.POSTINGSTATUS_UNMODERATED:
		// valid
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Invalid postingstatus")
	}

	db := database.DBConn

	db.Table("users").Where("id = ?", id).Update("postingstatus", *req.Postingstatus)

	log.Log(log.LogEntry{
		Type:    log.LOG_TYPE_USER,
		Subtype: log.LOG_SUBTYPE_OUR_POSTING_STATUS,
		User:    &id,
		Byuser:  &myid,
		Text:    req.Postingstatus,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// ClearFlag handles POST /modtools/members/:id/flag/clear - dismisses a
// member from the Flagged members list without banning them.
//
// @Summary Clear a member's flag
// @Tags member
// @Produce json
// @Param id path integer true "Member ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/members/{id}/flag/clear [post]
func ClearFlag(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	db := database.DBConn

	// Matches the spammembers predicate in session.go: a member drops out of
	// the flagged filter once reviewedat is not older than reviewrequestedat.
	db.Table("users").Where("id = ?", id).Update("reviewedat", gorm.Expr("NOW()"))

	log.Log(log.LogEntry{
		Type:    log.LOG_TYPE_USER,
		Subtype: log.LOG_SUBTYPE_FLAG_CLEARED,
		User:    &id,
		Byuser:  &myid,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
