package donations

import (
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// GiftAid represents a user's Gift Aid declaration
type GiftAid struct {
	ID                uint64     `json:"id" gorm:"column:id"`
	UserID            uint64     `json:"userid" gorm:"column:userid"`
	Timestamp         time.Time  `json:"timestamp" gorm:"column:timestamp"`
	Period            string     `json:"period" gorm:"column:period"`
	Fullname          string     `json:"fullname" gorm:"column:fullname"`
	Firstname         *string    `json:"firstname" gorm:"column:firstname"`
	Lastname          *string    `json:"lastname" gorm:"column:lastname"`
	Homeaddress       string     `json:"homeaddress" gorm:"column:homeaddress"`
	Deleted           *time.Time `json:"deleted" gorm:"column:deleted"`
	Reviewed          *time.Time `json:"reviewed" gorm:"column:reviewed"`
	Updated           time.Time  `json:"updated" gorm:"column:updated"`
	Postcode          *string    `json:"postcode" gorm:"column:postcode"`
	Housenameornumber *string    `json:"housenameornumber" gorm:"column:housenameornumber"`
}

// GetGiftAid returns the logged-in user's Gift Aid declaration, or dispatches
// to ListGiftAid/SearchGiftAid for admin operations.
// @Summary Get user's Gift Aid declaration
// @Description Returns the Gift Aid declaration for the logged-in user. With all=true returns admin review list. With search=xxx searches records.
// @Tags donations
// @Accept json
// @Produce json
// @Param all query boolean false "Return all records needing review (admin only)"
// @Param search query string false "Search records by name/address (admin only)"
// @Success 200 {object} GiftAid "User's Gift Aid declaration"
// @Failure 401 {object} map[string]string "Not logged in"
// @Failure 404 {object} map[string]string "No Gift Aid declaration found"
// @Router /giftaid [get]
func GetGiftAid(c *fiber.Ctx) error {
	// Dispatch to admin list/search handlers if appropriate query params are present
	if c.Query("all") == "true" {
		return ListGiftAid(c)
	}
	if c.Query("search") != "" {
		return SearchGiftAid(c)
	}

	db := database.DBConn

	// Get the logged-in user. Use WhoAmI (not GetJWTFromRequest) so that
	// c.Locals("authUsed") is set: that is what makes the global auth middleware
	// enforce server-side session revocation. Reading the JWT directly would let a
	// captured-but-logged-out token keep returning Gift Aid PII (name/address/
	// postcode) until it expires. The other giftaid handlers already use WhoAmI.
	userID := user.WhoAmI(c)
	if userID == 0 {
		return c.Status(401).JSON(fiber.Map{
			"error": "Not logged in",
		})
	}

	// Query for user's gift aid record (exclude deleted records)
	var giftaid GiftAid
	result := db.Table("giftaid").
		Select("id, userid, timestamp, period, fullname, firstname, lastname, homeaddress, deleted, reviewed, updated, postcode, housenameornumber").
		Where("userid = ? AND deleted IS NULL", userID).
		Limit(1).
		Scan(&giftaid)

	if result.Error != nil {
		return c.Status(500).JSON(fiber.Map{
			"error": "Failed to fetch Gift Aid declaration",
		})
	}

	// If no record found (ID will be 0), return 404
	if giftaid.ID == 0 {
		return c.Status(404).JSON(fiber.Map{
			"error": "No Gift Aid declaration found",
		})
	}

	// Return the gift aid data at top level (v2 format)
	return c.JSON(giftaid)
}

// =============================================================================
// Merged from donations/giftaid_write.go
// =============================================================================

// GiftAidListItem represents a gift aid record in the admin review list
type GiftAidListItem struct {
	GiftAid
	Email     *string `json:"email" gorm:"-"`
	Donations float64 `json:"donations" gorm:"column:donations"`
}

// SetGiftAidRequest is the request body for creating/updating a gift aid declaration.
// Either fullname or both firstname+lastname must be provided when period != Declined.
type SetGiftAidRequest struct {
	Period      string  `json:"period"`
	Fullname    string  `json:"fullname"`
	Firstname   *string `json:"firstname"`
	Lastname    *string `json:"lastname"`
	Homeaddress string  `json:"homeaddress"`
}

// EditGiftAidRequest is the request body for admin editing of a gift aid record.
// String fields use *string so that nil means "not provided" (don't update)
// while a non-nil pointer (even to "") means "explicitly set" (update, allowing clear).
type EditGiftAidRequest struct {
	ID                uint64  `json:"id"`
	Period            *string `json:"period"`
	Fullname          *string `json:"fullname"`
	Firstname         *string `json:"firstname"`
	Lastname          *string `json:"lastname"`
	Homeaddress       *string `json:"homeaddress"`
	Postcode          *string `json:"postcode"`
	Housenameornumber *string `json:"housenameornumber"`
	Reviewed          *bool   `json:"reviewed"`
	Deleted           *bool   `json:"deleted"`
}

// isGiftAidAdmin checks if a user has admin/support role or PERM_GIFTAID permission
func isGiftAidAdmin(myid uint64) bool {
	db := database.DBConn

	if auth.IsAdminOrSupport(myid) {
		return true
	}

	var permissions *string
	db.Table("users").Select("permissions").Where("id = ?", myid).Scan(&permissions)

	if permissions != nil && strings.Contains(strings.ToLower(*permissions), "giftaid") {
		return true
	}

	return false
}

// ListGiftAid returns gift aid records needing review (admin only)
// Called when GET /giftaid has all=true query param
func ListGiftAid(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !isGiftAidAdmin(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	db := database.DBConn

	var giftaids []GiftAidListItem
	db.Table("giftaid").
		Select("giftaid.*, SUM(users_donations.GrossAmount) AS donations").
		Joins("LEFT JOIN users_donations ON users_donations.userid = giftaid.userid").
		Where("giftaid.reviewed IS NULL AND giftaid.deleted IS NULL AND giftaid.period != 'Declined'").
		Group("giftaid.userid").
		Order("giftaid.timestamp DESC").
		Scan(&giftaids)

	if giftaids == nil {
		giftaids = make([]GiftAidListItem, 0)
	}

	// Fetch emails for each user
	for i := range giftaids {
		var email *string
		db.Table("users_emails").Select("email").Where("userid = ?", giftaids[i].UserID).Order("preferred DESC").Limit(1).Scan(&email)
		giftaids[i].Email = email
	}

	return c.JSON(fiber.Map{"giftaids": giftaids})
}

// SearchGiftAid searches gift aid records by name or address (admin only)
// Called when GET /giftaid has search=xxx query param
func SearchGiftAid(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !isGiftAidAdmin(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	search := c.Query("search")
	if search == "" {
		return fiber.NewError(fiber.StatusBadRequest, "search is required")
	}

	db := database.DBConn
	searchPattern := "%" + search + "%"

	var giftaids []GiftAidListItem
	db.Table("giftaid").
		Where("fullname LIKE ? OR homeaddress LIKE ? OR id LIKE ?", searchPattern, searchPattern, searchPattern).
		Scan(&giftaids)

	if giftaids == nil {
		giftaids = make([]GiftAidListItem, 0)
	}

	// Fetch emails for each user
	for i := range giftaids {
		var email *string
		db.Table("users_emails").Select("email").Where("userid = ?", giftaids[i].UserID).Order("preferred DESC").Limit(1).Scan(&email)
		giftaids[i].Email = email
	}

	return c.JSON(fiber.Map{"giftaids": giftaids})
}

// SetGiftAid creates or updates the logged-in user's gift aid declaration
// @Summary Set Gift Aid declaration
// @Description Creates or updates the user's Gift Aid declaration
// @Tags donations
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{} "Gift Aid ID"
// @Failure 400 {object} map[string]string "Bad parameters"
// @Failure 401 {object} map[string]string "Not logged in"
// @Router /giftaid [post]
func SetGiftAid(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req SetGiftAidRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Period == "" {
		return fiber.NewError(fiber.StatusBadRequest, "period is required")
	}

	// If not Declined, a name and homeaddress are required.
	// Either fullname OR both firstname+lastname must be provided.
	if req.Period != "Declined" {
		hasFullname := req.Fullname != ""
		hasFirstLast := req.Firstname != nil && *req.Firstname != "" &&
			req.Lastname != nil && *req.Lastname != ""
		if !hasFullname && !hasFirstLast {
			return fiber.NewError(fiber.StatusBadRequest, "fullname or firstname+lastname are required")
		}
		if req.Homeaddress == "" {
			return fiber.NewError(fiber.StatusBadRequest, "homeaddress is required")
		}
		// Derive fullname from firstname+lastname when only those are provided
		if !hasFullname && hasFirstLast {
			req.Fullname = *req.Firstname + " " + *req.Lastname
		}
	}

	db := database.DBConn

	// Clauses(gorm.WithResult()) reads the id from the same sql.Result the
	// write returned — never issue a separate SELECT LAST_INSERT_ID() as it's
	// unsafe under parallel load (GORM's connection pool may assign a
	// different connection).
	res := gorm.WithResult()
	tx := db.Table("giftaid").Clauses(res, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			{Column: clause.Column{Name: "period"}, Value: gorm.Expr("?", req.Period)},
			{Column: clause.Column{Name: "fullname"}, Value: gorm.Expr("?", req.Fullname)},
			{Column: clause.Column{Name: "firstname"}, Value: gorm.Expr("?", req.Firstname)},
			{Column: clause.Column{Name: "lastname"}, Value: gorm.Expr("?", req.Lastname)},
			{Column: clause.Column{Name: "homeaddress"}, Value: gorm.Expr("?", req.Homeaddress)},
			{Column: clause.Column{Name: "deleted"}, Value: gorm.Expr("NULL")},
		},
	}).Create(map[string]interface{}{
		"userid": myid, "period": req.Period, "fullname": req.Fullname,
		"firstname": req.Firstname, "lastname": req.Lastname, "homeaddress": req.Homeaddress,
	})

	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to set gift aid")
	}

	var id uint64
	if res.Result != nil {
		if lastID, idErr := res.Result.LastInsertId(); idErr == nil && lastID > 0 {
			id = uint64(lastID)
		}
	}

	return c.JSON(fiber.Map{"id": id})
}

