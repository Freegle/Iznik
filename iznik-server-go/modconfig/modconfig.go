package modconfig

import (
	stdlog "log"
	"strconv"
	"strings"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

type ModConfig struct {
	ID             uint64  `json:"id" gorm:"primary_key"`
	Name           string  `json:"name"`
	Createdby      *uint64 `json:"createdby"`
	Fromname       string  `json:"fromname"`
	Ccrejectto     string  `json:"ccrejectto"`
	Ccrejectaddr   string  `json:"ccrejectaddr"`
	Ccfollowupto   string  `json:"ccfollowupto"`
	Ccfollowupaddr string  `json:"ccfollowupaddr"`
	Ccrejmembto    string  `json:"ccrejmembto"`
	Ccrejmembaddr  string  `json:"ccrejmembaddr"`
	Ccfollmembto   string  `json:"ccfollmembto"`
	Ccfollmembaddr string  `json:"ccfollmembaddr"`
	Protected      int     `json:"protected"`
	Messageorder   *string `json:"messageorder"`
	Network        string  `json:"network"`
	Coloursubj     int     `json:"coloursubj"`
	Subjreg        string  `json:"subjreg"`
	Subjlen        int     `json:"subjlen"`
	Default        int     `json:"default"`
	Chatread       int     `json:"chatread"`
}

func (ModConfig) TableName() string {
	return "mod_configs"
}

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

func (StdMsg) TableName() string {
	return "mod_stdmsgs"
}

// canModify checks if the user can modify a config.
func canModify(myid uint64, cfg *ModConfig) bool {
	if auth.IsAdminOrSupport(myid) {
		return true
	}
	// Moderator can modify if they created it or it's not protected.
	if cfg.Createdby != nil && *cfg.Createdby == myid {
		return true
	}
	if cfg.Protected == 0 || cfg.Createdby == nil {
		// Not protected, or no valid lock owner — open to any mod who can see it.
		return canSee(myid, cfg)
	}
	return false
}

// canSee checks if a moderator can see this config.
func canSee(myid uint64, cfg *ModConfig) bool {
	// Admin/Support can see any config.
	if auth.IsAdminOrSupport(myid) {
		return true
	}
	// Created by them.
	if cfg.Createdby != nil && *cfg.Createdby == myid {
		return true
	}
	// Default configs visible to all.
	if cfg.Default == 1 {
		return true
	}
	// Used by mods on groups they moderate.
	var count int64
	database.DBConn.Table("memberships m1").
		Select("COUNT(*)").
		Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
		Where("m1.userid = ? AND m1.role IN (?, ?) AND m2.configid = ? AND m2.role IN (?, ?)",
			myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, cfg.ID, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Scan(&count)
	return count > 0
}

// configColumns is the explicit column list for mod_configs queries.
const configColumns = "id, name, createdby, fromname, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, protected, messageorder, network, coloursubj, subjreg, subjlen, `default`, chatread"

// stdMsgColumns is the explicit column list for mod_stdmsgs queries.
const stdMsgColumns = "id, configid, title, action, subjpref, subjsuff, body, rarelyused, autosend, newmodstatus, newdelstatus, edittext, `insert`"

// GetModConfig handles GET /modconfig.
// With id param: returns single config with stdmsgs.
// Without id param: returns list of configs visible to the user.
//
// @Summary Get mod config(s)
// @Tags modconfig
// @Produce json
// @Param id query integer false "Config ID"
// @Param all query boolean false "Return all configs (admin only)"
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /api/modconfig [get]
func GetModConfig(c *fiber.Ctx) error {
	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)

	if id == 0 {
		return listModConfigs(c)
	}

	// Auth check required for single config fetch.
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn
	var cfg ModConfig
	db.Table("mod_configs").Select(configColumns).Where("id = ?", id).Scan(&cfg)
	if cfg.ID == 0 {
		// V1 parity: return 200 with ret:2. The frontend treats
		// non-200 as a fatal "Settings inaccessible" error (9518.180).
		return c.JSON(fiber.Map{"ret": 2, "status": "Invalid config id"})
	}

	// Verify the user can see this config.
	if !canSee(myid, &cfg) {
		return c.JSON(fiber.Map{"ret": 3, "status": "Not authorised"})
	}

	// Get standard messages.
	var stdmsgs []StdMsg
	db.Table("mod_stdmsgs").Select(stdMsgColumns).Where("configid = ?", id).Scan(&stdmsgs)
	if stdmsgs == nil {
		stdmsgs = []StdMsg{}
	}

	// Compute "cansee" - why the user can see this config.
	var cansee string
	var sharedbyid uint64
	var sharedonid uint64

	if cfg.Createdby != nil && *cfg.Createdby == myid {
		cansee = "Created"
	} else if cfg.Default == 1 {
		cansee = "Default"
	} else {
		// Shared - find who is using it on a group we both moderate.
		type SharedInfo struct {
			Userid  uint64
			Groupid uint64
		}
		var shared SharedInfo
		db.Table("memberships m1").
			Select("m2.userid, m2.groupid").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m1.role IN (?, ?) AND m2.configid = ? AND m2.role IN (?, ?) AND m2.userid != ?",
				myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, cfg.ID, utils.ROLE_MODERATOR, utils.ROLE_OWNER, myid).
			Limit(1).
			Scan(&shared)

		if shared.Userid > 0 {
			cansee = "Shared"
			sharedbyid = shared.Userid
			sharedonid = shared.Groupid
		}
	}

	// Compute "using" - user IDs of moderators currently using this config.
	var usingUserIDs []uint64
	db.Table("memberships m").
		Distinct("m.userid").
		Where("m.configid = ? AND m.role IN (?, ?)", cfg.ID, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Limit(10).
		Pluck("userid", &usingUserIDs)

	if usingUserIDs == nil {
		usingUserIDs = []uint64{}
	}

	resp := fiber.Map{
		"id":             cfg.ID,
		"name":           cfg.Name,
		"createdby":      cfg.Createdby,
		"fromname":       cfg.Fromname,
		"ccrejectto":     cfg.Ccrejectto,
		"ccrejectaddr":   cfg.Ccrejectaddr,
		"ccfollowupto":   cfg.Ccfollowupto,
		"ccfollowupaddr": cfg.Ccfollowupaddr,
		"ccrejmembto":    cfg.Ccrejmembto,
		"ccrejmembaddr":  cfg.Ccrejmembaddr,
		"ccfollmembto":   cfg.Ccfollmembto,
		"ccfollmembaddr": cfg.Ccfollmembaddr,
		"protected":      cfg.Protected,
		"messageorder":   cfg.Messageorder,
		"network":        cfg.Network,
		"coloursubj":     cfg.Coloursubj,
		"subjreg":        cfg.Subjreg,
		"subjlen":        cfg.Subjlen,
		"default":        cfg.Default,
		"chatread":       cfg.Chatread,
		"stdmsgs":        stdmsgs,
		"using":          usingUserIDs,
	}

	if cansee != "" {
		resp["cansee"] = cansee
	}
	if sharedbyid > 0 {
		resp["sharedbyid"] = sharedbyid
	}
	if sharedonid > 0 {
		resp["sharedonid"] = sharedonid
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"config": resp,
	})
}

