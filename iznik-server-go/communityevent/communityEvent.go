package communityevent

import (
	"errors"
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	"os"
	"strconv"
	"sync"
	"time"
)

func (CommunityEvent) TableName() string {
	return "communityevents"
}

type CommunityEvent struct {
	ID             uint64               `json:"id" gorm:"primary_key"`
	Userid         uint64               `json:"userid"`
	Pending        bool                 `json:"pending"`
	Heldby         *uint64              `json:"heldby"`
	Title          string               `json:"title"`
	Location       string               `json:"location"`
	Contactname    string               `json:"contactname"`
	Contactphone   string               `json:"contactphone"`
	Contactemail   string               `json:"contactemail"`
	Contacturl     string               `json:"contacturl"`
	Description    string               `json:"description"`
	Timecommitment string               `json:"timecommitment"`
	Added          time.Time            `json:"added"`
	Image          *CommunityEventImage `json:"image" gorm:"-"`
	Dates          []CommunityEventDate `json:"dates" gorm:"-"`
	Canmodify      bool                 `json:"canmodify" gorm:"-"`
}

// distanceOrder orders by great-circle distance in miles from (lat, lng) to the
// poster's location (locations.lat/lng, joined via users.lastlocation), with
// rows lacking a poster location sorted last rather than first. GORM's Order
// switch has no default branch, so this must be a clause.OrderBy - a bare
// gorm.Expr passed straight to Order is silently dropped.
func distanceOrder(lat, lng float32) clause.OrderBy {
	return clause.OrderBy{Expression: gorm.Expr(
		"(locations.lat IS NULL OR locations.lng IS NULL) ASC, "+
			"3959 * ACOS(GREATEST(-1.0, LEAST(1.0, "+
			"COS(RADIANS(?)) * COS(RADIANS(locations.lat)) * COS(RADIANS(locations.lng) - RADIANS(?)) "+
			"+ SIN(RADIANS(?)) * SIN(RADIANS(locations.lat))))) ASC",
		lat, lng, lat)}
}

