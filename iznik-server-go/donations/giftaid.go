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

// giftAidColumns is what a donor sees of their own declaration. It leaves out recordedby, the
// volunteer who recorded an answer for them, which only Gift Aid volunteers see.
const giftAidColumns = "id, userid, timestamp, period, fullname, firstname, lastname, homeaddress, deleted, reviewed, updated, postcode, housenameornumber"

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
	if c.Query("noresponse") == "true" {
		return ListGiftAidNoResponse(c)
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
		Select(giftAidColumns).
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
	// RecordedBy is the volunteer who recorded this answer for the donor; nil when the donor
	// filled in the form themselves.
	RecordedBy *uint64 `json:"recordedby" gorm:"column:recordedby"`
	Email      *string `json:"email" gorm:"-"`
	Donations  float64 `json:"donations" gorm:"column:donations"`
}

// GiftAidNoResponse is a donor we have asked for a Gift Aid declaration who has not answered:
// no declaration on file (a declaration given up in review does not count as an answer) and at
// least one row in giftaid_asks.
type GiftAidNoResponse struct {
	UserID    uint64    `json:"userid" gorm:"column:userid"`
	Fullname  *string   `json:"fullname" gorm:"column:fullname"`
	Email     *string   `json:"email" gorm:"-"`
	Donations float64   `json:"donations" gorm:"column:donations"`
	Asks      int64     `json:"asks" gorm:"column:asks"`
	LastAsked time.Time `json:"lastasked" gorm:"column:lastasked"`
	// AskedBy is the volunteer who asked most recently; nil when it was the chase-up mail.
	AskedBy *uint64 `json:"askedby" gorm:"column:askedby"`
}

// RecordGiftAidRequest is the body of POST /giftaid/record.
type RecordGiftAidRequest struct {
	UserID  uint64 `json:"userid"`
	Outcome string `json:"outcome"`
}

