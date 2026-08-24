package tryst

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type Tryst struct {
	ID             uint64  `json:"id" gorm:"primary_key"`
	User1          uint64  `json:"user1"`
	User2          uint64  `json:"user2"`
	Arrangedat     string  `json:"arrangedat"`
	Arrangedfor    *string `json:"arrangedfor"`
	User1confirmed *string `json:"user1confirmed"`
	User2confirmed *string `json:"user2confirmed"`
	User1declined  *string `json:"user1declined"`
	User2declined  *string `json:"user2declined"`
}

// canSee checks if a user is one of the two participants.
func canSee(myid uint64, t *Tryst) bool {
	return t.ID > 0 && (t.User1 == myid || t.User2 == myid)
}

// calendarLink generates an Add to Calendar link for a tryst, creating a
// 1-hour event starting at the arrangedfor time.
//
// The event data is base64url-encoded JSON in a `data` query param, matching
// the format iznik-batch's TrystService::buildCalendarLink() already uses for
// the calendar invite email, and that AddToCalendar.vue / pages/calendar.client.vue
// expect. A previous version of this function returned a raw Google Calendar
// render URL, which those consumers can't parse - the button appeared to do
// nothing when tapped.
func calendarLink(arrangedfor *string) string {
	if arrangedfor == nil || *arrangedfor == "" {
		return ""
	}

	// GORM may return datetime as either "2006-01-02 15:04:05" or "2006-01-02T15:04:05Z".
	t, err := time.Parse("2006-01-02 15:04:05", *arrangedfor)
	if err != nil {
		t, err = time.Parse(time.RFC3339, *arrangedfor)
		if err != nil {
			return ""
		}
	}

	t = t.UTC()
	end := t.Add(time.Hour)

	eventData := map[string]string{
		"name":        "Freegle Handover",
		"description": "Arrange handover of Freegle item",
		"startDate":   t.Format("2006-01-02"),
		"startTime":   t.Format("15:04"),
		"endTime":     end.Format("15:04"),
		"timeZone":    "UTC",
		"location":    "",
	}

	jsonData, err := json.Marshal(eventData)
	if err != nil {
		return ""
	}

	encoded := base64.RawURLEncoding.EncodeToString(jsonData)

	return fmt.Sprintf("https://%s/calendar?data=%s", os.Getenv("USER_SITE"), encoded)
}

// GetTryst handles GET /tryst - list user's trysts or single by ID.
//
// @Summary Get trysts
// @Tags tryst
// @Produce json
// @Param id query integer false "Tryst ID for single"
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /api/tryst [get]
func GetTryst(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn
	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)

	if id > 0 {
		// Single tryst.
		var t Tryst
		db.Table("trysts").Where("id = ?", id).Scan(&t)
		if !canSee(myid, &t) {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}

		return c.JSON(fiber.Map{
			"ret":    0,
			"status": "Success",
			"tryst": fiber.Map{
				"id":           t.ID,
				"user1":        t.User1,
				"user2":        t.User2,
				"arrangedat":   t.Arrangedat,
				"arrangedfor":  t.Arrangedfor,
				"calendarLink": calendarLink(t.Arrangedfor),
			},
		})
	}

	// List all future trysts for user.
	var trysts []Tryst
	db.Table("trysts").Where("(user1 = ? OR user2 = ?) AND arrangedfor >= NOW()", myid, myid).Scan(&trysts)

	result := make([]map[string]interface{}, len(trysts))
	for i, t := range trysts {
		result[i] = map[string]interface{}{
			"id":           t.ID,
			"user1":        t.User1,
			"user2":        t.User2,
			"arrangedat":   t.Arrangedat,
			"arrangedfor":  t.Arrangedfor,
			"calendarLink": calendarLink(t.Arrangedfor),
		}
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"trysts": result,
	})
}

