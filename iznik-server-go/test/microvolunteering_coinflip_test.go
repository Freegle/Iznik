package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// TestMicrovolunteering_CoinFlipOneFallsBackToAIImage exercises the rand==1
// branch where the approved-message review is tried first, returns nil, and
// control falls through to the AI image review at microvolunteering.go:189-191.
func TestMicrovolunteering_CoinFlipOneFallsBackToAIImage(t *testing.T) {
	db := database.DBConn

	orig := microvolunteering.CoinFlip
	microvolunteering.CoinFlip = func() int { return 1 }
	t.Cleanup(func() { microvolunteering.CoinFlip = orig })

	prefix := uniquePrefix("mv_cf1")
	// Intentionally leave microvolunteering disabled on the group so
	// getApprovedMessageChallenge returns nil (the SQL filter requires
	// microvolunteering = 1).
	reviewerID := CreateTestUser(t, prefix+"_rev", "User")
	_, token := CreateTestSession(t, reviewerID)
	blockInviteChallenge(t, reviewerID)

	imgID := createTestAIImage(t, "coinflip-one-"+prefix, 77)

	// Scope to the two challenge types this test exercises — EEELabel now
	// runs ahead of AIImageReview in the default ordering and would shadow
	// the coin-flip path.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=CheckMessage,AIImageReview", nil))
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