func List(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn
	pending := c.Query("pending") == "true"
	start := time.Now().Format("2006-01-02")

	var ids []uint64

	if pending {
		// Pending events are a national moderation queue, not scoped to a community.
		if auth.IsModerator(myid) {
			db.Table("communityevents").
				Select("DISTINCT communityevents.id").
				Joins("INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id").
				Where("communityevents.deleted = 0 AND pending = 1 AND communityevents_dates.end >= ?", start).
				Order("communityevents_dates.end ASC").
				Pluck("id", &ids)
		}
	} else {
		loc := user.GetLatLng(myid)

		query := db.Table("communityevents").
			Select("DISTINCT communityevents.id").
			Joins("LEFT JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid").
			Joins("LEFT JOIN users ON communityevents.userid = users.id").
			Joins("LEFT JOIN locations ON locations.id = users.lastlocation").
			Where("end IS NOT NULL AND end >= ? AND communityevents.deleted = 0 AND (pending = 0 OR communityevents.userid = ?) AND users.deleted IS NULL",
				start, myid)

		if loc.Lat != 0 || loc.Lng != 0 {
			query = query.Order(distanceOrder(loc.Lat, loc.Lng))
		} else {
			query = query.Order("end ASC")
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
	var communityevent CommunityEvent
	var image CommunityEventImage
	var found bool
	var dates []CommunityEventDate
	archiveDomain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	imageDomain := os.Getenv("IMAGE_DOMAIN")

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)

	if err == nil {

		db := database.DBConn

		wg.Add(1)

		go func() {
			defer wg.Done()

			// Can always fetch a single one if we know the id, even if it's pending or held.
			err := db.Where("id = ? AND deleted = 0", id).First(&communityevent).Error
			found = !errors.Is(err, gorm.ErrRecordNotFound)
		}()

		wg.Add(1)

		go func() {
			defer wg.Done()

			db.Table("communityevents_images").Select("id, archived, externaluid, externalmods").
				Where("eventid = ?", id).Order("id DESC").Limit(1).Scan(&image)

			if image.ID > 0 {
				if image.Externaluid != "" {
					image.Ouruid = image.Externaluid
					image.Path = misc.GetImageDeliveryUrl(image.Externaluid, string(image.Externalmods))
					image.Paththumb = misc.GetImageDeliveryUrl(image.Externaluid, string(image.Externalmods))
					image.Externaluid = ""
				} else if image.Archived > 0 {
					image.Path = "https://" + archiveDomain + "/cimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
					image.Paththumb = "https://" + archiveDomain + "/tcimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
				} else {
					image.Path = "https://" + imageDomain + "/cimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
					image.Paththumb = "https://" + imageDomain + "/tcimg_" + strconv.FormatUint(image.ID, 10) + ".jpg"
				}
			}
		}()

		wg.Add(1)

		go func() {
			defer wg.Done()

			db.Table("communityevents_dates").Where("eventid = ?", id).Scan(&dates)
		}()

		wg.Wait()

		if found {
			if image.ID > 0 {
				communityevent.Image = &image
			}

			if dates == nil {
				dates = make([]CommunityEventDate, 0)
			}
			communityevent.Dates = dates

			myid := user.WhoAmI(c)
			if myid > 0 {
				communityevent.Canmodify = canModify(myid, communityevent.ID)
			}

			return c.JSON(communityevent)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "Not found")
}

func canModify(myid uint64, eventID uint64) bool {
	db := database.DBConn

	var ownerID *uint64
	db.Table("communityevents").Select("userid").Where("id = ?", eventID).Scan(&ownerID)

	if ownerID != nil && *ownerID == myid {
		return true
	}

	return auth.IsModerator(myid)
}

type CreateRequest struct {
	Title        string `json:"title"`
	Location     string `json:"location"`
	Contactname  string `json:"contactname"`
	Contactphone string `json:"contactphone"`
	Contactemail string `json:"contactemail"`
	Contacturl   string `json:"contacturl"`
	Description  string `json:"description"`
}

// Create handles POST /communityevent - create a new community event.
//
// @Summary Create a community event
// @Tags communityevent
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /communityevent [post]
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

	db := database.DBConn

	// Plain, isolated, literal single-row
	// INSERT (the "1" for pending is a fixed literal); id read back via GORM's
	// map-Create "@id" writeback.
	row := map[string]interface{}{
		"userid":       myid,
		"pending":      gorm.Expr("1"),
		"title":        req.Title,
		"location":     req.Location,
		"contactname":  req.Contactname,
		"contactphone": req.Contactphone,
		"contactemail": req.Contactemail,
		"contacturl":   req.Contacturl,
		"description":  req.Description,
	}
	if err := db.Table("communityevents").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create community event")
	}
	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	return c.JSON(fiber.Map{"id": id})
}

type PatchRequest struct {
	ID           uint64  `json:"id"`
	Action       string  `json:"action"`
	Title        *string `json:"title,omitempty"`
	Location     *string `json:"location,omitempty"`
	Pending      *bool   `json:"pending,omitempty"`
	Contactname  *string `json:"contactname,omitempty"`
	Contactphone *string `json:"contactphone,omitempty"`
	Contactemail *string `json:"contactemail,omitempty"`
	Contacturl   *string `json:"contacturl,omitempty"`
	Description  *string `json:"description,omitempty"`
	DateID       uint64  `json:"dateid"`
	PhotoID      uint64  `json:"photoid"`
	Start        string  `json:"start"`
	End          string  `json:"end"`
}

