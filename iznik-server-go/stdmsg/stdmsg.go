package stdmsg

import (
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
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
	database.DBConn.Table("mod_configs").Select("createdby").Where("id = ?", configid).Scan(&createdby)
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

	// Plain, isolated, literal single-row
	// INSERT (subjpref/subjsuff are fixed empty-string literals); id read back via
	// GORM's map-Create "@id" writeback.
	row := map[string]interface{}{
		"configid": req.Configid,
		"title":    req.Title,
		"subjpref": gorm.Expr("''"),
		"subjsuff": gorm.Expr("''"),
		"body":     gorm.Expr("''"),
	}
	if err := db.Table("mod_stdmsgs").Create(row).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 1, "status": "Create failed"})
	}
	newIDInt, _ := row["@id"].(int64)
	newID := uint64(newIDInt)

	// Apply optional attributes.
	if req.Action != "" {
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("action", req.Action)
	}
	if req.Subjpref != "" {
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("subjpref", req.Subjpref)
	}
	if req.Subjsuff != "" {
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("subjsuff", req.Subjsuff)
	}
	if req.Body != "" {
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("body", req.Body)
	}
	if req.Rarelyused != 0 {
		db.Table("mod_stdmsgs").Where("id = ?", newID).Update("rarelyused", req.Rarelyused)
	}
	if req.Autosend != 0 {
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
	db.Table("mod_stdmsgs").Select("configid").Where("id = ?", req.ID).Scan(&configid)
	if configid == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	if !canModifyConfig(myid, configid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to modify config"})
	}

	if req.Title != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("title", *req.Title)
	}
	if req.Action != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("action", *req.Action)
	}
	if req.Subjpref != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("subjpref", *req.Subjpref)
	}
	if req.Subjsuff != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("subjsuff", *req.Subjsuff)
	}
	if req.Body != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("body", *req.Body)
	}
	if req.Rarelyused != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("rarelyused", *req.Rarelyused)
	}
	if req.Autosend != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("autosend", *req.Autosend)
	}
	if req.Newmodstatus != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("newmodstatus", *req.Newmodstatus)
	}
	if req.Newdelstatus != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("newdelstatus", *req.Newdelstatus)
	}
	if req.Edittext != nil {
		db.Table("mod_stdmsgs").Where("id = ?", req.ID).Update("edittext", *req.Edittext)
	}
	if req.Insert != nil {
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
	db.Table("mod_stdmsgs").Select("configid").Where("id = ?", req.ID).Scan(&configid)
	if configid == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Invalid stdmsg id"})
	}

	if !canModifyConfig(myid, configid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to modify config"})
	}

	db.Table("mod_stdmsgs").Where("id = ?", req.ID).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
