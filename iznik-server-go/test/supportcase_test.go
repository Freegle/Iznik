package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func cleanupSupportCase(caseid uint64) {
	db := database.DBConn
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'support_case_message' AND JSON_EXTRACT(data, '$.caseid') = ?", caseid)
	db.Exec("DELETE FROM support_cases WHERE id = ?", caseid)
}

// scCall makes an API call and returns the status and the raw body.
func scCall(t *testing.T, method, path, token string, body interface{}) (int, []byte) {
	t.Helper()
	var reader io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		reader = bytes.NewBuffer(b)
	}
	if token != "" {
		sep := "?"
		if strings.Contains(path, "?") {
			sep = "&"
		}
		path += sep + "jwt=" + token
	}
	req := httptest.NewRequest(method, path, reader)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	raw, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, raw
}

func scMap(t *testing.T, raw []byte) map[string]interface{} {
	t.Helper()
	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(raw, &m), string(raw))
	return m
}

type scWorld struct {
	member, mod, group, chat uint64
	modToken                 string
}

// scSetup makes a community with a member, a moderator and a member-to-mods chat.
func scSetup(t *testing.T, name string) scWorld {
	prefix := uniquePrefix(name)
	w := scWorld{}
	w.member = CreateTestUser(t, prefix+"_m", "User")
	w.mod = CreateTestUser(t, prefix+"_mod", "Moderator")
	w.group = CreateTestGroup(t, prefix)
	CreateTestMembership(t, w.member, w.group, "Member")
	CreateTestMembership(t, w.mod, w.group, "Moderator")
	w.chat = CreateTestChatRoom(t, w.member, nil, &w.group, "User2Mod")
	CreateTestChatMessage(t, w.chat, w.member, "Hello mods")
	_, w.modToken = CreateTestSession(t, w.mod)
	return w
}

func scRefer(t *testing.T, token string, chatid uint64, reason string) (int, map[string]interface{}) {
	body := map[string]interface{}{"id": chatid, "action": "ReferToSupport", "modtools": true}
	if reason != "" {
		body["reason"] = reason
	}
	status, raw := scCall(t, "POST", "/api/chatrooms", token, body)
	m := map[string]interface{}{}
	json.Unmarshal(raw, &m)
	if id, ok := m["caseid"].(float64); ok {
		t.Cleanup(func() { cleanupSupportCase(uint64(id)) })
	}
	return status, m
}

func scReferOK(t *testing.T, token string, chatid uint64, reason string) uint64 {
	status, m := scRefer(t, token, chatid, reason)
	assert.Equal(t, 200, status)
	assert.Equal(t, "Success", m["status"])
	id, _ := m["caseid"].(float64)
	assert.Greater(t, id, float64(0))
	return uint64(id)
}

func scSupport(t *testing.T, name string) (uint64, string) {
	id := CreateTestUser(t, uniquePrefix(name), "Support")
	_, token := CreateTestSession(t, id)
	return id, token
}

func TestSupportCaseCreateRecordsCase(t *testing.T) {
	w := scSetup(t, "sccreate")
	caseid := scReferOK(t, w.modToken, w.chat, "  Member is upset  ")

	var status, fp, source, openKey string
	var reason *string
	var referrer, member, chatid uint64
	err := database.DBConn.Raw("SELECT status, firstpass_status, source, reason, open_key, referrerid, userid, chatid FROM support_cases WHERE id = ?", caseid).
		Row().Scan(&status, &fp, &source, &reason, &openKey, &referrer, &member, &chatid)
	assert.NoError(t, err)
	assert.Equal(t, "Open", status)
	assert.Equal(t, "Pending", fp)
	assert.Equal(t, "Referral", source)
	assert.Equal(t, "Member is upset", *reason)
	assert.Equal(t, fmt.Sprintf("%d-%d", w.chat, w.mod), openKey)
	assert.Equal(t, w.mod, referrer)
	assert.Equal(t, w.member, member, "a member-to-mods chat is about its member")
	assert.Equal(t, w.chat, chatid)

	var tasks int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type IN ('refer_to_support', 'support_case_message') AND (JSON_EXTRACT(data, '$.chatid') = ? OR JSON_EXTRACT(data, '$.caseid') = ?)", w.chat, caseid).Scan(&tasks)
	assert.Equal(t, int64(0), tasks, "the case row is the queue; a new referral queues no task")
}

