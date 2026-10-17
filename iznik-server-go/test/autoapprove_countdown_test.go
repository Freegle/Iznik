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

// insertAutomodRow records a messages_automod decision for (msgid, groupid), as the
// automod flowchart would after judging a Pending copy. createdMinutesAgo backdates
// created (0 = just now); pass a value bigger than how long ago the post was last edited
// to produce a stale row.
func insertAutomodRow(t *testing.T, msgid uint64, groupid uint64, verdict string, createdMinutesAgo int) {
	sql := fmt.Sprintf(
		"INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
			"VALUES (?, ?, 'approve', 'v1', ?, 'test_end', NULL, '{}', NOW() - INTERVAL %d MINUTE)", createdMinutesAgo)
	res := database.DBConn.Exec(sql, msgid, groupid, verdict)
	assert.NoError(t, res.Error)
}

// A3: loading the Pending queue bumps autoapprove_hold_until to >= NOW()+10m
// (extend-only — an existing longer hold is never shortened).
func TestListMessagesMTPendingBumpsHold(t *testing.T) {
	prefix := uniquePrefix("hold_bump")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	// The hold is read only on communities in the post-moderation trial, so it is only
	// written there (see the outside-trial check at the end).
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d", groupID))
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

	// Outside the trial nothing reads the hold, so loading the Pending queue writes nothing.
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	db.Exec("UPDATE messages_groups SET autoapprove_hold_until=NULL WHERE msgid=?", freshMsg)
	resp, err = getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var untouched int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND autoapprove_hold_until IS NULL", freshMsg, groupID).Scan(&untouched)
	assert.Equal(t, int64(1), untouched, "a community outside the trial gets no hold written on load")

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

	// Clean-path pending post: NULL poster, content-check clean, arrival 5 min ago, and the
	// flowchart's own decision recorded as a current approve.
	cleanMsg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", cleanMsg)
	insertAutomodRow(t, cleanMsg, groupID, "approve", 0)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", cleanMsg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", cleanMsg)
	defer db.Exec("DELETE FROM messages WHERE id=?", cleanMsg)

	// Mod sees autoapproveat.
	assert.NotNil(t, getAutoapproveatField(t, cleanMsg, groupID, modToken),
		"mod should see autoapproveat on a clean pending post with an approve verdict")
	// Non-mod does not.
	assert.Nil(t, getAutoapproveatField(t, cleanMsg, groupID, regToken),
		"non-mod must not see autoapproveat")
}

// The wait before a clean post publishes itself is the same for every community. A
// leftover settings.autoapprove.delay_minutes on the group must not shorten the countdown,
// and FREEGLE_AUTOAPPROVE_DELAY_MINUTES (the figure the cron reads) must lengthen it.
func TestAutoapproveatDelayIsSiteWide(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aadelaysite")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	// A community that tried to shorten the wait to 5 minutes.
	db.Exec("UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), '$.autoapprove.delay_minutes', 5) WHERE id = ?", groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	// Decided a minute after the content check, as the chart normally is (the cron wants the
	// decision strictly later than the check, so it cannot be the same second).
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a time string, got %#v", v)
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	// Site-wide 20 minutes from a decision 3 minutes ago: about 17 minutes away, not
	// already due as the group's 5-minute figure would make it.
	at := parseAt(getAutoapproveatField(t, msg, groupID, modToken))
	assert.True(t, at.After(time.Now().Add(12*time.Minute)),
		"a community setting must not shorten the site-wide wait; got %v", at)
	assert.True(t, at.Before(time.Now().Add(18*time.Minute)),
		"the countdown should be the site-wide 20 minutes; got %v", at)

	// The cron's own figure moves the countdown.
	t.Setenv("FREEGLE_AUTOAPPROVE_DELAY_MINUTES", "60")
	at = parseAt(getAutoapproveatField(t, msg, groupID, modToken))
	assert.True(t, at.After(time.Now().Add(50*time.Minute)),
		"FREEGLE_AUTOAPPROVE_DELAY_MINUTES must set the countdown; got %v", at)
}

// The wait counts from the chart's decision when that is later than arrival, as the cron's
// own query does: a verdict that lands late (service down, backlog) gives moderators the full
// window from then, instead of a post that is already overdue publishing the minute its row
// is written with no countdown ever shown.
func TestAutoapproveatCountsFromTheDecision(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aadecided")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	// Arrived two hours ago; the review only decided three minutes ago.
	msg := CreateTestMessage(t, poster, groupID, prefix+" late decision", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 2 HOUR, contentcheck_checked_at=NOW() - INTERVAL 2 HOUR, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	v := getAutoapproveatField(t, msg, groupID, modToken)
	s, ok := v.(string)
	assert.True(t, ok, "autoapproveat should be a time string, got %#v", v)
	at, err := time.Parse(time.RFC3339, s)
	assert.NoError(t, err)
	assert.True(t, at.After(time.Now().Add(14*time.Minute)),
		"the countdown should run 20 minutes from the decision, not be overdue; got %v", at)
	assert.True(t, at.Before(time.Now().Add(19*time.Minute)),
		"the countdown should run 20 minutes from the decision; got %v", at)
}

// A copy the batch picked for the quality-check sample waits for a moderator, whatever the
// chart decided, so it shows no countdown. An unsampled copy with an approve decision does.
func TestAutoapproveatQualitySample(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aasample")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" clean pending", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, autoapprove_hold_until=NULL, quality_sample=0 WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 0)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "not sampled: counts down")

	db.Exec("UPDATE messages_groups SET quality_sample=1 WHERE msgid=?", msg)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "sampled: waits for a moderator")

	// The decision block says so, which is how the card explains an approved decision
	// with no countdown.
	automod := getAutomodField(t, msg, groupID, modToken)
	assert.NotNil(t, automod, "the moderator sees the stored decision")
	assert.Equal(t, true, automod["qualitysample"], "the decision block carries the sample flag")
}

// After an edit the content check re-runs. A decision made before that re-run saw the old
// findings and is not current, as the cron's own query says, so there is no countdown until
// the chart has decided again.
func TestAutoapproveatDecisionBeforeContentCheckRerun(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	prefix := uniquePrefix("aastalefacts")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	// Decided four minutes ago; the content check ran again two minutes ago.
	msg := CreateTestMessage(t, poster, groupID, prefix+" edited after decision", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 10 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 2 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 4)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"a decision older than the content check's last run is not current")

	// The chart decides again on the new findings.
	db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 1)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"a decision made after the re-run counts down")
}

