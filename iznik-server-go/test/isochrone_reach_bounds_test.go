package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/browsecount"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// Sandwich-bounds prefilter for the reach queries (plans/2026-07-17-db3-cpu-reach-sql-
// prefilter.md): outer_bound/inner_bound live as SAME-ROW columns on rippling_reach, and
// the nearby feed and count DRIVE their R-tree from the small indexed outer_bound — the
// design's target shape — touching the ~178KB exact polygon only for the band between
// the bounds. Sentinel ladder: real bound > envelope (derivation failed: band, exact
// decides) > POINT (completed: pruned from the R-tree itself).
//
// The fixtures here are ADVERSARIAL: bounds deliberately contradicting the polygon,
// which verified writer-derived bounds never do — it is the only way to observe from
// the outside which shape the query trusted.

// insertReachPolygon seeds a reach row whose outer_bound defaults to the polygon's
// envelope (the derivation-fallback rung: band behaviour, exact test decides).
func insertReachPolygon(mid uint64, wkt string) {
	db := database.DBConn
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon), outer_bound = VALUES(outer_bound), "+
		"inner_bound = NULL, status = VALUES(status)", mid, wkt, wkt)
}

// setBounds overrides the sandwich columns (adversarially, in these tests).
func setBounds(mid uint64, outerWkt string, innerWkt *string) {
	db := database.DBConn
	if innerWkt == nil {
		db.Exec("UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = NULL "+
			"WHERE msgid = ?", outerWkt, mid)
	} else {
		db.Exec("UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), "+
			"inner_bound = ST_GeomFromText(?, 3857) WHERE msgid = ?", outerWkt, *innerWkt, mid)
	}
}

// degradeBounds collapses the outer bound to the completion POINT sentinel.
func degradeBounds(mid uint64) {
	db := database.DBConn
	db.Exec("UPDATE rippling_reach SET outer_bound = ST_SRID(POINT(lng, lat), 3857), inner_bound = NULL "+
		"WHERE msgid = ?", mid)
}

const coversViewerWkt = "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"
const missesViewerWkt = "POLYGON((5.0 51.4, 5.2 51.4, 5.2 51.6, 5.0 51.6, 5.0 51.4))"
const bigCoversViewerWkt = "POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))"
const farAwayWkt = "POLYGON((5 5, 5.1 5, 5.1 5.1, 5 5.1, 5 5))"

func TestNearbyReachFeedSandwichBounds(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("sandwich")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)

	// Polygon covers the viewer, but outer_bound is far away → the R-tree (driven from
	// outer_bound) must never surface it.
	cheapReject := CreateTestMessage(t, posterID, group, "OFFER: cheap reject (sandwich)", 51.5, -0.1)
	// Polygon does NOT cover the viewer, but inner_bound does → cheap-accepted without
	// consulting the polygon.
	cheapAccept := CreateTestMessage(t, posterID, group, "OFFER: cheap accept (sandwich)", 51.5, -0.1)
	// In the boundary band (inside outer, no inner): the exact polygon decides — covered.
	bandIn := CreateTestMessage(t, posterID, group, "OFFER: band in (sandwich)", 51.5, -0.1)
	// In the boundary band: the exact polygon decides — not covered.
	bandOut := CreateTestMessage(t, posterID, group, "OFFER: band out (sandwich)", 51.5, -0.1)
	// Envelope fallback rung (what insertReachPolygon writes by default): a row whose
	// derivation failed still reaches browse via its envelope + the exact test.
	envelope := CreateTestMessage(t, posterID, group, "OFFER: envelope fallback (sandwich)", 51.5, -0.1)
	// POINT sentinel (completion pruning): pruned by the R-tree itself, even though the
	// exact polygon covers the viewer and the spatial row is (adversarially) open.
	degraded := CreateTestMessage(t, posterID, group, "OFFER: degraded (sandwich)", 51.5, -0.1)

	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?, ?, ?, ?, ?)",
		cheapReject, cheapAccept, bandIn, bandOut, envelope, degraded)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?, ?, ?, ?)",
		cheapReject, cheapAccept, bandIn, bandOut, envelope, degraded)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	inner := coversViewerWkt
	insertReachPolygon(cheapReject, coversViewerWkt)
	setBounds(cheapReject, farAwayWkt, nil)

	insertReachPolygon(cheapAccept, missesViewerWkt)
	setBounds(cheapAccept, bigCoversViewerWkt, &inner)

	insertReachPolygon(bandIn, coversViewerWkt)
	setBounds(bandIn, bigCoversViewerWkt, nil)

	insertReachPolygon(bandOut, missesViewerWkt)
	setBounds(bandOut, bigCoversViewerWkt, nil)

	insertReachPolygon(envelope, coversViewerWkt) // envelope outer by default

	insertReachPolygon(degraded, coversViewerWkt)
	degradeBounds(degraded)

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
	assert.True(t, got[envelope], "an envelope-fallback row stays visible via the exact test")
	assert.False(t, got[degraded], "a POINT-degraded row is pruned by the outer_bound R-tree itself")
}

