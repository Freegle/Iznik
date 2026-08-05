package noticeboard

import (
	"fmt"
	"os"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// NoticeboardItem is a flat V2 response. Client fetches user details separately via /user/:id.
type NoticeboardItem struct {
	ID            uint64      `json:"id"`
	Name          *string     `json:"name"`
	Lat           float64     `json:"lat"`
	Lng           float64     `json:"lng"`
	Added         *time.Time  `json:"added"`
	Addedby       *uint64     `json:"addedby"`
	Description   *string     `json:"description"`
	Active        bool        `json:"active"`
	Lastcheckedat *time.Time  `json:"lastcheckedat"`
	Photo         *PhotoInfo  `json:"photo,omitempty"`
	Checks        []CheckItem `json:"checks"`
}

type PhotoInfo struct {
	ID        uint64 `json:"id"`
	Path      string `json:"path"`
	Paththumb string `json:"paththumb"`
}

type CheckItem struct {
	ID        uint64     `json:"id"`
	Userid    *uint64    `json:"userid"`
	Askedat   *time.Time `json:"askedat"`
	Checkedat *time.Time `json:"checkedat"`
	Inactive  bool       `json:"inactive"`
	Refreshed bool       `json:"refreshed"`
	Declined  bool       `json:"declined"`
	Comments  *string    `json:"comments"`
}

type NoticeboardListItem struct {
	ID   uint64  `json:"id"`
	Name *string `json:"name"`
	Lat  float64 `json:"lat"`
	Lng  float64 `json:"lng"`
}

// GetNoticeboard handles GET /noticeboard and GET /noticeboard/:id
func GetNoticeboard(c *fiber.Ctx) error {
	idStr := c.Params("id")

	if idStr != "" {
		return getSingle(c, idStr)
	}

	return getList(c)
}

func getSingle(c *fiber.Ctx, idStr string) error {
	id, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	db := database.DBConn

	var wg sync.WaitGroup
	var nb NoticeboardItem
	var checks []CheckItem
	var photoID uint64

	wg.Add(3)

	go func() {
		defer wg.Done()
		// ORM migration site 92d2a939593e (wave 1).
		db.Table("noticeboards").Select("id, name, lat, lng, added, addedby, description, active, lastcheckedat").Where("id = ?", id).Scan(&nb)
	}()

	go func() {
		defer wg.Done()
		// ORM migration site 3bcf101e10b0 (wave 1).
		db.Table("noticeboards_checks").Select("id, userid, askedat, checkedat, inactive, refreshed, declined, comments").Where("noticeboardid = ?", id).Order("id DESC").Scan(&checks)
	}()

	go func() {
		defer wg.Done()
		// ORM migration site 49eee194e249 (wave 1).
		db.Table("noticeboards_images").Select("id").Where("noticeboardid = ?", id).Limit(1).Scan(&photoID)
	}()

	wg.Wait()

	if nb.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Noticeboard not found")
	}

	if checks == nil {
		checks = make([]CheckItem, 0)
	}
	nb.Checks = checks

	if photoID > 0 {
		imageDomain := os.Getenv("IMAGE_DOMAIN")
		if imageDomain == "" {
			imageDomain = "images.ilovefreegle.org"
		}
		nb.Photo = &PhotoInfo{
			ID:        photoID,
			Path:      "https://" + imageDomain + "/bimg_" + strconv.FormatUint(photoID, 10) + ".jpg",
			Paththumb: "https://" + imageDomain + "/tbimg_" + strconv.FormatUint(photoID, 10) + ".jpg",
		}
	}

	return c.JSON(nb)
}

func getList(c *fiber.Ctx) error {
	db := database.DBConn

	authorityID, _ := strconv.ParseUint(c.Query("authorityid"), 10, 64)

	var noticeboards []NoticeboardListItem

	if authorityID > 0 {
		// ORM migration site e9346f66bbf4 (Tier 1 spatial review).
		db.Table("noticeboards").
			Select("noticeboards.id, noticeboards.name, noticeboards.lat, noticeboards.lng").
			Joins("INNER JOIN authorities ON authorities.id = ?", authorityID).
			Where("authorities.name IS NOT NULL AND active = 1 AND ST_CONTAINS(authorities.polygon, ST_SRID(POINT(noticeboards.lng, noticeboards.lat), ?))", utils.SRID).
			Scan(&noticeboards)
	} else {
		// ORM migration site b62e9af236c5 (wave 1).
		db.Table("noticeboards").Select("id, name, lat, lng").Where("name IS NOT NULL AND active = 1").Scan(&noticeboards)
	}

	if noticeboards == nil {
		noticeboards = make([]NoticeboardListItem, 0)
	}

	return c.JSON(fiber.Map{"noticeboards": noticeboards})
}

