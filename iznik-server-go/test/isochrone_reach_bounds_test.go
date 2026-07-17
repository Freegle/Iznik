package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// Sandwich-bounds prefilter for the reach queries (plans/2026-07-17-db3-cpu-reach-sql-
// prefilter.md): the nearby feed and count consult the small derived bounds in
// rippling_reach_bounds before the ~178KB exact polygon — outside outer_bound is an
// authoritative cheap reject, inside inner_bound an authoritative cheap accept, and only
// the band between them (or a missing bounds row) tests the exact polygon.
//
// The fixtures here are ADVERSARIAL: their bounds deliberately contradict their polygon,
// which verified writer-derived bounds never do — it is the only way to observe from the
// outside which shape the query trusted.

func ensureReachBoundsTable() {
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach_bounds (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		outer_bound GEOMETRY NOT NULL SRID 3857,
		inner_bound GEOMETRY NULL SRID 3857,
		SPATIAL INDEX rippling_reach_bounds_outer (outer_bound)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)
}

func insertReachPolygon(mid uint64, wkt string) {
	db := database.DBConn
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText(?, 3857), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), status = VALUES(status)", mid, wkt)
}

func insertBounds(mid uint64, outerWkt string, innerWkt *string) {
	db := database.DBConn
	if innerWkt == nil {
		db.Exec("INSERT INTO rippling_reach_bounds (msgid, outer_bound, inner_bound) "+
			"VALUES (?, ST_GeomFromText(?, 3857), NULL) "+
			"ON DUPLICATE KEY UPDATE outer_bound = VALUES(outer_bound), inner_bound = NULL", mid, outerWkt)
	} else {
		db.Exec("INSERT INTO rippling_reach_bounds (msgid, outer_bound, inner_bound) "+
			"VALUES (?, ST_GeomFromText(?, 3857), ST_GeomFromText(?, 3857)) "+
			"ON DUPLICATE KEY UPDATE outer_bound = VALUES(outer_bound), inner_bound = VALUES(inner_bound)",
			mid, outerWkt, *innerWkt)
	}
}

const coversViewerWkt = "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"
const missesViewerWkt = "POLYGON((5.0 51.4, 5.2 51.4, 5.2 51.6, 5.0 51.6, 5.0 51.4))"
const bigCoversViewerWkt = "POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))"
const farAwayWkt = "POLYGON((5 5, 5.1 5, 5.1 5.1, 5 5.1, 5 5))"

// cShapeMissesViewerWkt is a C-shaped polygon whose BOUNDING BOX covers the viewer at
// (-0.1, 51.5) but whose area does not (the viewer sits in the C's cavity). Cheap-accept
// fixtures use it because the sandwich query keeps an index-only MBRContains(rr.polygon)
// conjunct to drive the R-tree — always implied in production, where verified
// inner ⊆ polygon ⊆ its MBR — so a fixture whose whole bbox is elsewhere would be
// rejected by the index before the inner bound could prove anything. With this shape the
// MBR passes and the assertion isolates what matters: the inner bound short-circuits the
// exact ST_Contains.
const cShapeMissesViewerWkt = "POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.35, -0.25 51.35, -0.25 51.65, 0.1 51.65, 0.1 51.7, -0.3 51.7, -0.3 51.3))"

func TestNearbyReachFeedSandwichBounds(t *testing.T) {
	db := database.DBConn
	ensureReachBoundsTable()

	prefix := uniquePrefix("sandwich")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)

	// Polygon covers the viewer, but outer_bound is far away → the bounds cheap-reject
	// must hide it without consulting the polygon.
	cheapReject := CreateTestMessage(t, posterID, group, "OFFER: cheap reject (sandwich)", 51.5, -0.1)
	// Polygon does NOT cover the viewer, but inner_bound does → the bounds cheap-accept
	// must show it without consulting the polygon.
	cheapAccept := CreateTestMessage(t, posterID, group, "OFFER: cheap accept (sandwich)", 51.5, -0.1)
	// In the boundary band (inside outer, no inner): the exact polygon decides — covered.
	bandIn := CreateTestMessage(t, posterID, group, "OFFER: band in (sandwich)", 51.5, -0.1)
	// In the boundary band: the exact polygon decides — not covered.
	bandOut := CreateTestMessage(t, posterID, group, "OFFER: band out (sandwich)", 51.5, -0.1)
	// No bounds row at all: fail-safe fallback to the exact polygon — covered.
	fallback := CreateTestMessage(t, posterID, group, "OFFER: fallback (sandwich)", 51.5, -0.1)

	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?, ?, ?, ?)",
		cheapReject, cheapAccept, bandIn, bandOut, fallback)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?, ?, ?)",
		cheapReject, cheapAccept, bandIn, bandOut, fallback)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	inner := coversViewerWkt
	insertReachPolygon(cheapReject, coversViewerWkt)
	insertBounds(cheapReject, farAwayWkt, nil)

	insertReachPolygon(cheapAccept, cShapeMissesViewerWkt)
	insertBounds(cheapAccept, bigCoversViewerWkt, &inner)

	insertReachPolygon(bandIn, coversViewerWkt)
	insertBounds(bandIn, bigCoversViewerWkt, nil)

	insertReachPolygon(bandOut, missesViewerWkt)
	insertBounds(bandOut, bigCoversViewerWkt, nil)

	insertReachPolygon(fallback, coversViewerWkt)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}

	assert.False(t, got[cheapReject], "a viewer outside outer_bound is cheap-rejected without testing the polygon")
	assert.True(t, got[cheapAccept], "a viewer inside inner_bound is cheap-accepted without testing the polygon")
	assert.True(t, got[bandIn], "boundary band falls through to the exact polygon (covered → shown)")
	assert.False(t, got[bandOut], "boundary band falls through to the exact polygon (not covered → hidden)")
	assert.True(t, got[fallback], "a post with no bounds row falls back to the exact polygon")
}

