package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
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

	// Self-sufficient: messages_reach belongs to PR A (merges before #772). Create a
	// minimal stand-in so this test runs in isolation off master.
	db.Exec("CREATE TABLE IF NOT EXISTS messages_reach (msgid BIGINT UNSIGNED PRIMARY KEY, polygon GEOMETRY NOT NULL SRID 3857)")

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
	db.Exec("INSERT INTO messages_reach (msgid, polygon) VALUES (?, ST_GeomFromText("+
		"'POLYGON((-0.15 51.45,0.15 51.45,0.15 51.55,-0.15 51.55,-0.15 51.45))', 3857))", mid)

	covers := func(lng, lat string) int {
		var v int
		db.Raw("SELECT IFNULL(ST_Contains(polygon, ST_SRID(POINT("+lng+", "+lat+"), 3857)), 0) "+
			"FROM messages_reach WHERE msgid = ?", mid).Scan(&v)
		return v
	}

	assert.Equal(t, 1, covers("0.1", "51.5"), "reach initially covers the secondary group's area")
	assert.Equal(t, 1, covers("-0.1", "51.5"), "reach initially covers the origin area")

	message.ClipReachForRejectedGroup(db, mid, group2)

	assert.Equal(t, 0, covers("0.1", "51.5"), "rejected secondary group's area is clipped out of the reach")
	assert.Equal(t, 1, covers("-0.1", "51.5"), "origin area is still covered after the clip")
}

// RecordRippleEvent upserts a per-day counter (§15/§16 instrumentation), used here for the
// secondary-group rejection event.
func TestRecordRippleEvent(t *testing.T) {
	db := database.DBConn
	db.Exec("DELETE FROM ripple_event_metrics WHERE event = 'test_evt'")
	defer db.Exec("DELETE FROM ripple_event_metrics WHERE event = 'test_evt'")

	message.RecordRippleEvent(db, "test_evt")
	message.RecordRippleEvent(db, "test_evt")

	var count int
	db.Raw("SELECT count FROM ripple_event_metrics WHERE day = CURDATE() AND event = 'test_evt'").Scan(&count)
	assert.Equal(t, 2, count, "per-event counter increments in place via upsert")
}
