package comment

import (
	"strconv"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// CommentItem is a flat comment representation. Client fetches user details separately via /user/:id.
type CommentItem struct {
	ID       uint64     `json:"id"`
	Userid   uint64     `json:"userid"`
	Byuserid *uint64    `json:"byuserid"`
	Date     *time.Time `json:"date"`
	Reviewed *time.Time `json:"reviewed"`
	User1    *string    `json:"user1"`
	User2    *string    `json:"user2"`
	User3    *string    `json:"user3"`
	User4    *string    `json:"user4"`
	User5    *string    `json:"user5"`
	User6    *string    `json:"user6"`
	User7    *string    `json:"user7"`
	User8    *string    `json:"user8"`
	User9    *string    `json:"user9"`
	User10   *string    `json:"user10"`
	User11   *string    `json:"user11"`
	Flag     bool       `json:"flag"`
	Flagged  bool       `json:"flagged"`
}

// Get handles GET /api/comment
// Returns flat comment objects with userid/byuserid as IDs. Client fetches user details separately.
func Get(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)

	if id > 0 {
		return getSingle(c, myid, id)
	}

	contextID, _ := strconv.ParseUint(c.Query("context", "0"), 10, 64)

	// Comment notes are a national moderator tool, not scoped to a community.
	if !auth.IsModerator(myid) {
		return c.JSON(fiber.Map{
			"comments": make([]CommentItem, 0),
			"context":  nil,
		})
	}

	// Keyset pagination on id (never null, unique).
	whereSQL := "1=1"
	var whereArgs []interface{}

	if contextID > 0 {
		whereSQL = "users_comments.id < ?"
		whereArgs = append(whereArgs, contextID)
	}

	var rows []CommentItem
	db.Table("users_comments").Where(whereSQL, whereArgs...).
		Order("users_comments.id DESC").Limit(10).Scan(&rows)

	if len(rows) == 0 {
		return c.JSON(fiber.Map{
			"comments": make([]CommentItem, 0),
			"context":  nil,
		})
	}

	// Set Flagged from Flag for each row.
	for i := range rows {
		rows[i].Flagged = rows[i].Flag
	}

	// Context is the ID of the last row; always set when rows are non-empty
	// so the frontend can request the next page. Returns nil only when zero rows.
	ctx := fiber.Map{"id": rows[len(rows)-1].ID}

	return c.JSON(fiber.Map{
		"comments": rows,
		"context":  ctx,
	})
}

func getSingle(c *fiber.Ctx, myid uint64, id uint64) error {
	db := database.DBConn

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusNotFound, "Comment not found")
	}

	var row CommentItem
	db.Table("users_comments").Where("id = ?", id).Scan(&row)

	if row.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Comment not found")
	}

	row.Flagged = row.Flag

	return c.JSON(row)
}

type CreateRequest struct {
	Userid uint64  `json:"userid"`
	User1  *string `json:"user1"`
	User2  *string `json:"user2"`
	User3  *string `json:"user3"`
	User4  *string `json:"user4"`
	User5  *string `json:"user5"`
	User6  *string `json:"user6"`
	User7  *string `json:"user7"`
	User8  *string `json:"user8"`
	User9  *string `json:"user9"`
	User10 *string `json:"user10"`
	User11 *string `json:"user11"`
	Flag   bool    `json:"flag"`
}

type PatchRequest struct {
	ID     uint64  `json:"id"`
	User1  *string `json:"user1"`
	User2  *string `json:"user2"`
	User3  *string `json:"user3"`
	User4  *string `json:"user4"`
	User5  *string `json:"user5"`
	User6  *string `json:"user6"`
	User7  *string `json:"user7"`
	User8  *string `json:"user8"`
	User9  *string `json:"user9"`
	User10 *string `json:"user10"`
	User11 *string `json:"user11"`
	Flag   *bool   `json:"flag"`
}

// Create handles POST /api/comment
func Create(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
	}

	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Userid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "userid is required")
	}

	db := database.DBConn

	var flag int
	if req.Flag {
		flag = 1
	}

	// Plain, isolated, literal single-row
	// INSERT; id read back via GORM's map-Create "@id" writeback.
	row := map[string]interface{}{
		"userid":   req.Userid,
		"byuserid": myid,
		"user1":    req.User1,
		"user2":    req.User2,
		"user3":    req.User3,
		"user4":    req.User4,
		"user5":    req.User5,
		"user6":    req.User6,
		"user7":    req.User7,
		"user8":    req.User8,
		"user9":    req.User9,
		"user10":   req.User10,
		"user11":   req.User11,
		"flag":     flag,
	}
	if err := db.Table("users_comments").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create comment")
	}
	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	return c.JSON(fiber.Map{
		"id": id,
	})
}

// Edit handles PATCH /api/comment
func Edit(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
	}

	var req PatchRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	db.Table("users_comments").Where("id = ?", req.ID).Updates(map[string]interface{}{
		"user1":    req.User1,
		"user2":    req.User2,
		"user3":    req.User3,
		"user4":    req.User4,
		"user5":    req.User5,
		"user6":    req.User6,
		"user7":    req.User7,
		"user8":    req.User8,
		"user9":    req.User9,
		"user10":   req.User10,
		"user11":   req.User11,
		"flag":     gorm.Expr("COALESCE(?, flag)", req.Flag),
		"byuserid": myid,
		"reviewed": gorm.Expr("NOW()"),
	})

	return c.JSON(fiber.Map{
		"success": true,
	})
}

// Delete handles DELETE /api/comment/:id
func Delete(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid comment ID")
	}

	db := database.DBConn

	db.Table("users_comments").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{
		"success": true,
	})
}
