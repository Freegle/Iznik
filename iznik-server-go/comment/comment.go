package comment

import (
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

// CommentItem is a flat comment representation. Client fetches user details separately via /user/:id.
type CommentItem struct {
	ID       uint64     `json:"id"`
	Userid   uint64     `json:"userid"`
	Groupid  *uint64    `json:"groupid"`
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

	// List comments for moderated groups.
	groupid, _ := strconv.ParseUint(c.Query("groupid", "0"), 10, 64)
	contextID, _ := strconv.ParseUint(c.Query("context", "0"), 10, 64)

	// Get groups where user is moderator + admin/support check in parallel.
	var wg sync.WaitGroup
	var modGroupIDs []uint64
	var isAdmin bool

	wg.Add(2)
	go func() {
		defer wg.Done()
		modGroupIDs = user.GetActiveModGroupIDs(myid)
	}()
	go func() {
		defer wg.Done()
		isAdmin = auth.IsAdminOrSupport(myid)
	}()
	wg.Wait()

	if len(modGroupIDs) == 0 && !isAdmin {
		return c.JSON(fiber.Map{
			"comments": make([]CommentItem, 0),
			"context":  nil,
		})
	}

	// Build query using keyset pagination on id (never null, unique).
	//
	// Three
	// independent toggles - groupid>0, contextID>0, isAdmin - give 2x2x2 = 8
	// possible rendered forms, all declared in ormharness/shapes.json and
	// proven by TestTier3Shapes_f1e9e49a9c89 (iznik-server-go/test). The
	// WHERE is built as a single string and passed to ONE Where() call:
	// GORM's clause.Where wraps any fragment containing "AND"/"OR" in an
	// extra paren pair once there is more than one Where expression to
	// combine (clause/where.go buildExprs), which would diverge from the
	// golden.
	whereSQL := ""
	var whereArgs []interface{}

	if groupid > 0 {
		whereSQL += "groupid = ? AND "
		whereArgs = append(whereArgs, groupid)
	}

	if contextID > 0 {
		whereSQL += "users_comments.id < ? AND "
		whereArgs = append(whereArgs, contextID)
	}

	if !isAdmin {
		// Admin/support can see all comments.
		whereSQL += "(groupid IN (?) OR users_comments.byuserid = ?) AND "
		whereArgs = append(whereArgs, modGroupIDs, myid)
	}

	whereSQL += "1=1"

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

	// Get moderator group IDs and admin/support check in parallel.
	var wg sync.WaitGroup
	var modGroupIDs []uint64
	var isAdmin bool

	wg.Add(2)
	go func() {
		defer wg.Done()
		modGroupIDs = user.GetActiveModGroupIDs(myid)
	}()
	go func() {
		defer wg.Done()
		isAdmin = auth.IsAdminOrSupport(myid)
	}()
	wg.Wait()

	var row CommentItem

	if isAdmin {
		db.Table("users_comments").Where("id = ?", id).Scan(&row)
	} else if len(modGroupIDs) > 0 {
		db.Table("users_comments").Where("id = ? AND groupid IN ?", id, modGroupIDs).Scan(&row)
	}

	if row.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Comment not found")
	}

	row.Flagged = row.Flag

	return c.JSON(row)
}

type CreateRequest struct {
	Userid  uint64  `json:"userid"`
	Groupid *uint64 `json:"groupid"`
	User1   *string `json:"user1"`
	User2   *string `json:"user2"`
	User3   *string `json:"user3"`
	User4   *string `json:"user4"`
	User5   *string `json:"user5"`
	User6   *string `json:"user6"`
	User7   *string `json:"user7"`
	User8   *string `json:"user8"`
	User9   *string `json:"user9"`
	User10  *string `json:"user10"`
	User11  *string `json:"user11"`
	Flag    bool    `json:"flag"`
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

// canModerate checks if the user is a moderator/owner of the group, or admin/support.
func canModerate(myid uint64, groupid *uint64) bool {
	if auth.IsAdminOrSupport(myid) {
		return true
	}

	if groupid == nil || *groupid == 0 {
		return false
	}

	db := database.DBConn
	var role string
	db.Table("memberships").Select("role").Where("userid = ? AND groupid = ? AND collection = ?", myid, *groupid, utils.COLLECTION_APPROVED).Scan(&role)

	return role == utils.ROLE_MODERATOR || role == utils.ROLE_OWNER
}

// canModerateComment checks if the user can modify a specific existing comment.
func canModerateComment(myid uint64, commentID uint64) bool {
	db := database.DBConn

	var groupid *uint64
	db.Table("users_comments").Select("groupid").Where("id = ?", commentID).Scan(&groupid)

	return canModerate(myid, groupid)
}

// flagOthers flags a user for review in all their groups except the given group.
// Sets reviewrequestedat on each membership so mods on those groups see the flag.
func flagOthers(userid uint64, groupid uint64) {
	db := database.DBConn

	var otherGroupIDs []uint64
	db.Table("memberships").Where("userid = ? AND groupid != ?", userid, groupid).Pluck("groupid", &otherGroupIDs)

	now := time.Now().Format("2006-01-02 15:04")
	reason := "Note flagged to other groups"

	for _, gid := range otherGroupIDs {
		db.Table("memberships").Where("groupid = ? AND userid = ?", gid, userid).
			Updates(map[string]interface{}{"reviewreason": reason, "reviewrequestedat": now})
	}
}

// Create handles POST /api/comment
func Create(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Userid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "userid is required")
	}

	if !canModerate(myid, req.Groupid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
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
		"groupid":  req.Groupid,
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

	// Flag user in other groups if flag is set
	if id > 0 && req.Flag && req.Groupid != nil && *req.Groupid > 0 {
		flagOthers(req.Userid, *req.Groupid)
	}

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

	var req PatchRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	if !canModerateComment(myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
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

	// Flag user in other groups if flag is set to true
	if req.Flag != nil && *req.Flag {
		var commentUserid uint64
		var commentGroupid uint64
		db.Table("users_comments").Select("userid, groupid").Where("id = ?", req.ID).Row().Scan(&commentUserid, &commentGroupid)
		if commentUserid > 0 && commentGroupid > 0 {
			flagOthers(commentUserid, commentGroupid)
		}
	}

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

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid comment ID")
	}

	if !canModerateComment(myid, id) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
	}

	db := database.DBConn
	db.Table("users_comments").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{
		"success": true,
	})
}
