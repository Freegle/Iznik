package volunteering

import (
	"errors"
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/localreach"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"html"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

func (Volunteering) TableName() string {
	return "volunteering"
}

type Volunteering struct {
	ID             uint64    `json:"id" gorm:"primary_key"`
	Userid         uint64    `json:"userid"`
	Pending        bool      `json:"pending"`
	Heldby         *uint64   `json:"heldby"`
	Title          string    `json:"title"`
	Online         bool      `json:"online"`
	Location       string    `json:"location"`
	Contactname    string    `json:"contactname"`
	Contactphone   string    `json:"contactphone"`
	Contactemail   string    `json:"contactemail"`
	Contacturl     string    `json:"contacturl"`
	Description    string    `json:"description"`
	Timecommitment string    `json:"timecommitment"`
	Added          time.Time `json:"added"`
	// Renewed is when the owner last confirmed the opportunity is still active. The
	// client needs it to know whether a confirmation is due, so that it only asks when
	// the batch renewal clock says so rather than on every visit.
	Renewed      *time.Time         `json:"renewed"`
	Lat          *float64           `json:"lat"`
	Lng          *float64           `json:"lng"`
	Reachminutes *int               `json:"reachminutes"`
	Reachmiles   *float64           `json:"reachmiles"`
	Image        *VolunteeringImage `json:"image" gorm:"-"`
	Dates        []VolunteeringDate `json:"dates" gorm:"-"`
	Expired      bool               `json:"expired"`
	Canmodify    bool               `json:"canmodify" gorm:"-"`
	Url          string             `json:"url" gorm:"-"`
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

	var ids []uint64

	if pending {
		// Pending opportunities are a national moderation queue, not scoped to a community.
		if auth.IsModerator(myid) {
			db.Table("volunteering").
				Select("DISTINCT volunteering.id").
				Where("volunteering.deleted = 0 AND pending = 1").
				Order("id DESC").
				Pluck("id", &ids)
		}
	} else {
		start := time.Now().Format("2006-01-02")
		loc := user.GetLatLng(myid)

		query := db.Table("volunteering").
			Select("DISTINCT volunteering.id").
			Joins("LEFT JOIN volunteering_dates ON volunteering.id = volunteering_dates.volunteeringid").
			Joins("LEFT JOIN users ON volunteering.userid = users.id").
			Where("(applyby IS NULL OR applyby >= ?) AND (end IS NULL OR end >= ?) AND volunteering.deleted = 0 AND expired = 0 AND (pending = 0 OR volunteering.userid = ?) AND users.deleted IS NULL",
				start, start, myid).
			Limit(listLimit)

		// A member sees the opportunities whose reach covers them, nearest first, then the
		// online ones, which are shown everywhere. Their own are always shown. With no
		// location there is nothing to measure from, so only online ones and their own.
		lat, lng := float64(loc.Lat), float64(loc.Lng)
		if lat != 0 || lng != 0 {
			within, args := localreach.Within("volunteering", lat, lng)
			query = query.Where("(volunteering.userid = ? OR volunteering.online = 1 OR ("+within+"))", append([]interface{}{myid}, args...)...).
				Order("volunteering.online ASC").
				Order(localreach.Nearest("volunteering", lat, lng))
		} else {
			query = query.Where("(volunteering.userid = ? OR volunteering.online = 1)", myid).
				Order("volunteering.id DESC")
		}

		query.Pluck("id", &ids)
	}

	if len(ids) > 0 {
		return c.JSON(ids)
	} else {
		return c.JSON(make([]string, 0))
	}
}

func Single(c *fiber.Ctx) error {
	var wg sync.WaitGroup
	var volunteering Volunteering
	var image VolunteeringImage
	var found bool
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

			db.Table("volunteering_dates").Where("volunteeringid = ?", id).Scan(&dates)
		}()

		wg.Wait()

		if found {
			if image.ID > 0 {
				volunteering.Image = &image
			}

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

			// Set the canonical URL for this opportunity. Mirrors V1 getPublic():
			// $atts['url'] = 'https://' . USER_SITE . '/volunteering/' . $atts['id']
			userSite := os.Getenv("USER_SITE")
			if userSite == "" {
				userSite = "www.ilovefreegle.org"
			}
			volunteering.Url = "https://" + userSite + "/volunteering/" + strconv.FormatUint(volunteering.ID, 10)

			// Normalise contacturl: if non-empty and has no scheme, prepend https://.
			// Mirrors V1: if (strlen($atts['contacturl']) && strpos($atts['contacturl'], 'http') === FALSE)
			if len(volunteering.Contacturl) > 0 && !strings.Contains(volunteering.Contacturl, "http") {
				volunteering.Contacturl = "https://" + volunteering.Contacturl
			}

			myid := user.WhoAmI(c)
			if myid > 0 {
				volunteering.Canmodify = canModify(myid, volunteering.ID)
			}

			// The centre is usually the poster's own location, so only someone who can
			// edit the opportunity sees it. Everyone else gets the free-text location.
			if !volunteering.Canmodify {
				volunteering.Lat, volunteering.Lng = nil, nil
			}

			return c.JSON(volunteering)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "Not found")
}

