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

	userID := CreateTestUser(t, prefix, "User")
	msgID := CreateTestMessage(t, userID, "OFFER: Deckchair "+prefix, 53.0, -2.0)

	results := message.SearchByMsgID(db, msgID, nil)

	if assert.Len(t, results, 1, "a search for the id should find that one post") {
		// Msgid is what the API sends as "id"; ID is not selected by this query and is not serialised.
		assert.Equal(t, msgID, results[0].Msgid)
		assert.Equal(t, "id", results[0].Matchedon.Type, "the match is on the id, not on words")
		assert.Equal(t, strconv.FormatUint(msgID, 10), results[0].Matchedon.Word)
	}
}

func TestSearchByMsgID_FindsNothingForAnIDThatIsNotThere(t *testing.T) {
	db := database.DBConn
	assert.Empty(t, message.SearchByMsgID(db, 999999999999, nil))
}