func TestSupportCaseReasonIsOptionalAndCapped(t *testing.T) {
	w := scSetup(t, "screason")
	none := scReferOK(t, w.modToken, w.chat, "")
	var reason *string
	database.DBConn.Raw("SELECT reason FROM support_cases WHERE id = ?", none).Row().Scan(&reason)
	assert.Nil(t, reason)

	// A different referrer so the second is not the same open case.
	w2 := scSetup(t, "screason2")
	long := scReferOK(t, w2.modToken, w2.chat, strings.Repeat("é", 3000))
	var stored string
	database.DBConn.Raw("SELECT reason FROM support_cases WHERE id = ?", long).Row().Scan(&stored)
	assert.Equal(t, 2000, len([]rune(stored)))
}

func TestSupportCaseDoubleReferralReturnsSameCase(t *testing.T) {
	w := scSetup(t, "scdouble")
	first := scReferOK(t, w.modToken, w.chat, "first")
	second := scReferOK(t, w.modToken, w.chat, "second reason")
	assert.Equal(t, first, second)

	var cases int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_cases WHERE chatid = ? AND referrerid = ?", w.chat, w.mod).Scan(&cases)
	assert.Equal(t, int64(1), cases)

	var body, role string
	var msgid uint64
	err := database.DBConn.Raw("SELECT id, body, role FROM support_case_messages WHERE caseid = ?", first).Row().Scan(&msgid, &body, &role)
	assert.NoError(t, err)
	assert.Equal(t, "Referred again: second reason", body)
	assert.Equal(t, "Referrer", role)

	var tasks int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'support_case_message' AND JSON_EXTRACT(data, '$.caseid') = ? AND JSON_EXTRACT(data, '$.messageid') = ?", first, msgid).Scan(&tasks)
	assert.Equal(t, int64(1), tasks)

	// Referring again with no reason within moments is a double-click: no new message or task.
	scReferOK(t, w.modToken, w.chat, "")
	var bare int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ? AND body = 'Referred again'", first).Scan(&bare)
	assert.Equal(t, int64(0), bare)

	// Once the case is older than the window the same bare referral is a deliberate nudge.
	database.DBConn.Exec("UPDATE support_cases SET created_at = NOW() - INTERVAL 3 MINUTE WHERE id = ?", first)
	scReferOK(t, w.modToken, w.chat, "")
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ? AND body = 'Referred again'", first).Scan(&bare)
	assert.Equal(t, int64(1), bare)
}

func TestSupportCaseDoubleClickWithSameReasonAddsNothing(t *testing.T) {
	w := scSetup(t, "scdbl2")
	first := scReferOK(t, w.modToken, w.chat, "same words")
	assert.Equal(t, first, scReferOK(t, w.modToken, w.chat, "same words"))
	var msgs, tasks int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ?", first).Scan(&msgs)
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'support_case_message' AND JSON_EXTRACT(data, '$.caseid') = ?", first).Scan(&tasks)
	assert.Equal(t, int64(0), msgs)
	assert.Equal(t, int64(0), tasks)

	// Past the window the same reason is added.
	database.DBConn.Exec("UPDATE support_cases SET created_at = NOW() - INTERVAL 3 MINUTE WHERE id = ?", first)
	scReferOK(t, w.modToken, w.chat, "same words")
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ?", first).Scan(&msgs)
	assert.Equal(t, int64(1), msgs)
}

func TestSupportCaseMessagesAreRateLimited(t *testing.T) {
	w := scSetup(t, "scrate")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	path := fmt.Sprintf("/api/supportcases/%d/messages", caseid)
	for i := 0; i < 10; i++ {
		status, _ := scCall(t, "POST", path, w.modToken, map[string]interface{}{"body": fmt.Sprintf("m%d", i)})
		assert.Equal(t, 200, status)
	}
	status, raw := scCall(t, "POST", path, w.modToken, map[string]interface{}{"body": "one too many"})
	assert.Equal(t, 429, status)
	assert.Contains(t, string(raw), "Too many replies, please wait a little")
	var msgs int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ?", caseid).Scan(&msgs)
	assert.Equal(t, int64(10), msgs)

	// Another author is not held up by it, and the hour rolls on.
	_, sToken := scSupport(t, "scrate_s")
	status, _ = scCall(t, "POST", path, sToken, map[string]interface{}{"body": "support reply"})
	assert.Equal(t, 200, status)
	database.DBConn.Exec("UPDATE support_case_messages SET created_at = NOW() - INTERVAL 2 HOUR WHERE caseid = ?", caseid)
	status, _ = scCall(t, "POST", path, w.modToken, map[string]interface{}{"body": "later"})
	assert.Equal(t, 200, status)
}