// The unlimited-distance nearby COUNT (the 60s badge poll's fast path) runs its own SQL —
// it must consult the sandwich exactly like the feed, or badge and feed drift.
func TestNearbyCountSandwichBounds(t *testing.T) {
	db := database.DBConn
	ensureReachBoundsTable()

	prefix := uniquePrefix("sandwichcnt")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)

	cheapReject := CreateTestMessage(t, posterID, group, "OFFER: count cheap reject (sandwichcnt)", 51.5, -0.1)
	cheapAccept := CreateTestMessage(t, posterID, group, "OFFER: count cheap accept (sandwichcnt)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", cheapReject, cheapAccept)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", cheapReject, cheapAccept)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	countOf := func() float64 {
		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		return body["count"].(float64)
	}

	// Delta-based so leftover reach fixtures from other tests can't skew it, and STAGED
	// so polygon-only reads can't accidentally produce the same totals: first the
	// cheap-reject fixture alone (polygon-only would count it: +1; the sandwich must
	// not: +0), then the cheap-accept fixture (sandwich counts it: +1).
	before := countOf()

	insertReachPolygon(cheapReject, coversViewerWkt)
	insertBounds(cheapReject, farAwayWkt, nil)
	assert.Equal(t, before, countOf(),
		"a viewer outside outer_bound must not be counted, even though the polygon covers them")

	inner := coversViewerWkt
	insertReachPolygon(cheapAccept, cShapeMissesViewerWkt)
	insertBounds(cheapAccept, bigCoversViewerWkt, &inner)
	assert.Equal(t, before+1, countOf(),
		"a viewer inside inner_bound is counted, even though the polygon does not cover them")
}

// ClipReachForRejectedGroup SHRINKS the polygon in place (ST_Difference). A stale inner
// bound could then cheap-accept viewers inside the just-clipped-out area, so the clip
// must synchronously NULL inner_bound. (The outer bound is merely stale-loose — safe —
// and the next expander tick re-derives both.)
func TestClipReachForRejectedGroupNullsInnerBound(t *testing.T) {
	db := database.DBConn
	ensureReachBoundsTable()

	prefix := uniquePrefix("clipbounds")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: clip bounds (clipbounds)", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	// Rejecting group's area = the EASTERN half of the reach.
	db.Exec("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((-0.15 51.39,-0.05 51.39,-0.05 51.61,-0.15 51.61,-0.15 51.39))', 3857) WHERE id = ?", group)

	inner := "POLYGON((-0.18 51.42, -0.02 51.42, -0.02 51.58, -0.18 51.58, -0.18 51.42))"
	insertReachPolygon(mid, coversViewerWkt)
	insertBounds(mid, bigCoversViewerWkt, &inner)

	message.ClipReachForRejectedGroup(db, mid, group)

	// The polygon shrank (partial clip, row retained)...
	var polyType string
	db.Raw("SELECT ST_GeometryType(polygon) FROM rippling_reach WHERE msgid = ?", mid).Scan(&polyType)
	assert.NotEmpty(t, polyType, "reach row survives a partial clip")

	// ...and the inner bound was NULLed synchronously.
	var innerNull int
	db.Raw("SELECT inner_bound IS NULL FROM rippling_reach_bounds WHERE msgid = ?", mid).Scan(&innerNull)
	assert.Equal(t, 1, innerNull, "a polygon shrink must synchronously clear the inner bound")

	// The outer bound is untouched (stale-loose is safe).
	var outerType string
	db.Raw("SELECT ST_GeometryType(outer_bound) FROM rippling_reach_bounds WHERE msgid = ?", mid).Scan(&outerType)
	assert.Equal(t, "POLYGON", outerType, "outer bound is left as-is on clip")
}
