package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// getAutoapproveatField fetches a message as the given user and returns the
// autoapproveat value for the named group (nil if absent / not a mod / not pending).
func getAutoapproveatField(t *testing.T, msgid uint64, groupid uint64, token string) interface{} {
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
			return gm["autoapproveat"]
		}
	}
	return nil
}

// A3: loading the Pending queue bumps autoapprove_hold_until to >= NOW()+10m
// (extend-only — an existing longer hold is never shortened).
func TestListMessagesMTPendingBumpsHold(t *testing.T) {
	prefix := uniquePrefix("hold_bump")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	// Fresh pending post with no hold. contentcheck_checked_at must be set so the
	// post is visible in the Pending list (else the content-check filter hides it).
	freshMsg := CreateTestMessage(t, poster, groupID, prefix+" fresh pending", 52.0, -1.0)
	// Pending post already held 60 min out — extend-only must not shorten it.
	heldMsg := CreateTestMessage(t, poster, groupID, prefix+" held pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW(), contentcheck_checked_at=NOW(), autoapprove_hold_until=NULL WHERE msgid=?", freshMsg)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW(), contentcheck_checked_at=NOW(), autoapprove_hold_until=NOW() + INTERVAL 60 MINUTE WHERE msgid=?", heldMsg)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var freshSecs int64
	db.Raw("SELECT TIMESTAMPDIFF(SECOND, NOW(), autoapprove_hold_until) FROM messages_groups WHERE msgid=? AND groupid=?", freshMsg, groupID).Scan(&freshSecs)
	assert.GreaterOrEqual(t, freshSecs, int64(9*60), "fresh pending post should be held >= ~10 min after load")

	var heldSecs int64
	db.Raw("SELECT TIMESTAMPDIFF(SECOND, NOW(), autoapprove_hold_until) FROM messages_groups WHERE msgid=? AND groupid=?", heldMsg, groupID).Scan(&heldSecs)
	assert.Greater(t, heldSecs, int64(30*60), "existing longer hold must not be shortened (extend-only)")

	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", freshMsg, heldMsg)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?)", freshMsg, heldMsg)
}

// A4: GET /message/:id exposes autoapproveat only for Pending posts viewed by a
// moderator; clean-path posts get a time, danger-signalled posts get nil, and
// non-mods never see it.
func TestAutoapproveatPendingModGating(t *testing.T) {
	// This test exercises the clean 20-minute path, which is dark by default —
	// enable it (the rollout gate itself is covered by TestAutoapproveatRolloutGate).
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("autoapproveat")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	regularID := CreateTestUser(t, prefix+"_reg", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, regularID, groupID, "Member")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)
	_, regToken := CreateTestSession(t, regularID)

	// Clean-path pending post: NULL poster, content-check clean, arrival 5 min ago.
	cleanMsg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", cleanMsg)

	// Mod sees autoapproveat.
	assert.NotNil(t, getAutoapproveatField(t, cleanMsg, groupID, modToken),
		"mod should see autoapproveat on a clean pending post")
	// Non-mod does not.
	assert.Nil(t, getAutoapproveatField(t, cleanMsg, groupID, regToken),
		"non-mod must not see autoapproveat")

	// Danger-signalled post (poster is a known spammer) → no countdown.
	dangerMsg := CreateTestMessage(t, poster, groupID, prefix+" danger pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE WHERE msgid=?", dangerMsg)
	db.Exec("INSERT INTO spam_users (userid, collection, added) VALUES (?, 'Spammer', NOW())", poster)
	assert.Nil(t, getAutoapproveatField(t, dangerMsg, groupID, modToken),
		"danger-signalled post must not show a countdown")

	db.Exec("DELETE FROM spam_users WHERE userid=?", poster)
	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", cleanMsg, dangerMsg)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?)", cleanMsg, dangerMsg)
}


