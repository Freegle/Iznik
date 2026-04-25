package test

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// TestPatchMessage_RemoveAIAttachment_OwnerRecordsMicroaction verifies that when a message
// owner removes an AI-generated attachment via PATCH /api/message, a Reject microaction
// is recorded for the corresponding ai_images entry.
func TestPatchMessage_RemoveAIAttachment_OwnerRecordsMicroaction(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ai_att_owner")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, groupID, "Test AI Attachment Owner", 51.5, -1.0)

	externalUID := "freegletusd-test-ai-owner-" + prefix
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count) VALUES (?, ?, 1)",
		"test-ai-owner-"+prefix, externalUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE externaluid = ? LIMIT 1", externalUID).Scan(&aiImageID)
	assert.NotZero(t, aiImageID, "ai_images record should be created")
	t.Cleanup(func() { db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID) })

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, 1)",
		msgID, externalUID, `{"ai":true}`)
	var attachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attachID)
	assert.NotZero(t, attachID, "attachment record should be created")

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attachID)
		db.Exec("DELETE FROM microactions WHERE userid = ? AND aiimageid = ?", ownerID, aiImageID)
	})

	// PATCH with empty attachments — removes the AI attachment.
	body := map[string]interface{}{
		"id":          msgID,
		"attachments": []uint64{},
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify microaction was recorded as Reject for AIImageReview.
	var actionType, result string
	var recordedAiImageID uint64
	db.Raw("SELECT actiontype, result, aiimageid FROM microactions WHERE userid = ? AND aiimageid = ? LIMIT 1",
		ownerID, aiImageID).Row().Scan(&actionType, &result, &recordedAiImageID)
	assert.Equal(t, microvolunteering.ChallengeAIImageReview, actionType)
	assert.Equal(t, "Reject", result)
	assert.Equal(t, aiImageID, recordedAiImageID)
}

// TestPatchMessage_RemoveAIAttachment_ModRecordsMicroaction verifies that a moderator
// removing an AI-generated attachment also records a Reject microaction.
func TestPatchMessage_RemoveAIAttachment_ModRecordsMicroaction(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ai_att_mod")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msgID := CreateTestMessage(t, posterID, groupID, "Test AI Attachment Mod", 51.5, -1.0)

	externalUID := "freegletusd-test-ai-mod-" + prefix
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count) VALUES (?, ?, 1)",
		"test-ai-mod-"+prefix, externalUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE externaluid = ? LIMIT 1", externalUID).Scan(&aiImageID)
	assert.NotZero(t, aiImageID, "ai_images record should be created")
	t.Cleanup(func() { db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID) })

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, 1)",
		msgID, externalUID, `{"ai":true}`)
	var attachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attachID)
	assert.NotZero(t, attachID, "attachment record should be created")

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attachID)
		db.Exec("DELETE FROM microactions WHERE userid = ? AND aiimageid = ?", modID, aiImageID)
	})

	body := map[string]interface{}{
		"id":          msgID,
		"attachments": []uint64{},
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var actionType, result string
	var recordedAiImageID uint64
	db.Raw("SELECT actiontype, result, aiimageid FROM microactions WHERE userid = ? AND aiimageid = ? LIMIT 1",
		modID, aiImageID).Row().Scan(&actionType, &result, &recordedAiImageID)
	assert.Equal(t, microvolunteering.ChallengeAIImageReview, actionType)
	assert.Equal(t, "Reject", result)
	assert.Equal(t, aiImageID, recordedAiImageID)
}

// TestPatchMessage_RemoveNonAIAttachment_NoMicroaction verifies that removing a regular
// (non-AI) attachment does NOT create a microaction.
func TestPatchMessage_RemoveNonAIAttachment_NoMicroaction(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("non_ai_att")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, groupID, "Test Non-AI Attachment", 51.5, -1.0)
	attachID := CreateTestAttachment(t, msgID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attachID)
	})

	body := map[string]interface{}{
		"id":          msgID,
		"attachments": []uint64{},
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// No microaction should be created for a non-AI attachment deletion.
	var count int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE userid = ? AND actiontype = ?",
		ownerID, microvolunteering.ChallengeAIImageReview).Scan(&count)
	assert.Equal(t, int64(0), count, "No microaction should be recorded for non-AI attachment deletion")
}

// TestPatchMessage_RemoveAIAttachmentSubset_OnlyDeletedRecorded verifies that when a
// message has both AI and non-AI attachments and only the AI one is removed, only
// one microaction is recorded.
func TestPatchMessage_RemoveAIAttachmentSubset_OnlyDeletedRecorded(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("ai_att_subset")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, groupID, "Test AI Subset", 51.5, -1.0)

	// Create a non-AI attachment that will be kept.
	keptAttachID := CreateTestAttachment(t, msgID)

	// Create an AI attachment that will be removed.
	externalUID := "freegletusd-test-ai-subset-" + prefix
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count) VALUES (?, ?, 1)",
		"test-ai-subset-"+prefix, externalUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE externaluid = ? LIMIT 1", externalUID).Scan(&aiImageID)
	assert.NotZero(t, aiImageID)
	t.Cleanup(func() { db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID) })

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, ?, 0)",
		msgID, externalUID, `{"ai":true}`)
	var removedAttachID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND externaluid = ? LIMIT 1", msgID, externalUID).Scan(&removedAttachID)
	assert.NotZero(t, removedAttachID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id IN (?, ?)", keptAttachID, removedAttachID)
		db.Exec("DELETE FROM microactions WHERE userid = ? AND aiimageid = ?", ownerID, aiImageID)
	})

	// PATCH keeping only the non-AI attachment — removes the AI attachment.
	body := map[string]interface{}{
		"id":          msgID,
		"attachments": []uint64{keptAttachID},
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// One microaction recorded for the removed AI attachment.
	var count int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE userid = ? AND aiimageid = ? AND actiontype = ?",
		ownerID, aiImageID, microvolunteering.ChallengeAIImageReview).Scan(&count)
	assert.Equal(t, int64(1), count)

	// Kept attachment should still exist.
	var keptCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE id = ?", keptAttachID).Scan(&keptCount)
	assert.Equal(t, int64(1), keptCount, "Non-AI attachment should not be deleted")
}
