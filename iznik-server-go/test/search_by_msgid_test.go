package test

// SearchByMsgID is how a search for a bare number finds the post with that id. It
// survived the retirement of the keyword index, which rewrote the rest of search.go,
// and it had no test of its own: nothing proved that it returns the post, that it
// labels the match as an id match, or that a group filter is applied to it.

import (
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

func TestSearchByMsgID_FindsThePostAndLabelsItAnIDMatch(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchByID")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, "OFFER: Deckchair "+prefix, 53.0, -2.0)

	results := message.SearchByMsgID(db, msgID, nil)

	if assert.Len(t, results, 1, "a search for the id should find that one post") {
		// Msgid is what the API sends as "id"; ID is not selected by this query and is not serialised.
		assert.Equal(t, msgID, results[0].Msgid)
		assert.Equal(t, "id", results[0].Matchedon.Type, "the match is on the id, not on words")
		assert.Equal(t, strconv.FormatUint(msgID, 10), results[0].Matchedon.Word)
	}
}

func TestSearchByMsgID_HonoursAGroupFilter(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchByIDGroup")

	groupID := CreateTestGroup(t, prefix)
	otherGroupID := CreateTestGroup(t, prefix+"_other")
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, "OFFER: Watering can "+prefix, 53.0, -2.0)

	onItsOwnGroup := message.SearchByMsgID(db, msgID, []uint64{groupID})
	assert.Len(t, onItsOwnGroup, 1, "the post is on this group, so the filter keeps it")

	onAnotherGroup := message.SearchByMsgID(db, msgID, []uint64{otherGroupID})
	assert.Empty(t, onAnotherGroup, "the post is not on that group, so the filter drops it")
}

func TestSearchByMsgID_IgnoresAMembershipThatIsNotApproved(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchByIDPending")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, "OFFER: Stepladder "+prefix, 53.0, -2.0)

	// The post is waiting for a moderator rather than approved on that group.
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", msgID)
	t.Cleanup(func() {
		db.Exec("UPDATE messages_groups SET collection = 'Approved' WHERE msgid = ?", msgID)
	})

	assert.Empty(t, message.SearchByMsgID(db, msgID, []uint64{groupID}),
		"a filtered search should not return a post that is not approved on the group")

	assert.Len(t, message.SearchByMsgID(db, msgID, nil), 1,
		"without a group filter the post is still found by its id")
}

func TestSearchByMsgID_FindsNothingForAnIDThatIsNotThere(t *testing.T) {
	db := database.DBConn
	assert.Empty(t, message.SearchByMsgID(db, 999999999999, nil))
}
