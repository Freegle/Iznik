package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// MessageOriginGroup must return the group a message was posted to rather than rippled
// into, so only that group's rejection notifies the poster and a secondary (rippled-in)
// group's rejection stays silent (#6). It must NOT mis-attribute origin when the true
// origin row has been hard-deleted.
func TestMessageOriginGroup(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("origin")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a") // origin — CreateTestMessage sets arrival = NOW()
	group2 := CreateTestGroup(t, prefix+"b") // rippled in later

	mid := CreateTestMessage(t, userID, group1, "OFFER: origin test item", 51.5, -0.1)

	// Rippled into a second group an hour later. rippled_in = 1 is what ExpandService
	// writes, and is what tells the two rows apart.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0, 1)", mid, group2)

	// Origin = the group the post was not rippled into.
	assert.Equal(t, group1, message.MessageOriginGroup(db, mid))

	// A plain-delete rejection SOFT-deletes the origin row (deleted=1); it still persists
	// and is still correctly identified as origin (so a later secondary reject stays silent).
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", mid, group1)
	assert.Equal(t, group1, message.MessageOriginGroup(db, mid), "soft-deleted origin still matched")

	// HARD-deleting the origin row (handleDeleteMessage/handleMove) leaves only rippled-in
	// rows → 0, so the caller notifies all groups rather than mis-attributing origin to a
	// secondary group.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", mid, group1)
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, mid), "hard-deleted origin → 0 (safe fallback)")

	// No group rows at all → 0.
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, 999999999), "no rows → 0")
}

// ClipReachForRejectedGroup must subtract a rejecting secondary group's area from a post's
// rippling reach grid, so the post stops being reply-eligible / visible there, while the
// origin area stays covered (#6).
func TestClipReachForRejectedGroup(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("clipreach")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a") // origin (west)
	group2 := CreateTestGroup(t, prefix+"b") // secondary group that rejects (east)
	mid := CreateTestMessage(t, userID, group1, "OFFER: clip reach test item", 51.5, -0.1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0)", mid, group2)

	// group2's area (DPA/CGA = polyindex) lies to the EAST of the origin.
	db.Exec("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((0.05 51.45,0.15 51.45,0.15 51.55,0.05 51.55,0.05 51.45))', 3857) WHERE id = ?", group2)

	// Reach covers BOTH the western origin area and the eastern group2 area.
	const clipReachWKT = "POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))"
	seeded, err := spatial.RasterizeWKT(clipReachWKT)
	require.NoError(t, err, "the rasteriser must answer - check spatial-knn is up")
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText(?, 3857)), 'expanding')", mid, seeded, clipReachWKT)

	covers := func(lng, lat float64) bool {
		var raw []byte
		if err := db.Raw("SELECT polygon_cells FROM rippling_reach WHERE msgid = ?", mid).Row().Scan(&raw); err != nil {
			t.Fatalf("read polygon_cells: %v", err)
		}
		require.NotNil(t, raw)
		cs, derr := rippling.DecodeCellSet(raw)
		require.NoError(t, derr)
		return cs.Contains(lng, lat)
	}

	require.True(t, covers(0.1, 51.5), "reach initially covers the secondary group's area")
	require.True(t, covers(-0.1, 51.5), "reach initially covers the origin area")

	message.ClipReachForRejectedGroup(db, mid, group2)

	assert.False(t, covers(0.1, 51.5), "rejected secondary group's area is clipped out of the reach")
	assert.True(t, covers(-0.1, 51.5), "origin area is still covered after the clip")

	// The rejected group is persisted so the expander re-applies the clip on each tick
	// (otherwise advanceDue overwrites the grid from the cached schedule and undoes it).
	var recorded int
	db.Raw("SELECT JSON_CONTAINS(rejected_groups, CAST(? AS JSON)) FROM rippling_reach WHERE msgid = ?", group2, mid).Scan(&recorded)
	assert.Equal(t, 1, recorded, "rejected group id is recorded in rejected_groups for tick re-clipping")

	// Re-clipping is idempotent: a second rejection of the same group does not duplicate it.
	message.ClipReachForRejectedGroup(db, mid, group2)
	var n int
	db.Raw("SELECT JSON_LENGTH(rejected_groups) FROM rippling_reach WHERE msgid = ?", mid).Scan(&n)
	assert.Equal(t, 1, n, "the same rejected group is not appended twice")
}

