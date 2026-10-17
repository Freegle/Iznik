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
	status, _ := postAutomodFeedbackBody(t, token, msgid, groupid, node)
	return status
}

func postAutomodFeedbackBody(t *testing.T, token string, msgid, groupid uint64, node string) (int, map[string]interface{}) {
	body := fmt.Sprintf(`{"msgid": %d, "groupid": %d, "node": %q}`, msgid, groupid, node)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/automod/feedback?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	return resp.StatusCode, out
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
	assert.Equal(t, 400, postAutomodFeedback(t, modToken, msgID, groupID, "NOT_A_STEP"), "only a step the decision walked can be marked")

	status, out := postAutomodFeedbackBody(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 200, status)
	assert.Equal(t, false, out["already"])

	// A second mark of the same step by the same moderator counts once.
	status, out = postAutomodFeedbackBody(t, modToken, msgID, groupID, "LOAN")
	assert.Equal(t, 200, status)
	assert.Equal(t, true, out["already"], "the same moderator marking the same step again is told so")

	// The end node is a step too.
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "HOLD_LOAN"))

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_automod_feedback f JOIN messages_automod ma ON ma.id = f.automodid "+
		"WHERE ma.msgid = ? AND f.node = 'LOAN'", msgID).Scan(&count)
	assert.Equal(t, int64(1), count, "one row per moderator per step")

	// The decision payload says which steps this moderator has marked, for the modal after a reload.
	a := getAutomodField(t, msgID, groupID, modToken)
	if assert.NotNil(t, a) {
		marked, _ := a["feedback"].([]interface{})
		assert.ElementsMatch(t, []interface{}{"LOAN", "HOLD_LOAN"}, marked)
	}
}