func TestSupportCaseGroupChatIsRefused(t *testing.T) {
	w := scSetup(t, "scgroupchat")
	res := database.DBConn.Exec("INSERT INTO chat_rooms (chattype, groupid, latestmessage) VALUES ('Group', ?, NOW())", w.group)
	assert.NoError(t, res.Error)
	var gchat uint64
	database.DBConn.Raw("SELECT id FROM chat_rooms WHERE chattype = 'Group' AND groupid = ? ORDER BY id DESC LIMIT 1", w.group).Scan(&gchat)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM chat_rooms WHERE id = ?", gchat) })
	status, _ := scRefer(t, w.modToken, gchat, "")
	assert.Equal(t, 400, status)
	var cases int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_cases WHERE chatid = ?", gchat).Scan(&cases)
	assert.Equal(t, int64(0), cases)
}

func TestSupportCaseListPagingLimitStatusAndOrder(t *testing.T) {
	_, sToken := scSupport(t, "scpage_s")
	var ids []uint64
	for i := 0; i < 3; i++ {
		w := scSetup(t, fmt.Sprintf("scpage%d", i))
		ids = append(ids, scReferOK(t, w.modToken, w.chat, ""))
	}
	idsOf := func(path string) []float64 {
		status, raw := scCall(t, "GET", path, sToken, nil)
		assert.Equal(t, 200, status)
		var rows []map[string]interface{}
		assert.NoError(t, json.Unmarshal(raw, &rows), string(raw))
		out := []float64{}
		for _, r := range rows {
			out = append(out, r["id"].(float64))
		}
		return out
	}

	// Newest two, newest first.
	assert.Equal(t, []float64{float64(ids[2]), float64(ids[1])}, idsOf("/api/supportcases?limit=2"))
	// Strictly older than the middle one.
	older := idsOf(fmt.Sprintf("/api/supportcases?before=%d&limit=1", ids[1]))
	assert.Equal(t, []float64{float64(ids[0])}, older)

	// The default shows Open only: a Handled case drops out of it but stays in Handled and All.
	database.DBConn.Exec("UPDATE support_cases SET status = 'Handled', open_key = NULL WHERE id = ?", ids[2])
	assert.Equal(t, []float64{float64(ids[1]), float64(ids[0])}, idsOf("/api/supportcases?limit=2"))
	assert.Contains(t, idsOf("/api/supportcases?status=Handled&limit=100"), float64(ids[2]))
	assert.Contains(t, idsOf("/api/supportcases?status=All&limit=100"), float64(ids[2]))
	assert.NotContains(t, idsOf("/api/supportcases?status=Open&limit=100"), float64(ids[2]))

	// An oversized limit is capped, not obeyed: pad with more than 100 Handled cases.
	marker := uniquePrefix("scpagepad")
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM support_cases WHERE reason = ?", marker) })
	for i := 0; i < 105; i++ {
		assert.NoError(t, database.DBConn.Exec("INSERT INTO support_cases (source, status, firstpass_status, reason) VALUES ('Referral', 'Handled', 'Done', ?)", marker).Error)
	}
	assert.LessOrEqual(t, len(idsOf("/api/supportcases?status=All&limit=1000")), 100)
}

func TestSupportCaseReferrerWhoIsNotSupportCannotPatch(t *testing.T) {
	w := scSetup(t, "scpatchref")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	status, _ := scCall(t, "PATCH", "/api/supportcases", w.modToken, map[string]interface{}{"id": caseid, "status": "Handled"})
	assert.Equal(t, 404, status)
	var st string
	database.DBConn.Raw("SELECT status FROM support_cases WHERE id = ?", caseid).Scan(&st)
	assert.Equal(t, "Open", st)
}

func TestSupportCaseReferAgainAfterHandledIsNewCase(t *testing.T) {
	w := scSetup(t, "scagain")
	first := scReferOK(t, w.modToken, w.chat, "")
	_, sToken := scSupport(t, "scagain_s")
	status, _ := scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": first, "status": "Handled"})
	assert.Equal(t, 200, status)
	second := scReferOK(t, w.modToken, w.chat, "")
	assert.NotEqual(t, first, second)
}

