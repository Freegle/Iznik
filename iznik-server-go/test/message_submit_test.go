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

// TestSubmitMessageSingleCall covers the new PUT /message/submit endpoint that
// creates + attaches (inline) + joins + posts a complete message in ONE request,
// replacing the old draft→image→JoinAndPost dance.
func TestSubmitMessageSingleCall(t *testing.T) {
	prefix := uniquePrefix("submit_single")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	// Deliberately NOT a member — the single call must auto-join (must stay).
	_, token := CreateTestSession(t, userID)

	body, _ := json.Marshal(map[string]interface{}{
		"type":         "Offer",
		"item":         "Test Submit Sofa",
		"textbody":     "A lovely free sofa, single-call submit",
		"groupid":      groupID,
		"availablenow": 1,
		"attachments": []map[string]interface{}{
			{"externaluid": "submit-uid-aaa", "externalmods": map[string]bool{"ai": true}},
			{"externaluid": "submit-uid-bbb"},
		},
	})
	req := httptest.NewRequest("PUT", "/api/message/submit?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	msgID := uint64(result["id"].(float64))
	assert.Greater(t, msgID, uint64(0))
	assert.Equal(t, float64(groupID), result["groupid"])

	db := database.DBConn

	// Message row created with the right type; subject derived from the item.
	var mtype, subject string
	db.Raw("SELECT type, COALESCE(subject,'') FROM messages WHERE id = ?", msgID).Row().Scan(&mtype, &subject)
	assert.Equal(t, "Offer", mtype)
	assert.Contains(t, subject, "Test Submit Sofa")

	// Posted to the group as Pending (NOT auto-approved, NOT a draft).
	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Row().Scan(&collection)
	assert.Equal(t, "Pending", collection)

	var draftCount int64
	db.Raw("SELECT COUNT(*) FROM messages_drafts WHERE msgid = ?", msgID).Scan(&draftCount)
	assert.Equal(t, int64(0), draftCount, "single-call submit must not leave a draft row")

	// Attachments inserted INLINE with msgid set and no fake ids — first is primary.
	var attCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ?", msgID).Scan(&attCount)
	assert.Equal(t, int64(2), attCount)
	var primaryUID string
	db.Raw("SELECT externaluid FROM messages_attachments WHERE msgid = ? AND `primary` = 1 LIMIT 1", msgID).Row().Scan(&primaryUID)
	assert.Equal(t, "submit-uid-aaa", primaryUID)

	// Item linked.
	var itemCount int64
	db.Raw("SELECT COUNT(*) FROM messages_items WHERE msgid = ?", msgID).Scan(&itemCount)
	assert.Equal(t, int64(1), itemCount)

	// Auto-join: a membership row now exists for this user+group.
	var memberCount int64
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ?", userID, groupID).Scan(&memberCount)
	assert.Equal(t, int64(1), memberCount, "single-call submit must auto-join the group")

	// Posting recorded.
	var postingCount int64
	db.Raw("SELECT COUNT(*) FROM messages_postings WHERE msgid = ? AND groupid = ?", msgID, groupID).Scan(&postingCount)
	assert.Greater(t, postingCount, int64(0))
}

// TestSubmitMessageLinksExistingAttachment covers the case where the client has
// already created an attachment row via POST /image (msgid NULL). The single-call
// submit must LINK that orphan row to the new message rather than inserting a
// duplicate, so the message ends up with exactly one attachment for that uid.
func TestSubmitMessageLinksExistingAttachment(t *testing.T) {
	prefix := uniquePrefix("submit_link")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn

	// Simulate POST /image having already created an orphan attachment row.
	orphanUID := "submit-link-uid-ccc"
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, `primary`) VALUES (NULL, ?, 0)", orphanUID)

	body, _ := json.Marshal(map[string]interface{}{
		"type":     "Offer",
		"item":     "Test Link Lamp",
		"textbody": "Linking an existing uploaded attachment",
		"groupid":  groupID,
		"attachments": []map[string]interface{}{
			{"externaluid": orphanUID},
		},
	})
	req := httptest.NewRequest("PUT", "/api/message/submit?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	msgID := uint64(result["id"].(float64))
	assert.Greater(t, msgID, uint64(0))

	// Exactly one row for this uid, now linked to the message and primary — no duplicate.
	var rowCount int64
	db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE externaluid = ?", orphanUID).Scan(&rowCount)
	assert.Equal(t, int64(1), rowCount, "must link the orphan row, not insert a duplicate")

	var linkedMsgID uint64
	var primary int
	db.Raw("SELECT msgid, `primary` FROM messages_attachments WHERE externaluid = ? LIMIT 1", orphanUID).Row().Scan(&linkedMsgID, &primary)
	assert.Equal(t, msgID, linkedMsgID)
	assert.Equal(t, 1, primary)
}

// TestSubmitMessageApprovedForUnmoderatedMember covers the V1-parity collection
// logic: an existing member who is explicitly unmoderated (ourPostingStatus DEFAULT)
// on an open group posts STRAIGHT to Approved (not Pending) and is indexed in
// messages_spatial so it shows on the public map immediately.
func TestSubmitMessageApprovedForUnmoderatedMember(t *testing.T) {
	prefix := uniquePrefix("submit_approved")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("UPDATE memberships SET ourPostingStatus = 'DEFAULT' WHERE userid = ? AND groupid = ?", userID, groupID)

	// A location with real coordinates so the post can be added to the spatial index.
	locName := "SubmitLoc " + prefix
	db.Exec("INSERT INTO locations (name, type, lat, lng, canon, popularity) VALUES (?, 'Point', 55.95, -3.19, ?, 0)", locName, locName)
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", locName).Scan(&locID)

	body, _ := json.Marshal(map[string]interface{}{
		"type":       "Offer",
		"item":       "Approved Sofa",
		"textbody":   "Posted straight to Approved",
		"groupid":    groupID,
		"locationid": locID,
	})
	req := httptest.NewRequest("PUT", "/api/message/submit?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	msgID := uint64(result["id"].(float64))
	assert.Greater(t, msgID, uint64(0))

	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, groupID).Row().Scan(&collection)
	assert.Equal(t, "Approved", collection, "unmoderated member on an open group posts straight to Approved")

	var spatialCount int64
	db.Raw("SELECT COUNT(*) FROM messages_spatial WHERE msgid = ?", msgID).Scan(&spatialCount)
	assert.Equal(t, int64(1), spatialCount, "an Approved post must be added to the spatial index")
}

// TestSubmitMessageModeratedGroupStaysPending: even an unmoderated member posts
// Pending when the group itself is moderated.
func TestSubmitMessageModeratedGroupStaysPending(t *testing.T) {
	prefix := uniquePrefix("submit_modgroup")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("UPDATE memberships SET ourPostingStatus = 'DEFAULT' WHERE userid = ? AND groupid = ?", userID, groupID)
	db.Exec(`UPDATE `+"`groups`"+` SET settings = '{"moderated":1}' WHERE id = ?`, groupID)

	body, _ := json.Marshal(map[string]interface{}{
		"type": "Offer", "item": "Moderated Group Sofa", "groupid": groupID,
	})
	req := httptest.NewRequest("PUT", "/api/message/submit?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, 10000)
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	msgID := uint64(result["id"].(float64))

	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ?", msgID).Row().Scan(&collection)
	assert.Equal(t, "Pending", collection, "a moderated group forces Pending regardless of member status")
}

// TestSubmitMessageModeratedMemberStaysPending: a member whose posting status is
// MODERATED stays Pending even on an open group.
func TestSubmitMessageModeratedMemberStaysPending(t *testing.T) {
	prefix := uniquePrefix("submit_modmember")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("UPDATE memberships SET ourPostingStatus = 'MODERATED' WHERE userid = ? AND groupid = ?", userID, groupID)

	body, _ := json.Marshal(map[string]interface{}{
		"type": "Offer", "item": "Moderated Member Sofa", "groupid": groupID,
	})
	req := httptest.NewRequest("PUT", "/api/message/submit?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, 10000)
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	msgID := uint64(result["id"].(float64))

	var collection string
	db.Raw("SELECT collection FROM messages_groups WHERE msgid = ?", msgID).Row().Scan(&collection)
	assert.Equal(t, "Pending", collection, "a MODERATED member stays Pending")
}

// TestSubmitMessageValidation covers the input guards.
func TestSubmitMessageValidation(t *testing.T) {
	prefix := uniquePrefix("submit_valid")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	cases := []struct {
		name string
		body map[string]interface{}
		want int
	}{
		{"missing item", map[string]interface{}{"type": "Offer", "groupid": groupID}, 400},
		{"bad type", map[string]interface{}{"type": "Banana", "item": "x", "groupid": groupID}, 400},
		{"missing group", map[string]interface{}{"type": "Offer", "item": "x"}, 400},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			b, _ := json.Marshal(tc.body)
			req := httptest.NewRequest("PUT", "/api/message/submit?jwt="+token, bytes.NewBuffer(b))
			req.Header.Set("Content-Type", "application/json")
			resp, _ := getApp().Test(req, 10000)
			assert.Equal(t, tc.want, resp.StatusCode)
		})
	}

	// Logged out with no email → 401.
	b, _ := json.Marshal(map[string]interface{}{"type": "Offer", "item": "x", "groupid": groupID})
	req := httptest.NewRequest("PUT", "/api/message/submit", bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req, 10000)
	assert.Equal(t, 401, resp.StatusCode)

	_ = fmt.Sprint(userID)
}
