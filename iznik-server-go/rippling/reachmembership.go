package rippling

import (
	"log"
	"time"

	"github.com/freegle/iznik-server-go/spatial"
	"gorm.io/gorm"
)

// Point-in-reach for specific posts, answered from the stored cell grid
// (plans/2026-08-24-rippling-reach-raster-storage.md): a PK fetch of the
// row's polygon_cells plus a run-stream probe replaces the ST_Contains
// against a megabyte polygon that these gates used to pay per row. An
// undecidable row answers NOT in reach, which for every caller here is the
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
func ReachMembership(db *gorm.DB, msgids []uint64, lng, lat float64) (map[uint64]ReachRowInfo, error) {
	out := make(map[uint64]ReachRowInfo)
	if len(msgids) == 0 {
		return out, nil
	}

	var rows []struct {
		Msgid     uint64     `gorm:"column:msgid"`
		Lat       *float64   `gorm:"column:lat"`
		Lng       *float64   `gorm:"column:lng"`
		Schedule  *string    `gorm:"column:schedule"`
		Arrival   *time.Time `gorm:"column:arrival"`
		Cells     []byte     `gorm:"column:cells"`
		HasLabels bool       `gorm:"column:has_labels"`
	}
	if err := db.Table("rippling_reach rr").
		Select("rr.msgid, rr.lat, rr.lng, rr.schedule, rr.arrival, rr.polygon_cells AS cells, rr.reach_labels IS NOT NULL AS has_labels").
		Where("rr.msgid IN ?", msgids).
		Scan(&rows).Error; err != nil {
		log.Printf("reach membership fetch failed: %v", err)
		return out, err
	}

	// Stored labels are the deciding record wherever they exist: the exact
	// road-network answer at the post's current budget, from ONE batched
	// routing call. Posts the backfill has not reached (and every post, when
	// routing is unavailable) keep the cell-grid verdict below.
	verdicts := LabelVerdicts(lat, lng, msgids)

	var undecided []uint64
	for _, r := range rows {
		info := ReachRowInfo{Msgid: r.Msgid, Lat: r.Lat, Lng: r.Lng, Schedule: r.Schedule, Arrival: r.Arrival}
		if v, ok := verdicts[r.Msgid]; ok {
			info.InReach = v == LabelVerdictIn
			out[r.Msgid] = info
			continue
		}
		if in, ok := CellSetContains(r.Cells, lng, lat); ok {
			info.InReach = in
			out[r.Msgid] = info
			continue
		}
		// No usable cells. For a labelled row that is the RETIRED-grid
		// state (labels-truth drained it) during a routing outage: fail
		// closed, quietly - the designed double-failure direction. For an
		// unlabelled row it is an anomaly worth saying out loud.
		out[r.Msgid] = info
		if !r.HasLabels {
			undecided = append(undecided, r.Msgid)
		}
	}

	if len(undecided) > 0 {
		log.Printf("reach membership: %d rows undecidable with no legacy fallback (msgids %v)", len(undecided), undecided)
	}

	return out, nil
}

// SpatialReachIDs asks the spatial server which live reaches cover the point:
// `in` are definite (answered exactly from stored cell grids); `partial` are
// index rows whose boundary band could not decide, which healthy rows no
// longer produce - callers log and exclude them. ok=false (transport error,
// dataset not ready) means the caller must use its degraded path: the
// outer-bound superset in SQL, refined by probing stored cells.
func SpatialReachIDs(db *gorm.DB, lng, lat float64) (in []int64, partial []int64, ok bool) {
	in, partial, err := spatial.ReachContaining(lng, lat)
	if err != nil {
		return nil, nil, false
	}
	return in, partial, true
}
