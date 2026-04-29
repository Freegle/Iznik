package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// Helpers shared by tests in this file
// ---------------------------------------------------------------------------

// createTestAIImageWithStatus creates an ai_image row with the given status and externaluid.
func createTestAIImageWithStatus(t *testing.T, name string, externaluid string, status string) uint64 {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, ?)", name, externaluid, status)
	var id uint64
	db.Raw("SELECT id FROM ai_images WHERE name = ? ORDER BY id DESC LIMIT 1", name).Scan(&id)
	assert.NotZero(t, id)
	t.Cleanup(func() {
		db.Exec("DELETE FROM microactions WHERE aiimageid = ?", id)
		db.Exec("DELETE FROM ai_images WHERE id = ?", id)
	})
	return id
}

// ---------------------------------------------------------------------------
// Test 1: Majority-Reject quorum sets ai_images.status = 'rejected'
// ---------------------------------------------------------------------------

func TestAIImageRegen_QuorumRejectSetsStatusRejected(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("airegen_quorum")

	imgID := createTestAIImageWithStatus(t, "test-reject-"+prefix, "freegletusd-reject-"+prefix, "active")

	// Verify initial status is 'active'.
	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", imgID).Scan(&status)
	assert.Equal(t, "active", status, "Initial status should be active")

	// Cast 5 Reject votes (majority Reject = quorum should trigger status update).
	for i := 0; i < 5; i++ {
		vPrefix := uniquePrefix(fmt.Sprintf("%s_v%d", prefix, i))
		uid := CreateTestUser(t, vPrefix, "User")
		_, tok := CreateTestSession(t, uid)
		body := fmt.Sprintf(`{"aiimageid":%d,"response":"Reject","containspeople":false}`, imgID)
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		assert.Equal(t, 200, resp.StatusCode)
	}

	// Verify status changed to 'rejected'.
	db.Raw("SELECT status FROM ai_images WHERE id = ?", imgID).Scan(&status)
	assert.Equal(t, "rejected", status, "Status should be 'rejected' after quorum Reject votes")
}

func TestAIImageRegen_QuorumApproveDoesNotSetRejected(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("airegen_approve")

	imgID := createTestAIImageWithStatus(t, "test-approve-"+prefix, "freegletusd-approve-"+prefix, "active")

	// Cast 5 Approve votes — should NOT trigger rejected status.
	for i := 0; i < 5; i++ {
		vPrefix := uniquePrefix(fmt.Sprintf("%s_v%d", prefix, i))
		uid := CreateTestUser(t, vPrefix, "User")
		_, tok := CreateTestSession(t, uid)
		body := fmt.Sprintf(`{"aiimageid":%d,"response":"Approve","containspeople":false}`, imgID)
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		assert.Equal(t, 200, resp.StatusCode)
	}

	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", imgID).Scan(&status)
	assert.Equal(t, "active", status, "Majority Approve should leave status as active")
}

func TestAIImageRegen_MixedVotesNoRejectQuorum(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("airegen_mixed")

	imgID := createTestAIImageWithStatus(t, "test-mixed-"+prefix, "freegletusd-mixed-"+prefix, "active")

	// 3 Reject + 2 Approve = Reject majority but exactly quorum → should reject.
	responses := []string{"Reject", "Reject", "Reject", "Approve", "Approve"}
	for i, resp := range responses {
		vPrefix := uniquePrefix(fmt.Sprintf("%s_v%d", prefix, i))
		uid := CreateTestUser(t, vPrefix, "User")
		_, tok := CreateTestSession(t, uid)
		body := fmt.Sprintf(`{"aiimageid":%d,"response":"%s","containspeople":false}`, imgID, resp)
		req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+tok, strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		r, _ := getApp().Test(req)
		assert.Equal(t, 200, r.StatusCode)
	}

	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", imgID).Scan(&status)
	assert.Equal(t, "rejected", status, "3/5 Reject votes should set status to rejected")
}

// ---------------------------------------------------------------------------
// Test 2: Admin GET /api/admin/ai-images/review returns rejected images
// ---------------------------------------------------------------------------

