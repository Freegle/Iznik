package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The "I'm interested" button: GET and POST /message/:id/interested and GET /message/interested.
// See message/interested.go.

type interestedFixture struct {
	posterID uint64
	userID   uint64
	msgID    uint64
	token    string
}

func newInterestedFixture(t *testing.T, name string, arm string) interestedFixture {
	prefix := uniquePrefix(name)
	groupID := CreateTestGroup(t, prefix)
	f := interestedFixture{}
	f.posterID = CreateTestUser(t, prefix+"_poster", "User")
	f.userID = CreateTestUser(t, prefix+"_member", "User")
	f.msgID = CreateTestMessage(t, f.posterID, groupID, "OFFER: lamp ("+prefix+")", 51.5, -0.1)
	_, f.token = CreateTestSession(t, f.userID)

	if arm == "" {
		writeReplyConfirmConfig(t, "", "", false)
	} else {
		writeReplyConfirmConfig(t, `{"interested-button":{"arms":{"`+arm+`":[0,99]},"adopted":true}}`, "1", true)
	}

	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM messages_interested WHERE userid IN (?, ?)", f.userID, f.posterID)
		database.DBConn.Exec("DELETE FROM ux_experiment_exposures WHERE experiment = 'interested-button' AND userid IN (?, ?)", f.userID, f.posterID)
		database.DBConn.Exec("UPDATE users SET tnuserid = NULL WHERE id IN (?, ?)", f.userID, f.posterID)
	})

	return f
}

func interestedCall(t *testing.T, method string, path string, token string, body string, headers map[string]string) (int, map[string]interface{}, []interface{}) {
	t.Helper()
	url := path
	if token != "" {
		url += "?jwt=" + token
	}

	var req *http.Request
	if body != "" {
		req = httptest.NewRequest(method, url, bytes.NewBufferString(body))
		req.Header.Set("Content-Type", "application/json")
	} else {
		req = httptest.NewRequest(method, url, nil)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}

	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)

	var obj map[string]interface{}
	var arr []interface{}
	raw := new(bytes.Buffer)
	_, _ = raw.ReadFrom(resp.Body)
	if json.Unmarshal(raw.Bytes(), &obj) != nil {
		if json.Unmarshal(raw.Bytes(), &arr) != nil {
			// The test app answers an error with its text alone.
			obj = map[string]interface{}{"message": raw.String()}
		}
	}

	return resp.StatusCode, obj, arr
}

func (f interestedFixture) state(t *testing.T, headers map[string]string) map[string]interface{} {
	t.Helper()
	code, obj, _ := interestedCall(t, "GET", fmt.Sprintf("/api/message/%d/interested", f.msgID), f.token, "", headers)
	require.Equal(t, 200, code)

	return obj
}

func (f interestedFixture) set(t *testing.T, value bool) (int, map[string]interface{}) {
	t.Helper()
	code, obj, _ := interestedCall(t, "POST", fmt.Sprintf("/api/message/%d/interested", f.msgID), f.token,
		fmt.Sprintf(`{"interested": %t}`, value), nil)

	return code, obj
}

func interestedExposures(t *testing.T, userid uint64) []map[string]interface{} {
	var rows []map[string]interface{}
	database.DBConn.Raw("SELECT * FROM ux_experiment_exposures WHERE experiment = 'interested-button' AND userid = ?", userid).Scan(&rows)

	return rows
}

func TestInterested_RefusesAnonymousRequests(t *testing.T) {
	f := newInterestedFixture(t, "intanon", "button")

	for _, c := range []struct{ method, path, body string }{
		{"GET", fmt.Sprintf("/api/message/%d/interested", f.msgID), ""},
		{"POST", fmt.Sprintf("/api/message/%d/interested", f.msgID), `{"interested":true}`},
		{"GET", "/api/message/interested", ""},
	} {
		code, obj, _ := interestedCall(t, c.method, c.path, "", c.body, nil)
		assert.Equal(t, 401, code, c.method+" "+c.path)
		assert.Equal(t, "Not logged in", obj["message"])
	}

	assert.Empty(t, interestedExposures(t, f.userID))
}

