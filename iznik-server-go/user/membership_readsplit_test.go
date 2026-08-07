package user

import (
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/freegle/iznik-server-go/database"
)

func init() {
	database.InitDatabase()
}

// TestMembershipsPinsModtoolsReadsToWriter guards against Discourse #10008
// post 2: a mod flips a member's ourPostingStatus (Can't Post -> Moderated)
// via PatchMemberships (a write), then ModTools re-fetches that member
// (GET /user/:id?modtools=true) to refresh the Approve-button gate on a
// pending message. That re-fetch went through GetMemberships(id), a plain
// SELECT that the DB read/write split can route to a replica which hasn't
// applied the write yet (Galera apply-lag) - so the Approve button stayed
// hidden until the replica caught up, exactly the "it corrected itself
// after a delay" symptom the reporter described. Replica lag can't be
// simulated on the single test DB (see test/insert_id_test.go), so this
// asserts the routing decision directly: dbForMemberships must pin the
// modtools read to the writer, and must NOT pin the plain read used by
// high-frequency "my groups" checks (volunteering, community events, "who
// am I") - pinning those to the writer would defeat the point of the
// read/write split.
func TestMembershipsPinsModtoolsReadsToWriter(t *testing.T) {
	const writeMarker = "gorm:db_resolver:write"

	db := database.DBConn
	require.NotNil(t, db)

	pinned := dbForMemberships(db, true)
	_, ok := pinned.Statement.Settings.Load(writeMarker)
	assert.True(t, ok, "the modtools re-fetch after a posting-status change must be pinned to the writer")

	unpinned := dbForMemberships(db, false)
	_, ok = unpinned.Statement.Settings.Load(writeMarker)
	assert.False(t, ok, "the plain 'my groups' read must not be pinned to the writer")
}