// checkedOn returns checkedby for a copy, 0 when unstamped.
func checkedOn(t *testing.T, msgid, groupid uint64) uint64 {
	t.Helper()
	var by *uint64
	database.DBConn.Raw("SELECT checkedby FROM messages_groups WHERE msgid = ? AND groupid = ?", msgid, groupid).Scan(&by)
	if by == nil {
		return 0
	}
	return *by
}

// A moderator who APPROVES a post on one of their communities has looked at it and let it
// stand, so the copy of the same post that published itself on another trial community they
// moderate is stamped as checked, as if marked in Check. A copy on a community outside the
// trial is left alone, and so is one a moderator approved by hand.
//
// Rejecting or deleting the post on one community is not that: the moderator has turned the
// post down, and stamping its other copy as checked would lift the earned-reach hold on it
// (the hold lifts as soon as any copy is checked) and take it out of Check, the one place
// somebody still has to decide about it.
func TestModActionStampsOwnSelfPublishedCopies(t *testing.T) {
	prefix := uniquePrefix("aastampown")
	db := database.DBConn

	trial := CreateTestGroup(t, prefix+"_trial")
	outside := CreateTestGroup(t, prefix+"_outside")
	pendingOn := CreateTestGroup(t, prefix+"_pending")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	for _, gid := range []uint64{trial, outside, pendingOn} {
		CreateTestMembership(t, poster, gid, "Member")
		CreateTestMembership(t, modID, gid, "Moderator")
	}
	_, modToken := CreateTestSession(t, modID)
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d", trial))

	// A TN-style cross-post: direct rows on three communities. Self-published on the trial
	// community, self-published on one outside the trial, still Pending on the third.
	msg := CreateTestMessage(t, poster, trial, prefix+" cross-post", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NULL, checkedby=NULL, rippled_in=0 WHERE msgid=? AND groupid=?", msg, trial)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Approved', 0, 0)", msg, outside)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Pending', 0, 0)", msg, pendingOn)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	status, _ := postMultiAction(t, modToken, map[string]interface{}{
		"id": msg, "action": "Approve", "groupid": pendingOn,
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, "Approved", collectionOn(t, msg, pendingOn))
	assert.Equal(t, modID, checkedOn(t, msg, trial), "the self-published trial copy is stamped as checked by this moderator")
	assert.Equal(t, uint64(0), checkedOn(t, msg, outside), "a copy outside the trial is not part of Check and is not stamped")

	// A second post, this time rejected on the third community: the moderator has turned the
	// post down, so the live copy is not marked checked (it stays in Check, and its reach stays held).
	rejected := CreateTestMessage(t, poster, trial, prefix+" rejected elsewhere", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NULL, checkedby=NULL, rippled_in=0 WHERE msgid=? AND groupid=?", rejected, trial)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Pending', 0, 0)", rejected, pendingOn)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", rejected)
	defer db.Exec("DELETE FROM messages WHERE id=?", rejected)

	status, _ = postMultiAction(t, modToken, map[string]interface{}{
		"id": rejected, "action": "Reject", "groupid": pendingOn, "subject": "No thanks", "body": "Not for this community.",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, uint64(0), checkedOn(t, rejected, trial), "rejecting elsewhere is not a check of this copy")

	// Nor is deleting it elsewhere.
	deleted := CreateTestMessage(t, poster, trial, prefix+" deleted elsewhere", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NULL, checkedby=NULL, rippled_in=0 WHERE msgid=? AND groupid=?", deleted, trial)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Pending', 0, 0)", deleted, pendingOn)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", deleted)
	defer db.Exec("DELETE FROM messages WHERE id=?", deleted)

	status, _ = postMultiAction(t, modToken, map[string]interface{}{
		"id": deleted, "action": "Delete", "groupid": pendingOn,
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, uint64(0), checkedOn(t, deleted, trial), "deleting elsewhere is not a check of this copy")

	// A hand-approved copy is already reviewed and keeps its own record.
	handApproved := CreateTestMessage(t, poster, trial, prefix+" hand approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=?, checkedat=NULL, checkedby=NULL, rippled_in=0 WHERE msgid=? AND groupid=?", poster, handApproved, trial)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) VALUES (?, ?, NOW(), 'Pending', 0, 0)", handApproved, pendingOn)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", handApproved)
	defer db.Exec("DELETE FROM messages WHERE id=?", handApproved)

	status, _ = postMultiAction(t, modToken, map[string]interface{}{
		"id": handApproved, "action": "Approve", "groupid": pendingOn,
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, uint64(0), checkedOn(t, handApproved, trial), "a hand-approved copy is not stamped")
}

