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

// deleteAutomodFeedback sends DELETE /modtools/automod/feedback with the body ModTools sends
// (the frontend's DELETE helper JSON-encodes its parameters into the body). token "" sends no
// credentials.
func deleteAutomodFeedback(t *testing.T, token string, msgid, groupid uint64, node string) (int, map[string]interface{}) {
	body := fmt.Sprintf(`{"msgid": %d, "groupid": %d, "node": %q}`, msgid, groupid, node)
	url := "/api/modtools/automod/feedback"
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("DELETE", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	return resp.StatusCode, out
}

func countFeedbackRows(msgid uint64, node string) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_automod_feedback f JOIN messages_automod ma ON ma.id = f.automodid "+
		"WHERE ma.msgid = ? AND f.node = ?", msgid, node).Scan(&n)
	return n
}

// A moderator can take back a step they marked wrong. It removes only their own mark, under the
// same checks as marking it, and says 404 when there is nothing of theirs to remove.
func TestAutomodFeedbackUndo(t *testing.T) {
	groupID, msgID, modID, modToken, posterToken := automodFixture(t, "automod_fb_undo")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")

	// A second moderator of the same community, whose mark must survive the first one's undo.
	otherMod := CreateTestUser(t, uniquePrefix("automod_fb_undo_other"), "User")
	CreateTestMembership(t, otherMod, groupID, "Moderator")
	_, otherToken := CreateTestSession(t, otherMod)

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	status, _ := deleteAutomodFeedback(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 403, status, "not an automod group")

	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))

	status, _ = deleteAutomodFeedback(t, "", msgID, groupID, "LOAN")
	assert.Equal(t, 401, status, "not logged in")

	status, _ = deleteAutomodFeedback(t, posterToken, msgID, groupID, "LOAN")
	assert.Equal(t, 403, status, "not a moderator")

	status, _ = deleteAutomodFeedback(t, modToken, msgID+1000000, groupID, "LOAN")
	assert.Equal(t, 404, status, "no decision")

	status, _ = deleteAutomodFeedback(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 404, status, "nothing of this moderator's to remove")

	// Both moderators mark the step.
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"))
	assert.Equal(t, 200, postAutomodFeedback(t, otherToken, msgID, groupID, "LOAN"))
	assert.Equal(t, int64(2), countFeedbackRows(msgID, "LOAN"))

	status, out := deleteAutomodFeedback(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 200, status)
	assert.Equal(t, true, out["success"])
	assert.Equal(t, int64(1), countFeedbackRows(msgID, "LOAN"), "only the caller's own mark is removed")

	var mine int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_automod_feedback WHERE userid = ?", modID).Scan(&mine)
	assert.Equal(t, int64(0), mine)

	// The decision payload no longer lists the step as marked by this moderator, but still does
	// for the other one.
	a := getAutomodField(t, msgID, groupID, modToken)
	if assert.NotNil(t, a) {
		marked, _ := a["feedback"].([]interface{})
		assert.Empty(t, marked)
	}
	a = getAutomodField(t, msgID, groupID, otherToken)
	if assert.NotNil(t, a) {
		marked, _ := a["feedback"].([]interface{})
		assert.ElementsMatch(t, []interface{}{"LOAN"}, marked)
	}

	status, _ = deleteAutomodFeedback(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 404, status, "a second undo finds nothing")

	// The mark can be given again afterwards.
	status, out = postAutomodFeedbackBody(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 200, status)
	assert.Equal(t, false, out["already"])
}

func TestAutomodFeedbackUndoValidatesAndAcceptsQueryParameters(t *testing.T) {
	groupID, msgID, _, modToken, _ := automodFixture(t, "automod_fb_undo_params")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))

	status, _ := deleteAutomodFeedback(t, modToken, msgID, groupID, "")
	assert.Equal(t, 400, status, "the node is required")
	status, _ = deleteAutomodFeedback(t, modToken, 0, groupID, "LOAN")
	assert.Equal(t, 400, status, "the message is required")

	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"))

	// A client that cannot send a body on a DELETE names the step in the query instead.
	req := httptest.NewRequest("DELETE",
		fmt.Sprintf("/api/modtools/automod/feedback?msgid=%d&groupid=%d&node=LOAN&jwt=%s", msgID, groupID, modToken), nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, int64(0), countFeedbackRows(msgID, "LOAN"))
}

// The agreement report's feedback rows say how many marks a step has had and on how many
// decisions: two moderators marking one decision is two marks on one post.
func TestAutomodAgreementFeedbackCountsMarksAndPosts(t *testing.T) {
	groupID, msgID, _, modToken, _ := automodFixture(t, "automod_fb_posts")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))
	db := database.DBConn

	otherMod := CreateTestUser(t, uniquePrefix("automod_fb_posts_other"), "User")
	CreateTestMembership(t, otherMod, groupID, "Moderator")
	_, otherToken := CreateTestSession(t, otherMod)
	admin := CreateTestUser(t, uniquePrefix("automod_fb_posts_admin"), "Admin")
	_, adminToken := CreateTestSession(t, admin)

	// A second post on the same community, with its own decision that also walked LOAN.
	poster := CreateTestUser(t, uniquePrefix("automod_fb_posts_poster"), "User")
	CreateTestMembership(t, poster, groupID, "Member")
	secondMsg := CreateTestMessage(t, poster, groupID, uniquePrefix("automod_fb_posts")+" second", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', contentcheck_checked_at=NOW() WHERE msgid=?", secondMsg)
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'shadow', '2', 'hold', 'HOLD_LOAN', 'Asks to borrow', ?, NOW())",
		secondMsg, groupID, `[{"node":"LOAN","question":"Is it a loan?","kind":"text","answer":"yes","p":0.9,"threshold":0.7,"model":"claude:test","evidence":"lend me"}]`)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_automod WHERE msgid = ?", secondMsg)
		db.Exec("DELETE FROM messages_groups WHERE msgid = ?", secondMsg)
		db.Exec("DELETE FROM messages WHERE id = ?", secondMsg)
	})

	// Three marks of LOAN on two decisions: both moderators on the first post, one on the second.
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"))
	assert.Equal(t, 200, postAutomodFeedback(t, otherToken, msgID, groupID, "LOAN"))
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, secondMsg, groupID, "LOAN"))
	// One mark of the end node, on one decision.
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "HOLD_LOAN"))

	body := getAgreement(t, adminToken, fmt.Sprintf("days=7&groupid=%d", groupID))
	rows := map[string][2]int64{}
	for _, f := range body.Feedback {
		rows[f.Node] = [2]int64{f.Count, f.Posts}
	}
	assert.Equal(t, [2]int64{3, 2}, rows["LOAN"], "LOAN: three marks on two posts")
	assert.Equal(t, [2]int64{1, 1}, rows["HOLD_LOAN"], "HOLD_LOAN: one mark on one post")

	// Taking a mark back is reflected in both figures.
	status, _ := deleteAutomodFeedback(t, modToken, secondMsg, groupID, "LOAN")
	assert.Equal(t, 200, status)
	body = getAgreement(t, adminToken, fmt.Sprintf("days=7&groupid=%d", groupID))
	rows = map[string][2]int64{}
	for _, f := range body.Feedback {
		rows[f.Node] = [2]int64{f.Count, f.Posts}
	}
	assert.Equal(t, [2]int64{2, 1}, rows["LOAN"], "LOAN after one undo: two marks on one post")
}