func canModify(myid uint64, volunteeringID uint64) bool {
	db := database.DBConn

	var ownerID *uint64
	db.Table("volunteering").Select("userid").Where("id = ?", volunteeringID).Scan(&ownerID)

	if ownerID != nil && *ownerID == myid {
		return true
	}

	return auth.IsModerator(myid)
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
	// Where it is shown around, if not the poster's own location.
	Lat *float64 `json:"lat,omitempty"`
	Lng *float64 `json:"lng,omitempty"`
	// How far it is shown, as a drive time; the density cap at the centre if absent.
	Reachminutes *int `json:"reachminutes,omitempty"`
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

	// An online opportunity is shown everywhere, so it needs no centre. Anything else does.
	reach, ok := localreach.Resolve(myid, req.Lat, req.Lng, req.Reachminutes)
	if !ok && !req.Online {
		return fiber.NewError(fiber.StatusBadRequest, "We need to know where the opportunity is - please add a postcode")
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
	if ok {
		for k, v := range reach.Columns() {
			row[k] = v
		}
	}
	if err := db.Table("volunteering").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create volunteering")
	}
	// New opportunities always wait for approval; while events are held this one is also counted.
	if lockdown.Held("events") {
		lockdown.Count("held:events")
	}
	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	return c.JSON(fiber.Map{"id": id})
}

type PatchRequest struct {
	ID             uint64   `json:"id"`
	Action         string   `json:"action"`
	Title          *string  `json:"title,omitempty"`
	Location       *string  `json:"location,omitempty"`
	Online         *bool    `json:"online,omitempty"`
	Pending        *bool    `json:"pending,omitempty"`
	Contactname    *string  `json:"contactname,omitempty"`
	Contactphone   *string  `json:"contactphone,omitempty"`
	Contactemail   *string  `json:"contactemail,omitempty"`
	Contacturl     *string  `json:"contacturl,omitempty"`
	Description    *string  `json:"description,omitempty"`
	Timecommitment *string  `json:"timecommitment,omitempty"`
	Lat            *float64 `json:"lat,omitempty"`
	Lng            *float64 `json:"lng,omitempty"`
	Reachminutes   *int     `json:"reachminutes,omitempty"`
	DateID         uint64   `json:"dateid"`
	PhotoID        uint64   `json:"photoid"`
	Start          string   `json:"start"`
	End            string   `json:"end"`
	Applyby        string   `json:"applyby"`
}

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

// Update handles PATCH /volunteering - update a volunteering opportunity.
//
// @Summary Update a volunteering opportunity
// @Tags volunteering
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /volunteering [patch]
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
	if req.Action != "Release" && auth.IsModerator(myid) {
		if holder, name := volunteeringHeldByAnother(db, req.ID, myid); holder != 0 {
			return heldByAnotherResponse(c, holder, name)
		}
	}

	// Section 11.3 of the lockdown plan: PATCH /volunteering is refused while
	// "events" is held, except a moderator approving it out of moderation - setting
	// Pending to false and nothing else in the same call - which stays allowed so the
	// review queue keeps draining, the same shape as Approve elsewhere in the plan.
	//
	// isApprove is only a body-shape check, so it must never grant the fast path by
	// itself: canModify above lets the opportunity's own owner through this same code
	// path, and without a role check here that owner could send the bare approve body
	// and self-publish their own held opportunity (review finding 3). Only a moderator
	// of the opportunity's group, or Support/Admin, gets the fast path; everyone else
	// still needs isApprove to be false to even reach GateMember, so an owner's
	// approve attempt is refused exactly as any other edit is.
	isApprove := req.Pending != nil && !*req.Pending && req.Action == "" &&
		req.Title == nil && req.Location == nil && req.Online == nil &&
		req.Contactname == nil && req.Contactphone == nil && req.Contactemail == nil &&
		req.Contacturl == nil && req.Description == nil && req.Timecommitment == nil &&
		req.Lat == nil && req.Lng == nil && req.Reachminutes == nil
	if isApprove && auth.IsModerator(myid) {
		lockdown.CountApproval(myid)
	} else if lockdown.GateMember(c, myid, "events") {
		return nil
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
	if req.Lat != nil || req.Lng != nil || req.Reachminutes != nil {
		if err := localreach.Update(db, "volunteering", req.ID, req.Lat, req.Lng, req.Reachminutes); err != nil {
			return err
		}
	}
	if req.Pending != nil {
		// Approving out of moderation is terminal, so clear the hold with it rather
		// than leaving the opportunity pinned as "Held" forever.
		if *req.Pending {
			db.Table("volunteering").Where("id = ?", req.ID).Update("pending", *req.Pending)
		} else {
			db.Table("volunteering").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"pending": *req.Pending, "heldby": gorm.Expr("NULL")})

			var ownerID *uint64
			db.Table("volunteering").Select("userid").Where("id = ?", req.ID).Scan(&ownerID)
			if ownerID != nil && *ownerID > 0 {
				volID := req.ID
				newsfeed.CreateNewsfeedEntry(newsfeed.TypeVolunteerOpportunity, *ownerID, nil, &volID)
			}
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
		if auth.IsModerator(myid) {
			// Don't take a hold off another mod - Release is how you do that.
			if holder, name := volunteeringHeldByAnother(db, req.ID, myid); holder != 0 {
				return heldByAnotherResponse(c, holder, name)
			}
			db.Table("volunteering").Where("id = ?", req.ID).Update("heldby", myid)
		}
	case "Release":
		if auth.IsModerator(myid) {
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
