package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/browsecount"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// "Show posts from" can name one community. The Browse page narrows its list to that
// community's posts in the browser, so the badge has to count only those too.
func TestCountHonoursCommunityFilter(t *testing.T) {
	prefix := uniquePrefix("countgroup")
	db := database.DBConn
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	g1 := CreateTestGroup(t, prefix+"_one")
	g2 := CreateTestGroup(t, prefix+"_two")
	other := CreateTestGroup(t, prefix+"_other")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?), (?, ?)", userID, g1, userID, g2)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.browseView', 'mygroups', "+
		"'$.mylocation', JSON_OBJECT('lat', 55.9533, 'lng', -3.1883)) WHERE id = ?", userID)

	m1 := CreateTestMessage(t, posterID, g1, prefix+" one", 55.9533, -3.1883)
	m2 := CreateTestMessage(t, posterID, g2, prefix+" two", 55.9533, -3.1883)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", m1, m2)
	defer db.Exec("DELETE FROM memberships WHERE userid = ?", userID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", m1, m2)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", m1, m2)

	for _, maxDistance := range []string{"", "&maxDistance=50"} {
		browsecount.Invalidate(userID)
		assert.Equal(t, float64(2), countFor(t, token, maxDistance), "no community counts both")
		assert.Equal(t, float64(1), countFor(t, token, fmt.Sprintf("%s&groupid=%d", maxDistance, g1)), "only the first community")
		assert.Equal(t, float64(1), countFor(t, token, fmt.Sprintf("%s&groupid=%d", maxDistance, g2)), "only the second community")
		assert.Equal(t, float64(2), countFor(t, token, maxDistance+"&groupid=0"), "groupid=0 means no community")
	}

	// The saved choice applies when no parameter is sent, as for the nav badge; an explicit
	// groupid=0 still overrides it.
	db.Exec("UPDATE users SET settings = JSON_SET(settings, '$.browseGroup', ?) WHERE id = ?", g2, userID)
	browsecount.Invalidate(userID)
	assert.Equal(t, float64(1), countFor(t, token, ""), "settings.browseGroup is honoured server-side")
	assert.Equal(t, float64(2), countFor(t, token, "&groupid=0"), "explicit groupid=0 beats the saved choice")

	// A community the member has left no longer narrows the feed on the client, so the
	// badge ignores it too.
	db.Exec("UPDATE users SET settings = JSON_SET(settings, '$.browseGroup', ?) WHERE id = ?", other, userID)
	browsecount.Invalidate(userID)
	assert.Equal(t, float64(2), countFor(t, token, ""), "a saved community the member is not in is ignored")
}

// Same for the nearby view.
func TestNearbyCountHonoursCommunityFilter(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbycountgroup")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	g1 := CreateTestGroup(t, prefix+"_one")
	g2 := CreateTestGroup(t, prefix+"_two")
	m1 := CreateTestMessage(t, posterID, g1, "OFFER: community one (nearbycountgroup)", 51.5, -0.1)
	m2 := CreateTestMessage(t, posterID, g2, "OFFER: community two (nearbycountgroup)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", m1, m2)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", m1, m2)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?), (?, ?)", viewerID, g1, viewerID, g2)
	defer db.Exec("DELETE FROM memberships WHERE userid = ?", viewerID)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	for _, id := range []uint64{m1, m2} {
		db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
			"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
			"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", id,
			mustRasterize(t, "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"))
	}
	stubReachIndexFromDB(t, false)

	for _, maxDistance := range []string{"", "&maxDistance=10"} {
		browsecount.Invalidate(viewerID)
		all := countFor(t, token, maxDistance)
		one := countFor(t, token, fmt.Sprintf("%s&groupid=%d", maxDistance, g1))
		two := countFor(t, token, fmt.Sprintf("%s&groupid=%d", maxDistance, g2))

		assert.Equal(t, float64(1), one, "only the first community's post")
		assert.Equal(t, float64(1), two, "only the second community's post")
		assert.GreaterOrEqual(t, all, float64(2), "both posts with no community")
	}
}