// Update handles PATCH /communityevent - update a community event.
//
// @Summary Update a community event
// @Tags communityevent
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /communityevent [patch]
// eventHeldByAnother returns the id and name of a DIFFERENT moderator holding this
// event, or 0 if it is free to act on.
func eventHeldByAnother(db *gorm.DB, id uint64, myid uint64) (uint64, string) {
	var holder uint64
	db.Table("communityevents").Select("COALESCE(heldby, 0)").Where("id = ?", id).Scan(&holder)
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

	db := database.DBConn
	var exists uint64
	db.Table("communityevents").Select("id").Where("id = ?", req.ID).Scan(&exists)
	if exists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Community event not found")
	}

	if !canModify(myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized to modify this community event")
	}

	// A moderator holding this event has an exclusive claim on it. Only block other
	// MODERATORS: canModify also passes the event's owner, and a mod hold must not
	// stop an owner editing their own event. Release remains available below.
	if req.Action != "Release" && auth.IsModerator(myid) {
		if holder, name := eventHeldByAnother(db, req.ID, myid); holder != 0 {
			return heldByAnotherResponse(c, holder, name)
		}
	}

	// Update settable attributes
	if req.Title != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("title", *req.Title)
	}
	if req.Location != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("location", *req.Location)
	}
	if req.Pending != nil {
		// Approving out of moderation is terminal, so clear the hold with it rather
		// than leaving the event pinned as "Held" forever.
		if *req.Pending {
			db.Table("communityevents").Where("id = ?", req.ID).Update("pending", *req.Pending)
		} else {
			db.Table("communityevents").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"pending": *req.Pending, "heldby": gorm.Expr("NULL")})

			// Approved: the event is now publicly visible, so post it to the newsfeed.
			var ownerID *uint64
			db.Table("communityevents").Select("userid").Where("id = ?", req.ID).Scan(&ownerID)
			if ownerID != nil && *ownerID > 0 {
				eventID := req.ID
				newsfeed.CreateNewsfeedEntry(newsfeed.TypeCommunityEvent, *ownerID, &eventID, nil)
			}
		}
	}
	if req.Contactname != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("contactname", *req.Contactname)
	}
	if req.Contactphone != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("contactphone", *req.Contactphone)
	}
	if req.Contactemail != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("contactemail", *req.Contactemail)
	}
	if req.Contacturl != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("contacturl", *req.Contacturl)
	}
	if req.Description != nil {
		db.Table("communityevents").Where("id = ?", req.ID).Update("description", *req.Description)
	}

	// Process action
	switch req.Action {
	case "AddDate":
		db.Table("communityevents_dates").Create(map[string]interface{}{
			"eventid": req.ID,
			"start":   utils.NilIfEmpty(req.Start),
			"end":     utils.NilIfEmpty(req.End),
		})
	case "RemoveDate":
		if req.DateID > 0 {
			db.Table("communityevents_dates").Where("id = ?", req.DateID).Delete(nil)
		}
	case "SetPhoto":
		if req.PhotoID > 0 {
			db.Table("communityevents_images").Where("id = ?", req.PhotoID).Update("eventid", req.ID)
		}
	case "Hold":
		if auth.IsModerator(myid) {
			// Don't take a hold off another mod - Release is how you do that.
			if holder, name := eventHeldByAnother(db, req.ID, myid); holder != 0 {
				return heldByAnotherResponse(c, holder, name)
			}
			db.Table("communityevents").Where("id = ?", req.ID).Update("heldby", myid)
		}
	case "Release":
		if auth.IsModerator(myid) {
			db.Table("communityevents").Where("id = ?", req.ID).Update("heldby", gorm.Expr("NULL"))
		}
	}

	return c.JSON(fiber.Map{"success": true})
}

// Delete handles DELETE /communityevent/:id - delete a community event.
//
// @Summary Delete a community event
// @Tags communityevent
// @Produce json
// @Param id path integer true "Event ID"
// @Success 200 {object} map[string]interface{}
// @Router /communityevent/{id} [delete]
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
	db.Table("communityevents").Select("id").Where("id = ?", id).Scan(&exists)
	if exists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Community event not found")
	}

	if !canModify(myid, id) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized to delete this community event")
	}

	// Soft delete.
	db.Table("communityevents").Where("id = ?", id).Update("deleted", gorm.Expr("1"))

	return c.JSON(fiber.Map{"success": true})
}
