package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A rejecting community must stay recorded against a post that is still live. When the whole
// reach lies inside that community's area the clip leaves nothing, but the reach row, and the
// rejected_groups on it, stay so the expander does not start a second reach for the live post.
func TestClipReachForRejectedGroupKeepsRowWhenReachIsEmptied(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("clipempty")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	home := CreateTestGroup(t, prefix+"a")
	rejecting := CreateTestGroup(t, prefix+"b")
	mid := CreateTestMessage(t, userID, home, "OFFER: clip empty test item", 51.5, -0.1)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0, 1)", mid, rejecting)

	// The rejecting community's area is much bigger than the reach, which lies wholly inside it.
	db.Exec("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((0.0 51.4,0.3 51.4,0.3 51.6,0.0 51.6,0.0 51.4))', 3857) WHERE id = ?", rejecting)
	const reachWKT = "POLYGON((0.1 51.45,0.15 51.45,0.15 51.55,0.1 51.55,0.1 51.45))"
	seeded, err := spatial.RasterizeWKT(reachWKT)
	require.NoError(t, err, "the rasteriser must answer - check spatial-knn is up")
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status) VALUES (?, 51.5, 0.12, ?, "+
		"ST_Envelope(ST_GeomFromText(?, 3857)), 'expanding')", mid, seeded, reachWKT)

	message.ClipReachForRejectedGroup(db, mid, rejecting)

	var rows int64
	db.Raw("SELECT COUNT(*) FROM rippling_reach WHERE msgid = ?", mid).Scan(&rows)
	require.Equal(t, int64(1), rows, "the reach row stays while the post is live on its home community")

	var recorded int
	db.Raw("SELECT JSON_CONTAINS(rejected_groups, CAST(? AS JSON)) FROM rippling_reach WHERE msgid = ?", rejecting, mid).Scan(&recorded)
	assert.Equal(t, 1, recorded, "and the rejecting community is still recorded on it")
}
