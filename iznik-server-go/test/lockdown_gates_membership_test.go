package test

// Gate tests for membership.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md): moderator actions on POST/PATCH/DELETE
// /memberships are refused outright while "mods" is held. Own actions (DELETE self-leave,
// Approve, Emailfrequency/Eventsallowed/Volunteeringallowed/Settings) stay allowed -
// the plan is explicit that membership Approve carries no subject/body restriction and
// is not counted, unlike message.go's dispatchPostMessageAction.
//
// POST /memberships "Leave Member"/"Leave Approved Member" looks like the member's own
// action from its name, but is a moderator sending mail to the member, and section 11.9
// (added after review) corrects the plan's original "own | allowed" classification of it
// to refused while "mods" is held - see TestLockdownRefusesPostMembershipsLeaveMember.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so
// these tests never write a real "lockdowns" row and cannot race the other packages'
// test binaries that `go test ./...` runs concurrently against the same test database.

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// setUpModAndTarget creates a group, a moderator with a session, and a pending target
// member, returning (groupID, modToken, targetID).
func setUpModAndTarget(t *testing.T, prefix string) (uint64, string, uint64) {
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	createPendingMember(t, targetID, groupID)
	return groupID, modToken, targetID
}

func TestLockdownRefusesPostMembershipsHold(t *testing.T) {
	prefix := uniquePrefix("ld_mem_hold")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Hold"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsRelease(t *testing.T) {
	prefix := uniquePrefix("ld_mem_release")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)
	database.DBConn.Exec("UPDATE memberships SET heldby = ? WHERE userid = ? AND groupid = ?",
		targetID, targetID, groupID)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Release"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsReject(t *testing.T) {
	prefix := uniquePrefix("ld_mem_reject")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Reject"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsBan(t *testing.T) {
	prefix := uniquePrefix("ld_mem_ban")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Ban"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsUnban(t *testing.T) {
	prefix := uniquePrefix("ld_mem_unban")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Unban"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsReviewHold(t *testing.T) {
	prefix := uniquePrefix("ld_mem_revhold")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"ReviewHold"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsReviewRelease(t *testing.T) {
	prefix := uniquePrefix("ld_mem_revrelease")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"ReviewRelease"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsReviewIgnore(t *testing.T) {
	prefix := uniquePrefix("ld_mem_revignore")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"ReviewIgnore"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsHappinessReviewed(t *testing.T) {
	prefix := uniquePrefix("ld_mem_happiness")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"HappinessReviewed"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPostMembershipsDeleteApprovedMember(t *testing.T) {
	prefix := uniquePrefix("ld_mem_delapproved")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, targetID, groupID, "Member")

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Delete Approved Member"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

// Approve stays allowed - the plan is explicit that membership Approve carries no
// subject/body restriction and is not counted (unlike message.go).
func TestLockdownDoesNotRefusePostMembershipsApprove(t *testing.T) {
	prefix := uniquePrefix("ld_mem_approve_ok")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Approve"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// "Leave Member"/"Leave Approved Member" is not a decision like Approve/Reject/Ban -
// it is a moderator sending the member a mod-mail (V1 memberships.php:291-294, just
// $u->mail()) without changing the membership row. Section 10.5 of the plan first
// filed it as "own | allowed", reasoning from the membership row it does not touch,
// but section 11.9 (added after review) corrects that: it is a moderator starting a
// message to a member exactly as much as a chat or mail send is, so while "mods" is
// held a moderator who is not Support or Admin may not send it. It is refused with
// the same GateMod used for the moderation decisions above, before the
// background_tasks mail row is ever queued.
func TestLockdownRefusesPostMembershipsLeaveMember(t *testing.T) {
	prefix := uniquePrefix("ld_mem_leave")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Leave Member"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

// Leave Approved Member is the same action under a different name (V1 parity, see
// membership.go); both must be refused the same way.
func TestLockdownRefusesPostMembershipsLeaveApprovedMember(t *testing.T) {
	prefix := uniquePrefix("ld_mem_leaveapproved")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, targetID, groupID, "Member")

	restore := modsHeld()
	defer restore()

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Leave Approved Member"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

// Not held: an ordinary day sends the mail as normal, unaffected by GateMod.
func TestLockdownDoesNotRefusePostMembershipsLeaveMemberWhenNotHeld(t *testing.T) {
	prefix := uniquePrefix("ld_mem_leave_notheld_ok")
	groupID, modToken, targetID := setUpModAndTarget(t, prefix)

	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"action":"Leave Member"}`, targetID, groupID)
	req := httptest.NewRequest("POST", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// --- DELETE /memberships: both a moderator ban and a moderator removing someone
// else are refused; a member's own leave stays allowed. ---

func TestLockdownRefusesDeleteMembershipsBan(t *testing.T) {
	prefix := uniquePrefix("ld_mem_delban")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, targetID, groupID, "Member")

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"ban":true}`, targetID, groupID)
	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	req := httptest.NewRequest("DELETE", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesDeleteMembershipsRemoveOther(t *testing.T) {
	prefix := uniquePrefix("ld_mem_delother")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, targetID, groupID, "Member")

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"userid":%d,"groupid":%d}`, targetID, groupID)
	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	req := httptest.NewRequest("DELETE", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownDoesNotRefuseDeleteMembershipsSelfLeave(t *testing.T) {
	prefix := uniquePrefix("ld_mem_selfleave_ok")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"groupid":%d}`, groupID)
	url := fmt.Sprintf("/api/memberships?jwt=%s", token)
	req := httptest.NewRequest("DELETE", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// --- PATCH /memberships: Role and OurPostingStatus are moderator-only and refused;
// a member's own Emailfrequency change stays allowed. ---

func TestLockdownRefusesPatchMembershipsRole(t *testing.T) {
	prefix := uniquePrefix("ld_mem_role")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Owner")
	_, ownerToken := CreateTestSession(t, ownerID)
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")

	restore := modsHeld()
	defer restore()

	resp := patchMembershipRole(t, ownerToken, groupID, memberID, "Moderator")
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPatchMembershipsOurPostingStatus(t *testing.T) {
	prefix := uniquePrefix("ld_mem_postingstatus")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"ourpostingstatus":"MODERATED"}`, memberID, groupID)
	url := fmt.Sprintf("/api/memberships?jwt=%s", modToken)
	req := httptest.NewRequest("PATCH", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownDoesNotRefusePatchMembershipsEmailfrequency(t *testing.T) {
	prefix := uniquePrefix("ld_mem_emailfreq_ok")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"groupid":%d,"emailfrequency":0}`, groupID)
	url := fmt.Sprintf("/api/memberships?jwt=%s", token)
	req := httptest.NewRequest("PATCH", url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}
