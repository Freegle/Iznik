package isochrone

import (
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Sandwich-bounds prefilter for the browse reach queries — see
// rippling/reachbounds.go for the shared fragments and the design rationale
// (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md). The browse variant adds an
// index-only MBRContains conjunct so the query keeps driving rippling_reach's R-tree
// without fetching the polygon BLOB; verified bounds always imply it
// (inner ⊆ polygon ⊆ its MBR).

// reachContainmentSQL returns the JOIN + WHERE fragments (and their point parameters)
// for testing whether the viewer point lies inside rr's reach: the sandwich form when
// the bounds table exists, else the legacy exact-polygon test. Callers splice join
// after rippling_reach's JOIN and where in place of the old ST_Contains conjunct.
func reachContainmentSQL(db *gorm.DB, lng, lat float32) (join string, where string, args []interface{}) {
	if rippling.ReachBoundsReady(db) {
		expr, exprArgs := rippling.ReachInReachExpr(float64(lng), float64(lat), utils.SRID)
		args = append([]interface{}{lng, lat, utils.SRID}, exprArgs...)
		return rippling.ReachBoundsJoin,
			"AND MBRContains(rr.polygon, ST_SRID(POINT(?, ?), ?)) AND " + expr + " ",
			args
	}

	return "", "AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) ", []interface{}{lng, lat, utils.SRID}
}
