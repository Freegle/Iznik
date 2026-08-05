package test

// Tier 1 of the keep-raw adversarial review (plans/active/orm-keepraw-
// adversarial-review.md, section 4): spatial and adjacent sites the review
// found need NO new harness infrastructure, because a working precedent for
// the exact mechanism already ships elsewhere in this repo.
//
//   - ST_* as literal SELECT-column text, .Scan()-terminated in production.
//     Precedent: user/user.go:408 (site b06cd1b62344) - GORM never parses a
//     Select() string's content, so ST_AsText/ST_Y/ST_X/ST_CENTROID/etc.
//     survive verbatim exactly like any other column expression.
//   - ST_Contains/ST_SRID/POLYGON as bind-heavy WHERE or JOIN-ON text.
//     Precedent: chat/chatmessage.go:615 (site f31ae2ffe181) - same
//     mechanism, GORM only supplies the "?" positions, never the SQL around
//     them.
//   - Bound ORDER BY via ST_Distance_Sphere. Precedent: message/message.go's
//     ResolveOnBehalfPosting (site ecaf3f90bee2) - Order() itself takes no
//     bind args, so clause.OrderBy{Expression: gorm.Expr(...)} carries them.
//   - Multi-table DELETE ... FROM t1 JOIN t2. GORM's Delete callback
//     (callbacks/delete.go) only ever calls AddClauseIfNotExists(clause.From{})
//     - unlike the SELECT query callback, it never reads Statement.Joins - so
//     a plain .Joins() before .Delete() is silently dropped. Supplying an
//     already-populated clause.From{} via .Clauses() before .Delete() runs
//     makes that AddClauseIfNotExists a no-op, so the join set on it survives.
//   - UPDATE assignment via gorm.Expr(ST_GeomFromText/ST_SIMPLIFY), SRID as a
//     plain bind. Precedent in the same file: isochrone/isochrone.go's sibling
//     INSERT a few lines below (site d91a1a5d6b27) already puts the identical
//     expression into a gorm.Expr on a map-valued Create.
//   - The reach-cap sweep-ins: isochrone/message.go's keep-raw rule had no
//     function scoping, so its "Embeds utils.AuthorReachCapWhere" reason swept
//     in every raw site in the file - including six that do not embed that
//     fragment at all (checked individually against the real source below;
//     see the corrected, function-scoped keep-raw.json rules for the four
//     that stay raw for a real reason: fetchReachCandidates and nearbyCount
//     both call reachContainmentSQL, which branches on a live-DB
//     rippling.ReachBoundsReady gate - two genuine shapes, not a sweep-in;
//     markPinned splices a literal (non-bind) IN-list; myGroupsMessages'
//     bind-based IN-list golden was recorded as the unresolved runtime
//     placeholder-count string).
//
// Every test here renders the SAME chain the corresponding production site
// now uses (reads swap the production .Scan() for .Find(), the one
// dry-run-incompatible terminal - see ormharness/golden.go's renderDryRun)
// and compares it against the manifest's own recorded goldenSql via
// ormharness.AssertGoldenSQL, so a test failure here means the conversion
// diverged from the byte-exact pre-conversion statement, not from some
// hand-transcribed expectation. Every one of these would fail against the
// pre-conversion form: dropping .Table()'s alias/backtick handling,
// reordering a Joins() call, or supplying binds in the wrong clause all
// change the rendered SQL text that Canonical() compares.

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- isochrone/message.go: reach-cap sweep-ins, six ordinary fixed-shape queries ---