// listModConfigs returns all configs visible to the user.
func listModConfigs(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// Check if admin/support requesting all configs.
	all := c.Query("all", "") == "true"

	var configs []ModConfig

	if all {
		// Admin/support can see all configs.  Non-admin users silently
		// fall through to the per-moderator query below.
		if auth.IsAdminOrSupport(myid) {
			db.Table("mod_configs").Select(configColumns).Order("name").Scan(&configs)
		}
	}

	if configs == nil {
		// Return configs visible to this moderator:
		// 1. Created by them
		// 2. Default configs
		// 3. Used by groups they moderate
		//
		// Use UNION to avoid the expensive double LEFT JOIN on memberships
		// which caused full table scans on the 4.7M row memberships table.
		// Top-level
		// UNION with a trailing ORDER BY that applies to the whole union, not
		// any one arm - BuildClauses={"SELECT"} means GORM renders only the
		// SELECT clause, so ORDER BY has to be literal text inside the same
		// fragment rather than a separate .Order() call (that clause would
		// never be walked). Same mechanism as changes.go's GetChanges above.
		tx := db.Table("mod_configs").Select(
			configColumns+" FROM mod_configs WHERE createdby = ? "+
				"UNION "+
				"SELECT "+configColumns+" FROM mod_configs WHERE `default` = 1 "+
				"UNION "+
				"SELECT "+configColumns+" FROM mod_configs WHERE id IN ("+
				"SELECT m1.configid FROM memberships m1 "+
				"WHERE m1.configid IS NOT NULL AND m1.role IN (?, ?) "+
				"AND m1.groupid IN ("+
				"SELECT m2.groupid FROM memberships m2 "+
				"WHERE m2.userid = ? AND m2.role IN (?, ?)"+
				")"+
				") "+
				"ORDER BY name",
			myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER)
		tx.Statement.BuildClauses = []string{"SELECT"}
		tx.Scan(&configs)
	}

	if configs == nil {
		configs = []ModConfig{}
	}

	return c.JSON(configs)
}