func TestSupportCaseSecondReferrerGetsOwnCaseAndCannotSeeFirst(t *testing.T) {
	w := scSetup(t, "scsecond")
	mod2 := CreateTestUser(t, uniquePrefix("scsecond_mod2"), "Moderator")
	CreateTestMembership(t, mod2, w.group, "Moderator")
	_, token2 := CreateTestSession(t, mod2)

	first := scReferOK(t, w.modToken, w.chat, "mine")
	second := scReferOK(t, token2, w.chat, "theirs")
	assert.NotEqual(t, first, second)

	status, _ := scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", first), token2, nil)
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", first), token2, map[string]interface{}{"body": "hi"})
	assert.Equal(t, 404, status)

	status, raw := scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", second), token2, nil)
	assert.Equal(t, 200, status)
	assert.Equal(t, "theirs", scMap(t, raw)["reason"])

	// And the first moderator's case does not mention the second.
	_, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", first), w.modToken, nil)
	assert.NotContains(t, string(raw), "theirs")
}

func TestSupportCaseReferrerViewLacksFirstPass(t *testing.T) {
	w := scSetup(t, "scview")
	caseid := scReferOK(t, w.modToken, w.chat, "my reason")
	_, sToken := scSupport(t, "scview_s")
	supportID, sToken2 := scSupport(t, "scview_s2")
	_ = sToken2
	database.DBConn.Exec("UPDATE support_cases SET brief = ?, firstpass_error = 'SECRET-ERROR', firstpass_runid = NULL, handledby = ?, handled_at = NOW(), firstpass_status = 'Done' WHERE id = ?",
		`{"version":2,"question":"SECRET-SUMMARY","classification":"Bug","confidence":{"level":"High","reason":"x"}}`, supportID, caseid)
	status, _ := scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", caseid), sToken, map[string]interface{}{"body": "Support says hello", "draftused": "Unchanged"})
	assert.Equal(t, 200, status)

	status, raw := scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", caseid), w.modToken, nil)
	assert.Equal(t, 200, status)
	m := scMap(t, raw)

	keys := map[string]bool{}
	for k := range m {
		keys[k] = true
	}
	assert.Equal(t, map[string]bool{"viewer": true, "id": true, "created": true, "status": true, "chatid": true, "reason": true, "messages": true}, keys)
	assert.Equal(t, "Referrer", m["viewer"])
	assert.Equal(t, "my reason", m["reason"])
	for _, banned := range []string{"brief", "member", "userid", "referrer", "firstpass_status", "firstpass_error", "firstpass_runid", "handledby", "handled_at", "source", "updated"} {
		_, present := m[banned]
		assert.False(t, present, banned)
	}
	for _, secret := range []string{"SECRET-SUMMARY", "SECRET-ERROR", "firstpass", "classification"} {
		assert.NotContains(t, string(raw), secret)
	}

	msgs := m["messages"].([]interface{})
	assert.Len(t, msgs, 1)
	msg := msgs[0].(map[string]interface{})
	assert.Equal(t, "Support", msg["role"])
	assert.Equal(t, "Support says hello", msg["body"])
	assert.Equal(t, "No", msg["draftused"], "whether a draft was used is first pass information")
	for _, banned := range []string{"handledby", "brief", "classification", "firstpass_status"} {
		_, present := msg[banned]
		assert.False(t, present, "message must not carry "+banned)
	}
	assert.NotContains(t, string(raw), "handledby", "the referrer view names no handler")
}

func TestSupportCaseNonReferrerModeratorGets404(t *testing.T) {
	w := scSetup(t, "scother")
	caseid := scReferOK(t, w.modToken, w.chat, "")

	other := CreateTestUser(t, uniquePrefix("scother_o"), "User")
	CreateTestMembership(t, other, w.group, "Moderator")
	_, otherToken := CreateTestSession(t, other)

	path := fmt.Sprintf("/api/supportcases/%d", caseid)
	status, raw := scCall(t, "GET", path, otherToken, nil)
	assert.Equal(t, 404, status)
	assert.NotContains(t, string(raw), "caseid")
	status, _ = scCall(t, "POST", path+"/messages", otherToken, map[string]interface{}{"body": "x"})
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "PATCH", "/api/supportcases", otherToken, map[string]interface{}{"id": caseid, "status": "Handled"})
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "GET", "/api/supportcases", otherToken, nil)
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "GET", fmt.Sprintf("/api/supportcases?chatid=%d", w.chat), otherToken, nil)
	assert.Equal(t, 404, status)

	var st string
	database.DBConn.Raw("SELECT status FROM support_cases WHERE id = ?", caseid).Row().Scan(&st)
	assert.Equal(t, "Open", st, "a refused PATCH must not change the case")
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ?", caseid).Scan(&n)
	assert.Equal(t, int64(0), n, "a refused POST must not write")
}

