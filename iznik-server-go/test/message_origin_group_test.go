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

// MessageOriginGroup must return the group a message was first posted to (its arrival
// matches the message), so only that group's rejection notifies the poster, and a
// secondary (rippled-in) group's rejection stays silent (#6). It must NOT mis-attribute
// origin when the true origin row has been hard-deleted.
func TestMessageOriginGroup(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("origin")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a") // origin — CreateTestMessage sets arrival = NOW()
	group2 := CreateTestGroup(t, prefix+"b") // rippled in later

	mid := CreateTestMessage(t, userID, group1, "OFFER: origin test item", 51.5, -0.1)

	// Rippled into a second group an hour later.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0)", mid, group2)

	// Origin = the earliest-arriving group whose arrival matches the message.
	assert.Equal(t, group1, message.MessageOriginGroup(db, mid))

	// A plain-delete rejection SOFT-deletes the origin row (deleted=1); it still persists
	// and is still correctly identified as origin (so a later secondary reject stays silent).
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", mid, group1)
	assert.Equal(t, group1, message.MessageOriginGroup(db, mid), "soft-deleted origin still matched")

	// HARD-deleting the origin row (handleDeleteMessage/handleMove) leaves only the later
	// rippled-in group, which fails the arrival match → 0, so the caller notifies all
	// groups rather than mis-attributing origin to a secondary group.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", mid, group1)
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, mid), "hard-deleted origin → 0 (safe fallback)")

	// No group rows at all → 0.
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, 999999999), "no rows → 0")
}