// PostModConfig handles POST /modconfig to create a new config.
//
// @Summary Create mod config
// @Tags modconfig
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/modconfig [post]
func PostModConfig(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if !auth.IsSystemMod(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 4, "status": "Don't have rights to create configs"})
	}

	type CreateRequest struct {
		Name string `json:"name"`
		ID   uint64 `json:"id"` // Copy from existing.
	}

	var req CreateRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
		}
	}
	if req.Name == "" {
		req.Name = c.FormValue("name", c.Query("name", ""))
	}

	if req.Name == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Must supply name")
	}

	db := database.DBConn

	if req.ID > 0 {
		// Verify the user can see the source config before copying.
		var srcCfg ModConfig
		db.Table("mod_configs").Select(configColumns).Where("id = ?", req.ID).Scan(&srcCfg)
		if srcCfg.ID == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Source config not found")
		}
		if !canSee(myid, &srcCfg) {
			return fiber.NewError(fiber.StatusForbidden, "Not authorised to copy this config")
		}

		// Copy from existing config. database.InsertSelect keeps the copy a single
		// atomic INSERT ... SELECT (see database/clausebuilders.go); Clauses(res)
		// reads the generated id back from the same sql.Result the write returned,
		// never a separate SELECT LAST_INSERT_ID() - unsafe under parallel load,
		// since GORM's connection pool could assign that SELECT a different
		// connection.
		res := gorm.WithResult()
		tx := database.InsertSelect(db.Clauses(res), "mod_configs",
			"(name, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, "+
				"ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, network, coloursubj, subjlen, "+
				"fromname, subjreg, messageorder) "+
				"SELECT ?, ccrejectto, ccrejectaddr, ccfollowupto, ccfollowupaddr, "+
				"ccrejmembto, ccrejmembaddr, ccfollmembto, ccfollmembaddr, network, coloursubj, subjlen, "+
				"fromname, subjreg, messageorder "+
				"FROM mod_configs WHERE id = ?", req.Name, req.ID)
		if tx.Error != nil {
			stdlog.Printf("Failed to copy mod config %d: %v", req.ID, tx.Error)
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to copy config")
		}

		var newID uint64
		if res.Result != nil {
			if lastID, idErr := res.Result.LastInsertId(); idErr == nil && lastID > 0 {
				newID = uint64(lastID)
			}
		}
		if newID == 0 {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to get new config ID")
		}
		db.Table("mod_configs").Where("id = ?", newID).Update("createdby", myid)

		// Copy stdmsgs.
		var srcMsgs []StdMsg
		db.Table("mod_stdmsgs").Select(stdMsgColumns).Where("configid = ?", req.ID).Scan(&srcMsgs)
		for _, m := range srcMsgs {
			db.Table("mod_stdmsgs").Create(map[string]interface{}{
				"configid":     newID,
				"title":        m.Title,
				"action":       m.Action,
				"subjpref":     m.Subjpref,
				"subjsuff":     m.Subjsuff,
				"body":         m.Body,
				"rarelyused":   m.Rarelyused,
				"autosend":     m.Autosend,
				"newmodstatus": m.Newmodstatus,
				"newdelstatus": m.Newdelstatus,
				"edittext":     m.Edittext,
				"insert":       m.Insert,
			})
		}

		// Copy bulkops. ORM migration site e137c396bd13 (tier4).
		database.InsertSelect(db, "mod_bulkops",
			"(title, configid, `set`, criterion, runevery, action, bouncingfor) "+
				"SELECT title, ?, `set`, criterion, runevery, action, bouncingfor "+
				"FROM mod_bulkops WHERE configid = ?", newID, req.ID)

		// Log the creation.
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      log.LOG_TYPE_CONFIG,
			"subtype":   log.LOG_SUBTYPE_CREATED,
			"byuser":    myid,
			"configid":  newID,
		})

		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
	}

	// Simple create. Table()+map Create reads the generated id back from the
	// same sql.Result the INSERT returned, under the map key "@id" - see
	// test/insertid_gorm_writeback_test.go.
	row := map[string]interface{}{
		"name":           req.Name,
		"createdby":      myid,
		"ccrejectaddr":   gorm.Expr("''"),
		"ccfollowupaddr": gorm.Expr("''"),
		"ccrejmembaddr":  gorm.Expr("''"),
		"ccfollmembaddr": gorm.Expr("''"),
		"network":        gorm.Expr("''"),
	}
	if err := db.Table("mod_configs").Create(row).Error; err != nil {
		stdlog.Printf("Failed to create mod config: %v", err)
		return fiber.NewError(fiber.StatusInternalServerError, "Create failed")
	}

	var newID uint64
	lastID, _ := row["@id"].(int64)
	if lastID > 0 {
		newID = uint64(lastID)
	}
	if newID == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to get new config ID")
	}

	// Log the creation.
	db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"),
		"type":      log.LOG_TYPE_CONFIG,
		"subtype":   log.LOG_SUBTYPE_CREATED,
		"byuser":    myid,
		"configid":  newID,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

