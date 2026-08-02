package admin

import (
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

type Admin struct {
	ID            uint64     `json:"id"`
	Createdby     *uint64    `json:"createdby"`
	Groupid       *uint64    `json:"groupid"`
	Subject       *string    `json:"subject"`
	Text          *string    `json:"text"`
	CTA_Text      *string    `json:"ctatext"`
	CTA_Link      *string    `json:"ctalink"`
	Created       *time.Time `json:"created"`
	Complete      *time.Time `json:"complete"`
	Heldby        *uint64    `json:"heldby"`
	Pending       bool       `json:"pending"`
	Essential     bool       `json:"essential"`
	Template      *string    `json:"template"`
	Editprotected bool       `json:"editprotected"`
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

	if !auth.IsSystemMod(myid) && !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator")
	}

	db := database.DBConn
	var admin Admin
	// ORM migration site 0ddc492f685c (wave 1).
	db.Table("admins").Select("id, createdby, groupid, subject, text, ctatext, ctalink, created, complete, heldby, pending, essential, template, editprotected").Where("id = ?", id).Scan(&admin)

	if admin.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Admin not found")
	}

	return c.JSON(admin)
}

// ListAdmins handles GET /admin - list admins for groups the user moderates.
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

	db := database.DBConn

	groupidParam, _ := strconv.ParseUint(c.Query("groupid", "0"), 10, 64)
	pendingParam := c.Query("pending", "")

	// Build query: admins for the relevant group(s). We deliberately do NOT filter on
	// `complete` - the ModTools "Previous" tab is the archive of *sent* admins (which have
	// `complete` set), so filtering complete IS NULL hid the entire history and left only
	// stale, approved-but-never-sent admins on show (Discourse 9816). Matches V1
	// Admin::listForGroup, which returned all admins for a group ordered by created DESC.
	// The frontend partitions pending vs previous client-side by the `pending` flag.
	selectCols := "SELECT a.id, a.createdby, a.groupid, a.subject, a.text, a.ctatext, a.ctalink, a.created, a.complete, a.heldby, a.pending, a.essential, a.template, a.editprotected " +
		"FROM admins a WHERE "
	var query string
	var args []interface{}

	if groupidParam > 0 && auth.IsAdminOrSupport(myid) {
		// System Admin/Support may view the admin history for any specific group they ask for
		// (e.g. to look up a sent admin), without needing a membership on it.
		query = selectCols + "a.groupid = ?"
		args = append(args, groupidParam)
	} else {
		// Restrict to the caller's active mod groups (checks settings.active, not just role,
		// so admins for groups the mod has stepped back from are hidden). This applies to
		// ordinary mods always, and to Admin/Support when no specific group is requested -
		// otherwise the unscoped sweep leaked other groups' admins into the Pending tab
		// (Discourse 9816: "I can see Admins for groups I am not on"). Matches V1
		// Admin::listPending, which always scoped to the caller's own active mod groups.
		activeGroupIDs := user.GetActiveModGroupIDs(myid)
		if len(activeGroupIDs) == 0 {
			return c.JSON(make([]Admin, 0))
		}
		query = selectCols + "a.groupid IN (?)"
		args = append(args, activeGroupIDs)

		if groupidParam > 0 {
			query += " AND a.groupid = ?"
			args = append(args, groupidParam)
		}
	}

	if pendingParam == "true" {
		query += " AND a.pending = 1"
	} else if pendingParam == "false" {
		query += " AND a.pending = 0"
	}

	query += " ORDER BY a.created DESC, a.id DESC"

	var admins []Admin
	db.Raw(query, args...).Scan(&admins)

	if admins == nil {
		admins = make([]Admin, 0)
	}

	return c.JSON(admins)
}

