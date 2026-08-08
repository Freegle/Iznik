// Package partnerships backs the ModTools Partnerships page: the sponsorship deals we have
// with local authorities, the groups each one covers, the money involved, and the on-demand
// generation of the quarterly statistics spreadsheets councils receive.
//
// A partnership is held against an authority rather than a group, because that is how the
// deal is actually done. The groups it covers are derived from the authority/group boundary
// overlap and cached in partnerships_groups; each covered group gets a groups_sponsorship
// row so the member site shows the council as a sponsor.
package partnerships

import (
	"sort"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/authority"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// TeamName is the ModTools team whose members may use this page.
const TeamName = "Partnerships"

// ExpiryWarningDays is how far ahead a sponsorship counts as "expiring soon" - three months,
// matching the reminder mail the Partnerships team gets.
const ExpiryWarningDays = 92

// dateFmt selects DATE columns as plain YYYY-MM-DD strings. The driver runs with
// parseTime=True, so without this they come back as time.Time and serialise with a
// spurious time and zone.
const dateFmt = "'%Y-%m-%d'"

// Partnership is one sponsorship deal with a local authority.
type Partnership struct {
	ID            uint64  `json:"id"`
	Authorityid   uint64  `json:"authorityid"`
	Authorityname string  `json:"authorityname"`
	Name          string  `json:"name"`
	Tagline       *string `json:"tagline"`
	Description   *string `json:"description"`
	Linkurl       *string `json:"linkurl"`
	Imageurl      *string `json:"imageurl"`
	Startdate     string  `json:"startdate"`
	Enddate       string  `json:"enddate"`
	Amount        float64 `json:"amount"`
	Agreed        bool    `json:"agreed"`
	Agreeddate    *string `json:"agreeddate"`
	Contactname   *string `json:"contactname"`
	Contactemail  *string `json:"contactemail"`
	Notes         *string `json:"notes"`
	Visible       bool    `json:"visible"`

	// Derived, for the list view.
	Groupcount int     `json:"groupcount"`
	Invoiced   float64 `json:"invoiced"`
	Paid       float64 `json:"paid"`
	Expired    bool    `json:"expired"`
	Expiring   bool    `json:"expiring"`
}

// Group is one Freegle group covered by a partnership.
type Group struct {
	Groupid       uint64  `json:"groupid"`
	Nameshort     string  `json:"nameshort"`
	Namedisplay   string  `json:"namedisplay"`
	Sponsorshipid *uint64 `json:"sponsorshipid"`
}

// Payment is money invoiced against a partnership, and whether it has come in.
type Payment struct {
	ID        uint64  `json:"id"`
	Date      string  `json:"date"`
	Amount    float64 `json:"amount"`
	Paid      *string `json:"paid"`
	Reference *string `json:"reference"`
	Notes     *string `json:"notes"`
}

// CanUse reports whether a user may see and manage partnerships: a member of the
// Partnerships team, or Support/Admin.
func CanUse(myid uint64) bool {
	if myid == 0 {
		return false
	}

	if auth.IsAdminOrSupport(myid) {
		return true
	}

	db := database.DBConn
	var count int64
	db.Table("teams_members tm").
		Joins("INNER JOIN teams t ON tm.teamid = t.id").
		Where("t.name = ? AND tm.userid = ?", TeamName, myid).
		Count(&count)

	return count > 0
}

// requireUser rejects the request unless the caller may manage partnerships. It returns the
// caller's id, or an error already shaped as a JSON response.
func requireUser(c *fiber.Ctx) (uint64, error) {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return 0, c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	if !CanUse(myid) {
		return 0, c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
	}

	return myid, nil
}

// selectList is the column list for a partnership row, with the derived money and group
// counts folded in so the list view is a single query.
func selectList() string {
	return "partnerships.id, partnerships.authorityid, authorities.name AS authorityname, " +
		"partnerships.name, partnerships.tagline, partnerships.description, partnerships.linkurl, " +
		"partnerships.imageurl, DATE_FORMAT(partnerships.startdate, " + dateFmt + ") AS startdate, " +
		"DATE_FORMAT(partnerships.enddate, " + dateFmt + ") AS enddate, partnerships.amount, " +
		"partnerships.agreed, DATE_FORMAT(partnerships.agreeddate, " + dateFmt + ") AS agreeddate, " +
		"partnerships.contactname, partnerships.contactemail, partnerships.notes, partnerships.visible, " +
		"(SELECT COUNT(*) FROM partnerships_groups pg WHERE pg.partnershipid = partnerships.id) AS groupcount, " +
		"COALESCE((SELECT SUM(amount) FROM partnerships_payments pp WHERE pp.partnershipid = partnerships.id), 0) AS invoiced, " +
		"COALESCE((SELECT SUM(amount) FROM partnerships_payments pp WHERE pp.partnershipid = partnerships.id AND pp.paid IS NOT NULL), 0) AS paid, " +
		"partnerships.enddate < CURDATE() AS expired, " +
		"(partnerships.enddate >= CURDATE() AND partnerships.enddate <= DATE_ADD(CURDATE(), INTERVAL ? DAY)) AS expiring"
}

// List returns every partnership, the deal running out soonest last.
//
// @Summary List partnerships
// @Tags partnerships
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership [get]
func List(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	db := database.DBConn

	var partnerships []Partnership
	db.Table("partnerships").
		Select(selectList(), ExpiryWarningDays).
		Joins("INNER JOIN authorities ON authorities.id = partnerships.authorityid").
		Order("partnerships.enddate DESC, partnerships.id DESC").
		Scan(&partnerships)

	if partnerships == nil {
		partnerships = []Partnership{}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "partnerships": partnerships})
}

