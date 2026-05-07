package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestMessagePatch_BadAIImageForceReject verifies that when a moderator removes
// an AI-generated attachment with badAIImages set, the image is immediately
// rejected (status='rejected') without waiting for quorum.
func TestMessagePatch_BadAIImageForceReject(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("badaiimg")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	msgID := CreateTestMessage(t, ownerID, groupID, "Test item "+prefix, 55.0, -1.0)

	aiName := "badai-" + prefix
	aiUID := "freegletusd-test-" + aiName
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, 'active')", aiName, aiUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE name = ? ORDER BY id DESC LIMIT 1", aiName).Scan(&aiImageID)
	assert.NotZero(t, aiImageID)

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
	var attID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attID)
	assert.NotZero(t, attID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID)
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attID)
		db.Exec("DELETE FROM microactions WHERE aiimageid = ?", aiImageID)
	})

	body := fmt.Sprintf(`{"id":%d,"attachments":[],"badAIImages":[%d]}`, msgID, attID)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", aiImageID).Scan(&status)
	assert.Equal(t, "rejected", status, "AI image should be immediately rejected by moderator force-reject")
}

// TestMessagePatch_NormalAIDeletionVote verifies that a deletion without badAIImages
// records a Reject vote but does NOT immediately reject the image (quorum needed).
func TestMessagePatch_NormalAIDeletionVote(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("normalaidel")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	msgID := CreateTestMessage(t, ownerID, groupID, "Test item "+prefix, 55.0, -1.0)

	aiName := "normalai-" + prefix
	aiUID := "freegletusd-test-" + aiName
	db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, 'active')", aiName, aiUID)
	var aiImageID uint64
	db.Raw("SELECT id FROM ai_images WHERE name = ? ORDER BY id DESC LIMIT 1", aiName).Scan(&aiImageID)
	assert.NotZero(t, aiImageID)

	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
	var attID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attID)
	assert.NotZero(t, attID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID)
		db.Exec("DELETE FROM messages_attachments WHERE id = ?", attID)
		db.Exec("DELETE FROM microactions WHERE aiimageid = ?", aiImageID)
	})

	// No badAIImages — normal deletion
	body := fmt.Sprintf(`{"id":%d,"attachments":[]}`, msgID)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var status string
	db.Raw("SELECT status FROM ai_images WHERE id = ?", aiImageID).Scan(&status)
	assert.Equal(t, "active", status, "AI image should remain active after a single normal vote")

	var voteCount int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE aiimageid = ? AND result = 'Reject'", aiImageID).Scan(&voteCount)
	assert.Equal(t, int64(1), voteCount, "A Reject vote should be recorded")
}