// A rippled-in copy is AutoApproveService's: released after rippled_in_pending_hours,
// unless the RECEIVING group's own keywords or worry words held it, in which case it waits
// for one of that group's moderators and never auto-approves. The countdown must say the
// same - a 48h countdown on a rule-held copy promises a release that will not come.
func TestAutoapproveatRippledInCopy(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("RIPPLE_RIPPLED_IN_PENDING_HOURS", "2")
	prefix := uniquePrefix("aarippled")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a timestamp string")
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	released := CreateTestMessage(t, poster, groupID, prefix+" rippled copy", 52.0, -1.0)
	ruleHeld := CreateTestMessage(t, poster, groupID, prefix+" rule-held copy", 52.0, -1.0)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid IN (?, ?)", released, ruleHeld)
	defer db.Exec("DELETE FROM messages WHERE id IN (?, ?)", released, ruleHeld)
	db.Exec("UPDATE messages_groups SET collection='Pending', rippled_in=1, arrival=NOW() - INTERVAL 30 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 29 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", released)
	db.Exec("UPDATE messages_groups SET collection='Pending', rippled_in=1, arrival=NOW() - INTERVAL 30 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 29 MINUTE, autoapprove_hold_until=NULL, "+
		"contentcheck_reasons='[{\"check\":\"PerGroupWorryWord\",\"action\":\"flag\",\"detail\":\"rabbit\"}]' WHERE msgid=?", ruleHeld)

	v := getAutoapproveatField(t, released, groupID, modToken)
	assert.NotNil(t, v, "a rippled-in copy nothing holds is released after rippled_in_pending_hours")
	at := parseAt(v)
	assert.True(t, at.After(time.Now().Add(60*time.Minute)) && at.Before(time.Now().Add(120*time.Minute)),
		"estimate is arrival + 2h (RIPPLE_RIPPLED_IN_PENDING_HOURS), not the 48h fallback")

	assert.Nil(t, getAutoapproveatField(t, ruleHeld, groupID, modToken),
		"a copy held by the receiving group's own rules never auto-approves: no countdown")
}