func TestSupportCaseNotLoggedIn(t *testing.T) {
	for _, c := range []struct{ method, path string }{
		{"GET", "/api/supportcases"},
		{"GET", "/api/supportcases/1"},
		{"POST", "/api/supportcases/1/messages"},
		{"PATCH", "/api/supportcases"},
	} {
		status, _ := scCall(t, c.method, c.path, "", map[string]interface{}{"body": "x", "id": 1, "status": "Handled"})
		assert.Equal(t, 401, status, c.method+" "+c.path)
	}
}

func TestSupportCaseReferrerWhoLostTheChatGets404(t *testing.T) {
	w := scSetup(t, "sclost")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	database.DBConn.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", w.mod, w.group)

	path := fmt.Sprintf("/api/supportcases/%d", caseid)
	status, _ := scCall(t, "GET", path, w.modToken, nil)
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "POST", path+"/messages", w.modToken, map[string]interface{}{"body": "x"})
	assert.Equal(t, 404, status)
}

func TestSupportCaseSupportListGetPatch(t *testing.T) {
	w := scSetup(t, "sclist")
	caseid := scReferOK(t, w.modToken, w.chat, "please look")
	supportID, sToken := scSupport(t, "sclist_s")

	long := strings.Repeat("a", 300)
	database.DBConn.Exec("UPDATE support_cases SET brief = ?, firstpass_status = 'Done' WHERE id = ?",
		fmt.Sprintf(`{"version":2,"question":"%s","classification":"Usage","confidence":{"level":"Medium","reason":"r"}}`, long), caseid)
	status, _ := scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", caseid), w.modToken, map[string]interface{}{"body": "ping"})
	assert.Equal(t, 200, status)

	status, raw := scCall(t, "GET", fmt.Sprintf("/api/supportcases?chatid=%d", w.chat), sToken, nil)
	assert.Equal(t, 200, status)
	var rows []map[string]interface{}
	assert.NoError(t, json.Unmarshal(raw, &rows), string(raw))
	assert.Len(t, rows, 1)
	row := rows[0]
	assert.Equal(t, float64(caseid), row["id"])
	assert.Equal(t, "Open", row["status"])
	assert.Equal(t, float64(w.chat), row["chatid"])
	assert.Equal(t, "Usage", row["classification"])
	assert.Equal(t, "Medium", row["confidence"])
	assert.Equal(t, strings.Repeat("a", 200), row["summary"])
	assert.Equal(t, "Done", row["firstpass_status"])
	assert.Equal(t, float64(1), row["messages"])
	assert.Equal(t, float64(w.member), row["member"].(map[string]interface{})["id"])
	assert.Contains(t, row["referrer"].(map[string]interface{})["displayname"], "Test")
	assert.NotEmpty(t, row["created"])
	assert.NotEmpty(t, row["lastactivity"])

	// The status filter and paging.
	status, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases?status=Handled&chatid=%d", w.chat), sToken, nil)
	assert.Equal(t, 200, status)
	assert.Equal(t, "[]", strings.TrimSpace(string(raw)))
	status, _ = scCall(t, "GET", "/api/supportcases?status=Bogus", sToken, nil)
	assert.Equal(t, 400, status)
	status, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases?status=All&before=%d&limit=1", caseid), sToken, nil)
	assert.Equal(t, 200, status)
	json.Unmarshal(raw, &rows)
	for _, r := range rows {
		assert.Less(t, r["id"].(float64), float64(caseid))
	}
	assert.LessOrEqual(t, len(rows), 1)

	// Full view.
	status, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", caseid), sToken, nil)
	assert.Equal(t, 200, status)
	m := scMap(t, raw)
	assert.Equal(t, "Support", m["viewer"])
	assert.Equal(t, "Referral", m["source"])
	assert.Equal(t, "please look", m["reason"])
	assert.Equal(t, "Usage", m["brief"].(map[string]interface{})["classification"])
	assert.Equal(t, float64(w.member), m["member"].(map[string]interface{})["id"])
	assert.Equal(t, float64(w.mod), m["referrer"].(map[string]interface{})["id"])
	assert.Nil(t, m["handledby"])
	assert.Nil(t, m["firstpass_error"])
	assert.Len(t, m["messages"].([]interface{}), 1)

	// Handle, then reopen.
	status, _ = scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": caseid, "status": "Handled"})
	assert.Equal(t, 200, status)
	var st string
	var openKey *string
	var by *uint64
	database.DBConn.Raw("SELECT status, open_key, handledby FROM support_cases WHERE id = ?", caseid).Row().Scan(&st, &openKey, &by)
	assert.Equal(t, "Handled", st)
	assert.Nil(t, openKey)
	assert.Equal(t, supportID, *by)
	_, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", caseid), sToken, nil)
	m = scMap(t, raw)
	assert.Equal(t, float64(supportID), m["handledby"].(map[string]interface{})["id"])
	assert.NotNil(t, m["handled_at"])

	status, _ = scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": caseid, "status": "Open"})
	assert.Equal(t, 200, status)
	database.DBConn.Raw("SELECT status, open_key, handledby FROM support_cases WHERE id = ?", caseid).Row().Scan(&st, &openKey, &by)
	assert.Equal(t, "Open", st)
	assert.Equal(t, fmt.Sprintf("%d-%d", w.chat, w.mod), *openKey)
	assert.Nil(t, by)

	// Bad requests and a missing case.
	status, _ = scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": caseid, "status": "Closed"})
	assert.Equal(t, 400, status)
	status, _ = scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"status": "Open"})
	assert.Equal(t, 400, status)
	status, _ = scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": 999999999, "status": "Open"})
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "GET", "/api/supportcases/999999999", sToken, nil)
	assert.Equal(t, 404, status)
	status, _ = scCall(t, "GET", "/api/supportcases/abc", sToken, nil)
	assert.Equal(t, 404, status)
}

