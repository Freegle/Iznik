package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	json2 "encoding/json"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"
)

// postVerdict casts a CheckMessage verdict as the given user.
func postVerdict(t *testing.T, token string, msgid uint64, response string) {
	body := fmt.Sprintf(`{"msgid":%d,"response":"%s","comments":"seen it"}`, msgid, response)
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

// checkedState reads checkedat/checkedby for the post's row on the group.
func checkedState(t *testing.T, msgid, groupid uint64) (checkedat, checkedby interface{}) {
	row := database.DBConn.Raw("SELECT checkedat, checkedby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgid, groupid).Row()
	assert.NoError(t, row.Scan(&checkedat, &checkedby))
	return
}

// A post that published itself is looked at by a human once two different
// microvolunteers have approved it: checkedat is stamped with no checkedby, which
// clears it from the Checked queue and lifts the hold on spreading. One approval is
// not enough, and a later rejection puts it back in front of a moderator.
func TestMicroVolunteeringTwoApprovalsMarkChecked(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mv_quorum")
	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	r1 := CreateTestUser(t, prefix+"_r1", "User")
	r2 := CreateTestUser(t, prefix+"_r2", "User")
	r3 := CreateTestUser(t, prefix+"_r3", "User")
	for _, u := range []uint64{poster, r1, r2, r3} {
		CreateTestMembership(t, u, groupID, "Member")
	}
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, t1 := CreateTestSession(t, r1)
	_, t2 := CreateTestSession(t, r2)
	_, t3 := CreateTestSession(t, r3)

	msg := CreateTestMessage(t, poster, groupID, prefix+" self published", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection = 'Approved', approvedby = NULL, approvedat = NOW(), checkedat = NULL, checkedby = NULL, rippled_in = 0 WHERE msgid = ?", msg)
	defer db.Exec("DELETE FROM microactions WHERE msgid = ?", msg)

	postVerdict(t, t1, msg, "Approve")
	at, _ := checkedState(t, msg, groupID)
	assert.Nil(t, at, "one approval must not count as a human look")

	postVerdict(t, t2, msg, "Approve")
	at, by := checkedState(t, msg, groupID)
	assert.NotNil(t, at, "two approvals from different members mark the post checked")
	assert.Nil(t, by, "a microvolunteer check carries no checkedby, unlike a moderator's")

	postVerdict(t, t3, msg, "Reject")
	at, _ = checkedState(t, msg, groupID)
	assert.Nil(t, at, "a rejection after microvolunteers cleared the post reopens it for a moderator")
}

// Approvals never mark a post checked while a rejection stands against it, and a
// rejection never clears a moderator's own check.
func TestMicroVolunteeringApprovalsRespectRejectionsAndModerators(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mv_quorum2")
	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	r1 := CreateTestUser(t, prefix+"_r1", "User")
	r2 := CreateTestUser(t, prefix+"_r2", "User")
	r3 := CreateTestUser(t, prefix+"_r3", "User")
	for _, u := range []uint64{poster, r1, r2, r3} {
		CreateTestMembership(t, u, groupID, "Member")
	}
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, t1 := CreateTestSession(t, r1)
	_, t2 := CreateTestSession(t, r2)
	_, t3 := CreateTestSession(t, r3)

	// Rejected first, then approved twice: stays for a moderator.
	rejected := CreateTestMessage(t, poster, groupID, prefix+" rejected first", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection = 'Approved', approvedby = NULL, approvedat = NOW(), checkedat = NULL, checkedby = NULL, rippled_in = 0 WHERE msgid = ?", rejected)
	defer db.Exec("DELETE FROM microactions WHERE msgid = ?", rejected)
	postVerdict(t, t3, rejected, "Reject")
	postVerdict(t, t1, rejected, "Approve")
	postVerdict(t, t2, rejected, "Approve")
	at, _ := checkedState(t, rejected, groupID)
	assert.Nil(t, at, "approvals do not outvote a standing rejection")

	// Checked by a moderator: a rejection leaves that check alone.
	modChecked := CreateTestMessage(t, poster, groupID, prefix+" mod checked", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection = 'Approved', approvedby = NULL, approvedat = NOW(), checkedat = NOW(), checkedby = ?, rippled_in = 0 WHERE msgid = ?", modID, modChecked)
	defer db.Exec("DELETE FROM microactions WHERE msgid = ?", modChecked)
	postVerdict(t, t3, modChecked, "Reject")
	at, by := checkedState(t, modChecked, groupID)
	assert.NotNil(t, at, "a moderator's check survives a microvolunteer rejection")
	assert.NotNil(t, by)

	// Approved by a moderator in the first place: approvals have nothing to mark.
	modApproved := CreateTestMessage(t, poster, groupID, prefix+" mod approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection = 'Approved', approvedby = ?, approvedat = NOW(), checkedat = NULL, checkedby = NULL, rippled_in = 0 WHERE msgid = ?", modID, modApproved)
	defer db.Exec("DELETE FROM microactions WHERE msgid = ?", modApproved)
	postVerdict(t, t1, modApproved, "Approve")
	postVerdict(t, t2, modApproved, "Approve")
	at, _ = checkedState(t, modApproved, groupID)
	assert.Nil(t, at, "a post a moderator approved is not on the Checked queue and gains no checkedat")
}

// Pending posts that will publish by themselves come first in the microvolunteer
// queue, ahead of older pending posts that are waiting for a moderator anyway.
func TestGetMicrovolunteering_CheckMessagePendingPrefersSelfPublishing(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mv_prio")

	var reviewer uint64
	db.Exec("INSERT INTO users (firstname, lastname, systemrole, trustlevel) VALUES (?, 'Reviewer', 'User', ?)", prefix, microvolunteering.TrustModerate)
	db.Raw("SELECT id FROM users WHERE firstname = ? AND lastname = 'Reviewer' ORDER BY id DESC LIMIT 1", prefix).Scan(&reviewer)
	defer db.Exec("DELETE FROM users WHERE id = ?", reviewer)
	db.Exec("INSERT INTO microactions (actiontype, userid, version, comments, timestamp, score_negative) VALUES (?, ?, 4, 'block invite', NOW(), 0)", microvolunteering.ChallengeInvite, reviewer)
	defer db.Exec("DELETE FROM microactions WHERE userid = ?", reviewer)

	moderatedPoster := CreateTestUser(t, prefix+"_moderated", "User")
	autoPoster := CreateTestUser(t, prefix+"_auto", "User")

	var groupID uint64
	db.Exec("INSERT INTO `groups` (nameshort, namefull, type, microvolunteering, polyindex) VALUES (?, ?, 'Freegle', 1, ST_GeomFromText('POINT(0 0)', 3857))", prefix, prefix+" full")
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&groupID)
	defer db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)
	db.Exec("INSERT INTO memberships (userid, groupid) VALUES (?, ?)", reviewer, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid, ourPostingStatus) VALUES (?, ?, 'MODERATED')", moderatedPoster, groupID)
	db.Exec("INSERT INTO memberships (userid, groupid, ourPostingStatus) VALUES (?, ?, NULL)", autoPoster, groupID)
	defer db.Exec("DELETE FROM memberships WHERE groupid = ?", groupID)

	// The moderated member's post is older; the self-publishing one arrived later.
	older := CreateTestMessage(t, moderatedPoster, groupID, prefix+" moderated older", 52.0, -1.0)
	newer := CreateTestMessage(t, autoPoster, groupID, prefix+" auto newer", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection = 'Pending', arrival = NOW() - INTERVAL 10 MINUTE, autoreposts = 0 WHERE msgid = ?", older)
	db.Exec("UPDATE messages_groups SET collection = 'Pending', arrival = NOW() - INTERVAL 2 MINUTE, autoreposts = 0 WHERE msgid = ?", newer)
	db.Exec("UPDATE messages SET arrival = NOW() WHERE id IN (?, ?)", older, newer)

	token := getToken(t, reviewer)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, microvolunteering.ChallengeCheckMessage, result.Type)
	if assert.NotNil(t, result.Msgid) {
		assert.Equal(t, newer, *result.Msgid, "the post that will publish by itself is offered first")
	}
}