// The unlimited-distance nearby COUNT (the 60s badge poll's fast path) runs its own SQL —
// it must consult the sandwich exactly like the feed, or badge and feed drift.
func TestNearbyCountSandwichBounds(t *testing.T) {
	db := database.DBConn

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
		// The badge is allowed to be a few seconds behind a post arriving or changing
		// (see the browsecount package, whose own tests cover that). This test is about
		// which posts the counting SQL includes, so ask afresh each time rather than
		// measure the reuse.
		browsecount.Invalidate(viewerID)

		resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json2.Unmarshal(rsp(resp), &body)
		return body["count"].(float64)
	}

	// Delta-based so leftover reach fixtures from other tests can't skew it, and STAGED
	// so polygon-driven reads can't accidentally produce the same totals.
	before := countOf()

	insertReachPolygon(cheapReject, coversViewerWkt)
	setBounds(cheapReject, farAwayWkt, nil)
	assert.Equal(t, before, countOf(),
		"a viewer outside outer_bound must not be counted, even though the polygon covers them")

	inner := coversViewerWkt
	insertReachPolygon(cheapAccept, missesViewerWkt)
	setBounds(cheapAccept, bigCoversViewerWkt, &inner)
	assert.Equal(t, before+1, countOf(),
		"a viewer inside inner_bound is counted, even though the polygon does not cover them")
}

// ClipReachForRejectedGroup SHRINKS the polygon in place (ST_Difference). A stale inner
// bound could then cheap-accept viewers inside the just-clipped-out area, so the clip
// must NULL inner_bound in the same statement. (The outer bound is merely stale-loose —
// safe — and the next expander tick re-derives both.)
func TestClipReachForRejectedGroupNullsInnerBound(t *testing.T) {
	db := database.DBConn

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
	setBounds(mid, bigCoversViewerWkt, &inner)

	message.ClipReachForRejectedGroup(db, mid, group)

	// The polygon shrank (partial clip, row retained)...
	var polyType string
	db.Raw("SELECT ST_GeometryType(polygon) FROM rippling_reach WHERE msgid = ?", mid).Scan(&polyType)
	assert.NotEmpty(t, polyType, "reach row survives a partial clip")

	// ...and the inner bound was NULLed in the same statement.
	var innerNull int
	db.Raw("SELECT inner_bound IS NULL FROM rippling_reach WHERE msgid = ?", mid).Scan(&innerNull)
	assert.Equal(t, 1, innerNull, "a polygon shrink must clear the inner bound")

	// The outer bound is untouched (stale-loose is safe).
	var outerType string
	db.Raw("SELECT ST_GeometryType(outer_bound) FROM rippling_reach WHERE msgid = ?", mid).Scan(&outerType)
	assert.Equal(t, "POLYGON", outerType, "outer bound is left as-is on clip")
}
