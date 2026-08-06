package isochrone

import (
	"log"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type Isochrones struct {
	ID          uint64    `json:"id" gorm:"primary_key"`
	Userid      uint64    `json:"userid"`
	Isochroneid uint64    `json:"isochroneid"`
	Locationid  uint64    `json:"locationid"`
	Transport   string    `json:"transport"`
	Minutes     int       `json:"minutes"`
	Timestamp   time.Time `json:"timestamp"`
	Nickname    string    `json:"nickname"`
	Polygon     string    `json:"polygon"`
}

func (Isochrones) TableName() string {
	return "isochrones"
}

// validTransports is the whitelist of allowed transport types.
var validTransports = map[string]bool{
	"Walk":  true,
	"Cycle": true,
	"Drive": true,
}

// ListIsochrones handles GET /isochrone.
//
// Deprecated: the per-user isochrone editor was removed in the rippling-out
// "Nearby = reach" flip (PR #921). No current client calls this; retained only for
// backward compatibility with older deployed clients. See stores/nearby.js.
//
// @Deprecated
func ListIsochrones(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	isochrones := []Isochrones{}

	db.Table("isochrones_users").
		Select("isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon").
		Joins("INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id").
		Where("isochrones_users.userid = ?", myid).
		Scan(&isochrones)

	// Self-heal: if any isochrone has a POINT polygon (broken V2 creation), replace it
	// with a real Mapbox polygon.
	isochrones = HealPointIsochrones(db, isochrones, myid)

	if len(isochrones) == 0 {
		// Auto-create a default isochrone using the user's last known location
		// when none exist.
		var locationid uint64
		db.Table("users").Select("lastlocation").Where("id = ? AND lastlocation IS NOT NULL", myid).Scan(&locationid)

		if locationid > 0 {
			isoID := EnsureIsochroneExists(locationid, "Walk", 15)

			if isoID > 0 {
				// Link user to isochrone.
				result := db.Table("isochrones_users").Clauses(clause.OnConflict{
					DoUpdates: clause.Set{
						{Column: clause.Column{Name: "isochroneid"}, Value: clause.Column{Table: "excluded", Name: "isochroneid"}},
					},
				}).Create(map[string]interface{}{"userid": myid, "isochroneid": isoID})
				if result.Error != nil {
					log.Printf("Failed to link user %d to isochrone %d: %v", myid, isoID, result.Error)
				}

				// Re-fetch the isochrones.
				db.Table("isochrones_users").
					Select("isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon").
					Joins("INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id").
					Where("isochrones_users.userid = ?", myid).
					Scan(&isochrones)
			}
		}
	}

	return c.JSON(isochrones)
}

// EnsureIsochroneExists finds or creates an isochrone with a real polygon from Mapbox.
// Returns the isochrone ID, or 0 on failure.
func EnsureIsochroneExists(locationid uint64, transport string, minutes int) uint64 {
	db := database.DBConn

	// Check for existing isochrone with a real polygon (not a POINT).
	var isoID uint64
	db.Table("isochrones").
		Select("id").
		Where("locationid = ? AND transport = ? AND minutes = ? AND ST_GeometryType(polygon) != 'POINT'", locationid, transport, minutes).
		Order("id DESC").
		Limit(1).
		Scan(&isoID)

	if isoID > 0 {
		return isoID
	}

	// Get lat/lng from the location.
	var loc struct {
		Lat float64
		Lng float64
	}
	db.Table("locations").Select("lat, lng").Where("id = ?", locationid).Scan(&loc)

	if loc.Lat == 0 && loc.Lng == 0 {
		log.Printf("Location %d has no lat/lng", locationid)
		return 0
	}

	// Try the internal routing server first; fall back to Mapbox.
	source := "RoutingServer"
	wkt := FetchIsochroneWKTFromRoutingServer(transport, loc.Lat, loc.Lng, minutes)
	if wkt == "" {
		source = "Mapbox"
		wkt = FetchIsochroneWKT(transport, loc.Lng, loc.Lat, minutes)
	}

	if wkt != "" {
		// Check if there's an existing POINT isochrone with the same key — update it
		// rather than INSERT IGNORE (which would silently skip due to unique key).
		var existingPointID uint64
		db.Table("isochrones").
			Select("id").
			Where("locationid = ? AND transport = ? AND minutes = ? AND ST_GeometryType(polygon) = 'POINT'", locationid, transport, minutes).
			Order("id DESC").
			Limit(1).
			Scan(&existingPointID)

		if existingPointID > 0 {
			// Update the existing broken POINT isochrone with the real polygon.
			// Precedent:
			// the sibling INSERT a few lines below (site d91a1a5d6b27) already
			// puts the identical CASE/ST_SIMPLIFY/ST_GeomFromText expression
			// into a gorm.Expr with the SRID as a plain bind - this is the same
			// expression, targeted at an UPDATE instead of a Create. An explicit
			// clause.Set (not Updates(map)) keeps source before polygon exactly
			// as the original SET list had it, rather than relying on the
			// harness's column-reorder tolerance for two independent assignments.
			db.Table("isochrones").
				Clauses(clause.Set{
					{Column: clause.Column{Name: "source"}, Value: source},
					{Column: clause.Column{Name: "polygon"}, Value: gorm.Expr(
						"CASE WHEN ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) IS NULL THEN ST_GeomFromText(?, ?) ELSE ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) END",
						wkt, utils.SRID, wkt, utils.SRID, wkt, utils.SRID)},
				}).
				Where("id = ?", existingPointID).
				Updates(map[string]interface{}{})
			return existingPointID
		}

		// No existing row — insert fresh. Take the new id from the write result; the SELECT
		// fallback below only runs if INSERT IGNORE skipped a pre-existing row (9832 class).
		// Table()+map Create
		// reads the id back from the same sql.Result the INSERT returned,
		// under the map key "@id" (see test/insertid_gorm_writeback_test.go)
		// - same as
		// ExecInsertGetID it's a no-op (0) precisely when INSERT IGNORE
		// skipped a duplicate, matching the existing isoID==0 fallback below.
		row := map[string]interface{}{
			"locationid": locationid,
			"transport":  transport,
			"minutes":    minutes,
			"source":     source,
			"polygon": gorm.Expr("CASE WHEN ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) IS NULL THEN ST_GeomFromText(?, ?) ELSE ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) END",
				wkt, utils.SRID, wkt, utils.SRID, wkt, utils.SRID),
		}
		if err := db.Table("isochrones").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(row).Error; err != nil {
			log.Printf("Failed to insert isochrone from %s for location %d: %v", source, locationid, err)
			return 0
		}
		idInt64, _ := row["@id"].(int64)
		isoID = uint64(idInt64)
	} else {
		// Both providers unavailable — fall back to location geometry as placeholder.
		log.Printf("All isochrone providers failed for location %d, using location geometry as fallback", locationid)
		// ORM migration site 74620d093074 (keep-raw reason stale: INSERT ... SELECT is now a
		// supported conversion shape via database.InsertSelect - see database/clausebuilders.go and
		// newsfeed/newsfeed.go's "carry the photo" site, 08d12a748d01, for the established pattern
		// this follows). This one adds INSERT IGNORE on top: clause.Insert{Modifier: "IGNORE"} was
		// never the blocked case - only Modifier: "REPLACE" needs the ClauseBuilders["INSERT"]
		// override (see the sibling INSERT a few lines above, site d91a1a5d6b27, which already
		// proved plain "INSERT IGNORE INTO ..." renders correctly through GORM's own Insert.Build).
		// Deliberately NOT clause.OnConflict{DoNothing:true}: with Statement.Schema nil (.Table(),
		// not .Model()) that renders a dangling "ON DUPLICATE KEY UPDATE" with no column list, which
		// is invalid SQL - clause.Insert{Modifier: "IGNORE"} is the only correct spelling here.
		// gorm.WithResult() reads the id from the same sql.Result the INSERT returned, matching the
		// original ExecInsertGetID's res.LastInsertId() exactly, including staying 0 when INSERT
		// IGNORE skips a pre-existing row - the isoID == 0 fallback below still depends on that.
		res := gorm.WithResult()
		tx := database.InsertSelect(db.Clauses(res, clause.Insert{Modifier: "IGNORE"}), "isochrones",
			"(locationid, transport, minutes, polygon) "+
				"SELECT ?, ?, ?, COALESCE(geometry, ST_GeomFromText(CONCAT('POINT(', lng, ' ', lat, ')'), ?)) FROM locations WHERE id = ?",
			locationid, transport, minutes, utils.SRID, locationid)
		if tx.Error != nil {
			log.Printf("Failed to create fallback isochrone for location %d: %v", locationid, tx.Error)
			return 0
		}
		if res.Result != nil {
			if lastID, idErr := res.Result.LastInsertId(); idErr == nil && lastID > 0 {
				isoID = uint64(lastID)
			}
		}
	}

	if isoID == 0 {
		// INSERT IGNORE skipped a pre-existing row (the checks above missed it, e.g. under
		// read-split lag); read that existing row's id.
		db.Table("isochrones").Select("id").Where("locationid = ? AND transport = ? AND minutes = ?", locationid, transport, minutes).
			Order("id DESC").Limit(1).Scan(&isoID)
	}

	return isoID
}

// HealPointIsochrones checks if any of the user's isochrones have POINT geometry
// (from broken V2 creation) and replaces them with real Mapbox polygons.
func HealPointIsochrones(db *gorm.DB, isochrones []Isochrones, myid uint64) []Isochrones {
	needsRefetch := false

	for _, iso := range isochrones {
		if strings.HasPrefix(iso.Polygon, "POINT") {
			// This isochrone has broken POINT geometry. Create a proper one.
			transport := iso.Transport
			if transport == "" {
				transport = "Walk"
			}
			newIsoID := EnsureIsochroneExists(iso.Locationid, transport, iso.Minutes)
			if newIsoID > 0 {
				needsRefetch = true
				if newIsoID != iso.Isochroneid {
					// Point user to the new proper isochrone.
					db.Table("isochrones_users").Where("id = ?", iso.ID).Update("isochroneid", newIsoID)
				}
			}
		}
	}

	if needsRefetch {
		var refreshed []Isochrones
		db.Table("isochrones_users").
			Select("isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon").
			Joins("INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id").
			Where("isochrones_users.userid = ?", myid).
			Scan(&refreshed)
		return refreshed
	}

	return isochrones
}

const minMinutes = 5
const maxMinutes = 45

// CreateIsochrone handles PUT /isochrone to create or link an isochrone for the user.
//
// Deprecated: no current client calls this - the isochrone editor was removed in the
// rippling-out reach flip (PR #921). Retained for backward compatibility only.
//
// @Summary Create isochrone
// @Description [DEPRECATED - no current client calls this; see PR #921]
// @Tags isochrone
// @Accept json
// @Produce json
// @Security BearerAuth
// @Deprecated
// @Router /api/isochrone [put]
func CreateIsochrone(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type CreateRequest struct {
		Transport  string           `json:"transport"`
		Minutes    utils.FlexInt    `json:"minutes"`
		Nickname   string           `json:"nickname"`
		Locationid utils.FlexUint64 `json:"locationid"`
	}

	var req CreateRequest
	// FlexInt/FlexUint64 unmarshal both string and numeric JSON values, so
	// BodyParser handles requests from Vue v-model on <input type="range">.
	_ = c.BodyParser(&req)

	if req.Transport == "" {
		req.Transport = c.FormValue("transport", c.Query("transport", "Walk"))
	}
	if req.Minutes == 0 {
		m, _ := strconv.Atoi(c.FormValue("minutes", c.Query("minutes", "15")))
		req.Minutes = utils.FlexInt(m)
	}
	if req.Locationid == 0 {
		l, _ := strconv.ParseUint(c.FormValue("locationid", c.Query("locationid", "0")), 10, 64)
		req.Locationid = utils.FlexUint64(l)
	}
	if req.Nickname == "" {
		req.Nickname = c.FormValue("nickname", c.Query("nickname", ""))
	}

	// Validate transport against whitelist.
	if !validTransports[req.Transport] {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid transport - must be Walk, Cycle, or Drive")
	}

	// Clamp minutes.
	if req.Minutes < minMinutes {
		req.Minutes = minMinutes
	}
	if req.Minutes > maxMinutes {
		req.Minutes = maxMinutes
	}

	if req.Locationid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing locationid")
	}

	db := database.DBConn

	// Validate location exists.
	var locCount int64
	db.Table("locations").Where("id = ?", req.Locationid).Count(&locCount)
	if locCount == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Location not found")
	}

	// Find or create isochrone with real polygon from Mapbox.
	isoID := EnsureIsochroneExists(uint64(req.Locationid), req.Transport, int(req.Minutes))
	if isoID == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create isochrone")
	}

	// Link user to isochrone (upsert). ON DUPLICATE KEY UPDATE ... id=LAST_INSERT_ID(id) makes the
	// write report the id for both new and existing rows; take it from the result, not a
	// read-split-routable SELECT (9832 class).
	// GORM's own "@id" map
	// writeback is skipped when RowsAffected is 0, which MySQL reports on
	// EVERY re-link of an already-linked isochrone (the common case here,
	// since a user re-opening the same isochrone hits the duplicate-key
	// branch every time) - so Clauses(gorm.WithResult()) is needed, not
	// "@id" (see test/insertid_gorm_writeback_test.go's
	// WithResultBeatsTheRowsAffectedZeroTrap).
	linkRes := gorm.WithResult()
	result := db.Table("isochrones_users").Clauses(linkRes, clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "nickname"}, Value: clause.Column{Table: "excluded", Name: "nickname"}},
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
		},
	}).Create(map[string]interface{}{
		"userid":      myid,
		"isochroneid": isoID,
		"nickname":    req.Nickname,
	})
	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to link isochrone")
	}
	var newID uint64
	if linkRes.Result != nil {
		if id, idErr := linkRes.Result.LastInsertId(); idErr == nil {
			newID = uint64(id)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

// EditIsochrone handles PATCH /isochrone to update transport/minutes.
//
// Deprecated: no current client calls this - the isochrone editor was removed in the
// rippling-out reach flip (PR #921). Retained for backward compatibility only.
//
// @Summary Edit isochrone
// @Description [DEPRECATED - no current client calls this; see PR #921]
// @Tags isochrone
// @Accept json
// @Produce json
// @Security BearerAuth
// @Deprecated
// @Router /api/isochrone [patch]
func EditIsochrone(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type EditRequest struct {
		ID        utils.FlexUint64 `json:"id"`
		Minutes   utils.FlexInt    `json:"minutes"`
		Transport string           `json:"transport"`
	}

	var req EditRequest
	// FlexInt/FlexUint64 unmarshal both string and numeric JSON values, so
	// BodyParser handles requests from Vue v-model on <input type="range">.
	_ = c.BodyParser(&req)

	if req.ID == 0 {
		l, _ := strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
		req.ID = utils.FlexUint64(l)
	}
	if req.Minutes == 0 {
		m, _ := strconv.Atoi(c.FormValue("minutes", c.Query("minutes", "0")))
		req.Minutes = utils.FlexInt(m)
	}
	if req.Transport == "" {
		req.Transport = c.FormValue("transport", c.Query("transport", ""))
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	// Validate transport if provided - must be Walk/Cycle/Drive.
	if req.Transport != "" && !validTransports[req.Transport] {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid transport - must be Walk, Cycle, or Drive")
	}

	if req.Minutes < minMinutes {
		req.Minutes = minMinutes
	}
	if req.Minutes > maxMinutes {
		req.Minutes = maxMinutes
	}

	db := database.DBConn

	// Get current isochrone to find locationid and current transport.
	var current struct {
		Locationid uint64
		Userid     uint64
		Transport  string
	}
	db.Table("isochrones_users").
		Select("isochrones.locationid, isochrones_users.userid, isochrones.transport").
		Joins("INNER JOIN isochrones ON isochrones.id = isochrones_users.isochroneid").
		Where("isochrones_users.id = ?", req.ID).
		Scan(&current)

	if current.Locationid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Not found")
	}

	if current.Userid != myid {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	// Fall back to current transport if not provided (handles historical NULL transport rows).
	if req.Transport == "" {
		req.Transport = current.Transport
	}
	if req.Transport == "" {
		req.Transport = "Walk" // Ultimate fallback for NULL transport in DB.
	}

	// Find or create isochrone with new params and real polygon from Mapbox.
	isoID := EnsureIsochroneExists(current.Locationid, req.Transport, int(req.Minutes))
	if isoID == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create isochrone")
	}

	// Update the link to point to the new isochrone.
	result := db.Table("isochrones_users").Where("id = ?", req.ID).Update("isochroneid", isoID)
	if result.Error != nil {
		// Handle duplicate entry (timing window).
		log.Printf("Failed to update isochrone link %d, deleting duplicate: %v", req.ID, result.Error)
		db.Table("isochrones_users").Where("id = ?", req.ID).Delete(nil)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteIsochrone handles DELETE /isochrone to remove user's isochrone link.
//
// Deprecated: no current client calls this - the isochrone editor was removed in the
// rippling-out reach flip (PR #921). Retained for backward compatibility only.
//
// @Summary Delete isochrone
// @Description [DEPRECATED - no current client calls this; see PR #921]
// @Tags isochrone
// @Produce json
// @Param id query integer true "Isochrone user link ID"
// @Security BearerAuth
// @Deprecated
// @Router /api/isochrone [delete]
func DeleteIsochrone(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	type DeleteRequest struct {
		ID utils.FlexUint64 `json:"id"`
	}

	var req DeleteRequest
	_ = c.BodyParser(&req)

	id := uint64(req.ID)
	if id == 0 {
		id, _ = strconv.ParseUint(c.FormValue("id", c.Query("id", "0")), 10, 64)
	}
	if id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Missing id")
	}

	db := database.DBConn

	// Verify ownership: the isochrones_users record must belong to the current user.
	var count int64
	db.Table("isochrones_users").Where("id = ? AND userid = ?", id, myid).Count(&count)
	if count == 0 {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Access denied"})
	}

	db.Table("isochrones_users").Where("id = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