func TestTier1Spatial_7c6e312c8e3e(t *testing.T) {
	// effectiveBrowseView.
	ormharness.AssertGoldenSQL(t, "7c6e312c8e3e", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users").
			Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseView')), '')").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_d533dd101d62(t *testing.T) {
	// resolveMaxDistance.
	ormharness.AssertGoldenSQL(t, "d533dd101d62", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users").
			Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxDistance')), '')").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_93c466090882(t *testing.T) {
	// myGroupsMsgIDs.
	ormharness.AssertGoldenSQL(t, "93c466090882", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("DISTINCT ms.msgid").
			Where("ms.successful = 0 "+
				"AND EXISTS (SELECT 1 FROM messages_groups mg "+
				"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
				"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
				"AND mg.collection = 'Approved' AND mg.deleted = 0)", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_771b502217d0(t *testing.T) {
	// myGroupsCountUnfiltered.
	ormharness.AssertGoldenSQL(t, "771b502217d0", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("COUNT(DISTINCT ms.msgid)").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", 1, "View").
			Where("ms.successful = 0 AND ml.msgid IS NULL "+
				"AND EXISTS (SELECT 1 FROM messages_groups mg "+
				"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
				"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
				"AND mg.collection = 'Approved' AND mg.deleted = 0)", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_cf109e997b23(t *testing.T) {
	// myGroupsCount's distance-limited candidate query.
	ormharness.AssertGoldenSQL(t, "cf109e997b23", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, ms.msgid AS id").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", 1, "View").
			Where("ms.successful = 0 AND ml.msgid IS NULL "+
				"AND EXISTS (SELECT 1 FROM messages_groups mg "+
				"INNER JOIN memberships mem ON mem.groupid = mg.groupid "+
				"WHERE mg.msgid = ms.msgid AND mem.userid = ? "+
				"AND mg.collection = 'Approved' AND mg.deleted = 0)", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_29289246c69b(t *testing.T) {
	// Messages' own-posts arm (the viewer's own recent posts, independent of reach).
	ormharness.AssertGoldenSQL(t, "29289246c69b", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages m").
			Select("m.lat, m.lng, m.id, "+
				"ANY_VALUE(CASE WHEN mo.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
				"ANY_VALUE(CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
				"ANY_VALUE(mg.groupid) AS groupid, m.type, m.fromuser AS fromuser, "+
				"MAX(mg.arrival) AS arrival, m.arrival AS posted, "+
				"ANY_VALUE(CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END) AS unseen, "+
				"COALESCE((SELECT SUM(mlv.count) FROM messages_likes mlv WHERE mlv.msgid = m.id AND mlv.type = ?), 0) AS views, "+
				"(SELECT COUNT(*) FROM chat_messages cm WHERE cm.refmsgid = m.id AND cm.type = ? AND cm.reviewrejected = 0 AND cm.reviewrequired = 0) AS replies, "+
				"ANY_VALUE(COALESCE(rr.lat, 0)) AS reach_lat, ANY_VALUE(COALESCE(rr.lng, 0)) AS reach_lng, "+
				"ANY_VALUE(COALESCE(ST_AsText(ST_Envelope(rr.polygon)), '')) AS reach_wkt",
				"Taken", "Received", "View", "Interested").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
			Joins("LEFT JOIN messages_outcomes mo ON mo.msgid = m.id").
			Joins("LEFT JOIN messages_promises mp ON mp.msgid = m.id").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = m.id AND ml.userid = ? AND ml.type = ?", 1, "View").
			Joins("LEFT JOIN rippling_reach rr ON rr.msgid = m.id").
			Where("m.fromuser = ? AND mg.arrival >= ? AND mo.id IS NULL "+
				"AND (EXISTS (SELECT 1 FROM messages_spatial ms WHERE ms.msgid = m.id) "+
				"OR mg.collection = ?)", 1, "2026-01-01", "Pending").
			Group("m.id").
			Find(&dest)
	})
}

// --- authority/authority.go: Single --------------------------------------

func TestTier1Spatial_92416198b77d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "92416198b77d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("authorities").
			Select("id, name, area_code, ST_AsText(COALESCE(simplified, polygon)) AS polygon, "+
				"ST_Y(ST_CENTROID(polygon)) AS lat, ST_X(ST_CENTROID(polygon)) AS lng").
			Where("id = ?", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_3048dd76eab0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3048dd76eab0", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("groups").
			Select("groups.id, nameshort, namefull, lat, lng, "+
				"CASE WHEN poly IS NOT NULL THEN poly ELSE polyofficial END AS poly, "+
				"CASE WHEN ST_GeometryType(St_intersection(polyindex, Coalesce(simplified, polygon))) != 'GEOMCOLLECTION' THEN "+
				"CASE WHEN polyindex = Coalesce(simplified, polygon) THEN 1 "+
				"ELSE St_area(St_intersection(polyindex, Coalesce(simplified, polygon))) / St_area(polyindex) "+
				"END "+
				"ELSE 0 "+
				"END AS overlap, "+
				"CASE WHEN ST_GeometryType(St_intersection(polyindex, Coalesce(simplified, polygon))) != 'GEOMCOLLECTION' THEN "+
				"CASE WHEN polyindex = Coalesce(simplified, polygon) THEN 1 "+
				"ELSE St_area(polyindex) / St_area(St_intersection(polyindex, Coalesce(simplified, polygon))) "+
				"END "+
				"ELSE 0 "+
				"END AS overlap2").
			Joins("INNER JOIN authorities ON ( polyindex = Coalesce(simplified, polygon) OR St_intersects(polyindex, Coalesce(simplified, polygon)) )").
			Where("type = ? AND publish = 1 AND onmap = 1 AND authorities.id = ?", "Freegle", 1).
			Find(&dest)
	})
}

// --- authority/message.go: Messages ---------------------------------------

func TestTier1Spatial_1cbaddb2ed2a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1cbaddb2ed2a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial").
			Select("ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, "+
				"messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, "+
				"messages_spatial.msgtype AS type, messages_spatial.arrival, "+
				"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen").
			Joins("INNER JOIN authorities ON ST_Contains(authorities.polygon, point)").
			Joins("INNER JOIN `groups` ON groups.id = messages_spatial.groupid").
			Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ?", 1, "View").
			Where("authorities.id = ? AND messages_spatial.msgid > 0", 1).
			Order("unseen DESC, messages_spatial.arrival DESC, messages_spatial.msgid DESC").
			Find(&dest)
	})
}

// --- dashboard/dashboard.go: GetDashboard heatmap -------------------------

func TestTier1Spatial_88aea8da62be(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "88aea8da62be", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial").
			Select("ROUND(ST_Y(point), 2) AS lat, ROUND(ST_X(point), 2) AS lng, COUNT(*) AS count").
			Where("arrival > DATE_SUB(NOW(), INTERVAL 31 DAY) AND successful = 1").
			Group("ROUND(ST_Y(point), 2), ROUND(ST_X(point), 2)").
			Find(&dest)
	})
}

// --- isochrone/isochrone.go: ListIsochrones (x2, identical shape) and HealPointIsochrones ---

func TestTier1Spatial_701348841f78(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "701348841f78", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("isochrones_users").
			Select("isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon").
			Joins("INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id").
			Where("isochrones_users.userid = ?", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_bff19d769e26(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "bff19d769e26", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("isochrones_users").
			Select("isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon").
			Joins("INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id").
			Where("isochrones_users.userid = ?", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_583d5d5394ad(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "583d5d5394ad", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("isochrones_users").
			Select("isochrones_users.id, isochroneid, userid, timestamp, nickname, locationid, transport, minutes, ST_AsText(polygon) AS polygon").
			Joins("INNER JOIN isochrones ON isochrones_users.isochroneid = isochrones.id").
			Where("isochrones_users.userid = ?", 1).
			Find(&dest)
	})
}

// --- isochrone/isochrone.go: EnsureIsochroneExists's two POINT-lookup SELECTs and its UPDATE ---

func TestTier1Spatial_34e1f2e54b22(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "34e1f2e54b22", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("isochrones").
			Select("id").
			Where("locationid = ? AND transport = ? AND minutes = ? AND ST_GeometryType(polygon) != 'POINT'", 1, "Walk", 15).
			Order("id DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestTier1Spatial_5eea52bb68f7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5eea52bb68f7", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("isochrones").
			Select("id").
			Where("locationid = ? AND transport = ? AND minutes = ? AND ST_GeometryType(polygon) = 'POINT'", 1, "Walk", 15).
			Order("id DESC").
			Limit(1).
			Find(&dest)
	})
}

func TestTier1Spatial_e0e7375e71aa(t *testing.T) {
	// The explicit clause.Set (not Updates(map)) keeps source before polygon,
	// exactly as the original SET list had it - both assignments are
	// independent (neither reads the other's column) so a harness-normalised
	// reorder would also pass, but this matches the golden without relying on
	// that tolerance at all.
	ormharness.AssertGoldenSQL(t, "e0e7375e71aa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("isochrones").
			Clauses(clause.Set{
				{Column: clause.Column{Name: "source"}, Value: "Mapbox"},
				{Column: clause.Column{Name: "polygon"}, Value: gorm.Expr(
					"CASE WHEN ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) IS NULL THEN ST_GeomFromText(?, ?) ELSE ST_SIMPLIFY(ST_GeomFromText(?, ?), 0.01) END",
					"POLYGON((0 0,1 0,1 1,0 1,0 0))", 3857, "POLYGON((0 0,1 0,1 1,0 1,0 0))", 3857, "POLYGON((0 0,1 0,1 1,0 1,0 0))", 3857)},
			}).
			Where("id = ?", 1).
			Updates(map[string]interface{}{})
	})
}

// --- message/postmatches.go: PostMatches ----------------------------------

func TestTier1Spatial_498e74a4e8c5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "498e74a4e8c5", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_embeddings me").
			Select("me.subject_embedding, m.fromuser, m.type, ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng").
			Joins("INNER JOIN messages m ON m.id = me.msgid").
			Joins("LEFT JOIN messages_spatial ms ON ms.msgid = me.msgid").
			Where("me.msgid = ?", 1).
			Find(&dest)
	})
}

