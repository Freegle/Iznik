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

// The DEGRADED reach path (plans/2026-08-24-rippling-reach-raster-storage.md):
// when the spatial index cannot answer, the feed and the badge narrow by the
// small indexed outer_bound - a stored SUPERSET of the reach - and refine each
// candidate by probing its stored cell grid. outer_bound's sentinel ladder
// still applies: real bound > envelope (safe-loose) > POINT (completed:
// pruned by the narrow itself).
//
// Some fixtures here are ADVERSARIAL: an outer_bound deliberately
// contradicting the grid, which verified writer-derived bounds never do - it
// is the only way to observe from the outside which shape the query trusted.

// insertReachCells seeds a reach row whose grid comes from the real
// rasteriser and whose outer_bound defaults to the shape's envelope.
func insertReachCells(t *testing.T, mid uint64, wkt string) {
	db := database.DBConn
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText(?, 3857)), 'expanding') "+
		"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells), outer_bound = VALUES(outer_bound), "+
		"inner_bound = NULL, status = VALUES(status)", mid, mustRasterize(t, wkt), wkt)
}

// setOuterBound overrides the outer bound (adversarially, in these tests).
func setOuterBound(mid uint64, outerWkt string) {
	db := database.DBConn
	db.Exec("UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = NULL "+
		"WHERE msgid = ?", outerWkt, mid)
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

func TestNearbyReachFeedDegradedPath(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("sandwich")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)

	// Grid covers the viewer, but outer_bound is far away: the narrow (driven
	// from outer_bound) must never surface it. Adversarial - a writer-derived
	// outer is always a superset - but it is what proves the narrow ran.
	narrowReject := CreateTestMessage(t, posterID, group, "OFFER: narrow reject (sandwich)", 51.5, -0.1)
	// Outer covers the viewer but the grid does NOT: the probe must refine
	// the superset away.
	probedOut := CreateTestMessage(t, posterID, group, "OFFER: probed out (sandwich)", 51.5, -0.1)
	// Outer covers and the grid covers: shown.
	shown := CreateTestMessage(t, posterID, group, "OFFER: shown (sandwich)", 51.5, -0.1)
	// Envelope fallback rung (what insertReachCells writes by default).
	envelope := CreateTestMessage(t, posterID, group, "OFFER: envelope fallback (sandwich)", 51.5, -0.1)
	// POINT sentinel (completion pruning): pruned by the narrow itself, even
	// though the grid covers the viewer and the spatial row is open.
	degraded := CreateTestMessage(t, posterID, group, "OFFER: degraded (sandwich)", 51.5, -0.1)

	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?, ?, ?, ?)",
		narrowReject, probedOut, shown, envelope, degraded)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?, ?, ?, ?)",
		narrowReject, probedOut, shown, envelope, degraded)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	insertReachCells(t, narrowReject, coversViewerWkt)
	setOuterBound(narrowReject, farAwayWkt)

	insertReachCells(t, probedOut, missesViewerWkt)
	setOuterBound(probedOut, bigCoversViewerWkt)

	insertReachCells(t, shown, coversViewerWkt)
	setOuterBound(shown, bigCoversViewerWkt)

	insertReachCells(t, envelope, coversViewerWkt) // envelope outer by default

	insertReachCells(t, degraded, coversViewerWkt)
	degradeBounds(degraded)

	// The spatial index is DOWN: this is the degraded path's test. (The
	// rasterise calls above happened first, against the real service.)
	t.Setenv("SPATIAL_KNN_URL", "http://127.0.0.1:1")

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/isochrone/message?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var msgs []message.MessageSummary
	json2.Unmarshal(rsp(resp), &msgs)
	got := map[uint64]bool{}
	for _, m := range msgs {
		got[m.ID] = true
	}

	assert.False(t, got[narrowReject], "a viewer outside outer_bound is narrowed away without probing the grid")
	assert.False(t, got[probedOut], "the outer-bound superset is refined by the grid probe, never trusted alone")
	assert.True(t, got[shown], "outer covers and the grid covers: shown")
	assert.True(t, got[envelope], "an envelope-fallback row stays visible via the probe")
	assert.False(t, got[degraded], "a POINT-degraded row is pruned by the outer_bound narrow itself")
}

// The unlimited-distance nearby COUNT (the 60s badge poll's fast path) runs
// its own SQL - it must degrade exactly like the feed, or badge and feed
// drift.
func TestNearbyCountDegradedPath(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("sandwichcnt")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)

	narrowReject := CreateTestMessage(t, posterID, group, "OFFER: count narrow reject (sandwichcnt)", 51.5, -0.1)
	counted := CreateTestMessage(t, posterID, group, "OFFER: count probed in (sandwichcnt)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", narrowReject, counted)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", narrowReject, counted)

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

	// Rasterise the fixtures first, then take the spatial index down.
	narrowCells := mustRasterize(t, coversViewerWkt)
	countedCells := mustRasterize(t, coversViewerWkt)
	t.Setenv("SPATIAL_KNN_URL", "http://127.0.0.1:1")

	// Delta-based so leftover reach fixtures from other tests can't skew it.
	before := countOf()

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_GeomFromText(?, 3857), 'expanding')", narrowReject, narrowCells, farAwayWkt)
	assert.Equal(t, before, countOf(),
		"a viewer outside outer_bound must not be counted, even though the grid covers them")

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_GeomFromText(?, 3857), 'expanding')", counted, countedCells, bigCoversViewerWkt)
	assert.Equal(t, before+1, countOf(),
		"a viewer the outer bound and the grid both cover is counted on the degraded path")
}

// The clip SHRINKS the stored grid in place. A stale inner bound could then
// cheap-accept viewers inside the just-clipped-out area, so the clip must
// NULL inner_bound alongside the grid write. (The outer bound is merely
// stale-loose - safe - and the next expander tick re-derives both.)
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

	insertReachCells(t, mid, coversViewerWkt)
	db.Exec("UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), "+
		"inner_bound = ST_GeomFromText('POLYGON((-0.18 51.42, -0.02 51.42, -0.02 51.58, -0.18 51.58, -0.18 51.42))', 3857) "+
		"WHERE msgid = ?", bigCoversViewerWkt, mid)

	message.ClipReachForRejectedGroup(db, mid, group)

	// The grid shrank (partial clip, row retained)...
	var cells []byte
	db.Raw("SELECT polygon_cells FROM rippling_reach WHERE msgid = ?", mid).Row().Scan(&cells)
	assert.NotEmpty(t, cells, "reach row survives a partial clip with its grid")

	// ...and the inner bound was NULLed alongside it.
	var innerNull int
	db.Raw("SELECT inner_bound IS NULL FROM rippling_reach WHERE msgid = ?", mid).Scan(&innerNull)
	assert.Equal(t, 1, innerNull, "a reach shrink must clear the inner bound")

	// The outer bound is untouched (stale-loose is safe).
	var outerType string
	db.Raw("SELECT ST_GeometryType(outer_bound) FROM rippling_reach WHERE msgid = ?", mid).Scan(&outerType)
	assert.Equal(t, "POLYGON", outerType, "outer bound is left as-is on clip")
}
