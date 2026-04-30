package test

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// attachmentInResponse is a minimal struct for parsing the attachments array
// from a GET /api/message/:id response.
type attachmentInResponse struct {
	ID          uint64 `json:"id"`
	Path        string `json:"path"`
	Paththumb   string `json:"paththumb"`
	Externaluid string `json:"externaluid"`
	Ouruid      string `json:"ouruid"`
}

type messageWithAttachments struct {
	ID          uint64                 `json:"id"`
	Attachments []attachmentInResponse `json:"attachments"`
}

// TestGetMessage_RegularAttachmentReturned verifies that a plain (non-AI)
// attachment is included in the GET /api/message/:id response.
// Regression guard: the LEFT JOIN on ai_images added in PR #286 must not
// silently swallow SQL errors and return an empty attachments array.
func TestGetMessage_RegularAttachmentReturned(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("photos_regular")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")

	msgID := CreateTestMessage(t, userID, groupID, "Test Offer "+prefix, 55.9533, -3.1883)

	// Insert a plain attachment (no externaluid — old-style image stored by ID).
	db.Exec("INSERT INTO messages_attachments (msgid, `primary`) VALUES (?, 1)", msgID)
	var attID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attID)
	require.NotZero(t, attID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attID)
	})

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d", msgID), nil))
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var msg messageWithAttachments
	require.NoError(t, json.Unmarshal(body, &msg))

	assert.NotEmpty(t, msg.Attachments, "attachments must not be empty — SQL error in LEFT JOIN would silently wipe this")
	assert.Equal(t, attID, msg.Attachments[0].ID)
	assert.NotEmpty(t, msg.Attachments[0].Path, "path must be populated for non-Cloudflare attachment")
}

// TestGetMessage_ActiveAIAttachmentExternaluidPreserved verifies that an AI
// attachment whose ai_images row has status='active' is returned with its
// externaluid intact (not masked).
func TestGetMessage_ActiveAIAttachmentExternaluidPreserved(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("photos_ai_active")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")

	msgID := CreateTestMessage(t, userID, groupID, "Test Offer "+prefix, 55.9533, -3.1883)

	aiName := "ai-active-" + prefix
	aiUID := "freegletusd-test-" + aiName
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, 'active')", aiName, aiUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE name = ? LIMIT 1", aiName).Scan(&aiImageID)
	require.NotZero(t, aiImageID)

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
	var attID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attID)
	require.NotZero(t, attID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attID)
		db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID)
	})

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d", msgID), nil))
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var msg messageWithAttachments
	require.NoError(t, json.Unmarshal(body, &msg))

	require.NotEmpty(t, msg.Attachments, "active AI attachment must be returned")
	assert.Equal(t, attID, msg.Attachments[0].ID)
	assert.NotEmpty(t, msg.Attachments[0].Ouruid, "ouruid must be set from externaluid for Cloudflare images")
}

// TestGetMessage_RejectedAIAttachmentExternaluidMasked verifies that an AI
// attachment whose ai_images row has status='rejected' is returned with an
// empty externaluid, so the frontend shows a placeholder instead of the
// rejected illustration.
func TestGetMessage_RejectedAIAttachmentExternaluidMasked(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("photos_ai_rejected")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")

	msgID := CreateTestMessage(t, userID, groupID, "Test Offer "+prefix, 55.9533, -3.1883)

	aiName := "ai-rejected-" + prefix
	aiUID := "freegletusd-test-" + aiName
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, 'rejected')", aiName, aiUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE name = ? LIMIT 1", aiName).Scan(&aiImageID)
	require.NotZero(t, aiImageID)

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
	var attID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attID)
	require.NotZero(t, attID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attID)
		db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID)
	})

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d", msgID), nil))
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var msg messageWithAttachments
	require.NoError(t, json.Unmarshal(body, &msg))

	// The attachment row is still returned (not filtered out), but externaluid is masked.
	require.NotEmpty(t, msg.Attachments, "attachment row must still appear even when AI image is rejected")
	assert.Equal(t, attID, msg.Attachments[0].ID)
	assert.Empty(t, msg.Attachments[0].Externaluid, "rejected AI image externaluid must be masked to empty string")
	assert.Empty(t, msg.Attachments[0].Ouruid, "ouruid must not be set when externaluid is masked")
	// path is still set (uses image ID fallback) so the attachment slot renders as a placeholder.
	assert.NotEmpty(t, msg.Attachments[0].Path, "path must still be populated using ID-based fallback")
}
