package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// A volunteer must not be offered a post that has been withdrawn from their own
// community.
//
// Support referral SR-DYS36: a volunteer was shown "OFFER: Titanic model" and got a 403
// on voting. The post had rippled to seven communities and been withdrawn from six of
// them, including both of his. The picker looked only at messages.deleted, so the
// withdrawn rows in his communities still matched and it offered him the post. The
// check on the way back in requires a live row in a community he belongs to, so it
// refused him. Two filters that disagree, and the volunteer sees a failure for doing
// exactly what he was asked to do.
func TestMicrovolunteering_DoesNotOfferAPostWithdrawnFromTheVolunteersGroup(t *testing.T) {
	db := database.DBConn

	orig := microvolunteering.CoinFlip
	microvolunteering.CoinFlip = func() int { return 0 }
	t.Cleanup(func() { microvolunteering.CoinFlip = orig })

	prefix := uniquePrefix("mv_wd")
	groupID := CreateTestGroup(t, prefix)
	db.Exec("UPDATE `groups` SET microvolunteering = 1 WHERE id = ?", groupID)

	reviewerID := CreateTestUser(t, prefix+"_rev", "User")
	CreateTestMembership(t, reviewerID, groupID, "Member")
	_, token := CreateTestSession(t, reviewerID)
	blockInviteChallenge(t, reviewerID)

	senderID := CreateTestUser(t, prefix+"_snd", "User")
	msgID := CreateTestMessage(t, senderID, groupID, "withdrawn "+prefix, 55.9533, -3.1883)

	// As in the referral: the post itself is alive, but its copy in this volunteer's
	// community has been withdrawn.
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", msgID, groupID)

	// Same neutralising of stray AI images as the coin-flip test, so the approved
	// message path is the one under test.
	db.Exec(`
		INSERT INTO microactions (actiontype, userid, aiimageid, version, timestamp, result)
		SELECT 'AIImageReview', ?, id, 4, NOW(), 'Approve'
		FROM ai_images
		WHERE externaluid IS NOT NULL AND externaluid != ''
	`, reviewerID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=CheckMessage", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &result)

	if result.Msgid != nil {
		assert.NotEqual(t, msgID, *result.Msgid,
			"a post withdrawn from the volunteer's own community must not be offered: "+
				"voting on it is refused, so offering it only produces a 403")
	}

	t.Cleanup(func() {
		db.Exec("DELETE FROM microactions WHERE userid = ?", reviewerID)
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	})
}

// The other side of the same filter: a post still live in the volunteer's community is
// offered as before. Without this, narrowing the picker to live rows could quietly stop
// offering anything at all, and the suite would still be green.
func TestMicrovolunteering_StillOffersAPostLiveInTheVolunteersGroup(t *testing.T) {
	db := database.DBConn

	orig := microvolunteering.CoinFlip
	microvolunteering.CoinFlip = func() int { return 0 }
	t.Cleanup(func() { microvolunteering.CoinFlip = orig })

	prefix := uniquePrefix("mv_live")
	groupID := CreateTestGroup(t, prefix)
	db.Exec("UPDATE `groups` SET microvolunteering = 1 WHERE id = ?", groupID)

	reviewerID := CreateTestUser(t, prefix+"_rev", "User")
	CreateTestMembership(t, reviewerID, groupID, "Member")
	_, token := CreateTestSession(t, reviewerID)
	blockInviteChallenge(t, reviewerID)

	senderID := CreateTestUser(t, prefix+"_snd", "User")
	msgID := CreateTestMessage(t, senderID, groupID, "live "+prefix, 55.9533, -3.1883)

	db.Exec(`
		INSERT INTO microactions (actiontype, userid, aiimageid, version, timestamp, result)
		SELECT 'AIImageReview', ?, id, 4, NOW(), 'Approve'
		FROM ai_images
		WHERE externaluid IS NOT NULL AND externaluid != ''
	`, reviewerID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=CheckMessage", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &result)

	assert.Equal(t, microvolunteering.ChallengeCheckMessage, result.Type,
		"a live post in the volunteer's own community is still reviewable")
	if result.Msgid != nil {
		assert.Equal(t, msgID, *result.Msgid)
	}

	t.Cleanup(func() {
		db.Exec("DELETE FROM microactions WHERE userid = ?", reviewerID)
		db.Exec("DELETE FROM messages_spatial WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	})
}
