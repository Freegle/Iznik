package test

import (
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func banMemberAs(t *testing.T, modToken string, userID uint64) {
	request := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/members/%d/ban?jwt=%s", userID, modToken), nil)
	resp, err := getApp().Test(request)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

// A site-wide ban takes the member's open posts down: an approved post gets a Withdrawn outcome and
// a pending one is deleted. Posts that already have an outcome, and other members' posts, are left.
func TestBanWithdrawsOpenPosts(t *testing.T) {
	prefix := uniquePrefix("banwd")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	bannedID := CreateTestUser(t, prefix+"_banned", utils.SYSTEMROLE_USER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)

	approved := CreateTestMessage(t, bannedID, prefix+" approved", 52.2, -0.1)
	pending := CreateTestMessage(t, bannedID, prefix+" pending", 52.2, -0.1)
	db.Exec("UPDATE messages SET collection = ? WHERE id = ?", utils.COLLECTION_PENDING, pending)
	done := CreateTestMessage(t, bannedID, prefix+" done", 52.2, -0.1)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, ?)", done, utils.OUTCOME_TAKEN)
	others := CreateTestMessage(t, otherID, prefix+" others", 52.2, -0.1)

	banMemberAs(t, modToken, bannedID)

	var outcome string
	db.Raw("SELECT COALESCE(MAX(outcome), '') FROM messages_outcomes WHERE msgid = ?", approved).Scan(&outcome)
	assert.Equal(t, utils.OUTCOME_WITHDRAWN, outcome, "an approved post is withdrawn by the ban")

	var comments string
	db.Raw("SELECT COALESCE(MAX(comments), '') FROM messages_outcomes WHERE msgid = ?", approved).Scan(&comments)
	assert.Equal(t, "Marked as withdrawn by ban", comments)

	var deleted *string
	db.Raw("SELECT deleted FROM messages WHERE id = ?", pending).Scan(&deleted)
	assert.NotNil(t, deleted, "a pending post is deleted by the ban")

	db.Raw("SELECT COALESCE(MAX(outcome), '') FROM messages_outcomes WHERE msgid = ?", done).Scan(&outcome)
	assert.Equal(t, utils.OUTCOME_TAKEN, outcome, "a post with an outcome keeps it")

	var n int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", others).Scan(&n)
	assert.Equal(t, int64(0), n, "another member's post is untouched")
	db.Raw("SELECT deleted FROM messages WHERE id = ?", others).Scan(&deleted)
	assert.Nil(t, deleted)
}

// An admin may not delete an account whose system role is Moderator, Support or Admin.
func TestLimboUserCannotDeleteSystemRoleHolders(t *testing.T) {
	for _, role := range []string{utils.SYSTEMROLE_MODERATOR, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN} {
		prefix := uniquePrefix("delrole" + role)
		adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
		targetID := CreateTestUser(t, prefix+"_target", role)
		_, adminToken := CreateTestSession(t, adminID)

		request := httptest.NewRequest("DELETE", fmt.Sprintf("/api/user?jwt=%s&id=%d", adminToken, targetID), nil)
		resp, err := getApp().Test(request)
		assert.NoError(t, err)
		assert.Equal(t, fiber.StatusForbidden, resp.StatusCode, "role "+role)
	}
}