// --- message/reach.go: Reach and ReachBlockedSet's non-sandwich-bounds branch ---

func TestTier1Spatial_388f473a3332(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "388f473a3332", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_reach").
			Select("tick, total_ticks, status, arrival, next_expansion_at, ST_AsGeoJSON(polygon, 5) AS polygon").
			Where("msgid = ?", 1).
			Find(&dest)
	})
}

func TestTier1Spatial_7e7e69fa2f85(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7e7e69fa2f85", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_reach").
			Select("msgid").
			Where("msgid IN ? AND ST_Contains(polygon, ST_SRID(POINT(?, ?), ?)) = 0", []uint64{1, 2, 3}, 51.5, -0.1, 3857).
			Find(&dest)
	})
}

// --- noticeboard/noticeboard.go: getList's authority-scoped branch --------

func TestTier1Spatial_e9346f66bbf4(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e9346f66bbf4", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("noticeboards").
			Select("noticeboards.id, noticeboards.name, noticeboards.lat, noticeboards.lng").
			Joins("INNER JOIN authorities ON authorities.id = ?", 1).
			Where("authorities.name IS NOT NULL AND active = 1 AND ST_CONTAINS(authorities.polygon, ST_SRID(POINT(noticeboards.lng, noticeboards.lat), ?))", 3857).
			Find(&dest)
	})
}