// Single returns one partnership with the groups it covers, its financial-year split and
// its payments.
//
// @Summary Get a partnership
// @Tags partnerships
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id} [get]
func Single(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	p := load(id)
	if p.ID == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	years, explicit := yearsFor(p)

	return c.JSON(fiber.Map{
		"ret":         0,
		"status":      "Success",
		"partnership": p,
		"groups":      groupsFor(id),
		"years":       years,
		// Whether the split above was agreed year by year or worked out pro-rata, so the
		// page can say which it is showing.
		"explicityears": explicit,
		"payments":      paymentsFor(id),
	})
}

// load reads one partnership with its derived figures. A zero ID means it does not exist.
func load(id uint64) Partnership {
	db := database.DBConn

	var p Partnership
	db.Table("partnerships").
		Select(selectList(), ExpiryWarningDays).
		Joins("INNER JOIN authorities ON authorities.id = partnerships.authorityid").
		Where("partnerships.id = ?", id).
		Scan(&p)

	return p
}

// groupsFor lists the groups a partnership covers.
func groupsFor(id uint64) []Group {
	db := database.DBConn

	var groups []Group
	db.Table("partnerships_groups pg").
		Select("pg.groupid, g.nameshort, COALESCE(NULLIF(g.namefull, ''), g.nameshort) AS namedisplay, pg.sponsorshipid").
		Joins("INNER JOIN `groups` g ON g.id = pg.groupid").
		Where("pg.partnershipid = ?", id).
		Order("namedisplay ASC").
		Scan(&groups)

	if groups == nil {
		groups = []Group{}
	}

	return groups
}

// yearsFor returns how the deal's value falls into financial years, and whether that split
// was agreed year by year rather than worked out. Explicit rows win, so a deal with an
// uneven agreed schedule (say most of the money up front) reports what was actually agreed
// rather than a pro-rata guess.
func yearsFor(p Partnership) ([]YearAmount, bool) {
	db := database.DBConn

	var explicit []YearAmount
	db.Table("partnerships_years").
		Select("financialyear, amount").
		Where("partnershipid = ?", p.ID).
		Order("financialyear ASC").
		Scan(&explicit)

	if len(explicit) > 0 {
		for i := range explicit {
			explicit[i].Label = FinancialYearLabel(explicit[i].FinancialYear)
		}

		return explicit, true
	}

	start, errStart := time.Parse("2006-01-02", p.Startdate)
	end, errEnd := time.Parse("2006-01-02", p.Enddate)
	if errStart != nil || errEnd != nil {
		return []YearAmount{}, false
	}

	return SplitAcrossFinancialYears(start, end, p.Amount), false
}