// CreateTryst handles PUT /tryst to create a new tryst.
//
// @Summary Create tryst
// @Tags tryst
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/tryst [put]
func CreateTryst(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type CreateRequest struct {
		User1       uint64 `json:"user1"`
		User2       uint64 `json:"user2"`
		Arrangedfor string `json:"arrangedfor"`
	}

	var req CreateRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.User1 == 0 {
		req.User1, _ = strconv.ParseUint(c.FormValue("user1", c.Query("user1", "0")), 10, 64)
	}
	if req.User2 == 0 {
		req.User2, _ = strconv.ParseUint(c.FormValue("user2", c.Query("user2", "0")), 10, 64)
	}
	if req.Arrangedfor == "" {
		req.Arrangedfor = c.FormValue("arrangedfor", c.Query("arrangedfor", ""))
	}

	if req.User1 == 0 || req.User2 == 0 || req.Arrangedfor == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
	}
	if req.User1 == req.User2 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
	}

	// Caller must be one of the two participants.
	if myid != req.User1 && myid != req.User2 {
		return fiber.NewError(fiber.StatusForbidden, "Must be a participant")
	}

	db := database.DBConn

	// Verify a chat exists between the two users.
	// An ordinary
	// literal-WHERE COUNT, swept into the "INSERT id read back" category by
	// this function also containing CreateTryst's INSERT (site b0e6f29b54bd,
	// was 938d9dc56c71 before its bug fix) further down - this statement
	// doesn't touch that INSERT or read back any id.
	var chatCount int64
	db.Table("chat_rooms").
		Select("COUNT(*)").
		Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", req.User1, req.User2, req.User2, req.User1).
		Scan(&chatCount)
	if chatCount == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "No chat exists between these users")
	}

	// BUG FIX + ORM migration site 938d9dc56c71. Changing the SQL text would
	// rehash this to b0e6f29b54bd, but that id describes a fixed-but-still-raw
	// statement that never existed in any committed tree (the fix and the
	// conversion landed together), and the manifest must only ever record raw
	// SQL that was really there. So the site keeps the identity it had, and the
	// gap between its golden and what we now render is recorded as this site's
	// approvedDiff, marked as a deliberate behaviour change.
	//
	// It was kept raw with a wrong reason claiming GORM couldn't surface
	// LastInsertId for an ODKU insert: the ODKU clause used to be just
	// "arrangedat = NOW()" with no
	// "id = LAST_INSERT_ID(id)" forcing, so on a duplicate-key hit (unique
	// key arrangedfor_2 on (arrangedfor, user1, user2) - reachable any time a
	// caller re-arranges the SAME tryst) MySQL's LAST_INSERT_ID() reported 0,
	// not the existing row's id, and this handler returned {"id": 0} straight
	// to the client. Confirmed live against the test DB before this change:
	// the unfixed clause returns id 0 on the second (duplicate) insert; the
	// same sequence with "id = LAST_INSERT_ID(id)" added returns the original
	// row's id both times - see test/tryst_test.go's
	// TestCreateTrystDuplicateReturnsExistingID. Forcing the id is the same
	// idiom already used by every other ODKU/INSERT-IGNORE id-read-back site
	// in this codebase (chatroom.go's GetOrCreateUser2ModChat/User2UserChat,
	// group.go's CreateGroup) - .Clauses(res) is required, not
	// .Set("gorm:result", res), which silently does nothing.
	res := gorm.WithResult()
	tx := db.Table("trysts").Clauses(res, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			{Column: clause.Column{Name: "arrangedat"}, Value: gorm.Expr("NOW()")},
		},
	}).Create(map[string]interface{}{
		"user1": req.User1, "user2": req.User2, "arrangedfor": req.Arrangedfor,
	})
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Create failed")
	}

	var newID uint64
	if res.Result != nil {
		if lastID, idErr := res.Result.LastInsertId(); idErr == nil && lastID > 0 {
			newID = uint64(lastID)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

// PatchTryst handles PATCH /tryst to update arrangedfor.
//
// @Summary Update tryst
// @Tags tryst
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/tryst [patch]
func PatchTryst(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type PatchRequest struct {
		ID          uint64 `json:"id"`
		Arrangedfor string `json:"arrangedfor"`
	}

	var req PatchRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	db := database.DBConn
	var t Tryst
	db.Table("trysts").Where("id = ?", req.ID).Scan(&t)

	if !canSee(myid, &t) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	if req.Arrangedfor != "" {
		db.Table("trysts").Where("id = ?", req.ID).Update("arrangedfor", req.Arrangedfor)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// PostTryst handles POST /tryst for confirm/decline actions.
//
// @Summary Confirm or decline tryst
// @Tags tryst
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/tryst [post]
func PostTryst(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type ActionRequest struct {
		ID      uint64 `json:"id"`
		Confirm bool   `json:"confirm"`
		Decline bool   `json:"decline"`
	}

	var req ActionRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	db := database.DBConn
	var t Tryst
	db.Table("trysts").Where("id = ?", req.ID).Scan(&t)

	if !canSee(myid, &t) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	// Determine which user column to update.
	isUser1 := t.User1 == myid

	if req.Confirm {
		if isUser1 {
			db.Table("trysts").Where("id = ?", req.ID).Update("user1confirmed", gorm.Expr("NOW()"))
		} else {
			db.Table("trysts").Where("id = ?", req.ID).Update("user2confirmed", gorm.Expr("NOW()"))
		}
	}

	if req.Decline {
		if isUser1 {
			db.Table("trysts").Where("id = ?", req.ID).Update("user1declined", gorm.Expr("NOW()"))
		} else {
			db.Table("trysts").Where("id = ?", req.ID).Update("user2declined", gorm.Expr("NOW()"))
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteTryst handles DELETE /tryst.
//
// @Summary Delete tryst
// @Tags tryst
// @Produce json
// @Param id query integer true "Tryst ID"
// @Security BearerAuth
// @Router /api/tryst [delete]
func DeleteTryst(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Accept id from query string or JSON body (frontend sends DELETE with JSON body).
	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)
	if id == 0 {
		type DeleteRequest struct {
			ID uint64 `json:"id"`
		}
		var req DeleteRequest
		if err := c.BodyParser(&req); err == nil {
			id = req.ID
		}
	}
	if id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	db := database.DBConn
	var t Tryst
	db.Table("trysts").Where("id = ?", id).Scan(&t)

	if !canSee(myid, &t) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	db.Table("trysts").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