// PatchModConfig handles PATCH /modconfig to update settable attributes.
//
// @Summary Update mod config
// @Tags modconfig
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /api/modconfig [patch]
func PatchModConfig(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type PatchRequest struct {
		ID             uint64  `json:"id"`
		Name           *string `json:"name"`
		Fromname       *string `json:"fromname"`
		Ccrejectto     *string `json:"ccrejectto"`
		Ccrejectaddr   *string `json:"ccrejectaddr"`
		Ccfollowupto   *string `json:"ccfollowupto"`
		Ccfollowupaddr *string `json:"ccfollowupaddr"`
		Ccrejmembto    *string `json:"ccrejmembto"`
		Ccrejmembaddr  *string `json:"ccrejmembaddr"`
		Ccfollmembto   *string `json:"ccfollmembto"`
		Ccfollmembaddr *string `json:"ccfollmembaddr"`
		Protected      *int    `json:"protected"`
		Messageorder   *string `json:"messageorder"`
		Network        *string `json:"network"`
		Coloursubj     *int    `json:"coloursubj"`
		Subjreg        *string `json:"subjreg"`
		Subjlen        *int    `json:"subjlen"`
		Chatread       *int    `json:"chatread"`
	}

	var req PatchRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
		}
	}
	if req.ID == 0 {
		req.ID, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid config id")
	}

	db := database.DBConn
	var cfg ModConfig
	db.Table("mod_configs").Select(configColumns).Where("id = ?", req.ID).Scan(&cfg)
	if cfg.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Invalid config id")
	}

	if !canModify(myid, &cfg) {
		return fiber.NewError(fiber.StatusForbidden, "Don't have rights to modify config")
	}

	// ORM migration site a7b00c5503b7 (fieldwise coverage, not exhaustive
	// shapes). 17 independently-optional fields, each contributing its own
	// fixed "col = ?" fragment(s) with no fragment's value referencing
	// another assigned column - verified via the retired ormharness's
	// AssertGoldenFieldwise precondition check, which reused
	// setOrderIsLoadBearing (the same rule the retired check-set-order.sh
	// enforced elsewhere) and refused the site outright if that ever stopped
	// being true. That independence is what makes n+2 cases (each field
	// alone, empty, all together) a real proof rather than exhaustive 2^17 shape
	// coverage, which AssertGoldenShapes could never practically declare.
	// See the retired test/orm_fieldwise_modconfig_test.go and
	// ormharness/fieldwise.json (removed in d22ba1d6c)
	// - and Protected below, which is the one field that contributes two
	// fragments (protected, createdby) rather than one; fieldwise coverage
	// only requires that ITS OWN two fragments don't reference any OTHER
	// field's column, which they don't.
	updates := map[string]interface{}{}

	if req.Name != nil {
		updates["name"] = *req.Name
	}
	if req.Fromname != nil {
		updates["fromname"] = *req.Fromname
	}
	if req.Ccrejectto != nil {
		updates["ccrejectto"] = *req.Ccrejectto
	}
	if req.Ccrejectaddr != nil {
		updates["ccrejectaddr"] = *req.Ccrejectaddr
	}
	if req.Ccfollowupto != nil {
		updates["ccfollowupto"] = *req.Ccfollowupto
	}
	if req.Ccfollowupaddr != nil {
		updates["ccfollowupaddr"] = *req.Ccfollowupaddr
	}
	if req.Ccrejmembto != nil {
		updates["ccrejmembto"] = *req.Ccrejmembto
	}
	if req.Ccrejmembaddr != nil {
		updates["ccrejmembaddr"] = *req.Ccrejmembaddr
	}
	if req.Ccfollmembto != nil {
		updates["ccfollmembto"] = *req.Ccfollmembto
	}
	if req.Ccfollmembaddr != nil {
		updates["ccfollmembaddr"] = *req.Ccfollmembaddr
	}
	if req.Protected != nil {
		updates["protected"] = *req.Protected
		// When setting protected, also set createdby to the caller.
		updates["createdby"] = myid
	}
	if req.Messageorder != nil {
		updates["messageorder"] = *req.Messageorder
	}
	if req.Network != nil {
		updates["network"] = *req.Network
	}
	if req.Coloursubj != nil {
		updates["coloursubj"] = *req.Coloursubj
	}
	if req.Subjreg != nil {
		updates["subjreg"] = *req.Subjreg
	}
	if req.Subjlen != nil {
		updates["subjlen"] = *req.Subjlen
	}
	if req.Chatread != nil {
		updates["chatread"] = *req.Chatread
	}

	if len(updates) > 0 {
		if result := db.Table("mod_configs").Where("id = ?", req.ID).Updates(updates); result.Error != nil {
			stdlog.Printf("Failed to update mod config %d: %v", req.ID, result.Error)
			return fiber.NewError(fiber.StatusInternalServerError, "Update failed")
		}
	}

	// Log the edit.
	db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"),
		"type":      log.LOG_TYPE_CONFIG,
		"subtype":   log.LOG_SUBTYPE_EDIT,
		"byuser":    myid,
		"configid":  req.ID,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteModConfig handles DELETE /modconfig.
//
// @Summary Delete mod config
// @Tags modconfig
// @Produce json
// @Param id query integer true "Config ID"
// @Security BearerAuth
// @Router /api/modconfig [delete]
func DeleteModConfig(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, _ := strconv.ParseUint(c.Query("id", "0"), 10, 64)
	if id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid config id")
	}

	db := database.DBConn
	var cfg ModConfig
	db.Table("mod_configs").Select(configColumns).Where("id = ?", id).Scan(&cfg)
	if cfg.ID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Invalid config id")
	}

	if !canModify(myid, &cfg) {
		return fiber.NewError(fiber.StatusForbidden, "Don't have rights to modify config")
	}

	// Check if still in use.
	var inUse int64
	db.Table("memberships").Where("configid = ? AND role IN (?, ?)", id, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Count(&inUse)
	if inUse > 0 {
		return c.Status(fiber.StatusConflict).JSON(fiber.Map{"ret": 5, "status": "Config still in use"})
	}

	db.Table("mod_configs").Where("id = ?", id).Delete(nil)

	// Log the deletion.
	db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"),
		"type":      log.LOG_TYPE_CONFIG,
		"subtype":   log.LOG_SUBTYPE_DELETED,
		"byuser":    myid,
		"configid":  id,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
