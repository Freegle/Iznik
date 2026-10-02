package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// addMessageGroup inserts a messages_groups row - the row rippling adds on each
// receiving group - and registers its cleanup.
func addMessageGroup(t *testing.T, msgID, groupID uint64, collection string, deleted int) {
	db := database.DBConn
	result := db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, deleted, arrival, msgtype) "+
		"VALUES (?, ?, ?, ?, NOW(), 'Offer')", msgID, groupID, collection, deleted)
	require.NoError(t, result.Error)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID)
	})
}

// searchGroupFinds reports whether a group-scoped search returns msgID. It looks
// through the whole result set rather than only the top hit: other tests' open
// messages may also be in the store.
func searchGroupFinds(store *embedding.Store, vec [embedding.EmbeddingDim]float32, groupID, msgID uint64) bool {
	for _, r := range store.Search(vec[:], 20, "", []uint64{groupID}, nil, 0, 0, 0, 0) {
		if r.Msgid == msgID {
			return true
		}
	}
	return false
}

// A post Approved on its origin group and rippled into a neighbouring group has a
// messages_groups row on each, but messages_spatial stores only the origin group.
// Load must read the groups from messages_groups so a moderator searching the
// receiving group finds the post (Discourse 9808/751).
func TestStoreLoadReadsEveryApprovedGroup(t *testing.T) {
	prefix := uniquePrefix("storegroupsload")
	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	otherGroup := CreateTestGroup(t, prefix+"_other")
	userID := CreateTestUser(t, prefix, "Member")

	vec := unitVec(4.1)
	msgID := createOpenTestMessageWithEmbedding(t, userID, originGroup, "Rippled "+prefix, 51.5, -0.1, vec)
	addMessageGroup(t, msgID, originGroup, "Approved", 0)
	addMessageGroup(t, msgID, rippledGroup, "Approved", 0)

	var store embedding.Store
	require.NoError(t, store.Load())

	assert.True(t, searchGroupFinds(&store, vec, originGroup, msgID),
		"post must be findable on its origin group")
	assert.True(t, searchGroupFinds(&store, vec, rippledGroup, msgID),
		"post must be findable on the group it rippled into, which messages_spatial does not name")
	assert.False(t, searchGroupFinds(&store, vec, otherGroup, msgID),
		"post must not leak into a group it was never Approved on")
}

// Only live, Approved copies count. A Pending copy is not yet on the group, and a
// deleted copy has been withdrawn from it.
func TestStoreLoadSkipsPendingAndDeletedGroups(t *testing.T) {
	prefix := uniquePrefix("storegroupsfilter")
	originGroup := CreateTestGroup(t, prefix+"_origin")
	pendingGroup := CreateTestGroup(t, prefix+"_pending")
	deletedGroup := CreateTestGroup(t, prefix+"_deleted")
	userID := CreateTestUser(t, prefix, "Member")

	vec := unitVec(6.4)
	msgID := createOpenTestMessageWithEmbedding(t, userID, originGroup, "Filtered "+prefix, 51.5, -0.1, vec)
	addMessageGroup(t, msgID, originGroup, "Approved", 0)
	addMessageGroup(t, msgID, pendingGroup, "Pending", 0)
	addMessageGroup(t, msgID, deletedGroup, "Approved", 1)

	var store embedding.Store
	require.NoError(t, store.Load())

	assert.True(t, searchGroupFinds(&store, vec, originGroup, msgID),
		"post must be findable on its origin group")
	assert.False(t, searchGroupFinds(&store, vec, pendingGroup, msgID),
		"a Pending copy is not yet on the group and must not be findable there")
	assert.False(t, searchGroupFinds(&store, vec, deletedGroup, msgID),
		"a deleted copy has been withdrawn from the group and must not be findable there")
}

// Rippling happens minutes after approval, while the post is already in the store.
// Refresh only fetches blobs for messages it does not already hold, so it must
// still re-read the groups for the ones it keeps, or the receiving group's
// moderators cannot find the post until apiv2 next restarts.
func TestStoreRefreshPicksUpNewlyRippledGroup(t *testing.T) {
	prefix := uniquePrefix("storegroupsrefresh")
	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	userID := CreateTestUser(t, prefix, "Member")

	vec := unitVec(7.2)
	msgID := createOpenTestMessageWithEmbedding(t, userID, originGroup, "Ripples later "+prefix, 51.5, -0.1, vec)
	addMessageGroup(t, msgID, originGroup, "Approved", 0)

	var store embedding.Store
	require.NoError(t, store.Load())
	require.True(t, searchGroupFinds(&store, vec, originGroup, msgID))
	require.False(t, searchGroupFinds(&store, vec, rippledGroup, msgID),
		"post has not rippled yet")

	// The expander ripples the post in: a second Approved messages_groups row,
	// with no change to the message or its embedding.
	addMessageGroup(t, msgID, rippledGroup, "Approved", 0)

	require.NoError(t, store.Refresh())
	assert.True(t, searchGroupFinds(&store, vec, rippledGroup, msgID),
		"Refresh must pick up a group the post rippled into after it was loaded")
	assert.True(t, searchGroupFinds(&store, vec, originGroup, msgID),
		"the origin group must survive the refresh")
}

// The mirror of the above: a moderator removing the rippled-in copy must take the
// post out of that group's search results without waiting for a restart.
func TestStoreRefreshDropsWithdrawnGroup(t *testing.T) {
	prefix := uniquePrefix("storegroupswithdraw")
	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	userID := CreateTestUser(t, prefix, "Member")

	vec := unitVec(2.6)
	msgID := createOpenTestMessageWithEmbedding(t, userID, originGroup, "Withdrawn "+prefix, 51.5, -0.1, vec)
	addMessageGroup(t, msgID, originGroup, "Approved", 0)
	addMessageGroup(t, msgID, rippledGroup, "Approved", 0)

	var store embedding.Store
	require.NoError(t, store.Load())
	require.True(t, searchGroupFinds(&store, vec, rippledGroup, msgID))

	db := database.DBConn
	result := db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", msgID, rippledGroup)
	require.NoError(t, result.Error)

	require.NoError(t, store.Refresh())
	assert.False(t, searchGroupFinds(&store, vec, rippledGroup, msgID),
		"Refresh must drop a group the post was withdrawn from")
	assert.True(t, searchGroupFinds(&store, vec, originGroup, msgID),
		"the origin group must survive the refresh")
}
