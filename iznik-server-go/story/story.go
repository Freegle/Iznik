package story

import (
	"encoding/json"
	"log"
	"os"
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type StoryImage struct {
	ID           uint64          `json:"id"`
	Externaluid  string          `json:"externaluid"`
	Ouruid       string          `json:"ouruid"`
	Externalmods json.RawMessage `json:"externalmods"`
	Path         string          `json:"path"`
	PathThumb    string          `json:"paththumb"`
}

type Story struct {
	ID            uint64          `json:"id" gorm:"primary_key"`
	Userid        uint64          `json:"userid"`
	Date          *time.Time      `json:"date"`
	Public        bool            `json:"public"`
	Reviewed      bool            `json:"reviewed"`
	Headline      string          `json:"headline"`
	Story         string          `json:"story"`
	Imageid       uint64          `json:"imageid"`
	Imagearchived bool            `json:"-"`
	Imageuid      string          `json:"-"`
	Imagemods     json.RawMessage `json:"-"`
	Image         *StoryImage     `json:"image" gorm:"-"`
	StoryURL      string          `json:"url"`
}

func Single(c *fiber.Ctx) error {
	var s Story

	db := database.DBConn
	db.Table("users_stories").
		Select("users_stories.*, users_stories_images.id AS imageid, users_stories_images.archived AS imagearchived, users_stories_images.externaluid AS imageuid, users_stories_images.externalmods AS imagemods").
		Joins("LEFT JOIN users_stories_images ON users_stories_images.storyid = users_stories.id").
		Where("users_stories.id = ?", c.Params("id")).
		Scan(&s)

	if s.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Not found")
	}

	// SECURITY: an unreviewed or non-public story is only visible to its author, a
	// moderator of a group the author belongs to, or admin/support. This mirrors the
	// `reviewed = 1 AND public = 1` filter that List() and Group() already enforce;
	// Single() previously returned any story by id to anonymous callers.
	if !(s.Public && s.Reviewed) {
		if !canModStory(user.WhoAmI(c), s.ID) {
			return fiber.NewError(fiber.StatusNotFound, "Not found")
		}
	}

	if s.Imageid > 0 {
		if s.Imageuid != "" {
			s.Image = &StoryImage{
				ID:           s.Imageid,
				Ouruid:       s.Imageuid,
				Externalmods: s.Imagemods,
				Path:         misc.GetImageDeliveryUrl(s.Imageuid, string(s.Imagemods)),
				PathThumb:    misc.GetImageDeliveryUrl(s.Imageuid, string(s.Imagemods)),
			}
		} else if s.Imagearchived {
			s.Image = &StoryImage{
				ID:        s.Imageid,
				Path:      "https://" + os.Getenv("IMAGE_ARCHIVED_DOMAIN") + "/simg_" + strconv.FormatUint(s.Imageid, 10) + ".jpg",
				PathThumb: "https://" + os.Getenv("IMAGE_ARCHIVED_DOMAIN") + "/tsimg_" + strconv.FormatUint(s.Imageid, 10) + ".jpg",
			}
		} else {
			s.Image = &StoryImage{
				ID:        s.Imageid,
				Path:      "https://" + os.Getenv("IMAGE_DOMAIN") + "/simg_" + strconv.FormatUint(s.Imageid, 10) + ".jpg",
				PathThumb: "https://" + os.Getenv("IMAGE_DOMAIN") + "/tsimg_" + strconv.FormatUint(s.Imageid, 10) + ".jpg",
			}
		}
	}

	s.StoryURL = "https://" + os.Getenv("USER_SITE") + "/story/" + strconv.FormatUint(s.ID, 10)

	return c.JSON(s)
}

