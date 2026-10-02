package isochrone

import (
	"log"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
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
		// ST_SRID(geometry, SRID) re-tags the copied location geometry: locations rows have
		// carried SRID 0 in the past (Mar-Apr 2026 rows did), and a raw copy then poisons
		// every later ST_Contains against this isochrone with error 3033. Re-tagging is a
		// no-op for correctly-tagged rows and NULL-safe, so COALESCE still falls through.
		tx := database.InsertSelect(db.Clauses(res, clause.Insert{Modifier: "IGNORE"}), "isochrones",
			"(locationid, transport, minutes, polygon) "+
				"SELECT ?, ?, ?, COALESCE(ST_SRID(geometry, ?), ST_GeomFromText(CONCAT('POINT(', lng, ' ', lat, ')'), ?)) FROM locations WHERE id = ?",
			locationid, transport, minutes, utils.SRID, utils.SRID, locationid)
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