func TestSupportCaseMessageRoles(t *testing.T) {
	w := scSetup(t, "scroles")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	supportID, sToken := scSupport(t, "scroles_s")
	path := fmt.Sprintf("/api/supportcases/%d/messages", caseid)

	roleOf := func(msgid float64) (string, string, uint64) {
		var role, draft string
		var uid uint64
		database.DBConn.Raw("SELECT role, draftused, userid FROM support_case_messages WHERE id = ?", uint64(msgid)).Row().Scan(&role, &draft, &uid)
		return role, draft, uid
	}
	taskFor := func(msgid float64) int64 {
		var n int64
		database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'support_case_message' AND JSON_EXTRACT(data, '$.caseid') = ? AND JSON_EXTRACT(data, '$.messageid') = ?", caseid, uint64(msgid)).Scan(&n)
		return n
	}

	status, raw := scCall(t, "POST", path, sToken, map[string]interface{}{"body": "Here is the answer", "draftused": "Edited"})
	assert.Equal(t, 200, status)
	id := scMap(t, raw)["id"].(float64)
	role, draft, uid := roleOf(id)
	assert.Equal(t, "Support", role)
	assert.Equal(t, "Edited", draft)
	assert.Equal(t, supportID, uid)
	assert.Equal(t, int64(1), taskFor(id))

	// The referrer's own draftused is ignored: only Support uses a draft.
	status, raw = scCall(t, "POST", path, w.modToken, map[string]interface{}{"body": "Thanks", "draftused": "Unchanged"})
	assert.Equal(t, 200, status)
	id = scMap(t, raw)["id"].(float64)
	role, draft, _ = roleOf(id)
	assert.Equal(t, "Referrer", role)
	assert.Equal(t, "No", draft)
	assert.Equal(t, int64(1), taskFor(id))

	// Validation.
	status, _ = scCall(t, "POST", path, sToken, map[string]interface{}{"body": "   "})
	assert.Equal(t, 400, status)
	status, _ = scCall(t, "POST", path, sToken, map[string]interface{}{"body": strings.Repeat("x", 10001)})
	assert.Equal(t, 400, status)
	status, _ = scCall(t, "POST", path, sToken, map[string]interface{}{"body": "x", "draftused": "Sometimes"})
	assert.Equal(t, 400, status)
	status, _ = scCall(t, "POST", path, sToken, map[string]interface{}{"body": strings.Repeat("x", 10000)})
	assert.Equal(t, 200, status)
	status, _ = scCall(t, "POST", "/api/supportcases/999999999/messages", sToken, map[string]interface{}{"body": "x"})
	assert.Equal(t, 404, status)

	// The thread is in order, with the author's name.
	_, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", caseid), sToken, nil)
	msgs := scMap(t, raw)["messages"].([]interface{})
	assert.Len(t, msgs, 3)
	assert.Equal(t, "Support", msgs[0].(map[string]interface{})["role"])
	assert.Equal(t, "Edited", msgs[0].(map[string]interface{})["draftused"])
	assert.Equal(t, "Referrer", msgs[1].(map[string]interface{})["role"])
	assert.NotEmpty(t, msgs[1].(map[string]interface{})["displayname"])
}

