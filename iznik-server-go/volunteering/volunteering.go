package volunteering

import (
	"errors"
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	"html"
	"log"
	"os"
	"strconv"
	"sync"
	"time"
)

func (Volunteering) TableName() string {
	return "volunteering"
}

type Volunteering struct {
	ID             uint64             `json:"id" gorm:"primary_key"`
	Userid         uint64             `json:"userid"`
	Pending        bool               `json:"pending"`
	Heldby         *uint64            `json:"heldby"`
	Title          string             `json:"title"`
	Location       string             `json:"location"`
	Contactname    string             `json:"contactname"`
	Contactphone   string             `json:"contactphone"`
	Contactemail   string             `json:"contactemail"`
	Contacturl     string             `json:"contacturl"`
	Description    string             `json:"description"`
	Timecommitment string             `json:"timecommitment"`
	Added          time.Time          `json:"added"`
	Groups         []uint64           `json:"groups"  gorm:"-"`
	Image          *VolunteeringImage `json:"image" gorm:"-"`
	Dates          []VolunteeringDate `json:"dates" gorm:"-"`
	Expired        bool               `json:"expired"`
	Canmodify      bool               `json:"canmodify" gorm:"-"`

	// Renewed is when the owner last confirmed the opportunity is still active. The
	// client needs it to know whether a confirmation is due, so that it only asks when
	// the batch renewal clock says so rather than on every visit.
	Renewed *time.Time `json:"renewed"`
}

// listLimit caps how many opportunities a member's list returns. National ops are taken
// first, so a very large number of them could in principle crowd out local ones - there
// are none live today, and any that appear are central Freegle asks worth seeing.
const listLimit = 20

