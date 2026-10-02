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
	"github.com/freegle/iznik-server-go/misc"
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

// A deal moves through these statuses. Only a committed one (Confirmed, Paid or Overdue) is
// shown to members: we show a deal as soon as the council confirms it rather than waiting
// for the money, because councils often pay many months late.
const (
	StatusQuoted      = "Quoted"
	StatusInPrinciple = "InPrinciple"
	StatusConfirmed   = "Confirmed"
	StatusPaid        = "Paid"
	StatusOverdue     = "Overdue"
)

var statuses = map[string]bool{
	StatusQuoted: true, StatusInPrinciple: true, StatusConfirmed: true, StatusPaid: true, StatusOverdue: true,
}

// committedSQL is the SQL test for a committed deal, matching Committed.
const committedSQL = "partnerships.status IN ('Confirmed', 'Paid', 'Overdue')"

// Committed reports whether a deal's status means the council has signed up to it.
func Committed(status string) bool {
	return status == StatusConfirmed || status == StatusPaid || status == StatusOverdue
}

// How likely a council is to renew, shown as a traffic light.
var renewals = map[string]bool{"Likely": true, "Unsure": true, "Unlikely": true}

// Contact roles. Everyone gets the statistics; the role says who to ask about what.
var contactRoles = map[string]bool{"Waste": true, "Finance": true, "Other": true}

// Where a covered community came from. A Removed row is a community inside the boundary
// that was left out by hand; it is kept so that re-checking the boundary does not put it
// back, and it gets no sponsorship.
const (
	SourceBoundary = "Boundary"
	SourceAdded    = "Added"
	SourceRemoved  = "Removed"
)

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
	Status        string  `json:"status"`
	Renewal       *string `json:"renewal"`
	// The price before any bulk discount, when we gave one.
	Fullprice *float64 `json:"fullprice"`
	Notes     *string  `json:"notes"`
	Visible   bool     `json:"visible"`

	// Derived, for the list view.
	Groupcount int     `json:"groupcount"`
	Invoiced   float64 `json:"invoiced"`
	Paid       float64 `json:"paid"`
	Expired    bool    `json:"expired"`
	Expiring   bool    `json:"expiring"`
}

// Contact is someone at the council we deal with.
type Contact struct {
	ID    uint64  `json:"id"`
	Name  *string `json:"name"`
	Email *string `json:"email"`
	Role  string  `json:"role"`
}

// Group is one Freegle group covered by a partnership, or left out of it by hand.
type Group struct {
	Groupid     uint64 `json:"groupid"`
	Nameshort   string `json:"nameshort"`
	Namedisplay string `json:"namedisplay"`
	Source      string `json:"source"`
	// Fraction of the group inside the council boundary; null for a group outside it.
	Overlap       *float64 `json:"overlap"`
	Sponsorshipid *uint64  `json:"sponsorshipid"`
}

// HistoryEntry is one deal with the same council, for the history list.
type HistoryEntry struct {
	ID        uint64  `json:"id"`
	Name      string  `json:"name"`
	Startdate string  `json:"startdate"`
	Enddate   string  `json:"enddate"`
	Amount    float64 `json:"amount"`
	Status    string  `json:"status"`
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
// caller's id, or an error for the handler to return straight back.
//
// These must be real errors rather than c.Status(...).JSON(...): that writes a response but
// returns nil, so the caller's `err != nil` check passes and the handler carries on and
// overwrites the refusal with the data it was meant to be withholding.
func requireUser(c *fiber.Ctx) (uint64, error) {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return 0, fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !CanUse(myid) {
		return 0, fiber.NewError(fiber.StatusForbidden, "Permission denied")
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
		"partnerships.status, partnerships.renewal, partnerships.fullprice, " +
		"partnerships.notes, partnerships.visible, " +
		"(SELECT COUNT(*) FROM partnerships_groups pg WHERE pg.partnershipid = partnerships.id AND pg.source != 'Removed') AS groupcount, " +
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
		"contacts":    contactsFor(id),
		"groups":      groupsFor(id),
		"years":       years,
		// Whether the split above was agreed year by year or worked out pro-rata, so the
		// page can say which it is showing.
		"explicityears": explicit,
		"payments":      paymentsFor(id),
		"history":       historyFor(p),
	})
}