// ClipReachForRejectedGroup must subtract a rejecting secondary group's area from a post's
// rippling reach, so the post stops being reply-eligible / visible there, while the origin
// area stays covered (#6).
func TestClipReachForRejectedGroup(t *testing.T) {
	db := database.DBConn

	// Self-sufficient: rippling_reach belongs to PR A (merges before #772). Create a
	// minimal stand-in so this test runs in isolation off master. rejected_groups records
	// the clip so the expander can re-apply it each tick (added by the additive migration).
	db.Exec("CREATE TABLE IF NOT EXISTS rippling_reach (msgid BIGINT UNSIGNED PRIMARY KEY, polygon GEOMETRY NOT NULL SRID 3857, rejected_groups JSON NULL)")
	db.Exec("ALTER TABLE rippling_reach ADD COLUMN rejected_groups JSON NULL")

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
	db.Exec("INSERT INTO rippling_reach (msgid, polygon, outer_bound) VALUES (?, ST_GeomFromText("+
		"'POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))', 3857), ST_Envelope(ST_GeomFromText("+
		"'POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))', 3857)))", mid)

	covers := func(lng, lat string) int {
		var v int
		db.Raw("SELECT IFNULL(ST_Contains(polygon, ST_SRID(POINT("+lng+", "+lat+"), 3857)), 0) "+
			"FROM rippling_reach WHERE msgid = ?", mid).Scan(&v)
		return v
	}

	assert.Equal(t, 1, covers("0.1", "51.5"), "reach initially covers the secondary group's area")
	assert.Equal(t, 1, covers("-0.1", "51.5"), "reach initially covers the origin area")

	// Seed the dedup state (plans/2026-08-23-rippling-reach-polygon-dedup.md):
	// a shared geom row for this exact polygon, and this row's hash pointed at
	// it - the state a real post reaches after the backfill. Skipped when the
	// migration has not run in this test DB (an older worktree schema), so the
	// test still covers the pre-dedup clip behaviour above either way.
	var geomTableExists int
	db.Raw("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rippling_reach_geom'").Scan(&geomTableExists)
	// A hard requirement, not a skip: setup-test-database.sh always runs the
	// Laravel migrations, so a missing table means a broken test environment,
	// and silently skipping would let the clip corruption guard rot unnoticed.
	require.Equal(t, 1, geomTableExists, "rippling_reach_geom must exist - run scripts/setup-test-database.sh")
	sharingMigrated := geomTableExists > 0
	var originalHash, originalWKB string
	var mid2 uint64
	if sharingMigrated {
		db.Exec("INSERT INTO rippling_reach_geom (hash, geom) "+
			"SELECT UNHEX(MD5(ST_AsBinary(polygon))), polygon FROM rippling_reach WHERE msgid = ? "+
			"ON DUPLICATE KEY UPDATE hash = hash", mid)
		db.Exec("UPDATE rippling_reach SET polygon_hash = UNHEX(MD5(ST_AsBinary(polygon))) WHERE msgid = ?", mid)
		db.Raw("SELECT HEX(polygon_hash) FROM rippling_reach WHERE msgid = ?", mid).Scan(&originalHash)
		db.Raw("SELECT HEX(ST_AsBinary(polygon)) FROM rippling_reach WHERE msgid = ?", mid).Scan(&originalWKB)
		require.NotEmpty(t, originalHash, "hash must be seeded before the clip")

		// A second post sharing the SAME hash (byte-identical polygon, up to 261
		// observed in production) - the clip on mid below must never touch it.
		mid2 = CreateTestMessage(t, userID, group1, "OFFER: clip reach test item 2 (shares hash)", 51.5, -0.1)
		db.Exec("INSERT INTO rippling_reach (msgid, polygon, outer_bound) VALUES (?, ST_GeomFromText("+
			"'POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))', 3857), ST_Envelope(ST_GeomFromText("+
			"'POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))', 3857)))", mid2)
		db.Exec("UPDATE rippling_reach SET polygon_hash = UNHEX(MD5(ST_AsBinary(polygon))) WHERE msgid = ?", mid2)
		var mid2Hash string
		db.Raw("SELECT HEX(polygon_hash) FROM rippling_reach WHERE msgid = ?", mid2).Scan(&mid2Hash)
		require.Equal(t, originalHash, mid2Hash, "the two posts must genuinely share one hash before the clip")
	}

	message.ClipReachForRejectedGroup(db, mid, group2)

	assert.Equal(t, 0, covers("0.1", "51.5"), "rejected secondary group's area is clipped out of the reach")
	assert.Equal(t, 1, covers("-0.1", "51.5"), "origin area is still covered after the clip")

	// The rejected group is persisted so the expander re-applies the clip on each tick
	// (otherwise advanceDue overwrites polygon from the cached schedule and undoes it).
	var recorded int
	db.Raw("SELECT JSON_CONTAINS(rejected_groups, CAST(? AS JSON)) FROM rippling_reach WHERE msgid = ?", group2, mid).Scan(&recorded)
	assert.Equal(t, 1, recorded, "rejected group id is recorded in rejected_groups for tick re-clipping")

	if sharingMigrated {
		// (a) The clip mutated the blob, so mid must be RE-POINTED to a new hash,
		// self-consistent with its own (now clipped) bytes - never left pointing
		// at the old hash and never left NULL.
		var newHash string
		db.Raw("SELECT HEX(polygon_hash) FROM rippling_reach WHERE msgid = ?", mid).Scan(&newHash)
		assert.NotEmpty(t, newHash, "polygon_hash must be re-pointed, not left NULL, after a successful clip")
		assert.NotEqual(t, originalHash, newHash, "the clip changed the bytes, so the hash must change too")
		var selfConsistent int
		db.Raw("SELECT (polygon_hash = UNHEX(MD5(ST_AsBinary(polygon)))) FROM rippling_reach WHERE msgid = ?", mid).Scan(&selfConsistent)
		assert.Equal(t, 1, selfConsistent, "polygon_hash must match MD5(WKB) of the row's own (clipped) polygon")

		// (b) The OLD shared geom row must be untouched: still the ORIGINAL,
		// unclipped bytes. This is the 261-post corruption guard - rewriting a
		// shared row in place would silently clip every other post sharing it.
		var oldGeomWKB string
		db.Raw("SELECT HEX(ST_AsBinary(geom)) FROM rippling_reach_geom WHERE hash = UNHEX(?)", originalHash).Scan(&oldGeomWKB)
		assert.Equal(t, originalWKB, oldGeomWKB, "the shared geom row for the ORIGINAL hash must still hold the ORIGINAL bytes")

		// (c) A NEW geom row exists for the clipped bytes, matching mid's new hash.
		var newGeomExists int
		db.Raw("SELECT COUNT(*) FROM rippling_reach_geom WHERE hash = UNHEX(?)", newHash).Scan(&newGeomExists)
		assert.Equal(t, 1, newGeomExists, "a new geom row must exist for the clipped bytes")

		// A second post sharing the original hash must be COMPLETELY untouched
		// by the first post's clip: same hash, same blob.
		var mid2HashAfter, mid2WKBAfter string
		db.Raw("SELECT HEX(polygon_hash) FROM rippling_reach WHERE msgid = ?", mid2).Scan(&mid2HashAfter)
		db.Raw("SELECT HEX(ST_AsBinary(polygon)) FROM rippling_reach WHERE msgid = ?", mid2).Scan(&mid2WKBAfter)
		assert.Equal(t, originalHash, mid2HashAfter, "a post sharing the clipped hash must keep pointing at the original hash")
		assert.Equal(t, originalWKB, mid2WKBAfter, "a post sharing the clipped hash must keep its own original bytes")
	}

	// Re-clipping is idempotent: a second rejection of the same group does not duplicate it.
	message.ClipReachForRejectedGroup(db, mid, group2)
	var n int
	db.Raw("SELECT JSON_LENGTH(rejected_groups) FROM rippling_reach WHERE msgid = ?", mid).Scan(&n)
	assert.Equal(t, 1, n, "the same rejected group is not appended twice")
}

