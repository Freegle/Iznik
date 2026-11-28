package test

// A post leaves messages_spatial once it is withdrawn or expires, and a taken one once it
// ages out, so SearchByMsgID cannot find it. The ModTools Approved page still lists those
// posts and is where moderators search by id; these tests prove a moderator finds them
// there and nobody else does.

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// withdrawnPost makes a post on its own group and withdraws it the way the spatial
// index run leaves it: an outcome, and no messages_spatial row.
func withdrawnPost(t *testing.T, prefix string) (groupID uint64, posterID uint64, msgID uint64) {
	db := database.DBConn
	groupID = CreateTestGroup(t, prefix)
	posterID = CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	msgID = CreateTestMessage(t, posterID, groupID, "OFFER: Television "+prefix, 55.95, -3.19)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, ?)", msgID, utils.OUTCOME_WITHDRAWN)
	db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
	return
}

func TestSearchModeratedByMsgID_FindsAWithdrawnPostForItsModerator(t *testing.T) {
	db := database.DBConn
	groupID, _, msgID := withdrawnPost(t, uniquePrefix("searchModWithdrawn"))
	modID := CreateTestUser(t, uniquePrefix("searchModWithdrawn_mod"), utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)

	assert.Empty(t, message.SearchByMsgID(db, msgID, []uint64{groupID}),
		"the live index no longer holds the post")

	results := message.SearchModeratedByMsgID(db, modID, msgID, []uint64{groupID})
	if assert.Len(t, results, 1, "the moderator of its group finds the withdrawn post by its id") {
		assert.Equal(t, msgID, results[0].Msgid)
		assert.Equal(t, groupID, results[0].Groupid)
		assert.Equal(t, "Offer", results[0].Type)
		assert.Equal(t, "id", results[0].Matchedon.Type, "the match is on the id, not on words")
		assert.Equal(t, 0.0, results[0].Lat, "a post with no location stays at 0,0, not blurred as a real point")
		assert.Equal(t, 0.0, results[0].Lng)
	}

	assert.Len(t, message.SearchModeratedByMsgID(db, modID, msgID, nil), 1,
		"with no group filter it is still found on a group they moderate")
}

func TestSearchModeratedByMsgID_FindsNothingForAMember(t *testing.T) {
	db := database.DBConn
	groupID, posterID, msgID := withdrawnPost(t, uniquePrefix("searchModMember"))

	assert.Empty(t, message.SearchModeratedByMsgID(db, posterID, msgID, []uint64{groupID}),
		"a member is not a moderator, so search on the site still finds live posts only")
	assert.Empty(t, message.SearchModeratedByMsgID(db, 0, msgID, nil),
		"nor does somebody who is not logged in")
}

func TestSearchModeratedByMsgID_OnlyOnGroupsTheyModerate(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchModOther")
	groupID, _, msgID := withdrawnPost(t, prefix)
	otherGroupID := CreateTestGroup(t, prefix+"_other")
	otherModID := CreateTestUser(t, prefix+"_othermod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, otherModID, otherGroupID, utils.ROLE_MODERATOR)
	CreateTestMembership(t, otherModID, groupID, utils.ROLE_MEMBER)

	assert.Empty(t, message.SearchModeratedByMsgID(db, otherModID, msgID, nil),
		"moderating another group, and only belonging to this one, finds nothing")

	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	assert.Len(t, message.SearchModeratedByMsgID(db, adminID, msgID, nil), 1,
		"admin and support find it on any group")
	assert.Empty(t, message.SearchModeratedByMsgID(db, adminID, msgID, []uint64{otherGroupID}),
		"but a group filter still applies to them")
}

func TestSearchModeratedByMsgID_OwnersAndSupportToo(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchModOwner")
	groupID, _, msgID := withdrawnPost(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_OWNER)
	supportID := CreateTestUser(t, prefix+"_support", utils.SYSTEMROLE_SUPPORT)

	assert.Len(t, message.SearchModeratedByMsgID(db, ownerID, msgID, []uint64{groupID}), 1,
		"the owner of its group finds it")
	assert.Len(t, message.SearchModeratedByMsgID(db, supportID, msgID, nil), 1,
		"support find it on any group, like admin")
}

func TestSearchModeratedByMsgID_BlursThePostersLocation(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchModBlur")
	groupID, _, msgID := withdrawnPost(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	const rawLat, rawLng = 55.95, -3.19
	db.Exec("UPDATE messages SET lat = ?, lng = ? WHERE id = ?", rawLat, rawLng, msgID)

	results := message.SearchModeratedByMsgID(db, modID, msgID, nil)
	if assert.Len(t, results, 1) {
		wantLat, wantLng := roadblur.RoadBlur(rawLat, rawLng, utils.BLUR_USER)
		assert.InDelta(t, wantLat, results[0].Lat, 0.0001, "the id search returns the blurred latitude")
		assert.InDelta(t, wantLng, results[0].Lng, 0.0001, "the id search returns the blurred longitude")
		assert.False(t, results[0].Lat == rawLat && results[0].Lng == rawLng,
			"the id search must not return the poster's exact stored location")
	}
}

func TestSearchModeratedByMsgID_SkipsDeletedPosters(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchModGonePoster")
	_, posterID, msgID := withdrawnPost(t, prefix)
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)

	db.Exec("UPDATE users SET deleted = NOW() WHERE id = ?", posterID)
	assert.Empty(t, message.SearchModeratedByMsgID(db, adminID, msgID, nil),
		"the Approved listing does not show a deleted member's posts, so neither does the id search")
}

func TestSearchModeratedByMsgID_SkipsDeletedPosts(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("searchModDeleted")
	_, _, msgID := withdrawnPost(t, prefix)
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)

	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", msgID)
	assert.Empty(t, message.SearchModeratedByMsgID(db, adminID, msgID, nil), "a deleted post is not found")

	_, _, removedID := withdrawnPost(t, prefix+"_removed")
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ?", removedID)
	assert.Empty(t, message.SearchModeratedByMsgID(db, adminID, removedID, nil),
		"nor is one removed from its group")
}

// The endpoint ModTools calls: /messages/approved/<groupid>/<msgid> searches for the id.
func TestSearchEndpoint_ModeratorFindsAWithdrawnPostByID(t *testing.T) {
	prefix := uniquePrefix("searchModEndpoint")
	groupID, posterID, msgID := withdrawnPost(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	_, posterToken := CreateTestSession(t, posterID)

	found := func(token string) []message.SearchResult {
		u := fmt.Sprintf("/api/message/search/%d?messagetype=All&groupids=%d&jwt=%s", msgID, groupID, token)
		resp, err := getApp().Test(httptest.NewRequest("GET", u, nil), 60000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		var results []message.SearchResult
		json2.Unmarshal(rsp(resp), &results)
		var matches []message.SearchResult
		for _, r := range results {
			if r.Msgid == msgID {
				matches = append(matches, r)
			}
		}
		return matches
	}

	byMod := found(modToken)
	if assert.Len(t, byMod, 1, "the moderator's search for the id returns the withdrawn post") {
		assert.Equal(t, "id", byMod[0].Matchedon.Type)
	}
	// If the moderator check failed open, the fallback would hand the poster the post here.
	assert.Empty(t, found(posterToken), "a member's search does not return a withdrawn post")
}