// The rollout gate (FREEGLE_AUTOAPPROVE_ENABLED / FREEGLE_AUTOAPPROVE_TRIAL_GROUPS)
// mirrors AutoApproveCleanService::enabledGroupIds. With the gate off (the default) a
// pending post shows no countdown at all: a community outside the trial sees Pending as
// it did before post-moderation. A trial group gets the 20-minute path for that group only.
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
	insertAutomodRow(t, msg, groupID, "approve", 0)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid = ?", msg)

	parseAt := func(v interface{}) time.Time {
		s, ok := v.(string)
		assert.True(t, ok, "autoapproveat should be a timestamp string")
		at, err := time.Parse(time.RFC3339, s)
		assert.NoError(t, err)
		return at
	}

	// Gate fully off: no countdown.
	v := getAutoapproveatField(t, msg, groupID, modToken)
	assert.Nil(t, v, "gate off: a community outside the trial shows no countdown")

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
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
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
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
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
// mod, for a proper decision; it requires explicit ids (no bulk reject).
//
// It is a second look, not a home community's decision. So it withdraws nothing, blocks
// nothing and deletes nothing: no rippled copy is soft-deleted, nobody's ripple membership is
// removed, no Message/Deleted log is written against the poster (the automated review reads
// those as "a moderator acted on this member's posts" and holds their next posts for 90
// days), and the post is not barred from rippling. The copies on other communities go back to
// their own Pending queues, in neutral words (nothing says "Check" to a community that has no
// Check queue), unless that community's own moderator reviewed theirs, which stays live.
func TestMarkCheckedReject(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_reject")
	db := database.DBConn

	groupA := CreateTestGroup(t, prefix+"_a")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupA, "Member")
	CreateTestMembership(t, modID, groupA, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// A real Check-queue row: self-published, and nobody has looked at it yet.
	approved := CreateTestMessage(t, poster, groupA, prefix+" approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NULL, checkedby=NULL WHERE msgid=?", approved)

	// The post is mid-ripple: an expanding reach row, a rippled-in copy on a community whose
	// moderators have not looked at it (the poster was joined to it by the ripple), one on a
	// community whose own moderator approved it by hand, and one on a community where it is
	// still Pending and a moderator is holding it.
	groupB := CreateTestGroup(t, prefix+"_b")
	groupC := CreateTestGroup(t, prefix+"_c")
	groupD := CreateTestGroup(t, prefix+"_d")
	modC := CreateTestUser(t, prefix+"_modc", "User")
	modD := CreateTestUser(t, prefix+"_modd", "User")
	CreateTestMembership(t, modC, groupC, "Moderator")
	CreateTestMembership(t, modD, groupD, "Moderator")
	db.Exec("INSERT INTO memberships (userid, groupid, role, rippled) VALUES (?, ?, 'Member', 1)", poster, groupB)
	// Mid-pause when rejected: the awaiting stamp must be banked and cleared by the
	// reject, or the "posts awaiting review" metric counts this frozen row forever.
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, arrival, awaiting_review_since) "+
		"VALUES (?, 52.0, -1.0, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-1.2 51.9,-0.8 51.9,-0.8 52.1,-1.2 52.1,-1.2 51.9))', 3857)), NOW(), "+
		"NOW() - INTERVAL 10 MINUTE)", approved)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1)", approved, groupB)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in, approvedby, approvedat) "+
		"VALUES (?, ?, NOW(), 'Approved', 0, 1, ?, NOW())", approved, groupC, modC)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in, heldby) "+
		"VALUES (?, ?, NOW(), 'Pending', 0, 1, ?)", approved, groupD, modD)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid=?", approved)
	defer db.Exec("DELETE FROM rippling_blocked WHERE msgid=?", approved)
	defer db.Exec("DELETE FROM memberships WHERE userid=? AND groupid=?", poster, groupB)

	// Reject requires explicit ids.
	noIDs := fmt.Sprintf(`{"groupid": %d, "reject": true}`, groupA)
	reqNo := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(noIDs))
	reqNo.Header.Set("Content-Type", "application/json")
	respNo, err := getApp().Test(reqNo)
	assert.NoError(t, err)
	assert.Equal(t, 400, respNo.StatusCode, "reject without ids must be 400")

	// A mod of a RECEIVING group cannot reject the rippled-in copy through this endpoint:
	// oversight actions are origin-row only.
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, modB, groupB, "Moderator")
	_, modBToken := CreateTestSession(t, modB)
	bodyB := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupB, approved)
	reqB := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modBToken), strings.NewReader(bodyB))
	reqB.Header.Set("Content-Type", "application/json")
	respB, err := getApp().Test(reqB)
	assert.NoError(t, err)
	assert.Equal(t, 200, respB.StatusCode)
	var rippledCopy int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND rippled_in=1 AND deleted=0", approved, groupB).Scan(&rippledCopy)
	assert.Equal(t, int64(1), rippledCopy, "a receiving-group mod's reject must not yank a rippled-in copy")

	// Reject the approved post from its home community's Check queue.
	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupA, approved)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var rejectResp map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&rejectResp)
	assert.Equal(t, float64(1), rejectResp["rejected"], "the count is of copies pulled back, whether or not any column changed")

	var pendingHeld, stillChecked int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Pending' AND heldby=? AND needs_moderator=1 AND checkedat IS NULL AND checkedby IS NULL", approved, groupA, modID).Scan(&pendingHeld)
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND checkedat IS NOT NULL", approved, groupA).Scan(&stillChecked)
	assert.Equal(t, int64(1), pendingHeld, "rejected post is Pending, held by the mod, needs_moderator set, checked stamp cleared")
	assert.Equal(t, int64(0), stillChecked, "checkedat must stay clear on reject")

	// Pulling a post back is a Hold, not a decision: the moderator's later Approve or
	// Reject writes its own log. No Rejected row yet, so an approved post does not count
	// against the member.
	var holdLogs, rejectedLogs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Hold' AND msgid=? AND groupid=? AND user=? AND byuser=? AND text='Pulled back from Check'",
		approved, groupA, poster, modID).Scan(&holdLogs)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Rejected' AND msgid=?", approved).Scan(&rejectedLogs)
	assert.Equal(t, int64(1), holdLogs, "reject must write one Message/Hold log row on the home community")
	assert.Equal(t, int64(0), rejectedLogs, "reject must not write a Rejected log before the moderator decides")

	// The reach is frozen, not killed, and the post is not barred from rippling: this was a
	// second look, not the home community's decision.
	var reachStatus string
	db.Raw("SELECT status FROM rippling_reach WHERE msgid=?", approved).Scan(&reachStatus)
	assert.Equal(t, "held", reachStatus, "reject must freeze the reach row synchronously")
	var blocked int64
	db.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid=?", approved).Scan(&blocked)
	assert.Equal(t, int64(0), blocked, "a Check reject is not a home decision, so it does not bar the post from rippling")

	// The await-review pause is settled by the reject: time banked, stamp cleared.
	var awaitOpen, awaitBanked int64
	db.Raw("SELECT COUNT(*) FROM rippling_reach WHERE msgid=? AND awaiting_review_since IS NOT NULL", approved).Scan(&awaitOpen)
	db.Raw("SELECT awaiting_review_seconds FROM rippling_reach WHERE msgid=?", approved).Scan(&awaitBanked)
	assert.Equal(t, int64(0), awaitOpen, "reject must clear the awaiting stamp")
	assert.GreaterOrEqual(t, awaitBanked, int64(590), "reject must bank the paused time")

	// Nothing is withdrawn: no copy deleted, no Deleted log against the poster (which the
	// automated review would read as a moderator acting on their posts), their ripple
	// membership untouched.
	var deletedCopies, deletedLogs, rippleMembership int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND deleted=1", approved).Scan(&deletedCopies)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Deleted' AND msgid=?", approved).Scan(&deletedLogs)
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid=? AND groupid=? AND rippled=1", poster, groupB).Scan(&rippleMembership)
	assert.Equal(t, int64(0), deletedCopies, "a Check reject withdraws no copy")
	assert.Equal(t, int64(0), deletedLogs, "and writes no Deleted log for the poster's next post to be held for")
	assert.Equal(t, int64(1), rippleMembership, "and removes nobody's membership")

	// The unreviewed rippled-in copy goes back to its own community's Pending queue for that
	// community's moderators, with the neutral Back to pending wording.
	var b struct {
		Collection     string
		Deleted        int
		NeedsModerator int
		Spamreason     *string
	}
	db.Raw("SELECT collection, deleted, needs_moderator, spamreason FROM messages_groups WHERE msgid=? AND groupid=?", approved, groupB).Scan(&b)
	assert.Equal(t, "Pending", b.Collection, "an unreviewed copy elsewhere goes back to its own Pending queue")
	assert.Equal(t, 0, b.Deleted)
	assert.Equal(t, 1, b.NeedsModerator, "waiting for a moderator of its own community")
	if assert.NotNil(t, b.Spamreason) {
		assert.Equal(t, "A moderator moved this post back to pending for review.", *b.Spamreason)
	}
	var bHold int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND subtype='Hold' AND msgid=? AND groupid=? AND byuser=? AND text='A moderator moved this post back to pending for review.'",
		approved, groupB, modID).Scan(&bHold)
	assert.Equal(t, int64(1), bHold, "that community is told, in neutral words")

	// The copy another community's moderator approved by hand stays live, untouched and unlogged.
	var keptCopy, keptLogs int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND deleted=0 AND approvedby=? AND needs_moderator=0", approved, groupC, modC).Scan(&keptCopy)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND msgid=? AND groupid=?", approved, groupC).Scan(&keptLogs)
	assert.Equal(t, int64(1), keptCopy, "a copy another community's moderator approved by hand stays live")
	assert.Equal(t, int64(0), keptLogs, "and gets no log")

	// A copy that is already Pending on another community, held by that community's own
	// moderator, is not this moderator's to touch.
	var d struct {
		Collection string
		Deleted    int
		Heldby     *uint64
	}
	db.Raw("SELECT collection, deleted, heldby FROM messages_groups WHERE msgid=? AND groupid=?", approved, groupD).Scan(&d)
	assert.Equal(t, "Pending", d.Collection)
	assert.Equal(t, 0, d.Deleted, "a held Pending copy elsewhere is not deleted out from under its moderator")
	if assert.NotNil(t, d.Heldby) {
		assert.Equal(t, modD, *d.Heldby, "and their hold stands")
	}
	var dLogs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type='Message' AND msgid=? AND groupid=?", approved, groupD).Scan(&dLogs)
	assert.Equal(t, int64(0), dLogs)

	// "Check" is a word for the trial community only.
	var checkWording int64
	db.Raw("SELECT (SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid <> ? AND spamreason LIKE '%Check%') + "+
		"(SELECT COUNT(*) FROM logs WHERE msgid=? AND groupid <> ? AND text LIKE '%Check%')", approved, groupA, approved, groupA).Scan(&checkWording)
	assert.Equal(t, int64(0), checkWording, "no copy or log outside the community acted on says Check")

	db.Exec("DELETE FROM logs WHERE type='Message' AND msgid=?", approved)
	db.Exec("DELETE FROM messages_groups WHERE msgid=?", approved)
	db.Exec("DELETE FROM messages WHERE id=?", approved)
}

