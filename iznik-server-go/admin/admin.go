package admin

import (
	"encoding/json"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

type Admin struct {
	ID            uint64     `json:"id"`
	Createdby     *uint64    `json:"-"`
	// CreatedbyUser is what the API returns as "createdby": the creator's id and name, as V1's
	// Admin::getPublic did. ModAdmin reads createdby.displayname and createdby.id.
	CreatedbyUser *AdminUser `json:"createdby" gorm:"-"`
	Subject       *string    `json:"subject"`
	Text          *string    `json:"text"`
	CTA_Text      *string    `json:"ctatext"`
	CTA_Link      *string    `json:"ctalink"`
	Created       *time.Time `json:"created"`
	Complete      *time.Time `json:"complete"`
	Heldby        *uint64    `json:"heldby"`
	Heldat        *time.Time `json:"heldat"`
	Parentid      *uint64    `json:"parentid"`
	Activeonly    bool       `json:"activeonly"`
	Sendafter     *time.Time `json:"sendafter"`
	Pending       bool       `json:"pending"`
	Essential     bool       `json:"essential"`
	Template      *string    `json:"template"`
	Editprotected bool       `json:"editprotected"`
}

// AdminUser is the creator of an admin, as V1 returned it.
type AdminUser struct {
	ID          uint64 `json:"id"`
	Displayname string `json:"displayname"`
}

// adminColumns are the admins columns returned to moderators. V1's Admin publicatts.
const adminColumns = "id, createdby, subject, text, ctatext, ctalink, created, complete, heldby, heldat, " +
	"pending, parentid, activeonly, sendafter, essential, template, editprotected"

// addCreators fills in createdby as {id, displayname}.
func addCreators(db *gorm.DB, admins []Admin) {
	ids := []uint64{}
	for _, a := range admins {
		if a.Createdby != nil {
			ids = append(ids, *a.Createdby)
		}
	}
	if len(ids) == 0 {
		return
	}

	type nameRow struct {
		ID          uint64
		Displayname string
	}
	var rows []nameRow
	db.Table("users").
		Select("id, COALESCE(NULLIF(fullname, ''), TRIM(CONCAT_WS(' ', firstname, lastname))) AS displayname").
		Where("id IN (?)", ids).Scan(&rows)
	names := map[uint64]string{}
	for _, r := range rows {
		names[r.ID] = r.Displayname
	}

	for i := range admins {
		if admins[i].Createdby != nil {
			if n, ok := names[*admins[i].Createdby]; ok {
				admins[i].CreatedbyUser = &AdminUser{ID: *admins[i].Createdby, Displayname: n}
			}
		}
	}
}

// normaliseSendAfter accepts ISO 8601 (e.g. "2006-01-02T15:04:05Z", or a browser datetime-local value)
// and converts it to the MySQL DATETIME format ("2006-01-02 15:04:05") which strict mode requires.
// Nil or empty means no send-after time, which is stored as NULL.
func normaliseSendAfter(in *string) interface{} {
	if in == nil || *in == "" {
		return nil
	}
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05", "2006-01-02T15:04", "2006-01-02 15:04:05"} {
		if t, err := time.Parse(layout, *in); err == nil {
			return t.UTC().Format("2006-01-02 15:04:05")
		}
	}
	return *in
}

// GetAdmin handles GET /admin/:id - get a single admin by ID.
//
// @Summary Get a specific admin message
// @Tags admin
// @Produce json
// @Param id path integer true "Admin ID"
// @Success 200 {object} map[string]interface{}
// @Router /modtools/admin/{id} [get]
func GetAdmin(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid admin ID")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	db := database.DBConn
	var admin Admin
	db.Table("admins").Select(adminColumns).Where("id = ?", id).Scan(&admin)

	if admin.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Admin not found")
	}

	one := []Admin{admin}
	addCreators(db, one)
	admin = one[0]

	return c.JSON(admin)
}

