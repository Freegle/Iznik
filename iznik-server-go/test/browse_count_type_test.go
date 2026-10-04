package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/browsecount"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func countFor(t *testing.T, token string, query string) float64 {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/count?jwt="+token+query, nil))
	require.Equal(t, 200, resp.StatusCode)
	var body map[string]interface{}
	json2.Unmarshal(rsp(resp), &body)
	return body["count"].(float64)
}

// The Browse page filters its feed to Offers or Wanteds client-side, so the unseen badge
// has to count only that type or it promises posts the filtered list does not show.
func TestMyGroupsCountHonoursTypeFilter(t *testing.T) {
	prefix := uniquePrefix("countmygroupstype")
	db := database.DBConn
	userID := CreateTestUser(t, prefix+"_u", "User")
	_, token := CreateTestSession(t, userID)
	posterID := CreateTestUser(t, prefix+"_p", "User")
	group := CreateTestGroup(t, prefix)
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", userID, group)
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings, '{}'), '$.browseView', 'mygroups') WHERE id = ?", userID)

	offer := CreateTestMessage(t, posterID, group, prefix+" offerpost", 55.9533, -3.1883)
	wanted := CreateTestMessage(t, posterID, group, prefix+" wantedpost", 55.9533, -3.1883)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", offer, wanted)
	db.Exec("UPDATE messages_spatial SET msgtype = 'Wanted' WHERE msgid = ?", wanted)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, group)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", offer, wanted)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", offer, wanted)

	for _, maxDistance := range []string{"", "&maxDistance=50"} {
		db.Exec("UPDATE users SET settings = JSON_SET(settings, '$.mylocation', JSON_OBJECT('lat', 55.9533, 'lng', -3.1883)) WHERE id = ?", userID)
		browsecount.Invalidate(userID)
		assert.Equal(t, float64(2), countFor(t, token, maxDistance), "no type counts both")
		assert.Equal(t, float64(1), countFor(t, token, maxDistance+"&type=Offer"), "type=Offer counts only the offer")
		assert.Equal(t, float64(1), countFor(t, token, maxDistance+"&type=Wanted"), "type=Wanted counts only the wanted")
		assert.Equal(t, float64(2), countFor(t, token, maxDistance+"&type=All"), "type=All counts both")
	}

	// The saved Browse filter applies with no query param, as the nav badge sends none.
	db.Exec("UPDATE users SET settings = JSON_SET(settings, '$.browseType', 'Wanted') WHERE id = ?", userID)
	browsecount.Invalidate(userID)
	assert.Equal(t, float64(1), countFor(t, token, ""), "settings.browseType is honoured server-side")
}

// Same for the nearby view, in both the unlimited fast COUNT and the distance-limited walk.
func TestNearbyCountHonoursTypeFilter(t *testing.T) {
	db := database.DBConn

	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_reach (
		msgid BIGINT UNSIGNED NOT NULL PRIMARY KEY,
		lat DOUBLE NOT NULL, lng DOUBLE NOT NULL,
		polygon_cells MEDIUMBLOB NULL,
		outer_bound GEOMETRY NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'expanding'
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("nearbycounttype")
	posterID := CreateTestUser(t, prefix+"_poster", "Poster")
	group := CreateTestGroup(t, prefix)
	offer := CreateTestMessage(t, posterID, group, "OFFER: type filter offer (nearbycounttype)", 51.5, -0.1)
	wanted := CreateTestMessage(t, posterID, group, "WANTED: type filter wanted (nearbycounttype)", 51.5, -0.1)
	db.Exec("UPDATE messages_spatial SET successful = 0 WHERE msgid IN (?, ?)", offer, wanted)
	db.Exec("UPDATE messages_spatial SET msgtype = 'Wanted' WHERE msgid = ?", wanted)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid IN (?, ?)", offer, wanted)

	viewerID, token := CreateFullTestUser(t, prefix+"_viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	for _, id := range []uint64{offer, wanted} {
		db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, -0.1, ?, "+
			"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))', 3857)), 'expanding') "+
			"ON DUPLICATE KEY UPDATE polygon_cells = VALUES(polygon_cells)", id,
			mustRasterize(t, "POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))"))
	}
	stubReachIndexFromDB(t, false)

	for _, maxDistance := range []string{"", "&maxDistance=10"} {
		browsecount.Invalidate(viewerID)
		all := countFor(t, token, maxDistance)
		offers := countFor(t, token, maxDistance+"&type=Offer")
		wanteds := countFor(t, token, maxDistance+"&type=Wanted")

		assert.GreaterOrEqual(t, all, float64(2), "both fixture posts are counted with no type")
		assert.GreaterOrEqual(t, offers, float64(1), "the offer is counted for type=Offer")
		assert.GreaterOrEqual(t, wanteds, float64(1), "the wanted is counted for type=Wanted")
		assert.Equal(t, all, offers+wanteds, "Offer and Wanted counts add up to the unfiltered count")
	}
}
