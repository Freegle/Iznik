package test

import (
	"bytes"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A PATCH that names only some fields changes only those. Fields it leaves out keep their text.
func TestCommentEditPartialBodyKeepsOtherFields(t *testing.T) {
	prefix := uniquePrefix("cm_partial")
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	targetID := CreateTestUser(t, prefix+"_target", utils.SYSTEMROLE_USER)
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	CreateTestMembership(t, targetID, groupID, utils.ROLE_MEMBER)
	_, modToken := CreateTestSession(t, modID)

	db := database.DBConn
	db.Exec("INSERT INTO users_comments (userid, groupid, byuserid, user1, user2, user3, flag) VALUES (?, ?, ?, 'first note', 'second note', 'third note', 0)", targetID, groupID, modID)
	var commentID uint64
	db.Raw("SELECT id FROM users_comments WHERE userid = ? AND groupid = ? ORDER BY id DESC LIMIT 1", targetID, groupID).Scan(&commentID)
	t.Cleanup(func() { db.Exec("DELETE FROM users_comments WHERE id = ?", commentID) })

	body := fmt.Sprintf(`{"id":%d,"user2":"changed note"}`, commentID)
	req := httptest.NewRequest("PATCH", "/api/comment?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var row struct {
		User1 *string
		User2 *string
		User3 *string
	}
	db.Raw("SELECT user1, user2, user3 FROM users_comments WHERE id = ?", commentID).Scan(&row)
	if assert.NotNil(t, row.User1, "a field the request did not send is not blanked") {
		assert.Equal(t, "first note", *row.User1)
	}
	if assert.NotNil(t, row.User2) {
		assert.Equal(t, "changed note", *row.User2)
	}
	if assert.NotNil(t, row.User3, "a field the request did not send is not blanked") {
		assert.Equal(t, "third note", *row.User3)
	}
}