// The clip must shrink polygon_cells alongside polygon
// (plans/2026-08-24-rippling-reach-raster-storage.md). The cell set is what the
// reply gate reads, so a clip that shrank only the polygon would leave the cells
// admitting people in the area the reach has just stopped covering - and a stale
// cell set is MORE permissive than the polygon it disagrees with, which is the
// dangerous direction.
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

	const reachWKT = "POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))"
	db.Exec("INSERT INTO rippling_reach (msgid, polygon, outer_bound) VALUES (?, "+
		"ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)))", mid, reachWKT, reachWKT)

	// Seed polygon_cells from the REAL rasteriser, the one place a polygon
	// becomes cells - a hand-built blob would only prove this test agrees with
	// itself about a format the writer has to agree with instead.
	seeded, err := spatial.RasterizeWKT(reachWKT)
	// A hard requirement, not a skip. The rasteriser IS available in every
	// environment that runs this suite, and skipping on its absence is how a
	// write path that never worked passes as green - the exact trap this
	// design already fell into once (see the plan's Stage 0 notes).
	require.NoError(t, err, "the rasteriser must answer - run scripts/setup-test-database.sh and check spatial-knn is up")
	require.NotEmpty(t, seeded, "the rasteriser must return a cell set to seed with")
	seedRes := db.Exec("UPDATE rippling_reach SET polygon_cells = ? WHERE msgid = ?", seeded, mid)
	require.NoError(t, seedRes.Error, "seeding polygon_cells must succeed")
	require.EqualValues(t, 1, seedRes.RowsAffected, "the seed must land on exactly this row")

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
	db.Exec("INSERT INTO rippling_reach (msgid, polygon, outer_bound) VALUES (?, "+
		"ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)))", mid, reachWKT, reachWKT)

	message.ClipReachForRejectedGroup(db, mid, group2)

	var raw []byte
	require.NoError(t, db.Raw("SELECT polygon_cells FROM rippling_reach WHERE msgid = ?", mid).Row().Scan(&raw))
	assert.Nil(t, raw, "a row with no cell set must still have none after a clip")

	// ...and the polygon clip itself still happened.
	var covers int
	db.Raw("SELECT IFNULL(ST_Contains(polygon, ST_SRID(POINT(0.1, 51.5), 3857)), 0) "+
		"FROM rippling_reach WHERE msgid = ?", mid).Scan(&covers)
	assert.Equal(t, 0, covers, "the polygon clip must run regardless of whether cells were present")
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
