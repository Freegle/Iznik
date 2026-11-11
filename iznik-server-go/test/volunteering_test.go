package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	volunteering2 "github.com/freegle/iznik-server-go/volunteering"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func TestVolunteering_InvalidID(t *testing.T) {
	// Non-integer ID should return 404
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/volunteering/notanint", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestVolunteering_V2Path(t *testing.T) {
	// Verify v2 paths work
	prefix := uniquePrefix("volv2")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/volunteering?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringCreateUnauthorized(t *testing.T) {
	body := `{"title":"Test","location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/volunteering", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestVolunteeringCreateMissingFields(t *testing.T) {
	prefix := uniquePrefix("volwr_miss")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	// Missing title
	body := `{"location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing location
	body = `{"title":"Test","description":"Test"}`
	req = httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing description
	body = `{"title":"Test","location":"Edinburgh"}`
	req = httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestVolunteeringSaveUnauthorized(t *testing.T) {
	body := `{"id":1,"title":"Updated"}`
	req := httptest.NewRequest("PATCH", "/api/volunteering", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestVolunteeringDeleteUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/volunteering/1", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestVolunteeringCreateWithoutGroup(t *testing.T) {
	prefix := uniquePrefix("volwr_nogrp")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	// Regular user can create without a group (groups added separately via AddGroup).
	body := `{"title":"Test Vol","location":"Edinburgh","description":"A test volunteering opportunity"}`
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringCreateNoGroupAdminAllowed(t *testing.T) {
	prefix := uniquePrefix("volwr_admngrp")
	adminID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, adminID)
	db := database.DBConn
	db.Exec("UPDATE users SET systemrole = ? WHERE id = ?", utils.SYSTEMROLE_SUPPORT, adminID)

	// Admin/support can create without a group.
	body := `{"title":"Test Vol","location":"Edinburgh","description":"A test volunteering opportunity"}`
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// A national (online) opportunity is shown to everyone, whatever their location.
func TestVolunteering_NationalOpsListedWithNoGroups(t *testing.T) {
	prefix := uniquePrefix("volnatnogrp")
	db := database.DBConn

	creatorID := CreateTestUser(t, prefix+"_creator", utils.SYSTEMROLE_USER)
	db.Exec("INSERT INTO volunteering (userid, title, description, location, online, pending, deleted, expired) VALUES (?, 'National Only Vol', 'national desc', '', 1, 0, 0, 0)", creatorID)
	var nationalOpID uint64
	db.Raw("SELECT id FROM volunteering WHERE userid = ? AND title = 'National Only Vol' ORDER BY id DESC LIMIT 1", creatorID).Scan(&nationalOpID)
	assert.Greater(t, nationalOpID, uint64(0))

	loneID := CreateTestUser(t, prefix+"_lone", utils.SYSTEMROLE_USER)
	_, loneToken := CreateTestSession(t, loneID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/volunteering?jwt="+loneToken, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ids []uint64
	json2.Unmarshal(rsp(resp), &ids)
	assert.Contains(t, ids, nationalOpID, "member of no communities must still see national ops")
}
func TestVolunteeringSave(t *testing.T) {
	prefix := uniquePrefix("volwr_save")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	// Update title and description
	body := fmt.Sprintf(`{"id":%d,"title":"Updated Title","description":"Updated Description"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify the update by fetching
	resp, _ = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/volunteering/%d", volunteeringID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var vol map[string]interface{}
	json2.Unmarshal(rsp(resp), &vol)
	assert.Equal(t, "Updated Title", vol["title"])
	assert.Equal(t, "Updated Description", vol["description"])
}

func TestVolunteeringSaveNonOwner(t *testing.T) {
	prefix := uniquePrefix("volwr_noown")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, otherToken := CreateTestSession(t, otherID)

	body := fmt.Sprintf(`{"id":%d,"title":"Hacked"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+otherToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestVolunteeringSaveByModerator(t *testing.T) {
	prefix := uniquePrefix("volwr_mod")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"id":%d,"title":"Mod Updated"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringAddDate(t *testing.T) {
	prefix := uniquePrefix("volwr_addd")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"id":%d,"action":"AddDate","start":"2026-03-01T10:00:00Z","end":"2026-03-15T18:00:00Z"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringRemoveDate(t *testing.T) {
	prefix := uniquePrefix("volwr_remd")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	// Get existing dates
	db := database.DBConn
	var dateID uint64
	db.Raw("SELECT id FROM volunteering_dates WHERE volunteeringid = ? LIMIT 1", volunteeringID).Scan(&dateID)

	body := fmt.Sprintf(`{"id":%d,"action":"RemoveDate","dateid":%d}`, volunteeringID, dateID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringSetPhoto(t *testing.T) {
	prefix := uniquePrefix("volwr_photo")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	// Create a test image
	db := database.DBConn
	db.Exec("INSERT INTO volunteering_images (opportunityid, contenttype) VALUES (?, 'image/jpeg')", volunteeringID)
	var photoID uint64
	db.Raw("SELECT id FROM volunteering_images WHERE opportunityid = ? ORDER BY id DESC LIMIT 1", volunteeringID).Scan(&photoID)

	body := fmt.Sprintf(`{"id":%d,"action":"SetPhoto","photoid":%d}`, volunteeringID, photoID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringRenew(t *testing.T) {
	prefix := uniquePrefix("volwr_renew")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"id":%d,"action":"Renew"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify renewed timestamp and expired = 0
	db := database.DBConn
	var expired int
	var renewed *string
	db.Raw("SELECT expired, renewed FROM volunteering WHERE id = ?", volunteeringID).Row().Scan(&expired, &renewed)
	assert.Equal(t, 0, expired)
	assert.NotNil(t, renewed)

	// The renewal timestamp must come back to the client, which uses it to decide
	// whether a confirmation is due. Without it the site asks the owner to confirm
	// on every single visit, however recently they last did.
	resp2, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/volunteering/%d?jwt=%s", volunteeringID, token), nil))
	assert.Equal(t, 200, resp2.StatusCode)

	var fetched volunteering2.Volunteering
	json2.Unmarshal(rsp(resp2), &fetched)
	assert.NotNil(t, fetched.Renewed)
}

func TestVolunteeringExpire(t *testing.T) {
	prefix := uniquePrefix("volwr_expire")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"id":%d,"action":"Expire"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify expired = 1
	db := database.DBConn
	var expired int
	db.Raw("SELECT expired FROM volunteering WHERE id = ?", volunteeringID).Scan(&expired)
	assert.Equal(t, 1, expired)
}

func TestVolunteeringDelete(t *testing.T) {
	prefix := uniquePrefix("volwr_del")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, userID)
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/volunteering/%d?jwt=%s", volunteeringID, token), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, true, result["success"])

	// Verify soft-deleted
	db := database.DBConn
	var deleted int
	db.Raw("SELECT deleted FROM volunteering WHERE id = ?", volunteeringID).Scan(&deleted)
	assert.Equal(t, 1, deleted)
}

func TestVolunteeringDeleteNonOwner(t *testing.T) {
	prefix := uniquePrefix("volwr_dno")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, otherToken := CreateTestSession(t, otherID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/volunteering/%d?jwt=%s", volunteeringID, otherToken), nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestVolunteeringHold(t *testing.T) {
	prefix := uniquePrefix("volwr_hold")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"id":%d,"action":"Hold"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is set to the moderator
	db := database.DBConn
	var heldby *uint64
	db.Raw("SELECT heldby FROM volunteering WHERE id = ?", volunteeringID).Scan(&heldby)
	assert.NotNil(t, heldby)
	assert.Equal(t, modID, *heldby)
}

func TestVolunteeringRelease(t *testing.T) {
	prefix := uniquePrefix("volwr_rel")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	// First hold it
	db := database.DBConn
	db.Exec("UPDATE volunteering SET heldby = ? WHERE id = ?", modID, volunteeringID)

	// Then release it
	body := fmt.Sprintf(`{"id":%d,"action":"Release"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is NULL
	var heldby *uint64
	db.Raw("SELECT heldby FROM volunteering WHERE id = ?", volunteeringID).Scan(&heldby)
	assert.Nil(t, heldby)
}

func TestVolunteeringHoldNonModerator(t *testing.T) {
	prefix := uniquePrefix("volwr_holdnm")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, ownerToken := CreateTestSession(t, ownerID)

	// Owner (non-mod) tries to hold - should succeed (canModify passes) but heldby should NOT be set
	body := fmt.Sprintf(`{"id":%d,"action":"Hold"}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+ownerToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify heldby is still NULL (isModerator check failed)
	db := database.DBConn
	var heldby *uint64
	db.Raw("SELECT heldby FROM volunteering WHERE id = ?", volunteeringID).Scan(&heldby)
	assert.Nil(t, heldby)
}

func TestVolunteeringPending(t *testing.T) {
	prefix := uniquePrefix("volwr_pend")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	// Set pending = 1
	db := database.DBConn
	db.Exec("UPDATE volunteering SET pending = 1 WHERE id = ?", volunteeringID)

	// Approve it (set pending = false)
	body := fmt.Sprintf(`{"id":%d,"pending":false}`, volunteeringID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// Verify pending = 0
	var pendingVal int
	db.Raw("SELECT pending FROM volunteering WHERE id = ?", volunteeringID).Scan(&pendingVal)
	assert.Equal(t, 0, pendingVal)
}

func TestVolunteeringDeleteByModerator(t *testing.T) {
	prefix := uniquePrefix("volwr_dmod")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	volunteeringID := CreateTestVolunteering(t, ownerID)
	_, modToken := CreateTestSession(t, modID)

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/volunteering/%d?jwt=%s", volunteeringID, modToken), nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringNullUserid(t *testing.T) {
	// Volunteering entries can have NULL userid (e.g. created by old V1 code).
	// canModify must not crash scanning NULL into uint64.
	prefix := uniquePrefix("volwr_null")
	db := database.DBConn

	// Insert volunteering with NULL userid directly.
	db.Exec("INSERT INTO volunteering (userid, title, description, location, pending, deleted, expired) VALUES (NULL, ?, 'Null user test', 'Somewhere', 0, 0, 0)", "NullUser Vol "+prefix)
	var volID uint64
	db.Raw("SELECT id FROM volunteering WHERE title = ? ORDER BY id DESC LIMIT 1", "NullUser Vol "+prefix).Scan(&volID)
	assert.Greater(t, volID, uint64(0))
	db.Exec("INSERT INTO volunteering_dates (volunteeringid, start, end) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))", volID)

	// GET should succeed (no scan error).
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/volunteering/%d", volID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	// A moderator trying to PATCH should get 403 from canModify (not a 500 scan crash).
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)

	body := fmt.Sprintf(`{"id":%d,"title":"Updated by mod"}`, volID)
	req := httptest.NewRequest("PATCH", "/api/volunteering?jwt="+modToken, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringSingleResponseFields(t *testing.T) {
	prefix := uniquePrefix("volfields")
	db := database.DBConn

	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)

	// Create a volunteering with online=1 and a contacturl that has no scheme.
	db.Exec("INSERT INTO volunteering (userid, title, location, description, online, pending, deleted, expired, contacturl) "+
		"VALUES (?, 'Fields Test Vol', 'Edinburgh', 'Testing response fields', 1, 0, 0, 0, 'example.com')", userID)
	var volID uint64
	db.Raw("SELECT id FROM volunteering WHERE userid = ? AND title = 'Fields Test Vol' ORDER BY id DESC LIMIT 1", userID).Scan(&volID)
	assert.Greater(t, volID, uint64(0))
	db.Exec("INSERT INTO volunteering_dates (volunteeringid, start, end) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))", volID)

	// Set a renewed timestamp so the field is non-null.
	db.Exec("UPDATE volunteering SET renewed = NOW() WHERE id = ?", volID)

	t.Cleanup(func() {
		db.Exec("DELETE FROM volunteering_dates WHERE volunteeringid = ?", volID)
		db.Exec("DELETE FROM volunteering WHERE id = ?", volID)
	})

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/volunteering/%d", volID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// 'url' must be present and contain the path segment.
	urlVal, urlOk := result["url"].(string)
	assert.True(t, urlOk, "url field must be a string")
	assert.Contains(t, urlVal, "/volunteering/", "url must contain /volunteering/ path")

	// 'online' must be true.
	assert.Equal(t, true, result["online"], "online field must be true")

	// 'renewed' must be non-null (we set it above).
	assert.NotNil(t, result["renewed"], "renewed field must be non-null after Renew action")

	// 'contacturl' must have been normalised to https://.
	contacturl, cuOk := result["contacturl"].(string)
	assert.True(t, cuOk, "contacturl field must be a string")
	assert.True(t, strings.HasPrefix(contacturl, "https://"), "contacturl without scheme must be prefixed with https://")
}

func TestVolunteeringCreate(t *testing.T) {
	prefix := uniquePrefix("volwr_create")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	body := fmt.Sprintf(`{"title":"Test Vol %s","location":"Edinburgh","description":"A test volunteering opportunity","contactname":"Test","contactemail":"test@test.com"}`, prefix)
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Greater(t, result["id"], float64(0))
}