func TestInterested_ButtonArmIsOfferedAndExposureWritten(t *testing.T) {
	f := newInterestedFixture(t, "intbutton", "button")

	st := f.state(t, nil)
	assert.Equal(t, "button", st["arm"])
	assert.Equal(t, true, st["eligible"])
	assert.Equal(t, false, st["interested"])

	rows := interestedExposures(t, f.userID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "button", rows[0]["arm"])
		assert.Equal(t, "view", rows[0]["subject_type"])
		assert.EqualValues(t, f.msgID, rows[0]["subject_id"])
	}

	// A second look leaves the one row.
	f.state(t, nil)
	assert.Len(t, interestedExposures(t, f.userID), 1)
}

func TestInterested_ControlArmWritesExposureWithArmControl(t *testing.T) {
	f := newInterestedFixture(t, "intcontrol", "control")

	st := f.state(t, nil)
	assert.Equal(t, "control", st["arm"])
	assert.Equal(t, true, st["eligible"])

	rows := interestedExposures(t, f.userID)
	if assert.Len(t, rows, 1) {
		assert.Equal(t, "control", rows[0]["arm"])
	}
}

func TestInterested_SwitchOffGivesNoArmAndNoRow(t *testing.T) {
	f := newInterestedFixture(t, "intoff", "")

	st := f.state(t, nil)
	assert.Equal(t, "", st["arm"])
	assert.Equal(t, true, st["eligible"])
	assert.Empty(t, interestedExposures(t, f.userID))

	// The experiment is configured but the global switch is not 1.
	writeReplyConfirmConfig(t, `{"interested-button":{"arms":{"button":[0,99]},"adopted":true}}`, "0", true)
	ux.FlushServerArmCache()
	st = f.state(t, nil)
	assert.Equal(t, "", st["arm"])
	assert.Empty(t, interestedExposures(t, f.userID))
}

func TestInterested_NotEligibleCases(t *testing.T) {
	t.Run("own post", func(t *testing.T) {
		f := newInterestedFixture(t, "intown", "button")
		_, f.token = CreateTestSession(t, f.posterID)
		st := f.state(t, nil)
		assert.Equal(t, false, st["eligible"])
		assert.Equal(t, "", st["arm"])
		assert.Empty(t, interestedExposures(t, f.posterID))

		code, _ := f.set(t, true)
		assert.Equal(t, 400, code)
	})

	t.Run("outcome", func(t *testing.T) {
		f := newInterestedFixture(t, "intoutcome", "button")
		database.DBConn.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", f.msgID)
		t.Cleanup(func() { database.DBConn.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", f.msgID) })

		st := f.state(t, nil)
		assert.Equal(t, false, st["eligible"])
		assert.Equal(t, "", st["arm"])
		assert.Empty(t, interestedExposures(t, f.userID))
	})

	t.Run("deleted", func(t *testing.T) {
		f := newInterestedFixture(t, "intdeleted", "button")
		database.DBConn.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", f.msgID)

		assert.Equal(t, false, f.state(t, nil)["eligible"])
	})

	t.Run("withdrawn from every community", func(t *testing.T) {
		f := newInterestedFixture(t, "intwithdrawn", "button")
		database.DBConn.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ?", f.msgID)

		assert.Equal(t, false, f.state(t, nil)["eligible"])
	})

	t.Run("not approved", func(t *testing.T) {
		f := newInterestedFixture(t, "intpending", "button")
		database.DBConn.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", f.msgID)

		assert.Equal(t, false, f.state(t, nil)["eligible"])
	})

	t.Run("not an offer or wanted", func(t *testing.T) {
		f := newInterestedFixture(t, "intother", "button")
		database.DBConn.Exec("UPDATE messages SET type = 'Other' WHERE id = ?", f.msgID)

		assert.Equal(t, false, f.state(t, nil)["eligible"])
	})

	t.Run("missing post", func(t *testing.T) {
		f := newInterestedFixture(t, "intmissing", "button")
		f.msgID = 4000000000
		assert.Equal(t, false, f.state(t, nil)["eligible"])
	})

	t.Run("member is TrashNothing", func(t *testing.T) {
		f := newInterestedFixture(t, "inttnme", "button")
		database.DBConn.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", f.userID, f.userID)

		assert.Equal(t, false, f.state(t, nil)["eligible"])
		code, _ := f.set(t, true)
		assert.Equal(t, 400, code)
	})

	t.Run("poster is TrashNothing", func(t *testing.T) {
		f := newInterestedFixture(t, "inttnposter", "button")
		database.DBConn.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", f.posterID, f.posterID)

		assert.Equal(t, false, f.state(t, nil)["eligible"])
		assert.Empty(t, interestedExposures(t, f.userID))
	})

	t.Run("from the app", func(t *testing.T) {
		f := newInterestedFixture(t, "intapp", "button")

		for _, h := range []map[string]string{
			{"Origin": "https://localhost"},
			{"Origin": "capacitor://localhost"},
		} {
			st := f.state(t, h)
			assert.Equal(t, false, st["eligible"])
			assert.Equal(t, "", st["arm"])
		}
		assert.Empty(t, interestedExposures(t, f.userID))
	})
}

func TestInterested_MarkAndRemove(t *testing.T) {
	f := newInterestedFixture(t, "intmark", "button")

	code, obj := f.set(t, true)
	assert.Equal(t, 200, code)
	assert.Equal(t, true, obj["interested"])
	assert.Equal(t, true, f.state(t, nil)["interested"])

	// Marking twice is harmless and keeps one row.
	code, _ = f.set(t, true)
	assert.Equal(t, 200, code)
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_interested WHERE userid = ? AND msgid = ?", f.userID, f.msgID).Scan(&n)
	assert.EqualValues(t, 1, n)

	code, obj = f.set(t, false)
	assert.Equal(t, 200, code)
	assert.Equal(t, false, obj["interested"])
	assert.Equal(t, false, f.state(t, nil)["interested"])

	// Removing what is not there is fine.
	code, _ = f.set(t, false)
	assert.Equal(t, 200, code)
}

func TestInterested_MarkWorksWhateverTheArm(t *testing.T) {
	f := newInterestedFixture(t, "intnoarm", "")

	code, _ := f.set(t, true)
	assert.Equal(t, 200, code)

	// An existing mark shows even when the post is no longer eligible.
	database.DBConn.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", f.msgID)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", f.msgID) })
	st := f.state(t, nil)
	assert.Equal(t, true, st["interested"])
	assert.Equal(t, false, st["eligible"])
}