// After a Check reject the moderator decides. Approving brings the copy back as the
// moderator's own approval: the hold and needs_moderator go, approvedby is stamped. The
// reach the pull-back froze spreads again from where it had got to (nothing re-ripples or
// re-notifies anyone already told), because a Check reject does not bar the post from
// rippling the way a home community's Back to pending does.
func TestMarkCheckedRejectThenApprove(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_approve")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" approved again", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, checkedat=NULL, checkedby=NULL WHERE msgid=?", msg)
	addExpandingReach(t, msg)
	scheduleReachIn(msg, "1 HOUR")
	defer db.Exec("DELETE FROM logs WHERE type='Message' AND msgid=?", msg)
	defer db.Exec("DELETE FROM rippling_blocked WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	out := postMarkChecked(t, modToken, fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg))
	assert.Equal(t, float64(1), out["rejected"])
	requireFrozen(t, msg, "the pull-back from Check froze the reach")

	status, body := postModAction(t, modToken, msg, "Approve", groupID)
	assert.Equal(t, 200, status, body)

	var state struct {
		Collection     string
		Approvedby     *uint64
		Heldby         *uint64
		NeedsModerator int
	}
	db.Raw("SELECT collection, approvedby, heldby, needs_moderator FROM messages_groups WHERE msgid=? AND groupid=?", msg, groupID).Scan(&state)
	assert.Equal(t, "Approved", state.Collection)
	if assert.NotNil(t, state.Approvedby) {
		assert.Equal(t, modID, *state.Approvedby, "the moderator's own approval")
	}
	assert.Nil(t, state.Heldby, "the hold is released by the approval")
	assert.Equal(t, 0, state.NeedsModerator, "and so is needs_moderator")
	assertResumed(t, msg, "approving the home copy lifts the freeze the pull-back put on the reach")

	var blocked int64
	db.Raw("SELECT COUNT(*) FROM rippling_blocked WHERE msgid=?", msg).Scan(&blocked)
	assert.Equal(t, int64(0), blocked, "nothing barred the post from rippling")
}

// A Check reject then a Release (the moderator lets a colleague decide) leaves
// needs_moderator set: the auto-approve cron and the countdown both skip the copy, so the
// post does not quietly publish itself a second time the minute the hold comes off.
func TestMarkCheckedRejectThenReleaseKeepsNeedsModerator(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_release")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" published", 52.0, -1.0)
	// Published by the clean path an hour ago on a still-current approve decision.
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, arrival=NOW() - INTERVAL 1 HOUR, contentcheck_checked_at=NOW() - INTERVAL 1 HOUR, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 55)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM logs WHERE type='Message' AND msgid=?", msg)
	defer db.Exec("DELETE FROM rippling_blocked WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	status, out := postModAction(t, modToken, msg, "Release", groupID)
	assert.Equal(t, 200, status, out)

	var state struct {
		Collection     string
		Heldby         *uint64
		NeedsModerator int
	}
	db.Raw("SELECT collection, heldby, needs_moderator FROM messages_groups WHERE msgid=? AND groupid=?", msg, groupID).Scan(&state)
	assert.Equal(t, "Pending", state.Collection)
	assert.Nil(t, state.Heldby, "Release clears the hold")
	assert.Equal(t, 1, state.NeedsModerator, "but the post still waits for a moderator's decision")

	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"no countdown on a released copy that still needs a moderator, whatever the chart decided")
}

// A copy a moderator approved by hand is not in the Check queue, so the Check reject cannot
// reach it: undoing a moderator's approval is Back to pending, on purpose.
func TestMarkCheckedRejectSkipsHandApprovedCopy(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_byhand")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" by hand", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=?, approvedat=NOW() WHERE msgid=?", modID, msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	body := fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	assert.Equal(t, float64(0), out["rejected"])

	var untouched int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND approvedby=? AND heldby IS NULL AND needs_moderator=0", msg, groupID, modID).Scan(&untouched)
	assert.Equal(t, int64(1), untouched, "a hand-approved copy is not reachable through the Check reject")
}

// Outside the post-moderation trial the Check queue does not exist, so neither do its
// actions: the same predicate matches every community's 48-hour fallback approvals, and a
// stamp there would count as a review in the stats and lift the earned-reach hold.
func TestMarkCheckedOutsideTrialDoesNothing(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("markchk_notrial")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" fallback approved", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	post := func(body string) map[string]interface{} {
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/messages/markchecked?jwt=%s", modToken), strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
		var out map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&out)
		return out
	}

	assert.Equal(t, float64(0), post(fmt.Sprintf(`{"groupid": %d, "ids": [%d]}`, groupID, msg))["checked"])
	assert.Equal(t, float64(0), post(`{"groupid": 0, "filter": "checked"}`)["checked"])
	assert.Equal(t, float64(0), post(fmt.Sprintf(`{"groupid": %d, "reject": true, "ids": [%d]}`, groupID, msg))["rejected"])

	var untouched int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid=? AND groupid=? AND collection='Approved' AND checkedat IS NULL AND heldby IS NULL", msg, groupID).Scan(&untouched)
	assert.Equal(t, int64(1), untouched, "a community outside the trial is left exactly as it was")
}