func List(c *fiber.Ctx) error {
	db := database.DBConn
	myid := user.WhoAmI(c)

	limit := c.Query("limit", "100")
	limit64, _ := strconv.ParseUint(limit, 10, 64)

	reviewed := c.Query("reviewed", "1")
	public := c.Query("public", "1")

	// Three
	// mutually-exclusive branches (authority / review-with-groups / plain)
	// times an optional newsletterreviewed filter (not reachable on the
	// authority branch) give 1 + 2 + 2 = 5 possible rendered forms, all
	// proven by the retired ormharness (shapes.json /
	// TestTier3Shapes_0ca4810292dc, removed in d22ba1d6c).
	// Each branch's WHERE is built as a single string and passed to ONE
	// Where() call: GORM's clause.Where wraps any fragment containing
	// "AND"/"OR" in an extra paren pair once there is more than one Where
	// expression to combine (clause/where.go buildExprs), which would
	// diverge from the golden.
	var tx *gorm.DB

	if authorityid := c.Query("authorityid"); authorityid != "" {
		// Filter stories by users whose location falls within the authority boundary.
		authorityid64, _ := strconv.ParseUint(authorityid, 10, 64)
		whereSQL := "reviewed = ? AND public = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL " +
			"AND locations.lat IS NOT NULL " +
			"AND ST_Contains((SELECT polygon FROM authorities WHERE id = ?), ST_SRID(POINT(locations.lng, locations.lat), ?))"
		tx = db.Table("users_stories").
			Select("DISTINCT users_stories.id").
			Joins("INNER JOIN users ON users.id = users_stories.userid").
			Joins("LEFT JOIN locations ON locations.id = users.lastlocation").
			Where(whereSQL, reviewed, public, authorityid64, utils.SRID).
			Order("date DESC").Limit(int(limit64))
	} else {
		// When reviewing (unreviewed stories), filter by moderator's active groups.
		modGroupIDs := user.GetActiveModGroupIDs(myid)

		var whereSQL string
		var whereArgs []interface{}

		if len(modGroupIDs) > 0 && reviewed == "0" {
			// review listing has no public filter, has 31-day date cutoff.
			storyCutoff := time.Now().AddDate(0, 0, -31).Format("2006-01-02")
			whereSQL = "reviewed = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL " +
				"AND users_stories.date > ? AND memberships.groupid IN (?) AND memberships.collection = ?"
			whereArgs = []interface{}{reviewed, storyCutoff, modGroupIDs, utils.COLLECTION_APPROVED}
			tx = db.Table("users_stories").
				Select("DISTINCT users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid").
				Joins("INNER JOIN memberships ON memberships.userid = users_stories.userid")
		} else {
			whereSQL = "reviewed = ? AND public = ? AND userid IS NOT NULL AND users.deleted IS NULL"
			whereArgs = []interface{}{reviewed, public}
			tx = db.Table("users_stories").
				Select("users_stories.id").
				Joins("INNER JOIN users ON users.id = users_stories.userid")
		}

		if newsletterreviewed := c.Query("newsletterreviewed"); newsletterreviewed != "" {
			whereSQL += " AND newsletterreviewed = ?"
			whereArgs = append(whereArgs, newsletterreviewed)
		}

		tx = tx.Where(whereSQL, whereArgs...).Order("date DESC").Limit(int(limit64))
	}

	var ids []uint64
	tx.Pluck("id", &ids)

	if ids == nil {
		ids = make([]uint64, 0)
	}

	return c.JSON(ids)
}

func Group(c *fiber.Ctx) error {
	db := database.DBConn

	limit := c.Query("limit", "100")
	limit64, _ := strconv.ParseUint(limit, 10, 64)
	groupid := c.Params("id", "0")
	groupid64, _ := strconv.ParseUint(groupid, 10, 64)

	reviewed := c.Query("reviewed", "1")
	public := c.Query("public", "1")

	var ids []uint64

	db.Table("users_stories").
		Select("DISTINCT users_stories.id").
		Joins("INNER JOIN memberships ON memberships.userid = users_stories.userid").
		Joins("INNER JOIN users ON users.id = users_stories.userid").
		Where("memberships.groupid = ? AND reviewed = ? AND public = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL",
			groupid64, reviewed, public).
		Order("date DESC").
		Limit(int(limit64)).
		Pluck("id", &ids)

	if ids == nil {
		ids = make([]uint64, 0)
	}

	return c.JSON(ids)
}