// ListAdmins handles GET /admin - list admins for the national moderator pool.
//
// @Summary List admin messages
// @Tags admin
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /modtools/admin [get]
func ListAdmins(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	db := database.DBConn

	pendingParam := c.Query("pending", "")

	// We deliberately do NOT filter on `complete` - the ModTools "Previous" tab is the
	// archive of *sent* admins (which have `complete` set), so filtering complete IS NULL
	// hid the entire history and left only stale, approved-but-never-sent admins on show
	// (Discourse 9816). The frontend partitions pending vs previous client-side by the
	// `pending` flag.
	tx := db.Table("admins a").Select("a.id, a.createdby, a.subject, a.text, a.ctatext, " +
		"a.ctalink, a.created, a.complete, a.heldby, a.heldat, a.pending, a.parentid, a.activeonly, " +
		"a.sendafter, a.essential, a.template, a.editprotected")

	if pendingParam == "true" {
		tx = tx.Where("a.pending = 1")
	} else if pendingParam == "false" {
		tx = tx.Where("a.pending = 0")
	}

	var admins []Admin
	tx.Order("a.created DESC, a.id DESC").Scan(&admins)

	if admins == nil {
		admins = make([]Admin, 0)
	}

	addCreators(db, admins)

	return c.JSON(admins)
}

type PostAdminRequest struct {
	ID            uint64  `json:"id"`
	Action        string  `json:"action"`
	Subject       string  `json:"subject"`
	Text          string  `json:"text"`
	CTA_Text      *string `json:"ctatext,omitempty"`
	CTA_Link      *string `json:"ctalink,omitempty"`
	Essential     *bool   `json:"essential,omitempty"`
	Template      *string `json:"template,omitempty"`
	Editprotected *bool   `json:"editprotected,omitempty"`
	SendAfter     *string `json:"sendafter,omitempty"`
}

// PostAdmin handles POST /admin - action-based handler for Create, Hold, Release.
//
// @Summary Create an admin message
// @Tags admin
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /modtools/admin [post]
func PostAdmin(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	var req PostAdminRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	switch req.Action {
	case "Hold":
		if req.ID == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "id is required")
		}

		// Don't take a hold off another mod - Release is the way to do that.
		if holder, name := adminHeldByAnother(db, req.ID, myid); holder != 0 {
			return heldByAnotherResponse(c, holder, name)
		}

		// V1 recorded when the hold was taken as well as who took it; ModAdmin shows it.
		db.Table("admins").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"heldby": myid, "heldat": gorm.Expr("NOW()")})
		return c.JSON(fiber.Map{"success": true})

	case "Release":
		if req.ID == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "id is required")
		}

		db.Table("admins").Where("id = ?", req.ID).Update("heldby", gorm.Expr("NULL"))
		return c.JSON(fiber.Map{"success": true})

	default:
		// Create new admin.
		if req.Subject == "" {
			return fiber.NewError(fiber.StatusBadRequest, "subject is required")
		}

		essential := true
		if req.Essential != nil {
			essential = *req.Essential
		}

		template := ""
		if req.Template != nil {
			template = *req.Template
		}

		sendAfter := normaliseSendAfter(req.SendAfter)

		// Table()+map Create reads the generated id back from the same
		// sql.Result the INSERT returned, under the map key "@id" - see
		// test/insertid_gorm_writeback_test.go.
		row := map[string]interface{}{
			"createdby":     myid,
			"subject":       req.Subject,
			"text":          req.Text,
			"ctatext":       req.CTA_Text,
			"ctalink":       req.CTA_Link,
			"essential":     essential,
			"template":      template,
			"editprotected": req.Editprotected != nil && *req.Editprotected,
			"sendafter":     sendAfter,
			"created":       gorm.Expr("NOW()"),
		}
		if err := db.Table("admins").Create(row).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create admin")
		}

		var id uint64
		lastID, _ := row["@id"].(int64)
		if lastID > 0 {
			id = uint64(lastID)
		}

		return c.JSON(fiber.Map{"id": id})
	}
}

type PatchAdminRequest struct {
	ID            uint64  `json:"id"`
	Subject       *string `json:"subject,omitempty"`
	Text          *string `json:"text,omitempty"`
	Complete      *string `json:"complete,omitempty"`
	Pending       *bool   `json:"pending,omitempty"`
	CTA_Text      *string `json:"ctatext,omitempty"`
	CTA_Link      *string `json:"ctalink,omitempty"`
	Essential     *bool   `json:"essential,omitempty"`
	Template      *string `json:"template,omitempty"`
	Editprotected *bool   `json:"editprotected,omitempty"`
	// Sendafter is held raw so an explicit null or "" (clear it) can be told from absent.
	Sendafter json.RawMessage `json:"sendafter,omitempty"`
}

