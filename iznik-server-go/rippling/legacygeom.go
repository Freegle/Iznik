package rippling

import (
	"os"
	"sync"

	"github.com/freegle/iznik-server-go/spatial"
	"gorm.io/gorm"
)

// Test overrides for the era guards. BOTH eras have to be tested: dropping
// the columns from the test schema would only swap which one is covered, and
// the transition era is the code that runs on production first, for as long as
// the backfill takes, so it cannot be the untested one. Overriding the answer
// exercises the cells-only branches against a schema that still has the
// columns - which works precisely because those branches never name a dropped
// column, and asserting that is the point. The migration proves the schema
// half separately, by running.
var legacyForTest struct {
	sync.Mutex
	polygon  *bool
	overflow *bool
}

// SetLegacyGeomForTest forces the era guards' answers; nil restores the real
// schema check. Call with defer to restore.
func SetLegacyGeomForTest(polygon, overflow *bool) {
	legacyForTest.Lock()
	defer legacyForTest.Unlock()
	legacyForTest.polygon = polygon
	legacyForTest.overflow = overflow
}

func legacyOverride(which string) (bool, bool) {
	legacyForTest.Lock()
	defer legacyForTest.Unlock()
	var p *bool
	if which == "polygon" {
		p = legacyForTest.polygon
	} else {
		p = legacyForTest.overflow
	}
	if p == nil {
		return false, false
	}
	return *p, true
}

var legacyPolygonOnce sync.Once
var legacyPolygonExists bool

// LegacyPolygonReady reports whether rippling_reach still carries the legacy
// polygon geometry column. The cells migration
// (plans/2026-08-24-rippling-reach-raster-storage.md, Stage 3) eventually
// DROPS polygon/max_polygon/overflow_bounds: after that every fallback that
// reads them must be dead, and this is the guard that kills them. Checked
// once per process, like ReachBoundsReady/GeomShareReady: the operator drops
// the columns only after the cells backfill reaches 100%, and restarts the Go
// API afterwards.
//
// The three guards describe successive schema eras and are deliberately
// separate: GeomShareReady (the dedup hash/table, unwound by the same drop),
// ReachBoundsReady (the sandwich bounds, which STAY), and this one (the fat
// geometry itself).
func LegacyPolygonReady(db *gorm.DB) bool {
	if v, ok := legacyOverride("polygon"); ok {
		return v
	}
	if db == nil {
		return false
	}
	legacyPolygonOnce.Do(func() {
		var n int64
		db.Table("information_schema.COLUMNS").
			Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'polygon'").
			Count(&n)
		legacyPolygonExists = n > 0
	})
	return legacyPolygonExists
}

// SpatialReachIDs asks the spatial server which live reaches cover the point:
// `in` are definite (answered exactly from stored cell grids), `partial` are
// legacy coarse-raster rows whose boundary band cannot decide (the caller
// exact-tests those against the legacy geometry while it exists). ok=false
// (mode off, transport error, dataset not ready) means the caller must use
// its SQL containment path.
//
// The SPATIAL_REACH_MODE dark-launch flag is only consulted while the legacy
// polygon columns exist: after the drop there is no SQL containment to fall
// back to, so the spatial index is always tried and its failure falls to the
// caller's outer-bound + cells-probe degraded path instead.
func SpatialReachIDs(db *gorm.DB, lng, lat float64) (in []int64, partial []int64, ok bool) {
	if os.Getenv("SPATIAL_REACH_MODE") != "on" && LegacyPolygonReady(db) {
		return nil, nil, false
	}
	in, partial, err := spatial.ReachContaining(lng, lat)
	if err != nil {
		return nil, nil, false
	}
	return in, partial, true
}

var legacyOverflowOnce sync.Once
var legacyOverflowExists bool

// LegacyOverflowReady is LegacyPolygonReady's twin for overflow_bounds (the
// ring WKT column). Separate because the two columns are dropped by separate
// statements and any window between them must not confuse the guards.
func LegacyOverflowReady(db *gorm.DB) bool {
	if v, ok := legacyOverride("overflow"); ok {
		return v
	}
	if db == nil {
		return false
	}
	legacyOverflowOnce.Do(func() {
		var n int64
		db.Table("information_schema.COLUMNS").
			Where("table_schema = DATABASE() AND table_name = 'rippling_reach' AND column_name = 'overflow_bounds'").
			Count(&n)
		legacyOverflowExists = n > 0
	})
	return legacyOverflowExists
}
