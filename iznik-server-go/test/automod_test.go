package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// getAutomodField fetches a message as the given user and returns the automod block for the
// named group, or nil.
func getAutomodField(t *testing.T, msgid uint64, groupid uint64, token string) map[string]interface{} {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgid, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var body map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&body)
	groups, _ := body["groups"].([]interface{})
	for _, g := range groups {
		gm, _ := g.(map[string]interface{})
		if gid, ok := gm["groupid"].(float64); ok && uint64(gid) == groupid {
			a, _ := gm["automod"].(map[string]interface{})
			return a
		}
	}
	return nil
}

func postAutomodFeedback(t *testing.T, token string, msgid, groupid uint64, node string) int {
	body := fmt.Sprintf(`{"msgid": %d, "groupid": %d, "node": %q}`, msgid, groupid, node)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/automod/feedback?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	return resp.StatusCode
}

// automodFixture: a group with a moderator, a poster and a Pending post with a hold decision.
func automodFixture(t *testing.T, name string) (groupID, msgID, modID uint64, modToken string, posterToken string) {
	prefix := uniquePrefix(name)
	groupID = CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID = CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken = CreateTestSession(t, modID)
	_, posterToken = CreateTestSession(t, poster)

	msgID = CreateTestMessage(t, poster, groupID, prefix+" automod pending", 52.0, -1.0)
	database.DBConn.Exec("UPDATE messages_groups SET collection='Pending', contentcheck_checked_at=NOW() WHERE msgid=?", msgID)
	database.DBConn.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'shadow', '2', 'hold', 'HOLD_LOAN', 'Asks to borrow', ?, NOW())",
		msgID, groupID, `[{"node":"LOAN","question":"Is it a loan?","kind":"text","answer":"yes","p":0.9,"threshold":0.7,"model":"claude:test","evidence":"lend me"}]`)
	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM messages_automod WHERE msgid = ?", msgID)
		database.DBConn.Exec("DELETE FROM messages_groups WHERE msgid = ?", msgID)
		database.DBConn.Exec("DELETE FROM messages WHERE id = ?", msgID)
	})
	return
}

func TestAutomodDecisionShownToModeratorsOfAutomodGroupsOnly(t *testing.T) {
	groupID, msgID, _, modToken, posterToken := automodFixture(t, "automod_payload")

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	assert.Nil(t, getAutomodField(t, msgID, groupID, modToken), "not an automod group: nothing shown")

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))
	a := getAutomodField(t, msgID, groupID, modToken)
	if assert.NotNil(t, a, "a moderator of a shadow group sees the decision") {
		assert.Equal(t, "hold", a["verdict"])
		assert.Equal(t, "HOLD_LOAN", a["end"])
		assert.Equal(t, "shadow", a["mode"])
		assert.Equal(t, "2", a["version"])
		path, _ := a["path"].([]interface{})
		assert.Len(t, path, 1)
	}

	assert.Nil(t, getAutomodField(t, msgID, groupID, posterToken), "the poster is not a moderator: nothing shown")
}

func TestAutomodFeedback(t *testing.T) {
	groupID, msgID, _, modToken, posterToken := automodFixture(t, "automod_feedback")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	assert.Equal(t, 403, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"), "not an automod group")

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))
	assert.Equal(t, 403, postAutomodFeedback(t, posterToken, msgID, groupID, "LOAN"), "not a moderator")
	assert.Equal(t, 404, postAutomodFeedback(t, modToken, msgID+1000000, groupID, "LOAN"), "no decision")
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"))

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_automod_feedback f JOIN messages_automod ma ON ma.id = f.automodid "+
		"WHERE ma.msgid = ? AND f.node = 'LOAN'", msgID).Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestAutomodAgreement(t *testing.T) {
	groupID, msgID, _, modToken, _ := automodFixture(t, "automod_agreement")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))

	// The chart held it; a human then approved it: a disagreement.
	database.DBConn.Exec("UPDATE messages_groups SET collection='Approved', approvedby=1 WHERE msgid=?", msgID)
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"))

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/automod/agreement?days=7&jwt=%s", modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode, "an ordinary moderator cannot see the SysAdmin report")

	admin := CreateTestUser(t, uniquePrefix("automod_admin"), "Admin")
	_, adminToken := CreateTestSession(t, admin)
	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/automod/agreement?days=7&jwt=%s", adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body struct {
		Days  int   `json:"days"`
		Total int64 `json:"total"`
		ByEnd []struct {
			End          string `json:"end"`
			Verdict      string `json:"verdict"`
			Count        int64  `json:"count"`
			ModDisagreed int64  `json:"modDisagreed"`
		} `json:"byEnd"`
		Feedback []struct {
			Node  string `json:"node"`
			Count int64  `json:"count"`
		} `json:"feedback"`
		Shadow struct {
			Count int64 `json:"count"`
		} `json:"shadow"`
	}
	json.NewDecoder(resp.Body).Decode(&body)
	assert.Equal(t, 7, body.Days)
	assert.GreaterOrEqual(t, body.Shadow.Count, int64(1))

	found := false
	for _, e := range body.ByEnd {
		if e.End == "HOLD_LOAN" && e.Verdict == "hold" {
			found = true
			assert.GreaterOrEqual(t, e.ModDisagreed, int64(1), "a held post a human approved counts as a disagreement")
		}
	}
	assert.True(t, found, "HOLD_LOAN appears in the report")

	loan := false
	for _, f := range body.Feedback {
		if f.Node == "LOAN" && f.Count >= 1 {
			loan = true
		}
	}
	assert.True(t, loan, "the step-is-wrong feedback is counted")
}

func TestSessionMarksAutomodMemberships(t *testing.T) {
	groupID, _, _, modToken, _ := automodFixture(t, "automod_session")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/session?jwt=%s", modToken), nil))
	assert.NoError(t, err)
	var body map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&body)
	groups, _ := body["groups"].([]interface{})
	marked := false
	for _, g := range groups {
		gm, _ := g.(map[string]interface{})
		if gid, ok := gm["groupid"].(float64); ok && uint64(gid) == groupID {
			marked, _ = gm["automod"].(bool)
		}
	}
	assert.True(t, marked, "a moderator's shadow-group membership is marked automod")
}