// A copy with a spam reason on it is never published by either cron, so it gets no
// countdown: a members' report quorum writes the copy's spamreason as it pulls the post
// back, with no hold and no needs_moderator, and the chart's approve row is still current.
func TestAutoapproveatCopySpamreasonHidesCountdown(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aaspamreason")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" reported", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL, spamreason='Reported by members' WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "a copy with a spam reason shows no countdown")

	db.Exec("UPDATE messages_groups SET spamreason=NULL WHERE msgid=?", msg)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "the same copy without one does")
}

// The cron only publishes a post whose member still has no posting status on the
// community. The chart saw the status as a fact when it ran, but a moderator setting the
// member to Moderated while the post waits does not re-run it, so the countdown reads the
// membership too, or it would show a wait the cron never honours.
func TestAutoapproveatPostingStatusSetMidWait(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aastatus")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" status changed", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "no posting status: the countdown shows")

	db.Exec("UPDATE memberships SET ourPostingStatus = 'MODERATED' WHERE userid = ? AND groupid = ?", poster, groupID)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "set to Moderated mid-wait: no countdown, as the cron will not publish it")
}

// The countdown is for the moderators of the community whose copy will publish. A post that
// is Pending on a trial community and on a neighbour outside the trial (a TrashNothing
// cross-post) is shown to the neighbour's moderator too, and the API used to hand them the
// trial community's countdown in the payload - a promise about a copy that is not theirs.
func TestAutoapproveatOnlyOnTheViewersOwnCopies(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aaviewer")
	db := database.DBConn

	trial := CreateTestGroup(t, prefix+"_trial")
	outside := CreateTestGroup(t, prefix+"_outside")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d", trial))
	poster := CreateTestUser(t, prefix+"_poster", "User")
	trialMod := CreateTestUser(t, prefix+"_trialmod", "User")
	outsideMod := CreateTestUser(t, prefix+"_outsidemod", "User")
	bothMod := CreateTestUser(t, prefix+"_bothmod", "User")
	CreateTestMembership(t, poster, trial, "Member")
	CreateTestMembership(t, poster, outside, "Member")
	CreateTestMembership(t, trialMod, trial, "Moderator")
	CreateTestMembership(t, outsideMod, outside, "Moderator")
	CreateTestMembership(t, bothMod, trial, "Moderator")
	CreateTestMembership(t, bothMod, outside, "Moderator")
	_, trialToken := CreateTestSession(t, trialMod)
	_, outsideToken := CreateTestSession(t, outsideMod)
	_, bothToken := CreateTestSession(t, bothMod)

	msg := CreateTestMessage(t, poster, trial, prefix+" cross-post", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in, contentcheck_checked_at) VALUES (?, ?, NOW() - INTERVAL 5 MINUTE, 'Pending', 0, 0, NOW() - INTERVAL 4 MINUTE)", msg, outside)
	insertAutomodRow(t, msg, trial, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	assert.NotNil(t, getAutoapproveatField(t, msg, trial, trialToken), "the trial community's moderator sees its countdown")
	assert.Nil(t, getAutoapproveatField(t, msg, trial, outsideToken),
		"a moderator of a community outside the trial is not handed a countdown for a copy that is not theirs")
	assert.Nil(t, getAutoapproveatField(t, msg, outside, outsideToken), "and their own copy has none")
	assert.NotNil(t, getAutoapproveatField(t, msg, trial, bothToken), "a moderator of both still sees the trial copy's")
}

// The cron has three more conditions than the countdown used to check. A hold on the message
// itself (not just on this community's copy), a content check that has not run on the copy,
// and a poster whose account has been deleted each stop the cron publishing, so none may show
// a countdown.
func TestAutoapproveatMirrorsTheCronsOtherConditions(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aacronconds")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	// A moderator as production has them (system role Moderator), who is shown a post whose
	// poster has since deleted their account; a plain member is not.
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" cron conditions", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	insertAutomodRow(t, msg, groupID, "approve", 3)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)
	defer db.Exec("UPDATE users SET deleted = NULL WHERE id = ?", poster)

	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "nothing stops it: the countdown shows")

	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", modID, msg)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "held on the message itself: the cron skips it, so no countdown")
	db.Exec("UPDATE messages SET heldby = NULL WHERE id = ?", msg)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken))

	db.Exec("UPDATE messages_groups SET contentcheck_checked_at = NULL WHERE msgid = ?", msg)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "the content check has not run on the copy: the cron needs it to have")
	db.Exec("UPDATE messages_groups SET contentcheck_checked_at = NOW() - INTERVAL 4 MINUTE WHERE msgid = ?", msg)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken))

	db.Exec("UPDATE users SET deleted = NOW() WHERE id = ?", poster)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "the poster's account is deleted: the cron joins the poster, so it will not publish")
}

// A moderator clicking Approve on a copy the cron published moments earlier changes nothing,
// so it must do nothing: no approval mail task for the poster-facing batch (which would write
// a phantom Approved log against a copy that has no approver) and no second freebie alert.
func TestApproveOfACopyThatAlreadyPublishedChangesNothing(t *testing.T) {
	prefix := uniquePrefix("aaphantom")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" published a moment ago", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Approved', approvedby=NULL, approvedat=NOW() WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM background_tasks WHERE JSON_EXTRACT(data, '$.msgid') = ?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	status, out := postMultiAction(t, modToken, map[string]interface{}{"id": msg, "action": "Approve", "groupid": groupID})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(1), out["ret"], "answered like the several-communities form: no longer pending, not approved")
	assert.Equal(t, int64(0), taskCount(t, "email_message_approved", msg), "no approval task for a copy that was not approved")
	assert.Equal(t, int64(0), taskCount(t, "freebie_alerts_add", msg), "and no second freebie alert")

	var approvedby *uint64
	db.Raw("SELECT approvedby FROM messages_groups WHERE msgid=? AND groupid=?", msg, groupID).Scan(&approvedby)
	assert.Nil(t, approvedby, "the copy is still the cron's, not a moderator approval")
}

