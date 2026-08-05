package test

// Batch C (per team-lead): the remaining runtime-varying keep-raw sites
// across rippling/analytics.go, chat/chatmessage.go, image/image.go,
// message/message.go, isochrone/message.go, chat/chatroom.go,
// message/helper.go, logs/logs.go, job/job.go and comment/comment.go.
//
// Most of this batch (21 of 28 sites) turned out to already be converted -
// Tier3-shapes had removed their keep-raw.json rules and added passing
// AssertGoldenShapes tests (test/orm_tier3_shapes_test.go,
// test/orm_tier2_test.go, test/orm_shapes_pilot_test.go), but manifest.json's
// status field was never flipped from "keep-raw" to "converted". That
// included image/image.go's four sites, which the team lead flagged as
// possibly needing a new "varying identifier" capability (table/column name
// looked up from typeConfigs[imgType] at runtime) - it turned out
// test/orm_shapes_pilot_test.go had already solved exactly that, by
// declaring one named shape per configured image type (the set is finite and
// known at compile time: Message/Group/Newsletter/CommunityEvent/
// Volunteering/ChatMessage/User/Newsfeed/Story/Noticeboard). No new harness
// capability needed there - that was a manifest hygiene gap, not an open
// mechanism question.
//
// This file covers the genuinely-still-raw remainder.

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// legacyReachWhere/sandwichReachWhere are the two forms
// isochrone/reachbounds.go's reachContainmentSQL can return, gated on
// rippling.ReachBoundsReady(db) (a live-DB, sync.Once-cached check - not
// something a dry-run Layer 1 test can flip, and even if it could,
// reachBoundsOnce is a package-level singleton shared across the whole test
// binary). Both forms are copied verbatim from rippling/reachbounds.go
// (ReachBrowseWhere) and isochrone/reachbounds.go so this proves the GORM
// chain renders EACH actual shape correctly, the same "hardcode the known
// forms, don't call the live-gated function" approach
// ormharness/reachcap_test.go already established for the reach-cap half of
// these same two sites.
const legacyReachWhere = "AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "
const sandwichReachWhere = "AND MBRContains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
	"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) " +
	"AND (COALESCE(ST_Contains(rr.inner_bound, ST_SRID(POINT(?, ?), ?)), 0) = 1 " +
	"OR EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.msgid = rr.msgid AND ST_Contains(r2.polygon, ST_SRID(POINT(?, ?), ?)))) "

// reachWhereArgs returns n dummy point-triple args (float32 lng/lat + int
// srid, repeated), matching however many "?" the given reachWhere form
// carries - 3 for legacy, 12 (4 triples) for sandwich.
func reachWhereArgs(n int) []interface{} {
	args := make([]interface{}, n)
	for i := range args {
		if i%3 == 2 {
			args[i] = 3857
		} else {
			args[i] = float32(51.5)
		}
	}
	return args
}

// isochrone/message.go: myGroupsMessages. Ids always travelled as real bind
// values (fmt.Sprintf only built the "?,?,?,..." placeholder-count text
// itself, not the ids), so this needed only the native GORM IN-list form.
func TestBatchC_cacb3cb38871(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cacb3cb38871", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
				"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
				"ms.msgtype AS type, m.fromuser AS fromuser, ms.arrival, m.arrival AS posted, "+
				"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen, "+
				"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = ms.msgid AND mlv.type = ?), 0) AS views, "+
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = ms.msgid AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
				"COALESCE(rr.lat, 0) AS reach_lat, COALESCE(rr.lng, 0) AS reach_lng, COALESCE(ST_AsText(ST_Envelope(rr.polygon)), '') AS reach_wkt",
				"View", "Interested").
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", uint64(1), "View").
			Joins("LEFT JOIN rippling_reach rr ON rr.msgid = ms.msgid").
			Where("ms.msgid IN ?", []uint64{10, 20, 30}).
			Find(&dest)
	})
}

// isochrone/message.go: fetchReachCandidates. Two independent shape axes -
// unseenOnly (a plain bool toggle) and reachContainmentSQL's live-DB-gated
// choice of WHERE fragment - composed as ONE concatenated WHERE string in
// production (isochrone/message.go), for exactly the reason
// ormharness/reachcap_test.go's own doc comment calls out: splitting a
// fragment that itself contains "AND"/"OR" into a separate Where() call
// trips GORM's own paren-wrapping (clause/where.go buildExprs) once more
// than one Where expression is being combined.
func TestBatchC_5adca7e5928e(t *testing.T) {
	build := func(unseenOnly bool, reachWhere string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			unseenFilter := ""
			if unseenOnly {
				unseenFilter = "AND ml.msgid IS NULL "
			}
			whereSQL := "ms.successful = 0 " + unseenFilter + "AND rr.status != 'held' " + reachWhere + utils.AuthorReachCapWhere
			pointArgN := strings.Count(reachWhere, "?")
			whereArgs := reachWhereArgs(pointArgN)
			whereArgs = append(whereArgs, 9007199254740991.0, float32(51.5), float32(-0.1), float32(51.5))

			var dest []map[string]interface{}
			return tx.Table("messages_spatial ms").
				Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
					"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
					"ms.msgtype AS type, m.fromuser AS fromuser, ms.arrival, m.arrival AS posted, "+
					"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen, "+
					"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = ms.msgid AND mlv.type = ?), 0) AS views, "+
					"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = ms.msgid AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
					"rr.lat AS reach_lat, rr.lng AS reach_lng, ST_AsText(ST_Envelope(rr.polygon)) AS reach_wkt",
					"View", "Interested").
				Joins("INNER JOIN messages m ON m.id = ms.msgid").
				Joins("INNER JOIN users au ON au.id = m.fromuser").
				Joins("INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid").
				Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", uint64(1), "View").
				Where(whereSQL, whereArgs...).
				Find(&dest)
		}
	}

	ormharness.AssertGoldenShapes(t, "5adca7e5928e", []ormharness.Shape{
		{Name: "LegacySeen", Build: build(false, legacyReachWhere)},
		{Name: "LegacyUnseen", Build: build(true, legacyReachWhere)},
		{Name: "SandwichSeen", Build: build(false, sandwichReachWhere)},
		{Name: "SandwichUnseen", Build: build(true, sandwichReachWhere)},
	})
}