func TestSupportCaseSupportUserWhoIsTheReferrerPostsAsReferrer(t *testing.T) {
	w := scSetup(t, "scself")
	supportID, sToken := scSupport(t, "scself_s")
	caseid := scReferOK(t, sToken, w.chat, "I am Support and I referred this")

	status, raw := scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", caseid), sToken, map[string]interface{}{"body": "note to self"})
	assert.Equal(t, 200, status)
	var role string
	database.DBConn.Raw("SELECT role FROM support_case_messages WHERE id = ?", uint64(scMap(t, raw)["id"].(float64))).Row().Scan(&role)
	assert.Equal(t, "Referrer", role)

	// They still get the full view, being Support.
	_, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", caseid), sToken, nil)
	assert.Equal(t, "Support", scMap(t, raw)["viewer"])

	// Another Support user posts as Support.
	_, s2 := scSupport(t, "scself_s2")
	_, raw = scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", caseid), s2, map[string]interface{}{"body": "reply"})
	database.DBConn.Raw("SELECT role FROM support_case_messages WHERE id = ?", uint64(scMap(t, raw)["id"].(float64))).Row().Scan(&role)
	assert.Equal(t, "Support", role)
	_ = supportID
}

func TestSupportCaseReferrerPostReopensHandledCase(t *testing.T) {
	w := scSetup(t, "screopen")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	_, sToken := scSupport(t, "screopen_s")
	scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": caseid, "status": "Handled"})

	// Support replying does not reopen it.
	status, _ := scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", caseid), sToken, map[string]interface{}{"body": "closing note"})
	assert.Equal(t, 200, status)
	var st string
	database.DBConn.Raw("SELECT status FROM support_cases WHERE id = ?", caseid).Row().Scan(&st)
	assert.Equal(t, "Handled", st)

	status, _ = scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", caseid), w.modToken, map[string]interface{}{"body": "one more thing"})
	assert.Equal(t, 200, status)
	var openKey *string
	var by *uint64
	database.DBConn.Raw("SELECT status, open_key, handledby FROM support_cases WHERE id = ?", caseid).Row().Scan(&st, &openKey, &by)
	assert.Equal(t, "Open", st)
	assert.Equal(t, fmt.Sprintf("%d-%d", w.chat, w.mod), *openKey)
	assert.Nil(t, by)
}

func TestSupportCaseReopenConflictIs409(t *testing.T) {
	w := scSetup(t, "scconflict")
	first := scReferOK(t, w.modToken, w.chat, "")
	_, sToken := scSupport(t, "scconflict_s")
	scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": first, "status": "Handled"})
	second := scReferOK(t, w.modToken, w.chat, "")
	assert.NotEqual(t, first, second)

	// The referrer posting on the old, Handled case cannot reopen it beside the open one.
	var before int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ?", first).Scan(&before)
	status, _ := scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", first), w.modToken, map[string]interface{}{"body": "late"})
	assert.Equal(t, 409, status)
	var after int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ?", first).Scan(&after)
	assert.Equal(t, before, after, "a refused reopen must not leave the message behind")
	var st string
	database.DBConn.Raw("SELECT status FROM support_cases WHERE id = ?", first).Row().Scan(&st)
	assert.Equal(t, "Handled", st)

	// Support reopening it by PATCH is refused the same way.
	status, _ = scCall(t, "PATCH", "/api/supportcases", sToken, map[string]interface{}{"id": first, "status": "Open"})
	assert.Equal(t, 409, status)
}