// canModStory checks if a user can modify a story.
// They can if they're the story owner, admin/support, or a moderator on a group
// the story author is a member of.
func canModStory(myid uint64, storyID uint64) bool {
	db := database.DBConn

	var authorID uint64
	db.Table("users_stories").Select("userid").Where("id = ?", storyID).Scan(&authorID)

	if authorID == 0 {
		return false
	}

	if authorID == myid {
		return true
	}

	if auth.IsAdminOrSupport(myid) {
		return true
	}

	// Check if moderator/owner on a group the story author is a member of.
	var count int64
	db.Table("memberships m1").
		Joins("INNER JOIN memberships m2 ON m2.groupid = m1.groupid").
		Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?) AND m1.collection = ? AND m2.collection = ?",
			myid, authorID, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED, utils.COLLECTION_APPROVED).
		Count(&count)

	return count > 0
}

// createStoryNewsfeedEntry creates a newsfeed entry when a story is reviewed and made public.
func createStoryNewsfeedEntry(userid uint64, storyID uint64) {
	db := database.DBConn

	var lat, lng *float64

	if userid > 0 {
		type UserLoc struct {
			Lat *float64
			Lng *float64
		}
		var ul UserLoc
		db.Table("users u").
			Select("l.lat, l.lng").
			Joins("LEFT JOIN locations l ON l.id = u.lastlocation").
			Where("u.id = ?", userid).
			Scan(&ul)
		lat = ul.Lat
		lng = ul.Lng
	}

	if lat == nil || lng == nil {
		return
	}

	result := db.Table("newsfeed").Create(map[string]interface{}{
		"type":           gorm.Expr("'Story'"),
		"userid":         userid,
		"storyid":        storyID,
		"position":       gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), ?)", *lng, *lat, utils.SRID),
		"hidden":         gorm.Expr("NULL"),
		"deleted":        gorm.Expr("NULL"),
		"reviewrequired": gorm.Expr("0"),
		"pinned":         gorm.Expr("0"),
	})

	if result.Error != nil {
		log.Printf("Failed to create story newsfeed entry: %v", result.Error)
	}
}

// @Summary Create a story
// @Tags story
// @Router /story [put]
func CreateStory(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type CreateRequest struct {
		Public   bool   `json:"public"`
		Headline string `json:"headline"`
		Story    string `json:"story"`
		Photo    uint64 `json:"photo"`
	}
	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Plain, isolated, literal single-row
	// INSERT; id read back via GORM's map-Create "@id" writeback. (An earlier
	// review cited this site as already converted using this exact pattern - it
	// wasn't; this is the first real conversion of it.)
	row := map[string]interface{}{
		"public":   req.Public,
		"userid":   myid,
		"headline": req.Headline,
		"story":    req.Story,
	}
	if err := db.Table("users_stories").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Database error")
	}
	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	if req.Photo > 0 && id > 0 {
		db.Table("users_stories_images").Where("id = ?", req.Photo).Update("storyid", id)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": id})
}

// toBoolInt converts a JSON value (bool or number) to an *int for DB storage.
// Returns nil if the value is nil, meaning the field was not present in the request.
func toBoolInt(v interface{}) *int {
	if v == nil {
		return nil
	}
	var val int
	switch t := v.(type) {
	case bool:
		if t {
			val = 1
		}
	case float64:
		val = int(t)
	default:
		return nil
	}
	return &val
}