// --- town/town.go: Near's "closer than" fallback --------------------------

func TestTier1Spatial_23ee2bc0640f(t *testing.T) {
	// Order() itself takes no bind args, so the two ST_Distance_Sphere binds
	// go through clause.OrderBy{Expression: gorm.Expr(...)} - same technique
	// as message/message.go's ResolveOnBehalfPosting (site ecaf3f90bee2).
	ormharness.AssertGoldenSQL(t, "23ee2bc0640f", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("towns").
			Select("name").
			Where("lat IS NOT NULL AND lng IS NOT NULL").
			Order(clause.OrderBy{Expression: gorm.Expr("ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?))", -0.1, 51.5)}).
			Limit(1).
			Find(&dest)
	})
}

// --- message/message.go: ClipReachForRejectedGroup's DELETE ... JOIN -----

func TestTier1Spatial_d4f71ea17664(t *testing.T) {
	// GORM's Delete callback (callbacks/delete.go) only ever calls
	// AddClauseIfNotExists(clause.From{}) - it never reads Statement.Joins the
	// way the SELECT query callback does - so a plain .Joins() before
	// .Delete() is silently dropped. Supplying our own non-empty clause.From{}
	// via .Clauses() before .Delete() runs makes that AddClauseIfNotExists a
	// no-op, so the join set on it survives; its Expression is a raw
	// gorm.Expr so its bind lands inside the FROM clause, ahead of the
	// WHERE's own bind. clause.Delete{Modifier: "mr"} supplies the "DELETE mr"
	// alias prefix.
	ormharness.AssertGoldenSQL(t, "d4f71ea17664", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("rippling_reach mr").
			Clauses(
				clause.Delete{Modifier: "mr"},
				clause.From{Joins: []clause.Join{{Expression: gorm.Expr("JOIN `groups` g ON g.id = ?", 5)}}},
			).
			Where("mr.msgid = ? AND g.polyindex IS NOT NULL "+
				"AND ST_GeometryType(g.polyindex) <> 'POINT' "+
				"AND ST_Within(mr.polygon, g.polyindex)", 1).
			Delete(nil)
	})
}

