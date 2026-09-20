package communityevent

import (
	"errors"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/newsfeed"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	"log"
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
	Groups         []uint64             `json:"groups" gorm:"-"`
	Image          *CommunityEventImage `json:"image" gorm:"-"`
	Dates          []CommunityEventDate `json:"dates" gorm:"-"`
	Canmodify      bool                 `json:"canmodify" gorm:"-"`
}

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
		// Return only pending events on groups where the user is Owner/Moderator.
		// Must join communityevents_dates and filter to future events.
		modGroupIDs := user.GetActiveModGroupIDs(myid)

		if len(modGroupIDs) > 0 {
			start := time.Now().Format("2006-01-02")
			db.Table("communityevents").
				Select("DISTINCT communityevents.id").
				Joins("INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid").
				Joins("INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id").
				Where("groupid IN (?) AND communityevents.deleted = 0 AND pending = 1 AND communityevents_dates.end >= ?", modGroupIDs, start).
				Order("communityevents_dates.end ASC").
				Pluck("id", &ids)
		}
	} else if len(groupids) > 0 {
		start := time.Now().Format("2006-01-02")

		db.Table("communityevents").
			Select("DISTINCT communityevents.id").
			Joins("INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid").
			Joins("LEFT JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid").
			Joins("LEFT JOIN users ON communityevents.userid = users.id").
			Where("groupid IN (?) AND end IS NOT NULL AND end >= ? AND communityevents.deleted = 0 AND (pending = 0 OR communityevents.userid = ?) AND users.deleted IS NULL",
				groupids, start, myid).
			Order("end ASC").
			Pluck("id", &ids)
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

	// Allow the logged-in creator to see their own pending event on the group page,
	// matching List's behaviour: `pending = 0 OR communityevents.userid = myid`.
	// Anonymous requests (myid = 0) only see approved events.
	myid := user.WhoAmI(c)

	var ids []uint64

	start := time.Now().Format("2006-01-02")

	db.Table("communityevents").
		Select("DISTINCT communityevents.id").
		Joins("LEFT JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid").
		Joins("LEFT JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid").
		Joins("LEFT JOIN users ON communityevents.userid = users.id").
		Where("groupid = ? AND end IS NOT NULL AND end >= ? AND communityevents.deleted = 0 AND (pending = 0 OR communityevents.userid = ?) AND users.deleted IS NULL",
			id, start, myid).
		Order("end ASC").
		Pluck("id", &ids)

	if len(ids) > 0 {
		return c.JSON(ids)
	} else {
		// Force [] rather than null to be returned.
		return c.JSON(make([]string, 0))
	}
}

func Single(c *fiber.Ctx) error {
	var wg sync.WaitGroup
	var communityevent CommunityEvent
	var image CommunityEventImage
	var found bool
	var groups []uint64
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

			db.Table("communityevents_groups").Where("eventid = ?", id).Pluck("groupid", &groups)
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

			if groups == nil {
				groups = make([]uint64, 0)
			}
			communityevent.Groups = groups

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

	return isModerator(myid, eventID)
}

func isModerator(myid uint64, eventID uint64) bool {
	if user.IsAdminOrSupport(myid) {
		return true
	}

	// Single query to check if user is moderator/owner of any linked group.
	db := database.DBConn
	var count int64
	db.Table("memberships m").
		Joins("INNER JOIN communityevents_groups ceg ON ceg.groupid = m.groupid").
		Where("ceg.eventid = ? AND m.userid = ? AND m.collection = ? AND m.role IN (?, ?)",
			eventID, myid, utils.COLLECTION_APPROVED, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Count(&count)

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
	Title        string `json:"title"`
	Location     string `json:"location"`
	Contactname  string `json:"contactname"`
	Contactphone string `json:"contactphone"`
	Contactemail string `json:"contactemail"`
	Contacturl   string `json:"contacturl"`
	Description  string `json:"description"`
	GroupID      uint64 `json:"groupid"`
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

	// Validate group membership if a group is provided (frontend may add groups separately via AddGroup).
	if req.GroupID > 0 && !user.IsAdminOrSupport(myid) {
		if !isMemberOfGroup(myid, req.GroupID) {
			return fiber.NewError(fiber.StatusForbidden, "Not a member of the specified group")
		}
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

	if id > 0 && req.GroupID > 0 {
		db.Table("communityevents_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"eventid": id,
			"groupid": req.GroupID,
		})
	}

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
	GroupID      uint64  `json:"groupid"`
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
	if req.Action != "Release" && isModerator(myid, req.ID) {
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
	case "AddGroup":
		if req.GroupID > 0 {
			// Validate group membership: regular users must be a member of the group.
			if !user.IsAdminOrSupport(myid) && !isMemberOfGroup(myid, req.GroupID) {
				return fiber.NewError(fiber.StatusForbidden, "Not a member of the specified group")
			}

			db.Table("communityevents_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
				"eventid": req.ID,
				"groupid": req.GroupID,
			})

			// Side effects: create newsfeed entry and notify group moderators.
			// 1. Create newsfeed entry for this community event.
			var ownerID *uint64
			db.Table("communityevents").Select("userid").Where("id = ?", req.ID).Scan(&ownerID)
			if ownerID != nil && *ownerID > 0 {
				eventID := req.ID
				newsfeed.CreateNewsfeedEntry(newsfeed.TypeCommunityEvent, *ownerID, req.GroupID, &eventID, nil)
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
			db.Table("communityevents_groups").Where("eventid = ? AND groupid = ?", req.ID, req.GroupID).Delete(nil)
		}
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
		if isModerator(myid, req.ID) {
			// Don't take a hold off another mod - Release is how you do that.
			if holder, name := eventHeldByAnother(db, req.ID, myid); holder != 0 {
				return heldByAnotherResponse(c, holder, name)
			}
			db.Table("communityevents").Where("id = ?", req.ID).Update("heldby", myid)
		}
	case "Release":
		if isModerator(myid, req.ID) {
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
