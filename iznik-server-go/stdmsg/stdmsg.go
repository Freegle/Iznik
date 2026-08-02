package stdmsg

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

type StdMsg struct {
	ID           uint64  `json:"id" gorm:"primary_key"`
	Configid     uint64  `json:"configid"`
	Title        string  `json:"title"`
	Action       string  `json:"action"`
	Subjpref     string  `json:"subjpref"`
	Subjsuff     string  `json:"subjsuff"`
	Body         string  `json:"body"`
	Rarelyused   int     `json:"rarelyused"`
	Autosend     int     `json:"autosend"`
	Newmodstatus string  `json:"newmodstatus"`
	Newdelstatus string  `json:"newdelstatus"`
	Edittext     string  `json:"edittext"`
	Insert       *string `json:"insert"`
}

// canModifyConfig checks if user can modify the parent config.
func canModifyConfig(myid uint64, configid uint64) bool {
	if auth.IsAdminOrSupport(myid) {
		return true
	}

	var createdby *uint64
	var protected int
	// ORM migration site a1df6eefdf33 (wave 1).
	database.DBConn.Table("mod_configs").Select("createdby").Where("id = ?", configid).Scan(&createdby)
	// ORM migration site 22a63b0ac626 (wave 1).
	database.DBConn.Table("mod_configs").Select("protected").Where("id = ?", configid).Scan(&protected)

	if createdby != nil && *createdby == myid {
		return true
	}
	if protected == 0 {
		return true
	}
	return false
}

// GetStdMsg handles GET /stdmsg.
//
// @Summary Get standard message
// @Tags stdmsg
// @Produce json
// @Param id query integer true "StdMsg ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/stdmsg [get]
func GetStdMsg(c *fiber.Ctx) error {
	// Standard messages are moderator canned-reply templates. Reading them requires
	// a moderator, matching PostStdMsg/PatchStdMsg; the GET was previously wide open.
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}
	if !auth.IsSystemMod(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to view standard messages"})
	}

	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)
	if id == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	db := database.DBConn
	var msg StdMsg
	// ORM migration site ae1076412fce (wave 1).
	db.Table("mod_stdmsgs").Where("id = ?", id).Scan(&msg)
	if msg.ID == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"stdmsg": msg,
	})
}

