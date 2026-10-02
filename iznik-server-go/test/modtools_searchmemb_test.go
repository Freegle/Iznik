package test

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The ModTools "find posts by member" search (GET /modtools/messages
// ?subaction=searchmemb) finds the matching members first and then their
// posts through the poster index, the way v1 did. The previous shape scanned
// the community's posts newest-first looking for one whose poster matched,
// which for a person with fewer posts than the page size - the usual case -
// had to read the whole community before it could stop, and on production's
// larger communities hit MAX_EXECUTION_TIME on every search and answered 500.
//
// The candidate query pins the memberships access path with FORCE INDEX on
// memberships_groupid_collection_emailfrequency, as the member search does,
// so these tests also fail loudly if that index is ever renamed.

func mtSearchMemberIDs(t *testing.T, token string, groupID uint64, term string) (int, []uint64) {
	t.Helper()
	u := fmt.Sprintf("/api/modtools/messages?collection=Approved&subaction=searchmemb&search=%s&jwt=%s",
		url.QueryEscape(term), token)
	if groupID > 0 {
		u += fmt.Sprintf("&groupid=%d", groupID)
	}
	resp, err := getApp().Test(httptest.NewRequest("GET", u, nil))
	require.NoError(t, err)

	var body map[string]interface{}
	_ = json.NewDecoder(resp.Body).Decode(&body)
	var ids []uint64
	if raw, ok := body["messages"].([]interface{}); ok {
		for _, id := range raw {
			ids = append(ids, uint64(id.(float64)))
		}
	}
	return resp.StatusCode, ids
}

func contains(ids []uint64, id uint64) bool {
	for _, v := range ids {
		if v == id {
			return true
		}
	}
	return false
}

func TestListMessagesMT_SearchMemberFindsPostsByNameAndEmail(t *testing.T) {
	prefix := uniquePrefix("srchmemb")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// CreateTestUser gives the poster fullname "Test User <prefix>_poster",
	// lastname "<prefix>_poster" and email "<prefix>_poster@test.com".
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: searchmemb sofa (EH1)", 55.9533, -3.1883)
	defer func() {
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	}()

	// A fragment of the name, scoped to the community.
	status, ids := mtSearchMemberIDs(t, modToken, groupID, prefix+"_poster")
	assert.Equal(t, 200, status)
	assert.True(t, contains(ids, msgID), "a name fragment should find the member's post; got %v", ids)

	// A fragment that only the email carries.
	status, ids = mtSearchMemberIDs(t, modToken, groupID, prefix+"_poster@test")
	assert.Equal(t, 200, status)
	assert.True(t, contains(ids, msgID), "an email fragment should find the member's post; got %v", ids)

	// Unscoped: every community the moderator covers.
	status, ids = mtSearchMemberIDs(t, modToken, 0, prefix+"_poster")
	assert.Equal(t, 200, status)
	assert.True(t, contains(ids, msgID), "the all-communities search should find the post too; got %v", ids)

	// The numeric branch is unchanged: a member id finds their posts directly.
	status, ids = mtSearchMemberIDs(t, modToken, groupID, fmt.Sprintf("%d", posterID))
	assert.Equal(t, 200, status)
	assert.True(t, contains(ids, msgID), "a member id should find the member's post; got %v", ids)

	// A term that matches nobody is an ordinary empty answer, not an error.
	status, ids = mtSearchMemberIDs(t, modToken, groupID, prefix+"_nobody_at_all")
	assert.Equal(t, 200, status)
	assert.Empty(t, ids)

	// The moderator themselves matches "<prefix>" too but has no posts, so a
	// term shared by several members returns only the posts that exist.
	status, ids = mtSearchMemberIDs(t, modToken, groupID, prefix)
	assert.Equal(t, 200, status)
	assert.Equal(t, []uint64{msgID}, ids)
}

// Candidates are the community's current members, as in v1. A poster who has
// left is not found by name any more; their posts remain reachable by member
// id, which is the branch moderators reach from a member's profile.
func TestListMessagesMT_SearchMemberIsScopedToCurrentMembers(t *testing.T) {
	prefix := uniquePrefix("srchmemb_left")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	posterID := CreateTestUser(t, prefix+"_gone", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: departed sofa (EH1)", 55.9533, -3.1883)
	defer func() {
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	}()

	status, ids := mtSearchMemberIDs(t, modToken, groupID, prefix+"_gone")
	assert.Equal(t, 200, status)
	require.True(t, contains(ids, msgID), "while a member, the post is found by name")

	db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", posterID, groupID)

	status, ids = mtSearchMemberIDs(t, modToken, groupID, prefix+"_gone")
	assert.Equal(t, 200, status)
	assert.False(t, contains(ids, msgID), "after leaving, a name search no longer lists the post")

	status, ids = mtSearchMemberIDs(t, modToken, groupID, fmt.Sprintf("%d", posterID))
	assert.Equal(t, 200, status)
	assert.True(t, contains(ids, msgID), "the post is still reachable by member id")
}

// A member search whose query fails is an error, not "Nothing found" - the
// same rule the queue listing follows (Discourse 10037). A cancelled context
// fails every query on the handle, which is the same class of failure as the
// execution-time abort the search is capped by.
func TestListMessagesMT_SearchMemberQueryErrorIsNotNothingFound(t *testing.T) {
	prefix := uniquePrefix("srchmemb_err")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status, _ := mtSearchMemberIDs(t, modToken, groupID, prefix)
	require.Equal(t, 200, status, "sanity: the search works before the handle is broken")

	good := database.DBConn
	cancelledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	database.DBConn = good.WithContext(cancelledCtx)
	defer func() { database.DBConn = good }()

	status, ids := mtSearchMemberIDs(t, modToken, groupID, prefix)
	assert.NotEqual(t, 200, status, "a failed member search must not be answered with a success")
	assert.Nil(t, ids, "a failed member search must not return a messages array")
}
