package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// POST /api/admin/ai-images/:id/suppress
//
// Admin/Support action that marks an AI image as terminally 'suppressed' — the
// "don't keep asking for an AI image for this item" control. Mirrors the
// volunteer-quorum suppress path (microvolunteering.checkAIImageSuppressQuorum),
// but applied directly by a moderator from the AI Images review page.
// ---------------------------------------------------------------------------

func TestAIImageSuppress_RequiresAuth(t *testing.T) {
	prefix := uniquePrefix("aisup_noauth")
	imgID := createTestAIImageWithStatus(t, "suppress-noauth-"+prefix, "freegletusd-supnoauth-"+prefix, "rejected")

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/suppress", imgID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestAIImageSuppress_RequiresAdminOrSupport(t *testing.T) {
	prefix := uniquePrefix("aisup_norm")
	uid := CreateTestUser(t, prefix, "User")
	_, tok := CreateTestSession(t, uid)
	imgID := createTestAIImageWithStatus(t, "suppress-norm-"+prefix, "freegletusd-supnorm-"+prefix, "rejected")

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/suppress?jwt="+tok, imgID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAIImageSuppress_NotFound(t *testing.T) {
	prefix := uniquePrefix("aisup_404")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	req := httptest.NewRequest("POST", "/api/admin/ai-images/999999999/suppress?jwt="+tok, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

// Suppress sets status='suppressed' (terminal), clears any pending review state, and
// deletes the review votes so the image leaves the regeneration queue.
func TestAIImageSuppress_SetsStatusSuppressedAndClearsQueue(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("aisup_set")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	imgID := createTestAIImageWithStatus(t, "suppress-set-"+prefix, "freegletusd-supset-"+prefix, "rejected")

	// Pre-populate pending state + a review vote — both should be cleared on suppress.
	db.Exec("UPDATE ai_images SET pending_externaluid = ?, regeneration_notes = ? WHERE id = ?",
		"freegletusd-pending-"+prefix, "some notes", imgID)
	voter := CreateTestUser(t, prefix+"_voter", "User")
	db.Exec("INSERT INTO microactions (actiontype, userid, aiimageid, result, version, score_negative) VALUES (?, ?, ?, 'Reject', 4, 0)",
		microvolunteering.ChallengeAIImageReview, voter, imgID)

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/suppress?jwt="+tok, imgID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Status should now be the terminal 'suppressed'.
	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", imgID).Scan(&status)
	assert.Equal(t, "suppressed", status, "Status should be 'suppressed' after admin suppress")

	// Pending review state should be cleared.
	var pendingUID *string
	db.Raw("SELECT pending_externaluid FROM ai_images WHERE id = ?", imgID).Scan(&pendingUID)
	assert.Nil(t, pendingUID, "pending_externaluid should be cleared after suppress")

	// Review votes should be deleted so the image leaves the queue.
	var voteCount int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE aiimageid = ? AND actiontype = ?",
		imgID, microvolunteering.ChallengeAIImageReview).Scan(&voteCount)
	assert.Equal(t, int64(0), voteCount, "Review votes should be deleted after suppress")
}

// A 'regenerating' image can also be suppressed (admin override) and must then be
// excluded from the review list.
func TestAIImageSuppress_RemovesFromReviewList(t *testing.T) {
	prefix := uniquePrefix("aisup_list")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	imgID := createTestAIImageWithStatus(t, "suppress-list-"+prefix, "freegletusd-suplist-"+prefix, "regenerating")

	reqS := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/suppress?jwt="+tok, imgID), nil)
	respS, _ := getApp().Test(reqS)
	assert.Equal(t, 200, respS.StatusCode)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/review?jwt="+tok, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	for _, item := range result {
		assert.NotEqual(t, imgID, uint64(item["id"].(float64)), "Suppressed image must not appear in review list")
	}
}