// The rollout gate (FREEGLE_AUTOAPPROVE_ENABLED / FREEGLE_AUTOAPPROVE_TRIAL_GROUPS)
// mirrors AutoApproveCleanService::enabledGroupIds. With the gate off (the default) a
// clean pending post must NOT show the 20-minute countdown — the clean path will not
// run, so showing it would promise moderators an auto-approval that never fires. It
// falls back to the 48h estimate instead. A trial group restores the 20-minute path
// for that group only.
func TestAutoapproveatRolloutGate(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	prefix := uniquePrefix("aagate")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" gated pending", 52.0, -1.0)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msg)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a timestamp string")
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	// Gate fully off: the countdown falls back to the 48h estimate.
	v := getAutoapproveatField(t, msg, groupID, modToken)
	assert.NotNil(t, v, "gate off: clean pending post still shows the 48h fallback estimate")
	assert.True(t, parseAt(v).After(time.Now().Add(40*time.Hour)),
		"gate off: estimate must be the 48h fallback, not the 20-minute clean path")

	// This group in the trial list: the 20-minute clean path applies again.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf(" %d ", groupID))
	v = getAutoapproveatField(t, msg, groupID, modToken)
	assert.NotNil(t, v)
	assert.True(t, parseAt(v).Before(time.Now().Add(time.Hour)),
		"trial group: the 20-minute clean-path estimate applies")

	// Global switch on: clean path everywhere, trial list irrelevant.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "999999999")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	v = getAutoapproveatField(t, msg, groupID, modToken)
	assert.NotNil(t, v)
	assert.True(t, parseAt(v).Before(time.Now().Add(time.Hour)),
		"global switch on: the 20-minute clean-path estimate applies")
}

// D9: markchecked with explicit ids marks only Approved rows on the mod's groups,
// leaving Pending rows and other-group rows untouched.
func TestMarkCheckedSpecificIDs(t *testing.T) {
	prefix := uniquePrefix("markchk_ids")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, poster, groupB, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator") // mod of A only
	_, modToken := CreateTestSession(t, modID)

	approvedA := CreateTestMessage(t, poster, groupA, prefix+" approved a", 52.0, -1.0)
	pendingA := CreateTestMessage(t, poster, groupA, prefix+" pending a", 52.0, -1.0)
	approvedB := CreateTestMessage(t, poster, groupB, prefix+" approved b", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL WHERE msgid IN (?, ?)", approvedA, approvedB)
	db.Exec("UPDATE messages_groups SET collection='Pending' WHERE msgid=?", pendingA)

	body := fmt.Sprintf(`{"groupid": %d, "ids": [%d, %d, %d]}`, groupA, approvedA, pendingA, approvedB)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var aChecked, pendChecked, bChecked int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approvedA, groupA).Scan(&aChecked)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", pendingA, groupA).Scan(&pendChecked)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approvedB, groupB).Scan(&bChecked)
	assert.Equal(t, int64(1), aChecked, "Approved post on the mod's group should be checked")
	assert.Equal(t, int64(0), pendChecked, "Pending post must not be marked checked (collection guard, D9)")
	assert.Equal(t, int64(0), bChecked, "post on a non-moderated group must not be checked")

	db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?, ?)", approvedA, pendingA, approvedB)
	db.Exec("DELETE FROM messages WHERE id IN (?, ?, ?)", approvedA, pendingA, approvedB)
}

// D11: markchecked returns 403 for a mod acting on a group they don't moderate,
// and for a non-mod using groupid=0.
func TestMarkCheckedCrossGroupAndNonMod(t *testing.T) {
	prefix := uniquePrefix("markchk_403")

	groupA := CreateTestGroup(t, prefix+"_a")
	groupB := CreateTestGroup(t, prefix+"_b")
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, modB, groupB, "Moderator") // mod of B only
	_, modBToken := CreateTestSession(t, modB)

	// Mod of B targeting group A → 403.
	bodyA := fmt.Sprintf(`{"groupid": %d, "filter": "checked"}`, groupA)
	reqA := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modBToken), strings.NewReader(bodyA))
	reqA.Header.Set("Content-Type", "application/json")
	respA, err := getApp().Test(reqA)
	assert.NoError(t, err)
	assert.Equal(t, 403, respA.StatusCode, "mod of B must not mark group A")

	// Non-mod with groupid=0 → 403.
	regID := CreateTestUser(t, prefix+"_reg", "User")
	_, regToken := CreateTestSession(t, regID)
	body0 := `{"groupid": 0, "filter": "checked"}`
	req0 := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", regToken), strings.NewReader(body0))
	req0.Header.Set("Content-Type", "application/json")
	resp0, err := getApp().Test(req0)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp0.StatusCode, "non-mod with groupid=0 must get 403")
}