// contactsFor lists the people at the council, in the order they were added.
func contactsFor(id uint64) []Contact {
	db := database.DBConn

	var contacts []Contact
	db.Table("partnerships_contacts").
		Select("id, name, email, role").
		Where("partnershipid = ?", id).
		Order("id ASC").
		Scan(&contacts)

	if contacts == nil {
		contacts = []Contact{}
	}

	return contacts
}

// historyFor lists every deal we have had with the same council, this one included, newest
// first - when they have sponsored us and when they have not.
func historyFor(p Partnership) []HistoryEntry {
	db := database.DBConn

	var history []HistoryEntry
	db.Table("partnerships").
		Select("id, name, DATE_FORMAT(startdate, "+dateFmt+") AS startdate, "+
			"DATE_FORMAT(enddate, "+dateFmt+") AS enddate, amount, status").
		Where("authorityid = ?", p.Authorityid).
		Order("startdate DESC, id DESC").
		Scan(&history)

	if history == nil {
		history = []HistoryEntry{}
	}

	return history
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

// groupsFor lists the groups a partnership covers, and those left out of it by hand.
func groupsFor(id uint64) []Group {
	db := database.DBConn

	var groups []Group
	db.Table("partnerships_groups pg").
		Select("pg.groupid, g.nameshort, COALESCE(NULLIF(g.namefull, ''), g.nameshort) AS namedisplay, "+
			"pg.source, pg.overlap, pg.sponsorshipid").
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
	Authorityid utils.FlexUint64 `json:"authorityid"`
	Name        *string          `json:"name"`
	Tagline     *string          `json:"tagline"`
	Description *string          `json:"description"`
	Linkurl     *string          `json:"linkurl"`
	Imageurl    *string          `json:"imageurl"`
	// An uploaded logo: the id of the image row the uploader created. It is turned into a
	// delivery URL and stored as imageurl.
	Imageid   utils.FlexUint64   `json:"imageid"`
	Startdate *string            `json:"startdate"`
	Enddate   *string            `json:"enddate"`
	Amount    *utils.FlexFloat64 `json:"amount"`
	Status    *string            `json:"status"`
	// An empty string clears the traffic light.
	Renewal *string `json:"renewal"`
	// Zero clears it: there was no bulk discount.
	Fullprice *utils.FlexFloat64 `json:"fullprice"`
	Notes     *string            `json:"notes"`
	Visible   *bool              `json:"visible"`
	// Replaces the whole contact list when present.
	Contacts *[]contactRequest `json:"contacts"`
	// Only on create: communities inside the boundary to leave out, and communities outside
	// it to add, chosen before the deal was saved.
	Excludegroupids []utils.FlexUint64 `json:"excludegroupids"`
	Includegroupids []utils.FlexUint64 `json:"includegroupids"`
}

type contactRequest struct {
	Name  string `json:"name"`
	Email string `json:"email"`
	Role  string `json:"role"`
}

// validate checks the enumerated fields, returning a message for the caller or "".
func (req *createRequest) validate() string {
	if req.Status != nil && !statuses[*req.Status] {
		return "Unknown status"
	}

	if req.Renewal != nil && *req.Renewal != "" && !renewals[*req.Renewal] {
		return "Unknown renewal"
	}

	if req.Contacts != nil {
		for _, ct := range *req.Contacts {
			if ct.Role != "" && !contactRoles[ct.Role] {
				return "Unknown contact role"
			}
		}
	}

	return ""
}

// uploadedImageURL turns the id of an uploaded logo into the URL members' browsers fetch
// it from. The uploader files a logo as an unattached group image.
func uploadedImageURL(db *gorm.DB, imageid uint64) string {
	var img struct {
		Externaluid  *string
		Externalmods *string
	}
	db.Table("groups_images").Select("externaluid, externalmods").Where("id = ?", imageid).Scan(&img)

	if img.Externaluid == nil || *img.Externaluid == "" {
		return ""
	}

	return misc.GetImageDeliveryUrl(*img.Externaluid, stringOr(img.Externalmods, ""))
}

// saveContacts replaces a partnership's contacts. Rows with neither a name nor an email are
// dropped, so a blank line left in the form does not become a contact.
func saveContacts(db *gorm.DB, id uint64, contacts []contactRequest) {
	db.Table("partnerships_contacts").Where("partnershipid = ?", id).Delete(nil)

	for _, ct := range contacts {
		if ct.Name == "" && ct.Email == "" {
			continue
		}

		role := ct.Role
		if role == "" {
			role = "Waste"
		}

		db.Table("partnerships_contacts").Create(map[string]interface{}{
			"partnershipid": id,
			"name":          utils.NilIfEmpty(ct.Name),
			"email":         utils.NilIfEmpty(ct.Email),
			"role":          role,
		})
	}
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

	if msg := req.validate(); msg != "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": msg})
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

	status := StatusQuoted
	if req.Status != nil {
		status = *req.Status
	}

	var imageurl interface{} = req.Imageurl
	if req.Imageid != 0 {
		if url := uploadedImageURL(db, uint64(req.Imageid)); url != "" {
			imageurl = url
		}
	}

	row := map[string]interface{}{
		"authorityid": uint64(req.Authorityid),
		"name":        name,
		"tagline":     req.Tagline,
		"description": req.Description,
		"linkurl":     req.Linkurl,
		"imageurl":    imageurl,
		"startdate":   *req.Startdate,
		"enddate":     *req.Enddate,
		"amount":      floatOr(req.Amount, 0),
		"status":      status,
		"renewal":     nullableString(req.Renewal),
		"fullprice":   nullablePositive(req.Fullprice),
		"notes":       req.Notes,
		"visible":     boolOr(req.Visible, true),
	}

	if err := db.Table("partnerships").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}

	newIDInt, _ := row["@id"].(int64)
	id := uint64(newIDInt)

	if req.Contacts != nil {
		saveContacts(db, id, *req.Contacts)
	}

	detectGroups(db, id, uint64(req.Authorityid))

	for _, gid := range req.Excludegroupids {
		removeGroup(db, id, uint64(gid))
	}

	for _, gid := range req.Includegroupids {
		addGroup(db, id, uint64(gid))
	}

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

	if msg := req.validate(); msg != "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": msg})
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
	setString(updates, "status", req.Status)
	setNullableString(updates, "renewal", req.Renewal)
	setNullableString(updates, "notes", req.Notes)

	if req.Imageid != 0 {
		if url := uploadedImageURL(db, uint64(req.Imageid)); url != "" {
			updates["imageurl"] = url
		}
	}

	if req.Amount != nil {
		updates["amount"] = float64(*req.Amount)
	}
	if req.Fullprice != nil {
		updates["fullprice"] = nullablePositive(req.Fullprice)
	}
	if req.Visible != nil {
		updates["visible"] = *req.Visible
	}

	if req.Contacts != nil {
		saveContacts(db, id, *req.Contacts)
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

// PatchGroups adds a group to a partnership (or puts back one that was left out), leaves one
// out, or re-checks the list against the authority boundary. Any group can be added, not
// just those inside the boundary: a council sometimes sponsors a neighbouring community.
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
		removeGroup(db, id, uint64(req.Groupid))
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

	// The headline boxes follow the pipeline a deal moves along: quoted, agreed in principle,
	// then committed (confirmed, paid or overdue). Money received comes from the invoices.
	// Each stage is kept apart in the per-year split too, so hoped-for deals never flatter
	// the income figures.
	type bucket struct {
		quoted      float64
		inprinciple float64
		committed   float64
	}
	buckets := map[int]*bucket{}

	var quoted, inPrinciple, committed, overdue, invoiced, received float64
	active, expiring := 0, 0

	for _, p := range partnerships {
		invoiced += p.Invoiced
		received += p.Paid

		switch {
		case p.Status == StatusQuoted:
			quoted += p.Amount
		case p.Status == StatusInPrinciple:
			inPrinciple += p.Amount
		case Committed(p.Status):
			committed += p.Amount
		}
		if p.Status == StatusOverdue {
			overdue += p.Amount
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
			switch {
			case p.Status == StatusQuoted:
				b.quoted += y.Amount
			case p.Status == StatusInPrinciple:
				b.inprinciple += y.Amount
			default:
				b.committed += y.Amount
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
		b := buckets[fy]
		years = append(years, fiber.Map{
			"financialyear": fy,
			"label":         FinancialYearLabel(fy),
			"quoted":        round2(b.quoted),
			"inprinciple":   round2(b.inprinciple),
			"committed":     round2(b.committed),
			"total":         round2(b.quoted + b.inprinciple + b.committed),
		})
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"summary": fiber.Map{
			"count":       len(partnerships),
			"active":      active,
			"expiring":    expiring,
			"quoted":      round2(quoted),
			"inprinciple": round2(inPrinciple),
			"committed":   round2(committed),
			"overdue":     round2(overdue),
			"invoiced":    round2(invoiced),
			"received":    round2(received),
			// Committed money we have not yet received, whether or not it has been invoiced.
			"tocome": round2(committed - received),
			"years":  years,
		},
	})
}

// detectGroups brings partnerships_groups into line with the authority boundary: a new
// community inside it is covered, a Boundary community no longer inside it is dropped, and
// every row's overlap is refreshed. Communities added or left out by hand stay as they are,
// so re-checking never undoes a decision someone made.
func detectGroups(db *gorm.DB, partnershipid uint64, authorityid uint64) {
	inside := map[uint64]float64{}
	for _, g := range authority.GroupsForAuthority(authorityid) {
		inside[g.ID] = g.Overlap
	}

	var rows []struct {
		Groupid uint64
		Source  string
	}
	db.Table("partnerships_groups").Select("groupid, source").
		Where("partnershipid = ?", partnershipid).Scan(&rows)

	have := map[uint64]bool{}
	for _, r := range rows {
		have[r.Groupid] = true
		overlap, ok := inside[r.Groupid]

		if !ok {
			if r.Source == SourceBoundary {
				removeSponsorshipForGroup(db, partnershipid, r.Groupid)
				db.Table("partnerships_groups").
					Where("partnershipid = ? AND groupid = ?", partnershipid, r.Groupid).Delete(nil)
			} else {
				setOverlap(db, partnershipid, r.Groupid, nil)
			}

			continue
		}

		setOverlap(db, partnershipid, r.Groupid, &overlap)
	}

	for gid, overlap := range inside {
		if !have[gid] {
			db.Table("partnerships_groups").Create(map[string]interface{}{
				"partnershipid": partnershipid,
				"groupid":       gid,
				"source":        SourceBoundary,
				"overlap":       overlap,
			})
		}
	}
}

func setOverlap(db *gorm.DB, partnershipid uint64, groupid uint64, overlap *float64) {
	var v interface{}
	if overlap != nil {
		v = *overlap
	}

	db.Table("partnerships_groups").
		Where("partnershipid = ? AND groupid = ?", partnershipid, groupid).
		Update("overlap", v)
}

// addGroup covers a group: a new row for a group added by hand, or putting back one that was
// left out. A group inside the boundary goes back to being a Boundary row; one outside it is
// Added, and counts in full in the statistics.
func addGroup(db *gorm.DB, partnershipid uint64, groupid uint64) {
	var existing struct {
		Source  string
		Overlap *float64
	}
	db.Table("partnerships_groups").Select("source, overlap").
		Where("partnershipid = ? AND groupid = ?", partnershipid, groupid).Scan(&existing)

	source := SourceAdded
	if existing.Overlap != nil {
		source = SourceBoundary
	}

	if existing.Source != "" {
		if existing.Source == SourceRemoved {
			db.Table("partnerships_groups").
				Where("partnershipid = ? AND groupid = ?", partnershipid, groupid).
				Update("source", source)
		}

		return
	}

	db.Table("partnerships_groups").Create(map[string]interface{}{
		"partnershipid": partnershipid,
		"groupid":       groupid,
		"source":        source,
	})
}

// removeGroup leaves a group out. One added by hand simply goes; one inside the boundary is
// kept as Removed so that the next boundary check does not bring it back.
func removeGroup(db *gorm.DB, partnershipid uint64, groupid uint64) {
	removeSponsorshipForGroup(db, partnershipid, groupid)

	var source string
	db.Table("partnerships_groups").Select("source").
		Where("partnershipid = ? AND groupid = ?", partnershipid, groupid).Scan(&source)

	row := db.Table("partnerships_groups").Where("partnershipid = ? AND groupid = ?", partnershipid, groupid)

	switch source {
	case SourceAdded:
		row.Delete(nil)
	case SourceBoundary:
		row.Updates(map[string]interface{}{"source": SourceRemoved, "sponsorshipid": nil})
	}
}

// syncSponsorships writes one groups_sponsorship row per covered group, so the member site
// shows the council as a sponsor of every group the deal covers.
//
// The row is only visible once the deal is committed: a quote or an agreement in principle
// is a conversation, not something to advertise.
func syncSponsorships(db *gorm.DB, partnershipid uint64) {
	var p struct {
		Name        string
		Tagline     *string
		Description *string
		Linkurl     *string
		Imageurl    *string
		Startdate   string
		Enddate     string
		Amount      float64
		Status      string
		Notes       *string
		Visible     bool
	}

	db.Table("partnerships").
		Select("name, tagline, description, linkurl, imageurl, "+
			"DATE_FORMAT(startdate, "+dateFmt+") AS startdate, DATE_FORMAT(enddate, "+dateFmt+") AS enddate, "+
			"amount, status, notes, visible").
		Where("id = ?", partnershipid).
		Scan(&p)

	if p.Name == "" {
		return
	}

	// groups_sponsorship has room for a single contact; the first waste-team contact is the
	// one who would want to hear about the sponsorship.
	var contact struct {
		Name  *string
		Email *string
	}
	db.Table("partnerships_contacts").Select("name, email").
		Where("partnershipid = ?", partnershipid).
		Order("role = 'Waste' DESC, id ASC").Limit(1).Scan(&contact)

	// groups_sponsorship requires a contact, and orders sponsors by amount.
	fields := map[string]interface{}{
		"name":         p.Name,
		"linkurl":      p.Linkurl,
		"startdate":    p.Startdate,
		"enddate":      p.Enddate,
		"contactname":  stringOr(contact.Name, ""),
		"contactemail": stringOr(contact.Email, ""),
		"amount":       int(p.Amount),
		"notes":        p.Notes,
		"imageurl":     p.Imageurl,
		"visible":      p.Visible && Committed(p.Status),
		"tagline":      p.Tagline,
		"description":  p.Description,
	}

	var links []struct {
		Groupid       uint64
		Sponsorshipid *uint64
	}
	db.Table("partnerships_groups").
		Select("groupid, sponsorshipid").
		Where("partnershipid = ? AND source != ?", partnershipid, SourceRemoved).
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

// nullableString stores an absent or empty string as NULL.
func nullableString(v *string) interface{} {
	return nullableDate(v)
}

// nullablePositive stores an absent or non-positive amount as NULL.
func nullablePositive(v *utils.FlexFloat64) interface{} {
	if v == nil || float64(*v) <= 0 {
		return nil
	}

	return float64(*v)
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