func TestAIImageRegen_AdminListReview_RequiresAuth(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/review", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestAIImageRegen_AdminListReview_RequiresAdminOrSupport(t *testing.T) {
	prefix := uniquePrefix("airegen_authnorm")
	uid := CreateTestUser(t, prefix, "User")
	_, tok := CreateTestSession(t, uid)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/review?jwt="+tok, nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAIImageRegen_AdminListReview_ReturnsList(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("airegen_list")

	// Create Support user.
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	// Create a rejected image.
	imgID := createTestAIImageWithStatus(t, "rejected-img-"+prefix, "freegletusd-rej-"+prefix, "rejected")

	// Add some votes with known users.
	voter1 := CreateTestUser(t, prefix+"_v1", "User")
	voter2 := CreateTestUser(t, prefix+"_v2", "User")
	db.Exec("INSERT INTO microactions (actiontype, userid, aiimageid, result, containspeople, version, score_negative) VALUES (?, ?, ?, 'Reject', 0, 4, 0)",
		microvolunteering.ChallengeAIImageReview, voter1, imgID)
	db.Exec("INSERT INTO microactions (actiontype, userid, aiimageid, result, containspeople, version, score_negative) VALUES (?, ?, ?, 'Reject', 0, 4, 0)",
		microvolunteering.ChallengeAIImageReview, voter2, imgID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM microactions WHERE aiimageid = ?", imgID)
	})

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/review?jwt="+tok, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// Find our test image.
	var found map[string]interface{}
	for _, item := range result {
		if uint64(item["id"].(float64)) == imgID {
			found = item
			break
		}
	}
	assert.NotNil(t, found, "Rejected image should appear in review list")
	assert.Equal(t, "rejected", found["status"])
	assert.NotNil(t, found["votes"], "votes field should be present")
	votes := found["votes"].([]interface{})
	assert.GreaterOrEqual(t, len(votes), 2, "Should have at least 2 votes")

	// Votes should include voter names.
	var hasName bool
	for _, v := range votes {
		vote := v.(map[string]interface{})
		if vote["displayname"] != nil && vote["displayname"].(string) != "" {
			hasName = true
		}
	}
	assert.True(t, hasName, "Votes should include voter displaynames")
}

func TestAIImageRegen_AdminListReview_ActiveNotIncluded(t *testing.T) {
	prefix := uniquePrefix("airegen_active")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	// Create an active image (should NOT appear).
	imgID := createTestAIImageWithStatus(t, "active-img-"+prefix, "freegletusd-act-"+prefix, "active")

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/review?jwt="+tok, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result []map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	for _, item := range result {
		assert.NotEqual(t, imgID, uint64(item["id"].(float64)), "Active image should not appear in review list")
	}
}

// ---------------------------------------------------------------------------
// Test 3: POST /api/admin/ai-images/:id/regenerate
// ---------------------------------------------------------------------------

