package rippling

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// GeomJoin/GeomExpr are the PK-join-plus-COALESCE contract every reader of a
// shared reach geometry uses (plans/2026-08-23-rippling-reach-polygon-dedup.md).
// share=false must be a pure no-op (empty join, bare column reference) so an
// unmigrated deploy runs exactly the pre-dedup query.
func TestGeomJoin_NotShared(t *testing.T) {
	assert.Equal(t, "", GeomJoin(false, "rr", "polygon", "g"))
	assert.Equal(t, "", GeomJoin(false, "rr", "max_polygon", "g"))
}

func TestGeomJoin_Shared(t *testing.T) {
	assert.Equal(t, " LEFT JOIN rippling_reach_geom g ON g.hash = rr.polygon_hash",
		GeomJoin(true, "rr", "polygon", "g"))
	assert.Equal(t, " LEFT JOIN rippling_reach_geom gm ON gm.hash = rr.max_polygon_hash",
		GeomJoin(true, "rr", "max_polygon", "gm"))
}

func TestGeomExpr_NotShared(t *testing.T) {
	assert.Equal(t, "rr.polygon", GeomExpr(false, "rr", "polygon", "g"))
	assert.Equal(t, "r2.max_polygon", GeomExpr(false, "r2", "max_polygon", "g2"))
}

func TestGeomExpr_Shared(t *testing.T) {
	assert.Equal(t, "COALESCE(g.geom, rr.polygon)", GeomExpr(true, "rr", "polygon", "g"))
	assert.Equal(t, "COALESCE(gm.geom, rr.max_polygon)", GeomExpr(true, "rr", "max_polygon", "gm"))
}

// assertShareable is the allowlist keeping column names honest before they are
// interpolated into SQL - a typo must fail loudly (panic), not silently parse
// as some other column.
func TestGeomJoinExpr_PanicOnUnknownColumn(t *testing.T) {
	assert.Panics(t, func() { GeomJoin(true, "rr", "outer_bound", "g") })
	assert.Panics(t, func() { GeomExpr(true, "rr", "inner_bound", "g") })
	assert.Panics(t, func() { GeomJoin(false, "rr", "schedule", "g") })
}

// GeomShareReady must not panic or query on a nil handle - callers that have
// not yet resolved a *gorm.DB (or are testing without one) get a safe false.
func TestGeomShareReady_NilDB(t *testing.T) {
	assert.False(t, GeomShareReady(nil))
}
