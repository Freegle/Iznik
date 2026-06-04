package test

// TestJoinMethod* — V1→V2 parity for the membership join-method field.
//
// V1 (User.php:944-957): User::addMembership writes the Group/Joined log's
// text = 'Manual' (manual truthy), 'Auto' (manual === false), or '' (null).
// V2 was discarding the manual field from PutMembershipsRequest and hard-coding
// the Joined-log text to "". Additionally GetUserMembershipHistory was not
// returning l.text so even existing text was invisible to ModTools.
//
// These tests pin the corrected behaviour: text must flow end-to-end from the
// PUT /memberships request body → logs.text → GET /user/:id/membershiphistory response.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestJoinMethodManual verifies that manual=true records 'Manual' in logs.text.
func TestJoinMethodManual(t *testing.T) {
	prefix := uniquePrefix("jm_manual")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	groupID := CreateTestGroup(t, prefix)

	// self-join with manual=true
	manualTrue := true
	body := map[string]interface{}{
		"groupid": groupID,
		"manual":  manualTrue,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/memberships?jwt=%s", token)
	req := httptest.NewRequest("PUT", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify the Joined log has text='Manual'
	var logText string
	db.Raw("SELECT COALESCE(text,'') FROM logs WHERE type='Group' AND subtype='Joined' AND user=? AND groupid=? ORDER BY id DESC LIMIT 1",
		userID, groupID).Scan(&logText)
	assert.Equal(t, "Manual", logText, "manual=true join must record text='Manual' in the Joined log")
}

// TestJoinMethodAuto verifies that manual=false records 'Auto' in logs.text.
func TestJoinMethodAuto(t *testing.T) {
	prefix := uniquePrefix("jm_auto")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	groupID := CreateTestGroup(t, prefix)

	// self-join with manual=false
	manualFalse := false
	body := map[string]interface{}{
		"groupid": groupID,
		"manual":  manualFalse,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/memberships?jwt=%s", token)
	req := httptest.NewRequest("PUT", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify the Joined log has text='Auto'
	var logText string
	db.Raw("SELECT COALESCE(text,'') FROM logs WHERE type='Group' AND subtype='Joined' AND user=? AND groupid=? ORDER BY id DESC LIMIT 1",
		userID, groupID).Scan(&logText)
	assert.Equal(t, "Auto", logText, "manual=false join must record text='Auto' in the Joined log")
}

// TestJoinMethodOmitted verifies that omitting manual records '' (empty) in logs.text.
func TestJoinMethodOmitted(t *testing.T) {
	prefix := uniquePrefix("jm_omit")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	groupID := CreateTestGroup(t, prefix)

	// self-join without manual field
	body := map[string]interface{}{
		"groupid": groupID,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/memberships?jwt=%s", token)
	req := httptest.NewRequest("PUT", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify the Joined log has text='' (empty / NULL)
	var logText string
	db.Raw("SELECT COALESCE(text,'') FROM logs WHERE type='Group' AND subtype='Joined' AND user=? AND groupid=? ORDER BY id DESC LIMIT 1",
		userID, groupID).Scan(&logText)
	assert.Equal(t, "", logText, "omitting manual must record empty text in the Joined log")
}

// TestJoinMethodHistoryReturnsText verifies that GET /user/:id/membershiphistory
// returns the text field (join-method) in each row.
func TestJoinMethodHistoryReturnsText(t *testing.T) {
	prefix := uniquePrefix("jm_hist")
	db := database.DBConn

	// Create a mod who can view membership history.
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Create target user and join with manual=true.
	memberID := CreateTestUser(t, prefix+"_member", "User")
	_, memberToken := CreateTestSession(t, memberID)

	manualTrue := true
	body := map[string]interface{}{
		"groupid": groupID,
		"manual":  manualTrue,
	}
	bodyBytes, _ := json.Marshal(body)
	putURL := fmt.Sprintf("/api/memberships?jwt=%s", memberToken)
	putReq := httptest.NewRequest("PUT", putURL, bytes.NewBuffer(bodyBytes))
	putReq.Header.Set("Content-Type", "application/json")
	putResp, err := getApp().Test(putReq)
	assert.NoError(t, err)
	assert.Equal(t, 200, putResp.StatusCode)

	// Confirm the log was written with text='Manual'.
	var logText string
	db.Raw("SELECT COALESCE(text,'') FROM logs WHERE type='Group' AND subtype='Joined' AND user=? AND groupid=? ORDER BY id DESC LIMIT 1",
		memberID, groupID).Scan(&logText)
	assert.Equal(t, "Manual", logText, "prerequisite: log must have text='Manual'")

	// GET /user/:id/membershiphistory as the mod.
	histURL := fmt.Sprintf("/api/user/%d/membershiphistory?jwt=%s", memberID, modToken)
	histReq := httptest.NewRequest("GET", histURL, nil)
	histResp, err := getApp().Test(histReq)
	assert.NoError(t, err)
	assert.Equal(t, 200, histResp.StatusCode)

	var history []map[string]interface{}
	json.NewDecoder(histResp.Body).Decode(&history)
	assert.GreaterOrEqual(t, len(history), 1, "history must contain at least one entry")

	// Find the Joined entry and assert it has text='Manual'.
	found := false
	for _, row := range history {
		if rowType, ok := row["type"].(string); ok && rowType == "Joined" {
			found = true
			textVal, hasText := row["text"]
			assert.True(t, hasText, "membershiphistory rows must include a 'text' field")
			assert.Equal(t, "Manual", textVal, "Joined entry text must be 'Manual' for a manual join")
			break
		}
	}
	assert.True(t, found, "history must contain a Joined entry")
}
