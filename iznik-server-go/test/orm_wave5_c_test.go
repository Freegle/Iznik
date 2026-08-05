package test

// Wave 5, batch C (plan section 7.3+): message/message.go's
// ClipReachForRejectedGroup (7653c7a2e4ed), from the "runtime-varying"
// keep-raw review. An optional ", mr.inner_bound = NULL" SET fragment is
// appended only once rippling.ReachBoundsReady(db) is true - exactly 2 real
// rendered forms, not unbounded input - so this converts with
// AssertGoldenShapes.
//
// Two sibling sites originally scoped into this batch - newsfeed/
// newsfeed.go's getFeed, both the gotLatLng==true (c42088f47c61) and
// gotLatLng==false (7558e63cd067) branches - turned out NOT convertible:
// both are genuine top-level 4-way/3-way SQL UNIONs on the highest-traffic
// newsfeed read path (~30+ positional bind args per branch), and GORM ships
// no UNION clause - same reason chat/chatroom.go's listChats stays raw. They
// were reverted out of this file; see their keep-raw.json entries
// ("Top-level UNION" category) for the record.
//
// The UPDATE ... JOIN here stays as db.Table(...).Clauses(...) rather than a
// plain .Table()/.Where() chain, using the ordered clause.Set house style
// established for that category (updatejoin_replace_test.go) and already
// proven at runtime by other live sites (session/merge.go, user/user.go,
// location/location.go, isochrone/isochrone.go, message.go's own
// PutMessageAs/applyPatchMessageCore).
//
// Nothing here is taken on trust: the rendered SQL for both shapes was
// independently checked with ormharness.RenderDryRunSQL against the exact
// production Table/Clauses/Where/Updates chain before this file was written.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- message/message.go: ClipReachForRejectedGroup (site 7653c7a2e4ed) -----

func TestOrmWave5_7653c7a2e4ed(t *testing.T) {
	build := func(withInnerBound bool) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			set := clause.Set{
				{Column: clause.Column{Table: "mr", Name: "polygon"}, Value: gorm.Expr("ST_Difference(mr.polygon, g.polyindex)")},
			}
			if withInnerBound {
				set = append(set, clause.Assignment{
					Column: clause.Column{Table: "mr", Name: "inner_bound"}, Value: gorm.Expr("NULL"),
				})
			}
			return tx.Table("rippling_reach mr JOIN `groups` g ON g.id = ?", uint64(7)).
				Clauses(set).
				Where("mr.msgid = ? AND g.polyindex IS NOT NULL AND ST_GeometryType(g.polyindex) <> 'POINT' AND ST_Intersects(mr.polygon, g.polyindex) AND NOT ST_Within(mr.polygon, g.polyindex)", uint64(42)).
				Updates(map[string]interface{}{})
		}
	}

	ormharness.AssertGoldenShapes(t, "7653c7a2e4ed", []ormharness.Shape{
		{Name: "NoInnerBound", Build: build(false)},
		{Name: "WithInnerBound", Build: build(true)},
	})
}
