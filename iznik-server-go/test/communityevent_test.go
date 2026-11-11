package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func TestCommunityEvent_InvalidID(t *testing.T) {
	// Non-integer ID should return 404
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/communityevent/notanint", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestCommunityEvent_V2Path(t *testing.T) {
	// Verify v2 paths work
	prefix := uniquePrefix("eventv2")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/communityevent?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventCreateUnauthorized(t *testing.T) {
	body := `{"title":"Test","location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/communityevent", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommunityEventCreateMissingFields(t *testing.T) {
	prefix := uniquePrefix("cewr_miss")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	// Missing title
	body := `{"location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing location
	body = `{"title":"Test","description":"Test"}`
	req = httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing description
	body = `{"title":"Test","location":"Edinburgh"}`
	req = httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestCommunityEventSaveUnauthorized(t *testing.T) {
	body := `{"id":1,"title":"Updated"}`
	req := httptest.NewRequest("PATCH", "/api/communityevent", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommunityEventDeleteUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/communityevent/1", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommunityEventCreateWithoutGroup(t *testing.T) {
	prefix := uniquePrefix("cewr_nogrp")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	// Regular user can create without a group (groups added separately via AddGroup).
	body := `{"title":"Test Event","location":"Edinburgh","description":"A test community event"}`
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventCreateNoGroupAdminAllowed(t *testing.T) {
	prefix := uniquePrefix("cewr_admngrp")
	adminID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, adminID)
	db := database.DBConn
	db.Exec("UPDATE users SET systemrole = ? WHERE id = ?", utils.SYSTEMROLE_SUPPORT, adminID)

	// Admin/support can create without a group.
	body := `{"title":"Test Event","location":"Edinburgh","description":"A test community event"}`
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}
func TestCommunityEventCreate(t *testing.T) {
	prefix := uniquePrefix("cewr_create")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"title":"Test Event %s","location":"Edinburgh","description":"A test community event","contactname":"Test","contactemail":"test@test.com"}`, prefix)
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Greater(t, result["id"], float64(0))
}

func TestCommunityEventSave(t *testing.T) {
	prefix := uniquePrefix("cewr_save")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, userID)
	_, token := CreateTestSession(t, userID)

	// Update title and description
	body := fmt.Sprintf(`{"id":%d,"title":"Updated Title","description":"Updated Description"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify the update by fetching
	resp, _ = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/communityevent/%d", eventID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var event map[string]interface{}
	json2.Unmarshal(rsp(resp), &event)
	assert.Equal(t, "Updated Title", event["title"])
	assert.Equal(t, "Updated Description", event["description"])
}

func TestCommunityEventSaveNonOwner(t *testing.T) {
	prefix := uniquePrefix("cewr_noown")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, otherToken := CreateTestSession(t, otherID)

	body := fmt.Sprintf(`{"id":%d,"title":"Hacked"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+otherToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestCommunityEventSaveByModerator(t *testing.T) {
	prefix := uniquePrefix("cewr_mod")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"id":%d,"title":"Mod Updated"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventAddDate(t *testing.T) {
	prefix := uniquePrefix("cewr_addd")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, userID)
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"id":%d,"action":"AddDate","start":"2026-03-01T10:00:00Z","end":"2026-03-01T18:00:00Z"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventRemoveDate(t *testing.T) {
	prefix := uniquePrefix("cewr_remd")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, userID)
	_, token := CreateTestSession(t, userID)

	// Get existing dates
	db := database.DBConn
	var dateID uint64
	db.Raw("SELECT id FROM communityevents_dates WHERE eventid = ? LIMIT 1", eventID).Scan(&dateID)

	body := fmt.Sprintf(`{"id":%d,"action":"RemoveDate","dateid":%d}`, eventID, dateID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventSetPhoto(t *testing.T) {
	prefix := uniquePrefix("cewr_photo")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, userID)
	_, token := CreateTestSession(t, userID)

	// Create a test image
	db := database.DBConn
	db.Exec("INSERT INTO communityevents_images (eventid, contenttype) VALUES (?, 'image/jpeg')", eventID)
	var photoID uint64
	db.Raw("SELECT id FROM communityevents_images WHERE eventid = ? ORDER BY id DESC LIMIT 1", eventID).Scan(&photoID)

	body := fmt.Sprintf(`{"id":%d,"action":"SetPhoto","photoid":%d}`, eventID, photoID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventHold(t *testing.T) {
	prefix := uniquePrefix("cewr_hold")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"id":%d,"action":"Hold"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is set
	db := database.DBConn
	var heldby *uint64
	db.Raw("SELECT heldby FROM communityevents WHERE id = ?", eventID).Scan(&heldby)
	assert.NotNil(t, heldby)
	assert.Equal(t, modID, *heldby)
}

func TestCommunityEventRelease(t *testing.T) {
	prefix := uniquePrefix("cewr_rel")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	// Hold first
	db := database.DBConn
	db.Exec("UPDATE communityevents SET heldby = ? WHERE id = ?", modID, eventID)

	// Release
	body := fmt.Sprintf(`{"id":%d,"action":"Release"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is cleared
	var heldby *uint64
	db.Raw("SELECT heldby FROM communityevents WHERE id = ?", eventID).Scan(&heldby)
	assert.Nil(t, heldby)
}

func TestCommunityEventDelete(t *testing.T) {
	prefix := uniquePrefix("cewr_del")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, userID)
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/communityevent/%d?jwt=%s", eventID, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify soft-deleted
	db := database.DBConn
	var deleted int
	db.Raw("SELECT deleted FROM communityevents WHERE id = ?", eventID).Scan(&deleted)
	assert.Equal(t, 1, deleted)
}

func TestCommunityEventDeleteNonOwner(t *testing.T) {
	prefix := uniquePrefix("cewr_dno")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, otherToken := CreateTestSession(t, otherID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/communityevent/%d?jwt=%s", eventID, otherToken), nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestCommunityEventDeleteByModerator(t *testing.T) {
	prefix := uniquePrefix("cewr_dmod")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/communityevent/%d?jwt=%s", eventID, modToken), nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventPendingBool(t *testing.T) {
	// Frontend sends pending as boolean (false), not integer (0).
	// The Go PatchRequest.Pending field must be *bool to accept this.
	prefix := uniquePrefix("cewr_pend")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	eventID := CreateTestCommunityEvent(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	// Set pending = 1
	db := database.DBConn
	db.Exec("UPDATE communityevents SET pending = 1 WHERE id = ?", eventID)

	// Approve: send pending as boolean false (matching frontend behavior)
	body := fmt.Sprintf(`{"id":%d,"pending":false}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify pending = 0
	var pendingVal int
	db.Raw("SELECT pending FROM communityevents WHERE id = ?", eventID).Scan(&pendingVal)
	assert.Equal(t, 0, pendingVal)
}

func TestCommunityEventNullUserid(t *testing.T) {
	// Community events can have NULL userid (e.g. created by old V1 code).
	// canModify must not crash scanning NULL into uint64.
	prefix := uniquePrefix("cewr_null")
	db := database.DBConn

	// Insert event with NULL userid directly.
	db.Exec("INSERT INTO communityevents (userid, title, description, location, pending, deleted) VALUES (NULL, ?, 'Null user test', 'Somewhere', 0, 0)", "NullUser CE "+prefix)
	var eventID uint64
	db.Raw("SELECT id FROM communityevents WHERE title = ? ORDER BY id DESC LIMIT 1", "NullUser CE "+prefix).Scan(&eventID)
	assert.Greater(t, eventID, uint64(0))
	db.Exec("INSERT INTO communityevents_dates (eventid, start, end) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))", eventID)

	// GET should succeed (no scan error).
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/communityevent/%d", eventID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	// A moderator trying to PATCH should succeed via isModerator (not crash from NULL scan).
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"id":%d,"title":"Updated by mod"}`, eventID)
	req := httptest.NewRequest("PATCH", "/api/communityevent?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEvent_PendingListExcludesExpired(t *testing.T) {
	// Pending events with past end dates should NOT appear in the listing.
	prefix := uniquePrefix("eventexp")
	db := database.DBConn

	creatorID := CreateTestUser(t, prefix+"_creator", utils.SYSTEMROLE_USER)

	// Create a pending event with a PAST end date.
	db.Exec("INSERT INTO communityevents (userid, title, location, description, pending, deleted) VALUES (?, 'Expired Event', 'Test Location', 'Past', 1, 0)", creatorID)
	var expiredID uint64
	db.Raw("SELECT id FROM communityevents WHERE userid = ? AND title = 'Expired Event' ORDER BY id DESC LIMIT 1", creatorID).Scan(&expiredID)
	assert.Greater(t, expiredID, uint64(0))
	db.Exec("INSERT INTO communityevents_dates (eventid, `start`, `end`) VALUES (?, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))", expiredID)

	// Create a pending event with a FUTURE end date.
	db.Exec("INSERT INTO communityevents (userid, title, location, description, pending, deleted) VALUES (?, 'Future Event', 'Test Location', 'Upcoming', 1, 0)", creatorID)
	var futureID uint64
	db.Raw("SELECT id FROM communityevents WHERE userid = ? AND title = 'Future Event' ORDER BY id DESC LIMIT 1", creatorID).Scan(&futureID)
	assert.Greater(t, futureID, uint64(0))
	db.Exec("INSERT INTO communityevents_dates (eventid, `start`, `end`) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY))", futureID)

	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/communityevent?pending=true&jwt="+modToken, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ids []uint64
	json2.Unmarshal(rsp(resp), &ids)
	assert.Contains(t, ids, futureID, "Future pending event should appear")
	assert.NotContains(t, ids, expiredID, "Expired pending event should NOT appear")
}