// paymentsFor lists the invoices raised against a partnership.
func paymentsFor(id uint64) []Payment {
	db := database.DBConn

	var payments []Payment
	db.Table("partnerships_payments").
		Select("id, DATE_FORMAT(date, "+dateFmt+") AS date, amount, "+
			"DATE_FORMAT(paid, "+dateFmt+") AS paid, reference, notes").
		Where("partnershipid = ?", id).
		Order("date ASC, id ASC").
		Scan(&payments)

	if payments == nil {
		payments = []Payment{}
	}

	return payments
}

// createRequest is the body accepted by Create and (with everything optional) Update.
// Pointers distinguish "not supplied" from "set to empty", so a tagline can be cleared.
type createRequest struct {
	Authorityid  utils.FlexUint64   `json:"authorityid"`
	Name         *string            `json:"name"`
	Tagline      *string            `json:"tagline"`
	Description  *string            `json:"description"`
	Linkurl      *string            `json:"linkurl"`
	Imageurl     *string            `json:"imageurl"`
	Startdate    *string            `json:"startdate"`
	Enddate      *string            `json:"enddate"`
	Amount       *utils.FlexFloat64 `json:"amount"`
	Agreed       *bool              `json:"agreed"`
	Agreeddate   *string            `json:"agreeddate"`
	Contactname  *string            `json:"contactname"`
	Contactemail *string            `json:"contactemail"`
	Notes        *string            `json:"notes"`
	Visible      *bool              `json:"visible"`
}

// Create adds a partnership, works out which groups the authority covers, and writes the
// sponsorship rows the member site reads.
//
// @Summary Create a partnership
// @Tags partnerships
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership [post]
func Create(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	var req createRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	if req.Authorityid == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing authorityid"})
	}

	if req.Startdate == nil || req.Enddate == nil || *req.Startdate == "" || *req.Enddate == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing startdate or enddate"})
	}

	db := database.DBConn

	var authorityName string
	db.Table("authorities").Select("name").Where("id = ?", uint64(req.Authorityid)).Scan(&authorityName)
	if authorityName == "" {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Authority not found"})
	}

	// Default the display name to the council's own name - that is what a member should see.
	name := authorityName
	if req.Name != nil && *req.Name != "" {
		name = *req.Name
	}

	row := map[string]interface{}{
		"authorityid":  uint64(req.Authorityid),
		"name":         name,
		"tagline":      req.Tagline,
		"description":  req.Description,
		"linkurl":      req.Linkurl,
		"imageurl":     req.Imageurl,
		"startdate":    *req.Startdate,
		"enddate":      *req.Enddate,
		"amount":       floatOr(req.Amount, 0),
		"agreed":       boolOr(req.Agreed, false),
		"agreeddate":   nullableDate(req.Agreeddate),
		"contactname":  req.Contactname,
		"contactemail": req.Contactemail,
		"notes":        req.Notes,
		"visible":      boolOr(req.Visible, true),
	}

	if err := db.Table("partnerships").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}

	newIDInt, _ := row["@id"].(int64)
	id := uint64(newIDInt)

	detectGroups(db, id, uint64(req.Authorityid))
	syncSponsorships(db, id)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": id})
}