// PatchAdmin handles PATCH /admin - update an admin.
//
// @Summary Update an admin message
// @Tags admin
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /modtools/admin [patch]
// adminHeldByAnother returns the id and name of a DIFFERENT moderator holding this
// admin message, or 0 if it is free to act on.
func adminHeldByAnother(db *gorm.DB, id uint64, myid uint64) (uint64, string) {
	var holder uint64
	db.Table("admins").Select("COALESCE(heldby, 0)").Where("id = ?", id).Scan(&holder)
	if holder == 0 || holder == myid {
		return 0, ""
	}
	var name string
	db.Table("users").Select("fullname").Where("id = ?", holder).Scan(&name)
	return holder, name
}

// heldByAnotherResponse is the 409 a moderation action gets when someone else holds
// the item, carrying who so the UI can name them rather than just failing.
func heldByAnotherResponse(c *fiber.Ctx, holder uint64, name string) error {
	return c.Status(fiber.StatusConflict).JSON(fiber.Map{
		"ret":        1,
		"status":     "Held by another moderator",
		"heldby":     holder,
		"heldbyname": name,
	})
}

func PatchAdmin(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	var req PatchAdminRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	// Editing or completing an admin message another mod is holding is exactly the
	// case the hold exists to prevent.
	if holder, name := adminHeldByAnother(db, req.ID, myid); holder != 0 {
		return heldByAnotherResponse(c, holder, name)
	}

	// Validate sendafter before changing anything. V1 allowed it to be set by PATCH; null or ""
	// clears it.
	var sendafterVal interface{}
	sendafterSet := len(req.Sendafter) > 0
	if sendafterSet {
		var sa *string
		if err := json.Unmarshal(req.Sendafter, &sa); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid sendafter")
		}
		sendafterVal = normaliseSendAfter(sa)
		if str, ok := sendafterVal.(string); ok {
			if _, err := time.Parse("2006-01-02 15:04:05", str); err != nil {
				return fiber.NewError(fiber.StatusBadRequest, "Invalid sendafter")
			}
		}
	}

	if req.Subject != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("subject", *req.Subject)
	}
	if req.Text != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("text", *req.Text)
	}
	if req.Complete != nil {
		// Completing is terminal, so drop the hold with it. Leaving it set pinned the
		// message as "Held" indefinitely, so it stayed locked to a mod who had already
		// finished with it and only a manual Release cleared it.
		db.Table("admins").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"complete": gorm.Expr("NOW()"), "heldby": gorm.Expr("NULL")})
	}
	if req.Pending != nil {
		var val int
		if *req.Pending {
			val = 1
		}
		db.Table("admins").Where("id = ?", req.ID).Update("pending", val)
	}
	if req.CTA_Text != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("ctatext", *req.CTA_Text)
	}
	if req.CTA_Link != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("ctalink", *req.CTA_Link)
	}
	if req.Essential != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("essential", *req.Essential)
	}
	if req.Template != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("template", *req.Template)
	}
	if req.Editprotected != nil {
		db.Table("admins").Where("id = ?", req.ID).Update("editprotected", *req.Editprotected)
	}
	if sendafterSet {
		db.Table("admins").Where("id = ?", req.ID).Update("sendafter", sendafterVal)
	}

	// Track who edited and when.
	db.Table("admins").Where("id = ?", req.ID).
		Updates(map[string]interface{}{"editedat": gorm.Expr("NOW()"), "editedby": myid})

	return c.JSON(fiber.Map{"success": true})
}

type DeleteAdminRequest struct {
	ID uint64 `json:"id"`
}

// DeleteAdmin handles DELETE /admin - delete an admin.
//
// @Summary Delete an admin message
// @Tags admin
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /modtools/admin [delete]
func DeleteAdmin(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	// Support both body and query parameter for ID.
	var id uint64
	var req DeleteAdminRequest
	if err := c.BodyParser(&req); err == nil && req.ID > 0 {
		id = req.ID
	} else {
		id, _ = strconv.ParseUint(c.Query("id", "0"), 10, 64)
	}

	if id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	db.Table("admins").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"success": true})
}
