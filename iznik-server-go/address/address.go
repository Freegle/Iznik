package address

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm/clause"
	"strconv"
)

type CreateRequest struct {
	PafID        uint64   `json:"pafid"`
	Instructions string   `json:"instructions"`
	Lat          *float64 `json:"lat,omitempty"`
	Lng          *float64 `json:"lng,omitempty"`
}

type UpdateRequest struct {
	ID           uint64   `json:"id"`
	PafID        *uint64  `json:"pafid,omitempty"`
	Instructions *string  `json:"instructions,omitempty"`
	Lat          *float64 `json:"lat,omitempty"`
	Lng          *float64 `json:"lng,omitempty"`
}

func Create(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req CreateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.PafID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "pafid is required")
	}

	db := database.DBConn

	// Use REPLACE INTO so that if (userid, pafid) already exists, it replaces the row. Read the
	// id back from the same sql.Result the write returned (GORM's "@id" map key): REPLACE is
	// DELETE+INSERT on conflict so the new row has a fresh AUTO_INCREMENT id, and a "SELECT id ...
	// WHERE userid AND pafid" here is routed to a read replica under the read/write split and can
	// return a stale id or none (Discourse 9832 class).
	// ORM migration site 990cc13deb7e (keep-raw reason stale: database.RegisterCustomClauseBuilders
	// now overrides the INSERT clause builder so clause.Insert{Modifier: "REPLACE"} renders a clean
	// "REPLACE INTO" - see database/clausebuilders.go, and e.g. spammers/spammers.go's handleSpammer
	// for an existing conversion of the same shape).
	row := map[string]interface{}{
		"userid":       myid,
		"pafid":        req.PafID,
		"instructions": req.Instructions,
		"lat":          req.Lat,
		"lng":          req.Lng,
	}
	if err := db.Table("users_addresses").Clauses(clause.Insert{Modifier: "REPLACE"}).Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create address")
	}

	var id uint64
	if idInt64, ok := row["@id"].(int64); ok && idInt64 > 0 {
		id = uint64(idInt64)
	}

	return c.JSON(fiber.Map{"id": id})
}

func Update(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req UpdateRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	// Check ownership
	var ownerID uint64
	// ORM migration site c9552d1439f7 (wave 1).
	db.Table("users_addresses").Select("userid").Where("id = ?", req.ID).Scan(&ownerID)

	if ownerID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Address not found")
	}

	if ownerID != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your address")
	}

	// Update settable attributes
	if req.Instructions != nil {
		// ORM migration site 8d9bcc14ce4f (wave 2).
		db.Table("users_addresses").Where("id = ?", req.ID).Update("instructions", *req.Instructions)
	}
	if req.Lat != nil {
		// ORM migration site 47ad28590287 (wave 2).
		db.Table("users_addresses").Where("id = ?", req.ID).Update("lat", *req.Lat)
	}
	if req.Lng != nil {
		// ORM migration site 339b4832d0ee (wave 2).
		db.Table("users_addresses").Where("id = ?", req.ID).Update("lng", *req.Lng)
	}
	if req.PafID != nil {
		// ORM migration site 34929e307f19 (wave 2).
		db.Table("users_addresses").Where("id = ?", req.ID).Update("pafid", *req.PafID)
	}

	return c.JSON(fiber.Map{"success": true})
}

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

	// Check ownership
	var ownerID uint64
	// ORM migration site e5e485de6a36 (wave 1).
	db.Table("users_addresses").Select("userid").Where("id = ?", id).Scan(&ownerID)

	if ownerID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Address not found")
	}

	if ownerID != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your address")
	}

	// ORM migration site 57a5e9a51661 (wave 2).
	db.Table("users_addresses").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"success": true})
}

type Address struct {
	ID                              uint64  `json:"id" gorm:"primary_key"`
	Userid                          uint64  `json:"userid"`
	Instructions                    string  `json:"instructions"`
	Lat                             float64 `json:"lat"`
	Lng                             float64 `json:"lng"`
	Postcode                        string  `json:"postcode"`
	Posttown                        string  `json:"posttown"`
	Dependentlocality               string  `json:"dependentlocality"`
	Doubledependentlocality         string  `json:"doubledependentlocality"`
	Thoroughfaredescriptor          string  `json:"thoroughfaredescriptor"`
	Dependentthoroughfaredescriptor string  `json:"dependentthoroughfaredescriptor"`
	Buildingname                    string  `json:"buildingname"`
	Subbuildingname                 string  `json:"subbuildingname"`
	Pobox                           string  `json:"pobox"`
	Departmentname                  string  `json:"departmentname"`
	Organisationname                string  `json:"organisationname"`
}

