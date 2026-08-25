package rippling

import (
	"log"
	"time"

	"gorm.io/gorm"
)

// Point-in-reach for specific posts, answered from the stored cell grid
// (plans/2026-08-24-rippling-reach-raster-storage.md): a PK fetch of the
// row's polygon_cells plus a run-stream probe replaces the ST_Contains
// against a megabyte polygon that these gates used to pay per row. Rows the
// backfill has not reached fall back, per row, to the legacy geometry SQL -
// only while the legacy columns still exist (LegacyPolygonReady); afterwards
// an undecidable row answers NOT in reach, which for every caller here is the
// fail-closed direction (a gate holds a reply; a "not reached yet" notice
// shows).

// ReachRowInfo is one reach row's origin bookkeeping plus the decided answer
// for the query point.
type ReachRowInfo struct {
	Msgid    uint64
	Lat      *float64
	Lng      *float64
	Schedule *string
	Arrival  *time.Time
	InReach  bool
}

// ReachMembership fetches the listed reach rows (no status filter - callers
// that care already filter) and answers point-in-reach per row. Absent msgids
// are absent from the map, which is how callers see "no reach row at all" -
// distinct from a returned error, on which callers keep their existing
// fail-open/fail-closed behaviour for a failed query.
func ReachMembership(db *gorm.DB, msgids []uint64, lng, lat float64, srid int) (map[uint64]ReachRowInfo, error) {
	out := make(map[uint64]ReachRowInfo)
	if len(msgids) == 0 {
		return out, nil
	}

	var rows []struct {
		Msgid    uint64     `gorm:"column:msgid"`
		Lat      *float64   `gorm:"column:lat"`
		Lng      *float64   `gorm:"column:lng"`
		Schedule *string    `gorm:"column:schedule"`
		Arrival  *time.Time `gorm:"column:arrival"`
		Cells    []byte     `gorm:"column:cells"`
	}
	if err := db.Table("rippling_reach rr").
		Select("rr.msgid, rr.lat, rr.lng, rr.schedule, rr.arrival, rr.polygon_cells AS cells").
		Where("rr.msgid IN ?", msgids).
		Scan(&rows).Error; err != nil {
		log.Printf("reach membership fetch failed: %v", err)
		return out, err
	}

	var undecided []uint64
	for _, r := range rows {
		info := ReachRowInfo{Msgid: r.Msgid, Lat: r.Lat, Lng: r.Lng, Schedule: r.Schedule, Arrival: r.Arrival}
		if in, ok := CellSetContains(r.Cells, lng, lat); ok {
			info.InReach = in
			out[r.Msgid] = info
			continue
		}
		// No usable cells: legacy geometry decides while it exists.
		out[r.Msgid] = info // InReach false until/unless the legacy pass flips it
		undecided = append(undecided, r.Msgid)
	}

	if len(undecided) > 0 {
		if LegacyPolygonReady(db) {
			expr, exprArgs := ReachInReachExpr(GeomShareReady(db), lng, lat, srid)
			var legacyRows []struct {
				Msgid   uint64 `gorm:"column:msgid"`
				InReach bool   `gorm:"column:in_reach"`
			}
			if err := db.Table("rippling_reach rr").
				Select("rr.msgid, "+expr+" AS in_reach", exprArgs...).
				Where("rr.msgid IN ?", undecided).
				Scan(&legacyRows).Error; err != nil {
				log.Printf("reach membership legacy fallback failed: %v", err)
			} else {
				for _, lr := range legacyRows {
					info := out[lr.Msgid]
					info.InReach = lr.InReach
					out[lr.Msgid] = info
				}
			}
		} else {
			// Post-drop this cannot happen for healthy rows (every writer
			// stores cells); say so rather than silently failing closed.
			log.Printf("reach membership: %d rows undecidable with no legacy fallback (msgids %v)", len(undecided), undecided)
		}
	}

	return out, nil
}