// Update changes a partnership and re-syncs its sponsorship rows, so editing the tagline,
// the link or the dates immediately changes what members see.
//
// @Summary Update a partnership
// @Tags partnerships
// @Accept json
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id} [patch]
func Update(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	var req createRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	db := database.DBConn

	var existingAuthority uint64
	db.Table("partnerships").Select("authorityid").Where("id = ?", id).Scan(&existingAuthority)
	if existingAuthority == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	updates := map[string]interface{}{}
	setString(updates, "name", req.Name)
	setNullableString(updates, "tagline", req.Tagline)
	setNullableString(updates, "description", req.Description)
	setNullableString(updates, "linkurl", req.Linkurl)
	setNullableString(updates, "imageurl", req.Imageurl)
	setString(updates, "startdate", req.Startdate)
	setString(updates, "enddate", req.Enddate)
	setNullableString(updates, "agreeddate", req.Agreeddate)
	setNullableString(updates, "contactname", req.Contactname)
	setNullableString(updates, "contactemail", req.Contactemail)
	setNullableString(updates, "notes", req.Notes)

	if req.Amount != nil {
		updates["amount"] = float64(*req.Amount)
	}
	if req.Agreed != nil {
		updates["agreed"] = *req.Agreed
	}
	if req.Visible != nil {
		updates["visible"] = *req.Visible
	}

	// Moving the deal to a different council changes which groups it covers.
	authorityChanged := req.Authorityid != 0 && uint64(req.Authorityid) != existingAuthority
	if authorityChanged {
		var exists int64
		db.Table("authorities").Where("id = ?", uint64(req.Authorityid)).Count(&exists)
		if exists == 0 {
			return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Authority not found"})
		}
		updates["authorityid"] = uint64(req.Authorityid)
	}

	if len(updates) > 0 {
		db.Table("partnerships").Where("id = ?", id).Updates(updates)
	}

	if authorityChanged {
		removeSponsorships(db, id)
		db.Table("partnerships_groups").Where("partnershipid = ?", id).Delete(nil)
		detectGroups(db, id, uint64(req.Authorityid))
	}

	syncSponsorships(db, id)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// Delete removes a partnership. Its sponsorship rows go too, so the council stops appearing
// on the member site straight away; years, payments and group links cascade in the schema.
//
// @Summary Delete a partnership
// @Tags partnerships
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id} [delete]
func Delete(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn
	removeSponsorships(db, id)
	db.Table("partnerships").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// Groups lists the groups a partnership covers, alongside the groups the authority's
// boundary suggests, so a mistake in the overlap can be corrected by hand.
//
// @Summary List the groups a partnership covers
// @Tags partnerships
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id}/group [get]
func Groups(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn

	var authorityid uint64
	db.Table("partnerships").Select("authorityid").Where("id = ?", id).Scan(&authorityid)
	if authorityid == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	available := []Group{}
	for _, g := range authority.GroupsForAuthority(authorityid) {
		available = append(available, Group{
			Groupid:     g.ID,
			Nameshort:   g.Nameshort,
			Namedisplay: g.Namedisplay,
		})
	}

	return c.JSON(fiber.Map{
		"ret":       0,
		"status":    "Success",
		"groups":    groupsFor(id),
		"available": available,
	})
}