// Round 2 (team-lead review): the five bind-heavy core-ranking sites declined
// in round 1 for risk (no local compilation to catch a transposed bind), now
// safe to convert because the harness gained a placeholder-vs-bind-count
// check that runs before any text comparison (ormharness/golden.go), and
// team-lead can run the full suite via the status API. Every bind below was
// traced positionally against the manifest golden, not assumed from shape.

// --- location/location.go: ClosestGroups's polygon-containment pass ------

func TestTier1Spatial_3736547cd88a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3736547cd88a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("groups").
			Select("id, nameshort, namefull, ontn, settings, 0 AS dist, "+
				"haversine(lat, lng, ?, ?) AS hav, "+
				"CASE WHEN altlat IS NOT NULL THEN haversine(altlat, altlng, ?, ?) ELSE NULL END AS hav2",
				51.5, -0.1, 51.5, -0.1).
			Where("ST_Contains(polyindex, ST_SRID(POINT(?, ?), ?)) AND publish = 1 AND listable = 1", -0.1, 51.5, 3857).
			Order("hav ASC, external ASC").
			Limit(10).
			Find(&dest)
	})
}

// --- location/location.go: ClosestGroups's radius-band pass --------------

func TestTier1Spatial_961c4c5a214a(t *testing.T) {
	// No .Group() call: an empty-Columns GroupBy renders a bare "HAVING (...)"
	// with no "GROUP BY" keyword (clause/group_by.go + clause/clause.go), which
	// this golden expects.
	ormharness.AssertGoldenSQL(t, "961c4c5a214a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("groups").
			Select("id, nameshort, namefull, ontn, settings, "+
				"ST_distance(ST_SRID(POINT(?, ?), ?), polyindex) * 111195 * 0.000621371 AS dist, "+
				"haversine(lat, lng, ?, ?) AS hav, CASE WHEN altlat IS NOT NULL THEN haversine(altlat, altlng, ?, ?) ELSE NULL END AS hav2",
				-0.1, 51.5, 3857, 51.5, -0.1, 51.5, -0.1).
			Where("MBRIntersects(polyindex, ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?)) "+
				"AND publish = 1 AND listable = 1",
				-0.2, 51.4, -0.2, 51.6, 0.0, 51.6, 0.0, 51.4, -0.2, 51.4, 3857).
			Having("(hav IS NOT NULL AND hav < ? OR hav2 IS NOT NULL AND hav2 < ?)", 5.0, 5.0).
			Order("dist ASC, hav ASC, external ASC").
			Limit(10).
			Find(&dest)
	})
}