// @Summary Update a story (mod review)
// @Tags story
// @Router /story [patch]
func UpdateStory(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Use interface{} for fields that may be sent as bool (true/false) or int (1/0).
	type UpdateRequest struct {
		ID                 uint64      `json:"id"`
		Public             interface{} `json:"public"`
		Headline           *string     `json:"headline"`
		Story              *string     `json:"story"`
		Reviewed           interface{} `json:"reviewed"`
		Newsletterreviewed interface{} `json:"newsletterreviewed"`
		Newsletter         interface{} `json:"newsletter"`
	}
	var req UpdateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing story ID")
	}

	if !canModStory(myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	db := database.DBConn

	// Get current state before update (for newsfeed side effect).
	type StoryState struct {
		Reviewed     bool
		Public       bool
		Userid       uint64
		Fromnewsfeed bool
	}
	var before StoryState
	db.Table("users_stories").
		Select("reviewed, public, userid, COALESCE(fromnewsfeed, 0) AS fromnewsfeed").
		Where("id = ?", req.ID).
		Scan(&before)

	// Update settable attributes.
	if p := toBoolInt(req.Public); p != nil {
		db.Table("users_stories").Where("id = ?", req.ID).Update("public", *p)
	}
	if req.Headline != nil {
		db.Table("users_stories").Where("id = ?", req.ID).Update("headline", *req.Headline)
	}
	if req.Story != nil {
		db.Table("users_stories").Where("id = ?", req.ID).Update("story", *req.Story)
	}
	if r := toBoolInt(req.Reviewed); r != nil {
		db.Table("users_stories").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"reviewed": *r, "reviewedby": myid})
	}
	if nr := toBoolInt(req.Newsletterreviewed); nr != nil {
		db.Table("users_stories").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"newsletterreviewed": *nr, "newsletterreviewedby": myid})
	}
	if n := toBoolInt(req.Newsletter); n != nil {
		db.Table("users_stories").Where("id = ?", req.ID).Update("newsletter", *n)
	}

	// Side effect: if story just became reviewed+public and wasn't from newsfeed, create newsfeed entry.
	newsfeedBefore := before.Reviewed && before.Public

	var after StoryState
	db.Table("users_stories").
		Select("reviewed, public, userid, COALESCE(fromnewsfeed, 0) AS fromnewsfeed").
		Where("id = ?", req.ID).
		Scan(&after)
	newsfeedAfter := after.Reviewed && after.Public

	if !newsfeedBefore && newsfeedAfter && !before.Fromnewsfeed {
		createStoryNewsfeedEntry(before.Userid, req.ID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// @Summary Like a story
// @Tags story
// @Router /story/like [post]
func LikeStory(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type LikeRequest struct {
		ID uint64 `json:"id"`
	}
	var req LikeRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing story ID")
	}

	db := database.DBConn
	// Converted together with its
	// identical twin at PostStory's Like case (0d3865cbb34e): a half-converted
	// pair renumbers the survivor's site ID, so gate (h) refuses the split
	// state.
	db.Table("users_stories_likes").Clauses(clause.Insert{Modifier: "IGNORE"}).
		Create(map[string]interface{}{"storyid": req.ID, "userid": myid})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// @Summary Unlike a story
// @Tags story
// @Router /story/unlike [post]
func UnlikeStory(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type UnlikeRequest struct {
		ID uint64 `json:"id"`
	}
	var req UnlikeRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing story ID")
	}

	db := database.DBConn
	db.Table("users_stories_likes").Where("storyid = ? AND userid = ?", req.ID, myid).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// @Summary Post story action (Like/Unlike)
// @Tags story
// @Router /story [post]
func PostStory(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type PostRequest struct {
		ID     uint64 `json:"id"`
		Action string `json:"action"`
	}
	var req PostRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing story ID")
	}

	db := database.DBConn

	switch req.Action {
	case "Like":
		// Twin of 713e8b8dab08 above.
		db.Table("users_stories_likes").Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"storyid": req.ID, "userid": myid})
	case "Unlike":
		db.Table("users_stories_likes").Where("storyid = ? AND userid = ?", req.ID, myid).Delete(nil)
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// @Summary Delete a story
// @Tags story
// @Router /story/{id} [delete]
func DeleteStory(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}

	if !canModStory(myid, id) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	db := database.DBConn
	db.Table("users_stories").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