// PatchGroups adds or removes a group from a partnership, or re-derives the whole list from
// the authority boundary.
//
// @Summary Change the groups a partnership covers
// @Tags partnerships
// @Accept json
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id}/group [patch]
func PatchGroups(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	var req struct {
		Action  string           `json:"action"`
		Groupid utils.FlexUint64 `json:"groupid"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	db := database.DBConn

	var authorityid uint64
	db.Table("partnerships").Select("authorityid").Where("id = ?", id).Scan(&authorityid)
	if authorityid == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	switch req.Action {
	case "Add":
		if req.Groupid == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing groupid"})
		}
		addGroup(db, id, uint64(req.Groupid))
	case "Remove":
		if req.Groupid == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing groupid"})
		}
		removeSponsorshipForGroup(db, id, uint64(req.Groupid))
		db.Table("partnerships_groups").
			Where("partnershipid = ? AND groupid = ?", id, uint64(req.Groupid)).
			Delete(nil)
	case "Redetect":
		detectGroups(db, id, authorityid)
	default:
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Unknown action"})
	}

	syncSponsorships(db, id)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "groups": groupsFor(id)})
}

// PutYears replaces the explicit financial-year split for a multi-year deal. Sending an
// empty list drops back to pro-rating the deal across its term.
//
// @Summary Set the financial-year split of a partnership
// @Tags partnerships
// @Accept json
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id}/year [put]
func PutYears(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	var req struct {
		Years []struct {
			Financialyear utils.FlexInt     `json:"financialyear"`
			Amount        utils.FlexFloat64 `json:"amount"`
		} `json:"years"`
	}
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	db := database.DBConn

	var count int64
	db.Table("partnerships").Where("id = ?", id).Count(&count)
	if count == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	db.Table("partnerships_years").Where("partnershipid = ?", id).Delete(nil)

	for _, y := range req.Years {
		if y.Financialyear == 0 {
			continue
		}
		db.Table("partnerships_years").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{
				"partnershipid": id,
				"financialyear": int(y.Financialyear),
				"amount":        float64(y.Amount),
			})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// paymentRequest is the body for creating or editing an invoice.
type paymentRequest struct {
	Date      *string            `json:"date"`
	Amount    *utils.FlexFloat64 `json:"amount"`
	Paid      *string            `json:"paid"`
	Reference *string            `json:"reference"`
	Notes     *string            `json:"notes"`
}

// CreatePayment records an invoice against a partnership.
//
// @Summary Add a payment to a partnership
// @Tags partnerships
// @Accept json
// @Produce json
// @Param id path integer true "Partnership ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id}/payment [post]
func CreatePayment(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	var req paymentRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	if req.Date == nil || *req.Date == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing date"})
	}

	db := database.DBConn

	var count int64
	db.Table("partnerships").Where("id = ?", id).Count(&count)
	if count == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	row := map[string]interface{}{
		"partnershipid": id,
		"date":          *req.Date,
		"amount":        floatOr(req.Amount, 0),
		"paid":          nullableDate(req.Paid),
		"reference":     req.Reference,
		"notes":         req.Notes,
	}

	if err := db.Table("partnerships_payments").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}

	newIDInt, _ := row["@id"].(int64)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": uint64(newIDInt)})
}

// UpdatePayment edits an invoice - most often to mark it paid.
//
// @Summary Update a payment
// @Tags partnerships
// @Accept json
// @Produce json
// @Param id path integer true "Partnership ID"
// @Param paymentid path integer true "Payment ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id}/payment/{paymentid} [patch]
func UpdatePayment(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	paymentid, _ := strconv.ParseUint(c.Params("paymentid"), 10, 64)
	if id == 0 || paymentid == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	var req paymentRequest
	if err := c.BodyParser(&req); err != nil {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid body"})
	}

	db := database.DBConn

	var count int64
	db.Table("partnerships_payments").Where("id = ? AND partnershipid = ?", paymentid, id).Count(&count)
	if count == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Not found"})
	}

	updates := map[string]interface{}{}
	setString(updates, "date", req.Date)
	setNullableString(updates, "reference", req.Reference)
	setNullableString(updates, "notes", req.Notes)

	if req.Amount != nil {
		updates["amount"] = float64(*req.Amount)
	}
	// An empty paid date means "not paid after all", so it must clear the column.
	if req.Paid != nil {
		updates["paid"] = nullableDate(req.Paid)
	}

	if len(updates) > 0 {
		db.Table("partnerships_payments").Where("id = ?", paymentid).Updates(updates)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeletePayment removes an invoice.
//
// @Summary Delete a payment
// @Tags partnerships
// @Produce json
// @Param id path integer true "Partnership ID"
// @Param paymentid path integer true "Payment ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/{id}/payment/{paymentid} [delete]
func DeletePayment(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)
	paymentid, _ := strconv.ParseUint(c.Params("paymentid"), 10, 64)
	if id == 0 || paymentid == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Missing id"})
	}

	db := database.DBConn
	db.Table("partnerships_payments").Where("id = ? AND partnershipid = ?", paymentid, id).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// Summary totals the partnership income and splits it by financial year, which is what the
// page's headline figures and its income graph are drawn from.
//
// @Summary Partnership income totals and financial-year split
// @Tags partnerships
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/partnership/summary [get]
func Summary(c *fiber.Ctx) error {
	if _, err := requireUser(c); err != nil {
		return err
	}

	db := database.DBConn

	var partnerships []Partnership
	db.Table("partnerships").
		Select(selectList(), ExpiryWarningDays).
		Joins("INNER JOIN authorities ON authorities.id = partnerships.authorityid").
		Scan(&partnerships)

	// Aggregate the per-partnership splits into one row per financial year. Agreed and
	// unagreed money is kept apart so a pipeline of hoped-for deals never flatters the
	// income figures.
	type bucket struct {
		agreed   float64
		pipeline float64
	}
	buckets := map[int]*bucket{}

	var total, agreedTotal, invoiced, paid float64
	active, expiring := 0, 0

	for _, p := range partnerships {
		total += p.Amount
		invoiced += p.Invoiced
		paid += p.Paid

		if p.Agreed {
			agreedTotal += p.Amount
		}
		if !p.Expired {
			active++
		}
		if p.Expiring {
			expiring++
		}

		years, _ := yearsFor(p)
		for _, y := range years {
			b, ok := buckets[y.FinancialYear]
			if !ok {
				b = &bucket{}
				buckets[y.FinancialYear] = b
			}
			if p.Agreed {
				b.agreed += y.Amount
			} else {
				b.pipeline += y.Amount
			}
		}
	}

	// One row per financial year, oldest first, so the graph reads left to right.
	fys := make([]int, 0, len(buckets))
	for fy := range buckets {
		fys = append(fys, fy)
	}
	sort.Ints(fys)

	years := make([]fiber.Map, 0, len(fys))
	for _, fy := range fys {
		years = append(years, fiber.Map{
			"financialyear": fy,
			"label":         FinancialYearLabel(fy),
			"agreed":        round2(buckets[fy].agreed),
			"pipeline":      round2(buckets[fy].pipeline),
			"total":         round2(buckets[fy].agreed + buckets[fy].pipeline),
		})
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"summary": fiber.Map{
			"count":       len(partnerships),
			"active":      active,
			"expiring":    expiring,
			"total":       round2(total),
			"agreed":      round2(agreedTotal),
			"invoiced":    round2(invoiced),
			"paid":        round2(paid),
			"outstanding": round2(invoiced - paid),
			"years":       years,
		},
	})
}

// detectGroups fills partnerships_groups from the authority's boundary overlap. Groups added
// by hand from outside the boundary are left alone; a group removed by hand that is still
// inside the boundary does come back, which is the point of asking for a re-detect.
func detectGroups(db *gorm.DB, partnershipid uint64, authorityid uint64) {
	for _, g := range authority.GroupsForAuthority(authorityid) {
		addGroup(db, partnershipid, g.ID)
	}
}

// addGroup links a group to a partnership, ignoring a group that is already linked.
func addGroup(db *gorm.DB, partnershipid uint64, groupid uint64) {
	db.Table("partnerships_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).
		Create(map[string]interface{}{
			"partnershipid": partnershipid,
			"groupid":       groupid,
		})
}

// syncSponsorships writes one groups_sponsorship row per covered group, so the member site
// shows the council as a sponsor of every group the deal covers.
//
// The row is only visible while the deal is agreed: an unagreed deal is a conversation, not
// something to advertise.
func syncSponsorships(db *gorm.DB, partnershipid uint64) {
	var p struct {
		Name         string
		Tagline      *string
		Description  *string
		Linkurl      *string
		Imageurl     *string
		Startdate    string
		Enddate      string
		Amount       float64
		Agreed       bool
		Contactname  *string
		Contactemail *string
		Notes        *string
		Visible      bool
	}

	db.Table("partnerships").
		Select("name, tagline, description, linkurl, imageurl, "+
			"DATE_FORMAT(startdate, "+dateFmt+") AS startdate, DATE_FORMAT(enddate, "+dateFmt+") AS enddate, "+
			"amount, agreed, contactname, contactemail, notes, visible").
		Where("id = ?", partnershipid).
		Scan(&p)

	if p.Name == "" {
		return
	}

	// groups_sponsorship requires a contact, and orders sponsors by amount.
	fields := map[string]interface{}{
		"name":         p.Name,
		"linkurl":      p.Linkurl,
		"startdate":    p.Startdate,
		"enddate":      p.Enddate,
		"contactname":  stringOr(p.Contactname, ""),
		"contactemail": stringOr(p.Contactemail, ""),
		"amount":       int(p.Amount),
		"notes":        p.Notes,
		"imageurl":     p.Imageurl,
		"visible":      p.Visible && p.Agreed,
		"tagline":      p.Tagline,
		"description":  p.Description,
	}

	var links []struct {
		Groupid       uint64
		Sponsorshipid *uint64
	}
	db.Table("partnerships_groups").
		Select("groupid, sponsorshipid").
		Where("partnershipid = ?", partnershipid).
		Scan(&links)

	for _, link := range links {
		if link.Sponsorshipid != nil {
			db.Table("groups_sponsorship").Where("id = ?", *link.Sponsorshipid).Updates(fields)

			continue
		}

		row := map[string]interface{}{"groupid": link.Groupid}
		for k, v := range fields {
			row[k] = v
		}

		if err := db.Table("groups_sponsorship").Create(row).Error; err != nil {
			continue
		}

		newIDInt, _ := row["@id"].(int64)
		db.Table("partnerships_groups").
			Where("partnershipid = ? AND groupid = ?", partnershipid, link.Groupid).
			Update("sponsorshipid", uint64(newIDInt))
	}
}

// sponsorshipIDs is the subquery of the sponsorship rows a partnership created.
func sponsorshipIDs(db *gorm.DB, partnershipid uint64) *gorm.DB {
	return db.Table("partnerships_groups").
		Select("sponsorshipid").
		Where("partnershipid = ? AND sponsorshipid IS NOT NULL", partnershipid)
}

// removeSponsorships deletes every groups_sponsorship row this partnership created.
func removeSponsorships(db *gorm.DB, partnershipid uint64) {
	db.Table("groups_sponsorship").
		Where("id IN (?)", sponsorshipIDs(db, partnershipid)).
		Delete(nil)
}

// removeSponsorshipForGroup drops the sponsorship for one group leaving a partnership.
func removeSponsorshipForGroup(db *gorm.DB, partnershipid uint64, groupid uint64) {
	db.Table("groups_sponsorship").
		Where("id IN (?)", sponsorshipIDs(db, partnershipid).Where("groupid = ?", groupid)).
		Delete(nil)
}

// --- small helpers -------------------------------------------------------------------

func setString(updates map[string]interface{}, column string, v *string) {
	if v != nil && *v != "" {
		updates[column] = *v
	}
}

// setNullableString lets an empty string clear the column, which is how the UI removes a
// tagline or a link.
func setNullableString(updates map[string]interface{}, column string, v *string) {
	if v == nil {
		return
	}

	if *v == "" {
		updates[column] = nil

		return
	}

	updates[column] = *v
}

func nullableDate(v *string) interface{} {
	if v == nil || *v == "" {
		return nil
	}

	return *v
}

func stringOr(v *string, fallback string) string {
	if v == nil {
		return fallback
	}

	return *v
}

func floatOr(v *utils.FlexFloat64, fallback float64) float64 {
	if v == nil {
		return fallback
	}

	return float64(*v)
}

func boolOr(v *bool, fallback bool) bool {
	if v == nil {
		return fallback
	}

	return *v
}