func ListForUser(c *fiber.Ctx) error {
	var r []Address

	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn
	// ORM migration site b9b602714ff9 (wave 4).
	db.Table("users_addresses").
		Select("users_addresses.id, users_addresses.userid, instructions,"+
			"COALESCE(users_addresses.lat, locations.lat) AS lat, "+
			"COALESCE(users_addresses.lng, locations.lng) AS lng, "+
			"locations.name AS postcode, "+
			"posttown,dependentlocality,doubledependentlocality,thoroughfaredescriptor,dependentthoroughfaredescriptor,buildingname,subbuildingname,pobox,departmentname,organisationname").
		Joins("INNER JOIN paf_addresses ON paf_addresses.id = users_addresses.pafid").
		Joins("INNER JOIN locations ON locations.id = paf_addresses.postcodeid").
		Joins("LEFT JOIN paf_posttown ON paf_posttown.id = paf_addresses.posttownid").
		Joins("LEFT JOIN paf_dependentlocality ON paf_dependentlocality.id = paf_addresses.dependentlocalityid").
		Joins("LEFT JOIN paf_doubledependentlocality ON paf_doubledependentlocality.id = paf_addresses.doubledependentlocalityid").
		Joins("LEFT JOIN paf_thoroughfaredescriptor ON paf_thoroughfaredescriptor.id = paf_addresses.thoroughfaredescriptorid").
		Joins("LEFT JOIN paf_dependentthoroughfaredescriptor ON paf_dependentthoroughfaredescriptor.id = paf_addresses.dependentthoroughfaredescriptorid").
		Joins("LEFT JOIN paf_buildingname ON paf_buildingname.id = paf_addresses.buildingnameid").
		Joins("LEFT JOIN paf_subbuildingname ON paf_subbuildingname.id = paf_addresses.subbuildingnameid").
		Joins("LEFT JOIN paf_pobox ON paf_pobox.id = paf_addresses.poboxid").
		Joins("LEFT JOIN paf_departmentname ON paf_departmentname.id = paf_addresses.departmentnameid").
		Joins("LEFT JOIN paf_organisationname ON paf_organisationname.id = paf_addresses.organisationnameid").
		Where("users_addresses.userid = ?", myid).
		Scan(&r)

	if len(r) == 0 {
		// Force [] rather than null to be returned.
		return c.JSON(make([]string, 0))
	} else {
		return c.JSON(r)
	}
}

func GetAddress(c *fiber.Ctx) error {
	var r []Address

	myid := user.WhoAmI(c)
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)

	if err == nil {
		// We have to check that the address is referenced by a chat message in a chat to which we have access, or
		// which we own, or where we are a moderator of the group associated with the chat, or if we have Support/Admin rights.
		db := database.DBConn
		// ORM migration site 608507c3053f (wave 4).
		db.Table("users_addresses").
			Select("users_addresses.id, users_addresses.userid, instructions,"+
				"COALESCE(users_addresses.lat, locations.lat) AS lat, "+
				"COALESCE(users_addresses.lng, locations.lng) AS lng, "+
				"locations.name AS postcode, "+
				"posttown,dependentlocality,doubledependentlocality,thoroughfaredescriptor,dependentthoroughfaredescriptor,buildingname,subbuildingname,pobox,departmentname,organisationname").
			Joins("LEFT JOIN chat_rooms ON chat_rooms.user1 = ? OR chat_rooms.user2 = ?", myid, myid).
			Joins("LEFT JOIN chat_messages ON chat_messages.chatid = chat_rooms.id").
			Joins("LEFT JOIN users ON users.id = ?", myid).
			Joins("INNER JOIN paf_addresses ON paf_addresses.id = users_addresses.pafid").
			Joins("INNER JOIN locations ON locations.id = paf_addresses.postcodeid").
			Joins("LEFT JOIN paf_posttown ON paf_posttown.id = paf_addresses.posttownid").
			Joins("LEFT JOIN paf_dependentlocality ON paf_dependentlocality.id = paf_addresses.dependentlocalityid").
			Joins("LEFT JOIN paf_doubledependentlocality ON paf_doubledependentlocality.id = paf_addresses.doubledependentlocalityid").
			Joins("LEFT JOIN paf_thoroughfaredescriptor ON paf_thoroughfaredescriptor.id = paf_addresses.thoroughfaredescriptorid").
			Joins("LEFT JOIN paf_dependentthoroughfaredescriptor ON paf_dependentthoroughfaredescriptor.id = paf_addresses.dependentthoroughfaredescriptorid").
			Joins("LEFT JOIN paf_buildingname ON paf_buildingname.id = paf_addresses.buildingnameid").
			Joins("LEFT JOIN paf_subbuildingname ON paf_subbuildingname.id = paf_addresses.subbuildingnameid").
			Joins("LEFT JOIN paf_pobox ON paf_pobox.id = paf_addresses.poboxid").
			Joins("LEFT JOIN paf_departmentname ON paf_departmentname.id = paf_addresses.departmentnameid").
			Joins("LEFT JOIN paf_organisationname ON paf_organisationname.id = paf_addresses.organisationnameid").
			// SECURITY: access is granted to the address owner, to a party the address was
			// shared with in a chat, or to a platform (system-role) moderator/support/admin.
			// The previous per-group membership join was gameable (create a User2Mod chat to a
			// group you moderate to unlock any address) and is replaced by the system-role test.
			Where("users_addresses.id = ? AND (users_addresses.userid = ? OR (chat_messages.type = ? AND chat_messages.message = ?) OR users.systemrole IN (?, ?, ?))",
				id, myid, utils.CHAT_MESSAGE_ADDRESS, id, utils.SYSTEMROLE_MODERATOR, utils.SYSTEMROLE_ADMIN, utils.SYSTEMROLE_SUPPORT).
			Limit(1).
			Scan(&r)
	}

	if len(r) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Address not visible")
	} else {
		return c.JSON(r[0])
	}

}