// The clip must shrink polygon_cells
// (plans/2026-08-24-rippling-reach-raster-storage.md). The cell set is what the
// reply gate reads, so a failed clip must leave it untouched rather than stale
// or invented - a stale grid is MORE permissive than the reach it disagrees
// with, which is the dangerous direction.
func TestClipReachForRejectedGroupClipsTheCellSet(t *testing.T) {
	db := database.DBConn

	var hasCells int
	db.Raw("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() " +
		"AND table_name = 'rippling_reach' AND column_name = 'polygon_cells'").Scan(&hasCells)
	require.Equal(t, 1, hasCells,
		"rippling_reach.polygon_cells must exist - run scripts/setup-test-database.sh")

	prefix := uniquePrefix("clipcells")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a") // origin (west)
	group2 := CreateTestGroup(t, prefix+"b") // secondary group that rejects (east)
	mid := CreateTestMessage(t, userID, group1, "OFFER: clip cells test item", 51.5, -0.1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0)", mid, group2)

	// group2's area is the EASTERN half of the reach below.
	db.Exec("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((0.0 51.45,0.15 51.45,0.15 51.55,0.0 51.55,0.0 51.45))', 3857) WHERE id = ?", group2)

	// Seed polygon_cells from the REAL rasteriser, the one place a polygon
	// becomes cells - a hand-built blob would only prove this test agrees with
	// itself about a format the writer has to agree with instead. A hard
	// requirement, not a skip: the rasteriser IS available in every
	// environment that runs this suite, and skipping on its absence is how a
	// write path that never worked passes as green.
	const reachWKT = "POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))"
	seeded, err := spatial.RasterizeWKT(reachWKT)
	require.NoError(t, err, "the rasteriser must answer - run scripts/setup-test-database.sh and check spatial-knn is up")
	require.NotEmpty(t, seeded, "the rasteriser must return a cell set to seed with")
	seedRes := db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText(?, 3857)), 'expanding')", mid, seeded, reachWKT)
	require.NoError(t, seedRes.Error, "seeding polygon_cells must succeed")

	cellsCover := func(lng, lat float64) bool {
		var raw []byte
		if err := db.Raw("SELECT polygon_cells FROM rippling_reach WHERE msgid = ?", mid).Row().Scan(&raw); err != nil {
			t.Fatalf("read polygon_cells: %v", err)
		}
		require.NotNil(t, raw, "polygon_cells must not be NULL")
		cs, derr := rippling.DecodeCellSet(raw)
		require.NoError(t, derr, "stored polygon_cells must decode (seeded %d bytes %x, read %d bytes %x)",
			len(seeded), seeded[:minInt(16, len(seeded))], len(raw), raw[:minInt(16, len(raw))])
		return cs.Contains(lng, lat)
	}

	require.True(t, cellsCover(0.1, 51.5), "the seeded cell set covers the eastern area")
	require.True(t, cellsCover(-0.1, 51.5), "the seeded cell set covers the western origin area")

	message.ClipReachForRejectedGroup(db, mid, group2)

	assert.False(t, cellsCover(0.1, 51.5),
		"the rejected group's eastern area must be cleared from the cell set, not just the polygon")
	assert.True(t, cellsCover(-0.1, 51.5),
		"the western origin area must survive in the cell set")
}

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

// A row with NO cell set yet (the state before the backfill reaches it) must
// come out of a clip with polygon_cells still NULL - never a partial or
// invented grid. NULL is what tells every reader to use the polygon.
func TestClipReachForRejectedGroupLeavesAbsentCellsNull(t *testing.T) {
	db := database.DBConn

	var hasCells int
	db.Raw("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() " +
		"AND table_name = 'rippling_reach' AND column_name = 'polygon_cells'").Scan(&hasCells)
	require.Equal(t, 1, hasCells, "rippling_reach.polygon_cells must exist")

	prefix := uniquePrefix("clipnocells")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a")
	group2 := CreateTestGroup(t, prefix+"b")
	mid := CreateTestMessage(t, userID, group1, "OFFER: clip without cells", 51.5, -0.1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0)", mid, group2)
	db.Exec("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((0.0 51.45,0.15 51.45,0.15 51.55,0.0 51.55,0.0 51.45))', 3857) WHERE id = ?", group2)

	const reachWKT = "POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))"
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_Envelope(ST_GeomFromText(?, 3857)), 'expanding')", mid, reachWKT)

	message.ClipReachForRejectedGroup(db, mid, group2)

	var raw []byte
	require.NoError(t, db.Raw("SELECT polygon_cells FROM rippling_reach WHERE msgid = ?", mid).Row().Scan(&raw))
	assert.Nil(t, raw, "a row with no cell set must still have none after a clip - never an invented grid")

	// ...and the rejection is still recorded, so the next advance re-applies
	// the clip once the row has a grid again.
	var recorded int
	db.Raw("SELECT JSON_CONTAINS(rejected_groups, CAST(? AS JSON)) FROM rippling_reach WHERE msgid = ?", group2, mid).Scan(&recorded)
	assert.Equal(t, 1, recorded, "the rejection is recorded even when there was nothing to clip yet")
}

// RecordRippleEvent upserts a per-day counter (§15/§16 instrumentation), used here for the
// secondary-group rejection event.
func TestRecordRippleEvent(t *testing.T) {
	db := database.DBConn
	db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'test_evt'")
	defer db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'test_evt'")

	message.RecordRippleEvent(db, "test_evt")
	message.RecordRippleEvent(db, "test_evt")

	var count int
	db.Raw("SELECT count FROM rippling_event_metrics WHERE day = CURDATE() AND event = 'test_evt'").Scan(&count)
	assert.Equal(t, 2, count, "per-event counter increments in place via upsert")
}
