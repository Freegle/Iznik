package test

import (
	"encoding/json"
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// scWorkUser makes a user with the given system role who moderates a community, so the session
// computes work counts for them.
func scWorkUser(t *testing.T, name, systemrole string) string {
	prefix := uniquePrefix(name)
	id := CreateTestUser(t, prefix, systemrole)
	CreateTestMembership(t, id, CreateTestGroup(t, prefix), utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, id)
	return token
}

func scWorkCount(t *testing.T, token, key string) float64 {
	t.Helper()
	v, ok := getSessionWork(t, token)[key].(float64)
	assert.True(t, ok, "work should carry %s", key)
	return v
}

// A case counts for Support and Admin once its first pass has finished, until Support replies, and
// again when the referrer writes back. The referring moderator never gets the count.
func TestWorkCountSupportCasesWaitingForSupport(t *testing.T) {
	db := database.DBConn
	w := scSetup(t, "scwork")
	supTok := scWorkUser(t, "scwork_sup", utils.SYSTEMROLE_SUPPORT)
	admTok := scWorkUser(t, "scwork_adm", utils.SYSTEMROLE_ADMIN)
	_, replyTok := scSupport(t, "scwork_reply")

	baseSup := scWorkCount(t, supTok, "supportcases")
	baseAdm := scWorkCount(t, admTok, "supportcases")
	baseTotal := scWorkCount(t, supTok, "total")
	delta := func() (float64, float64) {
		return scWorkCount(t, supTok, "supportcases") - baseSup, scWorkCount(t, admTok, "supportcases") - baseAdm
	}

	caseid := scReferOK(t, w.modToken, w.chat, "Why can't they reply?")

	// The first pass has not run: not yet Support's to answer.
	s, a := delta()
	assert.Equal(t, float64(0), s, "a Pending first pass is not counted")
	assert.Equal(t, float64(0), a)
	db.Exec("UPDATE support_cases SET firstpass_status = 'Running' WHERE id = ?", caseid)
	s, _ = delta()
	assert.Equal(t, float64(0), s, "a Running first pass is not counted")

	for _, finished := range []string{"Done", "Failed", "Skipped"} {
		db.Exec("UPDATE support_cases SET firstpass_status = ? WHERE id = ?", finished, caseid)
		s, a = delta()
		assert.Equal(t, float64(1), s, "a first pass that is %s is counted for Support", finished)
		assert.Equal(t, float64(1), a, "and for Admin")
	}
	assert.Equal(t, float64(1), scWorkCount(t, supTok, "total")-baseTotal, "the count is in the red total")
	assert.Equal(t, float64(0), scWorkCount(t, w.modToken, "supportcases"), "the referring moderator gets no count")

	path := fmt.Sprintf("/api/supportcases/%d/messages", caseid)
	status, _ := scCall(t, "POST", path, replyTok, map[string]interface{}{"body": "Looking now"})
	assert.Equal(t, 200, status)
	s, _ = delta()
	assert.Equal(t, float64(0), s, "a Support reply clears it")

	status, _ = scCall(t, "POST", path, w.modToken, map[string]interface{}{"body": "Still stuck"})
	assert.Equal(t, 200, status)
	s, _ = delta()
	assert.Equal(t, float64(1), s, "the referrer writing back counts it again")

	status, _ = scCall(t, "PATCH", "/api/supportcases", replyTok, map[string]interface{}{"id": caseid, "status": "Handled"})
	assert.Equal(t, 200, status)
	s, _ = delta()
	assert.Equal(t, float64(0), s, "a Handled case is not counted")
}

// A plain moderator who is not Support gets no count even when cases are waiting.
func TestWorkCountSupportCasesNotForModerators(t *testing.T) {
	w := scSetup(t, "scworkmod")
	caseid := scReferOK(t, w.modToken, w.chat, "")
	database.DBConn.Exec("UPDATE support_cases SET firstpass_status = 'Done' WHERE id = ?", caseid)
	modTok := scWorkUser(t, "scworkmod_other", utils.SYSTEMROLE_MODERATOR)
	assert.Equal(t, float64(0), scWorkCount(t, modTok, "supportcases"))
}

// The list marks the cases waiting for Support, and status=Waiting lists only those.
func TestSupportCaseListWaitingFlagAndFilter(t *testing.T) {
	db := database.DBConn
	_, sToken := scSupport(t, "sclistwait_s")

	w1 := scSetup(t, "sclistwaitA")
	w2 := scSetup(t, "sclistwaitB")
	w3 := scSetup(t, "sclistwaitC")
	open := scReferOK(t, w1.modToken, w1.chat, "")
	answered := scReferOK(t, w2.modToken, w2.chat, "")
	pending := scReferOK(t, w3.modToken, w3.chat, "")
	db.Exec("UPDATE support_cases SET firstpass_status = 'Done' WHERE id IN (?, ?)", open, answered)
	status, _ := scCall(t, "POST", fmt.Sprintf("/api/supportcases/%d/messages", answered), sToken, map[string]interface{}{"body": "Done it"})
	assert.Equal(t, 200, status)

	rowsOf := func(path string) map[float64]map[string]interface{} {
		status, raw := scCall(t, "GET", path, sToken, nil)
		assert.Equal(t, 200, status, string(raw))
		var rows []map[string]interface{}
		assert.NoError(t, json.Unmarshal(raw, &rows), string(raw))
		out := map[float64]map[string]interface{}{}
		for _, r := range rows {
			out[r["id"].(float64)] = r
		}
		return out
	}

	all := rowsOf("/api/supportcases?status=All&limit=100")
	assert.Equal(t, true, all[float64(open)]["awaiting"])
	assert.Equal(t, false, all[float64(answered)]["awaiting"])
	assert.Equal(t, false, all[float64(pending)]["awaiting"])

	only := rowsOf("/api/supportcases?status=Waiting&limit=100")
	assert.Contains(t, only, float64(open))
	assert.NotContains(t, only, float64(answered))
	assert.NotContains(t, only, float64(pending))

	status, _ = scCall(t, "GET", "/api/supportcases?status=Sometimes", sToken, nil)
	assert.Equal(t, 400, status)
}