// holdState reads a copy's autoapprove_hold_until: whether one is set, and how many seconds
// from now it ends.
func holdState(msgid, groupid uint64) (set bool, secs int64) {
	database.DBConn.Raw("SELECT autoapprove_hold_until IS NOT NULL, "+
		"COALESCE(TIMESTAMPDIFF(SECOND, NOW(), autoapprove_hold_until), 0) "+
		"FROM messages_groups WHERE msgid = ? AND groupid = ?", msgid, groupid).Row().Scan(&set, &secs)
	return
}

// Opening the Pending queue holds every post it lists for at least ten minutes, not only the
// page that was fetched: a moderator with the queue open must not see a post on page two
// publish itself because they had not scrolled to it. The hold covers the communities in the
// request that are in the trial, only extends, and leaves alone what the queue does not show.
func TestListMessagesMTPendingHoldsEveryCopyOnTheListedCommunities(t *testing.T) {
	prefix := uniquePrefix("hold_all")
	db := database.DBConn

	listed := CreateTestGroup(t, prefix+"_listed")
	otherTrial := CreateTestGroup(t, prefix+"_other")
	outside := CreateTestGroup(t, prefix+"_outside")
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "")
	t.Setenv("FREEGLE_AUTOAPPROVE_TRIAL_GROUPS", fmt.Sprintf("%d,%d", listed, otherTrial))
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	for _, gid := range []uint64{listed, otherTrial, outside} {
		CreateTestMembership(t, poster, gid, "Member")
		CreateTestMembership(t, modID, gid, "Moderator")
	}
	_, modToken := CreateTestSession(t, modID)

	var msgs []uint64
	pending := func(gid uint64, subject, extra string) uint64 {
		m := CreateTestMessage(t, poster, gid, prefix+" "+subject, 52.0, -1.0)
		db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW(), contentcheck_checked_at=NOW(), "+
			"autoapprove_hold_until=NULL, heldby=NULL, rippled_in=0 "+extra+" WHERE msgid=?", m)
		msgs = append(msgs, m)
		return m
	}
	t.Cleanup(func() {
		for _, m := range msgs {
			db.Exec("DELETE FROM messages_groups WHERE msgid = ?", m)
			db.Exec("DELETE FROM messages WHERE id = ?", m)
		}
	})

	// Three posts waiting on the listed community: the page below fetches one of them.
	a1 := pending(listed, "a1", "")
	a2 := pending(listed, "a2", "")
	a3 := pending(listed, "a3", "")
	// Already held for an hour: a later load must not shorten that.
	aLong := pending(listed, "long", ", autoapprove_hold_until = NOW() + INTERVAL 60 MINUTE")
	// What the queue does not show, or what no hold could matter to.
	aHeld := pending(listed, "held by a moderator", fmt.Sprintf(", heldby = %d", modID))
	aRippled := pending(listed, "rippled in", ", rippled_in = 1")
	aUnchecked := pending(listed, "content check not run", ", contentcheck_checked_at = NULL")
	aApproved := pending(listed, "already live", ", collection = 'Approved'")
	// Another trial community the moderator covers, and one outside the trial.
	b1 := pending(otherTrial, "b1", "")
	c1 := pending(outside, "c1", "")

	list := func(query string) []interface{} {
		resp, err := getApp().Test(httptest.NewRequest("GET",
			fmt.Sprintf("/api/modtools/messages?collection=Pending&%s&jwt=%s", query, modToken), nil))
		assert.NoError(t, err)
		assert.Equal(t, 200, resp.StatusCode)
		var body map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&body)
		ids, _ := body["messages"].([]interface{})
		return ids
	}

	// The community is named and one post fetched: every post the queue shows for it is held.
	assert.Len(t, list(fmt.Sprintf("groupid=%d&limit=1", listed)), 1)

	for name, m := range map[string]uint64{"a1": a1, "a2": a2, "a3": a3} {
		set, secs := holdState(m, listed)
		assert.True(t, set, "%s: held although it was not on the page that was fetched", name)
		assert.GreaterOrEqual(t, secs, int64(9*60), "%s: at least ten minutes", name)
	}
	_, longSecs := holdState(aLong, listed)
	assert.Greater(t, longSecs, int64(30*60), "extend-only: a hold further out is not shortened")

	for name, m := range map[string]uint64{"held by a moderator": aHeld, "rippled in": aRippled,
		"content check not run": aUnchecked, "already live": aApproved} {
		set, _ := holdState(m, listed)
		assert.False(t, set, "%s: not in the queue's scope, so no hold is written", name)
	}

	// Bounded to the communities in the request: the others are not touched.
	set, _ := holdState(b1, otherTrial)
	assert.False(t, set, "a trial community the request did not name is not held")
	set, _ = holdState(c1, outside)
	assert.False(t, set, "a community outside the trial is never held")

	// With no community named the request covers every community the moderator is active on;
	// the trial ones among them are held, the one outside still is not.
	assert.NotEmpty(t, list("limit=1"))
	set, secs := holdState(b1, otherTrial)
	assert.True(t, set, "listing every community holds the other trial community's posts too")
	assert.GreaterOrEqual(t, secs, int64(9*60))
	set, _ = holdState(c1, outside)
	assert.False(t, set, "still never held outside the trial")
}

// insertAutomodRowInMode is insertAutomodRow with the mode the decision was recorded in.
func insertAutomodRowInMode(t *testing.T, msgid uint64, groupid uint64, mode, verdict string, createdMinutesAgo int) {
	sql := fmt.Sprintf(
		"INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
			"VALUES (?, ?, ?, 'v1', ?, 'test_end', NULL, '{}', NOW() - INTERVAL %d MINUTE)", createdMinutesAgo)
	res := database.DBConn.Exec(sql, msgid, groupid, mode, verdict)
	assert.NoError(t, res.Error)
}