// answeredGiftAidSQL is true when the donor whose id is in `col` has answered:
// a live declaration of either kind, or a decline (which the donor's own withdrawal leaves
// deleted). A declaration given up in review is deleted and not Declined, so it is not an answer.
func answeredGiftAidSQL(col string) string {
	return "EXISTS (SELECT 1 FROM giftaid WHERE giftaid.userid = " + col + " AND (giftaid.deleted IS NULL OR giftaid.period = 'Declined'))"
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
	//
	// giftaid has UNIQUE KEY (userid), so a member who declares again reuses their
	// existing row. `timestamp` is DEFAULT CURRENT_TIMESTAMP with no ON UPDATE, so it
	// must be refreshed here or the row keeps the date of the member's *first* ever
	// declaration. That date is not cosmetic: it is written to the HMRC claim CSV as
	// the declaration date, and GiftAidClaimService::identifyGiftAidedDonations
	// matches donations against it (`>=` for period Future, `=` for This). Leaving it
	// stale lets someone who declined years ago and re-declares Future today pull
	// every donation since that old date into a claim.
	//
	// `reviewed` is cleared for the same reason: this is a new declaration, possibly
	// with a new name or address, so it has to go back through review before we claim
	// on it.
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
			{Column: clause.Column{Name: "timestamp"}, Value: gorm.Expr("NOW()")},
			{Column: clause.Column{Name: "reviewed"}, Value: gorm.Expr("NULL")},
			// The donor answered for themselves, so no volunteer recorded it.
			{Column: clause.Column{Name: "recordedby"}, Value: gorm.Expr("NULL")},
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
			"period":     gorm.Expr("'Declined'"),
			"deleted":    gorm.Expr("NOW()"),
			"recordedby": gorm.Expr("NULL"),
		}),
	}).Create(map[string]interface{}{
		"userid":      myid,
		"period":      gorm.Expr("'Declined'"),
		"fullname":    fullname,
		"homeaddress": gorm.Expr("''"),
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// ListGiftAidNoResponse lists donors we have asked for a Gift Aid declaration who have not
// answered (admin only). Called when GET /giftaid has noresponse=true.
func ListGiftAidNoResponse(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !isGiftAidAdmin(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	db := database.DBConn

	donors := make([]GiftAidNoResponse, 0)
	res := db.Raw(`
		SELECT asks.userid, users.fullname, asks.asks, asks.lastasked,
			(SELECT a2.askedby FROM giftaid_asks a2 WHERE a2.userid = asks.userid ORDER BY a2.timestamp DESC, a2.id DESC LIMIT 1) AS askedby,
			COALESCE((SELECT SUM(GrossAmount) FROM users_donations WHERE users_donations.userid = asks.userid), 0) AS donations
		FROM (SELECT userid, COUNT(*) AS asks, MAX(timestamp) AS lastasked FROM giftaid_asks GROUP BY userid) asks
		INNER JOIN users ON users.id = asks.userid
		WHERE NOT ` + answeredGiftAidSQL("asks.userid") + `
		ORDER BY asks.lastasked DESC`).Scan(&donors)

	if res.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to list donors")
	}

	for i := range donors {
		var email *string
		db.Table("users_emails").Select("email").Where("userid = ?", donors[i].UserID).Order("preferred DESC").Limit(1).Scan(&email)
		donors[i].Email = email
	}

	return c.JSON(fiber.Map{"donors": donors})
}

// RecordGiftAid records a donor's Gift Aid answer on their behalf (admin only), so a volunteer
// never has to sign in as the donor to do it.
//
//   - Declined: the donor told us, some other way than the form, that we cannot claim. Their
//     declaration becomes Declined, dated now and marked with the volunteer who recorded it. It
//     replaces a consent they gave earlier, because a donor can withdraw by any means.
//   - NoResponse: we asked again and heard nothing. One more row in giftaid_asks; this can happen
//     any number of times. It is refused once the donor has answered, because then it is not true.
//
// @Summary Record a donor's Gift Aid answer (admin)
// @Tags donations
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{} "Success"
// @Failure 400 {object} map[string]string "Bad parameters"
// @Failure 403 {object} map[string]string "Not authorized"
// @Failure 404 {object} map[string]string "No such user"
// @Failure 409 {object} map[string]string "The donor has already answered"
// @Router /giftaid/record [post]
func RecordGiftAid(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !isGiftAidAdmin(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	var req RecordGiftAidRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.UserID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "userid is required")
	}

	if req.Outcome != "Declined" && req.Outcome != "NoResponse" {
		return fiber.NewError(fiber.StatusBadRequest, "outcome must be Declined or NoResponse")
	}

	db := database.DBConn

	var fullname *string
	var found int64
	if err := db.Raw("SELECT COUNT(*), MAX(fullname) FROM users WHERE id = ? AND deleted IS NULL", req.UserID).
		Row().Scan(&found, &fullname); err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to find user")
	}

	if found == 0 {
		return fiber.NewError(fiber.StatusNotFound, "No such user")
	}

	if req.Outcome == "NoResponse" {
		var answered bool
		if err := db.Raw("SELECT "+answeredGiftAidSQL("?"), req.UserID).Row().Scan(&answered); err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to check Gift Aid")
		}

		if answered {
			return fiber.NewError(fiber.StatusConflict, "This donor has already answered about Gift Aid")
		}

		if err := db.Exec("INSERT INTO giftaid_asks (userid, askedby) VALUES (?, ?)", req.UserID, myid).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to record the ask")
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	name := ""
	if fullname != nil {
		name = *fullname
	}

	// giftaid has UNIQUE KEY (userid). An existing row keeps its name and address, which belong to
	// the donor, and takes the decline; timestamp is refreshed because it is the date of the answer.
	tx := db.Table("giftaid").Clauses(clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "period"}, Value: gorm.Expr("'Declined'")},
			{Column: clause.Column{Name: "timestamp"}, Value: gorm.Expr("NOW()")},
			{Column: clause.Column{Name: "deleted"}, Value: gorm.Expr("NULL")},
			{Column: clause.Column{Name: "reviewed"}, Value: gorm.Expr("NULL")},
			{Column: clause.Column{Name: "recordedby"}, Value: gorm.Expr("?", myid)},
		},
	}).Create(map[string]interface{}{
		"userid":      req.UserID,
		"period":      gorm.Expr("'Declined'"),
		"fullname":    name,
		"homeaddress": gorm.Expr("''"),
		"recordedby":  myid,
	})

	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to record the decline")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