func TestInterested_BadRequests(t *testing.T) {
	f := newInterestedFixture(t, "intbad", "button")

	code, _, _ := interestedCall(t, "GET", "/api/message/abc/interested", f.token, "", nil)
	assert.Equal(t, 400, code)
	code, _, _ = interestedCall(t, "POST", "/api/message/abc/interested", f.token, `{"interested":true}`, nil)
	assert.Equal(t, 400, code)
	code, _, _ = interestedCall(t, "POST", fmt.Sprintf("/api/message/%d/interested", f.msgID), f.token, `not json`, nil)
	assert.Equal(t, 400, code)
}

func TestInterested_List(t *testing.T) {
	f := newInterestedFixture(t, "intlist", "button")
	groupID := CreateTestGroup(t, uniquePrefix("intlistg"))

	older := CreateTestMessage(t, f.posterID, groupID, "OFFER: chair ("+uniquePrefix("c")+")", 51.5, -0.1)
	repliedMsg := CreateTestMessage(t, f.posterID, groupID, "OFFER: desk ("+uniquePrefix("d")+")", 51.5, -0.1)
	closedMsg := CreateTestMessage(t, f.posterID, groupID, "OFFER: rug ("+uniquePrefix("r")+")", 51.5, -0.1)
	ancient := CreateTestMessage(t, f.posterID, groupID, "OFFER: bed ("+uniquePrefix("b")+")", 51.5, -0.1)

	db := database.DBConn
	db.Exec("INSERT INTO messages_interested (msgid, userid, added, chasedat) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 3 DAY), NULL)", older, f.userID)
	db.Exec("INSERT INTO messages_interested (msgid, userid, added, chasedat) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))", repliedMsg, f.userID)
	db.Exec("INSERT INTO messages_interested (msgid, userid, added) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR))", closedMsg, f.userID)
	db.Exec("INSERT INTO messages_interested (msgid, userid, added) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 40 DAY))", ancient, f.userID)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", closedMsg)
	t.Cleanup(func() { db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", closedMsg) })

	chatID := CreateTestChatRoom(t, f.userID, &f.posterID, nil, "User2User")
	db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, type, refmsgid) VALUES (?, ?, 'Is it available?', NOW(), 'Interested', ?)",
		chatID, f.userID, repliedMsg)

	code, _, arr := interestedCall(t, "GET", "/api/message/interested", f.token, "", nil)
	require.Equal(t, 200, code)
	require.Len(t, arr, 3, "the mark from 40 days ago is left out")

	row := func(i int) map[string]interface{} { return arr[i].(map[string]interface{}) }

	// Newest first.
	assert.EqualValues(t, closedMsg, row(0)["msgid"])
	assert.Equal(t, false, row(0)["open"])
	assert.Equal(t, false, row(0)["replied"])
	assert.Nil(t, row(0)["chasedat"])

	assert.EqualValues(t, repliedMsg, row(1)["msgid"])
	assert.Equal(t, true, row(1)["replied"])
	assert.Equal(t, true, row(1)["open"])
	assert.NotNil(t, row(1)["chasedat"])
	assert.Regexp(t, `^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$`, row(1)["added"])

	assert.EqualValues(t, older, row(2)["msgid"])
	assert.Equal(t, true, row(2)["open"])
	assert.Equal(t, false, row(2)["replied"])
}