type PostNoticeboardRequest struct {
	Lat         *float64 `json:"lat"`
	Lng         *float64 `json:"lng"`
	Name        *string  `json:"name"`
	Description *string  `json:"description"`
	Active      *bool    `json:"active"`
	Action      string   `json:"action"`
	ID          uint64   `json:"id"`
	Comments    *string  `json:"comments"`
}

type PatchNoticeboardRequest struct {
	ID            uint64   `json:"id"`
	Name          *string  `json:"name"`
	Lat           *float64 `json:"lat"`
	Lng           *float64 `json:"lng"`
	Description   *string  `json:"description"`
	Active        *bool    `json:"active"`
	Lastcheckedat *string  `json:"lastcheckedat"`
	Photoid       *uint64  `json:"photoid"`
}

func PostNoticeboard(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		// Previously unchecked: an anonymous caller could record noticeboard checks
		// (userid 0) and flip a board active/inactive. PatchNoticeboard already gates
		// this; POST must too.
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PostNoticeboardRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	if req.Action != "" {
		// Action on existing noticeboard
		if req.ID == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "id is required for action")
		}

		switch req.Action {
		case "Refreshed":
			// ORM migration site b22dc6f55660 (wave 2).
			db.Table("noticeboards_checks").Create(map[string]interface{}{
				"noticeboardid": req.ID,
				"userid":        myid,
				"checkedat":     gorm.Expr("NOW()"),
				"refreshed":     gorm.Expr("1"),
				"inactive":      gorm.Expr("0"),
			})
			// ORM migration site 4e32d794943f (wave 2).
			db.Table("noticeboards").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"lastcheckedat": gorm.Expr("NOW()"), "active": gorm.Expr("1")})
		case "Declined":
			// ORM migration site 8e072007de59 (wave 2).
			db.Table("noticeboards_checks").Create(map[string]interface{}{
				"noticeboardid": req.ID,
				"userid":        myid,
				"checkedat":     gorm.Expr("NOW()"),
				"declined":      gorm.Expr("1"),
				"inactive":      gorm.Expr("0"),
			})
		case "Inactive":
			// ORM migration site 29fd88a76f02 (wave 2).
			db.Table("noticeboards_checks").Create(map[string]interface{}{
				"noticeboardid": req.ID,
				"userid":        myid,
				"checkedat":     gorm.Expr("NOW()"),
				"inactive":      gorm.Expr("1"),
			})
			// ORM migration site 90267e07036b (wave 2).
			db.Table("noticeboards").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"lastcheckedat": gorm.Expr("NOW()"), "active": gorm.Expr("0")})
		case "Comments":
			comments := ""
			if req.Comments != nil {
				comments = *req.Comments
			}
			// ORM migration site fc070851caba (wave 2).
			db.Table("noticeboards_checks").Create(map[string]interface{}{
				"noticeboardid": req.ID,
				"userid":        myid,
				"checkedat":     gorm.Expr("NOW()"),
				"comments":      comments,
				"inactive":      gorm.Expr("0"),
			})
		default:
			return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	// Create new noticeboard
	if req.Lat == nil || req.Lng == nil {
		return fiber.NewError(fiber.StatusBadRequest, "lat and lng are required")
	}

	active := true
	if req.Active != nil {
		active = *req.Active
	}

	name := ""
	if req.Name != nil {
		name = *req.Name
	}

	description := ""
	if req.Description != nil {
		description = *req.Description
	}

	// Use NULL for addedby when user is not logged in (myid=0) to satisfy FK constraint.
	var addedby interface{}
	if myid > 0 {
		addedby = myid
	}

	// ORM migration site 42bb2fc5fe91 (tier6). Same zero-precision-change
	// conversion as newsfeed.go's createRefer/createPost and newsfeed/create.go
	// (10bcbd6a6404, f961504c334d, 90b0f0bb3029): the WKT text is built exactly
	// as before via fmt.Sprintf("POINT(%f %f)", ...), then bound as a genuine
	// ST_GeomFromText argument rather than spliced into the SQL text.
	row := map[string]interface{}{
		"name":          name,
		"lat":           *req.Lat,
		"lng":           *req.Lng,
		"position":      gorm.Expr("ST_GeomFromText(?, ?)", fmt.Sprintf("POINT(%f %f)", *req.Lng, *req.Lat), utils.SRID),
		"added":         gorm.Expr("NOW()"),
		"addedby":       addedby,
		"description":   description,
		"active":        active,
		"lastcheckedat": gorm.Expr("NOW()"),
	}
	if err := db.Table("noticeboards").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Create failed")
	}

	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": id})
}