// isochrone/message.go: nearbyCount, unlimited-distance branch. Same
// live-DB-gated reachWhere axis as 5adca7e5928e above (both call
// reachContainmentSQL), no unseenOnly toggle - this branch always filters
// ml.msgid IS NULL.
func TestBatchC_73c548fa7cce(t *testing.T) {
	build := func(reachWhere string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			whereSQL := "ms.successful = 0 AND ml.msgid IS NULL " + reachWhere + utils.AuthorReachCapWhere
			pointArgN := strings.Count(reachWhere, "?")
			whereArgs := reachWhereArgs(pointArgN)
			whereArgs = append(whereArgs, 9007199254740991.0, float32(51.5), float32(-0.1), float32(51.5))

			var dest []map[string]interface{}
			return tx.Table("messages_spatial ms").
				Select("COUNT(DISTINCT ms.msgid)").
				Joins("INNER JOIN messages m ON m.id = ms.msgid").
				Joins("INNER JOIN users au ON au.id = m.fromuser").
				Joins("INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid").
				Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", uint64(1), "View").
				Where(whereSQL, whereArgs...).
				Find(&dest)
		}
	}

	ormharness.AssertGoldenShapes(t, "73c548fa7cce", []ormharness.Shape{
		{Name: "Legacy", Build: build(legacyReachWhere)},
		{Name: "Sandwich", Build: build(sandwichReachWhere)},
	})
}

// job/job.go: JobsForIDs. Two shapes on categoryClause (no category filter
// vs a REGEXP bind); areaClause (the %f-formatted search-box polygon) is
// fixed text for a given lat/lng/distByID input, unchanged by the category
// axis, so both shapes share the same box built from the SAME fixed inputs
// (lat=51.5, lng=-0.1, distByID={100: 0.5} -> maxDist=0.5, chosen so the
// box corners are round numbers: sw=(-0.6,51.0), ne=(0.4,52.0)). Only the
// id-list conversion (native "IN ?" instead of a hand-built comma-joined
// string) is new; the box's float formatting is untouched - see
// job/job.go's own comment on why that sidesteps the precision-equivalence
// question the keep-raw reason raised.
func TestBatchC_bc3af5374c0c(t *testing.T) {
	const box = "ST_SRID(POLYGON(LINESTRING(POINT(-0.600000, 51.000000), POINT(-0.600000, 52.000000), " +
		"POINT(0.400000, 52.000000), POINT(0.400000, 51.000000), POINT(-0.600000, 51.000000))), 3857)"
	areaClause := "(ST_Dimension(jobs.geometry) < 2 OR ST_Area(jobs.geometry) / ST_Area(" + box + ") < 2)"
	distExpr := "ST_Distance_Sphere(POINT(?, ?), POINT(ST_X(ST_Centroid(jobs.geometry)), ST_Y(ST_Centroid(jobs.geometry))))"

	build := func(categoryClause string, categoryArgs ...any) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			var dest []map[string]interface{}
			whereSQL := "jobs.id IN ? AND " + categoryClause + " AND " + areaClause
			whereArgs := append([]any{[]int64{100, 200}}, categoryArgs...)
			return tx.Table("jobs").
				Select("jobs.id, jobs.url, jobs.title, jobs.location, jobs.body, jobs.job_reference, "+
					"jobs.category, jobs.cpc, jobs.clickability, ai_images.externaluid, "+
					distExpr+" / 1000 AS dist_km",
					-0.1, 51.5).
				Joins("LEFT JOIN ai_images ON ai_images.name = jobs.canonical_title").
				Where(whereSQL, whereArgs...).
				Order("jobs.cpc * jobs.clickability * GREATEST(0.5, 1 - COALESCE(DATEDIFF(NOW(), jobs.posted_at), 0) * 0.07) DESC, jobs.id ASC").
				Limit(50).
				Find(&dest)
		}
	}

	ormharness.AssertGoldenShapes(t, "bc3af5374c0c", []ormharness.Shape{
		{Name: "NoCategory", Build: build("category IS NOT NULL")},
		{Name: "WithCategory", Build: build("category REGEXP ?", "(^|;)jobs.*")},
	})
}