type PostAdminRequest struct {
	ID            uint64  `json:"id"`
	Action        string  `json:"action"`
	GroupID       uint64  `json:"groupid"`
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

		// Check mod of the admin's group.
		var adminGroupID uint64
		// ORM migration site 6eb1be4ff453 (wave 1).
		db.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", req.ID).Scan(&adminGroupID)

		if !user.IsModOfGroup(myid, adminGroupID) {
			return fiber.NewError(fiber.StatusForbidden, "Must be a moderator of the admin's group")
		}

		// Don't take a hold off another mod - Release is the way to do that.
		if holder, name := adminHeldByAnother(db, req.ID, myid); holder != 0 {
			return heldByAnotherResponse(c, holder, name)
		}

		db.Exec("UPDATE admins SET heldby = ? WHERE id = ?", myid, req.ID)
		return c.JSON(fiber.Map{"success": true})

	case "Release":
		if req.ID == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "id is required")
		}

		var adminGroupID uint64
		// ORM migration site 0ebce3bdec81 (wave 1).
		db.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", req.ID).Scan(&adminGroupID)

		if !user.IsModOfGroup(myid, adminGroupID) {
			return fiber.NewError(fiber.StatusForbidden, "Must be a moderator of the admin's group")
		}

		db.Exec("UPDATE admins SET heldby = NULL WHERE id = ?", req.ID)
		return c.JSON(fiber.Map{"success": true})

	default:
		// Create new admin.
		if req.GroupID == 0 && !user.IsAdminOrSupport(myid) {
			return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
		}

		if req.GroupID > 0 && !user.IsModOfGroup(myid, req.GroupID) {
			return fiber.NewError(fiber.StatusForbidden, "Must be a moderator of the group")
		}

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

		// Normalise sendafter: accept ISO 8601 (e.g. "2006-01-02T15:04:05Z") and
		// convert to MySQL DATETIME format ("2006-01-02 15:04:05") which strict mode requires.
		var sendAfter interface{}
		if req.SendAfter != nil && *req.SendAfter != "" {
			for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05", "2006-01-02 15:04:05"} {
				if t, err := time.Parse(layout, *req.SendAfter); err == nil {
					s := t.UTC().Format("2006-01-02 15:04:05")
					sendAfter = s
					break
				}
			}
			if sendAfter == nil {
				sendAfter = *req.SendAfter
			}
		}

		// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
		// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
		// parallel load (GORM's connection pool may assign a different connection).
		sqlDB, err := db.DB()
		if err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Database error")
		}
		sqlResult, err := sqlDB.Exec("INSERT INTO admins (createdby, groupid, subject, text, ctatext, ctalink, essential, template, editprotected, sendafter, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
			myid, utils.NilIfZero(req.GroupID), req.Subject, req.Text, req.CTA_Text, req.CTA_Link, essential, template, req.Editprotected != nil && *req.Editprotected, sendAfter)

		if err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create admin")
		}

		var id uint64
		lastID, err := sqlResult.LastInsertId()
		if err == nil && lastID > 0 {
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
	// ORM migration site be61683f2a10 (wave 1).
	db.Table("admins").Select("COALESCE(heldby, 0)").Where("id = ?", id).Scan(&holder)
	if holder == 0 || holder == myid {
		return 0, ""
	}
	var name string
	// ORM migration site 619fb338bc20 (wave 1).
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

	var req PatchAdminRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	var adminGroupID uint64
	// ORM migration site 8607a46d5c6f (wave 1).
	db.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", req.ID).Scan(&adminGroupID)

	if !user.IsModOfGroup(myid, adminGroupID) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator of the admin's group")
	}

	// Editing or completing an admin message another mod is holding is exactly the
	// case the hold exists to prevent.
	if holder, name := adminHeldByAnother(db, req.ID, myid); holder != 0 {
		return heldByAnotherResponse(c, holder, name)
	}

	if req.Subject != nil {
		db.Exec("UPDATE admins SET subject = ? WHERE id = ?", *req.Subject, req.ID)
	}
	if req.Text != nil {
		db.Exec("UPDATE admins SET text = ? WHERE id = ?", *req.Text, req.ID)
	}
	if req.Complete != nil {
		// Completing is terminal, so drop the hold with it. Leaving it set pinned the
		// message as "Held" indefinitely, so it stayed locked to a mod who had already
		// finished with it and only a manual Release cleared it.
		db.Exec("UPDATE admins SET complete = NOW(), heldby = NULL WHERE id = ?", req.ID)
	}
	if req.Pending != nil {
		var val int
		if *req.Pending {
			val = 1
		}
		db.Exec("UPDATE admins SET pending = ? WHERE id = ?", val, req.ID)
	}
	if req.CTA_Text != nil {
		db.Exec("UPDATE admins SET ctatext = ? WHERE id = ?", *req.CTA_Text, req.ID)
	}
	if req.CTA_Link != nil {
		db.Exec("UPDATE admins SET ctalink = ? WHERE id = ?", *req.CTA_Link, req.ID)
	}
	if req.Essential != nil {
		db.Exec("UPDATE admins SET essential = ? WHERE id = ?", *req.Essential, req.ID)
	}
	if req.Template != nil {
		db.Exec("UPDATE admins SET template = ? WHERE id = ?", *req.Template, req.ID)
	}
	if req.Editprotected != nil {
		db.Exec("UPDATE admins SET editprotected = ? WHERE id = ?", *req.Editprotected, req.ID)
	}

	// Track who edited and when.
	db.Exec("UPDATE admins SET editedat = NOW(), editedby = ? WHERE id = ?", myid, req.ID)

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

	var adminGroupID uint64
	// ORM migration site a1ff219a08d7 (wave 1).
	db.Table("admins").Select("COALESCE(groupid, 0)").Where("id = ?", id).Scan(&adminGroupID)

	if !user.IsModOfGroup(myid, adminGroupID) {
		return fiber.NewError(fiber.StatusForbidden, "Must be a moderator of the admin's group")
	}

	db.Exec("DELETE FROM admins WHERE id = ?", id)

	return c.JSON(fiber.Map{"success": true})
}