func PatchNoticeboard(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PatchNoticeboardRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	// Check noticeboard exists and get current name and creator for auth check.
	var currentName string
	var addedby uint64
	var count int64
	// ORM migration site 17992b4893d5 (wave 1).
	db.Table("noticeboards").Select("COUNT(*), COALESCE(name, ''), COALESCE(addedby, 0)").Where("id = ?", req.ID).Row().Scan(&count, &currentName, &addedby)
	if count == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Noticeboard not found")
	}

	// Must be the creator or a moderator.
	if myid != addedby && !auth.IsSystemMod(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	// Update settable attributes
	if req.Name != nil {
		// ORM migration site 231d70e1fa28 (wave 2).
		db.Table("noticeboards").Where("id = ?", req.ID).Update("name", *req.Name)
	}
	if req.Lat != nil {
		// ORM migration site 96d656eb95cb (wave 2).
		db.Table("noticeboards").Where("id = ?", req.ID).Update("lat", *req.Lat)
	}
	if req.Lng != nil {
		// ORM migration site 86722b0b3cff (wave 2).
		db.Table("noticeboards").Where("id = ?", req.ID).Update("lng", *req.Lng)
	}
	if req.Description != nil {
		// ORM migration site 0c12eb5cf095 (wave 2).
		db.Table("noticeboards").Where("id = ?", req.ID).Update("description", *req.Description)
	}
	if req.Active != nil {
		// ORM migration site b4e8507b4bf0 (wave 2).
		db.Table("noticeboards").Where("id = ?", req.ID).Update("active", *req.Active)
	}
	if req.Lastcheckedat != nil {
		// ORM migration site 86acdf5e502f (wave 2).
		db.Table("noticeboards").Where("id = ?", req.ID).Update("lastcheckedat", *req.Lastcheckedat)
	}

	// Link photo if provided
	if req.Photoid != nil {
		// ORM migration site 42bec0874800 (wave 2).
		db.Table("noticeboards_images").Where("id = ?", *req.Photoid).Update("noticeboardid", req.ID)
	}

	// Create newsfeed entry on first name assignment (when name was empty and is now being set)
	if req.Name != nil && currentName == "" && *req.Name != "" {
		isActive := true
		if req.Active != nil {
			isActive = *req.Active
		}

		if isActive {
			// Get the noticeboard data for the newsfeed entry
			var addedby uint64
			var lat, lng float64
			// ORM migration site 2719baff5f09 (wave 1).
			db.Table("noticeboards").Select("COALESCE(addedby, 0), COALESCE(lat, 0), COALESCE(lng, 0)").Where("id = ?", req.ID).Row().Scan(&addedby, &lat, &lng)

			if addedby > 0 {
				// Create newsfeed entry with type 'Noticeboard'.
				// ORM migration site c4e30fd6a513 (tier6). Same
				// zero-precision-change conversion as PostNoticeboard
				// (42bb2fc5fe91) above: the WKT text is built exactly as
				// before via fmt.Sprintf("POINT(%f %f)", ...), then bound as a
				// genuine ST_GeomFromText argument rather than spliced into
				// the SQL text.
				db.Table("newsfeed").Create(map[string]interface{}{
					"type":     gorm.Expr("'Noticeboard'"),
					"userid":   addedby,
					"message":  fmt.Sprintf(`{"id":%d,"name":"%s"}`, req.ID, *req.Name),
					"added":    gorm.Expr("NOW()"),
					"position": gorm.Expr("ST_GeomFromText(?, ?)", fmt.Sprintf("POINT(%f %f)", lng, lat), utils.SRID),
				})
			}
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": req.ID})
}

// =============================================================================
// Merged from noticeboard/noticeboard_write.go
// =============================================================================

// DeleteNoticeboard deletes a noticeboard. Requires moderator or admin role.
// @Summary Delete noticeboard
// @Description Deletes a noticeboard by ID. Requires mod/admin.
// @Tags noticeboard
// @Produce json
// @Param id path integer true "Noticeboard ID"
// @Security BearerAuth
// @Success 200 {object} fiber.Map "Success"
// @Failure 400 {object} fiber.Error "Invalid ID"
// @Failure 401 {object} fiber.Error "Not logged in"
// @Failure 403 {object} fiber.Error "Not authorized"
// @Failure 404 {object} fiber.Error "Noticeboard not found"
// @Router /noticeboard/{id} [delete]
func DeleteNoticeboard(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	db := database.DBConn

	// Check the user has mod/admin role
	if !auth.IsSystemMod(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	// Check noticeboard exists
	var count int64
	// ORM migration site 6f1e9fffdf7e (wave 1).
	db.Table("noticeboards").Where("id = ?", id).Count(&count)
	if count == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Noticeboard not found")
	}

	// Delete the noticeboard
	// ORM migration site 1689d28e9c22 (wave 2).
	result := db.Table("noticeboards").Where("id = ?", id).Delete(nil)
	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to delete noticeboard")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