func List(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn
	pending := c.Query("pending") == "true"

	memberships := user.GetMemberships(myid)
	var groupids []uint64
	for _, membership := range memberships {
		groupids = append(groupids, membership.Groupid)
	}

	var ids []uint64

	if pending {
		// Return only pending volunteering visible to this active moderator/admin.
		// Use GetActiveModGroupIDs to exclude backup mods.
		modGroupIDs := user.GetActiveModGroupIDs(myid)

		seen := make(map[uint64]bool)

		if len(modGroupIDs) > 0 {
			var groupIds []uint64
			db.Table("volunteering").
				Select("DISTINCT volunteering.id").
				Joins("INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
				Where("groupid IN (?) AND volunteering.deleted = 0 AND pending = 1", modGroupIDs).
				Order("id DESC").
				Pluck("id", &groupIds)
			for _, id := range groupIds {
				if !seen[id] {
					seen[id] = true
					ids = append(ids, id)
				}
			}
		}

		// Mirrors V1 User::getWorkCounts (User.php:6651-6656): users with
		// PERM_NATIONAL_VOLUNTEERS see pending national ops (no volunteering_groups
		// row, i.e. groupid IS NULL) in addition to their per-group ones.
		if auth.HasPermission(myid, auth.PERM_NATIONAL_VOLUNTEERS) {
			var nationalIds []uint64
			db.Table("volunteering").
				Select("volunteering.id").
				Joins("LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
				Where("volunteering_groups.groupid IS NULL AND volunteering.deleted = 0 AND pending = 1").
				Order("volunteering.id DESC").
				Pluck("id", &nationalIds)
			for _, id := range nationalIds {
				if !seen[id] {
					seen[id] = true
					ids = append(ids, id)
				}
			}
		}
	} else {
		start := time.Now().Format("2006-01-02")
		seen := make(map[uint64]bool)

		// National opportunities have NO volunteering_groups row (that absence is what makes
		// them national), so the group query below can never find them - they were missing
		// from the list entirely. Fetch them separately and put them FIRST: they apply
		// whoever is looking, whereas ordering everything by id DESC would bury them under
		// whatever local ops happen to be newer.
		var nationalIds []uint64
		db.Table("volunteering").
			Select("DISTINCT volunteering.id").
			Joins("LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
			Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
			Joins("LEFT JOIN users ON volunteering.userid = users.id").
			Where("volunteering_groups.groupid IS NULL AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND (pending = 0 OR volunteering.userid = ?) AND users.deleted IS NULL",
				start, start, myid).
			Order("volunteering.id DESC").
			Limit(listLimit).
			Pluck("id", &nationalIds)

		for _, id := range nationalIds {
			if !seen[id] {
				seen[id] = true
				ids = append(ids, id)
			}
		}

		if len(groupids) > 0 && len(ids) < listLimit {
			var groupOpIds []uint64
			db.Table("volunteering").
				Select("DISTINCT volunteering.id").
				Joins("INNER JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
				Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
				Joins("LEFT JOIN users ON volunteering.userid = users.id").
				Where("groupid IN (?) AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND (pending = 0 OR volunteering.userid = ?) AND users.deleted IS NULL",
					groupids, start, start, myid).
				Order("id DESC").
				Limit(listLimit).
				Pluck("id", &groupOpIds)

			for _, id := range groupOpIds {
				if len(ids) >= listLimit {
					break
				}
				if !seen[id] {
					seen[id] = true
					ids = append(ids, id)
				}
			}
		}
	}

	if len(ids) > 0 {
		return c.JSON(ids)
	} else {
		return c.JSON(make([]string, 0))
	}
}

func ListGroup(c *fiber.Ctx) error {
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)

	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid groupid")
	}

	db := database.DBConn

	var ids []uint64

	start := time.Now().Format("2006-01-02")

	db.Table("volunteering").
		Select("DISTINCT volunteering.id").
		Joins("LEFT JOIN volunteering_groups ON volunteering.id = volunteering_groups.volunteeringid").
		Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
		Joins("LEFT JOIN users ON volunteering.userid = users.id").
		Where("groupid = ? AND (applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND pending = 0 AND users.deleted IS NULL",
			id, start, start).
		Order("id DESC").
		Pluck("volunteeringid", &ids)

	if len(ids) > 0 {
		return c.JSON(ids)
	} else {
		// Force [] rather than null to be returned.
		return c.JSON(make([]string, 0))
	}
}

func Single(c *fiber.Ctx) error {
	var wg sync.WaitGroup
	var volunteering Volunteering
	var image VolunteeringImage
	var found bool
	var groups []uint64
	var dates []VolunteeringDate
	archiveDomain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	imageDomain := os.Getenv("IMAGE_DOMAIN")

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)

	if err == nil {
		db := database.DBConn

		wg.Add(1)

		go func() {
			defer wg.Done()

			// Can always fetch a single one if we know the id, even if it's pending or held.
			err := db.Where("id = ? AND deleted = 0", id).First(&volunteering).Error
			found = !errors.Is(err, gorm.ErrRecordNotFound)
		}()

		wg.Add(1)

		go func() {
			defer wg.Done()

			db.Table("volunteering_images").Select("id, archived, externaluid, externalmods").
				Where("opportunityid = ?", id).Order("id DESC").Limit(1).Scan(&image)

			if image.ID > 0 {
				if image.Externaluid != "" {
					image.Ouruid = image.Externaluid
					image.Path = misc.GetImageDeliveryUrl(image.Externaluid, string(image.Externalmods))
					image.Paththumb = misc.GetImageDeliveryUrl(image.Externaluid, string(image.Externalmods))
					image.Externaluid = ""
				} else if image.Archived > 0 {
					image.Path = "https://" + archiveDomain + "/oimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
					image.Paththumb = "https://" + archiveDomain + "/toimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
				} else {
					image.Path = "https://" + imageDomain + "/oimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
					image.Paththumb = "https://" + imageDomain + "/toimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
				}
			}
		}()

		wg.Add(1)

		go func() {
			defer wg.Done()

			db.Table("volunteering_groups").Where("volunteeringid = ?", id).Pluck("groupid", &groups)
		}()

		wg.Add(1)

		go func() {
			defer wg.Done()

			db.Table("volunteering_dates").Where("volunteeringid = ?", id).Scan(&dates)
		}()

		wg.Wait()

		if found {
			if image.ID > 0 {
				volunteering.Image = &image
			}

			if groups == nil {
				groups = make([]uint64, 0)
			}
			volunteering.Groups = groups

			if dates == nil {
				dates = make([]VolunteeringDate, 0)
			}
			volunteering.Dates = dates

			// Decode HTML entities in text fields
			volunteering.Title = html.UnescapeString(volunteering.Title)
			volunteering.Description = html.UnescapeString(volunteering.Description)
			volunteering.Location = html.UnescapeString(volunteering.Location)
			volunteering.Contactname = html.UnescapeString(volunteering.Contactname)
			volunteering.Contacturl = html.UnescapeString(volunteering.Contacturl)
			volunteering.Timecommitment = html.UnescapeString(volunteering.Timecommitment)

			myid := user.WhoAmI(c)
			if myid > 0 {
				volunteering.Canmodify = canModify(myid, volunteering.ID)
			}

			return c.JSON(volunteering)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "Not found")
}

// canModify checks if a user can modify a volunteering opportunity.
// They can if they created it, are admin/support, or are a moderator/owner of a group
// the volunteering is linked to.
func canModify(myid uint64, volunteeringID uint64) bool {
	db := database.DBConn

	var ownerID *uint64
	db.Table("volunteering").Select("userid").Where("id = ?", volunteeringID).Scan(&ownerID)

	if ownerID != nil && *ownerID == myid {
		return true
	}

	return isModerator(myid, volunteeringID)
}

// isModerator checks if a user is a moderator who can hold/release volunteering opportunities.
func isModerator(myid uint64, volunteeringID uint64) bool {
	if user.IsAdminOrSupport(myid) {
		return true
	}

	// Single query to check if user is moderator/owner of any linked group.
	db := database.DBConn
	var count int64
	db.Table("memberships m").
		Select("COUNT(*)").
		Joins("INNER JOIN volunteering_groups vg ON vg.groupid = m.groupid").
		Where("vg.volunteeringid = ? AND m.userid = ? AND m.collection = ? AND m.role IN (?, ?)",
			volunteeringID, myid, utils.COLLECTION_APPROVED, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Scan(&count)

	return count > 0
}

// isMemberOfGroup checks if a user has an approved membership in the given group.
func isMemberOfGroup(myid uint64, groupid uint64) bool {
	db := database.DBConn
	var count int64
	db.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?", myid, groupid, utils.COLLECTION_APPROVED).Count(&count)
	return count > 0
}

type CreateRequest struct {
	Title          string `json:"title"`
	Online         bool   `json:"online"`
	Location       string `json:"location"`
	Contactname    string `json:"contactname"`
	Contactphone   string `json:"contactphone"`
	Contactemail   string `json:"contactemail"`
	Contacturl     string `json:"contacturl"`
	Description    string `json:"description"`
	Timecommitment string `json:"timecommitment"`
	GroupID        uint64 `json:"groupid"`
}

// Create handles POST /volunteering - create a new volunteering opportunity.
//
// @Summary Create a volunteering opportunity
// @Tags volunteering
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /volunteering [post]
func Create(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Title == "" || req.Location == "" || req.Description == "" {
		return fiber.NewError(fiber.StatusBadRequest, "title, location and description are required")
	}

	// Validate group membership if a group is provided (frontend may add groups separately via AddGroup).
	if req.GroupID > 0 && !user.IsAdminOrSupport(myid) {
		if !isMemberOfGroup(myid, req.GroupID) {
			return fiber.NewError(fiber.StatusForbidden, "Not a member of the specified group")
		}
	}

	db := database.DBConn

	// Plain, isolated, literal single-row
	// INSERT (the "1" for pending is a fixed literal, not a bind); id read back via
	// GORM's map-Create "@id" writeback.
	row := map[string]interface{}{
		"userid":         myid,
		"pending":        gorm.Expr("1"),
		"title":          req.Title,
		"online":         req.Online,
		"location":       req.Location,
		"contactname":    req.Contactname,
		"contactphone":   req.Contactphone,
		"contactemail":   req.Contactemail,
		"contacturl":     req.Contacturl,
		"description":    req.Description,
		"timecommitment": req.Timecommitment,
	}
	if err := db.Table("volunteering").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create volunteering")
	}
	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	if id > 0 && req.GroupID > 0 {
		// Converted together with its
		// identical twin below (316bb6807874): a half-converted pair renumbers
		// the survivor's site ID, so gate (h) refuses the split state.
		db.Table("volunteering_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"volunteeringid": id, "groupid": req.GroupID})
	}

	return c.JSON(fiber.Map{"id": id})
}

type PatchRequest struct {
	ID             uint64  `json:"id"`
	Action         string  `json:"action"`
	Title          *string `json:"title,omitempty"`
	Location       *string `json:"location,omitempty"`
	Online         *bool   `json:"online,omitempty"`
	Pending        *bool   `json:"pending,omitempty"`
	Contactname    *string `json:"contactname,omitempty"`
	Contactphone   *string `json:"contactphone,omitempty"`
	Contactemail   *string `json:"contactemail,omitempty"`
	Contacturl     *string `json:"contacturl,omitempty"`
	Description    *string `json:"description,omitempty"`
	Timecommitment *string `json:"timecommitment,omitempty"`
	GroupID        uint64  `json:"groupid"`
	DateID         uint64  `json:"dateid"`
	PhotoID        uint64  `json:"photoid"`
	Start          string  `json:"start"`
	End            string  `json:"end"`
	Applyby        string  `json:"applyby"`
}

// Update handles PATCH /volunteering - update a volunteering opportunity.
//
// @Summary Update a volunteering opportunity
// @Tags volunteering
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /volunteering [patch]
// volunteeringHeldByAnother returns the id and name of a DIFFERENT moderator holding
// this opportunity, or 0 if it is free to act on.
func volunteeringHeldByAnother(db *gorm.DB, id uint64, myid uint64) (uint64, string) {
	var holder uint64
	db.Table("volunteering").Select("COALESCE(heldby, 0)").Where("id = ?", id).Scan(&holder)
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

func Update(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PatchRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	// Check the volunteering exists
	db := database.DBConn
	var exists uint64
	db.Table("volunteering").Select("id").Where("id = ?", req.ID).Scan(&exists)
	if exists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Volunteering not found")
	}

	if !canModify(myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized to modify this volunteering")
	}

	// A moderator holding this has an exclusive claim on it. Only block other
	// MODERATORS: canModify also passes the owner, and a mod hold must not stop an
	// owner editing their own opportunity. Release remains available below.
	if req.Action != "Release" && isModerator(myid, req.ID) {
		if holder, name := volunteeringHeldByAnother(db, req.ID, myid); holder != 0 {
			return heldByAnotherResponse(c, holder, name)
		}
	}

	// Update settable attributes
	if req.Title != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("title", *req.Title)
	}
	if req.Location != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("location", *req.Location)
	}
	if req.Online != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("online", *req.Online)
	}
	if req.Pending != nil {
		// Approving out of moderation is terminal, so clear the hold with it rather
		// than leaving the opportunity pinned as "Held" forever.
		if *req.Pending {
			db.Table("volunteering").Where("id = ?", req.ID).Update("pending", *req.Pending)
		} else {
			db.Table("volunteering").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"pending": *req.Pending, "heldby": gorm.Expr("NULL")})
		}
	}
	if req.Contactname != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("contactname", *req.Contactname)
	}
	if req.Contactphone != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("contactphone", *req.Contactphone)
	}
	if req.Contactemail != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("contactemail", *req.Contactemail)
	}
	if req.Contacturl != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("contacturl", *req.Contacturl)
	}
	if req.Description != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("description", *req.Description)
	}
	if req.Timecommitment != nil {
		db.Table("volunteering").Where("id = ?", req.ID).Update("timecommitment", *req.Timecommitment)
	}

	// Process action
	switch req.Action {
	case "AddGroup":
		if req.GroupID > 0 {
			// Validate group membership: regular users must be a member of the group.
			if !user.IsAdminOrSupport(myid) && !isMemberOfGroup(myid, req.GroupID) {
				return fiber.NewError(fiber.StatusForbidden, "Not a member of the specified group")
			}

			// Twin of c77cdc1a1f5f above.
			db.Table("volunteering_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).
				Create(map[string]interface{}{"volunteeringid": req.ID, "groupid": req.GroupID})

			// Side effects: create newsfeed entry and notify group moderators.
			// 1. Create newsfeed entry for this volunteering opportunity.
			var ownerID *uint64
			db.Table("volunteering").Select("userid").Where("id = ?", req.ID).Scan(&ownerID)
			if ownerID != nil && *ownerID > 0 {
				volID := req.ID
				newsfeed.CreateNewsfeedEntry(newsfeed.TypeVolunteerOpportunity, *ownerID, req.GroupID, nil, &volID)
			}

			// 2. Notify group moderators via background task queue.
			if err := queue.QueueTask(queue.TaskPushNotifyGroupMods, map[string]interface{}{
				"group_id": req.GroupID,
			}); err != nil {
				log.Printf("Failed to queue push notification for group %d: %v", req.GroupID, err)
			}
		}
	case "RemoveGroup":
		if req.GroupID > 0 {
			db.Table("volunteering_groups").Where("volunteeringid = ? AND groupid = ?", req.ID, req.GroupID).Delete(nil)
		}
	case "AddDate":
		db.Table("volunteering_dates").Create(map[string]interface{}{
			"volunteeringid": req.ID,
			"start":          utils.NilIfEmpty(req.Start),
			"end":            utils.NilIfEmpty(req.End),
			"applyby":        utils.NilIfEmpty(req.Applyby),
		})
	case "RemoveDate":
		if req.DateID > 0 {
			db.Table("volunteering_dates").Where("id = ?", req.DateID).Delete(nil)
		}
	case "SetPhoto":
		if req.PhotoID > 0 {
			db.Table("volunteering_images").Where("id = ?", req.PhotoID).Update("opportunityid", req.ID)
		}
	case "Renew":
		db.Table("volunteering").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"renewed": gorm.Expr("NOW()"), "expired": gorm.Expr("0")})
	case "Expire":
		db.Table("volunteering").Where("id = ?", req.ID).Update("expired", gorm.Expr("1"))
	case "Hold":
		if isModerator(myid, req.ID) {
			// Don't take a hold off another mod - Release is how you do that.
			if holder, name := volunteeringHeldByAnother(db, req.ID, myid); holder != 0 {
				return heldByAnotherResponse(c, holder, name)
			}
			db.Table("volunteering").Where("id = ?", req.ID).Update("heldby", myid)
		}
	case "Release":
		if isModerator(myid, req.ID) {
			db.Table("volunteering").Where("id = ?", req.ID).Update("heldby", gorm.Expr("NULL"))
		}
	}

	return c.JSON(fiber.Map{"success": true})
}

// Delete handles DELETE /volunteering/:id - delete a volunteering opportunity.
//
// @Summary Delete a volunteering opportunity
// @Tags volunteering
// @Produce json
// @Param id path integer true "Volunteering ID"
// @Success 200 {object} map[string]interface{}
// @Router /volunteering/{id} [delete]
func Delete(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}

	db := database.DBConn
	var exists uint64
	db.Table("volunteering").Select("id").Where("id = ?", id).Scan(&exists)
	if exists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Volunteering not found")
	}

	if !canModify(myid, id) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized to delete this volunteering")
	}

	// Soft delete.
	db.Table("volunteering").Where("id = ?", id).
		Updates(map[string]interface{}{"deleted": gorm.Expr("1"), "deletedby": myid})

	return c.JSON(fiber.Map{"success": true})
}