func TestAutomodAgreement(t *testing.T) {
	groupID, msgID, modID, modToken, _ := automodFixture(t, "automod_agreement")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", fmt.Sprintf("%d", groupID))

	// The chart held it; a human then approved it: a disagreement.
	assert.NoError(t, database.DBConn.Exec("UPDATE messages_groups SET collection='Approved', approvedby=? WHERE msgid=?", modID, msgID).Error)
	assert.Equal(t, 200, postAutomodFeedback(t, modToken, msgID, groupID, "LOAN"))

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/automod/agreement?days=7&jwt=%s", modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode, "an ordinary moderator cannot see the SysAdmin report")

	admin := CreateTestUser(t, uniquePrefix("automod_admin"), "Admin")
	_, adminToken := CreateTestSession(t, admin)
	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/automod/agreement?days=7&jwt=%s", adminToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var body agreementBody
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

type agreementBody struct {
	Days        int    `json:"days"`
	Groupid     uint64 `json:"groupid"`
	Total       int64  `json:"total"`
	Unavailable int64  `json:"unavailable"`
	Late        int64  `json:"late"`
	Latency     struct {
		AvgSeconds float64 `json:"avgSeconds"`
		MaxSeconds float64 `json:"maxSeconds"`
	} `json:"latency"`
	ByEnd []struct {
		End                string `json:"end"`
		Verdict            string `json:"verdict"`
		Count              int64  `json:"count"`
		ModDisagreed       int64  `json:"modDisagreed"`
		NeighbourDisagreed int64  `json:"neighbourDisagreed"`
	} `json:"byEnd"`
	ByGroup []struct {
		Groupid            uint64 `json:"groupid"`
		Mode               string `json:"mode"`
		Nameshort          string `json:"nameshort"`
		Decisions          int64  `json:"decisions"`
		Decided            int64  `json:"decided"`
		Approves           int64  `json:"approves"`
		Holds              int64  `json:"holds"`
		Unavailable        int64  `json:"unavailable"`
		ModDisagreed       int64  `json:"modDisagreed"`
		NeighbourDisagreed int64  `json:"neighbourDisagreed"`
		Late               int64  `json:"late"`
	} `json:"byGroup"`
	ByVersion []struct {
		Version            string `json:"version"`
		Decisions          int64  `json:"decisions"`
		Decided            int64  `json:"decided"`
		Approves           int64  `json:"approves"`
		Holds              int64  `json:"holds"`
		Unavailable        int64  `json:"unavailable"`
		ModDisagreed       int64  `json:"modDisagreed"`
		NeighbourDisagreed int64  `json:"neighbourDisagreed"`
		Late               int64  `json:"late"`
	} `json:"byVersion"`
	ByDay []struct {
		Day                string `json:"day"`
		Decisions          int64  `json:"decisions"`
		Decided            int64  `json:"decided"`
		Approves           int64  `json:"approves"`
		Holds              int64  `json:"holds"`
		Unavailable        int64  `json:"unavailable"`
		ModDisagreed       int64  `json:"modDisagreed"`
		NeighbourDisagreed int64  `json:"neighbourDisagreed"`
		Late               int64  `json:"late"`
	} `json:"byDay"`
	Feedback []struct {
		Node  string `json:"node"`
		Count int64  `json:"count"`
		Posts int64  `json:"posts"`
	} `json:"feedback"`
	PulledBack         int64 `json:"pulledBack"`
	PulledBackApproved int64 `json:"pulledBackApproved"`
	PulledBackRejected int64 `json:"pulledBackRejected"`
	Shadow             struct {
		Count int64 `json:"count"`
	} `json:"shadow"`
	Approve struct {
		Count int64 `json:"count"`
	} `json:"approve"`
}

func getAgreement(t *testing.T, token string, query string) agreementBody {
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/automod/agreement?%s&jwt=%s", query, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var body agreementBody
	json.NewDecoder(resp.Body).Decode(&body)
	return body
}

// The report answers the rollout questions per community: how many decisions, how many of
// them were real decisions, how often a moderator here or on a NEIGHBOURING community then
// disagreed, how long the chart took, and how many decisions came too late for a countdown.
// A groupid filter narrows every figure to one community.
func TestAutomodAgreementByGroupLatencyAndNeighbours(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "")
	prefix := uniquePrefix("automod_bygroup")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	other := CreateTestGroup(t, prefix+"_other")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, poster, other, "Member")
	CreateTestMembership(t, modB, groupB, "Moderator")
	admin := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, admin)
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d,%d", groupA, other))

	// Approved on A: content-checked three hours ago, decided an hour ago (two hours'
	// latency, and late: arrival + 20 minutes was long gone), published, then a moderator
	// of neighbouring B rejected the rippled copy there.
	late := CreateTestMessage(t, poster, groupA, prefix+" late", 52.0, -1.0)
	// Publishing re-stamps the copy's arrival (the digest keys off it), so a published copy's
	// arrival is when it went live, and the time the post waited is read off the post itself.
	db.Exec("UPDATE messages SET arrival=NOW() - INTERVAL 3 HOUR WHERE id=?", late)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 3 HOUR WHERE msgid=?", late)
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'approve', '2', 'approve', 'APPROVE', NULL, '[]', NOW() - INTERVAL 1 HOUR)", late, groupA)
	db.Exec("INSERT INTO logs (timestamp, type, subtype, msgid, groupid, byuser, user) VALUES (NOW(), 'Message', 'Rejected', ?, ?, ?, ?)", late, groupB, modB, poster)

	// Held on A, promptly: not disagreed with by anyone.
	held := CreateTestMessage(t, poster, groupA, prefix+" held", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 10 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 9 MINUTE WHERE msgid=?", held)
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'approve', '2', 'hold', 'HOLD_MONEY', 'Asks for money', '[]', NOW() - INTERVAL 8 MINUTE)", held, groupA)

	// The service could not answer for a third: counted, but not a decision.
	down := CreateTestMessage(t, poster, groupA, prefix+" down", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 10 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 9 MINUTE WHERE msgid=?", down)
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'approve', '', 'hold', 'UNAVAILABLE', 'Automated review unavailable', '[]', NOW() - INTERVAL 8 MINUTE)", down, groupA)

	// A decision on another community, to be filtered out.
	elsewhere := CreateTestMessage(t, poster, other, prefix+" elsewhere", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 10 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 9 MINUTE WHERE msgid=?", elsewhere)
	db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
		"VALUES (?, ?, 'shadow', '2', 'approve', 'APPROVE', NULL, '[]', NOW() - INTERVAL 8 MINUTE)", elsewhere, other)

	t.Cleanup(func() {
		db.Exec("DELETE FROM logs WHERE msgid IN (?, ?, ?, ?)", late, held, down, elsewhere)
		db.Exec("DELETE FROM messages_automod WHERE msgid IN (?, ?, ?, ?)", late, held, down, elsewhere)
		db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?, ?, ?)", late, held, down, elsewhere)
		db.Exec("DELETE FROM messages WHERE id IN (?, ?, ?, ?)", late, held, down, elsewhere)
	})

	body := getAgreement(t, adminToken, fmt.Sprintf("days=1&groupid=%d", groupA))
	assert.Equal(t, groupA, body.Groupid)
	assert.Equal(t, int64(3), body.Total, "the filter keeps only this community's decisions")
	assert.Equal(t, int64(3), body.Approve.Count)
	assert.Equal(t, int64(0), body.Shadow.Count)
	assert.Equal(t, int64(1), body.Unavailable)
	assert.Equal(t, int64(1), body.Late, "the decision an hour ago on a post that arrived three hours ago came after its wait")
	assert.GreaterOrEqual(t, body.Latency.MaxSeconds, float64(2*3600-60), "two hours from content check to decision")
	assert.LessOrEqual(t, body.Latency.MaxSeconds, float64(2*3600+60))
	assert.Greater(t, body.Latency.AvgSeconds, float64(0))
	assert.Less(t, body.Latency.AvgSeconds, body.Latency.MaxSeconds, "the prompt hold pulls the average below the slow one; the UNAVAILABLE row is left out")

	if assert.Len(t, body.ByGroup, 1) {
		g := body.ByGroup[0]
		assert.Equal(t, groupA, g.Groupid)
		assert.NotEmpty(t, g.Nameshort)
		assert.Equal(t, int64(3), g.Decisions)
		assert.Equal(t, int64(2), g.Decided, "UNAVAILABLE is not a decision")
		assert.Equal(t, int64(1), g.Approves)
		assert.Equal(t, int64(1), g.Holds)
		assert.Equal(t, int64(1), g.Unavailable)
		assert.Equal(t, int64(0), g.ModDisagreed, "nobody on A disagreed")
		assert.Equal(t, int64(1), g.NeighbourDisagreed, "B's moderator rejecting the rippled copy is a neighbour disagreement")
		assert.Equal(t, int64(1), g.Late)
	}

	approveRow := false
	for _, e := range body.ByEnd {
		if e.End == "APPROVE" {
			approveRow = true
			assert.Equal(t, int64(1), e.NeighbourDisagreed)
			assert.Equal(t, int64(0), e.ModDisagreed)
		}
		if e.End == "UNAVAILABLE" {
			assert.Equal(t, int64(0), e.ModDisagreed, "an UNAVAILABLE row is never a disagreement")
		}
	}
	assert.True(t, approveRow)

	// The same figures per chart version and per day, so a chart bump or a bad day shows as
	// a step rather than being averaged into the window.
	if assert.Len(t, body.ByVersion, 2, "chart version 2 and the UNAVAILABLE rows' empty version") {
		for _, v := range body.ByVersion {
			if v.Version == "2" {
				assert.Equal(t, int64(2), v.Decisions)
				assert.Equal(t, int64(2), v.Decided)
				assert.Equal(t, int64(1), v.Approves)
				assert.Equal(t, int64(1), v.Holds)
				assert.Equal(t, int64(1), v.NeighbourDisagreed)
				assert.Equal(t, int64(1), v.Late)
			} else {
				assert.Equal(t, "", v.Version, "an UNAVAILABLE row has no chart version")
				assert.Equal(t, int64(1), v.Unavailable)
				assert.Equal(t, int64(0), v.Decided)
			}
		}
	}
	if assert.Len(t, body.ByDay, 1, "every decision here was made today") {
		d := body.ByDay[0]
		assert.Regexp(t, `^\d{4}-\d{2}-\d{2}`, d.Day)
		assert.Equal(t, int64(3), d.Decisions)
		assert.Equal(t, int64(2), d.Decided)
		assert.Equal(t, int64(1), d.Unavailable)
		assert.Equal(t, int64(1), d.NeighbourDisagreed)
		assert.Equal(t, int64(1), d.Late)
	}

	all := getAgreement(t, adminToken, "days=1")
	assert.GreaterOrEqual(t, all.Total, int64(4), "without the filter every community's decisions count")
	seen := map[uint64]bool{}
	for _, g := range all.ByGroup {
		seen[g.Groupid] = true
	}
	assert.True(t, seen[groupA] && seen[other], "one row per community")
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
