package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// When a post is collected (Taken/Received) while still Pending in another group - the
// rippling-out case, where the post was rippled into a neighbouring group and is awaiting
// that group's approval - the still-Pending appearance is retired so it leaves that group's
// mod queue, but the post itself and its Approved (origin) appearance survive as a Taken record.
func TestPostMessageTakenRetiresRippledPendingKeepsApproved(t *testing.T) {
	prefix := uniquePrefix("outcome_ripple_pending")
	db := database.DBConn

	originGroup := CreateTestGroup(t, prefix+"_origin")
	rippledGroup := CreateTestGroup(t, prefix+"_rippled")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, originGroup, "Member")
	_, posterToken := CreateTestSession(t, posterID)

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)
	db.Exec("INSERT INTO messages (fromuser, subject, textbody, message, type, locationid, arrival, date) VALUES (?, ?, 'Body', 'Body', 'Offer', ?, NOW(), NOW())",
		posterID, prefix+" offer", locationID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? AND subject = ? ORDER BY id DESC LIMIT 1", posterID, prefix+" offer").Scan(&msgID)
	if msgID == 0 {
		t.Fatal("failed to create message")
	}

	// Approved on the origin group; Pending + rippled_in=1 on the rippled-into group.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in, approvedat) VALUES (?, ?, NOW(), 'Approved', 0, 0, NOW())", msgID, originGroup)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Pending', 0, 1)", msgID, rippledGroup)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)

	body := map[string]interface{}{"id": msgID, "action": "Outcome", "outcome": "Taken"}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", posterToken), bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "poster can mark their offer Taken")

	// The rippled-in Pending row is retired (soft-deleted) - removed from that group's pending.
	var rippledDeleted int
	db.Raw("SELECT deleted FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, rippledGroup).Scan(&rippledDeleted)
	assert.Equal(t, 1, rippledDeleted, "rippled-in Pending row should be soft-deleted on Take")

	// The origin Approved row survives.
	var originDeleted int
	db.Raw("SELECT deleted FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, originGroup).Scan(&originDeleted)
	assert.Equal(t, 0, originDeleted, "origin Approved row must survive")

	// The post itself is NOT deleted.
	var msgDeleted *string
	db.Raw("SELECT deleted FROM messages WHERE id = ?", msgID).Scan(&msgDeleted)
	assert.Nil(t, msgDeleted, "the post itself must NOT be deleted")

	// The Taken outcome is recorded.
	var outcomeCount int
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = 'Taken'", msgID).Scan(&outcomeCount)
	assert.Equal(t, 1, outcomeCount, "Taken outcome recorded")
}

// Guard: a post that is Pending only on its single group (Approved nowhere) must NOT have its
// lone Pending row removed on Take - doing so would strand the message (no live appearance left).
func TestPostMessageTakenSingleGroupPendingNotStranded(t *testing.T) {
	prefix := uniquePrefix("outcome_single_pending")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	_, posterToken := CreateTestSession(t, posterID)

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)
	db.Exec("INSERT INTO messages (fromuser, subject, textbody, message, type, locationid, arrival, date) VALUES (?, ?, 'Body', 'Body', 'Offer', ?, NOW(), NOW())",
		posterID, prefix+" offer", locationID)
	var msgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? AND subject = ? ORDER BY id DESC LIMIT 1", posterID, prefix+" offer").Scan(&msgID)
	if msgID == 0 {
		t.Fatal("failed to create message")
	}

	// Pending on its only group, Approved nowhere.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Pending', 0, 0)", msgID, groupID)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)

	body := map[string]interface{}{"id": msgID, "action": "Outcome", "outcome": "Taken"}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", posterToken), bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Not Approved elsewhere -> the lone Pending row is left intact (post not stranded).
	var deleted int
	db.Raw("SELECT deleted FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&deleted)
	assert.Equal(t, 0, deleted, "single-group Pending row must NOT be removed (would strand the post)")
}
