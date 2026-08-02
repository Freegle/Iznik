package spammers

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// GetSpammers handles GET /spammers with search and pagination.
//
// @Summary List spammers
// @Tags spammers
// @Produce json
// @Param collection query string false "Collection: Spammer, Whitelisted, PendingAdd, PendingRemove"
// @Param search query string false "Search term"
// @Param context query string false "Pagination context (last ID)"
// @Param partner query string false "Partner API key (alternative to session auth)"
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /api/spammers [get]
func GetSpammers(c *fiber.Ctx) error {
	// Partners with a valid key can access the spammer list without a user session.
	partner := c.Query("partner", "")
	if partner != "" {
		db := database.DBConn
		var partnerID uint64
		// ORM migration site 4d467f8ef688 (wave 1).
		db.Table("partners_keys").Select("id").Where("`key` = ?", partner).Scan(&partnerID)

		if partnerID == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
		}
		// Valid partner — fall through to the query logic.
	} else {
		// Standard user authentication path.
		myid := user.WhoAmI(c)
		if myid == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Not moderator")
		}

		if !auth.IsSystemMod(myid) {
			// Return empty list for non-moderators rather than an error,
			// so ModTools pages degrade gracefully.
			return c.JSON(fiber.Map{
				"spammers": []fiber.Map{},
				"context":  fiber.Map{},
			})
		}
	}

	db := database.DBConn
	collection := c.Query("collection", "")
	search := c.Query("search", "")
	contextID, _ := strconv.ParseUint(c.Query("context", "0"), 10, 64)

	type SpamRow struct {
		ID         uint64  `json:"id"`
		Userid     uint64  `json:"userid"`
		Collection string  `json:"collection"`
		Reason     string  `json:"reason"`
		Added      string  `json:"added"`
		Byuserid   *uint64 `json:"byuserid"`
		Heldby     *uint64 `json:"heldby"`
		Heldat     *string `json:"heldat"`
	}

	// ORM migration site d64650fb9560 (Tier 3 keep-raw review). Four
	// independent toggles - collection!="", contextID>0, userid>0, search!="" -
	// give 2x2x2x2 = 16 possible rendered forms, all declared in
	// ormharness/shapes.json and proven by TestTier3Shapes_d64650fb9560
	// (iznik-server-go/test).
	// WHERE built as a single string for ONE Where() call: GORM's
	// clause.Where wraps any fragment containing "AND"/"OR" in an extra
	// paren pair once there is more than one Where expression to combine
	// (clause/where.go buildExprs), which would diverge from the golden.
	tx := db.Table("spam_users").
		Select("DISTINCT spam_users.*").
		Joins("INNER JOIN users ON spam_users.userid = users.id")

	whereSQL := "1=1"
	var whereArgs []interface{}

	if collection != "" {
		whereSQL += " AND spam_users.collection = ?"
		whereArgs = append(whereArgs, collection)
	}

	if contextID > 0 {
		whereSQL += " AND spam_users.id < ?"
		whereArgs = append(whereArgs, contextID)
	}

	useridFilter, _ := strconv.ParseUint(c.Query("userid", "0"), 10, 64)
	if useridFilter > 0 {
		whereSQL += " AND spam_users.userid = ?"
		whereArgs = append(whereArgs, useridFilter)
	}

	if search != "" {
		tx = tx.Joins("LEFT JOIN users_emails ON users_emails.userid = spam_users.userid")
		searchLike := "%" + search + "%"
		whereSQL += " AND (users_emails.email LIKE ? OR users.fullname LIKE ?)"
		whereArgs = append(whereArgs, searchLike, searchLike)
	}

	var rows []SpamRow
	tx.Where(whereSQL, whereArgs...).Order("spam_users.id DESC").Limit(10).Scan(&rows)

	if len(rows) == 0 {
		rows = make([]SpamRow, 0)
	}

	ctx := map[string]interface{}{}
	if len(rows) > 0 {
		ctx["id"] = rows[len(rows)-1].ID
	}

	return c.JSON(fiber.Map{
		"ret":      0,
		"status":   "Success",
		"spammers": rows,
		"context":  ctx,
	})
}