// --- location/location.go: SearchLocations's ModTools bounding-box branch -

func TestTier1Spatial_dc731a47a66a(t *testing.T) {
	// .Table() with args embeds the derived table's own bind inside the FROM
	// clause - the same mechanism as a plain literal table name taking args,
	// chainable_api.go's Table(name string, args ...interface{}).
	ormharness.AssertGoldenSQL(t, "dc731a47a66a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("(SELECT DISTINCT locationid FROM locations_spatial "+
			"INNER JOIN locations l2 ON l2.areaid = locations_spatial.locationid "+
			"WHERE ST_Intersects(locations_spatial.geometry, "+
			"ST_GeomFromText(?, ?)) "+
			"AND l2.type = ?) ls",
			"POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))", 3857, "Postcode").
			Select("DISTINCT l.id, l.name, l.type, l.lat, l.lng, l.areaid, " +
				"ST_AsText(CASE WHEN l.ourgeometry IS NOT NULL THEN l.ourgeometry ELSE l.geometry END) AS polygon").
			Joins("INNER JOIN locations l ON l.id = ls.locationid").
			Joins("LEFT JOIN locations_excluded ON ls.locationid = locations_excluded.locationid").
			Where("locations_excluded.locationid IS NULL").
			Limit(500).
			Find(&dest)
	})
}

// --- message/bounds.go: Bounds's spatial-index pass and own-posts pass ---

func TestTier1Spatial_f4e3b1a23446(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f4e3b1a23446", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial").
			Select("ST_Y(point) AS lat, ST_X(point) AS lng, messages_spatial.msgid AS id, "+
				"messages_spatial.successful, messages_spatial.promised, messages_spatial.groupid, "+
				"messages_spatial.msgtype AS type, messages_spatial.arrival, "+
				"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen").
			Joins("INNER JOIN `groups` ON groups.id = messages_spatial.groupid").
			Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ?", 1, "View").
			Where("ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), point)",
				-0.2, 51.4, -0.2, 51.6, 0.0, 51.6, 0.0, 51.4, -0.2, 51.4, 3857).
			Find(&dest)
	})
}

func TestTier1Spatial_72fd7dc3ca1e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "72fd7dc3ca1e", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages").
			Select("messages.lat, messages.lng, messages.id, "+
				"ANY_VALUE(CASE WHEN messages_outcomes.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
				"ANY_VALUE(CASE WHEN messages_promises.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
				"MIN(messages_groups.groupid) AS groupid, "+
				"messages.type,"+
				"MAX(messages_groups.arrival) AS arrival, "+
				"ANY_VALUE(CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END) AS unseen",
				"Taken", "Received").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid").
			Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Joins("LEFT JOIN messages_promises ON messages_promises.msgid = messages.id").
			Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?", 1, "View").
			Where("fromuser = ? AND messages_groups.arrival >= ? AND "+
				"ST_Contains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), ST_SRID(POINT(messages.lng, messages.lat), ?)) "+
				"AND messages_outcomes.id IS NULL",
				1, "2026-01-01", -0.2, 51.4, -0.2, 51.6, 0.0, 51.6, 0.0, 51.4, -0.2, 51.4, 3857, 3857).
			Group("messages.id").
			Find(&dest)
	})
}

// --- isochrone/message.go: markPinned, re-shaped to a bind-based IN-list -

func TestTier1Spatial_032b7f1b9500(t *testing.T) {
	// Round 1 left this raw: the ids were formatted as decimal text and
	// joined directly into the SQL string, so the golden had a variable
	// number of literal integers no fixed golden could describe. Round 2
	// switches the source to a bound []uint64 (isochrone/message.go's
	// markPinned), so the SOURCE TEXT is now a single fixed "IN ?" and this
	// is the same clean-IN-list shape as message/reach.go's ReachBlockedSet.
	ormharness.AssertGoldenSQL(t, "032b7f1b9500", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_pinned").
			Select("msgid").
			Where("msgid IN ?", []uint64{1, 2, 3}).
			Find(&dest)
	})
}