// A decision recorded in shadow mode was never a promise: the cron publishes only on an approve
// recorded in approve mode, so a community moved from shadow to approve has no countdown on a
// post until the chart has decided it again.
func TestAutoapproveatIgnoresAShadowDecision(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aashadowdec")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	msg := CreateTestMessage(t, poster, groupID, prefix+" shadow then approve", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	insertAutomodRowInMode(t, msg, groupID, "shadow", "approve", 2)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"an approve recorded in shadow mode never counts down: the cron would not publish on it")

	db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	insertAutomodRowInMode(t, msg, groupID, "approve", "approve", 2)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken),
		"the same approve recorded in approve mode does")
}

// The cron wants the decision strictly later than the content check it drew its facts from and
// than the last edit: equal to the second is not later, because the chart may have read the
// text first. The countdown says the same, so it never promises a publish the cron refuses.
func TestAutoapproveatDecisionMustBeStrictlyLater(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	prefix := uniquePrefix("aastrict")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	const checked = "2026-10-10 10:00:00"
	const later = "2026-10-10 10:00:01"
	msg := CreateTestMessage(t, poster, groupID, prefix+" strictly later", 52.0, -1.0)
	db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=?, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", checked, msg)
	db.Exec("UPDATE messages SET editedat = NULL WHERE id = ?", msg)
	defer db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages_groups WHERE msgid=?", msg)
	defer db.Exec("DELETE FROM messages WHERE id=?", msg)

	decide := func(created string) {
		db.Exec("DELETE FROM messages_automod WHERE msgid=?", msg)
		res := db.Exec("INSERT INTO messages_automod (msgid, groupid, mode, chart_version, verdict, end_node, reason, path, created) "+
			"VALUES (?, ?, 'approve', 'v1', 'approve', 'test_end', NULL, '{}', ?)", msg, groupID, created)
		assert.NoError(t, res.Error)
	}

	decide(checked)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "decided in the same second as the content check: not later")
	decide(later)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "a second later is later")

	db.Exec("UPDATE messages SET editedat = ? WHERE id = ?", later, msg)
	assert.Nil(t, getAutoapproveatField(t, msg, groupID, modToken), "decided in the same second as the last edit: not later")
	db.Exec("UPDATE messages SET editedat = ? WHERE id = ?", checked, msg)
	assert.NotNil(t, getAutoapproveatField(t, msg, groupID, modToken), "decided after the edit")
}

// The lockdown stops both auto-approve crons: while posts is held neither of them runs, and a
// post the lockdown is holding (an open lockdown_holds row) is admitted by the content check when
// posts is lifted, not by the clock. So a held post shows no countdown, on the clean path or the
// rippled-in path, and the card's "held by lockdown" label is what says why.
func TestAutoapproveatNoCountdownWhileTheLockdownHoldsPosts(t *testing.T) {
	t.Setenv("FREEGLE_AUTOAPPROVE_ENABLED", "true")
	t.Setenv("FREEGLE_AUTOMOD_SHADOW_GROUPS", "")
	t.Setenv("RIPPLE_RIPPLED_IN_PENDING_HOURS", "2")
	prefix := uniquePrefix("aalockdown")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, poster, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	db.Exec("UPDATE memberships SET ourPostingStatus = NULL WHERE userid = ? AND groupid = ?", poster, groupID)
	_, modToken := CreateTestSession(t, modID)

	clean := CreateTestMessage(t, poster, groupID, prefix+" clean", 52.0, -1.0)
	other := CreateTestMessage(t, poster, groupID, prefix+" other", 52.0, -1.0)
	rippled := CreateTestMessage(t, poster, groupID, prefix+" rippled", 52.0, -1.0)
	for _, m := range []uint64{clean, other, rippled} {
		defer db.Exec("DELETE FROM messages_automod WHERE msgid = ?", m)
		defer db.Exec("DELETE FROM messages_groups WHERE msgid = ?", m)
		defer db.Exec("DELETE FROM messages WHERE id = ?", m)
	}
	for _, m := range []uint64{clean, other} {
		db.Exec("UPDATE messages_groups SET collection='Pending', arrival=NOW() - INTERVAL 5 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 4 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", m)
		insertAutomodRow(t, m, groupID, "approve", 0)
	}
	db.Exec("UPDATE messages_groups SET collection='Pending', rippled_in=1, arrival=NOW() - INTERVAL 30 MINUTE, contentcheck_checked_at=NOW() - INTERVAL 29 MINUTE, contentcheck_reasons=NULL, autoapprove_hold_until=NULL WHERE msgid=?", rippled)

	counts := func() (c, o, r bool) {
		return getAutoapproveatField(t, clean, groupID, modToken) != nil,
			getAutoapproveatField(t, other, groupID, modToken) != nil,
			getAutoapproveatField(t, rippled, groupID, modToken) != nil
	}

	c, o, r := counts()
	assert.True(t, c && o && r, "nothing held: all three count down")

	// A hold on the post itself: that post has none, and the others still do.
	insertPostHold(t, clean)
	c, o, r = counts()
	assert.False(t, c, "the lockdown is holding this post: the cron skips it, so no countdown")
	assert.True(t, o && r, "a hold on one post says nothing about the others")

	// A resolved hold no longer stops it.
	db.Exec("UPDATE lockdown_holds SET outcome = 'released' WHERE kind = 'post' AND refid = ?", clean)
	c, _, _ = counts()
	assert.True(t, c, "a hold with an outcome recorded is resolved")

	// Posts held site-wide: neither cron runs, so no post has a countdown, whichever path it is on.
	restore := postsHeld()
	c, o, r = counts()
	restore()
	assert.False(t, c || o || r, "posts is held: nothing publishes by itself, so nothing counts down")
	c, o, r = counts()
	assert.True(t, c && o && r, "lifted: they count down again")

	// A lockdown that holds other surfaces leaves the crons running.
	restore = modsHeld()
	_, o, _ = counts()
	restore()
	assert.True(t, o, "only posts stops the crons")
}