func TestAIImageRegen_Regenerate_RequiresAdminOrSupport(t *testing.T) {
	prefix := uniquePrefix("airegen_regauth")
	uid := CreateTestUser(t, prefix, "User")
	_, tok := CreateTestSession(t, uid)
	imgID := createTestAIImageWithStatus(t, "regen-test-"+prefix, "freegletusd-regen-"+prefix, "rejected")

	body := fmt.Sprintf(`{"notes":"Too blurry"}`)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/regenerate?jwt="+tok, imgID), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAIImageRegen_Regenerate_SavesNotes(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("airegen_regnotes")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	imgID := createTestAIImageWithStatus(t, "regen-notes-"+prefix, "freegletusd-regnotes-"+prefix, "rejected")

	body := `{"notes":"The image shows a person, not the item"}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/regenerate?jwt="+tok, imgID), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Notes should be saved.
	var notes string
	db.Raw("SELECT COALESCE(regeneration_notes, '') FROM ai_images WHERE id = ?", imgID).Scan(&notes)
	assert.Equal(t, "The image shows a person, not the item", notes)

	// Response should include a preview URL.
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotEmpty(t, result["preview_url"], "preview_url should be returned")
	previewURL := result["preview_url"].(string)
	assert.Contains(t, previewURL, "pollinations.ai", "preview_url should point to Pollinations.ai")
}

// ---------------------------------------------------------------------------
// Test 4: POST /api/admin/ai-images/:id/accept
// ---------------------------------------------------------------------------

func TestAIImageRegen_Accept_RequiresAdminOrSupport(t *testing.T) {
	prefix := uniquePrefix("airegen_accauth")
	uid := CreateTestUser(t, prefix, "User")
	_, tok := CreateTestSession(t, uid)
	imgID := createTestAIImageWithStatus(t, "accept-auth-"+prefix, "freegletusd-accauth-"+prefix, "rejected")

	body := `{"pending_externaluid":"freegletusd-new-abc"}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/accept?jwt="+tok, imgID), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAIImageRegen_Accept_UpdatesExternaluidAndResetStatus(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("airegen_accept")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	oldUID := "freegletusd-old-" + prefix
	newUID := "freegletusd-new-" + prefix

	imgID := createTestAIImageWithStatus(t, "accept-img-"+prefix, oldUID, "rejected")

	// Pre-populate pending_externaluid (as if regenerate was called).
	db.Exec("UPDATE ai_images SET pending_externaluid = ? WHERE id = ?", newUID, imgID)

	// Create some microactions (votes) for this image — should be deleted on accept.
	voter := CreateTestUser(t, prefix+"_voter", "User")
	db.Exec("INSERT INTO microactions (actiontype, userid, aiimageid, result, version, score_negative) VALUES (?, ?, ?, 'Reject', 4, 0)",
		microvolunteering.ChallengeAIImageReview, voter, imgID)

	// Create a message attachment pointing to the old externaluid.
	var msgID uint64
	db.Exec("INSERT INTO messages (fromuser, subject, textbody, message, type, arrival) VALUES (?, 'Test', 'Test body', 'Test body', 'Offer', NOW())", supportID)
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&msgID)
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods) VALUES (?, ?, ?)",
		msgID, oldUID, `{"ai":true}`)
	var attachID uint64
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&attachID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attachID)
		db.Exec("DELETE FROM messages WHERE id = ?", msgID)
	})

	body := `{"pending_externaluid":"` + newUID + `"}`
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/admin/ai-images/%d/accept?jwt="+tok, imgID), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// ai_images.externaluid should now be the new UID.
	var externalUID string
	db.Raw("SELECT externaluid FROM ai_images WHERE id = ?", imgID).Scan(&externalUID)
	assert.Equal(t, newUID, externalUID, "externaluid should be updated to the new UID")

	// Status should be reset to 'active'.
	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", imgID).Scan(&status)
	assert.Equal(t, "active", status, "Status should be reset to active after accept")

	// pending_externaluid should be cleared.
	var pendingUID *string
	db.Raw("SELECT pending_externaluid FROM ai_images WHERE id = ?", imgID).Scan(&pendingUID)
	assert.Nil(t, pendingUID, "pending_externaluid should be cleared after accept")

	// Old microactions votes should be deleted.
	var voteCount int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE aiimageid = ? AND actiontype = ?",
		imgID, microvolunteering.ChallengeAIImageReview).Scan(&voteCount)
	assert.Equal(t, int64(0), voteCount, "Old votes should be deleted after accepting new image")

	// messages_attachments pointing to old UID should be updated.
	var updatedUID string
	db.Raw("SELECT externaluid FROM messages_attachments WHERE id = ?", attachID).Scan(&updatedUID)
	assert.Equal(t, newUID, updatedUID, "Attachment externaluid should be updated to new UID")
}

// ---------------------------------------------------------------------------
// Test 5: GET /api/admin/ai-images/count
// ---------------------------------------------------------------------------

func TestAIImageCount_RequiresAuth(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/count", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestAIImageCount_RequiresAdminOrSupport(t *testing.T) {
	prefix := uniquePrefix("aicount_auth")
	uid := CreateTestUser(t, prefix, "User")
	_, tok := CreateTestSession(t, uid)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/count?jwt="+tok, nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAIImageCount_ReturnsCount(t *testing.T) {
	prefix := uniquePrefix("aicount_val")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, tok := CreateTestSession(t, supportID)

	// Get baseline count before creating test images.
	resp0, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/count?jwt="+tok, nil))
	assert.Equal(t, 200, resp0.StatusCode)
	var baseline map[string]interface{}
	json2.Unmarshal(rsp(resp0), &baseline)
	baseCount := int64(baseline["count"].(float64))

	// Create one rejected and one regenerating image — both should be counted.
	createTestAIImageWithStatus(t, "count-rej-"+prefix, "freegletusd-count-rej-"+prefix, "rejected")
	createTestAIImageWithStatus(t, "count-regen-"+prefix, "freegletusd-count-regen-"+prefix, "regenerating")
	// Active image should NOT be counted.
	createTestAIImageWithStatus(t, "count-active-"+prefix, "freegletusd-count-active-"+prefix, "active")

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/admin/ai-images/count?jwt="+tok, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	count := int64(result["count"].(float64))
	assert.Equal(t, baseCount+2, count, "Count should include rejected and regenerating images, not active")
}
