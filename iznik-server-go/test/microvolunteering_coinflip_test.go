package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// TestMicrovolunteering_CoinFlipZeroFallsBackToMessage exercises the rand==0
// branch in GetChallenge where the AI image review is tried first and returns
// nil, so control falls through to the approved-message review. Before the
// CoinFlip shim this was only hit probabilistically; that made Go per-job
// Coveralls status flip between -0.01% and +0% on otherwise identical runs.
func TestMicrovolunteering_CoinFlipZeroFallsBackToMessage(t *testing.T) {
	db := database.DBConn

	orig := microvolunteering.CoinFlip
	microvolunteering.CoinFlip = func() int { return 0 }
	t.Cleanup(func() { microvolunteering.CoinFlip = orig })

	prefix := uniquePrefix("mv_cf0")
	groupID := CreateTestGroup(t, prefix)
	// Leave microvolunteeringoptions NULL — the SQL filter uses
	// `(microvolunteeringoptions IS NULL OR JSON_EXTRACT(...) = 1)` and the
	// JSON boolean `true` does NOT compare-equal to integer 1 in MySQL, so
	// setting `{"approvedmessages":true}` would filter the row out.
	db.Exec("UPDATE `groups` SET microvolunteering = 1 WHERE id = ?", groupID)

	reviewerID := CreateTestUser(t, prefix+"_rev", "User")
	CreateTestMembership(t, reviewerID, groupID, "Member")
	_, token := CreateTestSession(t, reviewerID)
	blockInviteChallenge(t, reviewerID)

	senderID := CreateTestUser(t, prefix+"_snd", "User")
	msgID := CreateTestMessage(t, senderID, groupID, "coinflip zero "+prefix, 55.9533, -3.1883)

	// Neutralise any AI images left in the shared test DB by other tests so
	// getAIImageReviewChallenge returns nil for this reviewer — that forces
	// the fallback to getApprovedMessageChallenge to execute.
	db.Exec(`
		INSERT INTO microactions (actiontype, userid, aiimageid, version, timestamp, result)
		SELECT 'AIImageReview', ?, id, 4, NOW(), 'Approve'
		FROM ai_images
		WHERE externaluid IS NOT NULL AND externaluid != ''
	`, reviewerID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, microvolunteering.ChallengeCheckMessage, result.Type,
		"CoinFlip=0 with no AI images must return the fallback message challenge")
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

// TestMicrovolunteering_CoinFlipOneFallsBackToAIImage exercises the rand==1
// branch where the approved-message review is tried first, returns nil, and
// control falls through to the AI image review at microvolunteering.go:189-191.
func TestMicrovolunteering_CoinFlipOneFallsBackToAIImage(t *testing.T) {
	db := database.DBConn

	orig := microvolunteering.CoinFlip
	microvolunteering.CoinFlip = func() int { return 1 }
	t.Cleanup(func() { microvolunteering.CoinFlip = orig })

	prefix := uniquePrefix("mv_cf1")
	groupID := CreateTestGroup(t, prefix)
	// Intentionally leave microvolunteering disabled on the group so
	// getApprovedMessageChallenge returns nil (the SQL filter requires
	// microvolunteering = 1).
	reviewerID := CreateTestUser(t, prefix+"_rev", "User")
	CreateTestMembership(t, reviewerID, groupID, "Member")
	_, token := CreateTestSession(t, reviewerID)
	blockInviteChallenge(t, reviewerID)

	imgID := createTestAIImage(t, "coinflip-one-"+prefix, 77)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, microvolunteering.ChallengeAIImageReview, result.Type,
		"CoinFlip=1 with no eligible messages must return the fallback AI image challenge")
	if result.AIImage != nil {
		assert.Equal(t, imgID, result.AIImage.ID)
	}

	t.Cleanup(func() {
		db.Exec("DELETE FROM microactions WHERE userid = ?", reviewerID)
	})
}
