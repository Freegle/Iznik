package test

// Gate tests for communityevent/volunteering/noticeboard/story (section 11.3 of the
// lockdown plan, plans/active/2026-09-27-lockdown-switch.md), driven by the "events"
// row there:
//   - communityevent, volunteering: POST (create) already forces pending=1
//     unconditionally in the code with no lockdown-specific change needed, so it is
//     not re-tested here; PATCH (edit) is refused, except a moderator approving out
//     of moderation - {"id":X,"pending":false} alone - which stays allowed.
//   - noticeboard: has no pending/review state at all (confirmed against the
//     noticeboards migration), so unlike the other three, creation is refused
//     outright rather than held; edits are refused too.
//   - story: PUT /story (create) already defaults reviewed=false with no
//     lockdown-specific change needed (not re-tested here); PATCH /story (edit or
//     mod review) is refused outright, with no approve exception - a story's review
//     fields are exactly what "edits refused" means for this endpoint.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so
// these tests never write a real "lockdowns" row and cannot race the other packages'
// test binaries that `go test ./...` runs concurrently against the same test database.
// createTestNoticeboard is defined in noticeboard_test.go, same package.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/stretchr/testify/assert"
)

func eventsHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"events": true},
	})
}

// --- communityevent: edit refused, moderator approve still allowed ---

func TestLockdownRefusesCommunityEventEdit(t *testing.T) {
	prefix := uniquePrefix("ld_ce_edit")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	eventID := CreateTestCommunityEvent(t, ownerID, groupID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": eventID, "title": prefix + " edited"})
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+ownerToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)
}

func TestLockdownAllowsCommunityEventApproveWhenEventsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_ce_appr")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	eventID := CreateTestCommunityEvent(t, ownerID, groupID)

	db := database.DBConn
	db.Exec("UPDATE communityevents SET pending = 1 WHERE id = ?", eventID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": eventID, "pending": false})
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "a bare approve must keep draining the review queue while events is held")

	var pending int
	db.Raw("SELECT pending FROM communityevents WHERE id = ?", eventID).Scan(&pending)
	assert.Equal(t, 0, pending)
}

// --- communityevent: the approve fast-path is a body-shape check, not a role check.
// A member who owns their own pending event can send the same bare
// {"id":X,"pending":false} a moderator would send to approve it, and must be refused
// exactly as any other edit would be while "events" is held (review finding 3). Only a
// moderator of the event's group (or Support/Admin) may use the fast path. ---

func TestLockdownRefusesCommunityEventSelfApproveByOwner(t *testing.T) {
	prefix := uniquePrefix("ld_ce_selfappr")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	eventID := CreateTestCommunityEvent(t, ownerID, groupID)

	db := database.DBConn
	db.Exec("UPDATE communityevents SET pending = 1 WHERE id = ?", eventID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": eventID, "pending": false})
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+ownerToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var pending int
	db.Raw("SELECT pending FROM communityevents WHERE id = ?", eventID).Scan(&pending)
	assert.Equal(t, 1, pending, "an owner who is not a moderator must not be able to self-approve out of an events hold")
}

func TestLockdownCommunityEventModeratorApproveIsCounted(t *testing.T) {
	prefix := uniquePrefix("ld_ce_appr_count")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	eventID := CreateTestCommunityEvent(t, ownerID, groupID)

	db := database.DBConn
	db.Exec("UPDATE communityevents SET pending = 1 WHERE id = ?", eventID)

	const incidentID = 999997
	db.Exec("DELETE FROM lockdown_counters WHERE lockdownid = ?", incidentID)
	restore := lockdown.SetTestState(lockdown.State{
		ID:         incidentID,
		IncidentID: incidentID,
		Active:     true,
		Surfaces:   map[string]bool{"events": true, "mods": true},
	})
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": eventID, "pending": false})
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "a moderator's bare approve must still go through while events is held")

	var pending int
	db.Raw("SELECT pending FROM communityevents WHERE id = ?", eventID).Scan(&pending)
	assert.Equal(t, 0, pending)

	var count int
	db.Raw("SELECT count FROM lockdown_counters WHERE lockdownid = ? AND kind = ?",
		incidentID, fmt.Sprintf("approved:%d", modID)).Scan(&count)
	assert.Equal(t, 1, count, "a moderator's bare approve during a mods hold must be counted")
}