// PostSpammer handles POST /spammers to add a spammer entry.
//
// @Summary Add spammer
// @Tags spammers
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/spammers [post]
func PostSpammer(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type AddRequest struct {
		Userid     uint64 `json:"userid"`
		Collection string `json:"collection"`
		Reason     string `json:"reason"`
	}

	var req AddRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.Userid == 0 {
		req.Userid, _ = strconv.ParseUint(c.FormValue("userid", c.Query("userid", "0")), 10, 64)
	}
	if req.Collection == "" {
		req.Collection = c.FormValue("collection", c.Query("collection", ""))
	}
	if req.Reason == "" {
		req.Reason = c.FormValue("reason", c.Query("reason", ""))
	}

	if req.Userid == 0 || req.Collection == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
	}

	isAdmin := user.IsAdminOrSupport(myid)
	hasSpamAdmin := auth.HasPermission(myid, auth.PERM_SPAM_ADMIN)

	// Only admins or SpamAdmin users can add directly as Spammer/Whitelisted.
	// Anyone can report as PendingAdd.
	if !isAdmin && !hasSpamAdmin && req.Collection != utils.SPAM_COLLECTION_PENDING_ADD {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	db := database.DBConn

	// V1 parity (Spam::addSpammer): for PendingAdd, skip the REPLACE if a spam_users row
	// already exists for this user so the original reporter's byuserid is preserved.
	// This is the fix for Discourse #9589 (wrong-attribution bug).
	if req.Collection == utils.SPAM_COLLECTION_PENDING_ADD {
		var existingCount int64
		// ORM migration site 3a1a2fda0491 (wave 1).
		db.Table("spam_users").Where("userid = ?", req.Userid).Count(&existingCount)
		if existingCount > 0 {
			return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": 0})
		}
	}

	// ORM migration site 20dfce4d2228 (Tier 1 batch review). GORM's map-Create
	// reads the id back from the same sql.Result the INSERT/REPLACE returned
	// (under the map key "@id"), which is the same write-connection guarantee
	// the old sqlDB.Exec()+LastInsertId() call had - REPLACE always inserts a
	// fresh row (never a no-op like ON DUPLICATE KEY UPDATE can be), so there
	// is no "0 on a no-op hit" trap here. clause.Insert{Modifier: "REPLACE"}
	// needs database.RegisterCustomClauseBuilders's ClauseBuilders["INSERT"]
	// override (wired into database.DBConn at startup; see
	// database/clausebuilders.go) - without it this would render "INSERT
	// REPLACE INTO", not "REPLACE INTO".
	row := map[string]interface{}{
		"userid":     req.Userid,
		"collection": req.Collection,
		"reason":     req.Reason,
		"byuserid":   myid,
		"heldby":     gorm.Expr("NULL"),
		"heldat":     gorm.Expr("NULL"),
	}
	if err := db.Table("spam_users").Clauses(clause.Insert{Modifier: "REPLACE"}).Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to add spammer")
	}

	var newID uint64
	if idInt64, ok := row["@id"].(int64); ok && idInt64 > 0 {
		newID = uint64(idInt64)
	}

	// V1 parity: reporting a SYSTEMROLE_USER as PendingAdd suppresses their ChitChat/newsfeed
	// posts by setting users.newsfeedmodstatus = 'Suppressed' while pending review.
	if req.Collection == utils.SPAM_COLLECTION_PENDING_ADD {
		// ORM migration site 284c8dddea5c (wave 2).
		db.Table("users").Where("id = ? AND systemrole = ?", req.Userid, utils.SYSTEMROLE_USER).
			Update("newsfeedmodstatus", utils.NEWSFEED_MODSTATUS_SUPPRESSED)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

// PatchSpammer handles PATCH /spammers to update collection/reason.
//
// @Summary Update spammer
// @Tags spammers
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/spammers [patch]
func PatchSpammer(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type PatchRequest struct {
		ID         uint64  `json:"id"`
		Collection string  `json:"collection"`
		Reason     string  `json:"reason"`
		Heldby     *uint64 `json:"heldby"`
	}

	var req PatchRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	isAdmin := user.IsAdminOrSupport(myid)
	hasSpamAdmin := auth.HasPermission(myid, auth.PERM_SPAM_ADMIN)

	// Get current state.
	db := database.DBConn
	var current struct {
		Collection string
		Reason     string
		Byuserid   *uint64
		Heldby     *uint64
	}
	// ORM migration site b35f73621756 (wave 1).
	db.Table("spam_users").Select("collection, reason, byuserid, heldby").Where("id = ?", req.ID).Scan(&current)

	if current.Collection == "" {
		return fiber.NewError(fiber.StatusNotFound, "Not found")
	}

	// Another mod's hold is an exclusive claim on this report. Refuse to resolve it
	// (any collection change) or to take the hold off them, but keep the plain
	// release working - it is the escape hatch when the holder is away. The client
	// sends the unchanged collection for both hold and release, and a different one
	// only for a real decision, so the collection is what distinguishes them.
	if current.Heldby != nil && *current.Heldby != myid {
		changingCollection := req.Collection != "" && req.Collection != current.Collection
		takingHold := req.Heldby != nil
		if changingCollection || takingHold {
			var holderName string
			// ORM migration site 4e48616b5b66 (wave 1).
			db.Table("users").Select("fullname").Where("id = ?", *current.Heldby).Scan(&holderName)
			return c.Status(fiber.StatusConflict).JSON(fiber.Map{
				"ret":        1,
				"status":     "Held by another moderator",
				"heldby":     *current.Heldby,
				"heldbyname": holderName,
			})
		}
	}

	// Permission: admins and SpamAdmin users can do anything.
	// Regular system mods can only move Spammer -> PendingRemove.
	if !isAdmin && !hasSpamAdmin {
		if !auth.IsSystemMod(myid) {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}
		// Moderators without SpamAdmin can only request removal.
		if !(current.Collection == utils.SPAM_COLLECTION_SPAMMER && req.Collection == utils.SPAM_COLLECTION_PENDING_REMOVE) {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}
	}

	// Build update. V1 parity (Spam::updateSpammer):
	//   * Preserve the existing reason when the caller sends an empty one — a Hold
	//     action carries no reason and must not blank out the reporter's facts.
	//   * Preserve the existing byuserid (original reporter) except for
	//     PENDING_REMOVE, where V1 tracks who requested removal by setting byuserid
	//     to the acting mod. Otherwise holding or confirming a report must not
	//     reassign the report to the current mod (Discourse #9592).
	if req.Collection != "" {
		reason := req.Reason
		if reason == "" {
			reason = current.Reason
		}
		var byuserid interface{}
		if req.Collection == utils.SPAM_COLLECTION_PENDING_REMOVE {
			byuserid = myid
		} else {
			byuserid = current.Byuserid
		}
		// You can only hold something as yourself. The holder identity used to be
		// taken verbatim from the request body, so a client could set the hold to an
		// arbitrary user id. Presence of heldby means "hold", absence means release.
		var heldby *uint64
		if req.Heldby != nil {
			heldby = &myid
		}

		// ORM migration site 2c2dda80b557 (wave 2).
		//
		// The SET order here is not load-bearing, though an earlier version of
		// check-set-order.sh said it was. The "?" inside the heldat CASE is a
		// bind fed from a Go variable that happens to be called heldby; it is
		// not a reference to the heldby column, and the SQL names no assigned
		// column at all. The checker was scanning gorm.Expr's bind arguments
		// alongside its SQL, so a Go identifier sharing a column name read as a
		// cross-reference. It now scans only the SQL literal.
		db.Table("spam_users").Where("id = ?", req.ID).
			Updates(map[string]interface{}{
				"collection": req.Collection,
				"reason":     reason,
				"byuserid":   byuserid,
				"heldby":     heldby,
				"heldat":     gorm.Expr("CASE WHEN ? IS NOT NULL THEN NOW() ELSE NULL END", heldby),
			})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// ExportSpammers returns all confirmed spammers with their email addresses.
// Used by partner services (e.g. Trash Nothing) to sync spammer lists.
//
// @Summary Export spammers
// @Tags spammers
// @Produce json
// @Param partner query string false "Partner API key"
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /api/spammers/export [get]
func ExportSpammers(c *fiber.Ctx) error {
	// Accept either partner key or moderator session.
	partner := c.Query("partner", "")
	if partner != "" {
		db := database.DBConn
		var partnerID uint64
		// ORM migration site 9f33a7d0a5b1 (wave 1).
		db.Table("partners_keys").Select("id").Where("`key` = ?", partner).Scan(&partnerID)

		if partnerID == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
		}
	} else {
		myid := user.WhoAmI(c)
		if myid == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Not authorized")
		}
		if !auth.IsSystemMod(myid) {
			return fiber.NewError(fiber.StatusForbidden, "Not authorized")
		}
	}

	db := database.DBConn

	type ExportRow struct {
		ID     uint64 `json:"id"`
		Added  string `json:"added"`
		Reason string `json:"reason"`
		Email  string `json:"email"`
	}

	var rows []ExportRow
	// ORM migration site b524187a3675 (wave 4).
	db.Table("spam_users").
		Select("spam_users.id, spam_users.added, reason, email").
		Joins("INNER JOIN users_emails ON spam_users.userid = users_emails.userid").
		Where("collection = ?", utils.SPAM_COLLECTION_SPAMMER).
		Scan(&rows)

	if rows == nil {
		rows = make([]ExportRow, 0)
	}

	return c.JSON(fiber.Map{
		"ret":      0,
		"status":   "Success",
		"spammers": rows,
	})
}

// DeleteSpammer handles DELETE /spammers (admin or SpamAdmin permission).
//
// @Summary Delete spammer
// @Tags spammers
// @Produce json
// @Param id query integer true "Spammer record ID"
// @Security BearerAuth
// @Router /api/spammers [delete]
func DeleteSpammer(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !user.IsAdminOrSupport(myid) && !auth.HasPermission(myid, auth.PERM_SPAM_ADMIN) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	type DeleteRequest struct {
		ID uint64 `json:"id"`
	}

	var req DeleteRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	db := database.DBConn
	// ORM migration site cd86450dea5a (wave 2).
	db.Table("spam_users").Where("id = ?", req.ID).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