// EditGiftAid allows an admin to edit a gift aid record
// @Summary Edit Gift Aid declaration (admin)
// @Description Admin edits a Gift Aid record
// @Tags donations
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{} "Success"
// @Failure 401 {object} map[string]string "Not logged in"
// @Failure 403 {object} map[string]string "Not authorized"
// @Router /giftaid [patch]
func EditGiftAid(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !isGiftAidAdmin(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	var req EditGiftAidRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	// Update each field individually if provided (non-nil pointer means explicitly sent,
	// even if empty string -- allowing fields to be cleared).
	if req.Period != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("period", *req.Period)
	}
	if req.Fullname != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("fullname", *req.Fullname)
	}
	if req.Firstname != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("firstname", *req.Firstname)
	}
	if req.Lastname != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("lastname", *req.Lastname)
	}
	if req.Homeaddress != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("homeaddress", *req.Homeaddress)
	}
	if req.Postcode != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("postcode", *req.Postcode)
	}
	if req.Housenameornumber != nil {
		db.Table("giftaid").Where("id = ?", req.ID).Update("housenameornumber", *req.Housenameornumber)
	}
	if req.Reviewed != nil && *req.Reviewed {
		db.Table("giftaid").Where("id = ?", req.ID).Update("reviewed", gorm.Expr("NOW()"))
	}
	if req.Deleted != nil && *req.Deleted {
		db.Table("giftaid").Where("id = ?", req.ID).Update("deleted", gorm.Expr("NOW()"))
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteGiftAid soft-deletes the logged-in user's gift aid declaration
// @Summary Delete Gift Aid declaration
// @Description Soft-deletes the user's Gift Aid declaration by setting period to Declined and deleted to NOW()
// @Tags donations
// @Produce json
// @Success 200 {object} map[string]interface{} "Success"
// @Failure 401 {object} map[string]string "Not logged in"
// @Router /giftaid [delete]
func DeleteGiftAid(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// Get user's name for the insert if record doesn't exist
	var fullname string
	db.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", myid).Scan(&fullname)

	// INSERT or update existing record to mark as Declined.
	db.Table("giftaid").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{
			"period":  gorm.Expr("'Declined'"),
			"deleted": gorm.Expr("NOW()"),
		}),
	}).Create(map[string]interface{}{
		"userid":      myid,
		"period":      gorm.Expr("'Declined'"),
		"fullname":    fullname,
		"homeaddress": gorm.Expr("''"),
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