// PostStdMsg handles POST /stdmsg to create a new standard message.
//
// @Summary Create standard message
// @Tags stdmsg
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/stdmsg [post]
func PostStdMsg(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	if !auth.IsSystemMod(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to create configs"})
	}

	type CreateRequest struct {
		Configid     uint64  `json:"configid"`
		Title        string  `json:"title"`
		Action       string  `json:"action"`
		Subjpref     string  `json:"subjpref"`
		Subjsuff     string  `json:"subjsuff"`
		Body         string  `json:"body"`
		Rarelyused   int     `json:"rarelyused"`
		Autosend     int     `json:"autosend"`
		Newmodstatus string  `json:"newmodstatus"`
		Newdelstatus string  `json:"newdelstatus"`
		Edittext     string  `json:"edittext"`
		Insert       *string `json:"insert"`
	}

	var req CreateRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.Title == "" {
		req.Title = c.FormValue("title", c.Query("title", ""))
	}
	if req.Configid == 0 {
		req.Configid, _ = strconv.ParseUint(c.FormValue("configid", c.Query("configid", "0")), 10, 64)
	}

	if req.Title == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 3, "status": "Must supply title"})
	}
	if req.Configid == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 3, "status": "Must supply configid"})
	}

	db := database.DBConn

	// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
	// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
	// parallel load (GORM's connection pool may assign a different connection).
	sqlDB, err := db.DB()
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Database error"})
	}
	sqlResult, err := sqlDB.Exec("INSERT INTO mod_stdmsgs (configid, title, subjpref, subjsuff, body) VALUES (?, ?, '', '', '')", req.Configid, req.Title)
	if err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}

	var newID uint64
	lastID, err := sqlResult.LastInsertId()
	if err == nil && lastID > 0 {
		newID = uint64(lastID)
	}

	// Apply optional attributes.
	if req.Action != "" {
		// ORM migration site 46c5fb361ea2 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("action", req.Action)
	}
	if req.Subjpref != "" {
		// ORM migration site 116e92a68ac4 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("subjpref", req.Subjpref)
	}
	if req.Subjsuff != "" {
		// ORM migration site 46bebb6b38be (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("subjsuff", req.Subjsuff)
	}
	if req.Body != "" {
		// ORM migration site 8ad8c1589208 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("body", req.Body)
	}
	if req.Rarelyused != 0 {
		// ORM migration site 948c386a078b (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("rarelyused", req.Rarelyused)
	}
	if req.Autosend != 0 {
		// ORM migration site 2ba672ef4292 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("autosend", req.Autosend)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

// PatchStdMsg handles PATCH /stdmsg to update attributes.
//
// @Summary Update standard message
// @Tags stdmsg
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/stdmsg [patch]
func PatchStdMsg(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	type PatchRequest struct {
		ID           uint64  `json:"id"`
		Title        *string `json:"title"`
		Action       *string `json:"action"`
		Subjpref     *string `json:"subjpref"`
		Subjsuff     *string `json:"subjsuff"`
		Body         *string `json:"body"`
		Rarelyused   *int    `json:"rarelyused"`
		Autosend     *int    `json:"autosend"`
		Newmodstatus *string `json:"newmodstatus"`
		Newdelstatus *string `json:"newdelstatus"`
		Edittext     *string `json:"edittext"`
		Insert       *string `json:"insert"`
	}

	var req PatchRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}

	if req.ID == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	db := database.DBConn

	// Get the stdmsg to find its configid.
	var configid uint64
	// ORM migration site 102535ad9bab (wave 1).
	db.Table("mod_stdmsgs").Select("configid").Where("id = ?", req.ID).Scan(&configid)
	if configid == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	if !canModifyConfig(myid, configid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to modify config"})
	}

	if req.Title != nil {
		// ORM migration site 0d06dc492d55 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("title", *req.Title)
	}
	if req.Action != nil {
		// ORM migration site cef43692b937 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("action", *req.Action)
	}
	if req.Subjpref != nil {
		// ORM migration site f29e07819b1a (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("subjpref", *req.Subjpref)
	}
	if req.Subjsuff != nil {
		// ORM migration site f9fc836339e6 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("subjsuff", *req.Subjsuff)
	}
	if req.Body != nil {
		// ORM migration site 5e8a95612260 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("body", *req.Body)
	}
	if req.Rarelyused != nil {
		// ORM migration site 82fe128d30d3 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("rarelyused", *req.Rarelyused)
	}
	if req.Autosend != nil {
		// ORM migration site 6a8c185c8d22 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("autosend", *req.Autosend)
	}
	if req.Newmodstatus != nil {
		// ORM migration site 8e96c309ddf2 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("newmodstatus", *req.Newmodstatus)
	}
	if req.Newdelstatus != nil {
		// ORM migration site 7ab15f7bfb8f (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("newdelstatus", *req.Newdelstatus)
	}
	if req.Edittext != nil {
		// ORM migration site 4dcf8ff38c9f (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("edittext", *req.Edittext)
	}
	if req.Insert != nil {
		// ORM migration site 2379cd419502 (wave 2).
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("insert", *req.Insert)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteStdMsg handles DELETE /stdmsg.
//
// @Summary Delete standard message
// @Tags stdmsg
// @Produce json
// @Param id query integer true "StdMsg ID"
// @Security BearerAuth
// @Router /api/stdmsg [delete]
func DeleteStdMsg(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	type DeleteRequest struct {
		ID uint64 `json:"id"`
	}

	var req DeleteRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		c.BodyParser(&req)
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.Query("id", "0"), 10, 64)
	}

	if req.ID == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	db := database.DBConn

	var configid uint64
	// ORM migration site d6c28a45c7b1 (wave 1).
	db.Table("mod_stdmsgs").Select("configid").Where("id = ?", req.ID).Scan(&configid)
	if configid == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	if !canModifyConfig(myid, configid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to modify config"})
	}

	// ORM migration site 3157418b1d37 (wave 2).
	db.Table("mod_stdmsgs").Where("id = ?", req.ID).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