// Reject pulls the specified Approved auto-published posts back to Pending, held by the
// mod, clearing checkedat; it requires explicit ids (no bulk reject).
func TestMarkCheckedReject(t *testing.T) {
	prefix := uniquePrefix("markchk_reject")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	approved := CreateTestMessage(t, poster, groupA, prefix+" approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NOW(), checkedby=? WHERE msgid=?", modID, approved)

	// The post is mid-ripple: an expanding reach row, and a rippled-in copy on another
	// group. The reject must hard-stop the reach and must NOT touch the rippled-in copy
	// (the engine's own retraction handles those).
	groupB := CreateTestGroup(t, prefix+"_b")
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival) "+
		"VALUES (?, 52.0, -1.0, ST_GeomFromText('POLYGON((-1.2 51.9,-0.8 51.9,-0.8 52.1,-1.2 52.1,-1.2 51.9))', 3857), NOW())", approved)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", approved, groupB)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid=?", approved)

	// Reject requires explicit ids.
	noIDs := fmt.Sprintf(`{"groupid": %d, "reject": true}`, groupA)
	reqNo := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(noIDs))
	reqNo.Header.Set("Content-Type", "application/json")
	respNo, err := getApp().Test(reqNo)
	assert.NoError(t, err)
	assert.Equal(t, 400, respNo.StatusCode, "reject without ids must be 400")

	// Reject the approved post.
	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupA, approved)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var pendingHeld, stillChecked int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Pending' AND heldby=? AND checkedat IS NULL", approved, groupA, modID).Scan(&pendingHeld)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approved, groupA).Scan(&stillChecked)
	assert.Equal(t, int64(1), pendingHeld, "rejected post is Pending, held by the mod, checkedat cleared")
	assert.Equal(t, int64(0), stillChecked, "checkedat must be cleared on reject")

	// The Rejected log row is what feeds the moderation-stats KPI and the clean
	// auto-approver's danger-signal veto for this member's next post.
	var rejectedLogs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Rejected' AND msgid=? AND groupid=? AND user=? AND byuser=?",
		approved, groupA, poster, modID).Scan(&rejectedLogs)
	assert.Equal(t, int64(1), rejectedLogs, "reject must write a Message/Rejected log row")

	// The reach engine is hard-stopped immediately, not left to expand for another tick.
	var reachStatus string
	db.Raw("SELECT status FROM rippling_reach WHERE msgid=?", approved).Scan(&reachStatus)
	assert.Equal(t, "stopped", reachStatus, "reject must stop the reach row synchronously")

	// The rippled-in copy on the other group is untouched (the engine retracts those).
	var rippledCopy int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND rippled_in=1", approved, groupB).Scan(&rippledCopy)
	assert.Equal(t, int64(1), rippledCopy, "reject must not directly touch rippled-in copies")

	// A mod of the RECEIVING group cannot reject the rippled-in copy through this
	// endpoint either — oversight actions are origin-row only.
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, modB, groupB, "Moderator")
	_, modBToken := CreateTestSession(t, modB)
	bodyB := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupB, approved)
	reqB := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modBToken), strings.NewReader(bodyB))
	reqB.Header.Set("Content-Type", "application/json")
	respB, err := getApp().Test(reqB)
	assert.NoError(t, err)
	assert.Equal(t, 200, respB.StatusCode)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND rippled_in=1", approved, groupB).Scan(&rippledCopy)
	assert.Equal(t, int64(1), rippledCopy, "a receiving-group mod's reject must not yank a rippled-in copy")

	db.Exec("DELETE FROM logs WHERE type='Message' AND subtype='Rejected' AND msgid=?", approved)
	db.Exec("DELETE FROM messages_groups WHERE msgid=?", approved)
	db.Exec("DELETE FROM messages WHERE id=?", approved)
}