// Round 3 (team-lead review, second pass over the remaining Spatial-category
// keep-raw sites): convert what's genuinely convertible, and for anything
// that stays, name the real blocking mechanism instead of the generic
// "Spatial: risk" boilerplate a file-wide keep-raw rule swept it under.

// --- group/group.go: PatchGroup's polyindex recompute ---------------------

func TestTier1Spatial_548090e97d00(t *testing.T) {
	// SRID folded into the gorm.Expr string via fmt.Sprintf at build time -
	// same shipped idiom as location.go's locations_spatial REPLACE sites
	// (25b7b92e33fd/6f1d6543e5c0), which is text-preserving (no approved
	// diff needed): the extractor resolves the %d to the same literal number
	// either way.
	ormharness.AssertGoldenSQL(t, "548090e97d00", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("groups").
			Where("id = ?", 1).
			Update("polyindex", gorm.Expr(fmt.Sprintf(
				"ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'), %d)", 3857)))
	})
}

// --- location/location.go: UpdateLocation's validate + geometry write ----

func TestTier1Spatial_745c0a9ca82e(t *testing.T) {
	// Same bare-scalar-SELECT technique as group.go's validateGeometry (site
	// 6d0982e798b5): Statement.BuildClauses={"SELECT"} suppresses GORM's
	// automatic FROM.
	ormharness.AssertGoldenSQL(t, "745c0a9ca82e", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("locations").Select(fmt.Sprintf("ST_IsValid(ST_GeomFromText(?, %d)) AS valid", 3857), "POLYGON((0 0,1 0,1 1,0 1,0 0))")
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
}

func TestTier1Spatial_aa63c688e6b1(t *testing.T) {
	// `type` = 'Polygon' is a literal in the original SQL, not a bind, so its
	// clause.Set Value is gorm.Expr("'Polygon'") rather than a plain Go
	// string - a plain string would add a placeholder the original never had
	// and the placeholder-vs-bind-count check would catch it.
	ormharness.AssertGoldenSQL(t, "aa63c688e6b1", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("locations").
			Clauses(clause.Set{
				{Column: clause.Column{Name: "type"}, Value: gorm.Expr("'Polygon'")},
				{Column: clause.Column{Name: "ourgeometry"}, Value: gorm.Expr(
					fmt.Sprintf("ST_GeomFromText(?, %d)", 3857), "POLYGON((0 0,1 0,1 1,0 1,0 0))")},
			}).
			Where("id = ?", 1).
			Updates(map[string]interface{}{})
	})
}

// --- message/search.go: nearbyFeedMsgIDs's reach arm and own-posts arm ---

func TestTier1Spatial_ff00b3ba45a3(t *testing.T) {
	// The extractor now resolves utils.AuthorReachCapWhere to a single fixed
	// golden (no {{expr}} gap) - the manifest's stale, presentInCode=false
	// 7ef7f895e8bf is the pre-fix snapshot of this same site.
	ormharness.AssertGoldenSQL(t, "ff00b3ba45a3", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_reach rr").
			Select("ms.msgid").
			Joins("INNER JOIN messages_spatial ms ON ms.msgid = rr.msgid").
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Joins("INNER JOIN users au ON au.id = m.fromuser").
			Where("ms.successful = 0 AND rr.status != 'held' "+
				"AND ST_Contains(rr.outer_bound, ST_SRID(POINT(?, ?), ?)) "+
				"AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "+
				utils.AuthorReachCapWhere,
				-0.1, 51.5, 3857,
				-0.1, 51.5, 3857,
				9007199254740991.0, 51.5, -0.1, 51.5).
			Find(&dest)
	})
}

func TestTier1Spatial_17a182469755(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "17a182469755", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("ms.msgid").
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Where("ms.successful = 0 AND m.fromuser = ?", 1).
			Find(&dest)
	})
}
