package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/isochrone"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// FilterReachBlocked (Q2a, §6) hides posts whose rippling reach exists but does not yet
// cover the viewer, while leaving posts with no reach row (non-rippling / pre-engine)
// visible.
func TestFilterReachBlocked(t *testing.T) {
	db := database.DBConn

	// Self-sufficient: rippling_reach belongs to PR A (merges before browse). Minimal
	// stand-in so this runs in isolation off master.
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon GEOMETRY NOT NULL SRID 3857,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding',
		SPATIAL INDEX msgreach_poly (polygon)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	// Create real messages so the FK on rippling_reach.msgid (present once the PR A
	// migration runs) does not reject the test inserts.
	prefix := uniquePrefix("filterreach")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	mid1 := CreateTestMessage(t, posterID, groupID, "OFFER: reach-test-visible (filterreach)", 51.5, -0.1)
	mid2 := CreateTestMessage(t, posterID, groupID, "OFFER: reach-test-hidden (filterreach)", 53.0, 2.0)
	mid3 := CreateTestMessage(t, posterID, groupID, "OFFER: reach-test-no-reach (filterreach)", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", mid1, mid2)

	// Viewer at lng -0.1, lat 51.5.
	vlat, vlng := 51.5, -0.1

	// Reach covers the viewer → stays visible.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon) VALUES (?, 51.5, -0.1, "+
		"ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)) "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", mid1)
	// Reach far away, does not cover the viewer → hidden.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon) VALUES (?, 53.0, 2.0, "+
		"ST_GeomFromText('POLYGON((2.0 53.0,2.1 53.0,2.1 53.1,2.0 53.1,2.0 53.0))', 3857)) "+
		"ON DUPLICATE KEY UPDATE polygon = VALUES(polygon)", mid2)

	// mid3 has no reach row → non-rippling → stays visible.
	in := []message.MessageSummary{{ID: mid1}, {ID: mid2}, {ID: mid3}}
	out := isochrone.FilterReachBlocked(db, in, vlat, vlng)

	got := map[uint64]bool{}
	for _, m := range out {
		got[m.ID] = true
	}
	assert.True(t, got[mid1], "reach covering the viewer stays visible")
	assert.False(t, got[mid2], "reach not covering the viewer is hidden")
	assert.True(t, got[mid3], "post with no reach row stays visible")
}