func TestInterested_ListIsEmptyArray(t *testing.T) {
	f := newInterestedFixture(t, "intempty", "button")

	code, _, arr := interestedCall(t, "GET", "/api/message/interested", f.token, "", nil)
	assert.Equal(t, 200, code)
	assert.NotNil(t, arr)
	assert.Len(t, arr, 0)
}

func TestInterested_MergeMovesMarksAndKeepsTheKeptAccountsOnAClash(t *testing.T) {
	f := newInterestedFixture(t, "intmerge", "button")
	groupID := CreateTestGroup(t, uniquePrefix("intmergeg"))
	other := CreateTestMessage(t, f.posterID, groupID, "OFFER: sofa ("+uniquePrefix("s")+")", 51.5, -0.1)
	keptID := CreateTestUser(t, uniquePrefix("intmergekept"), "User")
	db := database.DBConn
	t.Cleanup(func() { db.Exec("DELETE FROM messages_interested WHERE userid = ?", keptID) })

	// Both marked f.msgID; only the discarded account marked other.
	db.Exec("INSERT INTO messages_interested (msgid, userid, added) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 2 DAY))", f.msgID, keptID)
	db.Exec("INSERT INTO messages_interested (msgid, userid) VALUES (?, ?)", f.msgID, f.userID)
	db.Exec("INSERT INTO messages_interested (msgid, userid) VALUES (?, ?)", other, f.userID)

	require.NoError(t, user.MergeUsersTx(db, f.userID, keptID, keptID))

	var kept []uint64
	db.Raw("SELECT msgid FROM messages_interested WHERE userid = ? ORDER BY msgid", keptID).Scan(&kept)
	assert.ElementsMatch(t, []uint64{f.msgID, other}, kept)

	var left int64
	db.Raw("SELECT COUNT(*) FROM messages_interested WHERE userid = ?", f.userID).Scan(&left)
	assert.EqualValues(t, 0, left)

	// The kept account's own, older mark survived the clash.
	var days int64
	db.Raw("SELECT TIMESTAMPDIFF(DAY, added, NOW()) FROM messages_interested WHERE userid = ? AND msgid = ?", keptID, f.msgID).Scan(&days)
	assert.GreaterOrEqual(t, days, int64(1))
}

func TestInterested_TheSwitchListEntryHasTheShapeTheCodeExpects(t *testing.T) {
	s, ok := ux.ServerSwitches["interested-button"]
	require.True(t, ok)
	assert.Equal(t, "interested-button", s.Name)
	assert.Equal(t, "server", s.Channel)
	assert.Equal(t, []string{"control", "button"}, s.Arms)
	assert.Equal(t, "interested_reply", s.Metric.Source)
	assert.Equal(t, 168, s.Metric.WindowHours)
}