// --- volunteering: edit refused, moderator approve still allowed ---

func TestLockdownRefusesVolunteeringEdit(t *testing.T) {
	prefix := uniquePrefix("ld_vol_edit")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	volID := CreateTestVolunteering(t, ownerID, groupID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": volID, "title": prefix + " edited"})
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+ownerToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)
}

func TestLockdownAllowsVolunteeringApproveWhenEventsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_vol_appr")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	volID := CreateTestVolunteering(t, ownerID, groupID)

	db := database.DBConn
	db.Exec("UPDATE volunteering SET pending = 1 WHERE id = ?", volID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": volID, "pending": false})
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "a bare approve must keep draining the review queue while events is held")

	var pending int
	db.Raw("SELECT pending FROM volunteering WHERE id = ?", volID).Scan(&pending)
	assert.Equal(t, 0, pending)
}

// --- volunteering: same body-shape approve fast-path bug as communityevent above
// (review finding 3) ---

func TestLockdownRefusesVolunteeringSelfApproveByOwner(t *testing.T) {
	prefix := uniquePrefix("ld_vol_selfappr")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken := CreateTestSession(t, ownerID)
	volID := CreateTestVolunteering(t, ownerID, groupID)

	db := database.DBConn
	db.Exec("UPDATE volunteering SET pending = 1 WHERE id = ?", volID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": volID, "pending": false})
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+ownerToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var pending int
	db.Raw("SELECT pending FROM volunteering WHERE id = ?", volID).Scan(&pending)
	assert.Equal(t, 1, pending, "an owner who is not a moderator must not be able to self-approve out of an events hold")
}

func TestLockdownVolunteeringModeratorApproveIsCounted(t *testing.T) {
	prefix := uniquePrefix("ld_vol_appr_count")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	volID := CreateTestVolunteering(t, ownerID, groupID)

	db := database.DBConn
	db.Exec("UPDATE volunteering SET pending = 1 WHERE id = ?", volID)

	const incidentID = 999996
	db.Exec("DELETE FROM lockdown_counters WHERE lockdownid = ?", incidentID)
	restore := lockdown.SetTestState(lockdown.State{
		ID:         incidentID,
		IncidentID: incidentID,
		Active:     true,
		Surfaces:   map[string]bool{"events": true, "mods": true},
	})
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": volID, "pending": false})
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "a moderator's bare approve must still go through while events is held")

	var pending int
	db.Raw("SELECT pending FROM volunteering WHERE id = ?", volID).Scan(&pending)
	assert.Equal(t, 0, pending)

	var count int
	db.Raw("SELECT count FROM lockdown_counters WHERE lockdownid = ? AND kind = ?",
		incidentID, fmt.Sprintf("approved:%d", modID)).Scan(&count)
	assert.Equal(t, 1, count, "a moderator's bare approve during a mods hold must be counted")
}

// --- noticeboard: no pending state, so both create and edit are refused outright ---

func TestLockdownRefusesNoticeboardCreate(t *testing.T) {
	prefix := uniquePrefix("ld_nb_create")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{
		"lat": 51.5, "lng": -0.1, "name": prefix, "description": "test",
	})
	req := httptest.NewRequest("POST", "/api/noticeboard?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM noticeboards WHERE addedby = ?", userID).Scan(&count)
	assert.Equal(t, int64(0), count, "no noticeboard must be created while events is held")
}

func TestLockdownRefusesNoticeboardEdit(t *testing.T) {
	prefix := uniquePrefix("ld_nb_edit")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	nbID := createTestNoticeboard(t, userID)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": nbID, "name": "Edited " + prefix})
	req := httptest.NewRequest("PATCH", "/api/noticeboard?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)
}

// --- story: PATCH is refused outright, no approve exception ---

func TestLockdownRefusesStoryEdit(t *testing.T) {
	prefix := uniquePrefix("ld_story_edit")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	storyID := CreateTestStory(t, userID, prefix+" headline", "A test story", false, true)

	restore := eventsHeld()
	defer restore()

	body, _ := json.Marshal(map[string]interface{}{"id": storyID, "headline": "Edited " + prefix})
	req := httptest.NewRequest("PATCH", "/api/story?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)
}