func TestSupportCaseMemberChoice(t *testing.T) {
	// User2User: the participant who is not the referrer, in either position.
	prefix := uniquePrefix("scmember")
	a := CreateTestUser(t, prefix+"_a", "Moderator")
	b := CreateTestUser(t, prefix+"_b", "Moderator")
	chat := CreateTestChatRoom(t, a, &b, nil, "User2User")
	_, tokenA := CreateTestSession(t, a)
	_, tokenB := CreateTestSession(t, b)

	memberOf := func(caseid uint64) *uint64 {
		var m *uint64
		database.DBConn.Raw("SELECT userid FROM support_cases WHERE id = ?", caseid).Row().Scan(&m)
		return m
	}
	assert.Equal(t, b, *memberOf(scReferOK(t, tokenA, chat, "")))
	assert.Equal(t, a, *memberOf(scReferOK(t, tokenB, chat, "")))

	// A referrer who is neither participant (Support) has no single member.
	_, sToken := scSupport(t, "scmember_s")
	assert.Nil(t, memberOf(scReferOK(t, sToken, chat, "")))

	// Mod2Mod has none either.
	w := scSetup(t, "scmember2")
	m2m := CreateTestChatRoom(t, w.mod, nil, &w.group, "Mod2Mod")
	assert.Nil(t, memberOf(scReferOK(t, w.modToken, m2m, "")))

	// The list shows such a case with no member.
	caseid := scReferOK(t, sToken, m2m, "")
	_, raw := scCall(t, "GET", fmt.Sprintf("/api/supportcases?chatid=%d", m2m), sToken, nil)
	var rows []map[string]interface{}
	json.Unmarshal(raw, &rows)
	found := false
	for _, r := range rows {
		if r["id"].(float64) == float64(caseid) {
			found = true
			assert.Nil(t, r["member"])
		}
	}
	assert.True(t, found)
}

func TestSupportCaseBriefThatIsNotJSONDoesNotBreakTheList(t *testing.T) {
	w := scSetup(t, "scbadbrief")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	_, sToken := scSupport(t, "scbadbrief_s")
	database.DBConn.Exec("UPDATE support_cases SET brief = 'not json' WHERE id = ?", caseid)

	status, raw := scCall(t, "GET", fmt.Sprintf("/api/supportcases?chatid=%d", w.chat), sToken, nil)
	assert.Equal(t, 200, status, string(raw))
	status, raw = scCall(t, "GET", fmt.Sprintf("/api/supportcases/%d", caseid), sToken, nil)
	assert.Equal(t, 200, status, string(raw))
	assert.Nil(t, scMap(t, raw)["brief"])
}

func TestSupportAIRunsCarryKind(t *testing.T) {
	_, token := scSupport(t, "saikind")
	id := recordSupportAIRun(t, token, map[string]interface{}{"query": "kind question " + uniquePrefix("k")})
	database.DBConn.Exec("UPDATE support_ai_runs SET kind = 'FirstPass' WHERE id = ?", id)
	other := recordSupportAIRun(t, token, map[string]interface{}{"query": "interactive " + uniquePrefix("k2")})

	_, raw := scCall(t, "GET", "/api/supportai/runs?limit=20", token, nil)
	var rows []map[string]interface{}
	assert.NoError(t, json.Unmarshal(raw, &rows))
	seen := map[float64]string{}
	for _, r := range rows {
		seen[r["id"].(float64)], _ = r["kind"].(string)
	}
	assert.Equal(t, "FirstPass", seen[float64(id)])
	assert.Equal(t, "Interactive", seen[float64(other)])

	_, raw = scCall(t, "GET", fmt.Sprintf("/api/supportai/runs/%d", id), token, nil)
	assert.Equal(t, "FirstPass", scMap(t, raw)["kind"])
}

func TestSupportCaseRepeatReferralReasonReachesPendingFirstPass(t *testing.T) {
	w := scSetup(t, "screpend")
	caseid := scReferOK(t, w.modToken, w.chat, "first reason")
	database.DBConn.Exec("UPDATE support_cases SET created_at = NOW() - INTERVAL 3 MINUTE WHERE id = ?", caseid)

	// Pending: the first pass reads support_cases.reason, so it must now be the new reason.
	assert.Equal(t, caseid, scReferOK(t, w.modToken, w.chat, "second reason"))
	var reason string
	database.DBConn.Raw("SELECT reason FROM support_cases WHERE id = ?", caseid).Row().Scan(&reason)
	assert.Equal(t, "second reason", reason)

	// A bare nudge keeps the reason.
	scReferOK(t, w.modToken, w.chat, "")
	database.DBConn.Raw("SELECT reason FROM support_cases WHERE id = ?", caseid).Row().Scan(&reason)
	assert.Equal(t, "second reason", reason)

	// Done: the brief stands, the reason is unchanged and the message carries the new one.
	database.DBConn.Exec("UPDATE support_cases SET firstpass_status = 'Done' WHERE id = ?", caseid)
	scReferOK(t, w.modToken, w.chat, "third reason")
	database.DBConn.Raw("SELECT reason FROM support_cases WHERE id = ?", caseid).Row().Scan(&reason)
	assert.Equal(t, "second reason", reason)
	var msgs int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_case_messages WHERE caseid = ? AND body = 'Referred again: third reason'", caseid).Scan(&msgs)
	assert.Equal(t, int64(1), msgs)
}
